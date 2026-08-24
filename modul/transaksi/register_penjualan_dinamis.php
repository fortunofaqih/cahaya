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
    'TALI KG','TALI LOS','BAHAN','TERPAL','BOX'
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
if(!$stmt) die('SQL Register Penjualan Dinamis Error: '.h(mysqli_error($conn)));
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
$minWidth = max(900, 500 + (count($selectedCats) * 270));
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>
.rpd-wrap *{box-sizing:border-box;font-family:'Segoe UI',Tahoma,Arial,sans-serif}
.rpd-wrap{padding:12px;background:#f0f2f5;color:#212529;font-size:11px}
.rpd-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;padding:11px 15px;border-radius:5px;color:#fff;background:linear-gradient(135deg,#1e3c72,#2a5298)}
.rpd-head h5{margin:0;font-size:15px}
.filter-card{margin-bottom:10px;padding:10px;border:1px solid #dee2e6;border-radius:5px;background:#fff}
.filter-grid{display:grid;grid-template-columns:180px 180px 1fr auto;gap:8px;align-items:end}
.ff label,.category-label{display:block;margin-bottom:3px;color:#0d6efd;font-size:10px;font-weight:700;text-transform:uppercase}
.ff input{width:100%;padding:6px 8px;border:1px solid #ced4da;border-radius:3px;font-size:11px}
.category-box{border:1px solid #ced4da;border-radius:4px;background:#fff;padding:7px 8px}
.category-actions{display:flex;gap:5px;margin-bottom:6px}
.category-grid{display:flex;flex-wrap:wrap;gap:5px 12px;align-items:center}
.category-item{display:inline-flex;align-items:center;gap:4px;white-space:nowrap;font-size:10.5px;color:#333}
.category-item input{margin:0;width:14px;height:14px}
.btn-vs{display:inline-flex;align-items:center;justify-content:center;min-height:30px;padding:6px 12px;border:0;border-radius:3px;color:#fff;font-size:11px;font-weight:bold;text-decoration:none;cursor:pointer}
.btn-mini{min-height:23px;padding:3px 7px;font-size:9px}
.btn-dark{background:#212529}.btn-secondary{background:#6c757d}.btn-primary{background:#0d6efd}
.table-wrap{max-height:610px;overflow:auto;border:1px solid #bac7d5;background:#fff}
.report-table{width:100%;min-width:<?= (int)$minWidth ?>px;border-collapse:collapse;font-size:9px}
.report-table th{position:sticky;z-index:3;padding:5px 3px;border:1px solid #9faebd;background:#e9ecef;color:#253c5c;text-align:center;white-space:nowrap}
.report-table thead tr:first-child th{top:0}
.report-table thead tr:nth-child(2) th{top:27px}
.report-table td{padding:4px 3px;border:1px solid #d3d3d3;white-space:nowrap}
.report-table tbody tr:hover td{background:#e8f2fe}
.report-table tfoot td{position:sticky;bottom:0;z-index:2;padding:5px 3px;border:1px solid #9faebd;background:#f2f2f2;font-weight:bold}
.money-cell,.qty-cell{text-align:right;font-variant-numeric:tabular-nums}
.text-center{text-align:center}.customer-cell{max-width:230px;overflow:hidden;text-overflow:ellipsis}
.sort-link{display:inline-flex;align-items:center;gap:4px;color:#253c5c;text-decoration:none;font-weight:700}
.sort-link:hover{color:#0d6efd;text-decoration:none}.sort-icon{font-size:9px;line-height:1}
.info-box{padding:8px 10px;margin-bottom:10px;border:1px solid #b6d4fe;background:#e7f1ff;color:#084298;border-radius:4px;font-size:10.5px}
@media(max-width:1100px){.filter-grid{grid-template-columns:1fr 1fr}.category-field,.filter-actions{grid-column:1/-1}}
@media(max-width:700px){.filter-grid{grid-template-columns:1fr}.category-field,.filter-actions{grid-column:auto}.rpd-head{align-items:flex-start;flex-direction:column}}
</style>

<?php
$printQuery = http_build_query([
    'start_date' => fmtDateR($startDate),
    'end_date' => fmtDateR($endDate),
    'sort' => $sort,
    'dir' => $dir,
    'kategori_submitted' => 1,
    'kategori' => $selectedCats
]);
?>

<div class="rpd-head">
    <h5>Register Penjualan Dinamis</h5>

    <a class="btn-vs btn-primary"
       href="modul/transaksi/cetak_register_penjualan_dinamis.php?<?=h($printQuery)?>"
       target="_blank">
        Cetak Register
    </a>
</div>

    <div class="filter-card">
        <form method="GET" action="index.php" id="formRegisterDinamis">
            <input type="hidden" name="page" value="register_penjualan_dinamis">
            <input type="hidden" name="sort" value="<?=h($sort)?>">
            <input type="hidden" name="dir" value="<?=h($dir)?>">
            <input type="hidden" name="kategori_submitted" value="1">

            <div class="filter-grid">
                <div class="ff">
                    <label>Start Date</label>
                    <input type="text" name="start_date" class="date-picker" value="<?=h(fmtDateR($startDate))?>" autocomplete="off">
                </div>

                <div class="ff">
                    <label>End Date</label>
                    <input type="text" name="end_date" class="date-picker" value="<?=h(fmtDateR($endDate))?>" autocomplete="off">
                </div>

                <div class="category-field">
                    <span class="category-label">Kategori Yang Ditampilkan</span>
                    <div class="category-box">
                        <div class="category-actions">
                            <button type="button" class="btn-vs btn-mini btn-primary" id="checkAllCat">Pilih Semua</button>
                            <button type="button" class="btn-vs btn-mini btn-secondary" id="clearAllCat">Kosongkan</button>
                        </div>
                        <div class="category-grid">
                            <?php foreach($allCats as $cat): ?>
                                <label class="category-item">
                                    <input type="checkbox" name="kategori[]" value="<?=h($cat)?>" <?=in_array($cat,$selectedCats,true)?'checked':''?>>
                                    <span><?=h($cat)?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="filter-actions" style="display:flex;gap:6px">
                    <button type="submit" class="btn-vs btn-dark">Search</button>
                    <a href="index.php?page=register_penjualan_dinamis" class="btn-vs btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <?php if(empty($selectedCats)): ?>
        <div class="info-box">Pilih minimal satu kategori untuk menampilkan register.</div>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="report-table">
            <thead>
                <tr>
                    <th rowspan="2">
                        <a class="sort-link" href="<?=h(sortUrlDinamis('shipping_no',$sort,$dir,$startDate,$endDate,$selectedCats))?>" title="Urutkan Shipping No.">
                            SHIPPING NO. <span class="sort-icon"><?=h(sortIconR('shipping_no',$sort,$dir))?></span>
                        </a>
                    </th>
                    <th rowspan="2">
                        <a class="sort-link" href="<?=h(sortUrlDinamis('invoice_no',$sort,$dir,$startDate,$endDate,$selectedCats))?>" title="Urutkan Invoice No.">
                            INVOICE NO. <span class="sort-icon"><?=h(sortIconR('invoice_no',$sort,$dir))?></span>
                        </a>
                    </th>
                    <th rowspan="2">NAMA CUST.</th>
                    <th rowspan="2">TOTAL</th>
                    <th rowspan="2">PENJUALAN</th>

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
                <tr><td colspan="5" class="text-center" style="padding:18px;color:#777">Belum ada kategori yang dipilih.</td></tr>
            <?php elseif(empty($rows)): ?>
                <tr><td colspan="<?=h($totalColumns)?>" class="text-center" style="padding:18px;color:#777">Tidak ada data pada periode dan kategori yang dipilih.</td></tr>
            <?php else: foreach($rows as $row): ?>
                <tr>
                    <td><?=h($row['shipping_no'])?></td>
                    <td><?=h($row['invoice_no'])?></td>
                    <td class="customer-cell" title="<?=h($row['customer_name'])?>"><?=h($row['customer_name'])?></td>
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
</div>

<script>
if(typeof flatpickr!=='undefined'){
    flatpickr('.date-picker',{dateFormat:'d-M-Y',allowInput:true,disableMobile:true});
}

document.getElementById('checkAllCat')?.addEventListener('click',function(){
    document.querySelectorAll('input[name="kategori[]"]').forEach(function(cb){ cb.checked=true; });
});

document.getElementById('clearAllCat')?.addEventListener('click',function(){
    document.querySelectorAll('input[name="kategori[]"]').forEach(function(cb){ cb.checked=false; });
});

document.getElementById('formRegisterDinamis')?.addEventListener('submit',function(e){
    const checked=document.querySelectorAll('input[name="kategori[]"]:checked').length;
    if(checked<1){
        alert('Pilih minimal 1 kategori yang ingin ditampilkan.');
        e.preventDefault();
    }
});
</script>
