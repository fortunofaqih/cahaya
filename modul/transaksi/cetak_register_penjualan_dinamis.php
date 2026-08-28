<?php
// modul/transaksi/register_penjualan_dinamis.php

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
    $v=trim((string)$v);
    if($v==='') return $f;
    foreach(['d-M-Y','Y-m-d','d-m-Y','d/m/Y'] as $fmt){
        $d=DateTime::createFromFormat($fmt,$v);
        if($d instanceof DateTime) return $d->format('Y-m-d');
    }
    return $f;
}
function fmtDateR($d){
    if(empty($d)||$d==='0000-00-00') return '';
    $t=strtotime($d);
    return $t?date('d-M-Y',$t):'';
}
function fmtMoneyR($v){ return number_format((float)$v,2,',','.'); }
function fmtQtyR($v){
    $s=number_format((float)$v,2,',','.');
    return rtrim(rtrim($s,'0'),',');
}
function emptyCatR(){ return ['qty'=>0.0,'qty_kg'=>0.0,'rp'=>0.0]; }

/*
|--------------------------------------------------------------------------
| KATEGORI TERSEDIA
|--------------------------------------------------------------------------
*/
$allCats = [
    'PP','KERTAS','PE','PE WARNA','LAIN LAIN',
    'HD','HD WARNA','HD KRESEK','HD SABLON','PP SABLON',
    'TALI KG','TALI LOS','BAHAN','TERPAL','BOX','SEDOTAN'
];

// Default awal mengikuti Register Penjualan Global existing.
$defaultCats = ['PP','KERTAS','PE','PE WARNA','LAIN LAIN'];

/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/
$today=date('Y-m-d');
$startDate=parseDateR($_GET['start_date']??'',date('Y-m-01'));
$endDate=parseDateR($_GET['end_date']??'',$today);
if(strtotime($startDate)>strtotime($endDate)) [$startDate,$endDate]=[$endDate,$startDate];

$kategoriSubmitted = isset($_GET['kategori_submitted']);
$postedCats = $_GET['kategori'] ?? null;

if (!$kategoriSubmitted && $postedCats === null) {
    $selectedCats = $defaultCats;
} else {
    $selectedCats = is_array($postedCats) ? $postedCats : [];
    $selectedCats = array_values(array_unique(array_filter(array_map('trim',$selectedCats), function($cat) use ($allCats){
        return in_array($cat,$allCats,true);
    })));
}

/*
|--------------------------------------------------------------------------
| SORTING
|--------------------------------------------------------------------------
*/
$sort = strtolower(trim((string)($_GET['sort'] ?? 'shipping_no')));
$dir  = strtolower(trim((string)($_GET['dir'] ?? 'asc')));

$allowedSort = ['shipping_no','invoice_no'];
$allowedDir  = ['asc','desc'];

if(!in_array($sort,$allowedSort,true)) $sort='shipping_no';
if(!in_array($dir,$allowedDir,true)) $dir='asc';

$orderColumn = $sort === 'invoice_no' ? 'di.invoice_no' : 'di.shipping_no';
$orderDirection = strtoupper($dir);

function sortUrlDinamis($column,$currentSort,$currentDir,$startDate,$endDate,$selectedCats){
    $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    return 'index.php?' . http_build_query([
        'page'=>'register_penjualan_dinamis',
        'start_date'=>fmtDateR($startDate),
        'end_date'=>fmtDateR($endDate),
        'sort'=>$column,
        'dir'=>$nextDir,
        'kategori_submitted'=>1,
        'kategori'=>$selectedCats,
    ]);
}

function sortIconR($column,$currentSort,$currentDir){
    if($currentSort!==$column) return '↕';
    return $currentDir==='asc'?'▲':'▼';
}

/*
|--------------------------------------------------------------------------
| DATA PENJUALAN
|--------------------------------------------------------------------------
| Rule kategori menggabungkan rule Register Penjualan Global dan
| Register Penjualan Global Detail.
|--------------------------------------------------------------------------
*/
$sql = "
SELECT
    di.invoice_no,
    di.shipping_no,
    hi.invoice_date,
    hi.customer_name,
    MAX(COALESCE(di.total,0)) AS total_invoice_shipping,
    MAX(COALESCE(di.subtotal,0)) AS penjualan_shipping,

    CASE
        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
         AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%WARNA%'
            THEN 'PE WARNA'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%KERTAS%'
            THEN 'KERTAS'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])PP([^A-Z]|$)'
         AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%SABLON%'
            THEN 'PP SABLON'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])PP([^A-Z]|$)'
            THEN 'PP'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
         AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%WARNA%'
            THEN 'HD WARNA'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
         AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%KRESEK%'
            THEN 'HD KRESEK'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
         AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%SABLON%'
            THEN 'HD SABLON'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%TALI%'
         AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])KG([^A-Z]|$)'
            THEN 'TALI KG'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%TALI%'
         AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%LOS%'
            THEN 'TALI LOS'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])BAHAN([^A-Z]|$)'
            THEN 'BAHAN'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%SEDOTAN%'
            THEN 'SEDOTAN'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) LIKE '%TERPAL%'
            THEN 'TERPAL'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])BOX([^A-Z]|$)'
            THEN 'BOX'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
            THEN 'HD'

        WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name),''),NULLIF(TRIM(mi.inventory_name),''),'')) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
            THEN 'PE'

        ELSE 'LAIN LAIN'
    END AS category_group,

    SUM(
        CASE
            WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,'')))<>''
             AND UPPER(TRIM(COALESCE(ds.uom_pack_shipping,'')))<>'KG'
                THEN COALESCE(ds.qty_pack_shipping,0)
            WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,'')))<>''
             AND UPPER(TRIM(COALESCE(ds.uom_detail_shipping,'')))<>'KG'
                THEN COALESCE(ds.qty_detail_shipping,0)
            ELSE COALESCE(ds.qty_shipping,0)
        END
    ) AS category_qty,

    SUM(
        CASE
            WHEN UPPER(TRIM(COALESCE(ds.uom_shipping,'')))='KG'
                THEN COALESCE(ds.qty_shipping,0)
            WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,'')))='KG'
                THEN COALESCE(ds.qty_pack_shipping,0)
            WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,'')))='KG'
                THEN COALESCE(ds.qty_detail_shipping,0)
            ELSE 0
        END
    ) AS category_qty_kg,

    SUM(
        CASE
            WHEN COALESCE(ds.subtotal,0)<>0 THEN COALESCE(ds.subtotal,0)
            ELSE COALESCE(dso.price,0) *
                CASE
                    WHEN COALESCE(ds.qty_pack_shipping,0)>0
                        THEN COALESCE(ds.qty_pack_shipping,0)
                    ELSE COALESCE(ds.qty_shipping,0)
                END
        END
    ) AS category_rp

FROM det_invoice di
INNER JOIN head_invoice hi ON hi.invoice_no=di.invoice_no
INNER JOIN hed_shipping hs ON hs.shipping_no=di.shipping_no
INNER JOIN det_shipping ds ON ds.shipping_no=di.shipping_no
LEFT JOIN m_inventory mi ON mi.inventory_id=ds.inventory_id
LEFT JOIN m_category mc ON TRIM(mc.categori_id)=TRIM(mi.category)
LEFT JOIN detail_sales_order dso
    ON dso.id=(
        SELECT MIN(dso2.id)
        FROM detail_sales_order dso2
        WHERE dso2.order_no=hs.order_no
          AND dso2.inventory_id=ds.inventory_id
    )

WHERE hi.invoice_date BETWEEN ? AND ?

  /*
   * Transaksi CP-MCP adalah transaksi khusus return.
   * Return sudah dilaporkan terpisah di Register Penjualan Global Return,
   * sehingga tidak boleh masuk kembali ke Register Penjualan Dinamis.
   *
   * Jangan mengecualikan invoice normal hanya karena pernah diretur,
   * karena invoice tersebut tetap merupakan penjualan asli.
   */
  AND UPPER(COALESCE(di.invoice_no,'')) NOT LIKE '%CP-MCP%'
  AND UPPER(COALESCE(di.shipping_no,'')) NOT LIKE '%CP-MCP%'
  AND UPPER(COALESCE(hs.order_no,'')) NOT LIKE '%CP-MCP%'

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

$stmt=mysqli_prepare($conn,$sql);
if(!$stmt) die('SQL Cetak Register Penjualan Dinamis Error: '.h(mysqli_error($conn)));
mysqli_stmt_bind_param($stmt,'ss',$startDate,$endDate);
mysqli_stmt_execute($stmt);
$res=mysqli_stmt_get_result($stmt);

/*
|--------------------------------------------------------------------------
| BUILD DATA DINAMIS
|--------------------------------------------------------------------------
*/
$rows=[];
$grand=['total'=>0.0,'penjualan'=>0.0];
foreach($selectedCats as $cat) $grand[$cat]=emptyCatR();

while($item=mysqli_fetch_assoc($res)){
    $g=(string)$item['category_group'];

    // Hanya kategori yang dicentang user yang ditampilkan/dihitung.
    if(!in_array($g,$selectedCats,true)) continue;

    $key=$item['invoice_no'].'|'.$item['shipping_no'];

    if(!isset($rows[$key])){
        $rows[$key]=[
            'shipping_no'=>$item['shipping_no'],
            'invoice_no'=>$item['invoice_no'],
            'customer_name'=>$item['customer_name'],
            'total'=>(float)$item['total_invoice_shipping'],
            'penjualan'=>(float)$item['penjualan_shipping'],
        ];

        foreach($selectedCats as $cat) $rows[$key][$cat]=emptyCatR();

        $grand['total']+=(float)$item['total_invoice_shipping'];
        $grand['penjualan']+=(float)$item['penjualan_shipping'];
    }

    $rows[$key][$g]['qty']+=(float)$item['category_qty'];
    $rows[$key][$g]['qty_kg']+=(float)$item['category_qty_kg'];
    $rows[$key][$g]['rp']+=(float)$item['category_rp'];

    $grand[$g]['qty']+=(float)$item['category_qty'];
    $grand[$g]['qty_kg']+=(float)$item['category_qty_kg'];
    $grand[$g]['rp']+=(float)$item['category_rp'];
}
mysqli_stmt_close($stmt);


$totalColumns = 5 + (count($selectedCats) * 3);
$catCount = count($selectedCats);

// Ukuran cetak menyesuaikan jumlah kategori.
if ($catCount <= 3) {
    $printFont = 8.0;
    $printPad = 3.0;
} elseif ($catCount <= 5) {
    $printFont = 6.8;
    $printPad = 2.4;
} elseif ($catCount <= 8) {
    $printFont = 5.6;
    $printPad = 1.8;
} elseif ($catCount <= 11) {
    $printFont = 4.8;
    $printPad = 1.3;
} else {
    $printFont = 4.1;
    $printPad = 1.0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Register Penjualan Dinamis</title>
<style>
@page{size:A4 landscape;margin:6mm 4mm}
*{box-sizing:border-box}
body{margin:0;padding:14px 18px;background:#f3f5f7;color:#000;font-family:Arial,Helvetica,sans-serif}
.print-actions{text-align:right;margin-bottom:8px}
.print-btn{border:0;border-radius:3px;background:#198754;color:#fff;padding:6px 12px;font-size:11px;font-weight:bold;cursor:pointer}
.print-card{width:98%;margin:0 auto;padding:16px 18px;background:#fff;border:1px solid #d9dee5;border-radius:6px;box-shadow:0 2px 10px rgba(0,0,0,.06);overflow-x:auto}
.print-header{text-align:center;margin-bottom:8px}
.print-header h2{margin:0 0 4px;font-size:18px;text-transform:uppercase}
.print-header .period{font-size:11px;font-weight:bold}
.print-header .categories{margin-top:3px;font-size:9px;color:#444}
.report-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:<?=h($printFont)?>px}
.report-table th,.report-table td{border:.5px solid #555;padding:<?=h($printPad)?>px 1.5px;vertical-align:middle;line-height:1.15}
.report-table th{background:#ececec;font-weight:bold;text-align:center;white-space:nowrap}
.report-table td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.customer-cell{white-space:normal!important;overflow-wrap:anywhere;line-height:1.1}
.qty-cell,.money-cell{text-align:right;font-variant-numeric:tabular-nums}
tfoot td{font-weight:bold;background:#f2f2f2}
.no-data{text-align:center;padding:10px!important}
.base-shipping{width:7%}.base-invoice{width:8%}.base-customer{width:12%}.base-total{width:7%}.base-sales{width:7%}
@media print{
    .print-actions{display:none!important}
    body{padding:0!important;background:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    .print-card{width:100%!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;box-shadow:none!important;overflow:visible!important}
    .print-header h2{font-size:13px!important}
    .print-header .period{font-size:8px!important}
    .print-header .categories{font-size:7px!important}
    thead{display:table-header-group}
    tfoot{display:table-row-group}
    tr{page-break-inside:avoid}
}
</style>
</head>
<body>
<div class="print-actions">
    <button type="button" class="print-btn" onclick="window.print()">Print</button>
</div>

<div class="print-card">
    <div class="print-header">
        <h2>Register Penjualan Dinamis</h2>
        <div class="period">Periode <?=h(fmtDateR($startDate))?> s/d <?=h(fmtDateR($endDate))?></div>
        <div class="categories">Kategori: <?=h($selectedCats ? implode(' | ',$selectedCats) : '-')?></div>
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th rowspan="2" class="base-shipping">SHIPPING NO.</th>
                <th rowspan="2" class="base-invoice">INVOICE NO.</th>
                <th rowspan="2" class="base-customer">NAMA CUST.</th>
                <th rowspan="2" class="base-total">TOTAL</th>
                <th rowspan="2" class="base-sales">PENJUALAN</th>
                <?php foreach($selectedCats as $cat): ?>
                    <th colspan="3"><?=h($cat)?></th>
                <?php endforeach; ?>
            </tr>
            <tr>
                <?php foreach($selectedCats as $cat): ?>
                    <th>Qty</th><th>Qty KG</th><th>Rp</th>
                <?php endforeach; ?>
            </tr>
        </thead>

        <tbody>
        <?php if(empty($selectedCats)): ?>
            <tr><td colspan="5" class="no-data">Tidak ada kategori yang dipilih.</td></tr>
        <?php elseif(empty($rows)): ?>
            <tr><td colspan="<?=h($totalColumns)?>" class="no-data">Tidak ada data pada periode dan kategori yang dipilih.</td></tr>
        <?php else: foreach($rows as $row): ?>
            <tr>
                <td><?=h($row['shipping_no'])?></td>
                <td><?=h($row['invoice_no'])?></td>
                <td class="customer-cell"><?=h($row['customer_name'])?></td>
                <td class="money-cell"><?=h(fmtMoneyR($row['total']))?></td>
                <td class="money-cell"><?=h(fmtMoneyR($row['penjualan']))?></td>
                <?php foreach($selectedCats as $cat): ?>
                    <td class="qty-cell"><?=h(fmtQtyR($row[$cat]['qty']))?></td>
                    <td class="qty-cell"><?=h(fmtQtyR($row[$cat]['qty_kg']))?></td>
                    <td class="money-cell"><?=h(fmtMoneyR($row[$cat]['rp']))?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>

        <?php if(!empty($selectedCats)): ?>
        <tfoot>
            <tr>
                <td colspan="3" style="text-align:right">TOTAL</td>
                <td class="money-cell"><?=h(fmtMoneyR($grand['total']))?></td>
                <td class="money-cell"><?=h(fmtMoneyR($grand['penjualan']))?></td>
                <?php foreach($selectedCats as $cat): ?>
                    <td class="qty-cell"><?=h(fmtQtyR($grand[$cat]['qty']))?></td>
                    <td class="qty-cell"><?=h(fmtQtyR($grand[$cat]['qty_kg']))?></td>
                    <td class="money-cell"><?=h(fmtMoneyR($grand[$cat]['rp']))?></td>
                <?php endforeach; ?>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<script>
window.addEventListener('load',function(){ window.print(); });
</script>
</body>
</html>
