<?php
// modul/transaksi/cetak_rekap_detail_penjualan.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatDateIndonesian($date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Ags',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];

    $ts = strtotime($date);

    if (!$ts) {
        return '';
    }

    return date('d', $ts) . '-' .
        $bulan[(int)date('m', $ts)] . '-' .
        date('Y', $ts);
}

function convertFilterDateToMysql($date): string
{
    $date = trim((string)$date);

    if ($date === '') {
        return '';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    $months = [
        'Jan' => '01',
        'Feb' => '02',
        'Mar' => '03',
        'Apr' => '04',
        'May' => '05',
        'Mei' => '05',
        'Jun' => '06',
        'Jul' => '07',
        'Aug' => '08',
        'Agu' => '08',
        'Ags' => '08',
        'Sep' => '09',
        'Oct' => '10',
        'Okt' => '10',
        'Nov' => '11',
        'Dec' => '12',
        'Des' => '12'
    ];

    $parts = explode('-', $date);

    if (count($parts) === 3) {
        $day = str_pad(trim($parts[0]), 2, '0', STR_PAD_LEFT);
        $monthText = substr(trim($parts[1]), 0, 3);
        $year = trim($parts[2]);

        if (isset($months[$monthText])) {
            return $year . '-' . $months[$monthText] . '-' . $day;
        }
    }

    return '';
}

function formatMonthIndonesian($date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Ags',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];

    $ts = strtotime($date);

    if (!$ts) {
        return '';
    }

    return $bulan[(int)date('m', $ts)];
}

function qtyFormat($value): string
{
    $value = (float)$value;
    $formatted = number_format($value, 4, ',', '.');
    $formatted = rtrim($formatted, '0');
    $formatted = rtrim($formatted, ',');

    return $formatted === '' ? '0' : $formatted;
}

function moneyFormat($value): string
{
    return number_format((float)$value, 0, ',', '.');
}

/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/
$today = date('Y-m-d');

$startDateRaw = trim((string)($_GET['start_date'] ?? formatDateIndonesian($today)));
$endDateRaw = trim((string)($_GET['end_date'] ?? formatDateIndonesian($today)));
$customerId = trim((string)($_GET['customer_id'] ?? ''));

$startDateSql = convertFilterDateToMysql($startDateRaw);
$endDateSql = convertFilterDateToMysql($endDateRaw);

if ($startDateSql === '') {
    $startDateSql = $today;
    $startDateRaw = formatDateIndonesian($today);
}

if ($endDateSql === '') {
    $endDateSql = $today;
    $endDateRaw = formatDateIndonesian($today);
}

if ($startDateSql > $endDateSql) {
    [$startDateSql, $endDateSql] = [$endDateSql, $startDateSql];
    [$startDateRaw, $endDateRaw] = [$endDateRaw, $startDateRaw];
}

$startSafe = mysqli_real_escape_string($conn, $startDateSql);
$endSafe = mysqli_real_escape_string($conn, $endDateSql);
$customerSafe = mysqli_real_escape_string($conn, $customerId);

$where = "
    WHERE DATE(hi.invoice_date) BETWEEN '$startSafe' AND '$endSafe'
      AND COALESCE(hi.invoice_no, '') NOT LIKE 'CP-MCP/INV/%'
      AND COALESCE(hi.order_no, '') NOT LIKE 'CP-MCP/SO/%'
";

if ($customerSafe !== '') {
    $where .= " AND hi.customer_id = '$customerSafe' ";
}

/*
|--------------------------------------------------------------------------
| QUERY
|--------------------------------------------------------------------------
| Price Unit, Price, Subtotal diambil dari detail_sales_order.
| Relasi detail SO: order_no + inventory_id.
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        hi.customer_id,
        hi.customer_name,
        hi.invoice_no,
        hi.invoice_date,

        di.shipping_no,
        COALESCE(di.order_no, hi.order_no, hs.order_no) AS order_no,

        ds.inventory_id,
        ds.inventory_name,
        ds.qty_shipping,
        ds.uom_shipping,
        ds.qty_pack_shipping,
        ds.uom_pack_shipping,
        ds.qty_detail_shipping,
        ds.uom_detail_shipping,

        (
            SELECT dso.price_unit
            FROM detail_sales_order dso
            WHERE dso.order_no = COALESCE(di.order_no, hi.order_no, hs.order_no)
              AND dso.inventory_id = ds.inventory_id
            ORDER BY dso.id ASC
            LIMIT 1
        ) AS price_unit,

        (
            SELECT dso.price
            FROM detail_sales_order dso
            WHERE dso.order_no = COALESCE(di.order_no, hi.order_no, hs.order_no)
              AND dso.inventory_id = ds.inventory_id
            ORDER BY dso.id ASC
            LIMIT 1
        ) AS price,

        (
            SELECT dso.subtotal
            FROM detail_sales_order dso
            WHERE dso.order_no = COALESCE(di.order_no, hi.order_no, hs.order_no)
              AND dso.inventory_id = ds.inventory_id
            ORDER BY dso.id ASC
            LIMIT 1
        ) AS subtotal

    FROM head_invoice hi

    INNER JOIN det_invoice di
        ON di.invoice_no = hi.invoice_no

    LEFT JOIN hed_shipping hs
        ON hs.shipping_no = di.shipping_no

    INNER JOIN det_shipping ds
        ON ds.shipping_no = di.shipping_no

    $where

    ORDER BY
        hi.customer_name ASC,
        hi.invoice_date ASC,
        hi.invoice_no ASC,
        di.shipping_no ASC,
        ds.id ASC
";

$query = mysqli_query($conn, $sql);

if (!$query) {
    die('Query Cetak Rekap Detail Penjualan Error: ' . mysqli_error($conn));
}

$rows = [];
$grandSubtotal = 0;

while ($row = mysqli_fetch_assoc($query)) {
    $rows[] = $row;
    $grandSubtotal += (float)($row['subtotal'] ?? 0);
}

/*
|--------------------------------------------------------------------------
| GROUPING CUSTOMER + BULAN
|--------------------------------------------------------------------------
| Customer ditampilkan sekali per customer.
| Bulan ditampilkan sekali per bulan di dalam customer tersebut.
|--------------------------------------------------------------------------
*/
$customerRowspan = [];
$monthRowspan = [];

foreach ($rows as $row) {
    $customerKey = (string)($row['customer_id'] ?? '') . '|' . (string)($row['customer_name'] ?? '');
    $monthKey = $customerKey . '|' . date('Y-m', strtotime($row['invoice_date']));

    if (!isset($customerRowspan[$customerKey])) {
        $customerRowspan[$customerKey] = 0;
    }

    if (!isset($monthRowspan[$monthKey])) {
        $monthRowspan[$monthKey] = 0;
    }

    $customerRowspan[$customerKey]++;
    $monthRowspan[$monthKey]++;
}

/*
|--------------------------------------------------------------------------
| CUSTOMER TITLE
|--------------------------------------------------------------------------
*/
$customerTitle = 'Semua Customer';

if ($customerId !== '') {
    $customerTitle = '';

    foreach ($rows as $row) {
        if ((string)$row['customer_id'] === $customerId) {
            $customerTitle = (string)$row['customer_name'];
            break;
        }
    }

    if ($customerTitle === '') {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT customer FROM m_customer WHERE customer_id = ? LIMIT 1"
        );

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $customerId);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $customerRow = mysqli_fetch_assoc($result);
            mysqli_stmt_close($stmt);

            $customerTitle = trim((string)($customerRow['customer'] ?? ''));
        }
    }

    if ($customerTitle === '') {
        $customerTitle = $customerId;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Laporan Detail Penjualan</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 10px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 8px;
    color: #000;
    background: #fff;
}

.report-title {
    text-align: center;
    margin-bottom: 10px;
}

.report-title h2 {
    margin: 0;
    font-size: 15px;
    text-transform: uppercase;
}

.report-title .period {
    margin-top: 4px;
    font-size: 10px;
}

.report-title .customer {
    margin-top: 3px;
    font-size: 9px;
    font-weight: bold;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.report-table th,
.report-table td {
    border: 1px solid #000;
    padding: 3px 3px;
    vertical-align: middle;
    word-wrap: break-word;
}

.report-table th {
    background: #e9ecef;
    text-align: center;
    font-size: 7px;
    font-weight: bold;
}

.report-table td {
    font-size: 7px;
}

.text-center {
    text-align: center;
}

td[rowspan] {
    vertical-align: top !important;
}

.text-right {
    text-align: right;
}

.text-left {
    text-align: left;
}

.nowrap {
    white-space: nowrap;
}

.customer-cell {
    font-weight: bold;
    vertical-align: top !important;
    background: #f8f9fa;
}

.total-row {
    background: #efefef;
    font-weight: bold;
}

.no-data {
    text-align: center;
    padding: 20px;
    border: 1px solid #000;
    font-weight: bold;
}

.action-buttons {
    text-align: center;
    margin-top: 15px;
}

.action-buttons button {
    padding: 7px 14px;
    margin: 0 4px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
}

.btn-print {
    background: #0d6efd;
    color: #fff;
}

.btn-close {
    background: #6c757d;
    color: #fff;
}

/* Lebar kolom A4 landscape */
.col-customer   { width: 10%; }
.col-month      { width: 4%; }
.col-date       { width: 6%; }
.col-qty        { width: 5%; }
.col-uom        { width: 4%; }
.col-qty-pack   { width: 5%; }
.col-uom-pack   { width: 5%; }
.col-qty-detail { width: 5%; }
.col-uom-detail { width: 5%; }
.col-price-unit { width: 7%; }
.col-price      { width: 7%; }
.col-subtotal   { width: 8%; }
.col-invoice    { width: 9%; }
.col-inventory  { width: 15%; }

@media print {
    body {
        padding: 0;
    }

    .action-buttons {
        display: none !important;
    }

    .report-table thead {
        display: table-header-group;
    }

    .report-table tr {
        page-break-inside: avoid;
        break-inside: avoid-page;
    }

    @page {
        size: A4 landscape;
        margin: 7mm;
    }
}
</style>
</head>

<body>

<div class="report-title">
    <h2>Laporan Detail Penjualan</h2>

    <div class="period">
        Pada Tanggal
        <strong><?= h(formatDateIndonesian($startDateSql)) ?></strong>
        s/d
        <strong><?= h(formatDateIndonesian($endDateSql)) ?></strong>
    </div>

    <div class="customer">
        Customer: <?= h($customerTitle) ?>
    </div>
</div>

<?php if (!$rows): ?>

    <div class="no-data">
        Tidak ada data penjualan pada periode / customer tersebut.
    </div>

<?php else: ?>

<table class="report-table">
    <thead>
        <tr>
            <th class="col-customer">Nama Customer</th>
            <th class="col-month">Bulan</th>
            <th class="col-date">Tanggal</th>
            <th class="col-qty">Qty</th>
            <th class="col-uom">UoM</th>
            <th class="col-qty-pack">Qty Pack</th>
            <th class="col-uom-pack">UoM Pack</th>
            <th class="col-qty-detail">Qty Detail</th>
            <th class="col-uom-detail">UoM Detail</th>
            <th class="col-price-unit">Price Unit</th>
            <th class="col-price">Price</th>
            <th class="col-subtotal">Subtotal</th>
            <th class="col-invoice">Invoice No.</th>
            <th class="col-inventory">Inventory Name</th>
        </tr>
    </thead>

    <tbody>
        <?php
        $printedCustomer = [];
        $printedMonth = [];

        foreach ($rows as $row):

            $customerKey =
                (string)($row['customer_id'] ?? '') .
                '|' .
                (string)($row['customer_name'] ?? '');

            $monthKey =
                $customerKey .
                '|' .
                date('Y-m', strtotime($row['invoice_date']));
        ?>
            <tr>

                <?php if (!isset($printedCustomer[$customerKey])): ?>
                    <td
                        class="customer-cell"
                        rowspan="<?= (int)$customerRowspan[$customerKey] ?>"
                    >
                        <?= h($row['customer_name'] ?: '-') ?>
                    </td>
                    <?php $printedCustomer[$customerKey] = true; ?>
                <?php endif; ?>

                <?php if (!isset($printedMonth[$monthKey])): ?>
                    <td
                        class="text-center"
                        rowspan="<?= (int)$monthRowspan[$monthKey] ?>"
                    >
                        <?= h(formatMonthIndonesian($row['invoice_date'])) ?>
                    </td>
                    <?php $printedMonth[$monthKey] = true; ?>
                <?php endif; ?>

                <td class="text-center nowrap">
                    <?= h(formatDateIndonesian($row['invoice_date'])) ?>
                </td>

                <td class="text-right">
                    <?= qtyFormat($row['qty_shipping']) ?>
                </td>

                <td class="text-center">
                    <?= h($row['uom_shipping'] ?: '-') ?>
                </td>

                <td class="text-right">
                    <?= qtyFormat($row['qty_pack_shipping']) ?>
                </td>

                <td class="text-center">
                    <?= h($row['uom_pack_shipping'] ?: '-') ?>
                </td>

                <td class="text-right">
                    <?= qtyFormat($row['qty_detail_shipping']) ?>
                </td>

                <td class="text-center">
                    <?= h($row['uom_detail_shipping'] ?: '-') ?>
                </td>

                <td class="text-right nowrap">
                    Rp <?= moneyFormat($row['price_unit']) ?>
                </td>

                <td class="text-right nowrap">
                    Rp <?= moneyFormat($row['price']) ?>
                </td>

                <td class="text-right nowrap">
                    Rp <?= moneyFormat($row['subtotal']) ?>
                </td>

                <td class="text-center">
                    <?= h($row['invoice_no'] ?: '-') ?>
                </td>

                <td>
                    <?= h($row['inventory_name'] ?: '-') ?>
                </td>
            </tr>
        <?php endforeach; ?>

        <tr class="total-row">
            <td colspan="11" class="text-right">
                TOTAL :
            </td>

            <td class="text-right nowrap">
                Rp <?= moneyFormat($grandSubtotal) ?>
            </td>

            <td colspan="2"></td>
        </tr>
    </tbody>
</table>

<?php endif; ?>

<div class="action-buttons">
    <button
        type="button"
        class="btn-print"
        onclick="window.print()"
    >
        Print
    </button>

    <button
        type="button"
        class="btn-close"
        onclick="window.close()"
    >
        Close
    </button>
</div>

<script>
window.onload = function() {
    window.print();
};
</script>

</body>
</html>
