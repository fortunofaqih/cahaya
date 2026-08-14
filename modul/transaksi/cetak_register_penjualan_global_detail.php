<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

function h($v){
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function parseDateR($v, $fallback){
    $v = trim((string)$v);
    if ($v === '') return $fallback;

    foreach (['d-M-Y','Y-m-d','d-m-Y','d/m/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d instanceof DateTime) return $d->format('Y-m-d');
    }

    return $fallback;
}

function fmtDateR($d){
    if (empty($d) || $d === '0000-00-00') return '';
    $t = strtotime($d);
    return $t ? date('d-M-Y', $t) : '';
}

function fmtMoneyR($v){
    return number_format((float)$v, 2, ',', '.');
}

function fmtQtyR($v){
    $s = number_format((float)$v, 2, ',', '.');
    return rtrim(rtrim($s, '0'), ',');
}

function emptyCatR(){
    return ['qty'=>0.0, 'qty_kg'=>0.0, 'rp'=>0.0];
}

function emptyGrandR(){
    return [
        'total'=>0.0,
        'penjualan'=>0.0,
        'HD'=>emptyCatR(),
        'HD WARNA'=>emptyCatR(),
        'HD KRESEK'=>emptyCatR(),
        'HD SABLON'=>emptyCatR(),
        'TALI KG'=>emptyCatR(),
        'TALI LOS'=>emptyCatR(),
        'BAHAN'=>emptyCatR(),
        'TERPAL'=>emptyCatR(),
        'BOX'=>emptyCatR()
    ];
}

$today = date('Y-m-d');
$startDate = parseDateR($_GET['start_date'] ?? '', date('Y-m-01'));
$endDate   = parseDateR($_GET['end_date'] ?? '', $today);

if (strtotime($startDate) > strtotime($endDate)) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$sort = strtolower(trim((string)($_GET['sort'] ?? 'shipping_no')));
$dir  = strtolower(trim((string)($_GET['dir'] ?? 'asc')));

$allowedSort = ['shipping_no', 'invoice_no'];
$allowedDir  = ['asc', 'desc'];

if (!in_array($sort, $allowedSort, true)) $sort = 'shipping_no';
if (!in_array($dir, $allowedDir, true)) $dir = 'asc';

$orderColumn = $sort === 'invoice_no' ? 'di.invoice_no' : 'di.shipping_no';
$orderDirection = strtoupper($dir);

$sql = "
SELECT
    di.invoice_no,
    di.shipping_no,
    hi.invoice_date,
    hi.customer_name,
    MAX(COALESCE(di.total,0)) AS total_invoice_shipping,
    MAX(COALESCE(di.subtotal,0)) AS penjualan_shipping,
  CASE
    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%WARNA%'
        THEN 'PE WARNA'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%KERTAS%'
        THEN 'KERTAS'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])PP([^A-Z]|$)'
        THEN 'PP'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
        THEN 'PE'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%WARNA%'
        THEN 'HD WARNA'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%KRESEK%'
        THEN 'HD KRESEK'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%SABLON%'
        THEN 'HD SABLON'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%TALI%'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])KG([^A-Z]|$)'
        THEN 'TALI KG'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%TALI%'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%LOS%'
        THEN 'TALI LOS'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])BAHAN([^A-Z]|$)'
        THEN 'BAHAN'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%TERPAL%'
        THEN 'TERPAL'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])BOX([^A-Z]|$)'
        THEN 'BOX'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
        THEN 'HD'

    ELSE NULL
END AS category_group,
    SUM(
        CASE
            WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,''))) <> ''
             AND UPPER(TRIM(COALESCE(ds.uom_pack_shipping,''))) <> 'KG'
                THEN COALESCE(ds.qty_pack_shipping,0)
            WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,''))) <> ''
             AND UPPER(TRIM(COALESCE(ds.uom_detail_shipping,''))) <> 'KG'
                THEN COALESCE(ds.qty_detail_shipping,0)
            ELSE COALESCE(ds.qty_shipping,0)
        END
    ) AS category_qty,
    SUM(
        CASE
            WHEN UPPER(TRIM(COALESCE(ds.uom_shipping,''))) = 'KG'
                THEN COALESCE(ds.qty_shipping,0)
            WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,''))) = 'KG'
                THEN COALESCE(ds.qty_pack_shipping,0)
            WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,''))) = 'KG'
                THEN COALESCE(ds.qty_detail_shipping,0)
            ELSE 0
        END
    ) AS category_qty_kg,
    SUM(
        CASE
            WHEN COALESCE(ds.subtotal,0) <> 0
                THEN COALESCE(ds.subtotal,0)
            ELSE COALESCE(dso.price,0) *
                CASE
                    WHEN COALESCE(ds.qty_pack_shipping,0) > 0
                        THEN COALESCE(ds.qty_pack_shipping,0)
                    ELSE COALESCE(ds.qty_shipping,0)
                END
        END
    ) AS category_rp
FROM det_invoice di
INNER JOIN head_invoice hi ON hi.invoice_no = di.invoice_no
INNER JOIN hed_shipping hs ON hs.shipping_no = di.shipping_no
INNER JOIN det_shipping ds ON ds.shipping_no = di.shipping_no
LEFT JOIN m_inventory mi ON mi.inventory_id = ds.inventory_id
LEFT JOIN m_category mc
    ON TRIM(mc.categori_id) = TRIM(mi.category)
LEFT JOIN detail_sales_order dso
    ON dso.id = (
        SELECT MIN(dso2.id)
        FROM detail_sales_order dso2
        WHERE dso2.order_no = hs.order_no
          AND dso2.inventory_id = ds.inventory_id
    )
WHERE hi.invoice_date BETWEEN ? AND ?
GROUP BY
    di.invoice_no,
    di.shipping_no,
    hi.invoice_date,
    hi.customer_name,
    category_group
ORDER BY
    {$orderColumn} {$orderDirection},
    hi.invoice_date ASC,
    di.shipping_no ASC,
    di.invoice_no ASC,
    category_group ASC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die('SQL Cetak Register Penjualan Global Detail Error: ' . h(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, 'ss', $startDate, $endDate);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$cats = [
    'HD',
    'HD WARNA',
    'HD KRESEK',
    'HD SABLON',
    'TALI KG',
    'TALI LOS',
    'BAHAN',
    'TERPAL',
    'BOX'
];

$rows = [];
$grand = emptyGrandR();

while ($item = mysqli_fetch_assoc($res)) {
    $key = $item['invoice_no'] . '|' . $item['shipping_no'];

    if (!isset($rows[$key])) {
        $rows[$key] = [
            'shipping_no' => $item['shipping_no'],
            'invoice_no' => $item['invoice_no'],
            'customer_name' => $item['customer_name'],
            'total' => (float)$item['total_invoice_shipping'],
            'penjualan' => (float)$item['penjualan_shipping']
        ];

        foreach ($cats as $cat) {
            $rows[$key][$cat] = emptyCatR();
        }

        $grand['total'] += (float)$item['total_invoice_shipping'];
        $grand['penjualan'] += (float)$item['penjualan_shipping'];
    }

    $g = $item['category_group'];

    /*
     * Tidak ada kolom LAIN LAIN.
     * Inventory yang tidak cocok dengan kategori di atas tidak diakumulasi
     * ke kategori produk mana pun.
     */
    if ($g !== null && isset($rows[$key][$g], $grand[$g])) {
        $rows[$key][$g]['qty'] += (float)$item['category_qty'];
        $rows[$key][$g]['qty_kg'] += (float)$item['category_qty_kg'];
        $rows[$key][$g]['rp'] += (float)$item['category_rp'];

        $grand[$g]['qty'] += (float)$item['category_qty'];
        $grand[$g]['qty_kg'] += (float)$item['category_qty_kg'];
        $grand[$g]['rp'] += (float)$item['category_rp'];
    }
}

mysqli_stmt_close($stmt);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Register Penjualan Global Detail</title>

<style>
@page {
    size: A4 landscape;
    margin: 7mm 5mm 7mm 5mm;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    color: #000;
    background: #fff;
    font-size: 6.5px;
}

.print-header {
    text-align: center;
    margin-bottom: 7px;
}

.print-header h2 {
    margin: 0 0 3px;
    font-size: 14px;
    text-transform: uppercase;
}

.print-header .period {
    font-size: 9px;
    font-weight: bold;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 6.4px;
}

.report-table th,
.report-table td {
    border: 0.5px solid #555;
    padding: 2px 1px;
    vertical-align: middle;
}

.report-table th {
    background: #ececec;
    font-weight: bold;
    text-align: center;
    line-height: 1.15;
}

.report-table td {
    white-space: nowrap;
}

.shipping-col { width: 64px; }
.invoice-col  { width: 64px; }
.customer-col { width: 92px; }
.total-col    { width: 55px; }

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

.category-head {
    font-size: 6.1px;
}

.sub-head {
    font-size: 5.7px;
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

.print-actions {
    margin-bottom: 8px;
    text-align: right;
}

.print-btn {
    border: 0;
    border-radius: 3px;
    background: #1e3c72;
    color: #fff;
    padding: 6px 12px;
    font-size: 11px;
    font-weight: bold;
    cursor: pointer;
}

@media print {
    .print-actions {
        display: none !important;
    }

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
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
</head>

<body>

<div class="print-actions">
    <button type="button" class="print-btn" onclick="window.print()">Print</button>
</div>

<div class="print-header">
    <h2>Register Penjualan Global Detail</h2>
    <div class="period">
        Periode <?= h(fmtDateR($startDate)) ?> s/d <?= h(fmtDateR($endDate)) ?>
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
                <th colspan="3" class="category-head"><?= h($cat) ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($cats as $cat): ?>
                <th class="sub-head">Qty</th>
                <th class="sub-head">Qty KG</th>
                <th class="sub-head">Rp</th>
            <?php endforeach; ?>
        </tr>
    </thead>

    <tbody>
    <?php if (empty($rows)): ?>
        <tr>
            <td colspan="32" class="no-data">
                Tidak ada data invoice pada periode ini.
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= h($row['shipping_no']) ?></td>
                <td><?= h($row['invoice_no']) ?></td>
                <td class="customer-cell"><?= h($row['customer_name']) ?></td>
                <td class="money-cell"><?= h(fmtMoneyR($row['total'])) ?></td>
                <td class="money-cell"><?= h(fmtMoneyR($row['penjualan'])) ?></td>

                <?php foreach ($cats as $cat): ?>
                    <td class="qty-cell"><?= h(fmtQtyR($row[$cat]['qty'])) ?></td>
                    <td class="qty-cell"><?= h(fmtQtyR($row[$cat]['qty_kg'])) ?></td>
                    <td class="money-cell"><?= h(fmtMoneyR($row[$cat]['rp'])) ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>

    <tfoot>
        <tr>
            <td colspan="3" style="text-align:right;">TOTAL</td>
            <td class="money-cell"><?= h(fmtMoneyR($grand['total'])) ?></td>
            <td class="money-cell"><?= h(fmtMoneyR($grand['penjualan'])) ?></td>

            <?php foreach ($cats as $cat): ?>
                <td class="qty-cell"><?= h(fmtQtyR($grand[$cat]['qty'])) ?></td>
                <td class="qty-cell"><?= h(fmtQtyR($grand[$cat]['qty_kg'])) ?></td>
                <td class="money-cell"><?= h(fmtMoneyR($grand[$cat]['rp'])) ?></td>
            <?php endforeach; ?>
        </tr>
    </tfoot>
</table>

<script>
window.addEventListener('load', function () {
    window.print();
});
</script>

</body>
</html>