<?php
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
    $t=strtotime($d); return $t?date('d-M-Y',$t):'';
}
function fmtMoneyR($v){
    // Buang pecahan desimal tanpa pembulatan.
    // Contoh: 400.412.886,50 -> 400.412.886,00
    $v=(float)$v;
    $whole=$v<0 ? ceil($v) : floor($v);
    return number_format($whole,2,',','.');
}
function fmtQtyR($v){
    $s=number_format((float)$v,2,',','.');
    return rtrim(rtrim($s,'0'),',');
}

function classifyCategoryR($inventoryName){
    $n = strtoupper(trim((string)$inventoryName));
    if($n==='') return 'LAIN LAIN';

    if(preg_match('/(^|[^A-Z])PE([^A-Z]|$)/',$n) && strpos($n,'WARNA')!==false) return 'PE WARNA';
    if(strpos($n,'KERTAS')!==false) return 'KERTAS';
    if(preg_match('/(^|[^A-Z])PP([^A-Z]|$)/',$n)) return 'PP';
    if(preg_match('/(^|[^A-Z])PE([^A-Z]|$)/',$n)) return 'PE';

    return 'LAIN LAIN';
}
function emptyCatR(){ return ['qty'=>0.0,'qty_kg'=>0.0,'rp'=>0.0]; }
function emptyGrandR(){
    return [
        'total'=>0.0,'penjualan'=>0.0,
        'PP'=>emptyCatR(),'KERTAS'=>emptyCatR(),'PE'=>emptyCatR(),
        'PE WARNA'=>emptyCatR(),'LAIN LAIN'=>emptyCatR()
    ];
}

$today=date('Y-m-d');
$startDate=parseDateR($_GET['start_date']??'',date('Y-m-01'));
$endDate=parseDateR($_GET['end_date']??'',$today);
if(strtotime($startDate)>strtotime($endDate)) [$startDate,$endDate]=[$endDate,$startDate];

// Sorting mengikuti halaman Register Penjualan Global.
$sort = strtolower(trim((string)($_GET['sort'] ?? 'shipping_no')));
$dir  = strtolower(trim((string)($_GET['dir'] ?? 'asc')));

$allowedSort = ['shipping_no', 'invoice_no'];
$allowedDir  = ['asc', 'desc'];

if (!in_array($sort, $allowedSort, true)) {
    $sort = 'shipping_no';
}
if (!in_array($dir, $allowedDir, true)) {
    $dir = 'asc';
}

$orderColumn = $sort === 'invoice_no' ? 'di.invoice_no' : 'di.shipping_no';
$orderDirection = strtoupper($dir);

/*
 * Register Penjualan Global berbasis INVOICE:
 * - TOTAL       = det_invoice.total
 * - PENJUALAN   = det_invoice.subtotal
 * - Qty/Qty KG  = det_shipping (untuk detail barang)
 * - Rp kategori = dialokasikan dari det_invoice.subtotal
 *
 * Jika satu shipping hanya berisi satu kategori, seluruh subtotal invoice
 * masuk ke kategori tersebut. Jika suatu saat ada multi kategori dalam satu
 * shipping, subtotal invoice dibagi proporsional berdasarkan nilai referensi
 * detail barang, sehingga total seluruh kategori tetap sama dengan subtotal invoice.
 */
$sql = "
SELECT
    di.invoice_no,
    di.shipping_no,
    hi.invoice_date,
    hi.customer_name,
    COALESCE(di.total,0) AS total_invoice_shipping,
    COALESCE(di.subtotal,0) AS penjualan_shipping,
    ds.id AS shipping_detail_id,
    COALESCE(
        NULLIF(TRIM(ds.inventory_name),''),
        NULLIF(TRIM(mi.inventory_name),''),
        ''
    ) AS inventory_name,

    CASE
        WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,'')))<>'' 
         AND UPPER(TRIM(COALESCE(ds.uom_pack_shipping,'')))<>'KG'
            THEN COALESCE(ds.qty_pack_shipping,0)
        WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,'')))<>'' 
         AND UPPER(TRIM(COALESCE(ds.uom_detail_shipping,'')))<>'KG'
            THEN COALESCE(ds.qty_detail_shipping,0)
        ELSE COALESCE(ds.qty_shipping,0)
    END AS category_qty,

    CASE
        WHEN UPPER(TRIM(COALESCE(ds.uom_shipping,'')))='KG'
            THEN COALESCE(ds.qty_shipping,0)
        WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,'')))='KG'
            THEN COALESCE(ds.qty_pack_shipping,0)
        WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,'')))='KG'
            THEN COALESCE(ds.qty_detail_shipping,0)
        ELSE 0
    END AS category_qty_kg,

    CASE
        WHEN COALESCE(ds.subtotal,0)<>0
            THEN ABS(COALESCE(ds.subtotal,0))
        WHEN COALESCE(dso.price,0)<>0
            THEN ABS(
                COALESCE(dso.price,0) *
                CASE
                    WHEN COALESCE(ds.qty_pack_shipping,0)>0
                        THEN COALESCE(ds.qty_pack_shipping,0)
                    ELSE COALESCE(ds.qty_shipping,0)
                END
            )
        ELSE 0
    END AS allocation_basis

FROM det_invoice di
INNER JOIN head_invoice hi
    ON TRIM(hi.invoice_no)=TRIM(di.invoice_no)
INNER JOIN hed_shipping hs
    ON TRIM(hs.shipping_no)=TRIM(di.shipping_no)
INNER JOIN det_shipping ds
    ON TRIM(ds.shipping_no)=TRIM(di.shipping_no)
LEFT JOIN m_inventory mi
    ON TRIM(mi.inventory_id)=TRIM(ds.inventory_id)
LEFT JOIN detail_sales_order dso
    ON dso.id=(
        SELECT MIN(dso2.id)
        FROM detail_sales_order dso2
        WHERE TRIM(dso2.order_no)=TRIM(hs.order_no)
          AND TRIM(dso2.inventory_id)=TRIM(ds.inventory_id)
    )

WHERE hi.invoice_date BETWEEN ? AND ?
  AND UPPER(COALESCE(di.invoice_no,'')) NOT LIKE '%CP-MCP%'
  AND UPPER(COALESCE(di.shipping_no,'')) NOT LIKE '%CP-MCP%'
  AND UPPER(COALESCE(hs.order_no,'')) NOT LIKE '%CP-MCP%'

ORDER BY
    {$orderColumn} {$orderDirection},
    hi.invoice_date ASC,
    di.shipping_no ASC,
    di.invoice_no ASC,
    ds.id ASC
";

$stmt=mysqli_prepare($conn,$sql);
if(!$stmt) die('SQL Register Penjualan Global Error: '.h(mysqli_error($conn)));
mysqli_stmt_bind_param($stmt,'ss',$startDate,$endDate);
mysqli_stmt_execute($stmt);
$res=mysqli_stmt_get_result($stmt);

$rows=[];
$grand=emptyGrandR();
$shippingGroups=[];

while($item=mysqli_fetch_assoc($res)){
    $key=trim((string)$item['invoice_no']).'|'.trim((string)$item['shipping_no']);

    if(!isset($shippingGroups[$key])){
        $shippingGroups[$key]=[
            'shipping_no'=>$item['shipping_no'],
            'invoice_no'=>$item['invoice_no'],
            'customer_name'=>$item['customer_name'],
            'total'=>(float)$item['total_invoice_shipping'],
            'penjualan'=>(float)$item['penjualan_shipping'],
            'items'=>[]
        ];
    }

    $g=classifyCategoryR($item['inventory_name']);

    $shippingGroups[$key]['items'][]=[
        'category'=>$g,
        'qty'=>(float)$item['category_qty'],
        'qty_kg'=>(float)$item['category_qty_kg'],
        'basis'=>(float)$item['allocation_basis']
    ];
}

mysqli_stmt_close($stmt);

foreach($shippingGroups as $key=>$ship){
    $rows[$key]=[
        'shipping_no'=>$ship['shipping_no'],
        'invoice_no'=>$ship['invoice_no'],
        'customer_name'=>$ship['customer_name'],
        'total'=>$ship['total'],
        'penjualan'=>$ship['penjualan'],
        'PP'=>emptyCatR(),'KERTAS'=>emptyCatR(),'PE'=>emptyCatR(),
        'PE WARNA'=>emptyCatR(),'LAIN LAIN'=>emptyCatR()
    ];

    $grand['total'] += $ship['total'];
    $grand['penjualan'] += $ship['penjualan'];

    $basisByCat=[];
    $totalBasis=0.0;

    foreach($ship['items'] as $it){
        $g=$it['category'];
        if(!isset($rows[$key][$g])) $g='LAIN LAIN';

        $rows[$key][$g]['qty'] += $it['qty'];
        $rows[$key][$g]['qty_kg'] += $it['qty_kg'];

        if(!isset($basisByCat[$g])) $basisByCat[$g]=0.0;
        $basisByCat[$g] += abs($it['basis']);
        $totalBasis += abs($it['basis']);
    }

    $catsInShipping=array_keys($basisByCat);

    if(count($catsInShipping)===1){
        $rows[$key][$catsInShipping[0]]['rp'] += $ship['penjualan'];
    } elseif($totalBasis>0){
        $allocated=0.0;
        $last=count($catsInShipping)-1;

        foreach($catsInShipping as $i=>$g){
            if($i===$last){
                $amount=$ship['penjualan']-$allocated;
            }else{
                $amount=$ship['penjualan']*($basisByCat[$g]/$totalBasis);
                $allocated += $amount;
            }
            $rows[$key][$g]['rp'] += $amount;
        }
    } elseif(!empty($catsInShipping)){
        /*
         * Safety fallback: bila satu shipping multi kategori tetapi seluruh
         * harga referensi 0, subtotal invoice tetap tidak boleh hilang.
         */
        $rows[$key][$catsInShipping[0]]['rp'] += $ship['penjualan'];
    }

    foreach(['PP','KERTAS','PE','PE WARNA','LAIN LAIN'] as $g){
        $grand[$g]['qty'] += $rows[$key][$g]['qty'];
        $grand[$g]['qty_kg'] += $rows[$key][$g]['qty_kg'];
        $grand[$g]['rp'] += $rows[$key][$g]['rp'];
    }
}
$cats=['PP','KERTAS','PE','PE WARNA','LAIN LAIN'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Register Penjualan Global</title>
<style>
@page{size:A3 landscape;margin:6mm}
*{box-sizing:border-box}
body{margin:0;padding:12px;background:#eef1f5;color:#000;font-family:Arial,Helvetica,sans-serif}
.toolbar{display:flex;justify-content:flex-end;gap:8px;max-width:410mm;margin:0 auto 10px}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 18px;border:0;border-radius:5px;color:#fff;font-size:13px;font-weight:bold;text-decoration:none;cursor:pointer}
.btn-back{background:#6c757d}.btn-print{background:#198754}
.screen-scroll{width:100%;overflow-x:auto;padding-bottom:10px}
.print-wrap{width:400mm;min-width:400mm;margin:0 auto;padding:5mm;background:#fff;box-shadow:0 2px 12px rgba(0,0,0,.12)}
.title{text-align:center;font-size:16px;font-weight:bold;margin-bottom:2px}
.period{text-align:center;font-size:12px;font-weight:bold;margin-bottom:5px}
.printed{text-align:right;font-size:8px;margin-bottom:3px}
table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:6.8px}
th,td{border:1px solid #000;padding:2px;vertical-align:middle;white-space:nowrap}
th{text-align:center;font-weight:bold;background:#f1f1f1}
.shipping-col,.invoice-col{width:31mm}.customer-col{width:43mm;overflow:hidden;text-overflow:ellipsis}
.main-money-col{width:28mm}.sub-qty-col{width:14mm}.sub-money-col{width:24mm}
.money-cell,.qty-cell{text-align:right;font-variant-numeric:tabular-nums}
tfoot td{font-weight:bold;background:#f1f1f1}
@media print{
 body{margin:0;padding:0;background:#fff}
 .no-print{display:none!important}
 .screen-scroll{overflow:visible;padding:0}
 .print-wrap{width:100%;min-width:0;margin:0;padding:0;box-shadow:none}
 .title{font-size:11pt}.period{font-size:9pt}
 table{font-size:5.7pt}
 th,td{padding:.7mm .5mm}
 thead{display:table-header-group}tfoot{display:table-footer-group}
 tr{page-break-inside:avoid}
}
</style>
</head>
<body>
<div class="toolbar no-print">
<a href="../../index.php?page=register_penjualan_global&start_date=<?=urlencode(fmtDateR($startDate))?>&end_date=<?=urlencode(fmtDateR($endDate))?>&sort=<?=urlencode($sort)?>&dir=<?=urlencode($dir)?>" class="btn btn-back">Kembali</a>
<button type="button" class="btn btn-print" onclick="window.print()">Cetak</button>
</div>
<div class="screen-scroll"><div class="print-wrap">
<div class="title">Register Penjualan Global</div>
<div class="period">Periode <?=h(fmtDateR($startDate))?> s/d <?=h(fmtDateR($endDate))?></div>
<div class="printed">Dicetak: <?=h(date('d-M-Y H:i:s'))?></div>
<table>
<thead>
<tr>
<th rowspan="2" class="shipping-col">SHIPPING NO.</th>
<th rowspan="2" class="invoice-col">INVOICE NO.</th>
<th rowspan="2" class="customer-col">NAMA CUST.</th>
<th rowspan="2" class="main-money-col">TOTAL</th>
<th rowspan="2" class="main-money-col">PENJUALAN</th>
<?php foreach($cats as $cat): ?><th colspan="3"><?=h($cat)?></th><?php endforeach; ?>
</tr>
<tr>
<?php foreach($cats as $cat): ?>
<th class="sub-qty-col">Qty</th><th class="sub-qty-col">Qty KG</th><th class="sub-money-col">Rp</th>
<?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php if(empty($rows)): ?>
<tr><td colspan="20" style="padding:10px;text-align:center">Tidak ada data invoice pada periode ini.</td></tr>
<?php else: foreach($rows as $row): ?>
<tr>
<td><?=h($row['shipping_no'])?></td>
<td><?=h($row['invoice_no'])?></td>
<td class="customer-col"><?=h($row['customer_name'])?></td>
<td class="money-cell"><?=h(fmtMoneyR($row['total']))?></td>
<td class="money-cell"><?=h(fmtMoneyR($row['penjualan']))?></td>
<?php foreach($cats as $cat): ?>
<td class="qty-cell"><?=h(fmtQtyR($row[$cat]['qty']))?></td>
<td class="qty-cell"><?=h(fmtQtyR($row[$cat]['qty_kg']))?></td>
<td class="money-cell"><?=h(fmtMoneyR($row[$cat]['rp']))?></td>
<?php endforeach; ?>
</tr>
<?php endforeach; endif; ?>
</tbody>
<tfoot><tr>
<td colspan="3" style="text-align:right">TOTAL</td>
<td class="money-cell"><?=h(fmtMoneyR($grand['total']))?></td>
<td class="money-cell"><?=h(fmtMoneyR($grand['penjualan']))?></td>
<?php foreach($cats as $cat): ?>
<td class="qty-cell"><?=h(fmtQtyR($grand[$cat]['qty']))?></td>
<td class="qty-cell"><?=h(fmtQtyR($grand[$cat]['qty_kg']))?></td>
<td class="money-cell"><?=h(fmtMoneyR($grand[$cat]['rp']))?></td>
<?php endforeach; ?>
</tr></tfoot>
</table>
</div></div>
</body>
</html>