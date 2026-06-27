<?php
// modul/transaksi/ajax_kartu_stok_order_customer_detail.php
// AJAX detail Kartu Stok Order Customer

// Penting: endpoint ini harus menghasilkan JSON murni, tanpa header/footer ERP.
// Buffer dipakai untuk membersihkan output tidak sengaja dari include/routing.
if (ob_get_level() === 0) {
    ob_start();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ini_set('display_errors', 0);
error_reporting(E_ALL);

function jsonResponse($payload) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['username'])) {
    jsonResponse([
        'success' => false,
        'message' => 'Session habis. Silakan login ulang.'
    ]);
}

include __DIR__ . '/../../koneksi.php';

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmtNum($value, $decimals = 2) {
    $num = (float)($value ?? 0);
    return number_format($num, $decimals, '.', ',');
}

function fmtDate($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '-';
}

function normalizeDate($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }
    $dt = DateTime::createFromFormat('d-M-Y', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }
    $dt = DateTime::createFromFormat('d-M-y', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : $fallback;
}

function unitText($unit) {
    $unit = trim((string)$unit);
    return $unit === '' ? '-' : strtoupper($unit);
}

function qtyUnit($qty, $unit) {
    $unit = trim((string)$unit);
    return fmtNum($qty) . ($unit !== '' ? ' ' . strtoupper($unit) : '');
}

function safeOutstanding($orderQty, $shippedQty) {
    return (float)$orderQty - (float)$shippedQty;
}

$order_no = trim((string)($_GET['order_no'] ?? ''));
$inventory_id = trim((string)($_GET['inventory_id'] ?? ''));
$today = date('Y-m-d');
$start_date = normalizeDate($_GET['start_date'] ?? '', $today);
$end_date = normalizeDate($_GET['end_date'] ?? '', $today);

if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

if ($order_no === '' || $inventory_id === '') {
    jsonResponse([
        'success' => false,
        'message' => 'Order No atau Inventory ID tidak lengkap.'
    ]);
}

// Ambil data order + ringkasan shipping period dan total.
$sql = "
    SELECT
        h.order_no,
        h.order_date,
        h.po,
        h.customer_id,
        h.customer_name,
        h.marketing_id,
        h.sales_id,
        h.shipment_due_date,
        h.tolerance,
        d.id AS so_detail_id,
        d.inventory_id,
        d.inventory_name,
        d.quantity,
        d.uom,
        d.quantity_pack,
        d.uom_pack,
        d.quantity_detail,
        d.uom_detail,
        d.price_unit,
        d.price,
        d.subtotal,
        d.remarks AS so_remarks,
        COALESCE(ship_period.shipped_qty_period, 0) AS shipped_qty_period,
        COALESCE(ship_period.shipped_qty_pack_period, 0) AS shipped_qty_pack_period,
        COALESCE(ship_period.shipped_qty_detail_period, 0) AS shipped_qty_detail_period,
        COALESCE(ship_period.total_sj_period, 0) AS total_sj_period,
        ship_period.first_shipping_date_period,
        ship_period.last_shipping_date_period,
        COALESCE(ship_all.shipped_qty_total, 0) AS shipped_qty_total,
        COALESCE(ship_all.shipped_qty_pack_total, 0) AS shipped_qty_pack_total,
        COALESCE(ship_all.shipped_qty_detail_total, 0) AS shipped_qty_detail_total,
        COALESCE(ship_all.total_sj_total, 0) AS total_sj_total,
        ship_all.last_shipping_date_total
    FROM head_sales_order h
    INNER JOIN detail_sales_order d ON d.order_no = h.order_no
    LEFT JOIN (
        SELECT
            hs.order_no,
            ds.inventory_id,
            SUM(COALESCE(ds.qty_shipping, 0)) AS shipped_qty_period,
            SUM(COALESCE(ds.qty_pack_shipping, 0)) AS shipped_qty_pack_period,
            SUM(COALESCE(ds.qty_detail_shipping, 0)) AS shipped_qty_detail_period,
            COUNT(DISTINCT hs.shipping_no) AS total_sj_period,
            MIN(hs.shipping_date) AS first_shipping_date_period,
            MAX(hs.shipping_date) AS last_shipping_date_period
        FROM hed_shipping hs
        INNER JOIN det_shipping ds ON ds.shipping_no = hs.shipping_no
        WHERE hs.order_no = ?
          AND ds.inventory_id = ?
          AND DATE(hs.shipping_date) BETWEEN ? AND ?
          AND COALESCE(hs.status, 'Open') <> 'Cancel'
        GROUP BY hs.order_no, ds.inventory_id
    ) ship_period ON ship_period.order_no = h.order_no AND ship_period.inventory_id = d.inventory_id
    LEFT JOIN (
        SELECT
            hs.order_no,
            ds.inventory_id,
            SUM(COALESCE(ds.qty_shipping, 0)) AS shipped_qty_total,
            SUM(COALESCE(ds.qty_pack_shipping, 0)) AS shipped_qty_pack_total,
            SUM(COALESCE(ds.qty_detail_shipping, 0)) AS shipped_qty_detail_total,
            COUNT(DISTINCT hs.shipping_no) AS total_sj_total,
            MAX(hs.shipping_date) AS last_shipping_date_total
        FROM hed_shipping hs
        INNER JOIN det_shipping ds ON ds.shipping_no = hs.shipping_no
        WHERE hs.order_no = ?
          AND ds.inventory_id = ?
          AND COALESCE(hs.status, 'Open') <> 'Cancel'
        GROUP BY hs.order_no, ds.inventory_id
    ) ship_all ON ship_all.order_no = h.order_no AND ship_all.inventory_id = d.inventory_id
    WHERE h.order_no = ?
      AND d.inventory_id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    jsonResponse(['success' => false, 'message' => 'Prepare error: ' . mysqli_error($conn)]);
}
mysqli_stmt_bind_param(
    $stmt,
    'ssssssss',
    $order_no,
    $inventory_id,
    $start_date,
    $end_date,
    $order_no,
    $inventory_id,
    $order_no,
    $inventory_id
);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$row) {
    jsonResponse([
        'success' => false,
        'message' => 'Data order tidak ditemukan.'
    ]);
}

// Summary UOM detail shipping dari tabel baru, periode dan total.
function getUomDetailSummary($conn, $order_no, $inventory_id, $start_date = null, $end_date = null) {
    $dateWhere = '';
    $types = 'ss';
    $params = [$order_no, $inventory_id];

    if ($start_date !== null && $end_date !== null) {
        $dateWhere = " AND DATE(hs.shipping_date) BETWEEN ? AND ? ";
        $types .= 'ss';
        $params[] = $start_date;
        $params[] = $end_date;
    }

    $sql = "
        SELECT
            UPPER(dud.uom_detail) AS uom_detail,
            SUM(COALESCE(dud.qty_detail, 0)) AS qty_detail
        FROM hed_shipping hs
        INNER JOIN det_shipping_uom_detail dud ON dud.shipping_no = hs.shipping_no
        WHERE hs.order_no = ?
          AND dud.inventory_id = ?
          AND COALESCE(hs.status, 'Open') <> 'Cancel'
          $dateWhere
        GROUP BY UPPER(dud.uom_detail)
        ORDER BY UPPER(dud.uom_detail)
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return '-';
    }
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $parts = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $parts[] = fmtNum($r['qty_detail']) . ' ' . strtoupper($r['uom_detail']);
    }
    mysqli_stmt_close($stmt);

    return !empty($parts) ? implode(', ', $parts) : '-';
}

$uomDetailPeriod = getUomDetailSummary($conn, $order_no, $inventory_id, $start_date, $end_date);
// Untuk tampilan detail, total dikirim mengikuti periode filter, bukan seluruh historis.
$uomDetailTotal = $uomDetailPeriod;

$orderQty = (float)$row['quantity'];
$orderPack = (float)$row['quantity_pack'];
$orderDetail = (float)$row['quantity_detail'];

$shipPeriodQty = (float)$row['shipped_qty_period'];
$shipPeriodPack = (float)$row['shipped_qty_pack_period'];
$shipPeriodDetail = (float)$row['shipped_qty_detail_period'];

$shipTotalQty = $shipPeriodQty;
$shipTotalPack = $shipPeriodPack;
$shipTotalDetail = $shipPeriodDetail;

// Sesuai alur report: Sudah Dikirim dihitung dari shipping pada periode filter saja.
// Outstanding = Jumlah Order - Shipping Periode Ini.
$outQty = safeOutstanding($orderQty, $shipPeriodQty);
$outPack = safeOutstanding($orderPack, $shipPeriodPack);
$outDetail = safeOutstanding($orderDetail, $shipPeriodDetail);

// Jika order qty_detail kosong / 0, jangan tampilkan outstanding detail negatif dari hasil pengiriman detail.
$showDetailOutstanding = $orderDetail > 0;

// Riwayat SJ periode.
$sqlHistory = "
    SELECT
        hs.shipping_no,
        hs.shipping_date,
        ds.qty_shipping,
        ds.uom_shipping,
        ds.qty_pack_shipping,
        ds.uom_pack_shipping,
        ds.qty_detail_shipping,
        ds.uom_detail_shipping,
        ds.remarks_inventory_shipping
    FROM hed_shipping hs
    INNER JOIN det_shipping ds ON ds.shipping_no = hs.shipping_no
    WHERE hs.order_no = ?
      AND ds.inventory_id = ?
      AND DATE(hs.shipping_date) BETWEEN ? AND ?
      AND COALESCE(hs.status, 'Open') <> 'Cancel'
    ORDER BY hs.shipping_date ASC, hs.shipping_no ASC
";
$stmtHistory = mysqli_prepare($conn, $sqlHistory);
$historyRows = [];
if ($stmtHistory) {
    mysqli_stmt_bind_param($stmtHistory, 'ssss', $order_no, $inventory_id, $start_date, $end_date);
    mysqli_stmt_execute($stmtHistory);
    $resHistory = mysqli_stmt_get_result($stmtHistory);
    while ($hist = mysqli_fetch_assoc($resHistory)) {
        $historyRows[] = $hist;
    }
    mysqli_stmt_close($stmtHistory);
}

ob_start();
?>
<div class="row mb-3">
    <div class="col-md-6">
        <table class="table table-sm table-bordered modal-detail-table">
            <tr><th>No. SO</th><td><?= e($row['order_no']) ?></td></tr>
            <tr><th>Tanggal Order</th><td><?= e(fmtDate($row['order_date'])) ?></td></tr>
            <tr><th>No. PO</th><td><?= e($row['po'] ?: '-') ?></td></tr>
            <tr><th>Customer</th><td><?= e($row['customer_name']) ?></td></tr>
        </table>
    </div>
    <div class="col-md-6">
        <table class="table table-sm table-bordered modal-detail-table">
            <tr><th>Inventory ID</th><td><?= e($row['inventory_id']) ?></td></tr>
            <tr><th>Inventory Name</th><td><?= e($row['inventory_name']) ?></td></tr>
            <tr><th>Marketing</th><td><?= e($row['marketing_id'] ?: '-') ?></td></tr>
            <tr><th>Periode Shipping</th><td><?= e(fmtDate($start_date)) ?> s/d <?= e(fmtDate($end_date)) ?></td></tr>
        </table>
    </div>
</div>

<table class="table table-bordered table-sm">
    <thead>
        <tr>
            <th>Keterangan</th>
            <th class="text-end">Qty</th>
            <th>UOM</th>
            <th class="text-end">Qty Pack</th>
            <th>UOM Pack</th>
            <th>UOM Detail</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td><strong>Jumlah Order</strong></td>
            <td class="text-end"><?= e(fmtNum($orderQty)) ?></td>
            <td><?= e(unitText($row['uom'])) ?></td>
            <td class="text-end"><?= e(fmtNum($orderPack)) ?></td>
            <td><?= e(unitText($row['uom_pack'])) ?></td>
            <td><?= e($orderDetail > 0 ? qtyUnit($orderDetail, $row['uom_detail']) : unitText($row['uom_detail'])) ?></td>
        </tr>
        <tr>
            <td><strong>Shipping Periode Ini</strong></td>
            <td class="text-end"><?= e(fmtNum($shipPeriodQty)) ?></td>
            <td><?= e(unitText($row['uom'])) ?></td>
            <td class="text-end"><?= e(fmtNum($shipPeriodPack)) ?></td>
            <td><?= e(unitText($row['uom_pack'])) ?></td>
            <td><?= e($uomDetailPeriod) ?></td>
        </tr>
        <tr>
            <td><strong>Sudah Dikirim</strong></td>
            <td class="text-end"><?= e(fmtNum($shipTotalQty)) ?></td>
            <td><?= e(unitText($row['uom'])) ?></td>
            <td class="text-end"><?= e(fmtNum($shipTotalPack)) ?></td>
            <td><?= e(unitText($row['uom_pack'])) ?></td>
            <td><?= e($uomDetailTotal) ?></td>
        </tr>
        <tr>
            <td><strong>Kurang / Outstanding</strong></td>
            <td class="text-end"><strong><?= e(fmtNum($outQty)) ?></strong></td>
            <td><?= e(unitText($row['uom'])) ?></td>
            <td class="text-end"><strong><?= e(fmtNum($outPack)) ?></strong></td>
            <td><?= e(unitText($row['uom_pack'])) ?></td>
            <td><?= e($showDetailOutstanding ? qtyUnit($outDetail, $row['uom_detail']) : '-') ?></td>
        </tr>
    </tbody>
</table>

<div class="mt-3">
    <h6 class="mb-2"><strong>Riwayat Shipping Periode Ini</strong></h6>
    <div class="table-responsive">
        <table class="table table-sm table-bordered">
            <thead>
                <tr>
                    <th style="width: 120px;">Tanggal Kirim</th>
                    <th style="width: 150px;">No. SJ</th>
                    <th class="text-end">Qty</th>
                    <th>UOM</th>
                    <th class="text-end">Qty Pack</th>
                    <th>UOM Pack</th>
                    <th>UOM Detail</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($historyRows)): ?>
                    <?php foreach ($historyRows as $hist): ?>
                        <tr>
                            <td><?= e(fmtDate($hist['shipping_date'])) ?></td>
                            <td><?= e($hist['shipping_no']) ?></td>
                            <td class="text-end"><?= e(fmtNum($hist['qty_shipping'])) ?></td>
                            <td><?= e(unitText($hist['uom_shipping'])) ?></td>
                            <td class="text-end"><?= e(fmtNum($hist['qty_pack_shipping'])) ?></td>
                            <td><?= e(unitText($hist['uom_pack_shipping'])) ?></td>
                            <td><?= e($hist['qty_detail_shipping'] > 0 ? qtyUnit($hist['qty_detail_shipping'], $hist['uom_detail_shipping']) : '-') ?></td>
                            <td><?= e($hist['remarks_inventory_shipping']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="8" class="text-center text-muted">Tidak ada shipping pada periode ini.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$html = ob_get_clean();

$printUrl = 'index.php?page=cetak_kartu_stok_order_customer&order_no=' . urlencode($row['order_no']) . '&inventory_id=' . urlencode($row['inventory_id']);

jsonResponse([
    'success' => true,
    'title' => 'Detail Kartu Stok Order Customer - ' . $row['order_no'],
    'html' => $html,
    'print_url' => $printUrl
]);
