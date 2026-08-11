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
function fmtMoneyR($v){ return number_format((float)$v,2,',','.'); }
function fmtQtyR($v){
    $s=number_format((float)$v,2,',','.');
    return rtrim(rtrim($s,'0'),',');
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

// Sorting hanya untuk Shipping No. dan Invoice No.
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

function sortUrlR($column, $currentSort, $currentDir, $startDate, $endDate) {
    $nextDir = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
    return 'index.php?' . http_build_query([
        'page' => 'register_penjualan_global',
        'start_date' => fmtDateR($startDate),
        'end_date' => fmtDateR($endDate),
        'sort' => $column,
        'dir' => $nextDir,
    ]);
}

function sortIconR($column, $currentSort, $currentDir) {
    if ($currentSort !== $column) return '↕';
    return $currentDir === 'asc' ? '▲' : '▼';
}

$sql = "
SELECT
    di.invoice_no,
    di.shipping_no,
    hi.invoice_date,
    hi.customer_name,
    MAX(COALESCE(di.total,0)) AS total_invoice_shipping,
    MAX(COALESCE(di.subtotal,0)) AS penjualan_shipping,
  CASE
    /*
     * PE WARNA harus diperiksa sebelum PE.
     */
    WHEN UPPER(
        COALESCE(
            NULLIF(TRIM(ds.inventory_name), ''),
            NULLIF(TRIM(mi.inventory_name), ''),
            ''
        )
    ) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
    AND UPPER(
        COALESCE(
            NULLIF(TRIM(ds.inventory_name), ''),
            NULLIF(TRIM(mi.inventory_name), ''),
            ''
        )
    ) LIKE '%WARNA%'
        THEN 'PE WARNA'

    /*
     * Inventory yang mengandung kata KERTAS.
     */
    WHEN UPPER(
        COALESCE(
            NULLIF(TRIM(ds.inventory_name), ''),
            NULLIF(TRIM(mi.inventory_name), ''),
            ''
        )
    ) LIKE '%KERTAS%'
        THEN 'KERTAS'

    /*
     * Inventory yang memiliki kata PP.
     */
    WHEN UPPER(
        COALESCE(
            NULLIF(TRIM(ds.inventory_name), ''),
            NULLIF(TRIM(mi.inventory_name), ''),
            ''
        )
    ) REGEXP '(^|[^A-Z])PP([^A-Z]|$)'
        THEN 'PP'

    /*
     * Inventory yang memiliki kata PE.
     */
    WHEN UPPER(
        COALESCE(
            NULLIF(TRIM(ds.inventory_name), ''),
            NULLIF(TRIM(mi.inventory_name), ''),
            ''
        )
    ) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
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
LEFT JOIN m_category mc
    ON TRIM(mc.categori_id) = TRIM(mi.category)
LEFT JOIN detail_sales_order dso
    ON dso.id=(
        SELECT MIN(dso2.id)
        FROM detail_sales_order dso2
        WHERE dso2.order_no=hs.order_no
          AND dso2.inventory_id=ds.inventory_id
    )
WHERE hi.invoice_date BETWEEN ? AND ?
GROUP BY
    di.invoice_no,di.shipping_no,hi.invoice_date,hi.customer_name,category_group
ORDER BY
    {$orderColumn} {$orderDirection},
    hi.invoice_date ASC,
    di.shipping_no ASC,
    di.invoice_no ASC,
    category_group ASC
";

$stmt=mysqli_prepare($conn,$sql);
if(!$stmt) die('SQL Register Penjualan Global Error: '.h(mysqli_error($conn)));
mysqli_stmt_bind_param($stmt,'ss',$startDate,$endDate);
mysqli_stmt_execute($stmt);
$res=mysqli_stmt_get_result($stmt);

$rows=[];
$grand=emptyGrandR();

while($item=mysqli_fetch_assoc($res)){
    $key=$item['invoice_no'].'|'.$item['shipping_no'];

    if(!isset($rows[$key])){
        $rows[$key]=[
            'shipping_no'=>$item['shipping_no'],
            'invoice_no'=>$item['invoice_no'],
            'customer_name'=>$item['customer_name'],
            'total'=>(float)$item['total_invoice_shipping'],
            'penjualan'=>(float)$item['penjualan_shipping'],
            'PP'=>emptyCatR(),'KERTAS'=>emptyCatR(),'PE'=>emptyCatR(),
            'PE WARNA'=>emptyCatR(),'LAIN LAIN'=>emptyCatR()
        ];
        $grand['total']+=(float)$item['total_invoice_shipping'];
        $grand['penjualan']+=(float)$item['penjualan_shipping'];
    }

    $g=$item['category_group'];
    if(!isset($rows[$key][$g])) $g='LAIN LAIN';

    $rows[$key][$g]['qty']+=(float)$item['category_qty'];
    $rows[$key][$g]['qty_kg']+=(float)$item['category_qty_kg'];
    $rows[$key][$g]['rp']+=(float)$item['category_rp'];

    $grand[$g]['qty']+=(float)$item['category_qty'];
    $grand[$g]['qty_kg']+=(float)$item['category_qty_kg'];
    $grand[$g]['rp']+=(float)$item['category_rp'];
}
mysqli_stmt_close($stmt);
$cats=['PP','KERTAS','PE','PE WARNA','LAIN LAIN'];
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<style>
.rpg-wrap *{box-sizing:border-box;font-family:'Segoe UI',Tahoma,Arial,sans-serif}
.rpg-wrap{padding:12px;background:#f0f2f5;color:#212529;font-size:11px}
.rpg-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;padding:11px 15px;border-radius:5px;color:#fff;background:linear-gradient(135deg,#1e3c72,#2a5298)}
.rpg-head h5{margin:0;font-size:15px}
.filter-card{margin-bottom:10px;padding:10px;border:1px solid #dee2e6;border-radius:5px;background:#fff}
.filter-grid{display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end}
.ff label{display:block;margin-bottom:3px;color:#0d6efd;font-size:10px;font-weight:700;text-transform:uppercase}
.ff input{width:100%;padding:6px 8px;border:1px solid #ced4da;border-radius:3px;font-size:11px}
.btn-vs{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:6px 12px;border:0;border-radius:3px;color:#fff;font-size:11px;font-weight:bold;text-decoration:none;cursor:pointer}
.btn-dark{background:#212529}.btn-secondary{background:#6c757d}.btn-success{background:#198754}
.table-wrap{max-height:610px;overflow:auto;border:1px solid #bac7d5;background:#fff}
.report-table{width:100%;min-width:1850px;border-collapse:collapse;font-size:9px}
.report-table th{position:sticky;z-index:3;padding:5px 3px;border:1px solid #9faebd;background:#e9ecef;color:#253c5c;text-align:center;white-space:nowrap}
.report-table thead tr:first-child th{top:0}
.report-table thead tr:nth-child(2) th{top:27px}
.report-table td{padding:4px 3px;border:1px solid #d3d3d3;white-space:nowrap}
.report-table tbody tr:hover td{background:#e8f2fe}
.report-table tfoot td{position:sticky;bottom:0;z-index:2;padding:5px 3px;border:1px solid #9faebd;background:#f2f2f2;font-weight:bold}
.money-cell,.qty-cell{text-align:right;font-variant-numeric:tabular-nums}
.text-center{text-align:center}.customer-cell{max-width:230px;overflow:hidden;text-overflow:ellipsis}
.sort-link{display:inline-flex;align-items:center;gap:4px;color:#253c5c;text-decoration:none;font-weight:700}
.sort-link:hover{color:#0d6efd;text-decoration:none}
.sort-icon{font-size:9px;line-height:1}
@media(max-width:800px){.filter-grid{grid-template-columns:1fr}.rpg-head{align-items:flex-start;flex-direction:column}}
</style>
<div class="rpg-wrap">
    <div class="rpg-head">
        <h5>Register Penjualan Global</h5>
        <a class="btn-vs btn-success"
           href="modul/transaksi/cetak_register_penjualan_global.php?start_date=<?=urlencode(fmtDateR($startDate))?>&end_date=<?=urlencode(fmtDateR($endDate))?>&sort=<?=urlencode($sort)?>&dir=<?=urlencode($dir)?>"
           target="_blank">Cetak Register</a>
    </div>

    <div class="filter-card">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="register_penjualan_global">
            <input type="hidden" name="sort" value="<?=h($sort)?>">
            <input type="hidden" name="dir" value="<?=h($dir)?>">
            <div class="filter-grid">
                <div class="ff">
                    <label>Start Date</label>
                    <input type="text" name="start_date" class="date-picker" value="<?=h(fmtDateR($startDate))?>" autocomplete="off">
                </div>
                <div class="ff">
                    <label>End Date</label>
                    <input type="text" name="end_date" class="date-picker" value="<?=h(fmtDateR($endDate))?>" autocomplete="off">
                </div>
                <div style="display:flex;gap:6px">
                    <button type="submit" class="btn-vs btn-dark">Search</button>
                    <a href="index.php?page=register_penjualan_global" class="btn-vs btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th rowspan="2">
                        <a class="sort-link" href="<?=h(sortUrlR('shipping_no',$sort,$dir,$startDate,$endDate))?>" title="Urutkan Shipping No.">
                            SHIPPING NO. <span class="sort-icon"><?=h(sortIconR('shipping_no',$sort,$dir))?></span>
                        </a>
                    </th>
                    <th rowspan="2">
                        <a class="sort-link" href="<?=h(sortUrlR('invoice_no',$sort,$dir,$startDate,$endDate))?>" title="Urutkan Invoice No.">
                            INVOICE NO. <span class="sort-icon"><?=h(sortIconR('invoice_no',$sort,$dir))?></span>
                        </a>
                    </th>
                    <th rowspan="2">NAMA CUST.</th>
                    <th rowspan="2">TOTAL</th>
                    <th rowspan="2">PENJUALAN</th>
                    <?php foreach($cats as $cat): ?>
                        <th colspan="3"><?=h($cat)?></th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php foreach($cats as $cat): ?>
                        <th>Qty</th><th>Qty KG</th><th>Rp</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="20" class="text-center" style="padding:18px;color:#777">Tidak ada data invoice pada periode ini.</td></tr>
            <?php else: foreach($rows as $row): ?>
                <tr>
                    <td><?=h($row['shipping_no'])?></td>
                    <td><?=h($row['invoice_no'])?></td>
                    <td class="customer-cell" title="<?=h($row['customer_name'])?>"><?=h($row['customer_name'])?></td>
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
            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right">TOTAL</td>
                    <td class="money-cell"><?=h(fmtMoneyR($grand['total']))?></td>
                    <td class="money-cell"><?=h(fmtMoneyR($grand['penjualan']))?></td>
                    <?php foreach($cats as $cat): ?>
                        <td class="qty-cell"><?=h(fmtQtyR($grand[$cat]['qty']))?></td>
                        <td class="qty-cell"><?=h(fmtQtyR($grand[$cat]['qty_kg']))?></td>
                        <td class="money-cell"><?=h(fmtMoneyR($grand[$cat]['rp']))?></td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<script>
if(typeof flatpickr!=='undefined'){
    flatpickr('.date-picker',{dateFormat:'d-M-Y',allowInput:true,disableMobile:true});
}
</script>