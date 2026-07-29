<?php
// modul/transaksi/cetak_invoice_full.php

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fmtDate($v) {
    if (!$v || $v === '0000-00-00') return '';
    $t = strtotime($v);
    return $t ? date('d-m-Y', $t) : '';
}
function fmtNumber($v) {
    $s = number_format((float)$v, 2, ',', '.');
    return rtrim(rtrim($s, '0'), ',');
}
function fmtMoney($v) { return number_format((float)$v, 2, ',', '.'); }

$invoiceNo = trim($_GET['invoice_no'] ?? $_GET['id'] ?? '');
if ($invoiceNo === '') die('Invoice No kosong.');

$stmt = mysqli_prepare($conn, "SELECT * FROM head_invoice WHERE invoice_no = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $invoiceNo);
mysqli_stmt_execute($stmt);
$head = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);
if (!$head) die('Invoice tidak ditemukan.');

$stmt = mysqli_prepare($conn, "
    SELECT di.shipping_no, di.shipping_date,
           hs.order_no, hs.order_date,
           hs.customer_id, hs.customer_name, hs.customer_address, hs.customer_city,
           hs.transporter, hs.driver_name, hs.truck_no, hs.remarks_shipping
    FROM det_invoice di
    INNER JOIN hed_shipping hs ON hs.shipping_no = di.shipping_no
    WHERE di.invoice_no = ?
    ORDER BY di.shipping_date, di.shipping_no
");
mysqli_stmt_bind_param($stmt, 's', $invoiceNo);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$shippingList = [];
while ($row = mysqli_fetch_assoc($res)) $shippingList[] = $row;
mysqli_stmt_close($stmt);

function getItems($conn, $shippingNo) {
    $stmt = mysqli_prepare($conn, "
        SELECT ds.*,
               CASE
                   WHEN COALESCE(ds.price_unit,0) > 0 THEN ds.price_unit
                   WHEN COALESCE(dso.price,0) > 0 THEN dso.price
                   WHEN COALESCE(dso.quantity_pack,0) > 0
                       THEN COALESCE(dso.subtotal,0) / NULLIF(dso.quantity_pack,0)
                   ELSE 0
               END AS invoice_price,
               CASE
                   WHEN COALESCE(ds.subtotal,0) > 0 THEN ds.subtotal
                   ELSE COALESCE(ds.qty_pack_shipping,0) *
                       CASE
                           WHEN COALESCE(ds.price_unit,0) > 0 THEN ds.price_unit
                           WHEN COALESCE(dso.price,0) > 0 THEN dso.price
                           WHEN COALESCE(dso.quantity_pack,0) > 0
                               THEN COALESCE(dso.subtotal,0) / NULLIF(dso.quantity_pack,0)
                           ELSE 0
                       END
               END AS invoice_subtotal
        FROM det_shipping ds
        INNER JOIN hed_shipping hs ON hs.shipping_no = ds.shipping_no
        LEFT JOIN detail_sales_order dso
          ON dso.order_no = hs.order_no
         AND dso.inventory_id = ds.inventory_id
        WHERE ds.shipping_no = ?
        ORDER BY ds.id
    ");
    mysqli_stmt_bind_param($stmt, 's', $shippingNo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $items = [];
    while ($row = mysqli_fetch_assoc($res)) $items[] = $row;
    mysqli_stmt_close($stmt);
    return $items;
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Cetak Invoice Lengkap - <?= e($invoiceNo) ?></title>
<style>
*{box-sizing:border-box} body{margin:0;padding:18px;background:#e9ecef;font-family:Arial,sans-serif;color:#111}
.no-print{text-align:center;margin-bottom:15px}.btn{display:inline-block;border:0;border-radius:4px;padding:8px 16px;margin:0 4px;color:#fff;text-decoration:none;cursor:pointer}.btn-secondary{background:#6c757d}.btn-success{background:#198754}
.page{width:210mm;min-height:297mm;margin:0 auto 18px;padding:14mm;background:#fff;box-shadow:0 0 8px rgba(0,0,0,.15);page-break-after:always}.page:last-of-type{page-break-after:auto}
.header{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #000;padding-bottom:10px;margin-bottom:12px}.company{font-size:16px;font-weight:bold}.title{text-align:right}.title h1{margin:0;font-size:22px}.title div{font-size:12px;margin-top:4px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}.box{border:1px solid #333;padding:8px 10px;min-height:95px}.box-title{font-weight:bold;border-bottom:1px solid #aaa;padding-bottom:4px;margin-bottom:6px}.info{width:100%;border-collapse:collapse;font-size:11px}.info td{padding:2px 0;vertical-align:top}.info td:first-child{width:100px;font-weight:bold}
.items{width:100%;border-collapse:collapse;font-size:10px}.items th,.items td{border:1px solid #222;padding:6px 5px;vertical-align:top}.items th{background:#e9ecef;text-align:center}.right{text-align:right}.center{text-align:center}.nowrap{white-space:nowrap}
.summary{width:78mm;margin-left:auto;margin-top:10px;border-collapse:collapse;font-size:11px}.summary td{border:1px solid #222;padding:6px 8px}.summary td:first-child{font-weight:bold}.remarks{margin-top:14px;border:1px solid #222;padding:8px;min-height:42px;font-size:11px}
@page{size:A4 portrait;margin:0}@media print{body{margin:0;padding:0;background:#fff}.no-print{display:none!important}.page{width:210mm;min-height:297mm;margin:0;padding:12mm;box-shadow:none}.items tr{page-break-inside:avoid}}
</style>
</head>
<body>
<div class="no-print">
<a class="btn btn-secondary" href="../../index.php?page=invoice">Kembali</a>
<button class="btn btn-success" onclick="window.print()">Cetak / Print</button>
</div>

<?php if (!$shippingList): ?>
<div class="page"><div class="center" style="padding-top:50mm">Shipping untuk invoice ini tidak ditemukan.</div></div>
<?php else: foreach ($shippingList as $ship):
    $items = getItems($conn, $ship['shipping_no']);
    $shippingSubtotal = 0;
    foreach ($items as $x) $shippingSubtotal += (float)($x['invoice_subtotal'] ?? 0);
?>
<div class="page">
    <div class="header">
        <!--<div><div class="company">PT MUTIARACAHAYA PLASTINDO</div><div style="font-size:11px;margin-top:4px">Invoice berdasarkan Shipping</div></div>-->
        <div class="title"><h1>INVOICE</h1></div>
    </div>

    <div class="info-grid">
        <div class="box"><div class="box-title">Customer</div><table class="info">
            <tr><td>Customer</td><td>: <?= e($ship['customer_name'] ?: $head['customer_name']) ?></td></tr>
            <tr><td>Address</td><td>: <?= nl2br(e($ship['customer_address'] ?: $head['customer_address'])) ?></td></tr>
            <tr><td>City</td><td>: <?= e($ship['customer_city'] ?: $head['customer_city']) ?></td></tr>
        </table></div>
        <div class="box"><div class="box-title">Invoice & Shipping</div><table class="info">
            <tr><td>Invoice No</td><td>: <?= e($invoiceNo) ?></td></tr>
            <tr><td>Shipping No</td><td>: <?= e($ship['shipping_no']) ?></td></tr>
            <tr><td>Shipping Date</td><td>: <?= e(fmtDate($ship['shipping_date'])) ?></td></tr>
            <tr><td>Order No</td><td>: <?= e($ship['order_no'] ?: $head['order_no']) ?></td></tr>
            <tr><td>Order Date</td><td>: <?= e(fmtDate($ship['order_date'])) ?></td></tr>
        </table></div>
    </div>

    <table class="items"><thead><tr>
        <th>No</th><th>Inventory ID</th><th>Inventory Name</th><th>Qty</th><th>UoM</th><th>Qty Pack</th><th>UoM Pack</th><th>Harga</th><th>Subtotal</th>
    </tr></thead><tbody>
    <?php if (!$items): ?><tr><td colspan="9" class="center">Detail barang tidak ditemukan.</td></tr>
    <?php else: foreach ($items as $i => $item): ?>
        <tr>
            <td class="center"><?= $i+1 ?></td><td><?= e($item['inventory_id']) ?></td>
            <td><?= e($item['inventory_name']) ?><?php if ($item['remarks_inventory_shipping']): ?><div style="font-size:9px;margin-top:3px"><?= e($item['remarks_inventory_shipping']) ?></div><?php endif; ?></td>
            <td class="right nowrap"><?= e(fmtNumber($item['qty_shipping'])) ?></td><td class="center"><?= e($item['uom_shipping']) ?></td>
            <td class="right nowrap"><?= e(fmtNumber($item['qty_pack_shipping'])) ?></td><td class="center"><?= e($item['uom_pack_shipping']) ?></td>
            <td class="right nowrap">Rp <?= e(fmtMoney($item['invoice_price'])) ?></td><td class="right nowrap">Rp <?= e(fmtMoney($item['invoice_subtotal'])) ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody></table>

    <table class="summary">
        <tr><td>Subtotal Shipping</td><td class="right">Rp <?= e(fmtMoney($shippingSubtotal)) ?></td></tr>
        <tr><td>Invoice Subtotal</td><td class="right">Rp <?= e(fmtMoney($head['subtotal'])) ?></td></tr>
        <tr><td>Down Payment</td><td class="right">Rp <?= e(fmtMoney($head['down_payment'])) ?></td></tr>
        <tr><td>Grand Total</td><td class="right"><strong>Rp <?= e(fmtMoney($head['grand_total'])) ?></strong></td></tr>
    </table>

    <div class="remarks"><strong>Remarks Invoice:</strong><br><?= nl2br(e($head['remarks_invoice'])) ?></div>
</div>
<?php endforeach; endif; ?>

<script>
<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
window.addEventListener('load',function(){setTimeout(function(){window.print()},400)});
<?php endif; ?>
</script>
</body>
</html>
