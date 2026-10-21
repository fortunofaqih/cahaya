<?php
// modul/transaksi/cetak_history_qty.php
// Laporan cetak mengambil filter yang sama dengan history_qty.php.
// Gunakan dialog cetak browser untuk print atau Save as PDF (A4 landscape).
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
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cetak Histori Perubahan Qty Invoice</title>

<style>
@page {
    size: A4 landscape;
    margin: 12mm 10mm;
}

* {
    box-sizing: border-box;
}

/* TAMPILAN MONITOR */
body {
    background: #f2f4f7;
    color: #171717;
    margin: 0;
    font: 13px Arial, Helvetica, sans-serif;
}

.sheet {
    width: 98%;
    max-width: none;
    margin: 16px auto;
    padding: 20px;
    background: white;
}

h1 {
    margin: 0 0 10px;
    text-align: center;
    font-size: 22px;
    letter-spacing: .2px;
}

.meta {
    margin: 0 0 15px;
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 5px 15px;
    font-size: 13px;
}

.meta strong {
    font-weight: 700;
}

table {
    border-collapse: collapse;
    width: 100%;
    table-layout: fixed;
}

th, td {
    border: 1px solid #777;
    padding: 8px 6px;
    font-size: 12px;
    vertical-align: top;
    overflow-wrap: anywhere;
}

th {
    background: #e9edf4;
    text-align: center;
}

.num {
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

.center {
    text-align: center;
}

.empty {
    padding: 14px;
    text-align: center;
    color: #666;
}

.toolbar {
    text-align: right;
    margin-bottom: 12px;
}

.toolbar button {
    background: #157347;
    color: white;
    border: 0;
    padding: 9px 14px;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 700;
}

.toolbar button:hover {
    background: #146c43;
}

.note {
    margin-top: 8px;
    font-size: 11px;
    color: #555;
}

thead {
    display: table-header-group;
}

tr {
    break-inside: avoid;
    page-break-inside: avoid;
}

/* TAMPILAN CETAK / PDF */
@media print {

    body {
        background: white;
        font-size: 11px;
    }

    .sheet {
        width: 100%;
        max-width: none;
        margin: 0;
        padding: 0;
    }

    .toolbar {
        display: none !important;
    }

    h1 {
        font-size: 14px;
        margin-bottom: 5px;
    }

    th, td {
        font-size: 8px;
        padding: 4px 3px;
    }

    .meta {
        font-size: 9px;
        margin-bottom: 12px;
    }

    .note {
        font-size: 9px;
    }

    thead th {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
</style>
</head><body><main class="sheet">
<div class="toolbar"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
<h1>MEMO PERUBAHAN QTY</h1>
<div class="meta">
 <div><strong>Periode Tanggal Edit:</strong> <?=eh($start)?> s/d <?=eh($end)?></div>
 <div><strong>Customer / ID:</strong> <?=eh($customer !== '' ? $customer : 'Semua')?></div>
 <div><strong>No Shipping:</strong> <?=eh($shipping !== '' ? $shipping : 'Semua')?></div>
</div>
<table><colgroup>
 <col style="width:3%"><col style="width:12%"><col style="width:11%"><col style="width:9%"><col style="width:13%"><col style="width:20%"><col style="width:8%"><col style="width:8%"><col style="width:8%"><col style="width:8%">
</colgroup><thead><tr>
<th>No</th><th>Tgl Edit</th><th>No Shipping</th><th>Date Shipping</th><th>Nama Customer</th><th>Inventory Name</th><th>Qty Awal (KG)</th><th>Qty Akhir (KG)</th><th>Selisih Qty (KG)</th><th>User Edit</th>
</tr></thead><tbody>
<?php $i=0; while ($r=mysqli_fetch_assoc($result)): $i++; ?>
<tr><td class="center"><?=$i?></td><td><?=eh($r['edited_at'])?></td><td><?=eh($r['shipping_no'])?></td><td><?=eh($r['shipping_date'])?></td><td><?=eh($r['customer_name'])?></td><td><?=eh($r['inventory_name'])?></td><td class="num"><?=nqty($r['qty_awal'])?></td><td class="num"><?=nqty($r['qty_akhir'])?></td><td class="num"><?=nqty($r['selisih_qty'])?></td><td><?=eh($r['edited_by'])?></td></tr>
<?php endwhile; if (!$i): ?><tr><td colspan="10" class="empty">Tidak ada histori perubahan Qty pada filter ini.</td></tr><?php endif; ?>
</tbody></table>
<div class="note">Total baris tercetak: <?=$i?>. Maksimal 5.000 perubahan terbaru sesuai filter, sama seperti laporan layar.</div>
</main></body></html>
<?php mysqli_stmt_close($stmt); ?>
