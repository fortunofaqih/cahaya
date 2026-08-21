<?php
// modul/transaksi/kartu_stok_order_customer.php
// Revisi: filter master customer/marketing + status All/Complete/Outstanding
// Data hanya muncul jika SO + inventory pernah mempunyai shipping non-cancel.

if (!isset($_SESSION['username'])) {
    header('Location: ../../login.php');
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
    if (empty($date) || $date === '0000-00-00') return '-';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '-';
}

function normalizeDateNullable($value) {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;

    foreach (['d-M-Y', 'd-M-y'] as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) return $dt->format('Y-m-d');
    }

    $ts = strtotime($value);
    return $ts ? date('Y-m-d', $ts) : '';
}

// =========================
// FILTER MASTER
// =========================
// Default periode: tanggal 1 sampai tanggal terakhir bulan berjalan.
// Contoh jika sekarang Agustus 2026:
// Start Date = 2026-08-01
// End Date   = 2026-08-31
$default_start_date = date('Y-m-01');
$default_end_date   = date('Y-m-t');

$start_date = normalizeDateNullable($_GET['start_date'] ?? '');
$end_date   = normalizeDateNullable($_GET['end_date'] ?? '');

// Jika parameter tanggal belum dikirim / kosong, gunakan periode bulan berjalan.
if ($start_date === '') {
    $start_date = $default_start_date;
}

if ($end_date === '') {
    $end_date = $default_end_date;
}

$search_so        = trim((string)($_GET['search_so'] ?? ''));
$customer_id      = trim((string)($_GET['customer_id'] ?? ''));
$marketing_id     = trim((string)($_GET['marketing_id'] ?? ''));
$status_filter    = strtolower(trim((string)($_GET['status'] ?? 'all')));

if (!in_array($status_filter, ['all', 'complete', 'outstanding'], true)) {
    $status_filter = 'all';
}

if ($start_date !== '' && $end_date !== '' && $start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$customerOptions = [];
$qCustomer = mysqli_query($conn, "
    SELECT customer_id, customer
    FROM m_customer
    WHERE COALESCE(is_active, 'Checked') = 'Checked'
    ORDER BY customer ASC
");
while ($r = mysqli_fetch_assoc($qCustomer)) $customerOptions[] = $r;

$marketingOptions = [];
$qMarketing = mysqli_query($conn, "
    SELECT marketing_id, marketing_name
    FROM m_marketing
    WHERE COALESCE(is_active, 'Checked') = 'Checked'
    ORDER BY marketing_name ASC
");
while ($r = mysqli_fetch_assoc($qMarketing)) $marketingOptions[] = $r;

// =========================
// QUERY UTAMA
// =========================
// Penting:
// 1. SO + inventory wajib sudah pernah mempunyai shipping.
// 2. shipped_qty dan shipped_qty_pack dihitung dari SELURUH shipping non-cancel.
// 3. Filter tanggal hanya menentukan SO/inventory yang punya aktivitas shipping di periode tsb.
// 4. Complete/Outstanding memakai outstanding aktual, bukan outstanding periode.

$where = [];
$params = [];
$types = '';

if ($search_so !== '') {
    $where[] = 'h.order_no LIKE ?';
    $params[] = '%' . $search_so . '%';
    $types .= 's';
}

if ($customer_id !== '') {
    $where[] = 'h.customer_id = ?';
    $params[] = $customer_id;
    $types .= 's';
}

if ($marketing_id !== '') {
    // Utama marketing_id. sales_id tetap dipertimbangkan untuk data lama.
    $where[] = '(h.marketing_id = ? OR h.sales_id = ?)';
    $params[] = $marketing_id;
    $params[] = $marketing_id;
    $types .= 'ss';
}

$dateExistsSql = '';
if ($start_date !== '' || $end_date !== '') {
    $dateParts = [];
    if ($start_date !== '') {
        $dateParts[] = 'DATE(hsx.shipping_date) >= ?';
        $params[] = $start_date;
        $types .= 's';
    }
    if ($end_date !== '') {
        $dateParts[] = 'DATE(hsx.shipping_date) <= ?';
        $params[] = $end_date;
        $types .= 's';
    }

    $dateExistsSql = "
        AND EXISTS (
            SELECT 1
            FROM hed_shipping hsx
            INNER JOIN det_shipping dsx ON dsx.shipping_no = hsx.shipping_no
            WHERE hsx.order_no = h.order_no
              AND dsx.inventory_id = d.inventory_id
              AND COALESCE(hsx.status, 'Open') <> 'Cancel'
              AND " . implode(' AND ', $dateParts) . "
        )
    ";
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : 'WHERE 1=1';

$statusHaving = '';
if ($status_filter === 'complete') {
    $statusHaving = "HAVING outstanding_base <= 0";
} elseif ($status_filter === 'outstanding') {
    $statusHaving = "HAVING outstanding_base > 0";
}

$sql = "
    SELECT
        h.order_no,
        h.order_date,
        h.po AS no_po,
        h.customer_id,
        COALESCE(NULLIF(mc.customer, ''), h.customer_name) AS customer,
        COALESCE(NULLIF(mm.marketing_name, ''), NULLIF(h.marketing_id, ''), NULLIF(h.sales_id, ''), '-') AS marketing_name,
        d.id AS so_detail_id,
        d.inventory_id,
        d.inventory_name,
        COALESCE(NULLIF(mi.internal_name, ''), d.inventory_name) AS internal_name,
        COALESCE(d.quantity, 0) AS order_qty,
        d.uom,
        COALESCE(d.quantity_pack, 0) AS order_qty_pack,
        d.uom_pack,
        COALESCE(sa.shipped_qty, 0) AS shipped_qty,
        COALESCE(sa.shipped_qty_pack, 0) AS shipped_qty_pack,
        COALESCE(sa.total_shipping, 0) AS total_shipping,
        sa.first_shipping_date,
        sa.last_shipping_date,
        (COALESCE(d.quantity, 0) - COALESCE(sa.shipped_qty, 0)) AS outstanding_qty,
        (COALESCE(d.quantity_pack, 0) - COALESCE(sa.shipped_qty_pack, 0)) AS outstanding_pack,
        CASE
            WHEN COALESCE(d.quantity, 0) > 0
                THEN (COALESCE(d.quantity, 0) - COALESCE(sa.shipped_qty, 0))
            ELSE (COALESCE(d.quantity_pack, 0) - COALESCE(sa.shipped_qty_pack, 0))
        END AS outstanding_base
    FROM head_sales_order h
    INNER JOIN detail_sales_order d
        ON d.order_no = h.order_no
    INNER JOIN (
        SELECT
            hs.order_no,
            ds.inventory_id,
            SUM(COALESCE(ds.qty_shipping, 0)) AS shipped_qty,
            SUM(COALESCE(ds.qty_pack_shipping, 0)) AS shipped_qty_pack,
            COUNT(DISTINCT hs.shipping_no) AS total_shipping,
            MIN(hs.shipping_date) AS first_shipping_date,
            MAX(hs.shipping_date) AS last_shipping_date
        FROM hed_shipping hs
        INNER JOIN det_shipping ds
            ON ds.shipping_no = hs.shipping_no
        WHERE COALESCE(hs.status, 'Open') <> 'Cancel'
        GROUP BY hs.order_no, ds.inventory_id
    ) sa
        ON sa.order_no = h.order_no
       AND sa.inventory_id = d.inventory_id
    LEFT JOIN m_customer mc
        ON mc.customer_id = h.customer_id
    LEFT JOIN m_marketing mm
        ON mm.marketing_id = COALESCE(NULLIF(h.marketing_id, ''), h.sales_id)
    LEFT JOIN m_inventory mi
        ON mi.inventory_id = d.inventory_id
    $whereSql
    $dateExistsSql
    $statusHaving
    ORDER BY h.order_date DESC, h.order_no DESC, d.id ASC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) die('Prepare error: ' . mysqli_error($conn));
if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$query = mysqli_stmt_get_result($stmt);
if (!$query) die('Query error: ' . mysqli_error($conn));
?>

<style>
.crystal-header{background:linear-gradient(135deg,#1e3c72 0%,#2a5298 100%);color:#fff;padding:10px 15px;border-radius:5px 5px 0 0;font-weight:bold;margin-bottom:15px}
.crystal-header h5{margin:0;font-size:16px;font-weight:700}
.filter-box{background:#f8f9fa;padding:12px;border-radius:5px;margin-bottom:15px;border:1px solid #dee2e6}
.filter-box label{margin-bottom:4px;color:#333;font-size:12px;font-weight:600}
.filter-box .form-control,.filter-box .form-select{font-size:12px;min-height:32px}
.table-responsive{border:1px solid #dee2e6;border-radius:5px;background:#fff}
.table{margin-bottom:0;font-size:12px}
.table thead th{background:#2a5298;color:#fff;vertical-align:middle;white-space:nowrap;text-align:center;font-size:11px;padding:7px 6px}
.table tbody td{vertical-align:middle;padding:6px}
.btn-action{padding:3px 7px;font-size:11px;border-radius:3px;margin:1px}
.badge-soft{display:inline-block;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:700}
.badge-complete{background:#d4edda;color:#155724}
.badge-outstanding{background:#fff3cd;color:#856404}
.text-small{font-size:11px;color:#666}
.detail-row>td{background:#f7f9fc!important;padding:12px!important}
.detail-box{border:1px solid #dbe3ef;border-radius:5px;background:#fff;padding:10px}
.detail-box .table thead th{background:#5b6f8f}
</style>

<div class="container-fluid">
    <div class="crystal-header">
        <h5><i class="fa fa-clipboard-list"></i> Kartu Stok Order Customer</h5>
    </div>

    <div class="filter-box">
        <form method="GET" action="index.php" id="formKso">
            <input type="hidden" name="page" value="kartu_stok_order_customer">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label>Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="<?= e($start_date) ?>">
                </div>
                <div class="col-md-2">
                    <label>End Date</label>
                    <input type="date" class="form-control" name="end_date" value="<?= e($end_date) ?>">
                </div>
                <div class="col-md-2">
                    <label>No. SO</label>
                    <input type="text" class="form-control" name="search_so" value="<?= e($search_so) ?>" placeholder="All">
                </div>
                <div class="col-md-2">
                    <label>Customer</label>
                    <select name="customer_id" class="form-select select2-kso">
                        <option value="">All Customer</option>
                        <?php foreach ($customerOptions as $c): ?>
                            <option value="<?= e($c['customer_id']) ?>" <?= $customer_id === $c['customer_id'] ? 'selected' : '' ?>>
                                <?= e($c['customer']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label>Marketing</label>
                    <select name="marketing_id" class="form-select select2-kso">
                        <option value="">All Marketing</option>
                        <?php foreach ($marketingOptions as $m): ?>
                            <option value="<?= e($m['marketing_id']) ?>" <?= $marketing_id === $m['marketing_id'] ? 'selected' : '' ?>>
                                <?= e($m['marketing_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label>Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="complete" <?= $status_filter === 'complete' ? 'selected' : '' ?>>Complete</option>
                        <option value="outstanding" <?= $status_filter === 'outstanding' ? 'selected' : '' ?>>Outstanding</option>
                    </select>
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
                    <th style="width:45px">No</th>
                    <th style="width:95px">Aksi</th>
                    <th style="width:145px">No. SO</th>
                    <th style="width:105px">Order Date</th>
                    <th style="width:140px">PO No.</th>
                    <th style="min-width:190px">Customer</th>
                    <th style="min-width:280px">Ukuran</th>
                </tr>
            </thead>
            <tbody>
            <?php if (mysqli_num_rows($query) > 0): ?>
                <?php $no = 1; while ($row = mysqli_fetch_assoc($query)): ?>
                    <?php
                    $isComplete = ((float)$row['outstanding_base'] <= 0);
                    $detailId = 'detail-kso-' . (int)$row['so_detail_id'];

                    // Print URL sementara disiapkan; file cetak dapat dibuat setelah format print disepakati.
                    $printUrl = 'index.php?page=cetak_kartu_stok_order_customer'
                              . '&order_no=' . urlencode($row['order_no'])
                              . '&inventory_id=' . urlencode($row['inventory_id']);
                    ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="text-center text-nowrap">
                            <button type="button"
                                    class="btn btn-info btn-action btn-expand-kso"
                                    data-target="<?= e($detailId) ?>"
                                    data-order-no="<?= e($row['order_no']) ?>"
                                    data-inventory-id="<?= e($row['inventory_id']) ?>"
                                    title="Expand">
                                <i class="fa fa-plus"></i>
                            </button>
                            <a href="<?= e($printUrl) ?>" target="_blank" class="btn btn-success btn-action" title="Print">
                                <i class="fa fa-print"></i>
                            </a>
                        </td>
                        <td>
                            <strong><?= e($row['order_no']) ?></strong><br>
                            <span class="badge-soft <?= $isComplete ? 'badge-complete' : 'badge-outstanding' ?>">
                                <?= $isComplete ? 'COMPLETE' : 'OUTSTANDING' ?>
                            </span>
                        </td>
                        <td class="text-center"><?= e(fmtDate($row['order_date'])) ?></td>
                        <td><?= e($row['no_po'] ?: '-') ?></td>
                        <td>
                            <strong><?= e($row['customer']) ?></strong><br>
                            <span class="text-small">Marketing: <?= e($row['marketing_name']) ?></span>
                        </td>
                        <td>
                            <?= e($row['internal_name']) ?><br>
                            <span class="text-small">
                                Order <?= e(fmtNum($row['order_qty'])) ?> <?= e(strtoupper((string)$row['uom'])) ?> |
                                Terkirim <?= e(fmtNum($row['shipped_qty'])) ?> <?= e(strtoupper((string)$row['uom'])) ?> |
                                Outstanding <?= e(fmtNum(max(0, (float)$row['outstanding_qty']))) ?> <?= e(strtoupper((string)$row['uom'])) ?>
                            </span>
                        </td>
                    </tr>
                    <tr id="<?= e($detailId) ?>" class="detail-row d-none">
                        <td colspan="7">
                            <div class="detail-box detail-content-kso">
                                <div class="text-center text-muted py-2">
                                    <i class="fa fa-spinner fa-spin"></i> Memuat detail...
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Data tidak ditemukan.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function(){
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('.select2-kso').select2({ width: '100%' });
    }

    document.querySelectorAll('.btn-expand-kso').forEach(function(btn){
        btn.addEventListener('click', function(){
            var targetId = btn.getAttribute('data-target');
            var row = document.getElementById(targetId);
            if (!row) return;

            var icon = btn.querySelector('i');
            var isOpen = !row.classList.contains('d-none');
            if (isOpen) {
                row.classList.add('d-none');
                if (icon) { icon.classList.remove('fa-minus'); icon.classList.add('fa-plus'); }
                return;
            }

            row.classList.remove('d-none');
            if (icon) { icon.classList.remove('fa-plus'); icon.classList.add('fa-minus'); }

            if (row.getAttribute('data-loaded') === '1') return;

            var content = row.querySelector('.detail-content-kso');
            var url = 'modul/transaksi/ajax_kartu_stok_order_customer_detail.php'
                    + '?order_no=' + encodeURIComponent(btn.getAttribute('data-order-no') || '')
                    + '&inventory_id=' + encodeURIComponent(btn.getAttribute('data-inventory-id') || '');

            fetch(url, { credentials: 'same-origin', headers: {'X-Requested-With':'XMLHttpRequest'} })
                .then(function(res){ return res.text(); })
                .then(function(text){
                    var data;
                    try { data = JSON.parse(text); }
                    catch(e) { throw new Error('Response AJAX bukan JSON: ' + text.substring(0,120)); }
                    if (!data.success) throw new Error(data.message || 'Gagal memuat detail.');
                    content.innerHTML = data.html;
                    row.setAttribute('data-loaded','1');
                })
                .catch(function(err){
                    content.innerHTML = '<div class="alert alert-danger mb-0">' + String(err.message || err) + '</div>';
                });
        });
    });
})();
</script>
