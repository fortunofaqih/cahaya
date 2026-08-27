<?php
/*
 * modul/transaksi/cetak_aging_piutang_detail.php
 *
 * RULE FINAL AGING PIUTANG:
 * 1. Customer yang tampil WAJIB masih ada dan aktif di m_customer.
 *    Customer yang sudah dihapus dari master tidak akan tampil lagi walaupun
 *    masih memiliki histori invoice/payment/return.
 * 2. SALDO AWAL carry-forward dari posisi akhir periode sebelumnya; customer_opening_balance hanya base migrasi.
 * 3. Untuk customer yang mempunyai saldo awal pada opening_date:
 *      - invoice dengan invoice_date <= opening_date dianggap sudah tercakup
 *        di saldo awal dan TIDAK dihitung lagi sebagai Penjualan.
 *      - invoice setelah opening_date dihitung sebagai Penjualan.
 *      - pembayaran setelah opening_date dihitung sebagai Bayar/Titip.
 * 4. Untuk customer yang TIDAK mempunyai saldo awal:
 *      - seluruh invoice valid sampai akhir periode dihitung sebagai Penjualan,
 *        termasuk invoice Juli yang masih ada di sistem.
 *      - seluruh pembayaran sampai akhir periode dihitung sebagai Bayar/Titip.
 * 5. Invoice / Shipping CP-MCP tidak masuk Penjualan/Pembayaran normal.
 * 6. Retur CP-MCP tetap dihitung standalone memakai grand_total.
 *    Retur normal memakai return_amount.
 * 7. Retur selalu mengurangi Penjualan Neto dan ditempatkan sebagai nilai MINUS
 *    di bucket "Lebih" sesuai rule user.
 * 8. TITIP pada Aging = detail_bayar.titip_amount yang SUDAH DIPAKAI.
 *    Saldo titip belum terpakai tidak ditampilkan dan tidak mengurangi piutang.
 * 9. Rumus wajib balance:
 *      Saldo Awal + Penjualan - Bayar - Titip = Akhir
 *      1-30 + 31-60 + 61-90 + Lebih + Belum Jatuh Tempo = Akhir
 */

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
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatMoney($value)
{
    return number_format((float)$value, 2, ',', '.');
}

function getMonthName($month)
{
    $names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
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
        'titip_used' => 0.0,
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
    return $city !== '' ? strtoupper($city) : 'TANPA KOTA';
}

function paymentCashComponent(array $row)
{
    $cash = (float)($row['cash_amount'] ?? 0);
    if ($cash > 0) {
        return $cash;
    }

    return max(
        (float)($row['bayar_amount'] ?? 0) - (float)($row['titip_amount'] ?? 0),
        0
    );
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

$title = 'Laporan Aging Periode Bulan ' . getMonthName($bulan) . ' Tahun ' . $tahun . ' CAHAYA';
$subtitle = getTitleFilter($filterBy, $filterValue);
$printedAt = date('d-M-Y H:i:s');

/* ============================================================
 * 1. MASTER CUSTOMER AKTIF = GERBANG UTAMA
 * ============================================================
 * Penting: gunakan m_customer sebagai source of truth.
 * Histori transaksi yang customer_id-nya sudah tidak ada di master
 * tidak akan dimasukkan ke Aging.
 */
$whereCustomer = "
    WHERE COALESCE(mc.is_active, 'Checked') = 'Checked'
";
$customerParams = [];
$customerTypes = '';

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereCustomer .= " AND COALESCE(mc.area_code, '') = ? ";
    $customerParams[] = $filterValue;
    $customerTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereCustomer .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $customerParams[] = $filterValue;
    $customerTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $whereCustomer .= " AND mc.customer_id = ? ";
    $customerParams[] = $filterValue;
    $customerTypes .= 's';
}

$sqlCustomer = "
    SELECT
        mc.customer_id,
        mc.customer,
        mc.city,
        mc.area_code
    FROM m_customer mc
    $whereCustomer
";

$stmtCustomer = mysqli_prepare($conn, $sqlCustomer);
if ($customerTypes !== '') {
    mysqli_stmt_bind_param($stmtCustomer, $customerTypes, ...$customerParams);
}
mysqli_stmt_execute($stmtCustomer);
$resCustomer = mysqli_stmt_get_result($stmtCustomer);

$masterCustomers = [];
while ($mc = mysqli_fetch_assoc($resCustomer)) {
    $cid = trim((string)$mc['customer_id']);
    if ($cid === '') {
        continue;
    }
    $masterCustomers[$cid] = [
        'customer_id' => $cid,
        'customer_name' => trim((string)($mc['customer'] ?? '')) ?: '-',
        'city' => normalizeCity($mc['city'] ?? ''),
        'area_code' => trim((string)($mc['area_code'] ?? '')),
    ];
}
mysqli_stmt_close($stmtCustomer);

/* Struktur hasil per customer. */
$customers = [];
$openingMap = [];
$openingBucket = [];
$invoiceMeta = [];
$invoicePaymentMap = [];
$orphanPaymentBucket = [];

function ensureCustomerRow(array &$customers, array $masterCustomers, string $customerId)
{
    if (isset($customers[$customerId])) {
        return true;
    }
    if (!isset($masterCustomers[$customerId])) {
        return false;
    }

    $m = $masterCustomers[$customerId];
    $customers[$customerId] = [
        'customer_id' => $customerId,
        'customer_name' => $m['customer_name'],
        'city' => $m['city'],
        'opening_date' => '',
        'amounts' => initAmountRow(),
        'selisih_balance' => 0.0,
    ];
    return true;
}

/* ============================================================
 * 2. CUT-OFF & OPENING BALANCE
 * ============================================================
 * Saldo Awal bulan berjalan = posisi piutang per H-1 periode.
 * customer_opening_balance hanya menjadi BASE migrasi, bukan angka yang
 * diulang terus setiap bulan.
 */
$previousDate = date('Y-m-d', strtotime($startDate . ' -1 day'));

$sqlOpening = "
    SELECT
        cob.opening_id,
        cob.opening_date,
        cob.customer_id,
        cob.opening_balance
    FROM customer_opening_balance cob
    INNER JOIN (
        SELECT customer_id, MAX(opening_id) AS opening_id
        FROM customer_opening_balance
        WHERE LOWER(COALESCE(status, 'Active')) = 'active'
          AND opening_date <= ?
        GROUP BY customer_id
    ) latest
        ON latest.opening_id = cob.opening_id
    INNER JOIN m_customer mc
        ON mc.customer_id = cob.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    WHERE 1=1
";

$openingParams = [$endDate];
$openingTypes = 's';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlOpening .= " AND COALESCE(mc.area_code, '') = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlOpening .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlOpening .= " AND mc.customer_id = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
}

$stmtOpening = mysqli_prepare($conn, $sqlOpening);
mysqli_stmt_bind_param($stmtOpening, $openingTypes, ...$openingParams);
mysqli_stmt_execute($stmtOpening);
$resOpening = mysqli_stmt_get_result($stmtOpening);

$globalOpeningCutoff = '';
while ($op = mysqli_fetch_assoc($resOpening)) {
    $cid = trim((string)($op['customer_id'] ?? ''));
    if ($cid === '' || !isset($masterCustomers[$cid])) {
        continue;
    }

    $openingMap[$cid] = [
        'opening_id' => (int)($op['opening_id'] ?? 0),
        'opening_date' => (string)($op['opening_date'] ?? ''),
        'opening_balance' => (float)($op['opening_balance'] ?? 0),
    ];

    $od = (string)($op['opening_date'] ?? '');
    if ($od !== '' && ($globalOpeningCutoff === '' || $od > $globalOpeningCutoff)) {
        $globalOpeningCutoff = $od;
    }
}
mysqli_stmt_close($stmtOpening);

/* Cut-off migrasi harus GLOBAL, tidak boleh mengikuti filter customer/kota. */
$stmtGlobalCutoff = mysqli_prepare(
    $conn,
    "
    SELECT MAX(cob.opening_date) AS cutoff_date
    FROM customer_opening_balance cob
    INNER JOIN m_customer mc
        ON mc.customer_id = cob.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    WHERE LOWER(COALESCE(cob.status, 'Active')) = 'active'
      AND cob.opening_date <= ?
    "
);
mysqli_stmt_bind_param($stmtGlobalCutoff, 's', $endDate);
mysqli_stmt_execute($stmtGlobalCutoff);
$resGlobalCutoff = mysqli_stmt_get_result($stmtGlobalCutoff);
$rowGlobalCutoff = mysqli_fetch_assoc($resGlobalCutoff);
$globalOpeningCutoff = trim((string)($rowGlobalCutoff['cutoff_date'] ?? ''));
mysqli_stmt_close($stmtGlobalCutoff);

/*
 * Periode pertama setelah cut-off migrasi mempunyai perlakuan khusus untuk
 * customer TANPA opening balance: invoice legacy sebelum startDate tetap
 * ditampilkan sebagai Penjualan, sesuai rule MARDI.
 * Mulai bulan berikutnya, posisi akhir bulan sebelumnya otomatis menjadi Awal.
 */
$firstOperationalDate = $globalOpeningCutoff !== ''
    ? date('Y-m-d', strtotime($globalOpeningCutoff . ' +1 day'))
    : '';
$isInitialMigrationPeriod = ($firstOperationalDate !== '' && $startDate === $firstOperationalDate);

/* Temp result per customer supaya finalisasi ringan. */
$calc = [];
foreach ($masterCustomers as $cid => $m) {
    $calc[$cid] = [
        'pre_invoice' => 0.0,
        'period_invoice' => 0.0,
        'pre_cash' => 0.0,
        'pre_titip' => 0.0,
        'period_cash' => 0.0,
        'period_titip' => 0.0,
        'opening_target_total' => 0.0,
        'orphan_total' => 0.0,
        'pre_return' => 0.0,
        'period_return' => 0.0,
        'total_return' => 0.0,
        'b_1_30' => 0.0,
        'b_31_60' => 0.0,
        'b_61_90' => 0.0,
        'b_lebih' => 0.0,
        'belum_jatuh_tempo' => 0.0,
    ];
}

/* ============================================================
 * 3. INVOICE + AGING BUCKET (AGREGASI SQL)
 * ============================================================
 * Pembayaran invoice diagregasi terlebih dahulu per invoice. Dengan cara ini
 * PHP tidak perlu loop semua detail pembayaran untuk membentuk outstanding.
 */
$invoiceGrossExprSql = "
    CASE
        WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
        WHEN COALESCE(hi.payment_balance, 0) > 0 THEN COALESCE(hi.payment_balance, 0)
        ELSE COALESCE(hi.grand_total, 0)
    END
";

$sqlInvoiceAgg = "
WITH latest_opening AS (
    SELECT cob.customer_id, cob.opening_date, cob.opening_balance
    FROM customer_opening_balance cob
    INNER JOIN (
        SELECT customer_id, MAX(opening_id) AS opening_id
        FROM customer_opening_balance
        WHERE LOWER(COALESCE(status, 'Active')) = 'active'
          AND opening_date <= ?
        GROUP BY customer_id
    ) x ON x.opening_id = cob.opening_id
),
pay_inv AS (
    SELECT
        db.invoice_no,
        SUM(
            /*
             * Cash mengikuti tanggal pembayaran (head_bayar.bayar_date).
             * Titip mengikuti tanggal PEMAKAIAN titip, yaitu saat detail_bayar
             * dibuat. Ini penting karena tanggal bayar bisa tetap memakai tanggal
             * asal titip yang bahkan lebih lama daripada tanggal invoice.
             */
            CASE
                WHEN hb.bayar_date <= ? THEN
                    CASE
                        WHEN COALESCE(db.cash_amount, 0) > 0 THEN COALESCE(db.cash_amount, 0)
                        ELSE GREATEST(COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0), 0)
                    END
                ELSE 0
            END
            +
            CASE
                WHEN DATE(COALESCE(db.date_created, hb.date_created, hb.bayar_date)) <= ?
                THEN COALESCE(db.titip_amount, 0)
                ELSE 0
            END
        ) AS paid_total
    FROM detail_bayar db
    INNER JOIN head_bayar hb ON hb.bayar_no = db.bayar_no
    WHERE (
            hb.bayar_date <= ?
         OR DATE(COALESCE(db.date_created, hb.date_created, hb.bayar_date)) <= ?
    )
      AND COALESCE(db.invoice_no, '') <> ''
      AND UPPER(COALESCE(db.invoice_no, '')) NOT LIKE '%CP-MCP%'
      AND UPPER(COALESCE(db.shipping_no, '')) NOT LIKE '%CP-MCP%'
    GROUP BY db.invoice_no
),
inv_base AS (
    SELECT
        hi.customer_id,
        hi.invoice_no,
        hi.invoice_date,
        DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days, 0) DAY) AS due_date,
        lo.opening_date,
        $invoiceGrossExprSql AS gross,
        COALESCE(pi.paid_total, 0) AS paid_total
    FROM head_invoice hi
    INNER JOIN m_customer mc
        ON mc.customer_id = hi.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    LEFT JOIN latest_opening lo ON lo.customer_id = hi.customer_id
    LEFT JOIN pay_inv pi ON pi.invoice_no = hi.invoice_no
    WHERE hi.invoice_date <= ?
      AND UPPER(COALESCE(hi.invoice_no, '')) NOT LIKE '%CP-MCP%'
";

$invoiceAggParams = [$endDate, $endDate, $endDate, $endDate, $endDate, $endDate];
$invoiceAggTypes = 'ssssss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlInvoiceAgg .= " AND COALESCE(mc.area_code, '') = ? ";
    $invoiceAggParams[] = $filterValue;
    $invoiceAggTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlInvoiceAgg .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $invoiceAggParams[] = $filterValue;
    $invoiceAggTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlInvoiceAgg .= " AND mc.customer_id = ? ";
    $invoiceAggParams[] = $filterValue;
    $invoiceAggTypes .= 's';
}

$sqlInvoiceAgg .= "
),
eligible AS (
    SELECT *, (gross - paid_total) AS outstanding
    FROM inv_base
    WHERE opening_date IS NULL OR invoice_date > opening_date
)
SELECT
    customer_id,
    SUM(CASE WHEN invoice_date < ? THEN gross ELSE 0 END) AS pre_invoice,
    SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN gross ELSE 0 END) AS period_invoice,

    SUM(CASE
        WHEN outstanding < 0 THEN outstanding
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) > 90 THEN outstanding
        ELSE 0 END
    ) AS b_lebih,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) BETWEEN 61 AND 90 THEN outstanding
        ELSE 0 END
    ) AS b_61_90,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) BETWEEN 31 AND 60 THEN outstanding
        ELSE 0 END
    ) AS b_31_60,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) BETWEEN 1 AND 30 THEN outstanding
        ELSE 0 END
    ) AS b_1_30,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) <= 0 THEN outstanding
        ELSE 0 END
    ) AS belum_jatuh_tempo
FROM eligible
GROUP BY customer_id
";

$invoiceAggParams[] = $startDate;
$invoiceAggParams[] = $startDate;
$invoiceAggParams[] = $endDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggTypes .= 'ssssssss';

$stmtInvoiceAgg = mysqli_prepare($conn, $sqlInvoiceAgg);
mysqli_stmt_bind_param($stmtInvoiceAgg, $invoiceAggTypes, ...$invoiceAggParams);
mysqli_stmt_execute($stmtInvoiceAgg);
$resInvoiceAgg = mysqli_stmt_get_result($stmtInvoiceAgg);

while ($r = mysqli_fetch_assoc($resInvoiceAgg)) {
    $cid = trim((string)($r['customer_id'] ?? ''));
    if (!isset($calc[$cid])) {
        continue;
    }
    foreach (['pre_invoice','period_invoice','b_lebih','b_61_90','b_31_60','b_1_30','belum_jatuh_tempo'] as $k) {
        $calc[$cid][$k] = (float)($r[$k] ?? 0);
    }
}
mysqli_stmt_close($stmtInvoiceAgg);

/* ============================================================
 * 4. PEMBAYARAN / TITIP USED (AGREGASI PER CUSTOMER)
 * ============================================================ */
$sqlPayAgg = "
WITH latest_opening AS (
    SELECT cob.customer_id, cob.opening_date, cob.opening_balance
    FROM customer_opening_balance cob
    INNER JOIN (
        SELECT customer_id, MAX(opening_id) AS opening_id
        FROM customer_opening_balance
        WHERE LOWER(COALESCE(status, 'Active')) = 'active'
          AND opening_date <= ?
        GROUP BY customer_id
    ) x ON x.opening_id = cob.opening_id
),
pay_base AS (
    SELECT
        hb.customer_id,
        hb.bayar_date AS cash_date,
        DATE(COALESCE(db.date_created, hb.date_created, hb.bayar_date)) AS titip_date,
        lo.opening_date,
        lo.opening_balance,
        db.invoice_no,
        db.opening_id,
        UPPER(COALESCE(db.payment_source, 'INVOICE')) AS payment_source,
        hi.invoice_date AS linked_invoice_date,
        CASE
            WHEN COALESCE(db.cash_amount, 0) > 0 THEN COALESCE(db.cash_amount, 0)
            ELSE GREATEST(COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0), 0)
        END AS cash_raw,
        COALESCE(db.titip_amount, 0) AS titip_raw,
        CASE
            WHEN lo.opening_date IS NOT NULL
             AND (
                    UPPER(COALESCE(db.payment_source, 'INVOICE')) = 'OPENING'
                 OR COALESCE(db.opening_id, 0) > 0
                 OR COALESCE(db.invoice_no, '') = ''
                 OR hi.invoice_date <= lo.opening_date
                 OR hi.invoice_no IS NULL
             ) THEN 1
            ELSE 0
        END AS is_opening_target,
        CASE
            WHEN lo.opening_date IS NULL
             AND (COALESCE(db.invoice_no, '') = '' OR hi.invoice_no IS NULL) THEN 1
            ELSE 0
        END AS is_orphan,
        CASE WHEN COALESCE(lo.opening_balance, 0) < 0 THEN -1 ELSE 1 END AS opening_sign
    FROM detail_bayar db
    INNER JOIN head_bayar hb ON hb.bayar_no = db.bayar_no
    INNER JOIN m_customer mc
        ON mc.customer_id = hb.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    LEFT JOIN latest_opening lo ON lo.customer_id = hb.customer_id
    LEFT JOIN head_invoice hi ON hi.invoice_no = db.invoice_no
    WHERE (
            hb.bayar_date <= ?
         OR DATE(COALESCE(db.date_created, hb.date_created, hb.bayar_date)) <= ?
    )
      AND UPPER(COALESCE(db.invoice_no, '')) NOT LIKE '%CP-MCP%'
      AND UPPER(COALESCE(db.shipping_no, '')) NOT LIKE '%CP-MCP%'
";

$payAggParams = [$endDate, $endDate, $endDate];
$payAggTypes = 'sss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlPayAgg .= " AND COALESCE(mc.area_code, '') = ? ";
    $payAggParams[] = $filterValue;
    $payAggTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlPayAgg .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $payAggParams[] = $filterValue;
    $payAggTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlPayAgg .= " AND mc.customer_id = ? ";
    $payAggParams[] = $filterValue;
    $payAggTypes .= 's';
}

$sqlPayAgg .= "
)
SELECT
    customer_id,

    /* Cash mengikuti tanggal bayar. */
    SUM(CASE
        WHEN cash_date < ?
         AND (opening_date IS NULL OR cash_date > opening_date)
        THEN cash_raw * (CASE WHEN is_opening_target = 1 THEN opening_sign ELSE 1 END)
        ELSE 0 END
    ) AS pre_cash,

    /* Titip mengikuti tanggal pemakaian, bukan tanggal asal titip. */
    SUM(CASE
        WHEN titip_date < ?
         AND (opening_date IS NULL OR titip_date > opening_date)
        THEN titip_raw * (CASE WHEN is_opening_target = 1 THEN opening_sign ELSE 1 END)
        ELSE 0 END
    ) AS pre_titip,

    SUM(CASE
        WHEN cash_date BETWEEN ? AND ?
         AND (opening_date IS NULL OR cash_date > opening_date)
        THEN cash_raw * (CASE WHEN is_opening_target = 1 THEN opening_sign ELSE 1 END)
        ELSE 0 END
    ) AS period_cash,

    SUM(CASE
        WHEN titip_date BETWEEN ? AND ?
         AND (opening_date IS NULL OR titip_date > opening_date)
        THEN titip_raw * (CASE WHEN is_opening_target = 1 THEN opening_sign ELSE 1 END)
        ELSE 0 END
    ) AS period_titip,

    SUM(CASE WHEN is_opening_target = 1 THEN
        (
            CASE
                WHEN cash_date <= ? AND (opening_date IS NULL OR cash_date > opening_date)
                THEN cash_raw ELSE 0
            END
            +
            CASE
                WHEN titip_date <= ? AND (opening_date IS NULL OR titip_date > opening_date)
                THEN titip_raw ELSE 0
            END
        ) * opening_sign
        ELSE 0 END
    ) AS opening_target_total,

    SUM(CASE WHEN is_orphan = 1 THEN
        (
            CASE WHEN cash_date <= ? THEN cash_raw ELSE 0 END
            + CASE WHEN titip_date <= ? THEN titip_raw ELSE 0 END
        )
        ELSE 0 END
    ) AS orphan_total
FROM pay_base
GROUP BY customer_id
";

$payAggParams[] = $startDate;
$payAggParams[] = $startDate;
$payAggParams[] = $startDate;
$payAggParams[] = $endDate;
$payAggParams[] = $startDate;
$payAggParams[] = $endDate;
$payAggParams[] = $endDate;
$payAggParams[] = $endDate;
$payAggParams[] = $endDate;
$payAggParams[] = $endDate;
$payAggTypes .= 'ssssssssss';

$stmtPayAgg = mysqli_prepare($conn, $sqlPayAgg);
mysqli_stmt_bind_param($stmtPayAgg, $payAggTypes, ...$payAggParams);
mysqli_stmt_execute($stmtPayAgg);
$resPayAgg = mysqli_stmt_get_result($stmtPayAgg);
while ($r = mysqli_fetch_assoc($resPayAgg)) {
    $cid = trim((string)($r['customer_id'] ?? ''));
    if (!isset($calc[$cid])) {
        continue;
    }
    foreach (['pre_cash','pre_titip','period_cash','period_titip','opening_target_total','orphan_total'] as $k) {
        $calc[$cid][$k] = (float)($r[$k] ?? 0);
    }
}
mysqli_stmt_close($stmtPayAgg);

/* ============================================================
 * 5. RETUR (AGREGASI PER CUSTOMER)
 * ============================================================
 * Retur tetap ditempatkan sebagai MINUS pada bucket Lebih.
 */
$sqlReturnAgg = "
WITH latest_opening AS (
    SELECT cob.customer_id, cob.opening_date
    FROM customer_opening_balance cob
    INNER JOIN (
        SELECT customer_id, MAX(opening_id) AS opening_id
        FROM customer_opening_balance
        WHERE LOWER(COALESCE(status, 'Active')) = 'active'
          AND opening_date <= ?
        GROUP BY customer_id
    ) x ON x.opening_id = cob.opening_id
), ret_base AS (
    SELECT
        hri.customer_id,
        hri.return_date,
        lo.opening_date,
        CASE
            WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
              OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
            THEN COALESCE(hri.grand_total, 0)
            ELSE COALESCE(hri.return_amount, 0)
        END AS return_value
    FROM head_retur_invoice hri
    INNER JOIN m_customer mc
        ON mc.customer_id = hri.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    LEFT JOIN latest_opening lo ON lo.customer_id = hri.customer_id
    WHERE hri.return_date <= ?
      AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
";

$returnAggParams = [$endDate, $endDate];
$returnAggTypes = 'ss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlReturnAgg .= " AND COALESCE(mc.area_code, '') = ? ";
    $returnAggParams[] = $filterValue;
    $returnAggTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlReturnAgg .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $returnAggParams[] = $filterValue;
    $returnAggTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlReturnAgg .= " AND mc.customer_id = ? ";
    $returnAggParams[] = $filterValue;
    $returnAggTypes .= 's';
}

$sqlReturnAgg .= "
), applicable AS (
    SELECT *
    FROM ret_base
    WHERE opening_date IS NULL OR return_date > opening_date
)
SELECT
    customer_id,
    SUM(CASE WHEN return_date < ? THEN return_value ELSE 0 END) AS pre_return,
    SUM(CASE WHEN return_date BETWEEN ? AND ? THEN return_value ELSE 0 END) AS period_return,
    SUM(return_value) AS total_return
FROM applicable
GROUP BY customer_id
";

$returnAggParams[] = $startDate;
$returnAggParams[] = $startDate;
$returnAggParams[] = $endDate;
$returnAggTypes .= 'sss';

$stmtReturnAgg = mysqli_prepare($conn, $sqlReturnAgg);
mysqli_stmt_bind_param($stmtReturnAgg, $returnAggTypes, ...$returnAggParams);
mysqli_stmt_execute($stmtReturnAgg);
$resReturnAgg = mysqli_stmt_get_result($stmtReturnAgg);
while ($r = mysqli_fetch_assoc($resReturnAgg)) {
    $cid = trim((string)($r['customer_id'] ?? ''));
    if (!isset($calc[$cid])) {
        continue;
    }
    foreach (['pre_return','period_return','total_return'] as $k) {
        $calc[$cid][$k] = (float)($r[$k] ?? 0);
    }
}
mysqli_stmt_close($stmtReturnAgg);

/* ============================================================
 * 6. FINALISASI CARRY-FORWARD PER CUSTOMER
 * ============================================================ */
foreach ($masterCustomers as $cid => $m) {
    $c = $calc[$cid];
    $hasOpening = isset($openingMap[$cid]);
    $openingBalance = $hasOpening ? (float)$openingMap[$cid]['opening_balance'] : 0.0;

    if ($hasOpening) {
        // Normal: base migrasi + seluruh mutasi setelah opening hingga H-1.
        $saldoAwal =
            $openingBalance
            + $c['pre_invoice']
            - $c['pre_return']
            - $c['pre_cash']
            - $c['pre_titip'];

        $periodInvoice = $c['period_invoice'];
        $periodCash = $c['period_cash'];
        $periodTitip = $c['period_titip'];
        $periodReturn = $c['period_return'];
    } elseif ($isInitialMigrationPeriod) {
        // Rule khusus periode migrasi pertama: MARDI dan customer tanpa opening
        // tetap menampilkan invoice legacy sebagai Penjualan, bukan Saldo Awal.
        $saldoAwal = 0.0;
        $periodInvoice = $c['pre_invoice'] + $c['period_invoice'];
        $periodCash = $c['pre_cash'] + $c['period_cash'];
        $periodTitip = $c['pre_titip'] + $c['period_titip'];
        $periodReturn = $c['pre_return'] + $c['period_return'];
    } else {
        // Bulan setelah migrasi: ending bulan lalu menjadi opening bulan ini.
        $saldoAwal =
            $c['pre_invoice']
            - $c['pre_return']
            - $c['pre_cash']
            - $c['pre_titip'];

        $periodInvoice = $c['period_invoice'];
        $periodCash = $c['period_cash'];
        $periodTitip = $c['period_titip'];
        $periodReturn = $c['period_return'];
    }

    $penjualanNeto = $periodInvoice - $periodReturn;
    $akhir = $saldoAwal + $penjualanNeto - $periodCash - $periodTitip;

    // Bucket akhir periode.
    $bLebih = $c['b_lebih'];

    if ($hasOpening) {
        // Opening snapshot selalu ditempatkan pada Lebih. Pembayaran yang memang
        // menarget opening/historical mengurangi bucket ini.
        $bLebih += $openingBalance - $c['opening_target_total'];
    }

    // Pembayaran orphan tanpa invoice menjadi kredit di Lebih.
    $bLebih -= $c['orphan_total'];

    // Sesuai rule bisnis: seluruh retur tampil minus pada Lebih.
    $bLebih -= $c['total_return'];

    $amounts = initAmountRow();
    $amounts['saldo_awal'] = $saldoAwal;
    $amounts['penjualan'] = $penjualanNeto;
    $amounts['bayar'] = $periodCash;
    $amounts['titip_used'] = $periodTitip;
    $amounts['retur'] = $periodReturn;
    $amounts['akhir'] = $akhir;
    $amounts['b_1_30'] = $c['b_1_30'];
    $amounts['b_31_60'] = $c['b_31_60'];
    $amounts['b_61_90'] = $c['b_61_90'];
    $amounts['b_lebih'] = $bLebih;
    $amounts['belum_jatuh_tempo'] = $c['belum_jatuh_tempo'];

    $bucketTotal =
        $amounts['b_1_30']
        + $amounts['b_31_60']
        + $amounts['b_61_90']
        + $amounts['b_lebih']
        + $amounts['belum_jatuh_tempo'];

    $selisih = $akhir - $bucketTotal;

    $activityTotal = 0.0;
    foreach ($amounts as $v) {
        $activityTotal += abs((float)$v);
    }

    if ($activityTotal < 0.0001) {
        continue;
    }

    $customers[$cid] = [
        'customer_id' => $cid,
        'customer_name' => $m['customer_name'],
        'city' => $m['city'],
        'opening_date' => $hasOpening ? $openingMap[$cid]['opening_date'] : '',
        'amounts' => $amounts,
        'selisih_balance' => $selisih,
    ];
}

/* ============================================================
 * 7. GROUP KOTA + GRAND TOTAL
 * ============================================================ */
$grouped = [];
foreach ($customers as $customer) {
    $city = $customer['city'];
    if (!isset($grouped[$city])) {
        $grouped[$city] = [
            'rows' => [],
            'totals' => initAmountRow(),
        ];
    }
    $grouped[$city]['rows'][] = $customer;
    addAmounts($grouped[$city]['totals'], $customer['amounts']);
}

ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($grouped as &$cityGroup) {
    usort($cityGroup['rows'], function ($a, $b) {
        return strcasecmp($a['customer_name'], $b['customer_name']);
    });
}
unset($cityGroup);

$grand = initAmountRow();
foreach ($grouped as $cityGroup) {
    addAmounts($grand, $cityGroup['totals']);
}

$grandFormula1 =
    $grand['saldo_awal']
    + $grand['penjualan']
    - $grand['bayar']
    - $grand['titip_used'];

$grandFormula2 =
    $grand['b_1_30']
    + $grand['b_31_60']
    + $grand['b_61_90']
    + $grand['b_lebih']
    + $grand['belum_jatuh_tempo'];

$grand['akhir'] = $grandFormula1;
$grandSelisih = $grandFormula1 - $grandFormula2;

/*
 * PAGINATION KHUSUS CETAK
 * ------------------------------------------------------------
 * Browser biasa akan mengulang <tfoot> pada setiap lembar cetak.
 * Karena GRAND TOTAL hanya boleh tampil pada halaman terakhir,
 * data cetak dibagi menjadi beberapa halaman secara eksplisit.
 *
 * Setiap halaman antara menampilkan:
 *     "Berlanjut ke halaman berikutnya..."
 *
 * GRAND TOTAL hanya dirender pada halaman terakhir.
 */
$printPages = [];
$printPageRows = [];
$printPageUnits = 0;

// Batas konservatif agar satu halaman tidak terpotong oleh browser.
// Ukuran kertas cetak report ini: 377.8mm x 279.4mm landscape.
$maxPrintUnitsPerPage = 44;

$flushPrintPage = static function () use (&$printPages, &$printPageRows, &$printPageUnits) {
    if (!empty($printPageRows)) {
        $printPages[] = $printPageRows;
        $printPageRows = [];
        $printPageUnits = 0;
    }
};

foreach ($grouped as $printCity => $printCityGroup) {
    // Hindari judul kota sendirian di ujung halaman.
    if ($printPageUnits > 0 && ($printPageUnits + 3) > $maxPrintUnitsPerPage) {
        $flushPrintPage();
    }

    $printPageRows[] = [
        'type' => 'city',
        'city' => $printCity,
    ];
    $printPageUnits++;

    foreach ($printCityGroup['rows'] as $printCustomer) {
        if (($printPageUnits + 1) > $maxPrintUnitsPerPage) {
            $flushPrintPage();

            // Jika satu kota berlanjut ke halaman berikutnya,
            // ulangi nama kota agar pembaca tidak kehilangan konteks.
            $printPageRows[] = [
                'type' => 'city_continued',
                'city' => $printCity,
            ];
            $printPageUnits++;
        }

        $printPageRows[] = [
            'type' => 'customer',
            'customer' => $printCustomer,
        ];
        $printPageUnits++;
    }

    // Pastikan TOTAL KOTA tidak terpisah dari halaman secara liar.
    if (($printPageUnits + 1) > $maxPrintUnitsPerPage) {
        $flushPrintPage();

        $printPageRows[] = [
            'type' => 'city_continued',
            'city' => $printCity,
        ];
        $printPageUnits++;
    }

    $printPageRows[] = [
        'type' => 'city_total',
        'city' => $printCity,
        'totals' => $printCityGroup['totals'],
    ];
    $printPageUnits++;
}

$flushPrintPage();

// Bila tidak ada data, tetap sediakan satu halaman cetak.
if (empty($printPages)) {
    $printPages[] = [];
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

        .print-report {
            display: none;
        }

        .print-page {
            background: #fff;
        }

        .continuation-message {
            margin-top: 4mm;
            padding-top: 2mm;
            border-top: 1px dashed #777;
            text-align: right;
            font-size: 8pt;
            font-style: italic;
            font-weight: bold;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .no-print {
                display: none !important;
            }


            .screen-report {
                display: none !important;
            }

            .print-report {
                display: block !important;
            }

            .print-report .print-page {
                width: 365.8mm !important;
                min-width: 365.8mm !important;
                max-width: 365.8mm !important;
                height: 267mm !important;
                min-height: 267mm !important;
                margin: 0 !important;
                padding: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                page-break-after: always;
                break-after: page;
                overflow: hidden !important;
            }

            .print-report .print-page:last-child {
                page-break-after: auto;
                break-after: auto;
            }

            .print-report .page-table-wrap {
                flex: 0 0 auto;
            }

            .print-report .continuation-message {
                margin-top: auto;
                padding-top: 2mm;
                padding-bottom: 1mm;
                border-top: 1px dashed #777;
                text-align: right;
                font-size: 7pt;
                font-style: italic;
                font-weight: bold;
            }

            .print-report .grand-total {
                break-inside: avoid;
                page-break-inside: avoid;
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

<?php if (abs($grandSelisih) > 0.01): ?>
<div class="no-print" style="max-width:1680px;margin:0 auto 12px;padding:9px 12px;background:#ffe8e8;border:1px solid #d9534f;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;">
    <strong>PERINGATAN BALANCE:</strong> Grand Total Rumus 1 dan Rumus 2 berbeda sebesar
    Rp <?= h(formatMoney($grandSelisih)) ?>. Periksa transaksi orphan/overpayment.
</div>
<?php endif; ?>

<div class="screen-scroll screen-report">
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
                                    <?= h(formatMoney($a['titip_used'])) ?>
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
                                <?= h(formatMoney($ct['titip_used'])) ?>
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
                        <?= h(formatMoney($grand['titip_used'])) ?>
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
            </tbody>


        </table>
    </div>
</div>


<div class="print-report">
    <?php $totalPrintPages = count($printPages); ?>

    <?php foreach ($printPages as $printPageIndex => $printRows): ?>
        <?php $isLastPrintPage = ($printPageIndex === $totalPrintPages - 1); ?>

        <section class="print-page">
            <div class="title">
                <?= h($title) ?>
            </div>

            <div class="subtitle">
                <?= h($subtitle) ?>
            </div>

            <div class="printed">
                Dicetak: <?= h($printedAt) ?>
                &nbsp; | &nbsp;
                Halaman <?= h($printPageIndex + 1) ?> / <?= h($totalPrintPages) ?>
            </div>

            <div class="page-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="customer-col">Nama Customer</th>
                            <th class="money-col">Awal</th>
                            <th class="money-col">Penjualan</th>
                            <th class="money-col">Bayar</th>
                            <th class="money-col">Titip</th>
                            <th class="money-col">Akhir</th>
                            <th class="money-col">1-30 Hari</th>
                            <th class="money-col">31-60 Hari</th>
                            <th class="money-col">61-90 Hari</th>
                            <th class="money-col">Lebih</th>
                            <th class="money-col">Blm Jth. Tempo</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($printRows) && empty($grouped)): ?>
                            <tr>
                                <td colspan="11" class="no-data">
                                    Tidak ada data aging piutang untuk filter ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($printRows as $printRow): ?>
                                <?php if ($printRow['type'] === 'city' || $printRow['type'] === 'city_continued'): ?>
                                    <tr class="city-row">
                                        <td colspan="11">
                                            <?= h($printRow['city']) ?>
                                            <?php if ($printRow['type'] === 'city_continued'): ?>
                                                <span style="font-weight:normal;">(lanjutan)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                <?php elseif ($printRow['type'] === 'customer'): ?>
                                    <?php
                                    $printCustomer = $printRow['customer'];
                                    $pa = $printCustomer['amounts'];
                                    ?>
                                    <tr>
                                        <td class="customer-col">
                                            <?= h($printCustomer['customer_name']) ?>
                                        </td>
                                        <td class="money-cell"><?= h(formatMoney($pa['saldo_awal'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['penjualan'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['bayar'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['titip_used'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['akhir'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['b_1_30'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['b_31_60'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['b_61_90'])) ?></td>
                                        <td class="money-cell <?= $pa['b_lebih'] < 0 ? 'return-negative' : '' ?>">
                                            <?= h(formatMoney($pa['b_lebih'])) ?>
                                        </td>
                                        <td class="money-cell"><?= h(formatMoney($pa['belum_jatuh_tempo'])) ?></td>
                                    </tr>

                                <?php elseif ($printRow['type'] === 'city_total'): ?>
                                    <?php $pct = $printRow['totals']; ?>
                                    <tr class="city-total">
                                        <td class="customer-col">
                                            TOTAL <?= h($printRow['city']) ?>
                                        </td>
                                        <td class="money-cell"><?= h(formatMoney($pct['saldo_awal'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['penjualan'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['bayar'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['titip_used'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['akhir'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['b_1_30'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['b_31_60'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['b_61_90'])) ?></td>
                                        <td class="money-cell <?= $pct['b_lebih'] < 0 ? 'return-negative' : '' ?>">
                                            <?= h(formatMoney($pct['b_lebih'])) ?>
                                        </td>
                                        <td class="money-cell"><?= h(formatMoney($pct['belum_jatuh_tempo'])) ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if ($isLastPrintPage): ?>
                            <tr class="grand-total">
                                <td class="customer-col">GRAND TOTAL</td>
                                <td class="money-cell"><?= h(formatMoney($grand['saldo_awal'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['penjualan'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['bayar'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['titip_used'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['akhir'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['b_1_30'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['b_31_60'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['b_61_90'])) ?></td>
                                <td class="money-cell <?= $grand['b_lebih'] < 0 ? 'return-negative' : '' ?>">
                                    <?= h(formatMoney($grand['b_lebih'])) ?>
                                </td>
                                <td class="money-cell"><?= h(formatMoney($grand['belum_jatuh_tempo'])) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$isLastPrintPage): ?>
                <div class="continuation-message">
                    Berlanjut ke halaman berikutnya...
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

</body>
</html>