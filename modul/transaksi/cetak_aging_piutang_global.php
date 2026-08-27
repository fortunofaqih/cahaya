<?php
/*
 * modul/transaksi/cetak_aging_piutang_global.php
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

$title = 'AGING PIUTANG - GLOBAL - CP';
$subtitle = 'Periode ' . getMonthName($bulan) . ' ' . $tahun . ' | ' . getTitleFilter($filterBy, $filterValue);
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
 * 7. AGREGASI GLOBAL + GRAND TOTAL
 * ============================================================
 * Mesin hitung sama dengan Aging Detail. Perbedaannya hanya tampilan:
 * - filter pelanggan => satu baris pelanggan
 * - filter lain => satu baris per kota
 */
function globalLabelForCustomer(array $customer, string $filterBy): string
{
    if ($filterBy === 'pelanggan') {
        return trim($customer['customer_id'] . ' - ' . $customer['customer_name']);
    }
    return normalizeCity($customer['city'] ?? '');
}

function globalLabelTitle(string $filterBy): string
{
    if ($filterBy === 'pelanggan') return 'Pelanggan';
    if ($filterBy === 'kota') return 'Kota';
    return 'Daerah';
}

$rows = [];
foreach ($customers as $customer) {
    $label = globalLabelForCustomer($customer, $filterBy);
    if (!isset($rows[$label])) {
        $rows[$label] = [
            'label' => $label,
            'amounts' => initAmountRow(),
            'selisih_balance' => 0.0,
        ];
    }
    addAmounts($rows[$label]['amounts'], $customer['amounts']);
    $rows[$label]['selisih_balance'] += (float)($customer['selisih_balance'] ?? 0);
}
ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);

$grand = initAmountRow();
foreach ($rows as $row) {
    addAmounts($grand, $row['amounts']);
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
$labelColumn = globalLabelTitle($filterBy);

/* Pagination cetak eksplisit agar Grand Total hanya di halaman terakhir. */
$printRows = array_values($rows);
$maxPrintRowsPerPage = 38;
$printPages = array_chunk($printRows, $maxPrintRowsPerPage);
if (empty($printPages)) {
    $printPages = [[]];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?></title>
<style>
@page { size: 377.8mm 279.4mm; margin: 5mm 6mm; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:"Courier New",Courier,monospace; font-size:12px; color:#000; background:#eef1f5; padding:16px; }
.toolbar { width:100%; display:flex; justify-content:flex-end; margin-bottom:16px; }
.btn-print { border:0; border-radius:6px; background:#2b5797; color:#fff; padding:12px 30px; font-size:16px; font-weight:bold; cursor:pointer; box-shadow:0 2px 6px rgba(0,0,0,.2); }
.screen-scroll { width:100%; overflow-x:auto; padding-bottom:12px; }
.print-wrap { width:1680px; min-width:1680px; margin:0 auto; padding:20px 24px; background:#fff; box-shadow:0 2px 12px rgba(0,0,0,.1); border-radius:4px; }
.title { text-align:center; font-size:20px; font-weight:bold; margin-bottom:2px; }
.subtitle { text-align:center; font-size:13px; margin-bottom:2px; }
.printed { text-align:right; font-size:11px; margin-bottom:8px; color:#555; }
table { width:100%; border-collapse:collapse; font-size:10px; }
th { border:1px solid #000; background:#e8e8e8; padding:6px 4px; text-align:center; font-weight:bold; white-space:nowrap; }
td { border-left:1px solid #000; border-right:1px solid #000; padding:5px 4px; vertical-align:middle; white-space:nowrap; }
tbody tr:first-child td { border-top:1px solid #000; }
tbody tr:last-child td { border-bottom:1px solid #000; }
.text-center { text-align:center; }
.text-right,.money-cell { text-align:right; }
.label-cell { white-space:normal; font-weight:500; }
.return-negative { color:#a00000; font-weight:bold; }
.grand-total td { border:1px solid #000; background:#e8e8e8; font-weight:bold; padding:6px 4px; }
.balance-warning { width:100%; max-width:1680px; margin:0 auto 12px; padding:9px 12px; background:#fff3cd; border:1px solid #e0b94e; color:#664d03; font-family:Arial,Helvetica,sans-serif; font-size:12px; font-weight:bold; }
.print-report { display:none; }
.continue-row td { border:0 !important; text-align:right; font-style:italic; font-weight:bold; padding-top:4mm !important; }

@media print {
 html,body { width:377.8mm !important; min-width:377.8mm !important; height:auto !important; margin:0 !important; padding:0 !important; overflow:visible !important; background:#fff !important; }
 body { display:block !important; font-family:"Courier New",Courier,monospace !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
 .no-print,.screen-report { display:none !important; }
 .print-report { display:block !important; }
 .print-page { width:365.8mm !important; min-width:365.8mm !important; max-width:365.8mm !important; margin:0 !important; padding:0 !important; page-break-after:always; break-after:page; }
 .print-page:last-child { page-break-after:auto; break-after:auto; }
 .title { font-size:10pt !important; line-height:1.1 !important; margin-bottom:.8mm !important; }
 .subtitle { font-size:8pt !important; line-height:1.1 !important; margin-bottom:.8mm !important; }
 .printed { font-size:7pt !important; margin-bottom:2mm !important; }
 table { width:100% !important; table-layout:fixed !important; border-collapse:collapse !important; font-size:7pt !important; line-height:1.05 !important; }
 th { padding:1.5mm .7mm !important; background:#f2f2f2 !important; font-weight:bold !important; white-space:nowrap !important; }
 td { padding:1.1mm .7mm !important; white-space:nowrap !important; }
 th:nth-child(1),td:nth-child(1){width:9mm!important;}
 th:nth-child(2),td:nth-child(2){width:47mm!important;}
 th:nth-child(n+3),td:nth-child(n+3){width:28.2mm!important;}
 .label-cell { white-space:normal !important; overflow-wrap:anywhere !important; }
 tr { page-break-inside:avoid !important; break-inside:avoid !important; }
 thead { display:table-header-group !important; }
 .grand-total td { background:#f2f2f2 !important; font-weight:bold !important; }
}
@media screen and (max-width:768px){ body{padding:8px}.toolbar{justify-content:center}.btn-print{width:100%;padding:14px}.print-wrap{padding:12px;min-width:1500px;width:1500px} }
</style>
</head>
<body>
<div class="toolbar no-print"><button type="button" class="btn-print" onclick="window.print()">🖨️ CETAK LAPORAN</button></div>

<div class="no-print" style="width:100%;max-width:1680px;margin:0 auto 12px;padding:9px 12px;background:#fffbe6;border:1px solid #d8c26e;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;">
Setting Epson LQ-2190: pilih <strong>US Std Fanfold</strong>, ukuran <strong>14 7/8 × 11 inci</strong> atau <strong>377,8 × 279,4 mm</strong>, orientasi <strong>Landscape</strong>, skala <strong>100%</strong>, margin minimum, serta nonaktifkan Header dan Footer browser.
</div>

<?php if (abs($grandSelisih) > 0.01): ?>
<div class="balance-warning no-print">PERINGATAN BALANCE: Grand Total Rumus 1 dan Rumus 2 berbeda sebesar Rp <?= h(formatMoney($grandSelisih)) ?>. Periksa transaksi orphan/overpayment.</div>
<?php endif; ?>

<div class="screen-scroll screen-report"><div class="print-wrap">
<div class="title"><?= h($title) ?></div>
<div class="subtitle"><?= h($subtitle) ?></div>
<div class="printed">Dicetak: <?= h($printedAt) ?></div>
<table>
<thead><tr>
<th>No</th><th><?= h($labelColumn) ?></th><th>Saldo Awal</th><th>Penjualan</th><th>Pembayaran</th><th>Titip</th><th>Saldo Akhir</th><th>1 - 30 Hari</th><th>31 - 60 Hari</th><th>61 - 90 Hari</th><th>Lebih / Retur</th><th>Belum Jatuh Tempo</th>
</tr></thead>
<tbody>
<?php if (empty($rows)): ?>
<tr><td colspan="12" class="text-center" style="padding:20px;color:#999;">Tidak ada data aging piutang untuk filter ini.</td></tr>
<?php else: $no=1; foreach ($rows as $row): $a=$row['amounts']; ?>
<tr>
<td class="text-center"><?= $no++ ?></td><td class="label-cell"><?= h($row['label']) ?></td>
<td class="money-cell"><?= h(formatMoney($a['saldo_awal'])) ?></td>
<td class="money-cell"><?= h(formatMoney($a['penjualan'])) ?></td>
<td class="money-cell"><?= h(formatMoney($a['bayar'])) ?></td>
<td class="money-cell"><?= h(formatMoney($a['titip_used'])) ?></td>
<td class="money-cell"><?= h(formatMoney($a['akhir'])) ?></td>
<td class="money-cell"><?= h(formatMoney($a['b_1_30'])) ?></td>
<td class="money-cell"><?= h(formatMoney($a['b_31_60'])) ?></td>
<td class="money-cell"><?= h(formatMoney($a['b_61_90'])) ?></td>
<td class="money-cell <?= $a['b_lebih'] < 0 ? 'return-negative' : '' ?>"><?= h(formatMoney($a['b_lebih'])) ?></td>
<td class="money-cell"><?= h(formatMoney($a['belum_jatuh_tempo'])) ?></td>
</tr>
<?php endforeach; endif; ?>
<tr class="grand-total"><td colspan="2" class="text-right">GRAND TOTAL</td>
<td class="money-cell"><?= h(formatMoney($grand['saldo_awal'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['penjualan'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['bayar'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['titip_used'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['akhir'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['b_1_30'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['b_31_60'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['b_61_90'])) ?></td><td class="money-cell <?= $grand['b_lebih'] < 0 ? 'return-negative' : '' ?>"><?= h(formatMoney($grand['b_lebih'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['belum_jatuh_tempo'])) ?></td></tr>
</tbody></table>
</div></div>

<div class="print-report">
<?php $globalNo=1; $pageCount=count($printPages); foreach ($printPages as $pageIndex=>$pageRows): $isLast=($pageIndex===$pageCount-1); ?>
<section class="print-page">
<div class="title"><?= h($title) ?></div><div class="subtitle"><?= h($subtitle) ?></div><div class="printed">Dicetak: <?= h($printedAt) ?> | Halaman <?= $pageIndex+1 ?> / <?= $pageCount ?></div>
<table><thead><tr>
<th>No</th><th><?= h($labelColumn) ?></th><th>Saldo Awal</th><th>Penjualan</th><th>Pembayaran</th><th>Titip</th><th>Saldo Akhir</th><th>1 - 30 Hari</th><th>31 - 60 Hari</th><th>61 - 90 Hari</th><th>Lebih / Retur</th><th>Belum Jatuh Tempo</th>
</tr></thead><tbody>
<?php if (empty($pageRows)): ?>
<tr><td colspan="12" class="text-center">Tidak ada data aging piutang untuk filter ini.</td></tr>
<?php else: foreach ($pageRows as $row): $a=$row['amounts']; ?>
<tr><td class="text-center"><?= $globalNo++ ?></td><td class="label-cell"><?= h($row['label']) ?></td>
<td class="money-cell"><?= h(formatMoney($a['saldo_awal'])) ?></td><td class="money-cell"><?= h(formatMoney($a['penjualan'])) ?></td><td class="money-cell"><?= h(formatMoney($a['bayar'])) ?></td><td class="money-cell"><?= h(formatMoney($a['titip_used'])) ?></td><td class="money-cell"><?= h(formatMoney($a['akhir'])) ?></td><td class="money-cell"><?= h(formatMoney($a['b_1_30'])) ?></td><td class="money-cell"><?= h(formatMoney($a['b_31_60'])) ?></td><td class="money-cell"><?= h(formatMoney($a['b_61_90'])) ?></td><td class="money-cell <?= $a['b_lebih'] < 0 ? 'return-negative' : '' ?>"><?= h(formatMoney($a['b_lebih'])) ?></td><td class="money-cell"><?= h(formatMoney($a['belum_jatuh_tempo'])) ?></td></tr>
<?php endforeach; endif; ?>
<?php if ($isLast): ?>
<tr class="grand-total"><td colspan="2" class="text-right">GRAND TOTAL</td><td class="money-cell"><?= h(formatMoney($grand['saldo_awal'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['penjualan'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['bayar'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['titip_used'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['akhir'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['b_1_30'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['b_31_60'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['b_61_90'])) ?></td><td class="money-cell <?= $grand['b_lebih'] < 0 ? 'return-negative' : '' ?>"><?= h(formatMoney($grand['b_lebih'])) ?></td><td class="money-cell"><?= h(formatMoney($grand['belum_jatuh_tempo'])) ?></td></tr>
<?php else: ?>
<tr class="continue-row"><td colspan="12">Berlanjut ke halaman berikutnya...</td></tr>
<?php endif; ?>
</tbody></table>
</section>
<?php endforeach; ?>
</div>
</body></html>
