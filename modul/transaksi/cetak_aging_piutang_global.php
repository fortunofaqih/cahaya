<?php
// modul/transaksi/cetak_aging_piutang_global.php
//
// LOGIKA FINAL GO-LIVE AWAL BULAN:
// 1. opening_balance berlaku pada AWAL opening_date.
//    Contoh: saldo akhir 31-Aug-2026 diinput sebagai opening_date 01-Sep-2026.
// 2. Invoice / pembayaran pada opening_date tetap dihitung (>= opening_date).
// 3. Titip masuk (head_titip/detail_titip amount_in) TIDAK mengurangi piutang.
// 4. Kolom Titip = saldo titip customer yang BELUM terpakai per akhir periode.
// 5. Titip yang sudah dipakai (detail_bayar.titip_amount) tetap mengurangi piutang secara INTERNAL.
// 6. Kolom Pembayaran = bagian Cash / Transfer saja.
// 7. Retur Invoice aktif mengurangi piutang.
// 8. Kolom "Lebih" = outstanding >90 hari + saldo historis tanpa umur - Retur Invoice.
// 9. detail_bayar.return_id hanya sebagai link audit/cross-check, tidak mengubah perhitungan.
// 10. Saldo Akhir = Saldo Awal + Penjualan - Pembayaran - Titip Terpakai - Retur.

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

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function formatDateIndo($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function getMonthName($month) {
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

function initRow($label) {
    return [
        'label' => $label,
        'saldo_awal' => 0.0,
        'penjualan' => 0.0,
        'pembayaran' => 0.0,
        'titip' => 0.0,       // DISPLAY: saldo titip belum terpakai
        'titip_used' => 0.0,  // INTERNAL: titip yang dipakai membayar
        'retur' => 0.0,       // INTERNAL / mutasi retur
        'saldo_akhir' => 0.0,
        'b_1_30' => 0.0,
        'b_31_60' => 0.0,
        'b_61_90' => 0.0,
        'b_lebih' => 0.0,
        'belum_jatuh_tempo' => 0.0,
    ];
}

function getGroupLabel($row, $filterBy) {
    $city = trim((string)($row['city'] ?? ''));
    $customerId = trim((string)($row['customer_id'] ?? ''));
    $customerName = trim((string)($row['customer_name'] ?? ''));

    if ($filterBy === 'pelanggan') {
        return trim($customerId . ' - ' . $customerName);
    }

    return $city !== '' ? strtoupper($city) : 'TANPA KOTA';
}

function getLabelTitle($filterBy) {
    if ($filterBy === 'kota') return 'Kota';
    if ($filterBy === 'pelanggan') return 'Pelanggan';
    return 'Daerah';
}

function cashExpr($alias = 'db') {
    return "
        CASE
            WHEN COALESCE({$alias}.cash_amount, 0) > 0
                THEN COALESCE({$alias}.cash_amount, 0)
            ELSE GREATEST(
                COALESCE({$alias}.bayar_amount, 0)
                - COALESCE({$alias}.titip_amount, 0),
                0
            )
        END
    ";
}

$bulan = (int)($_GET['bulan'] ?? date('n'));
$tahun = (int)($_GET['tahun'] ?? date('Y'));
$filterBy = trim((string)($_GET['filter_by'] ?? 'semua'));
$filterValue = trim((string)($_GET['filter_value'] ?? ''));

if ($bulan < 1 || $bulan > 12) {
    $bulan = (int)date('n');
}

if ($tahun < 2020 || $tahun > ((int)date('Y') + 1)) {
    $tahun = (int)date('Y');
}

$startDate = sprintf('%04d-%02d-01', $tahun, $bulan);
$endDate = date('Y-m-t', strtotime($startDate));
$asOfDate = $endDate;

$labelColumn = getLabelTitle($filterBy);

$titleFilter = 'Semua Grup';
if ($filterBy === 'grup') $titleFilter = 'Grup: ' . $filterValue;
if ($filterBy === 'kota') $titleFilter = 'Kota: ' . $filterValue;
if ($filterBy === 'pelanggan') $titleFilter = 'Pelanggan: ' . $filterValue;

$title = 'AGING PIUTANG - GLOBAL - CP';
$subtitle = 'Periode ' . getMonthName($bulan) . ' ' . $tahun . ' | ' . $titleFilter;
$printedAt = date('d-M-Y');

$rows = [];

/*
 * ============================================================
 * 1. SALDO AWAL MIGRASI
 * ============================================================
 *
 * opening_balance adalah saldo awal pada opening_date.
 * Transaksi pada opening_date tetap transaksi baru.
 *
 * Pembayaran / titip setelah opening_date terhadap invoice lama
 * tetap harus mengurangi saldo historis.
 */
$whereOpening = "
    WHERE LOWER(COALESCE(cob.status, 'Active')) = 'active'
      AND cob.opening_date <= ?
";

$openingParams = [
    // legacy cash before / period / cutoff
    $startDate,
    $startDate, $endDate,
    $endDate,

    // legacy titip before / period / cutoff
    $startDate,
    $startDate, $endDate,
    $endDate,

    // legacy retur before / period / cutoff
    $startDate,
    $startDate, $endDate,
    $endDate,

    // daftar opening balance sampai akhir periode
    $endDate
];

$openingTypes = 'sssssssssssss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereOpening .= " AND COALESCE(c.area_code, '') = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';

} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereOpening .= "
        AND COALESCE(
                NULLIF(c.city, ''),
                NULLIF(cob.customer_city, '')
            ) = ?
    ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';

} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $whereOpening .= " AND cob.customer_id = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
}

$cashDb = cashExpr('db');

$sqlOpening = "
    SELECT
        cob.customer_id,

        COALESCE(
            NULLIF(c.customer, ''),
            NULLIF(cob.customer_name, ''),
            '-'
        ) AS customer_name,

        COALESCE(
            NULLIF(c.city, ''),
            NULLIF(cob.customer_city, ''),
            'TANPA KOTA'
        ) AS city,

        COALESCE(c.area_code, '') AS area_code,

        cob.opening_date,
        COALESCE(cob.opening_balance, 0) AS opening_balance,

        /* Cash terhadap saldo historis sebelum awal periode laporan. */
        COALESCE((
            SELECT SUM($cashDb)
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

        /* Cash terhadap saldo historis pada periode laporan. */
        COALESCE((
            SELECT SUM($cashDb)
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

        /* Cash terhadap saldo historis sampai akhir periode. */
        COALESCE((
            SELECT SUM($cashDb)
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

        /* Titip TERPAKAI terhadap saldo historis sebelum awal periode. */
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

        /* Titip TERPAKAI terhadap saldo historis pada periode laporan. */
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

        /* Titip TERPAKAI terhadap saldo historis sampai akhir periode. */
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

        /* Retur aktif terhadap saldo historis sebelum periode. */
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

        /* Retur aktif terhadap saldo historis pada periode laporan. */
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

        /* Retur aktif terhadap saldo historis sampai akhir periode. */
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

if (!$stmtOpening) {
    die(
        'SQL SALDO AWAL ERROR: ' .
        h(mysqli_error($conn)) .
        '<br><pre>' .
        h($sqlOpening) .
        '</pre>'
    );
}

mysqli_stmt_bind_param(
    $stmtOpening,
    $openingTypes,
    ...$openingParams
);

mysqli_stmt_execute($stmtOpening);
$resOpening = mysqli_stmt_get_result($stmtOpening);

while ($op = mysqli_fetch_assoc($resOpening)) {
    $groupLabel = getGroupLabel($op, $filterBy);

    if (!isset($rows[$groupLabel])) {
        $rows[$groupLabel] = initRow($groupLabel);
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

    /*
     * Saldo Awal periode:
     * opening balance dikurangi pembayaran/titip yang sudah terjadi
     * sejak go-live tetapi sebelum bulan laporan.
     */
    $openingAtPeriod =
        $openingAmount
        - $legacyCashBefore
        - $legacyTitipBefore
        - $legacyReturBefore;

    /*
     * Saldo historis tersisa hingga akhir bulan.
     */
    $openingAtEnd =
        $openingAmount
        - $legacyCashCutoff
        - $legacyTitipCutoff
        - $legacyReturCutoff;

    $rows[$groupLabel]['saldo_awal'] += $openingAtPeriod;
    $rows[$groupLabel]['pembayaran'] += $legacyCashPeriod;
    $rows[$groupLabel]['titip_used'] += $legacyTitipPeriod;
    $rows[$groupLabel]['retur'] += $legacyReturPeriod;
    $rows[$groupLabel]['saldo_akhir'] += $openingAtEnd;

    /*
     * Saldo historis migrasi tidak memiliki umur invoice,
     * sehingga sisa akhirnya ditempatkan pada kolom Lebih.
     *
     * openingAtEnd sudah termasuk pengurang Retur.
     */
    $rows[$groupLabel]['b_lebih'] += $openingAtEnd;
}

mysqli_stmt_close($stmtOpening);

/*
 * ============================================================
 * 2. INVOICE BARU SETELAH GO-LIVE
 * ============================================================
 *
 * Invoice sebelum opening_date tidak dibaca ulang karena sudah
 * terkandung dalam opening_balance.
 */
$whereInvoice = "
    WHERE hi.invoice_date <= ?
      AND (
            cob.opening_date IS NULL
            OR hi.invoice_date >= cob.opening_date
          )
";

$invoiceParams = [
    // cash sebelum periode / periode / cutoff
    $startDate,
    $startDate, $endDate,
    $endDate,

    // titip sebelum periode / periode / cutoff
    $startDate,
    $startDate, $endDate,
    $endDate,

    // retur sebelum periode / periode / cutoff
    $startDate,
    $startDate, $endDate,
    $endDate,

    // invoice cutoff
    $endDate
];

$invoiceTypes = 'sssssssssssss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereInvoice .= " AND COALESCE(c.area_code, '') = ? ";
    $invoiceParams[] = $filterValue;
    $invoiceTypes .= 's';

} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereInvoice .= "
        AND COALESCE(
                NULLIF(c.city, ''),
                NULLIF(hi.customer_city, '')
            ) = ?
    ";
    $invoiceParams[] = $filterValue;
    $invoiceTypes .= 's';

} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $whereInvoice .= " AND hi.customer_id = ? ";
    $invoiceParams[] = $filterValue;
    $invoiceTypes .= 's';
}

/*
 * Nilai invoice mengikuti sumber yang sama dengan Kartu Piutang / Aging Detail.
 */
$invoiceAmountExpr = "
    CASE
        WHEN COALESCE(hi.piutang, 0) > 0
            THEN COALESCE(hi.piutang, 0)
        WHEN COALESCE(hi.payment_balance, 0) > 0
            THEN COALESCE(hi.payment_balance, 0)
        ELSE COALESCE(hi.grand_total, 0)
    END
";

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

        $invoiceAmountExpr AS invoice_amount,

        COALESCE((
            SELECT SUM($cashDb)
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date < ?
        ), 0) AS cash_before,

        COALESCE((
            SELECT SUM($cashDb)
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date BETWEEN ? AND ?
        ), 0) AS cash_period,

        COALESCE((
            SELECT SUM($cashDb)
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date <= ?
        ), 0) AS cash_cutoff,

        COALESCE((
            SELECT SUM(COALESCE(db.titip_amount, 0))
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date < ?
        ), 0) AS titip_before,

        COALESCE((
            SELECT SUM(COALESCE(db.titip_amount, 0))
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date BETWEEN ? AND ?
        ), 0) AS titip_period,

        COALESCE((
            SELECT SUM(COALESCE(db.titip_amount, 0))
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date <= ?
        ), 0) AS titip_cutoff,

        COALESCE((
            SELECT SUM(COALESCE(hri.return_amount, 0))
            FROM head_retur_invoice hri
            WHERE hri.invoice_no = hi.invoice_no
              AND hri.return_date < ?
              AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
        ), 0) AS retur_before,

        COALESCE((
            SELECT SUM(COALESCE(hri.return_amount, 0))
            FROM head_retur_invoice hri
            WHERE hri.invoice_no = hi.invoice_no
              AND hri.return_date BETWEEN ? AND ?
              AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
        ), 0) AS retur_period,

        COALESCE((
            SELECT SUM(COALESCE(hri.return_amount, 0))
            FROM head_retur_invoice hri
            WHERE hri.invoice_no = hi.invoice_no
              AND hri.return_date <= ?
              AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
        ), 0) AS retur_cutoff

    FROM head_invoice hi

    LEFT JOIN m_customer c
        ON c.customer_id = hi.customer_id

    LEFT JOIN customer_opening_balance cob
        ON cob.customer_id = hi.customer_id
       AND LOWER(COALESCE(cob.status, 'Active')) = 'active'

    $whereInvoice

    ORDER BY
        hi.customer_id ASC,
        hi.invoice_date ASC,
        hi.invoice_no ASC
";

$stmtInvoice = mysqli_prepare($conn, $sqlInvoice);

if (!$stmtInvoice) {
    die(
        'SQL AGING GLOBAL ERROR: ' .
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
$resInvoice = mysqli_stmt_get_result($stmtInvoice);

while ($inv = mysqli_fetch_assoc($resInvoice)) {
    $groupLabel = getGroupLabel($inv, $filterBy);

    if (!isset($rows[$groupLabel])) {
        $rows[$groupLabel] = initRow($groupLabel);
    }

    $invoiceDate = (string)($inv['invoice_date'] ?? '');
    $dueDate = (string)($inv['due_date'] ?? '');

    $invoiceAmount = (float)($inv['invoice_amount'] ?? 0);

    $cashBefore = (float)($inv['cash_before'] ?? 0);
    $cashPeriod = (float)($inv['cash_period'] ?? 0);
    $cashCutoff = (float)($inv['cash_cutoff'] ?? 0);

    $titipBefore = (float)($inv['titip_before'] ?? 0);
    $titipPeriod = (float)($inv['titip_period'] ?? 0);
    $titipCutoff = (float)($inv['titip_cutoff'] ?? 0);

    $returBefore = (float)($inv['retur_before'] ?? 0);
    $returPeriod = (float)($inv['retur_period'] ?? 0);
    $returCutoff = (float)($inv['retur_cutoff'] ?? 0);

    /*
     * Invoice sebelum bulan laporan tetapi sesudah go-live
     * menjadi bagian dari Saldo Awal bulan laporan.
     */
    if ($invoiceDate !== '' && $invoiceDate < $startDate) {
        $rows[$groupLabel]['saldo_awal'] +=
            $invoiceAmount
            - $cashBefore
            - $titipBefore
            - $returBefore;
    }

    /*
     * Invoice pada bulan laporan menjadi Penjualan.
     */
    if (
        $invoiceDate >= $startDate &&
        $invoiceDate <= $endDate
    ) {
        $rows[$groupLabel]['penjualan'] += $invoiceAmount;
    }

    /*
     * Mutasi bulan laporan:
     * Pembayaran = cash/transfer.
     * Titip = titip yang benar-benar dipakai.
     */
    $rows[$groupLabel]['pembayaran'] += $cashPeriod;
    $rows[$groupLabel]['titip_used'] += $titipPeriod;
    $rows[$groupLabel]['retur'] += $returPeriod;

    /*
     * Saldo invoice sampai akhir bulan.
     */
    $outstandingBeforeReturn =
        $invoiceAmount
        - $cashCutoff
        - $titipCutoff;

    $outstandingEnd =
        $outstandingBeforeReturn
        - $returCutoff;

    $rows[$groupLabel]['saldo_akhir'] += $outstandingEnd;

    /*
     * Aging invoice baru mengikuti umur jatuh tempo.
     */
    if ($outstandingBeforeReturn > 0.0001) {
        $ageDays = 0;

        if ($dueDate !== '' && $dueDate !== '0000-00-00') {
            $ageDays = (int)floor(
                (
                    strtotime($asOfDate)
                    - strtotime($dueDate)
                ) / 86400
            );
        }

        if ($ageDays <= 0) {
            $rows[$groupLabel]['belum_jatuh_tempo'] += $outstandingBeforeReturn;

        } elseif ($ageDays <= 30) {
            $rows[$groupLabel]['b_1_30'] += $outstandingBeforeReturn;

        } elseif ($ageDays <= 60) {
            $rows[$groupLabel]['b_31_60'] += $outstandingBeforeReturn;

        } elseif ($ageDays <= 90) {
            $rows[$groupLabel]['b_61_90'] += $outstandingBeforeReturn;

        } else {
            /*
             * Outstanding >90 hari masuk ke kolom Lebih.
             */
            $rows[$groupLabel]['b_lebih'] += $outstandingBeforeReturn;
        }
    }

    /*
     * Retur selalu ditempatkan pada kolom Lebih sebagai nilai minus.
     *
     * Lebih = outstanding >90 hari + saldo historis tanpa umur - Retur.
     */
    $rows[$groupLabel]['b_lebih'] -= $returCutoff;
}

mysqli_stmt_close($stmtInvoice);

/*
 * ============================================================
 * 3. SALDO TITIP BELUM TERPAKAI PER AKHIR PERIODE
 * ============================================================
 *
 * Kolom Titip hanya display saldo deposit yang masih tersedia.
 * Nilai ini TIDAK mengurangi piutang lagi, karena penggunaan titip
 * sudah dihitung melalui titip_used.
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
        COALESCE(c.area_code, '') AS area_code,
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
        ht.customer_city,
        c.area_code
";

$stmtSaldoTitip = mysqli_prepare($conn, $sqlSaldoTitip);

if ($stmtSaldoTitip) {
    mysqli_stmt_bind_param(
        $stmtSaldoTitip,
        $typesSaldoTitip,
        ...$paramsSaldoTitip
    );
    mysqli_stmt_execute($stmtSaldoTitip);
    $resSaldoTitip = mysqli_stmt_get_result($stmtSaldoTitip);

    while ($st = mysqli_fetch_assoc($resSaldoTitip)) {
        $groupLabel = getGroupLabel($st, $filterBy);

        if (!isset($rows[$groupLabel])) {
            $rows[$groupLabel] = initRow($groupLabel);
        }

        $rows[$groupLabel]['titip'] +=
            (float)($st['saldo_titip'] ?? 0);
    }

    mysqli_stmt_close($stmtSaldoTitip);
}

/*
 * Singkirkan baris benar-benar kosong.
 */
foreach ($rows as $key => $row) {
    $activity =
        abs((float)$row['saldo_awal'])
        + abs((float)$row['penjualan'])
        + abs((float)$row['pembayaran'])
        + abs((float)$row['titip'])
        + abs((float)$row['titip_used'])
        + abs((float)$row['retur'])
        + abs((float)$row['saldo_akhir']);

    if ($activity < 0.0001) {
        unset($rows[$key]);
    }
}

ksort(
    $rows,
    SORT_NATURAL | SORT_FLAG_CASE
);

$grand = initRow('GRAND TOTAL');

foreach ($rows as $row) {
    foreach ($grand as $key => $value) {
        if ($key !== 'label') {
            $grand[$key] += (float)($row[$key] ?? 0);
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            font-family: "Courier New", Courier, monospace;
            font-size: 12px;
            color: #000;
            background: #eef1f5;
            padding: 16px;
        }

        /* Toolbar Tombol Cetak */
        .toolbar {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }

        .btn-print {
            border: none;
            border-radius: 6px;
            background: #2b5797;
            color: #fff;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
            transition: 0.2s;
        }

        .btn-print:hover {
            background: #1a3f6a;
            transform: scale(1.02);
        }

        /* Container Scroll */
        .screen-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            padding-bottom: 12px;
            -webkit-overflow-scrolling: touch;
        }

        .screen-scroll::-webkit-scrollbar {
            height: 10px;
        }
        .screen-scroll::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }
        .screen-scroll::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 5px;
        }
        .screen-scroll::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        .print-wrap {
            width: 1680px;
            min-width: 1680px;
            margin: 0 auto;
            padding: 20px 24px;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            border-radius: 4px;
        }

        .title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .subtitle {
            text-align: center;
            font-size: 13px;
            margin-bottom: 2px;
        }

        .printed {
            text-align: right;
            font-size: 11px;
            margin-bottom: 8px;
            color: #555;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        th {
            border: 1px solid #000;
            background: #e8e8e8;
            padding: 6px 4px;
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
        }

        td {
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            padding: 5px 4px;
            vertical-align: middle;
            white-space: nowrap;
        }

        tbody tr:first-child td {
            border-top: 1px solid #000;
        }
        tbody tr:last-child td {
            border-bottom: 1px solid #000;
        }

        tfoot td {
            border: 1px solid #000;
            background: #e8e8e8;
            font-weight: bold;
            padding: 6px 4px;
        }

        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }

        .money-cell {
            text-align: right;
        }

        .label-cell {
            white-space: normal;
            font-weight: 500;
        }

        .return-negative {
            color: #a00000;
            font-weight: bold;
        }

        /* Print Styles */
        @media print {
            html,
            body {
                width: 377.8mm !important;
                min-width: 377.8mm !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                background: #fff !important;
            }

            body {
                display: block !important;
                font-family: "Courier New", Courier, monospace !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .no-print {
                display: none !important;
            }

            .screen-scroll {
                width: 365.8mm !important;
                min-width: 365.8mm !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .screen-scroll::-webkit-scrollbar {
                display: none !important;
            }

            .print-wrap {
                width: 365.8mm !important;
                min-width: 365.8mm !important;
                max-width: 365.8mm !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                background: #fff !important;
            }

            .title {
                font-size: 10pt !important;
                line-height: 1.1 !important;
                margin-bottom: 0.8mm !important;
            }

            .subtitle {
                font-size: 8pt !important;
                line-height: 1.1 !important;
                margin-bottom: 0.8mm !important;
            }

            .printed {
                font-size: 7pt !important;
                margin-bottom: 2mm !important;
            }

            table {
                width: 100% !important;
                table-layout: fixed !important;
                border-collapse: collapse !important;
                font-size: 7pt !important;
                line-height: 1.05 !important;
            }

            th {
                padding: 1.5mm 0.7mm !important;
                background: #f2f2f2 !important;
                font-weight: bold !important;
                white-space: nowrap !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            td {
                padding: 1.1mm 0.7mm !important;
                white-space: nowrap !important;
            }

            th:nth-child(1),
            td:nth-child(1) {
                width: 9mm !important;
            }

            th:nth-child(2),
            td:nth-child(2) {
                width: 47mm !important;
            }

            th:nth-child(n+3),
            td:nth-child(n+3) {
                width: 28.2mm !important;
            }

            .label-cell {
                white-space: normal !important;
                overflow-wrap: anywhere !important;
            }

            tfoot td {
                padding: 1.5mm 0.7mm !important;
                background: #f2f2f2 !important;
                font-weight: bold !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            thead {
                display: table-header-group !important;
            }

            tfoot {
                display: table-footer-group !important;
            }
        }

        /* Mobile */
        @media screen and (max-width: 768px) {
            body {
                padding: 8px;
            }

            .toolbar {
                justify-content: center;
            }

            .btn-print {
                width: 100%;
                padding: 14px;
                font-size: 16px;
            }

            .print-wrap {
                padding: 12px;
                min-width: 1500px;
                width: 1500px;
            }
        }
    </style>
</head>
<body>

<!-- Toolbar Cetak -->
<div class="toolbar no-print">
    <button type="button" class="btn-print" onclick="window.print()">
        🖨️ CETAK LAPORAN
    </button>
</div>

<div class="no-print" style="
    width:100%;
    max-width:1680px;
    margin:0 auto 12px;
    padding:9px 12px;
    background:#fffbe6;
    border:1px solid #d8c26e;
    font-family:Arial,Helvetica,sans-serif;
    font-size:12px;
    line-height:1.5;
">
    Setting Epson LQ-2190:
    pilih <strong>US Std Fanfold</strong>,
    ukuran <strong>14 7/8 × 11 inci</strong>
    atau <strong>377,8 × 279,4 mm</strong>,
    orientasi <strong>Landscape</strong>,
    skala <strong>100%</strong>,
    margin minimum, serta nonaktifkan Header dan Footer browser.
</div>

<!-- Container Scroll -->
<div class="screen-scroll">
    <div class="print-wrap">
        <div class="title"><?= h($title) ?></div>
        <div class="subtitle"><?= h($subtitle) ?></div>
        <div class="printed">Dicetak: <?= h($printedAt) ?></div>

        <table>
            <thead>
                <tr>
                    <th style="width:32px;">No</th>
                    <th style="width:145px;"><?= h($labelColumn) ?></th>
                    <th style="width:85px;">Saldo Awal</th>
                    <th style="width:85px;">Penjualan</th>
                    <th style="width:85px;">Pembayaran</th>
                    <th style="width:85px;">Titip</th>
                    <th style="width:85px;">Saldo Akhir</th>
                    <th style="width:78px;">1 - 30 Hari</th>
                    <th style="width:78px;">31 - 60 Hari</th>
                    <th style="width:78px;">61 - 90 Hari</th>
                    <th style="width:78px;">Lebih / Retur</th>
                    <th style="width:88px;">Belum Jatuh Tempo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="12" class="text-center" style="padding:20px; font-size:13px; color:#999;">
                            Tidak ada data aging piutang untuk filter ini.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php $no = 1; ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td class="text-center"><?= $no++ ?></td>
                            <td class="label-cell"><?= h($row['label']) ?></td>
                            <td class="money-cell"><?= h(formatMoney($row['saldo_awal'])) ?></td>
                            <td class="money-cell"><?= h(formatMoney($row['penjualan'])) ?></td>
                            <td class="money-cell"><?= h(formatMoney($row['pembayaran'])) ?></td>
                            <td class="money-cell"><?= h(formatMoney($row['titip'])) ?></td>
                            <td class="money-cell"><?= h(formatMoney($row['saldo_akhir'])) ?></td>
                            <td class="money-cell"><?= h(formatMoney($row['b_1_30'])) ?></td>
                            <td class="money-cell"><?= h(formatMoney($row['b_31_60'])) ?></td>
                            <td class="money-cell"><?= h(formatMoney($row['b_61_90'])) ?></td>
                            <td class="money-cell <?= $row['b_lebih'] < 0 ? 'return-negative' : '' ?>"><?= h(formatMoney($row['b_lebih'])) ?></td>
                            <td class="money-cell"><?= h(formatMoney($row['belum_jatuh_tempo'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2" class="text-right">GRAND TOTAL</td>
                    <td class="money-cell"><?= h(formatMoney($grand['saldo_awal'])) ?></td>
                    <td class="money-cell"><?= h(formatMoney($grand['penjualan'])) ?></td>
                    <td class="money-cell"><?= h(formatMoney($grand['pembayaran'])) ?></td>
                    <td class="money-cell"><?= h(formatMoney($grand['titip'])) ?></td>  
                    <td class="money-cell"><?= h(formatMoney($grand['saldo_akhir'])) ?></td>
                    <td class="money-cell"><?= h(formatMoney($grand['b_1_30'])) ?></td>
                    <td class="money-cell"><?= h(formatMoney($grand['b_31_60'])) ?></td>
                    <td class="money-cell"><?= h(formatMoney($grand['b_61_90'])) ?></td>
                    <td class="money-cell <?= $grand['b_lebih'] < 0 ? 'return-negative' : '' ?>"><?= h(formatMoney($grand['b_lebih'])) ?></td>
                    <td class="money-cell"><?= h(formatMoney($grand['belum_jatuh_tempo'])) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

</body>
</html>