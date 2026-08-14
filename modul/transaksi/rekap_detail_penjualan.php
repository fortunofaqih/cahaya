<?php
// modul/transaksi/rekap_detail_penjualan.php

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}
include __DIR__ . '/../../koneksi.php';

function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function esc(mysqli $c,$v){ return mysqli_real_escape_string($c,trim((string)$v)); }
function fmtDate($d){
    if(!$d || $d==='0000-00-00') return '';
    $m=[1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',7=>'Jul',8=>'Ags',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'];
    $t=strtotime($d); return $t?date('d',$t).'-'.$m[(int)date('m',$t)].'-'.date('Y',$t):'';
}
function mysqlDate($d){
    $d=trim((string)$d);
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)) return $d;
    $map=['Jan'=>'01','Feb'=>'02','Mar'=>'03','Apr'=>'04','May'=>'05','Mei'=>'05','Jun'=>'06','Jul'=>'07','Aug'=>'08','Agu'=>'08','Ags'=>'08','Sep'=>'09','Oct'=>'10','Okt'=>'10','Nov'=>'11','Dec'=>'12','Des'=>'12'];
    $p=explode('-',$d);
    if(count($p)===3){
        $mon=substr(trim($p[1]),0,3);
        if(isset($map[$mon])) return trim($p[2]).'-'.$map[$mon].'-'.str_pad(trim($p[0]),2,'0',STR_PAD_LEFT);
    }
    return '';
}
function rupiah($v){ return number_format((float)$v,0,',','.'); }
function qtyFmt($v){
    $s=number_format((float)$v,4,',','.');
    $s=rtrim(rtrim($s,'0'),',');
    return $s===''?'0':$s;
}

$today=date('Y-m-d'); $todayDisplay=fmtDate($today);
$startRaw=trim((string)($_GET['start_date']??$todayDisplay));
$endRaw=trim((string)($_GET['end_date']??$todayDisplay));
$start=mysqlDate($startRaw)?:$today; $end=mysqlDate($endRaw)?:$today;
if($start>$end){ [$start,$end]=[$end,$start]; [$startRaw,$endRaw]=[$endRaw,$startRaw]; }

$customerId=trim((string)($_GET['customer_id']??''));

$s=esc($conn,$start); $e=esc($conn,$end);
$c=esc($conn,$customerId);

$where=" WHERE DATE(hi.invoice_date) BETWEEN '$s' AND '$e'
         AND COALESCE(hi.invoice_no,'') NOT LIKE 'CP-MCP/INV/%'
         AND COALESCE(hi.order_no,'') NOT LIKE 'CP-MCP/SO/%' ";

if($c!=='') $where.=" AND hi.customer_id='$c' ";

$from="
FROM head_invoice hi
LEFT JOIN head_sales_order hso ON hso.order_no=hi.order_no
LEFT JOIN m_sales ms ON ms.sales_id=hso.sales_id
LEFT JOIN m_marketing mm ON mm.marketing_id=hso.marketing_id
LEFT JOIN (
    SELECT invoice_no,
           SUM(COALESCE(cash_amount,0)) cash_amount,
           SUM(COALESCE(titip_amount,0)) titip_amount,
           SUM(COALESCE(bayar_amount,0)) bayar_amount
    FROM detail_bayar GROUP BY invoice_no
) pay ON pay.invoice_no=hi.invoice_no
LEFT JOIN (
    SELECT invoice_no,SUM(COALESCE(return_amount,0)) return_amount
    FROM head_retur_invoice
    WHERE COALESCE(status,'Open')<>'Cancelled'
    GROUP BY invoice_no
) ret ON ret.invoice_no=hi.invoice_no
";

$summarySql="SELECT COUNT(*) total_invoice,
 COALESCE(SUM(hi.grand_total),0) total_penjualan,
 COALESCE(SUM(COALESCE(pay.bayar_amount,0)),0) total_pembayaran,
 COALESCE(SUM(COALESCE(ret.return_amount,0)),0) total_retur,
 COALESCE(SUM(GREATEST(COALESCE(hi.grand_total,0)-COALESCE(pay.bayar_amount,0)-COALESCE(ret.return_amount,0),0)),0) total_sisa
 $from $where";
$rs=mysqli_query($conn,$summarySql);
if(!$rs) die('Query summary error: '.mysqli_error($conn));
$summary=mysqli_fetch_assoc($rs);

$perPage=100; $pageNo=max(1,(int)($_GET['p']??1));
$totalRows=(int)($summary['total_invoice']??0);
$totalPages=max(1,(int)ceil($totalRows/$perPage));
$pageNo=min($pageNo,$totalPages); $offset=($pageNo-1)*$perPage;

$sql="SELECT hi.invoice_no,hi.invoice_date,hi.customer_id,hi.customer_name,hi.customer_address,hi.customer_city,
 hi.order_no,hi.order_date,hi.payment_type,hi.payment_term,hi.days,hi.currency,hi.remarks_invoice,
 hi.grand_total,hi.status,hi.approval_status,
 hso.po,hso.shipment_due_date,hso.remarks order_remarks,
 ms.sales_name,mm.marketing_name,
 COALESCE(pay.cash_amount,0) cash_amount,COALESCE(pay.titip_amount,0) titip_amount,
 COALESCE(pay.bayar_amount,0) bayar_amount,COALESCE(ret.return_amount,0) return_amount,
 (SELECT COUNT(DISTINCT x.shipping_no) FROM det_invoice x WHERE x.invoice_no=hi.invoice_no) shipping_count,
 (SELECT GROUP_CONCAT(DISTINCT x.shipping_no ORDER BY x.shipping_date,x.shipping_no SEPARATOR ', ')
  FROM det_invoice x WHERE x.invoice_no=hi.invoice_no) shipping_list
 $from $where
 ORDER BY hi.invoice_date DESC,hi.invoice_no DESC
 LIMIT $perPage OFFSET $offset";
$rs=mysqli_query($conn,$sql);
if(!$rs) die('Query rekap detail penjualan error: '.mysqli_error($conn));

$invoices=[];
while($r=mysqli_fetch_assoc($rs)){
    $r['balance_calc']=max(0,(float)$r['grand_total']-(float)$r['bayar_amount']-(float)$r['return_amount']);
    $r['details']=[];
    $invoices[$r['invoice_no']]=$r;
}

if($invoices){
    $in=array_map(fn($x)=>"'".mysqli_real_escape_string($conn,$x)."'",array_keys($invoices));
    $inList=implode(',',$in);
    $dsql="SELECT di.invoice_no,di.shipping_no,di.shipping_date,di.order_no,
      di.subtotal shipping_subtotal,
      hs.shipping_date actual_shipping_date,hs.sop_id,hs.gudang_id,hs.remarks_shipping shipping_remarks,
      ds.id shipping_detail_id,ds.inventory_id,ds.inventory_name,
      ds.qty_shipping,ds.uom_shipping,ds.qty_pack_shipping,ds.uom_pack_shipping,
      ds.qty_detail_shipping,ds.uom_detail_shipping,
      (SELECT dso.price_unit
       FROM detail_sales_order dso
       WHERE dso.order_no = COALESCE(di.order_no, hs.order_no)
         AND dso.inventory_id = ds.inventory_id
       ORDER BY dso.id ASC
       LIMIT 1) AS so_price_unit,

      (SELECT dso.price
       FROM detail_sales_order dso
       WHERE dso.order_no = COALESCE(di.order_no, hs.order_no)
         AND dso.inventory_id = ds.inventory_id
       ORDER BY dso.id ASC
       LIMIT 1) AS so_price,

      (SELECT dso.subtotal
       FROM detail_sales_order dso
       WHERE dso.order_no = COALESCE(di.order_no, hs.order_no)
         AND dso.inventory_id = ds.inventory_id
       ORDER BY dso.id ASC
       LIMIT 1) AS so_subtotal,
      ds.remarks_inventory_shipping,ds.note,
      mi.category,mi.type inventory_type,mi.merk,mi.quality
    FROM det_invoice di
    LEFT JOIN hed_shipping hs ON hs.shipping_no=di.shipping_no
    LEFT JOIN det_shipping ds ON ds.shipping_no=di.shipping_no
    LEFT JOIN m_inventory mi ON mi.inventory_id=ds.inventory_id
    WHERE di.invoice_no IN ($inList)
    ORDER BY di.invoice_no,COALESCE(hs.shipping_date,di.shipping_date),di.shipping_no,ds.id";
    $drs=mysqli_query($conn,$dsql);
    if(!$drs) die('Query detail shipping error: '.mysqli_error($conn));
    while($d=mysqli_fetch_assoc($drs)){
        if(isset($invoices[$d['invoice_no']])) $invoices[$d['invoice_no']]['details'][]=$d;
    }
}

function options(mysqli $conn,$sql,$key){
    $out=[]; $r=mysqli_query($conn,$sql);
    if($r) while($x=mysqli_fetch_assoc($r)) $out[]=$x[$key];
    return $out;
}
$customers=[]; $cr=mysqli_query($conn,"SELECT DISTINCT customer_id,customer_name FROM head_invoice
 WHERE COALESCE(customer_id,'')<>'' AND COALESCE(invoice_no,'') NOT LIKE 'CP-MCP/INV/%' ORDER BY customer_name");
if($cr) while($x=mysqli_fetch_assoc($cr)) $customers[]=$x;

function pageUrl($p){
    $q=$_GET; $q['page']='rekap_detail_penjualan'; $q['p']=$p;
    return 'index.php?'.http_build_query($q);
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>

<style>
.rdp-wrap{background:#f0f2f5;padding:12px;font-size:12px;color:#212529}
.rdp-card{background:#fff;border:1px solid #dee2e6;border-radius:5px;margin-bottom:10px;overflow:hidden}
.rdp-head{background:#e9ecef;border-bottom:1px solid #dee2e6;padding:9px 12px;display:flex;justify-content:space-between;align-items:center}
.rdp-head h4{font-size:15px;margin:0;font-weight:700}
.rdp-filter{padding:12px}.rdp-filter .form-label{font-size:10px;font-weight:700;text-transform:uppercase;margin-bottom:3px}
.rdp-filter .form-control,.rdp-filter .form-select{font-size:11px;min-height:31px}
.rdp-actions{display:flex;gap:6px;align-items:end;height:100%;flex-wrap:wrap}
.rdp-summary{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:10px}
.rdp-box{background:#fff;border:1px solid #dee2e6;border-radius:5px;padding:9px 11px}
.rdp-box .lbl{font-size:9px;text-transform:uppercase;color:#6c757d;font-weight:700}
.rdp-box .val{font-size:14px;font-weight:700;margin-top:2px}
.rdp-scroll{overflow-x:auto}.rdp-table{width:100%;min-width:1200px;border-collapse:collapse;font-size:11px}
.rdp-table th,.rdp-table td{border:1px solid #dee2e6;padding:6px;vertical-align:middle}
.rdp-table th{background:#e9ecef;text-align:center;font-size:10px;text-transform:uppercase;white-space:nowrap}
.rdp-main:hover td{background:#f8fbff}.right{text-align:right;white-space:nowrap}.center{text-align:center}.nowrap{white-space:nowrap}
.customer{min-width:190px;max-width:260px;white-space:normal}.invoice{font-weight:700;min-width:130px}
.ship-preview{display:block;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:9px;color:#6c757d}
.rdp-detail{display:none}.rdp-detail.open{display:table-row}.rdp-detail>td{padding:0!important;background:#f8f9fa}
.detail-box{padding:10px 12px 12px;border-left:4px solid #0d6efd}
.info-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:7px 14px;margin-bottom:10px;font-size:10px}
.info-label{font-size:9px;color:#6c757d;text-transform:uppercase;font-weight:700}.info-value{word-break:break-word}
.detail-scroll{overflow-x:auto}.detail-table{width:100%;min-width:1450px;border-collapse:collapse;background:#fff;font-size:10px}
.detail-table th,.detail-table td{border:1px solid #ced4da;padding:5px;vertical-align:top}
.detail-table th{background:#f1f3f5;text-align:center;white-space:nowrap}
.inv-name{min-width:240px;white-space:normal}.remarks{min-width:180px;max-width:280px;white-space:normal}
.ship-sep td{background:#e7f1ff!important;font-weight:700;color:#084298}
.badge-rdp{display:inline-block;padding:3px 6px;border-radius:10px;font-size:9px;font-weight:700}
.badge-open{background:#fff3cd;color:#664d03}.badge-ok{background:#d1e7dd;color:#0f5132}
.badge-bad{background:#f8d7da;color:#842029}.badge-other{background:#e2e3e5;color:#41464b}
.pagination-rdp{padding:10px 12px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.page-links{display:flex;gap:4px}.page-links a,.page-links span{padding:4px 7px;min-width:30px;text-align:center;border:1px solid #dee2e6;border-radius:3px;text-decoration:none}
.page-links .active{background:#0d6efd;color:#fff;border-color:#0d6efd}.empty{padding:30px;text-align:center;color:#6c757d}
@media(max-width:1100px){.rdp-summary{grid-template-columns:repeat(2,1fr)}.info-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.rdp-summary,.info-grid{grid-template-columns:1fr}}
.select2-container{width:100%!important}.select2-container--default .select2-selection--single{height:31px!important;border:1px solid #ced4da!important;font-size:11px!important}.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:29px!important}.select2-container--default .select2-selection--single .select2-selection__arrow{height:29px!important}
</style>

<div class="rdp-wrap">
<div class="rdp-card">
  <div class="rdp-head">
    <h4><i class="fa fa-chart-line"></i> Rekap Detail Penjualan</h4>
    <small class="text-muted">Periode berdasarkan Invoice Date</small>
  </div>
  <div class="rdp-filter">
  <form method="GET" action="index.php" class="row g-2">
    <input type="hidden" name="page" value="rekap_detail_penjualan">
    <div class="col-xl-2 col-md-3 col-sm-6"><label class="form-label">Start Date</label><input type="text" name="start_date" class="form-control form-control-sm datepicker" value="<?=h($startRaw)?>" autocomplete="off"></div>
    <div class="col-xl-2 col-md-3 col-sm-6"><label class="form-label">End Date</label><input type="text" name="end_date" class="form-control form-control-sm datepicker" value="<?=h($endRaw)?>" autocomplete="off"></div>
    <div class="col-xl-4 col-md-6"><label class="form-label">Customer</label><select name="customer_id" id="customer_id_filter" class="form-select form-select-sm"><option value="">-- Semua Customer --</option><?php foreach($customers as $x):?><option value="<?=h($x['customer_id'])?>" <?=$customerId===(string)$x['customer_id']?'selected':''?>><?=h($x['customer_name'])?> | <?=h($x['customer_id'])?></option><?php endforeach;?></select></div>
    <div class="col-xl-4 col-md-12"><div class="rdp-actions"><button class="btn btn-sm btn-primary"><i class="fa fa-search"></i> Tampilkan</button>
    <a href="index.php?page=rekap_detail_penjualan" class="btn btn-sm btn-outline-secondary"><i class="fa fa-rotate-left"></i> Reset</a>
     <a
    href="index.php?page=cetak_rekap_detail_penjualan&start_date=<?= urlencode($startRaw) ?>&end_date=<?= urlencode($endRaw) ?>&customer_id=<?= urlencode($customerId) ?>"
    target="_blank"
    class="btn btn-sm btn-success"
>
    <i class="fa fa-print"></i>
    Cetak
</a>
    <button type="button" class="btn btn-sm btn-outline-primary" id="expandAll"><i class="fa fa-plus-square"></i> Expand Semua</button></div></div>
   
  </form>
  </div>
</div>

<div class="rdp-summary">
 <div class="rdp-box"><div class="lbl">Jumlah Invoice</div><div class="val"><?=number_format((int)$summary['total_invoice'],0,',','.')?></div></div>
 <div class="rdp-box"><div class="lbl">Total Penjualan</div><div class="val">Rp <?=rupiah($summary['total_penjualan'])?></div></div>
 <div class="rdp-box"><div class="lbl">Total Pembayaran</div><div class="val">Rp <?=rupiah($summary['total_pembayaran'])?></div></div>
 <div class="rdp-box"><div class="lbl">Total Retur</div><div class="val">Rp <?=rupiah($summary['total_retur'])?></div></div>
 <div class="rdp-box"><div class="lbl">Sisa Piutang</div><div class="val">Rp <?=rupiah($summary['total_sisa'])?></div></div>
</div>

<div class="rdp-card">
<?php if(!$invoices):?><div class="empty"><i class="fa fa-circle-info"></i> Tidak ada data penjualan pada filter tersebut.</div>
<?php else:?>
<div class="rdp-scroll"><table class="rdp-table">
<thead><tr><th>Detail</th><th>Invoice Date</th><th>Invoice No.</th><th>Customer</th><th>Sales Order</th><th>Shipping</th><th>Total Invoice</th><th>Pembayaran</th><th>Retur</th><th>Sisa</th><th>Status</th></tr></thead>
<tbody>
<?php $i=0; foreach($invoices as $x): $i++; $id='rdp-'.$i;
$stxt=trim((string)($x['status']?:'Open')); $b='badge-other';
if(strcasecmp($stxt,'Open')===0)$b='badge-open';
elseif(in_array(strtolower($stxt),['closed','paid'],true))$b='badge-ok';
elseif(in_array(strtolower($stxt),['cancelled','reject'],true))$b='badge-bad';
?>
<tr class="rdp-main">
<td class="center"><button type="button" class="btn btn-sm btn-outline-primary btn-rdp" data-target="<?=h($id)?>"><i class="fa fa-plus"></i> Detail</button></td>
<td class="nowrap"><?=h(fmtDate($x['invoice_date']))?></td>
<td class="invoice"><?=h($x['invoice_no'])?></td>
<td class="customer"><strong><?=h($x['customer_name']?:'-')?></strong><?php if($x['customer_city']):?><br><small class="text-muted"><?=h($x['customer_city'])?></small><?php endif;?></td>
<td class="nowrap"><?=h($x['order_no']?:'-')?></td>
<td class="center"><span class="ship-preview" title="<?=h($x['shipping_list'])?>"><?=h($x['shipping_list']?:'-')?></span></td>
<td class="right">Rp <?=rupiah($x['grand_total'])?></td>
<td class="right">Rp <?=rupiah($x['bayar_amount'])?></td>
<td class="right">Rp <?=rupiah($x['return_amount'])?></td>
<td class="right"><strong>Rp <?=rupiah($x['balance_calc'])?></strong></td>
<td class="center"><span class="badge-rdp <?=h($b)?>"><?=h($stxt)?></span></td>
</tr>

<tr class="rdp-detail" id="<?=h($id)?>"><td colspan="11"><div class="detail-box">
<div class="info-grid">
 <div><div class="info-label">Customer ID</div><div class="info-value"><?=h($x['customer_id']?:'-')?></div></div>
 <div><div class="info-label">PO Customer</div><div class="info-value"><?=h($x['po']?:'-')?></div></div>
 <div><div class="info-label">Sales / Marketing</div><div class="info-value"><?=h($x['sales_name']?:'-')?> / <?=h($x['marketing_name']?:'-')?></div></div>
</div>

<div class="detail-scroll"><table class="detail-table">
<thead><tr><th>No.</th><th>Shipping No.</th><th>Shipping Date</th><th>SOP</th><th>Inventory ID</th><th>Inventory Name</th><th>Category</th><th>Qty</th><th>UoM</th><th>Qty Pack</th><th>UoM Pack</th><th>Qty Detail</th><th>UoM Detail</th><th>Price Unit</th><th>Price</th><th>Subtotal</th><th>Remarks</th></tr></thead>
<tbody>
<?php $n=0;$last=null; foreach($x['details'] as $d):
if($last!==$d['shipping_no']):$last=$d['shipping_no'];?>
<tr class="ship-sep"><td colspan="17"><i class="fa fa-truck"></i> Shipping: <?=h($d['shipping_no']?:'-')?> | Date: <?=h(fmtDate($d['actual_shipping_date']?:$d['shipping_date']))?> | Subtotal Shipping: Rp <?=rupiah($d['shipping_subtotal'])?><?php if($d['shipping_remarks']):?> | Remarks: <?=h($d['shipping_remarks'])?><?php endif;?></td></tr>
<?php endif; if(empty($d['shipping_detail_id'])):?><tr><td colspan="17" class="center text-muted">Detail inventory Shipping <?=h($d['shipping_no'])?> tidak ditemukan.</td></tr><?php continue; endif; $n++;
$rem=trim((string)($d['note']??'')); if($rem==='')$rem=trim((string)($d['remarks_inventory_shipping']??''));?>
<tr>
<td class="center"><?=$n?></td><td class="nowrap"><?=h($d['shipping_no'])?></td><td class="nowrap"><?=h(fmtDate($d['actual_shipping_date']?:$d['shipping_date']))?></td>
<td class="nowrap"><?=h($d['sop_id']?:'-')?></td><td class="nowrap"><?=h($d['inventory_id']?:'-')?></td><td class="inv-name"><?=h($d['inventory_name']?:'-')?></td>
<td><?=h($d['category']?:'-')?></td><td class="right"><?=qtyFmt($d['qty_shipping'])?></td><td class="center"><?=h($d['uom_shipping']?:'-')?></td>
<td class="right"><?=qtyFmt($d['qty_pack_shipping'])?></td><td class="center"><?=h($d['uom_pack_shipping']?:'-')?></td>
<td class="right"><?=qtyFmt($d['qty_detail_shipping'])?></td><td class="center"><?=h($d['uom_detail_shipping']?:'-')?></td>
<td class="right">Rp <?=rupiah($d['so_price_unit'])?></td>
<td class="right">Rp <?=rupiah($d['so_price'])?></td>
<td class="right">Rp <?=rupiah($d['so_subtotal'])?></td>
<td class="remarks"><?=nl2br(h($rem?:'-'))?></td>
</tr>
<?php endforeach; if(!$x['details']):?><tr><td colspan="17" class="center text-muted">Tidak ada detail shipping/inventory.</td></tr><?php endif;?>
</tbody></table></div>
</div></td></tr>
<?php endforeach;?>
</tbody></table></div>

<div class="pagination-rdp"><div>Menampilkan <strong><?=count($invoices)?></strong> dari <strong><?=number_format($totalRows,0,',','.')?></strong> invoice. Maksimal <?=$perPage?> per halaman.</div>
<?php if($totalPages>1):?><div class="page-links">
<?php if($pageNo>1):?><a href="<?=h(pageUrl($pageNo-1))?>">&laquo;</a><?php endif;?>
<?php $a=max(1,$pageNo-2);$z=min($totalPages,$pageNo+2);for($p=$a;$p<=$z;$p++):?>
<?php if($p===$pageNo):?><span class="active"><?=$p?></span><?php else:?><a href="<?=h(pageUrl($p))?>"><?=$p?></a><?php endif;?>
<?php endfor;if($pageNo<$totalPages):?><a href="<?=h(pageUrl($pageNo+1))?>">&raquo;</a><?php endif;?>
</div><?php endif;?></div>
<?php endif;?>
</div>
</div>

<script>
(function(){
 if(typeof flatpickr!=='undefined'){
   flatpickr('.datepicker',{dateFormat:'d-M-Y',allowInput:true,disableMobile:true,
    locale:{months:{shorthand:['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des'],
    longhand:['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']}}});
 }
 if(typeof jQuery!=='undefined' && jQuery.fn.select2){jQuery('#customer_id_filter').select2({width:'100%',placeholder:'-- Semua Customer --',allowClear:true});}
 function state(btn,open){
   btn.innerHTML=open?'<i class="fa fa-minus"></i> Tutup':'<i class="fa fa-plus"></i> Detail';
   btn.setAttribute('aria-expanded',open?'true':'false');
 }
 document.addEventListener('click',function(e){
   var b=e.target.closest('.btn-rdp'); if(!b)return;
   var r=document.getElementById(b.dataset.target); if(!r)return;
   var open=r.classList.toggle('open'); state(b,open);
 });
 var all=document.getElementById('expandAll'),opened=false;
 if(all)all.addEventListener('click',function(){
   opened=!opened;
   document.querySelectorAll('.rdp-detail').forEach(r=>r.classList.toggle('open',opened));
   document.querySelectorAll('.btn-rdp').forEach(b=>state(b,opened));
   all.innerHTML=opened?'<i class="fa fa-minus-square"></i> Tutup Semua':'<i class="fa fa-plus-square"></i> Expand Semua';
 });
})();
</script>
