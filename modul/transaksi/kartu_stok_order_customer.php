<?php
// modul/transaksi/kartu_stok_order_customer.php
// Report Kartu Stok Order Customer - sumber data pengiriman dari hed_shipping/det_shipping

if (!isset($_SESSION['username'])) {
    header("Location: ../../login.php");
    exit;
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

    // Dari input HTML date
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    // Dari datepicker format 27-Jun-2026 / 27-Jun-26
    $dt = DateTime::createFromFormat('d-M-Y', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    $dt = DateTime::createFromFormat('d-M-y', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    // Fallback strtotime
    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : $fallback;
}

function displayUnit($unit) {
    $unit = trim((string)$unit);
    return $unit === '' ? '-' : strtoupper($unit);
}

function formatQtyUnit($qty, $uom) {
    $qty = (float)($qty ?? 0);
    $uom = trim((string)$uom);
    if ($qty == 0 && $uom === '') {
        return '-';
    }
    return fmtNum($qty) . ($uom !== '' ? ' ' . strtoupper($uom) : '');
}

function calcOutstanding($orderQty, $shipQty) {
    return (float)$orderQty - (float)$shipQty;
}

// =========================
// FILTER
// =========================
$todaySql = date('Y-m-d');
$start_date = normalizeDate($_GET['start_date'] ?? '', $todaySql);
$end_date   = normalizeDate($_GET['end_date'] ?? '', $todaySql);

if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$start_date_display = fmtDate($start_date);
$end_date_display   = fmtDate($end_date);

$search_so        = trim((string)($_GET['search_so'] ?? ''));
$search_customer  = trim((string)($_GET['search_customer'] ?? ''));
$search_marketing = trim((string)($_GET['search_marketing'] ?? ''));

// =========================
// QUERY DATA
// Base data diambil dari SHIPPING pada periode filter,
// lalu join ke Sales Order untuk menghitung order vs sudah kirim vs kurang.
// =========================
$where = [];
$params = [];
$types = '';

if ($search_so !== '') {
    $where[] = "h.order_no LIKE ?";
    $params[] = '%' . $search_so . '%';
    $types .= 's';
}

if ($search_customer !== '') {
    $where[] = "(h.customer_name LIKE ? OR h.customer_id LIKE ?)";
    $params[] = '%' . $search_customer . '%';
    $params[] = '%' . $search_customer . '%';
    $types .= 'ss';
}

if ($search_marketing !== '') {
    $where[] = "(h.marketing_id LIKE ? OR h.sales_id LIKE ?)";
    $params[] = '%' . $search_marketing . '%';
    $params[] = '%' . $search_marketing . '%';
    $types .= 'ss';
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

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
        COALESCE(ship_all.shipped_qty, 0) AS shipped_qty,
        COALESCE(ship_all.shipped_qty_pack, 0) AS shipped_qty_pack,
        COALESCE(ship_all.shipped_qty_detail, 0) AS shipped_qty_detail,
        COALESCE(ship_all.total_sj, 0) AS total_sj,
        ship_all.last_shipping_date,
        COALESCE(detail_all.uom_detail_summary, '') AS shipped_uom_detail_summary
    FROM (
        SELECT DISTINCT hs.order_no, ds.inventory_id
        FROM hed_shipping hs
        INNER JOIN det_shipping ds ON ds.shipping_no = hs.shipping_no
        WHERE DATE(hs.shipping_date) BETWEEN ? AND ?
          AND COALESCE(hs.status, 'Open') <> 'Cancel'
    ) base_ship
    INNER JOIN head_sales_order h ON h.order_no = base_ship.order_no
    INNER JOIN detail_sales_order d ON d.order_no = h.order_no AND d.inventory_id = base_ship.inventory_id
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
        WHERE DATE(hs.shipping_date) BETWEEN ? AND ?
          AND COALESCE(hs.status, 'Open') <> 'Cancel'
        GROUP BY hs.order_no, ds.inventory_id
    ) ship_period ON ship_period.order_no = h.order_no AND ship_period.inventory_id = d.inventory_id
    LEFT JOIN (
        SELECT
            hs.order_no,
            ds.inventory_id,
            SUM(COALESCE(ds.qty_shipping, 0)) AS shipped_qty,
            SUM(COALESCE(ds.qty_pack_shipping, 0)) AS shipped_qty_pack,
            SUM(COALESCE(ds.qty_detail_shipping, 0)) AS shipped_qty_detail,
            COUNT(DISTINCT hs.shipping_no) AS total_sj,
            MAX(hs.shipping_date) AS last_shipping_date
        FROM hed_shipping hs
        INNER JOIN det_shipping ds ON ds.shipping_no = hs.shipping_no
        WHERE COALESCE(hs.status, 'Open') <> 'Cancel'
        GROUP BY hs.order_no, ds.inventory_id
    ) ship_all ON ship_all.order_no = h.order_no AND ship_all.inventory_id = d.inventory_id
    LEFT JOIN (
        SELECT
            x.order_no,
            x.inventory_id,
            GROUP_CONCAT(CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM FORMAT(x.qty_detail_sum, 2))), ' ', x.uom_detail) ORDER BY x.uom_detail SEPARATOR ', ') AS uom_detail_summary
        FROM (
            SELECT
                hs.order_no,
                dud.inventory_id,
                UPPER(dud.uom_detail) AS uom_detail,
                SUM(COALESCE(dud.qty_detail, 0)) AS qty_detail_sum
            FROM hed_shipping hs
            INNER JOIN det_shipping_uom_detail dud ON dud.shipping_no = hs.shipping_no
            WHERE COALESCE(hs.status, 'Open') <> 'Cancel'
            GROUP BY hs.order_no, dud.inventory_id, UPPER(dud.uom_detail)
        ) x
        GROUP BY x.order_no, x.inventory_id
    ) detail_all ON detail_all.order_no = h.order_no AND detail_all.inventory_id = d.inventory_id
    $whereSql
    ORDER BY ship_period.last_shipping_date_period DESC, h.order_no DESC, d.id ASC
";

$bindTypes = 'ssss' . $types;
$bindParams = [$start_date, $end_date, $start_date, $end_date, ...$params];

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die('Prepare error: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, $bindTypes, ...$bindParams);
mysqli_stmt_execute($stmt);
$query = mysqli_stmt_get_result($stmt);
if (!$query) {
    die('Query error: ' . mysqli_error($conn));
}
?>


<style>
    .crystal-header {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        color: #fff;
        padding: 10px 15px;
        border-radius: 5px 5px 0 0;
        font-weight: bold;
        margin-bottom: 15px;
    }
    .crystal-header h5 { margin: 0; font-size: 16px; font-weight: 700; }
    .filter-box {
        background: #f8f9fa;
        padding: 12px;
        border-radius: 5px;
        margin-bottom: 15px;
        border: 1px solid #dee2e6;
    }
    .filter-box label { margin-bottom: 4px; color: #333; font-size: 12px; font-weight: 600; }
    .filter-box .form-control { font-size: 12px; height: 32px; }
    .table-responsive { border: 1px solid #dee2e6; border-radius: 5px; background: #fff; }
    .table { margin-bottom: 0; font-size: 12px; }
    .table thead th {
        background: #2a5298;
        color: #fff;
        vertical-align: middle;
        white-space: nowrap;
        text-align: center;
        font-size: 11px;
        padding: 7px 6px;
    }
    .table tbody td { vertical-align: middle; padding: 6px; }
    .btn-action {
        padding: 3px 7px;
        font-size: 11px;
        border-radius: 3px;
        margin-right: 2px;
    }
    .badge-soft {
        display: inline-block;
        padding: 3px 7px;
        border-radius: 10px;
        font-size: 11px;
        font-weight: 700;
    }
    .badge-ok { background: #d4edda; color: #155724; }
    .badge-warn { background: #fff3cd; color: #856404; }
    .badge-over { background: #f8d7da; color: #721c24; }
    .modal-detail-table th { width: 35%; background: #f7f7f7; }
    .text-small { font-size: 11px; color: #666; }
    .datepicker-kso { background: #fff !important; cursor: pointer; }
    #detailKsoContent .table-responsive { border: 0; }
</style>

<div class="container-fluid">
    <div class="crystal-header">
        <h5><i class="fa fa-clipboard-list"></i> Kartu Stok Order Customer</h5>
    </div>

    <div class="filter-box">
        <form method="GET" action="index.php" id="formKartuStokOrderCustomer">
            <input type="hidden" name="page" value="kartu_stok_order_customer">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label>Start Date</label>
                    <input type="text" class="form-control datepicker-kso" name="start_date" id="start_date" value="<?= e($start_date_display) ?>" placeholder="dd-MMM-yyyy" autocomplete="off">
                </div>
                <div class="col-md-2">
                    <label>End Date</label>
                    <input type="text" class="form-control datepicker-kso" name="end_date" id="end_date" value="<?= e($end_date_display) ?>" placeholder="dd-MMM-yyyy" autocomplete="off">
                </div>
                <div class="col-md-2">
                    <label>No. SO</label>
                    <input type="text" class="form-control" name="search_so" placeholder="All" value="<?= e($search_so) ?>">
                </div>
                <div class="col-md-3">
                    <label>Nama Customer</label>
                    <input type="text" class="form-control" name="search_customer" placeholder="All" value="<?= e($search_customer) ?>">
                </div>
                <div class="col-md-2">
                    <label>Nama Marketing</label>
                    <input type="text" class="form-control" name="search_marketing" placeholder="All" value="<?= e($search_marketing) ?>">
                </div>
                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-search"></i> Cari</button>
                </div>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-hover">
            <thead>
                <tr>
                    <th style="width: 85px;">Aksi</th>
                    <th style="width: 45px;">No</th>
                    <th style="width: 130px;">No. SO</th>
                    <th>Customer Name</th>
                    <th>Inventory Name</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($query) > 0): ?>
                    <?php $no = 1; ?>
                    <?php while ($row = mysqli_fetch_assoc($query)): ?>
                        <?php
                            // Outstanding halaman mengikuti periode filter.
                            // Rumus: Jumlah Order - Shipping Periode Ini.
                            // Contoh: order 90, shipping periode 45 => outstanding 45.
                            $qtyOutstanding = calcOutstanding($row['quantity'], $row['shipped_qty_period']);

                            $statusClass = 'badge-warn';
                            $statusText = 'Belum Lengkap';
                            if ((float)$row['quantity'] > 0 && $qtyOutstanding <= 0) {
                                $statusClass = $qtyOutstanding < 0 ? 'badge-over' : 'badge-ok';
                                $statusText = $qtyOutstanding < 0 ? 'Over Kirim' : 'Selesai';
                            }

                            $printUrl = 'index.php?page=cetak_kartu_stok_order_customer'
                                . '&order_no=' . urlencode($row['order_no'])
                                . '&inventory_id=' . urlencode($row['inventory_id'])
                                . '&start_date=' . urlencode($start_date_display)
                                . '&end_date=' . urlencode($end_date_display);
                        ?>
                        <tr>
                            <td class="text-center">
                                <a href="<?= e($printUrl) ?>" target="_blank" class="btn btn-success btn-action" title="Cetak">
                                    <i class="fa fa-print"></i>
                                </a>
                                <button type="button"
                                        class="btn btn-info btn-action btn-detail-kso"
                                        title="Detail"
                                        data-order-no="<?= e($row['order_no']) ?>"
                                        data-inventory-id="<?= e($row['inventory_id']) ?>">
                                    <i class="fa fa-eye"></i>
                                </button>
                            </td>
                            <td class="text-center"><?= $no++ ?></td>
                            <td>
                                <strong><?= e($row['order_no']) ?></strong><br>
                                <span class="text-small">Order: <?= e(fmtDate($row['order_date'])) ?></span><br>
                                <span class="text-small">Kirim: <?= e(fmtDate($row['last_shipping_date_period'])) ?></span>
                            </td>
                            <td>
                                <?= e($row['customer_name']) ?><br>
                                <span class="text-small">Marketing: <?= e($row['marketing_id'] ?: '-') ?></span>
                            </td>
                            <td>
                                <?= e($row['inventory_name']) ?><br>
                                <span class="<?= e($statusClass) ?> badge-soft"><?= e($statusText) ?></span>
                                <span class="text-small">
                                    Shipping Periode: <?= e(fmtNum($row['shipped_qty_period'])) ?> <?= e(displayUnit($row['uom'])) ?> |
                                    Outstanding: <?= e(fmtNum($qtyOutstanding)) ?> <?= e(displayUnit($row['uom'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">Data shipping pada periode ini tidak ditemukan.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="detailKsoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="detailKsoTitle">Detail Kartu Stok Order Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="detailKsoContent">
                <div class="text-center text-muted py-4">Memuat data...</div>
            </div>
            <div class="modal-footer">
                <a href="#" target="_blank" class="btn btn-success btn-sm d-none" id="detailKsoPrintBtn"><i class="fa fa-print"></i> Cetak</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    function toDisplay(dateValue) {
        if (!dateValue) return '';
        var parts = dateValue.split('-');
        if (parts.length !== 3) return dateValue;
        return parts[2] + '-' + months[parseInt(parts[1], 10) - 1] + '-' + parts[0];
    }

    function toInputDate(displayValue) {
        if (!displayValue) return '';
        var parts = displayValue.split('-');
        if (parts.length !== 3) return displayValue;
        var monthIndex = months.map(function(m){ return m.toLowerCase(); }).indexOf(parts[1].toLowerCase());
        if (monthIndex < 0) return displayValue;
        return parts[2] + '-' + pad(monthIndex + 1) + '-' + pad(parseInt(parts[0], 10));
    }

    function attachPicker(el) {
        if (!el) return;

        if (window.jQuery && typeof jQuery(el).datepicker === 'function') {
            try {
                jQuery(el).datepicker({
                    format: 'dd-M-yyyy',
                    dateFormat: 'dd-M-yy',
                    autoclose: true,
                    todayHighlight: true
                });
                return;
            } catch (e) {}
        }

        el.addEventListener('focus', function() {
            var current = toInputDate(el.value);
            el.type = 'date';
            el.value = current;
        });
        el.addEventListener('blur', function() {
            var current = el.value;
            el.type = 'text';
            el.value = toDisplay(current);
        });
        el.addEventListener('change', function() {
            if (el.type === 'date') {
                var current = el.value;
                el.type = 'text';
                el.value = toDisplay(current);
            }
        });
    }

    attachPicker(document.getElementById('start_date'));
    attachPicker(document.getElementById('end_date'));

    var detailModalEl = document.getElementById('detailKsoModal');
    var detailTitle = document.getElementById('detailKsoTitle');
    var detailContent = document.getElementById('detailKsoContent');
    var detailPrintBtn = document.getElementById('detailKsoPrintBtn');

    function openModal() {
        if (window.bootstrap && bootstrap.Modal) {
            bootstrap.Modal.getOrCreateInstance(detailModalEl).show();
            return;
        }
        if (window.jQuery && typeof jQuery(detailModalEl).modal === 'function') {
            jQuery(detailModalEl).modal('show');
            return;
        }
        detailModalEl.style.display = 'block';
        detailModalEl.classList.add('show');
    }

    document.querySelectorAll('.btn-detail-kso').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var orderNo = btn.getAttribute('data-order-no') || '';
            var inventoryId = btn.getAttribute('data-inventory-id') || '';
            var startDate = document.getElementById('start_date').value || '';
            var endDate = document.getElementById('end_date').value || '';

            detailTitle.textContent = 'Detail Kartu Stok Order Customer';
            detailContent.innerHTML = '<div class="text-center text-muted py-4"><i class="fa fa-spinner fa-spin"></i> Memuat data...</div>';
            detailPrintBtn.classList.add('d-none');
            detailPrintBtn.href = '#';
            openModal();

            var url = 'modul/transaksi/ajax_kartu_stok_order_customer_detail.php'
                + '?order_no=' + encodeURIComponent(orderNo)
                + '&inventory_id=' + encodeURIComponent(inventoryId)
                + '&start_date=' + encodeURIComponent(startDate)
                + '&end_date=' + encodeURIComponent(endDate);

            fetch(url, {
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(response) {
                    return response.text().then(function(text) {
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Response bukan JSON:', text);
                            throw new Error('Response bukan JSON. Cek file ajax_kartu_stok_order_customer_detail.php atau routing index.php. Awal response: ' + text.substring(0, 80));
                        }
                    });
                })
                .then(function(data) {
                    if (!data || !data.success) {
                        detailContent.innerHTML = '<div class="alert alert-danger mb-0">' + (data && data.message ? data.message : 'Gagal memuat detail.') + '</div>';
                        return;
                    }
                    detailTitle.textContent = data.title || 'Detail Kartu Stok Order Customer';
                    detailContent.innerHTML = data.html || '';
                    if (data.print_url) {
                        detailPrintBtn.href = data.print_url;
                        detailPrintBtn.classList.remove('d-none');
                    }
                })
                .catch(function(error) {
                    detailContent.innerHTML = '<div class="alert alert-danger mb-0">Gagal memuat detail: ' + error + '</div>';
                });
        });
    });
})();
</script>
