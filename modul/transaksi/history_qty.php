<?php
// modul/transaksi/history_qty.php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['username'])) { header('Location: ../../login.php'); exit; }
require __DIR__ . '/../../koneksi.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');
function eh($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function nqty($n) { return number_format((float)$n, 4, ',', '.'); }
$start = (string)($_GET['start_date'] ?? date('Y-m-01'));
$end = (string)($_GET['end_date'] ?? date('Y-m-d'));
$customer = trim((string)($_GET['customer'] ?? ''));
$shipping = trim((string)($_GET['shipping_no'] ?? ''));
foreach ([$start, $end] as $date) {
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) die('Format tanggal tidak valid.');
}
if ($start > $end) die('Tanggal awal tidak boleh melebihi tanggal akhir.');
$sql = "SELECT history_id, edited_at, invoice_no, shipping_no, shipping_date,
        customer_name, inventory_name, qty_awal, qty_akhir, selisih_qty, edited_by
        FROM invoice_qty_history
        WHERE edited_at >= ? AND edited_at < DATE_ADD(?, INTERVAL 1 DAY)";
$types='ss'; $params=[$start,$end];
if ($customer !== '') { $sql .= ' AND (customer_name LIKE ? OR customer_id LIKE ?)'; $types.='ss'; $params[]='%'.$customer.'%'; $params[]='%'.$customer.'%'; }
if ($shipping !== '') { $sql .= ' AND shipping_no LIKE ?'; $types.='s'; $params[]='%'.$shipping.'%'; }
$sql .= ' ORDER BY edited_at DESC, history_id DESC LIMIT 5000';
$stmt=mysqli_prepare($conn,$sql);
mysqli_stmt_bind_param($stmt,$types,...$params);
mysqli_stmt_execute($stmt);
$result=mysqli_stmt_get_result($stmt);
// Saat file di-include melalui index.php?page=history_qty, URL relatif
// harus mengikuti lokasi index.php, bukan lokasi file PHP yang di-include.
$isViaIndex = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? ''))) === 'index.php';
$formAction = $isViaIndex ? 'index.php' : 'history_qty.php';
$resetUrl = $isViaIndex ? 'index.php?page=history_qty' : 'history_qty.php';
$printPath = $isViaIndex ? 'modul/transaksi/cetak_history_qty.php' : 'cetak_history_qty.php';
$printUrl = $printPath . '?' . http_build_query([
    'start_date' => $start,
    'end_date' => $end,
    'customer' => $customer,
    'shipping_no' => $shipping,
]);
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Histori Perubahan Qty Invoice</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<style>
body{background:#f4f6f8;font-size:12px}.wrap{max-width:1400px;margin:15px auto}.card-header{font-weight:700}
.table-sm th,.table-sm td{font-size:11px;vertical-align:middle}.table thead th{white-space:nowrap;background:#eef4ff}
.table td.name{min-width:220px;white-space:normal;word-break:break-word}.form-label{font-weight:600}
</style></head><body><div class="wrap">
<div class="card shadow-sm mb-3"><div class="card-header bg-primary text-white">History Qty Invoice</div>
<div class="card-body"><form method="get" action="<?=eh($formAction)?>" class="row g-2 align-items-end">
<?php if ($isViaIndex): ?><input type="hidden" name="page" value="history_qty"><?php endif; ?>
<div class="col-md-2"><label class="form-label">Start Date (Tanggal Edit)</label><input type="date" class="form-control form-control-sm" name="start_date" value="<?=eh($start)?>" required></div>
<div class="col-md-2"><label class="form-label">End Date (Tanggal Edit)</label><input type="date" class="form-control form-control-sm" name="end_date" value="<?=eh($end)?>" required></div>
<div class="col-md-3"><label class="form-label">Nama Customer / ID</label><input class="form-control form-control-sm" name="customer" value="<?=eh($customer)?>" placeholder="Cari customer"></div>
<div class="col-md-3"><label class="form-label">No Shipping</label><input class="form-control form-control-sm" name="shipping_no" value="<?=eh($shipping)?>" placeholder="Cari shipping"></div>
<div class="col-md-2 d-flex gap-2 flex-wrap"><button class="btn btn-primary btn-sm" type="submit">Filter</button><a class="btn btn-secondary btn-sm" href="<?=eh($resetUrl)?>">Reset</a><a class="btn btn-success btn-sm" href="<?=eh($printUrl)?>" target="_blank" rel="noopener noreferrer">Cetak / PDF</a></div>
</form></div></div>
<div class="card shadow-sm"><div class="card-body table-responsive p-0"><table class="table table-sm table-bordered table-striped mb-0"><thead><tr>
<th>No</th><th>Tgl Edit</th><th>No Shipping</th><th>Date Shipping</th><th>Nama Customer</th><th>Inventory Name</th><th class="text-end">Qty Awal (KG)</th><th class="text-end">Qty Akhir (KG)</th><th class="text-end">Selisih Qty (KG)</th><th>User Edit</th>
</tr></thead><tbody>
<?php $i=0; while ($r=mysqli_fetch_assoc($result)): $i++; ?>
<tr><td><?= $i ?></td><td><?=eh($r['edited_at'])?></td><td><?=eh($r['shipping_no'])?></td><td><?=eh($r['shipping_date'])?></td><td><?=eh($r['customer_name'])?></td><td class="name"><?=eh($r['inventory_name'])?></td><td class="text-end"><?=nqty($r['qty_awal'])?></td><td class="text-end"><?=nqty($r['qty_akhir'])?></td><td class="text-end"><?=nqty($r['selisih_qty'])?></td><td><?=eh($r['edited_by'])?></td></tr>
<?php endwhile; if (!$i): ?><tr><td colspan="10" class="text-center text-muted py-4">Tidak ada histori perubahan Qty pada filter ini.</td></tr><?php endif; ?>
</tbody></table></div></div><div class="text-muted mt-2">Maksimal 5.000 perubahan terbaru dalam rentang filter. Tanggal filter berdasarkan waktu edit.</div>
</div></body></html>
<?php mysqli_stmt_close($stmt); ?>
