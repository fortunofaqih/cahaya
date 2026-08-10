<?php
// PENTING LOGIKA TITIP:
// - detail_titip.amount_in = uang titip masuk / saldo titip, TIDAK langsung mengurangi piutang.
// - detail_bayar.titip_amount = uang titip yang SUDAH dipakai untuk pembayaran, INI yang mengurangi piutang.
// - cash_amount + titip_amount = bayar_amount.
// modul/transaksi/cetak_aging_piutang_detail.php
//
// REVISI BESAR:
// 1. Tampilan menjadi ringkasan per customer, dikelompokkan berdasarkan Kota.
// 2. Kolom Grup, Kota, dan Cust ID dihilangkan.
// 3. Urutan kolom:
//    Nama Customer | Awal | Penjualan | Bayar | Titip | Akhir |
//    TITIP = saldo titip customer yang BELUM terpakai per akhir periode.
//    Titip terpakai tetap mengurangi piutang secara internal.
//    1-30 Hari | 31-60 Hari | 61-90 Hari | Lebih | Belum Jatuh Tempo
// 4. Sales Return / Retur Invoice mengurangi piutang.
// 5. Kolom "Lebih" = outstanding >90 hari + saldo historis tanpa umur - Retur Invoice.
// 6. Retur berstatus Cancelled tidak dihitung.
// 7. Semua filter menggunakan bentuk tabel yang sama.
// 8. Pembayaran dan Retur tetap dihitung sebagai dua mutasi terpisah.
//    detail_bayar.return_id hanya berfungsi sebagai link cross-check/audit,
//    bukan untuk mengurangi atau menambah nilai pembayaran.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

function h($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatMoney($value)
{
    return number_format(
        (float)$value,
        2,
        ',',
        '.'
    );
}

function getMonthName($month)
{
    $names = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];

    return $names[(int)$month] ?? '';
}

function getTitleFilter($filterBy, $filterValue)
{
    if ($filterBy === 'grup' && $filterValue !== '') {
        return 'Grup: ' . $filterValue;
    }

    if ($filterBy === 'kota' && $filterValue !== '') {
        return 'Kota: ' . $filterValue;
    }

    if ($filterBy === 'pelanggan' && $filterValue !== '') {
        return 'Pelanggan: ' . $filterValue;
    }

    return 'Semua Grup';
}

function initAmountRow()
{
    return [
        'saldo_awal' => 0.0,
        'penjualan' => 0.0,
        'bayar' => 0.0,
        'titip' => 0.0,       // saldo titip belum terpakai per akhir periode (DISPLAY)
        'titip_used' => 0.0,  // titip yang dipakai untuk pembayaran (INTERNAL)
        'retur' => 0.0,
        'akhir' => 0.0,
        'b_1_30' => 0.0,
        'b_31_60' => 0.0,
        'b_61_90' => 0.0,
        'b_lebih' => 0.0,
        'belum_jatuh_tempo' => 0.0,
    ];
}

function addAmounts(array &$target, array $source)
{
    foreach (array_keys(initAmountRow()) as $key) {
        $target[$key] += (float)($source[$key] ?? 0);
    }
}

function normalizeCity($city)
{
    $city = trim((string)$city);

    return $city !== ''
        ? strtoupper($city)
        : 'TANPA KOTA';
}

$bulan = (int)($_GET['bulan'] ?? date('n'));
$tahun = (int)($_GET['tahun'] ?? date('Y'));

$filterBy = trim(
    (string)($_GET['filter_by'] ?? 'semua')
);

$filterValue = trim(
    (string)($_GET['filter_value'] ?? '')
);

if ($bulan < 1 || $bulan > 12) {
    $bulan = (int)date('n');
}

if (
    $tahun < 2020 ||
    $tahun > ((int)date('Y') + 1)
) {
    $tahun = (int)date('Y');
}

$startDate = sprintf(
    '%04d-%02d-01',
    $tahun,
    $bulan
);

$endDate = date(
    'Y-m-t',
    strtotime($startDate)
);

$asOfDate = $endDate;

$title =
    'Laporan Aging Periode Bulan ' .
    getMonthName($bulan) .
    ' Tahun ' .
    $tahun .
    ' CAHAYA';

$subtitle =
    getTitleFilter(
        $filterBy,
        $filterValue
    );

$printedAt = date('d-M-Y H:i:s');

/*
 * Nilai penjualan / piutang invoice disamakan dengan Kartu Piutang.
 */
$invoiceGrossExpr = "
    CASE
        WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
        WHEN COALESCE(hi.payment_balance, 0) > 0 THEN COALESCE(hi.payment_balance, 0)
        ELSE COALESCE(hi.grand_total, 0)
    END
";

/*
 * Filter yang sama diterapkan pada data invoice/customer.
 */
$whereInvoice = " WHERE hi.invoice_date <= ? ";
$params = [$endDate];
$types = 's';

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereInvoice .= "
        AND COALESCE(c.area_code, '') = ?
    ";

    $params[] = $filterValue;
    $types .= 's';

} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereInvoice .= "
        AND COALESCE(
                NULLIF(c.city, ''),
                NULLIF(hi.customer_city, '')
            ) = ?
    ";

    $params[] = $filterValue;
    $types .= 's';

} elseif (
    $filterBy === 'pelanggan' &&
    $filterValue !== ''
) {
    $whereInvoice .= "
        AND hi.customer_id = ?
    ";

    $params[] = $filterValue;
    $types .= 's';
}

/*
 * Ambil invoice hingga akhir periode.
 *
 * Pembayaran dan retur cutoff diambil per invoice.
 * Aging awalnya dihitung sebelum retur, kemudian total retur
 * ditempatkan sebagai pengurang pada kolom "Lebih".
 */
$sqlInvoice = "
    SELECT
        hi.invoice_no,
        hi.invoice_date,
        DATE_ADD(
            hi.invoice_date,
            INTERVAL COALESCE(hi.days, 0) DAY
        ) AS due_date,

        hi.customer_id,
        hi.customer_name,
        hi.customer_city,

        COALESCE(c.area_code, '') AS area_code,

        COALESCE(
            NULLIF(c.city, ''),
            NULLIF(hi.customer_city, ''),
            'TANPA KOTA'
        ) AS city,

        $invoiceGrossExpr AS invoice_gross,

        COALESCE((
            SELECT SUM(
                CASE
                    WHEN COALESCE(db.cash_amount, 0) > 0
                        THEN COALESCE(db.cash_amount, 0)
                    ELSE GREATEST(
                        COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
                        0
                    )
                END
            )
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date <= ?
        ), 0) AS pembayaran_cutoff,

        COALESCE((
            SELECT SUM(
                CASE
                    WHEN COALESCE(db.cash_amount, 0) > 0
                        THEN COALESCE(db.cash_amount, 0)
                    ELSE GREATEST(
                        COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
                        0
                    )
                END
            )
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date < ?
        ), 0) AS pembayaran_before_period,

        COALESCE((
            SELECT SUM(
                CASE
                    WHEN COALESCE(db.cash_amount, 0) > 0
                        THEN COALESCE(db.cash_amount, 0)
                    ELSE GREATEST(
                        COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
                        0
                    )
                END
            )
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date BETWEEN ? AND ?
        ), 0) AS pembayaran_period,

        COALESCE((
            SELECT SUM(COALESCE(db.titip_amount, 0))
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date <= ?
        ), 0) AS titip_cutoff,

        COALESCE((
            SELECT SUM(COALESCE(db.titip_amount, 0))
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date < ?
        ), 0) AS titip_before_period,

        COALESCE((
            SELECT SUM(COALESCE(db.titip_amount, 0))
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date BETWEEN ? AND ?
        ), 0) AS titip_period,

        COALESCE((
            SELECT SUM(hri.return_amount)
            FROM head_retur_invoice hri
            WHERE hri.invoice_no = hi.invoice_no
              AND hri.return_date <= ?
              AND LOWER(
                    COALESCE(hri.status, 'Open')
                  ) <> 'cancelled'
        ), 0) AS retur_cutoff,

        COALESCE((
            SELECT SUM(hri.return_amount)
            FROM head_retur_invoice hri
            WHERE hri.invoice_no = hi.invoice_no
              AND hri.return_date < ?
              AND LOWER(
                    COALESCE(hri.status, 'Open')
                  ) <> 'cancelled'
        ), 0) AS retur_before_period,

        COALESCE((
            SELECT SUM(hri.return_amount)
            FROM head_retur_invoice hri
            WHERE hri.invoice_no = hi.invoice_no
              AND hri.return_date BETWEEN ? AND ?
              AND LOWER(
                    COALESCE(hri.status, 'Open')
                  ) <> 'cancelled'
        ), 0) AS retur_period

    FROM head_invoice hi

    LEFT JOIN m_customer c
        ON c.customer_id = hi.customer_id

    LEFT JOIN customer_opening_balance cob
        ON cob.customer_id = hi.customer_id
       AND LOWER(COALESCE(cob.status, 'Active')) = 'active'

    $whereInvoice
      AND (cob.opening_date IS NULL OR hi.invoice_date >= cob.opening_date)

    ORDER BY
        city ASC,
        hi.customer_name ASC,
        hi.invoice_date ASC,
        hi.invoice_no ASC
";

/*
 * Urutan parameter:
 * 1 pembayaran cutoff
 * 2 pembayaran sebelum periode
 * 3-4 pembayaran periode
 * 5 retur cutoff
 * 6 retur sebelum periode
 * 7-8 retur periode
 * 9+ filter invoice
 */
$invoiceParams = [
    // pembayaran cash
    $endDate,
    $startDate,
    $startDate,
    $endDate,

    // titip terpakai
    $endDate,
    $startDate,
    $startDate,
    $endDate,

    // retur
    $endDate,
    $startDate,
    $startDate,
    $endDate,

    ...$params
];

$invoiceTypes =
    'ssssssssssss' .
    $types;

$stmtInvoice = mysqli_prepare(
    $conn,
    $sqlInvoice
);

if (!$stmtInvoice) {
    die(
        'SQL AGING ERROR: ' .
        h(mysqli_error($conn)) .
        '<br><pre>' .
        h($sqlInvoice) .
        '</pre>'
    );
}

mysqli_stmt_bind_param(
    $stmtInvoice,
    $invoiceTypes,
    ...$invoiceParams
);

mysqli_stmt_execute($stmtInvoice);

$resInvoice =
    mysqli_stmt_get_result($stmtInvoice);

/*
 * Struktur customer:
 * customerKey => data customer + nilai.
 */
$customers = [];

/*
 * Muat saldo awal migrasi (berlaku pada awal opening_date) lebih dulu agar customer tetap muncul
 * walaupun belum mempunyai invoice baru pada periode aplikasi.
 */
$whereOpening = "
    WHERE LOWER(COALESCE(cob.status, 'Active')) = 'active'
      AND cob.opening_date <= ?
";
$openingParams = [
    // legacy cash
    $startDate,
    $startDate,
    $endDate,
    $endDate,

    // legacy titip
    $startDate,
    $startDate,
    $endDate,
    $endDate,

    // legacy retur
    $startDate,
    $startDate,
    $endDate,
    $endDate,

    // cutoff opening balance list
    $endDate
];
$openingTypes = 'sssssssssssss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereOpening .= " AND COALESCE(c.area_code, '') = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereOpening .= "
        AND COALESCE(NULLIF(c.city, ''), NULLIF(cob.customer_city, '')) = ?
    ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $whereOpening .= " AND cob.customer_id = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
}

$sqlOpening = "
    SELECT
        cob.customer_id,
        COALESCE(c.customer, cob.customer_name, '') AS customer_name,
        COALESCE(NULLIF(c.city, ''), NULLIF(cob.customer_city, ''), 'TANPA KOTA') AS city,
        cob.opening_date,
        cob.opening_balance,

        /* Pembayaran cash untuk saldo historis / invoice sebelum cut-off. */
        COALESCE((
            SELECT SUM(
                CASE
                    WHEN COALESCE(db.cash_amount, 0) > 0
                        THEN COALESCE(db.cash_amount, 0)
                    ELSE GREATEST(
                        COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
                        0
                    )
                END
            )
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            LEFT JOIN head_invoice hi_old
                ON hi_old.invoice_no = db.invoice_no
            WHERE hb.customer_id = cob.customer_id
              AND hb.bayar_date >= cob.opening_date
              AND hb.bayar_date < ?
              AND (
                    hi_old.invoice_no IS NULL
                    OR hi_old.invoice_date < cob.opening_date
                  )
        ), 0) AS legacy_cash_before,

        COALESCE((
            SELECT SUM(
                CASE
                    WHEN COALESCE(db.cash_amount, 0) > 0
                        THEN COALESCE(db.cash_amount, 0)
                    ELSE GREATEST(
                        COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
                        0
                    )
                END
            )
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            LEFT JOIN head_invoice hi_old
                ON hi_old.invoice_no = db.invoice_no
            WHERE hb.customer_id = cob.customer_id
              AND hb.bayar_date >= cob.opening_date
              AND hb.bayar_date BETWEEN ? AND ?
              AND (
                    hi_old.invoice_no IS NULL
                    OR hi_old.invoice_date < cob.opening_date
                  )
        ), 0) AS legacy_cash_period,

        COALESCE((
            SELECT SUM(
                CASE
                    WHEN COALESCE(db.cash_amount, 0) > 0
                        THEN COALESCE(db.cash_amount, 0)
                    ELSE GREATEST(
                        COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
                        0
                    )
                END
            )
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            LEFT JOIN head_invoice hi_old
                ON hi_old.invoice_no = db.invoice_no
            WHERE hb.customer_id = cob.customer_id
              AND hb.bayar_date >= cob.opening_date
              AND hb.bayar_date <= ?
              AND (
                    hi_old.invoice_no IS NULL
                    OR hi_old.invoice_date < cob.opening_date
                  )
        ), 0) AS legacy_cash_cutoff,

        /* Titip terpakai untuk saldo historis. */
        COALESCE((
            SELECT SUM(COALESCE(db.titip_amount, 0))
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            LEFT JOIN head_invoice hi_old
                ON hi_old.invoice_no = db.invoice_no
            WHERE hb.customer_id = cob.customer_id
              AND hb.bayar_date >= cob.opening_date
              AND hb.bayar_date < ?
              AND (
                    hi_old.invoice_no IS NULL
                    OR hi_old.invoice_date < cob.opening_date
                  )
        ), 0) AS legacy_titip_before,

        COALESCE((
            SELECT SUM(COALESCE(db.titip_amount, 0))
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            LEFT JOIN head_invoice hi_old
                ON hi_old.invoice_no = db.invoice_no
            WHERE hb.customer_id = cob.customer_id
              AND hb.bayar_date >= cob.opening_date
              AND hb.bayar_date BETWEEN ? AND ?
              AND (
                    hi_old.invoice_no IS NULL
                    OR hi_old.invoice_date < cob.opening_date
                  )
        ), 0) AS legacy_titip_period,

        COALESCE((
            SELECT SUM(COALESCE(db.titip_amount, 0))
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            LEFT JOIN head_invoice hi_old
                ON hi_old.invoice_no = db.invoice_no
            WHERE hb.customer_id = cob.customer_id
              AND hb.bayar_date >= cob.opening_date
              AND hb.bayar_date <= ?
              AND (
                    hi_old.invoice_no IS NULL
                    OR hi_old.invoice_date < cob.opening_date
                  )
        ), 0) AS legacy_titip_cutoff,

        /* Retur terhadap saldo historis. */
        COALESCE((
            SELECT SUM(COALESCE(hri.return_amount, 0))
            FROM head_retur_invoice hri
            LEFT JOIN head_invoice hi_old
                ON hi_old.invoice_no = hri.invoice_no
            WHERE hri.customer_id = cob.customer_id
              AND hri.return_date >= cob.opening_date
              AND hri.return_date < ?
              AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
              AND (
                    hi_old.invoice_no IS NULL
                    OR hi_old.invoice_date < cob.opening_date
                  )
        ), 0) AS legacy_retur_before,

        COALESCE((
            SELECT SUM(COALESCE(hri.return_amount, 0))
            FROM head_retur_invoice hri
            LEFT JOIN head_invoice hi_old
                ON hi_old.invoice_no = hri.invoice_no
            WHERE hri.customer_id = cob.customer_id
              AND hri.return_date >= cob.opening_date
              AND hri.return_date BETWEEN ? AND ?
              AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
              AND (
                    hi_old.invoice_no IS NULL
                    OR hi_old.invoice_date < cob.opening_date
                  )
        ), 0) AS legacy_retur_period,

        COALESCE((
            SELECT SUM(COALESCE(hri.return_amount, 0))
            FROM head_retur_invoice hri
            LEFT JOIN head_invoice hi_old
                ON hi_old.invoice_no = hri.invoice_no
            WHERE hri.customer_id = cob.customer_id
              AND hri.return_date >= cob.opening_date
              AND hri.return_date <= ?
              AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
              AND (
                    hi_old.invoice_no IS NULL
                    OR hi_old.invoice_date < cob.opening_date
                  )
        ), 0) AS legacy_retur_cutoff

    FROM customer_opening_balance cob
    LEFT JOIN m_customer c
        ON c.customer_id = cob.customer_id
    $whereOpening
";

$stmtOpening = mysqli_prepare($conn, $sqlOpening);
mysqli_stmt_bind_param($stmtOpening, $openingTypes, ...$openingParams);
mysqli_stmt_execute($stmtOpening);
$resOpening = mysqli_stmt_get_result($stmtOpening);

while ($op = mysqli_fetch_assoc($resOpening)) {
    $customerId = trim((string)($op['customer_id'] ?? ''));
    $customerName = trim((string)($op['customer_name'] ?? ''));
    $city = normalizeCity($op['city'] ?? '');
    $customerKey = $customerId !== '' ? $customerId : $city . '|' . $customerName;

    if (!isset($customers[$customerKey])) {
        $customers[$customerKey] = [
            'customer_id' => $customerId,
            'customer_name' => $customerName !== '' ? $customerName : '-',
            'city' => $city,
            'opening_date' => (string)($op['opening_date'] ?? ''),
            'amounts' => initAmountRow(),
        ];
    }

    $openingAmount = (float)($op['opening_balance'] ?? 0);

    $legacyCashBefore = (float)($op['legacy_cash_before'] ?? 0);
    $legacyCashPeriod = (float)($op['legacy_cash_period'] ?? 0);
    $legacyCashCutoff = (float)($op['legacy_cash_cutoff'] ?? 0);

    $legacyTitipBefore = (float)($op['legacy_titip_before'] ?? 0);
    $legacyTitipPeriod = (float)($op['legacy_titip_period'] ?? 0);
    $legacyTitipCutoff = (float)($op['legacy_titip_cutoff'] ?? 0);

    $legacyReturBefore = (float)($op['legacy_retur_before'] ?? 0);
    $legacyReturPeriod = (float)($op['legacy_retur_period'] ?? 0);
    $legacyReturCutoff = (float)($op['legacy_retur_cutoff'] ?? 0);

    // Saldo awal pada awal bulan = saldo migrasi dikurangi mutasi historis
    // yang terjadi sejak tanggal go-live (termasuk opening_date) tetapi sebelum periode laporan.
    $customers[$customerKey]['amounts']['saldo_awal'] +=
        $openingAmount
        - $legacyCashBefore
        - $legacyTitipBefore
        - $legacyReturBefore;

    // Mutasi periode terhadap saldo historis tetap tampil pada kolom masing-masing.
    $customers[$customerKey]['amounts']['bayar'] += $legacyCashPeriod;
    $customers[$customerKey]['amounts']['titip_used'] += $legacyTitipPeriod;
    $customers[$customerKey]['amounts']['retur'] += $legacyReturPeriod;

    /*
     * Saldo migrasi tidak memiliki umur invoice, sehingga sisa historis
     * ditempatkan di kolom Lebih.
     * Retur Invoice tetap mengurangi Lebih dalam nilai minus.
     */
    $openingAtEnd =
        $openingAmount
        - $legacyCashCutoff
        - $legacyTitipCutoff
        - $legacyReturCutoff;

    $customers[$customerKey]['amounts']['b_lebih'] +=
        $openingAtEnd;
}

mysqli_stmt_close($stmtOpening);

while (
    $row =
    mysqli_fetch_assoc($resInvoice)
) {
    $customerId = trim(
        (string)($row['customer_id'] ?? '')
    );

    $customerName = trim(
        (string)($row['customer_name'] ?? '')
    );

    $city = normalizeCity(
        $row['city'] ?? ''
    );

    $customerKey =
        $customerId !== ''
            ? $customerId
            : $city . '|' . $customerName;

    if (!isset($customers[$customerKey])) {
        $customers[$customerKey] = [
            'customer_id' => $customerId,
            'customer_name' =>
                $customerName !== ''
                    ? $customerName
                    : '-',
            'city' => $city,
            'opening_date' => '',
            'amounts' => initAmountRow(),
        ];
    }

    $amounts =&
        $customers[$customerKey]['amounts'];

    $invoiceDate =
        (string)($row['invoice_date'] ?? '');

    $dueDate =
        (string)($row['due_date'] ?? '');

    $invoiceGross =
        (float)($row['invoice_gross'] ?? 0);

    $titipCutoff =
        (float)($row['titip_cutoff'] ?? 0);

    $titipBefore =
        (float)($row['titip_before_period'] ?? 0);

    $titipPeriod =
        (float)($row['titip_period'] ?? 0);

    $paymentCutoff =
        (float)($row['pembayaran_cutoff'] ?? 0);

    $paymentBefore =
        (float)($row['pembayaran_before_period'] ?? 0);

    $paymentPeriod =
        (float)($row['pembayaran_period'] ?? 0);

    $returnCutoff =
        (float)($row['retur_cutoff'] ?? 0);

    $returnBefore =
        (float)($row['retur_before_period'] ?? 0);

    $returnPeriod =
        (float)($row['retur_period'] ?? 0);

    /*
     * Saldo Awal:
     * hanya invoice sebelum awal periode.
     */
    if (
        $invoiceDate !== '' &&
        $invoiceDate < $startDate
    ) {
        $openingInvoice =
            $invoiceGross
            - $paymentBefore
            - $titipBefore
            - $returnBefore;

        $amounts['saldo_awal'] +=
            $openingInvoice;
    }

    /*
     * Mutasi periode.
     */
    if (
        $invoiceDate >= $startDate &&
        $invoiceDate <= $endDate
    ) {
        $amounts['penjualan'] +=
            $invoiceGross;

        // Titip periode dicatat berdasarkan detail_bayar.titip_amount.

    }

    $amounts['bayar'] +=
        $paymentPeriod;

    $amounts['titip_used'] +=
        $titipPeriod;

    $amounts['retur'] +=
        $returnPeriod;

    /*
     * Aging akhir periode sebelum retur.
     *
     * Retur tidak langsung ditempatkan sesuai umur invoice,
     * karena permintaan laporan adalah menaruh nilai retur
     * sebagai pengurang pada kolom "Lebih".
     */
    $outstandingBeforeReturn =
        $invoiceGross
        - $paymentCutoff
        - $titipCutoff;

    if ($outstandingBeforeReturn > 0.0001) {
        $ageDays = 0;

        if (
            $dueDate !== '' &&
            $dueDate !== '0000-00-00'
        ) {
            $ageDays = (int)floor(
                (
                    strtotime($asOfDate)
                    - strtotime($dueDate)
                ) / 86400
            );
        }

        if ($ageDays <= 0) {
            $amounts['belum_jatuh_tempo'] +=
                $outstandingBeforeReturn;

        } elseif ($ageDays <= 30) {
            $amounts['b_1_30'] +=
                $outstandingBeforeReturn;

        } elseif ($ageDays <= 60) {
            $amounts['b_31_60'] +=
                $outstandingBeforeReturn;

        } elseif ($ageDays <= 90) {
            $amounts['b_61_90'] +=
                $outstandingBeforeReturn;

        } else {
            /*
             * Outstanding > 90 hari tetap masuk kolom Lebih.
             */
            $amounts['b_lebih'] +=
                $outstandingBeforeReturn;
        }
    }

    /*
     * Retur Invoice ditempatkan pada kolom Lebih sebagai nilai minus.
     *
     * Jadi:
     * Lebih = Outstanding >90 hari + saldo historis tanpa umur - Retur.
     */
    $amounts['b_lebih'] -=
        $returnCutoff;

    unset($amounts);
}

mysqli_stmt_close($stmtInvoice);

/*
 * ============================================================
 * SALDO TITIP BELUM TERPAKAI PER AKHIR PERIODE
 * ============================================================
 *
 * Kolom TITIP sekarang adalah saldo deposit customer yang masih tersedia,
 * termasuk titip yang belum pernah dipakai.
 *
 * Sumber:
 * detail_titip.amount_in  = titip masuk
 * detail_titip.amount_out = titip dipakai / keluar
 *
 * Saldo Titip = SUM(amount_in - amount_out) sampai endDate.
 *
 * Saldo ini hanya DISPLAY dan TIDAK mengurangi piutang.
 */
$whereSaldoTitip = " WHERE dt.titip_date <= ? ";
$paramsSaldoTitip = [$endDate];
$typesSaldoTitip = 's';

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereSaldoTitip .= " AND COALESCE(c.area_code, '') = ? ";
    $paramsSaldoTitip[] = $filterValue;
    $typesSaldoTitip .= 's';

} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereSaldoTitip .= "
        AND COALESCE(
                NULLIF(c.city, ''),
                NULLIF(ht.customer_city, '')
            ) = ?
    ";
    $paramsSaldoTitip[] = $filterValue;
    $typesSaldoTitip .= 's';

} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $whereSaldoTitip .= " AND dt.customer_id = ? ";
    $paramsSaldoTitip[] = $filterValue;
    $typesSaldoTitip .= 's';
}

$sqlSaldoTitip = "
    SELECT
        dt.customer_id,
        COALESCE(
            NULLIF(c.customer, ''),
            NULLIF(ht.customer_name, ''),
            '-'
        ) AS customer_name,
        COALESCE(
            NULLIF(c.city, ''),
            NULLIF(ht.customer_city, ''),
            'TANPA KOTA'
        ) AS city,
        COALESCE(
            SUM(
                COALESCE(dt.amount_in, 0)
                - COALESCE(dt.amount_out, 0)
            ),
            0
        ) AS saldo_titip
    FROM detail_titip dt
    LEFT JOIN head_titip ht
        ON ht.titip_no = dt.titip_no
    LEFT JOIN m_customer c
        ON c.customer_id = dt.customer_id
    $whereSaldoTitip
    GROUP BY
        dt.customer_id,
        c.customer,
        ht.customer_name,
        c.city,
        ht.customer_city
";

$stmtSaldoTitip = mysqli_prepare($conn, $sqlSaldoTitip);

if (!$stmtSaldoTitip) {
    die(
        'SQL SALDO TITIP ERROR: ' .
        h(mysqli_error($conn)) .
        '<br><pre>' .
        h($sqlSaldoTitip) .
        '</pre>'
    );
}

mysqli_stmt_bind_param(
    $stmtSaldoTitip,
    $typesSaldoTitip,
    ...$paramsSaldoTitip
);

mysqli_stmt_execute($stmtSaldoTitip);
$resSaldoTitip = mysqli_stmt_get_result($stmtSaldoTitip);

while ($st = mysqli_fetch_assoc($resSaldoTitip)) {
    $customerId = trim((string)($st['customer_id'] ?? ''));
    $customerName = trim((string)($st['customer_name'] ?? ''));
    $city = normalizeCity($st['city'] ?? '');

    $customerKey =
        $customerId !== ''
            ? $customerId
            : $city . '|' . $customerName;

    if (!isset($customers[$customerKey])) {
        $customers[$customerKey] = [
            'customer_id' => $customerId,
            'customer_name' =>
                $customerName !== ''
                    ? $customerName
                    : '-',
            'city' => $city,
            'opening_date' => '',
            'amounts' => initAmountRow(),
        ];
    }

    $customers[$customerKey]['amounts']['titip'] =
        (float)($st['saldo_titip'] ?? 0);
}

mysqli_stmt_close($stmtSaldoTitip);

/*
 * Hitung saldo akhir per customer dan singkirkan customer
 * yang benar-benar tidak mempunyai mutasi maupun saldo.
 */
foreach ($customers as $key => &$customer) {
    $a =& $customer['amounts'];

    $a['akhir'] =
        $a['saldo_awal']
        + $a['penjualan']
        - $a['bayar']
        - $a['titip_used']
        - $a['retur'];

    $activityTotal =
        abs($a['saldo_awal'])
        + abs($a['penjualan'])
        + abs($a['bayar'])
        + abs($a['titip'])
        + abs($a['titip_used'])
        + abs($a['retur'])
        + abs($a['akhir']);

    if ($activityTotal < 0.0001) {
        unset($customers[$key]);
    }

    unset($a);
}
unset($customer);

/*
 * Kelompokkan berdasarkan kota.
 */
$grouped = [];

foreach ($customers as $customer) {
    $city = $customer['city'];

    if (!isset($grouped[$city])) {
        $grouped[$city] = [
            'rows' => [],
            'totals' => initAmountRow(),
        ];
    }

    $grouped[$city]['rows'][] =
        $customer;

    addAmounts(
        $grouped[$city]['totals'],
        $customer['amounts']
    );
}

ksort(
    $grouped,
    SORT_NATURAL | SORT_FLAG_CASE
);

foreach ($grouped as &$cityGroup) {
    usort(
        $cityGroup['rows'],
        function ($a, $b) {
            return strcasecmp(
                $a['customer_name'],
                $b['customer_name']
            );
        }
    );
}
unset($cityGroup);

$grand = initAmountRow();

foreach ($grouped as $cityGroup) {
    addAmounts(
        $grand,
        $cityGroup['totals']
    );
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= h($title) ?></title>

    <style>
        @page {
            size: 377.8mm 279.4mm;
            margin: 5mm 6mm;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family:
                "Courier New",
                Courier,
                monospace;
            font-size: 12px;
            color: #000;
            background: #eef1f5;
            padding: 16px;
        }

        .toolbar {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 16px;
        }

        .btn-action {
            border: none;
            border-radius: 6px;
            color: #fff;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            box-shadow:
                0 2px 6px rgba(0,0,0,.2);
        }

        .btn-back {
            background: #6c757d;
        }

        .btn-print {
            background: #2b5797;
        }

        .btn-action:hover {
            filter: brightness(.92);
            text-decoration: none;
        }

        .screen-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            padding-bottom: 12px;
            -webkit-overflow-scrolling: touch;
        }

        .screen-scroll::-webkit-scrollbar {
            height: 11px;
        }

        .screen-scroll::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }

        .screen-scroll::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 5px;
        }

        .print-wrap {
            width: 1680px;
            min-width: 1680px;
            margin: 0 auto;
            padding: 14px 18px;
            background: #fff;
            box-shadow:
                0 2px 12px rgba(0,0,0,.1);
            border-radius: 4px;
        }

        .title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .subtitle {
            font-size: 11px;
            margin-bottom: 3px;
        }

        .printed {
            text-align: right;
            font-size: 10px;
            color: #555;
            margin-bottom: 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 9px;
        }

        th {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 5px 3px;
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
        }

        td {
            padding: 3px 4px;
            vertical-align: middle;
            white-space: nowrap;
        }

        .customer-col {
            width: 245px;
            text-align: left;
            white-space: normal;
        }

        .money-col {
            width: 112px;
        }

        .money-cell {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .city-row td {
            padding-top: 8px;
            padding-bottom: 3px;
            font-weight: bold;
            font-size: 10px;
            border-top: 1px solid #000;
        }

        .city-total td {
            border-top: 1px solid #777;
            border-bottom: 1px solid #000;
            font-weight: bold;
            background: #f7f7f7;
            padding-top: 4px;
            padding-bottom: 4px;
        }

        .grand-total td {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            font-weight: bold;
            background: #e8e8e8;
            padding-top: 6px;
            padding-bottom: 6px;
        }

        .return-negative {
            color: #a00000;
            font-weight: bold;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .no-data {
            padding: 20px;
            text-align: center;
            color: #777;
            font-size: 12px;
        }

        .report-note {
            margin-top: 7px;
            font-size: 9px;
            color: #555;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .no-print {
                display: none !important;
            }

            .screen-scroll {
                overflow: visible !important;
                padding: 0;
            }

            html,
            body {
                width: 377.8mm !important;
                min-width: 377.8mm !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            }

            .print-wrap {
                width: 365.8mm !important;
                min-width: 365.8mm !important;
                max-width: 365.8mm !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            .title {
                font-size: 10pt;
                line-height: 1.1;
            }

            .subtitle {
                font-size: 8pt;
                line-height: 1.1;
            }

            .printed {
                font-size: 7pt;
                margin-bottom: 2mm;
            }

            table {
                width: 100% !important;
                table-layout: fixed !important;
                font-size: 7pt;
                line-height: 1.05;
            }

            th {
                padding: 1.5mm 0.7mm;
                font-weight: bold;
            }

            td {
                padding: 1.1mm 0.7mm;
            }

            .customer-col {
                width: 55mm !important;
            }

            .money-col {
                width: 28.2mm !important;
            }

            .city-row td {
                font-size: 7.5pt;
                padding-top: 2mm;
                padding-bottom: 0.8mm;
            }

            .city-total td {
                padding-top: 1.2mm;
                padding-bottom: 1.2mm;
            }

            .grand-total td {
                padding-top: 1.5mm;
                padding-bottom: 1.5mm;
            }

            .report-note {
                font-size: 6.5pt;
                margin-top: 2mm;
            }

            .city-total td,
            .grand-total td {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media screen and (max-width: 768px) {
            body {
                padding: 8px;
            }

            .toolbar {
                justify-content: center;
            }

            .btn-action {
                flex: 1;
                text-align: center;
            }

            .print-wrap {
                width: 1500px;
                min-width: 1500px;
            }
        }
    </style>
</head>

<body>

<div class="toolbar no-print">
    <a
        href="../../index.php?page=aging_piutang"
        class="btn-action btn-back"
    >
        KEMBALI
    </a>

    <button
        type="button"
        class="btn-action btn-print"
        onclick="window.print()"
    >
        CETAK LAPORAN
    </button>
</div>

<div class="no-print" style="
    max-width:1680px;
    margin:0 auto 12px;
    padding:9px 12px;
    background:#fffbe6;
    border:1px solid #e0c97f;
    font-family:Arial,Helvetica,sans-serif;
    font-size:12px;
    line-height:1.5;
">
    Setting Epson LQ-2190:
    <strong>Paper 14 7/8 × 11 inch</strong>,
    ukuran <strong>377,8 × 279,4 mm</strong>,
    orientasi <strong>Landscape</strong>,
    skala <strong>100%</strong>,
    margin minimum, dan nonaktifkan header/footer browser.
</div>

<div class="screen-scroll">
    <div class="print-wrap">
        <div class="title">
            <?= h($title) ?>
        </div>

        <div class="subtitle">
            <?= h($subtitle) ?>
        </div>

        <div class="printed">
            Dicetak:
            <?= h($printedAt) ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="customer-col">
                        Nama Customer
                    </th>

                    <th class="money-col">
                        Awal
                    </th>

                    <th class="money-col">
                        Penjualan
                    </th>

                    <th class="money-col">
                        Bayar
                    </th>

                    <th class="money-col">
                        Titip
                    </th>

                    <th class="money-col">
                        Akhir
                    </th>

                    <th class="money-col">
                        1-30 Hari
                    </th>

                    <th class="money-col">
                        31-60 Hari
                    </th>

                    <th class="money-col">
                        61-90 Hari
                    </th>

                    <th class="money-col">
                        Lebih
                    </th>

                    <th class="money-col">
                        Blm Jth. Tempo
                    </th>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($grouped)): ?>
                    <tr>
                        <td
                            colspan="11"
                            class="no-data"
                        >
                            Tidak ada data aging piutang untuk filter ini.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($grouped as $city => $cityGroup): ?>
                        <tr class="city-row">
                            <td colspan="11">
                                <?= h($city) ?>
                            </td>
                        </tr>

                        <?php foreach ($cityGroup['rows'] as $customer): ?>
                            <?php $a = $customer['amounts']; ?>

                            <tr>
                                <td class="customer-col">
                                    <?= h($customer['customer_name']) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['saldo_awal'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['penjualan'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['bayar'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['titip'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['akhir'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['b_1_30'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['b_31_60'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['b_61_90'])) ?>
                                </td>

                                <td
                                    class="money-cell
                                    <?= $a['b_lebih'] < 0 ? 'return-negative' : '' ?>"
                                >
                                    <?= h(formatMoney($a['b_lebih'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['belum_jatuh_tempo'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php $ct = $cityGroup['totals']; ?>

                        <tr class="city-total">
                            <td class="customer-col">
                                TOTAL <?= h($city) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['saldo_awal'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['penjualan'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['bayar'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['titip'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['akhir'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['b_1_30'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['b_31_60'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['b_61_90'])) ?>
                            </td>

                            <td
                                class="money-cell
                                <?= $ct['b_lebih'] < 0 ? 'return-negative' : '' ?>"
                            >
                                <?= h(formatMoney($ct['b_lebih'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['belum_jatuh_tempo'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>

            <tfoot>
                <tr class="grand-total">
                    <td class="customer-col">
                        GRAND TOTAL
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['saldo_awal'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['penjualan'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['bayar'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['titip'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['akhir'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['b_1_30'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['b_31_60'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['b_61_90'])) ?>
                    </td>

                    <td
                        class="money-cell
                        <?= $grand['b_lebih'] < 0 ? 'return-negative' : '' ?>"
                    >
                        <?= h(formatMoney($grand['b_lebih'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['belum_jatuh_tempo'])) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

</body>
</html>