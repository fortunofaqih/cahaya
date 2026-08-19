<?php
// modul/transaksi/register_penjualan_global_return.php

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function parseDateR($v,$f){
    $v = trim((string)$v);
    if ($v === '') return $f;

    foreach (['d-M-Y','Y-m-d','d-m-Y','d/m/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt,$v);
        if ($d instanceof DateTime) return $d->format('Y-m-d');
    }

    return $f;
}

function fmtDateR($d){
    if (empty($d) || $d === '0000-00-00') return '';
    $t = strtotime($d);
    return $t ? date('d-M-Y',$t) : '';
}

function fmtMoneyR($v){
    return number_format((float)$v,2,',','.');
}

function fmtQtyR($v){
    $s = number_format((float)$v,2,',','.');
    return rtrim(rtrim($s,'0'),',');
}

function emptyCatR(){
    return ['qty'=>0.0,'qty_kg'=>0.0,'rp'=>0.0];
}

function emptyGrandR(){
    return [
        'total'=>0.0,
        'penjualan'=>0.0,
        'PP'=>emptyCatR(),
        'KERTAS'=>emptyCatR(),
        'PE'=>emptyCatR(),
        'PE WARNA'=>emptyCatR(),
        'LAIN LAIN'=>emptyCatR()
    ];
}

$today = date('Y-m-d');
$startDate = parseDateR($_GET['start_date'] ?? '', date('Y-m-01'));
$endDate   = parseDateR($_GET['end_date'] ?? '', $today);

if (strtotime($startDate) > strtotime($endDate)) {
    [$startDate,$endDate] = [$endDate,$startDate];
}

/*
 * Sorting mengikuti Register Penjualan Global:
 * hanya Shipping No. dan Invoice No.
 */
$sort = strtolower(trim((string)($_GET['sort'] ?? 'shipping_no')));
$dir  = strtolower(trim((string)($_GET['dir'] ?? 'asc')));

$allowedSort = ['shipping_no','invoice_no'];
$allowedDir  = ['asc','desc'];

if (!in_array($sort,$allowedSort,true)) $sort = 'shipping_no';
if (!in_array($dir,$allowedDir,true)) $dir = 'asc';

$orderColumn = $sort === 'invoice_no' ? 'hri.invoice_no' : 'hri.shipping_no';
$orderDirection = strtoupper($dir);

function sortUrlR($column,$currentSort,$currentDir,$startDate,$endDate){
    $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';

    return 'index.php?' . http_build_query([
        'page' => 'register_penjualan_global_return',
        'start_date' => fmtDateR($startDate),
        'end_date' => fmtDateR($endDate),
        'sort' => $column,
        'dir' => $nextDir,
    ]);
}

function sortIconR($column,$currentSort,$currentDir){
    if ($currentSort !== $column) return '↕';
    return $currentDir === 'asc' ? '▲' : '▼';
}

/*
|--------------------------------------------------------------------------
| DATA RETUR
|--------------------------------------------------------------------------
| - Sumber header  : head_retur_invoice
| - Sumber detail  : detail_retur_invoice
| - Cancelled tidak dihitung.
| - Kategori dibaca dari inventory_name, mengikuti Register Penjualan Global.
| - CP-MCP: nilai Penjualan Retur memakai grand_total.
| - Retur normal: nilai Penjualan Retur memakai return_amount.
|--------------------------------------------------------------------------
*/
$sql = "
SELECT
    hri.return_id,
    hri.return_date,
    hri.invoice_no,
    hri.shipping_no,
    hri.customer_name,

    MAX(COALESCE(hri.grand_total,0)) AS total_return,

    MAX(
        CASE
            WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
              OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                THEN COALESCE(hri.grand_total,0)
            ELSE COALESCE(hri.return_amount,0)
        END
    ) AS penjualan_return,

    CASE
        WHEN UPPER(
            COALESCE(
                NULLIF(TRIM(dri.inventory_name),''),
                NULLIF(TRIM(mi.inventory_name),''),
                ''
            )
        ) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
        AND UPPER(
            COALESCE(
                NULLIF(TRIM(dri.inventory_name),''),
                NULLIF(TRIM(mi.inventory_name),''),
                ''
            )
        ) LIKE '%WARNA%'
            THEN 'PE WARNA'

        WHEN UPPER(
            COALESCE(
                NULLIF(TRIM(dri.inventory_name),''),
                NULLIF(TRIM(mi.inventory_name),''),
                ''
            )
        ) LIKE '%KERTAS%'
            THEN 'KERTAS'

        WHEN UPPER(
            COALESCE(
                NULLIF(TRIM(dri.inventory_name),''),
                NULLIF(TRIM(mi.inventory_name),''),
                ''
            )
        ) REGEXP '(^|[^A-Z])PP([^A-Z]|$)'
            THEN 'PP'

        WHEN UPPER(
            COALESCE(
                NULLIF(TRIM(dri.inventory_name),''),
                NULLIF(TRIM(mi.inventory_name),''),
                ''
            )
        ) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
            THEN 'PE'

        ELSE 'LAIN LAIN'
    END AS category_group,

    SUM(
        CASE
            WHEN UPPER(TRIM(COALESCE(dri.uom_pack,''))) <> ''
             AND UPPER(TRIM(COALESCE(dri.uom_pack,''))) <> 'KG'
                THEN COALESCE(dri.return_quantity_pack,0)

            WHEN UPPER(TRIM(COALESCE(dri.uom_detail,''))) <> ''
             AND UPPER(TRIM(COALESCE(dri.uom_detail,''))) <> 'KG'
                THEN COALESCE(dri.return_quantity_detail,0)

            ELSE COALESCE(dri.return_quantity,0)
        END
    ) AS category_qty,

    SUM(
        CASE
            WHEN UPPER(TRIM(COALESCE(dri.uom,''))) = 'KG'
                THEN COALESCE(dri.return_quantity,0)

            WHEN UPPER(TRIM(COALESCE(dri.uom_pack,''))) = 'KG'
                THEN COALESCE(dri.return_quantity_pack,0)

            WHEN UPPER(TRIM(COALESCE(dri.uom_detail,''))) = 'KG'
                THEN COALESCE(dri.return_quantity_detail,0)

            ELSE 0
        END
    ) AS category_qty_kg,

    SUM(COALESCE(dri.return_subtotal,0)) AS category_rp

FROM head_retur_invoice hri

INNER JOIN detail_retur_invoice dri
    ON TRIM(dri.return_id) = TRIM(hri.return_id)

LEFT JOIN m_inventory mi
    ON mi.inventory_id = dri.inventory_id

WHERE hri.return_date BETWEEN ? AND ?
  AND LOWER(COALESCE(hri.status,'Open')) <> 'cancelled'

GROUP BY
    hri.return_id,
    hri.return_date,
    hri.invoice_no,
    hri.shipping_no,
    hri.customer_name,
    category_group

ORDER BY
    {$orderColumn} {$orderDirection},
    hri.return_date ASC,
    hri.return_id ASC,
    category_group ASC
";

$stmt = mysqli_prepare($conn,$sql);

if (!$stmt) {
    die('SQL Register Penjualan Global Return Error: ' . h(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt,'ss',$startDate,$endDate);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$cats = ['PP','KERTAS','PE','PE WARNA','LAIN LAIN'];

$rows = [];
$grand = emptyGrandR();

while ($item = mysqli_fetch_assoc($res)) {
    /*
     * Satu return_id dibuat satu baris.
     * Invoice/shipping yang sama boleh mempunyai beberapa retur berbeda.
     */
    $key =
        (string)$item['return_id'] . '|' .
        (string)$item['invoice_no'] . '|' .
        (string)$item['shipping_no'];

    if (!isset($rows[$key])) {
        $rows[$key] = [
            'return_id' => $item['return_id'],
            'return_date' => $item['return_date'],
            'shipping_no' => $item['shipping_no'],
            'invoice_no' => $item['invoice_no'],
            'customer_name' => $item['customer_name'],
            'total' => (float)$item['total_return'],
            'penjualan' => (float)$item['penjualan_return'],
            'PP'=>emptyCatR(),
            'KERTAS'=>emptyCatR(),
            'PE'=>emptyCatR(),
            'PE WARNA'=>emptyCatR(),
            'LAIN LAIN'=>emptyCatR()
        ];

        $grand['total'] += (float)$item['total_return'];
        $grand['penjualan'] += (float)$item['penjualan_return'];
    }

    $g = $item['category_group'];

    if (!isset($rows[$key][$g])) {
        $g = 'LAIN LAIN';
    }

    $rows[$key][$g]['qty'] += (float)$item['category_qty'];
    $rows[$key][$g]['qty_kg'] += (float)$item['category_qty_kg'];
    $rows[$key][$g]['rp'] += (float)$item['category_rp'];

    $grand[$g]['qty'] += (float)$item['category_qty'];
    $grand[$g]['qty_kg'] += (float)$item['category_qty_kg'];
    $grand[$g]['rp'] += (float)$item['category_rp'];
}

mysqli_stmt_close($stmt);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Register Penjualan Global Return</title>

<style>
@page {
    size: A4 landscape;
    margin: 7mm 5mm;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    color: #000;
    background: #f3f5f7;
    font-size: 11px;
    padding: 18px 28px;
}

.print-actions {
    margin-bottom: 8px;
    text-align: right;
}

.print-btn {
    border: 0;
    border-radius: 3px;
    background: #8b0000;
    color: #fff;
    padding: 6px 12px;
    font-size: 11px;
    font-weight: bold;
    cursor: pointer;
}

.print-header {
    text-align: center;
    margin-bottom: 7px;
}

.print-header h2 {
    margin: 0 0 5px;
    font-size: 20px;
    text-transform: uppercase;
}

.print-header .period {
    font-size: 12px;
    font-weight: bold;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 10.5px;
}

.report-table th,
.report-table td {
    border: 0.5px solid #555;
    padding: 5px 4px;
    vertical-align: middle;
}

.report-table th {
    background: #ececec;
    font-weight: bold;
    text-align: center;
}

.report-table td {
    white-space: nowrap;
}

.shipping-col { width: 150px; }
.invoice-col  { width: 150px; }
.customer-col { width: 180px; }
.total-col    { width: 95px; }

.customer-cell {
    white-space: normal !important;
    overflow-wrap: anywhere;
    line-height: 1.1;
}

.qty-cell,
.money-cell {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

tfoot td {
    font-weight: bold;
    background: #f2f2f2;
}

.no-data {
    text-align: center;
    padding: 12px !important;
    font-size: 9px;
}


.print-card {
    width: 98%;
    max-width: 1850px;
    margin: 0 auto 22px;
    padding: 20px 24px;
    background: #fff;
    border: 1px solid #d9dee5;
    border-radius: 6px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
}


@media screen {
    .print-card {
        overflow-x: auto;
    }

    .report-table {
        min-width: 1850px;
    }

    .report-table th {
        font-size: 10.5px;
    }

    .report-table td {
        font-size: 10px;
    }
}

@media print {
    .print-actions {
        display: none !important;
    }

    .report-table {
        min-width: 0 !important;
        font-size: 6.6px !important;
    }

    .report-table th,
    .report-table td {
        padding: 2.2px 2px !important;
        font-size: 6.4px !important;
        line-height: 1.15 !important;
    }

    .print-header h2 {
        font-size: 14px !important;
    }

    .print-header .period {
        font-size: 9px !important;
    }


    .report-table th:nth-child(1),
    .report-table td:nth-child(1),
    .report-table th:nth-child(2),
    .report-table td:nth-child(2) {
        white-space: nowrap !important;
        font-size: 6.2px !important;
    }

    .shipping-col { width: 82px !important; }    .invoice-col  { width: 82px !important; }
    .customer-col { width: 86px !important; }
    .total-col    { width: 49px !important; }

    thead {
        display: table-header-group;
    }

    tfoot {
        display: table-row-group;
    }

    tr {
        page-break-inside: avoid;
    }

    body {
        padding: 0 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .print-card {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
    }
}
</style>
</head>

<body>

<div class="print-actions">
    <button type="button" class="print-btn" onclick="window.print()">
        Print
    </button>
</div>

<div class="print-card">

<div class="print-header">
    <h2>Register Penjualan Global Return</h2>

    <div class="period">
        Periode <?=h(fmtDateR($startDate))?> s/d <?=h(fmtDateR($endDate))?>
    </div>
</div>

<table class="report-table">
    <thead>
        <tr>
            <th rowspan="2" class="shipping-col">SHIPPING NO.</th>
            <th rowspan="2" class="invoice-col">INVOICE NO.</th>
            <th rowspan="2" class="customer-col">NAMA CUST.</th>
            <th rowspan="2" class="total-col">TOTAL</th>
            <th rowspan="2" class="total-col">PENJUALAN</th>

            <?php foreach ($cats as $cat): ?>
                <th colspan="3"><?=h($cat)?></th>
            <?php endforeach; ?>
        </tr>

        <tr>
            <?php foreach ($cats as $cat): ?>
                <th>Qty</th>
                <th>Qty KG</th>
                <th>Rp</th>
            <?php endforeach; ?>
        </tr>
    </thead>

    <tbody>
    <?php if (empty($rows)): ?>
        <tr>
            <td colspan="20" class="no-data">
                Tidak ada data return penjualan pada periode ini.
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?=h($row['shipping_no'])?></td>
                <td><?=h($row['invoice_no'])?></td>
                <td class="customer-cell"><?=h($row['customer_name'])?></td>
                <td class="money-cell"><?=h(fmtMoneyR($row['total']))?></td>
                <td class="money-cell"><?=h(fmtMoneyR($row['penjualan']))?></td>

                <?php foreach ($cats as $cat): ?>
                    <td class="qty-cell"><?=h(fmtQtyR($row[$cat]['qty']))?></td>
                    <td class="qty-cell"><?=h(fmtQtyR($row[$cat]['qty_kg']))?></td>
                    <td class="money-cell"><?=h(fmtMoneyR($row[$cat]['rp']))?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>

    <tfoot>
        <tr>
            <td colspan="3" style="text-align:right">TOTAL RETURN</td>
            <td class="money-cell"><?=h(fmtMoneyR($grand['total']))?></td>
            <td class="money-cell"><?=h(fmtMoneyR($grand['penjualan']))?></td>

            <?php foreach ($cats as $cat): ?>
                <td class="qty-cell"><?=h(fmtQtyR($grand[$cat]['qty']))?></td>
                <td class="qty-cell"><?=h(fmtQtyR($grand[$cat]['qty_kg']))?></td>
                <td class="money-cell"><?=h(fmtMoneyR($grand[$cat]['rp']))?></td>
            <?php endforeach; ?>
        </tr>
    </tfoot>
</table>

</div>

<script>
window.addEventListener('load', function () {
    window.print();
});
</script>

</body>
</html>