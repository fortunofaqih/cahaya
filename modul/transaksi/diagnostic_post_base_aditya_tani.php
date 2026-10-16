<?php
/*
 * DIAGNOSTIC POST_BASE - ADITYA TANI
 * Customer: C0000127
 * Periode: 01-Sep-2026 s/d 30-Sep-2026
 *
 * READ ONLY - SELECT ONLY.
 * Tidak mengubah data dan tidak mengubah calculation Aging Rev7.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$conn = null;
$connectionCandidates = [
    '../../../config/koneksi.php',
    '../../config/koneksi.php',
    '../config/koneksi.php',
    'config/koneksi.php',
    include __DIR__ . '/../../koneksi.php',
];

foreach ($connectionCandidates as $candidate) {
    if (file_exists($candidate)) {
        require_once $candidate;
        if (isset($koneksi) && $koneksi instanceof mysqli) {
            $conn = $koneksi;
        } elseif (isset($conn) && $conn instanceof mysqli) {
            // already available
        }
        if ($conn instanceof mysqli) {
            break;
        }
    }
}

if (!($conn instanceof mysqli)) {
    die('Koneksi database tidak ditemukan. Sesuaikan path config/koneksi.php pada file diagnostic.');
}

mysqli_set_charset($conn, 'utf8mb4');

$customerId = 'C0000127';
$startDate  = '2026-09-01';
$endDate    = '2026-09-30';
$baselineDate = '2026-08-31';

function fetchAllAssoc(mysqli $conn, string $sql, array $params = [], string $types = ''): array
{
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [['SQL_ERROR' => mysqli_error($conn), 'SQL' => $sql]];
    }
    if (!empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    if (!mysqli_stmt_execute($stmt)) {
        $err = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        return [['SQL_ERROR' => $err, 'SQL' => $sql]];
    }
    $res = mysqli_stmt_get_result($stmt);
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $rows;
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && mysqli_num_rows($res) > 0;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $safeTable = str_replace('`', '``', $table);
    $safeCol = mysqli_real_escape_string($conn, $column);
    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeCol}'");
    return $res && mysqli_num_rows($res) > 0;
}

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function renderTable(string $title, array $rows): void
{
    echo '<h2>'.h($title).'</h2>';
    if (empty($rows)) {
        echo '<p><strong>Tidak ada data.</strong></p>';
        return;
    }

    $columns = [];
    foreach ($rows as $r) {
        foreach (array_keys($r) as $k) {
            if (!in_array($k, $columns, true)) {
                $columns[] = $k;
            }
        }
    }

    echo '<div class="scroll"><table><thead><tr>';
    foreach ($columns as $c) {
        echo '<th>'.h($c).'</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($columns as $c) {
            $v = $r[$c] ?? '';
            echo '<td>'.h($v).'</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/* ============================================================
 * 1. MASTER CUSTOMER
 * ============================================================ */
$master = fetchAllAssoc(
    $conn,
    "SELECT customer_id, customer, city, is_active
     FROM m_customer
     WHERE customer_id = ?",
    [$customerId],
    's'
);

/* ============================================================
 * 2. HEAD + DETAIL INVOICE AUG-SEP
 * gross memakai rule Rev6/Rev7:
 * SUM(COALESCE(NULLIF(di.total,0), di.subtotal,0))
 * ============================================================ */
$invoiceRows = fetchAllAssoc(
    $conn,
    "SELECT
        hi.customer_id,
        hi.invoice_no,
        hi.invoice_date,
        hi.days,
        DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days,0) DAY) AS due_date,
        hi.status,
        hi.subtotal AS head_subtotal,
        hi.grand_total AS head_grand_total,
        hi.payment_balance,
        GROUP_CONCAT(DISTINCT di.shipping_no ORDER BY di.shipping_no SEPARATOR ', ') AS shipping_no,
        SUM(COALESCE(NULLIF(di.total,0), di.subtotal,0)) AS gross_from_detail
     FROM head_invoice hi
     LEFT JOIN det_invoice di
       ON di.invoice_no = hi.invoice_no
     WHERE hi.customer_id = ?
       AND hi.invoice_date BETWEEN '2026-08-01' AND ?
     GROUP BY
        hi.customer_id, hi.invoice_no, hi.invoice_date, hi.days,
        hi.status, hi.subtotal, hi.grand_total, hi.payment_balance
     ORDER BY hi.invoice_date, hi.invoice_no",
    [$customerId, $endDate],
    'ss'
);

/* ============================================================
 * 3. RAW DETAIL BAYAR SEP
 * ============================================================ */
$paymentRows = fetchAllAssoc(
    $conn,
    "SELECT
        hb.bayar_no,
        hb.bayar_date,
        hb.customer_id,
        db.id,
        db.payment_source,
        db.invoice_no,
        db.invoice_date AS detail_invoice_date,
        db.shipping_no,
        db.opening_id,
        db.return_id,
        db.invoice_amount,
        db.cash_amount,
        db.titip_amount,
        db.bayar_amount,
        db.sisa_after,
        db.remarks
     FROM head_bayar hb
     INNER JOIN detail_bayar db
       ON db.bayar_no = hb.bayar_no
     WHERE hb.customer_id = ?
       AND hb.bayar_date BETWEEN ? AND ?
     ORDER BY hb.bayar_date, hb.bayar_no, db.id",
    [$customerId, $startDate, $endDate],
    'sss'
);

/* ============================================================
 * 4. KHUSUS B-000000413
 * ============================================================ */
$payment413 = fetchAllAssoc(
    $conn,
    "SELECT
        hb.bayar_no,
        hb.bayar_date,
        db.id,
        db.payment_source,
        db.invoice_no,
        db.invoice_date AS detail_invoice_date,
        db.shipping_no,
        db.opening_id,
        db.return_id,
        db.invoice_amount,
        db.cash_amount,
        db.titip_amount,
        db.bayar_amount,
        db.sisa_after,
        db.remarks
     FROM head_bayar hb
     INNER JOIN detail_bayar db
       ON db.bayar_no = hb.bayar_no
     WHERE hb.customer_id = ?
       AND hb.bayar_no = 'B-000000413'
     ORDER BY db.id",
    [$customerId],
    's'
);

/* ============================================================
 * 5. RETUR RAW
 * Adaptif: hanya dijalankan bila tabel ada.
 * ============================================================ */
$returnRows = [];
if (tableExists($conn, 'head_retur_invoice')) {
    $returnCols = [
        "hri.return_id",
        "hri.return_date",
        "hri.customer_id",
        "hri.invoice_no",
        "hri.shipping_no",
        "hri.return_amount",
        "hri.status"
    ];
    $returnRows = fetchAllAssoc(
        $conn,
        "SELECT ".implode(", ", $returnCols)."
         FROM head_retur_invoice hri
         WHERE hri.customer_id = ?
           AND hri.return_date <= ?
         ORDER BY hri.return_date, hri.return_id",
        [$customerId, $endDate],
        'ss'
    );
}

/* ============================================================
 * 6. REPLIKA PER-INVOICE PAY_INV + RETURN_INV + OUTSTANDING
 *
 * paid_total diagnostic:
 * cash_amount + titip_amount untuk detail yang menunjuk invoice.
 * Ini sengaja ditampilkan transparan per invoice untuk mencari
 * apakah payment invoice lain terseret ke INV/2026/000495.
 * ============================================================ */
$returnJoin = "0";
if (tableExists($conn, 'head_retur_invoice')) {
    $returnJoin = "COALESCE((
        SELECT SUM(COALESCE(hri.return_amount,0))
        FROM head_retur_invoice hri
        WHERE hri.invoice_no = hi.invoice_no
          AND hri.return_date <= ?
          AND LOWER(COALESCE(hri.status,'Open')) <> 'cancelled'
          AND UPPER(COALESCE(hri.invoice_no,'')) NOT LIKE '%CP-MCP%'
          AND UPPER(COALESCE(hri.shipping_no,'')) NOT LIKE '%CP-MCP%'
    ),0)";
}

$sqlPerInvoice = "
SELECT
    hi.invoice_no,
    GROUP_CONCAT(DISTINCT di.shipping_no ORDER BY di.shipping_no SEPARATOR ', ') AS shipping_no,
    hi.invoice_date,
    hi.days,
    DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days,0) DAY) AS due_date,
    SUM(COALESCE(NULLIF(di.total,0), di.subtotal,0)) AS gross,

    COALESCE((
        SELECT SUM(
            COALESCE(db.cash_amount,0)
            + COALESCE(db.titip_amount,0)
        )
        FROM detail_bayar db
        INNER JOIN head_bayar hb
            ON hb.bayar_no = db.bayar_no
        WHERE db.invoice_no = hi.invoice_no
          AND hb.customer_id = hi.customer_id
          AND hb.bayar_date <= ?
          AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
          AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'
    ),0) AS paid_total_simple,

    {$returnJoin} AS return_total,

    (
        SUM(COALESCE(NULLIF(di.total,0), di.subtotal,0))
        - COALESCE((
            SELECT SUM(
                COALESCE(db.cash_amount,0)
                + COALESCE(db.titip_amount,0)
            )
            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.customer_id = hi.customer_id
              AND hb.bayar_date <= ?
              AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
              AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'
        ),0)
        - {$returnJoin}
    ) AS outstanding_simple,

    CASE
        WHEN (
            SUM(COALESCE(NULLIF(di.total,0), di.subtotal,0))
            - COALESCE((
                SELECT SUM(
                    COALESCE(db.cash_amount,0)
                    + COALESCE(db.titip_amount,0)
                )
                FROM detail_bayar db
                INNER JOIN head_bayar hb
                    ON hb.bayar_no = db.bayar_no
                WHERE db.invoice_no = hi.invoice_no
                  AND hb.customer_id = hi.customer_id
                  AND hb.bayar_date <= ?
                  AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
                  AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'
            ),0)
            - {$returnJoin}
        ) <= 0 THEN 'LUNAS / CREDIT'
        WHEN DATEDIFF(?, DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days,0) DAY)) <= 0
            THEN 'BJT'
        WHEN DATEDIFF(?, DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days,0) DAY)) BETWEEN 1 AND 30
            THEN '1-30'
        WHEN DATEDIFF(?, DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days,0) DAY)) BETWEEN 31 AND 60
            THEN '31-60'
        WHEN DATEDIFF(?, DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days,0) DAY)) BETWEEN 61 AND 90
            THEN '61-90'
        ELSE 'LEBIH'
    END AS expected_bucket_simple

FROM head_invoice hi
LEFT JOIN det_invoice di
    ON di.invoice_no = hi.invoice_no
WHERE hi.customer_id = ?
  AND hi.invoice_date BETWEEN '2026-08-01' AND ?
  AND UPPER(COALESCE(hi.invoice_no,'')) NOT LIKE '%CP-MCP%'
GROUP BY hi.invoice_no, hi.invoice_date, hi.days
ORDER BY hi.invoice_date, hi.invoice_no
";

/*
 * Parameter count differs depending on return table.
 * returnJoin occurs 3x in SQL.
 */
if (tableExists($conn, 'head_retur_invoice')) {
    $paramsPerInvoice = [
        $endDate,             // paid_total_simple
        $endDate,             // return 1
        $endDate,             // paid in outstanding
        $endDate,             // return 2
        $endDate,             // paid in CASE
        $endDate,             // return 3
        $endDate, $endDate, $endDate, $endDate, // bucket DATEDIFF
        $customerId, $endDate
    ];
    $typesPerInvoice = str_repeat('s', count($paramsPerInvoice));
} else {
    $paramsPerInvoice = [
        $endDate,
        $endDate,
        $endDate,
        $endDate, $endDate, $endDate, $endDate,
        $customerId, $endDate
    ];
    $typesPerInvoice = str_repeat('s', count($paramsPerInvoice));
}
$perInvoiceRows = fetchAllAssoc($conn, $sqlPerInvoice, $paramsPerInvoice, $typesPerInvoice);

/* ============================================================
 * 7. CROSS CHECK: payment rows yang refer ke shipping 001323/001028
 * ============================================================ */
$shippingCross = fetchAllAssoc(
    $conn,
    "SELECT
        hb.bayar_no,
        hb.bayar_date,
        db.payment_source,
        db.invoice_no,
        db.invoice_date AS detail_invoice_date,
        db.shipping_no,
        db.cash_amount,
        db.titip_amount,
        db.bayar_amount,
        db.return_id,
        db.remarks
     FROM head_bayar hb
     INNER JOIN detail_bayar db
       ON db.bayar_no = hb.bayar_no
     WHERE hb.customer_id = ?
       AND (
           db.shipping_no IN ('001323','001028')
           OR db.invoice_no IN ('INV/2026/000495','INV/2026/000221')
       )
     ORDER BY hb.bayar_date, hb.bayar_no, db.id",
    [$customerId],
    's'
);

/* ============================================================
 * HTML
 * ============================================================ */
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Diagnostic Post Base - Aditya Tani</title>
<style>
body{font-family:Arial,sans-serif;font-size:13px;margin:20px;color:#222}
h1{margin-bottom:5px}
h2{margin-top:28px;border-bottom:2px solid #333;padding-bottom:5px}
.note{padding:10px;background:#f5f5f5;border:1px solid #bbb}
.scroll{overflow-x:auto}
table{border-collapse:collapse;width:100%;margin-top:8px}
th,td{border:1px solid #999;padding:6px;vertical-align:top;white-space:nowrap}
th{background:#eee}
.focus{font-weight:bold}
</style>
</head>
<body>
<h1>Diagnostic POST_BASE - ADITYA TANI</h1>
<div class="note">
Customer ID: <strong><?=h($customerId)?></strong><br>
Periode: <strong><?=h($startDate)?> s/d <?=h($endDate)?></strong><br>
Baseline: <strong><?=h($baselineDate)?></strong><br>
READ ONLY / SELECT ONLY. Tidak mengubah data.
</div>

<?php
renderTable('1. Master Customer', $master);
renderTable('2. Invoice Aug-Sep + Shipping + Gross', $invoiceRows);
renderTable('3. Raw Detail Pembayaran September', $paymentRows);
renderTable('4. Detail B-000000413', $payment413);
renderTable('5. Raw Retur sampai 30-Sep', $returnRows);
renderTable('6. Per Invoice: Gross - Paid - Return - Outstanding - Expected Bucket', $perInvoiceRows);
renderTable('7. Cross Check INV000221 / INV000495 dan Shipping 001028 / 001323', $shippingCross);
?>

<h2>Yang perlu dikirim kembali</h2>
<p>
Copy terutama tabel <strong>6</strong> dan <strong>7</strong>. Jika tabel 6 menunjukkan
INV/2026/000495 outstanding Rp1.180.000 dan bucket BJT, tetapi Rev7 post_base_bjt tetap 0,
berarti masalah berada pada query clone POST_BASE Rev7, bukan pada data invoice.
</p>
</body>
</html>
