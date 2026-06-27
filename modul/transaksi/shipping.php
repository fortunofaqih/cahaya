<?php
// modul/transaksi/shipping.php

if (!isset($_SESSION['username'])) {
    header("Location: ../../login.php");
    exit;
}

include __DIR__ . '/../../koneksi.php';

// =============================================
// HELPER
// =============================================
function formatDateIndonesian($date) {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }

    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return '';
    }

    $tanggal = date('d', $timestamp);
    $bulan_num = (int)date('m', $timestamp);
    $tahun = date('Y', $timestamp);

    return $tanggal . '-' . $bulan[$bulan_num] . '-' . $tahun;
}

function convertFilterDateToMysql($date) {
    if ($date === null || trim($date) === '') {
        return '';
    }

    $date = trim($date);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    $months = [
        'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
        'May' => '05', 'Mei' => '05',
        'Jun' => '06', 'Jul' => '07',
        'Aug' => '08', 'Agu' => '08',
        'Sep' => '09',
        'Oct' => '10', 'Okt' => '10',
        'Nov' => '11',
        'Dec' => '12', 'Des' => '12'
    ];

    $parts = explode('-', $date);
    if (count($parts) === 3) {
        $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $monthText = $parts[1];
        $year = $parts[2];

        if (isset($months[$monthText]) && preg_match('/^\d{4}$/', $year)) {
            return $year . '-' . $months[$monthText] . '-' . $day;
        }
    }

    return '';
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// =============================================
// FILTER PENCARIAN - DEFAULT HARI INI
// =============================================
$start_date_raw = isset($_GET['start_date']) && trim($_GET['start_date']) !== ''
    ? trim($_GET['start_date'])
    : formatDateIndonesian(date('Y-m-d'));

$end_date_raw = isset($_GET['end_date']) && trim($_GET['end_date']) !== ''
    ? trim($_GET['end_date'])
    : formatDateIndonesian(date('Y-m-d'));

$start_date_sql = convertFilterDateToMysql($start_date_raw);
$end_date_sql = convertFilterDateToMysql($end_date_raw);

if ($start_date_sql === '') {
    $start_date_sql = date('Y-m-d');
    $start_date_raw = formatDateIndonesian($start_date_sql);
}

if ($end_date_sql === '') {
    $end_date_sql = date('Y-m-d');
    $end_date_raw = formatDateIndonesian($end_date_sql);
}

if ($start_date_sql > $end_date_sql) {
    [$start_date_sql, $end_date_sql] = [$end_date_sql, $start_date_sql];
    [$start_date_raw, $end_date_raw] = [$end_date_raw, $start_date_raw];
}

$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$approval_status = isset($_GET['approval_status']) ? trim($_GET['approval_status']) : '';
$shipping_id = isset($_GET['shipping_id']) ? trim($_GET['shipping_id']) : '';

// Batasi value filter agar tidak ada value aneh
$allowed_status = ['', 'Open', 'Close', 'Closed'];
$allowed_approval = ['', 'Approve', 'Reject', 'Pending'];

if (!in_array($status, $allowed_status, true)) {
    $status = '';
}

if (!in_array($approval_status, $allowed_approval, true)) {
    $approval_status = '';
}

// =============================================
// QUERY UTAMA
// =============================================
$whereParts = [];
$params = [];
$types = '';

$whereParts[] = "DATE(h.shipping_date) BETWEEN ? AND ?";
$params[] = $start_date_sql;
$params[] = $end_date_sql;
$types .= 'ss';

if ($status !== '') {
    if ($status === 'Close' || $status === 'Closed') {
        $whereParts[] = "h.status IN ('Close', 'Closed')";
    } else {
        $whereParts[] = "h.status = ?";
        $params[] = $status;
        $types .= 's';
    }
}

if ($approval_status !== '') {
    $whereParts[] = "h.approval_status = ?";
    $params[] = $approval_status;
    $types .= 's';
}

if ($shipping_id !== '') {
    $whereParts[] = "h.shipping_no LIKE ?";
    $params[] = '%' . $shipping_id . '%';
    $types .= 's';
}

$where = "WHERE " . implode(" AND ", $whereParts);

$sql = "
    SELECT
        h.*,
        g.name AS gudang_name,
        COALESCE(dc.total_detail, 0) AS total_detail
    FROM hed_shipping h
    LEFT JOIN m_gudang g ON h.gudang_id = g.gudang_id
    LEFT JOIN (
        SELECT shipping_no, COUNT(*) AS total_detail
        FROM det_shipping
        GROUP BY shipping_no
    ) dc ON h.shipping_no = dc.shipping_no
    $where
    ORDER BY h.shipping_date DESC, h.shipping_no DESC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die("Query Shipping Prepare Error: " . mysqli_error($conn));
}

if ($types !== '') {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$query = mysqli_stmt_get_result($stmt);

if (!$query) {
    die("Query Shipping Error: " . mysqli_error($conn));
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

    .crystal-header h5 {
        margin: 0;
        font-size: 16px;
        font-weight: 700;
    }

    .filter-box {
        background: #f8f9fa;
        padding: 12px;
        border-radius: 5px;
        margin-bottom: 15px;
        border: 1px solid #dee2e6;
    }

    .filter-box label {
        margin-bottom: 4px;
        color: #333;
    }

    .filter-box .form-control,
    .filter-box .form-select {
        font-size: 12px;
        height: 31px;
    }

    .btn-vb-primary {
        background: linear-gradient(to bottom, #2b579a, #1e3d6b);
        border: 1px solid #183054;
        color: #fff;
    }

    .btn-vb-primary:hover,
    .btn-vb-primary:focus {
        background: linear-gradient(to bottom, #23477d, #142948);
        border-color: #122542;
        color: #fff;
    }

    .btn-action {
        padding: 4px 8px !important;
        font-size: 10px !important;
        border-radius: 3px;
        margin: 0 2px;
        display: inline-block;
        text-decoration: none;
        white-space: nowrap;
    }

    .btn-cetak-shipping {
        background: #17a2b8 !important;
        color: #fff !important;
        border: none;
    }

    .btn-cetak-shipping:hover {
        background: #138496 !important;
        color: #fff !important;
        transform: translateY(-1px);
    }

    .table-wrapper-shipping {
        max-height: 550px;
        overflow: auto;
        border: 1px solid #dee2e6;
        background: #fff;
    }

    .table-crystal {
        font-size: 11px;
        border-collapse: separate;
        border-spacing: 0;
        width: max-content;
        min-width: 100%;
        margin-bottom: 0;
    }

    .table-crystal th,
    .table-crystal td {
        padding: 5px 6px;
        border-right: 1px solid #dee2e6;
        border-bottom: 1px solid #dee2e6;
        white-space: nowrap;
        vertical-align: middle;
        background: #fff;
    }

    .table-crystal th {
        background: #e9ecef;
        font-weight: 700;
        color: #333;
        position: sticky;
        top: 0;
        z-index: 5;
    }

    .table-crystal tbody tr:hover td {
        background: #f8fbff;
    }

    .sticky-col-aksi {
        position: sticky;
        left: 0;
        z-index: 6;
        min-width: 135px;
        width: 135px;
        max-width: 135px;
        background: #fff !important;
    }

    .table-crystal th.sticky-col-aksi {
        background: #e9ecef !important;
        z-index: 8;
    }

    .sticky-col-shipping {
        position: sticky;
        left: 135px;
        z-index: 6;
        min-width: 130px;
        width: 130px;
        max-width: 130px;
        background: #fff !important;
        font-weight: 700;
    }

    .table-crystal th.sticky-col-shipping {
        background: #e9ecef !important;
        z-index: 8;
    }

    .badge-status {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 10px;
        font-weight: 700;
        min-width: 52px;
        text-align: center;
    }

    .badge-open {
        background: #28a745;
        color: #fff;
    }

    .badge-close {
        background: #dc3545;
        color: #fff;
    }

    .badge-pending {
        background: #ffc107;
        color: #000;
    }

    .approval-select {
        padding: 4px 8px;
        border-radius: 4px;
        border: 1px solid #ddd;
        font-size: 11px;
        cursor: pointer;
        min-width: 90px;
    }
</style>

<div class="d-print-none">
    <div class="crystal-header mb-3">
        <h5 class="m-0"><i class="fa fa-truck"></i> Shipping</h5>
    </div>

    <?php if (!empty($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <!-- FILTER PANEL -->
    <div class="filter-box">
        <form method="GET" action="index.php" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="shipping">

            <div class="col-md-2">
                <label class="form-label fw-bold small">Start Date</label>
                <input
                    type="text"
                    name="start_date"
                    class="form-control form-control-sm datepicker"
                    value="<?= e($start_date_raw) ?>"
                    autocomplete="off"
                >
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold small">End Date</label>
                <input
                    type="text"
                    name="end_date"
                    class="form-control form-control-sm datepicker"
                    value="<?= e($end_date_raw) ?>"
                    autocomplete="off"
                >
            </div>

            <div class="col-md-1">
                <label class="form-label fw-bold small">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="Open" <?= $status === 'Open' ? 'selected' : '' ?>>Open</option>
                    <option value="Close" <?= ($status === 'Close' || $status === 'Closed') ? 'selected' : '' ?>>Close</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold small">Approval Status</label>
                <select name="approval_status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="Pending" <?= $approval_status === 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Approve" <?= $approval_status === 'Approve' ? 'selected' : '' ?>>Approve</option>
                    <option value="Reject" <?= $approval_status === 'Reject' ? 'selected' : '' ?>>Reject</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold small">Shipping ID</label>
                <input
                    type="text"
                    name="shipping_id"
                    class="form-control form-control-sm"
                    placeholder="Search Shipping..."
                    value="<?= e($shipping_id) ?>"
                >
            </div>

            <div class="col-md-1"></div>

            <div class="col-md-2">
                <button type="submit" class="btn btn-vb-primary btn-sm px-3 fw-bold shadow-sm w-100 mb-2">
                    <i class="fa fa-search"></i> Search
                </button>

                <button
                    type="button"
                    class="btn btn-vb-primary btn-sm px-3 fw-bold shadow-sm w-100"
                    onclick="window.location.href='index.php?page=add_shipping'"
                >
                    <i class="fa fa-plus-circle"></i> Create New Shipping
                </button>
            </div>
        </form>
    </div>
</div>

<!-- TABLE HEADER -->
<div class="table-wrapper-shipping">
    <table class="table-crystal table-hover">
        <thead>
            <tr>
                <th class="sticky-col-aksi">Aksi</th>
                <th class="sticky-col-shipping">Shipping No</th>
                <th>Shipping Date</th>
                <th>Order No</th>
                <th>Order Date</th>
                <th>Customer ID</th>
                <th>Customer Name</th>
                <th>Customer Address</th>
                <th>Customer City</th>
                <th>SOP ID</th>
                <th>SOP Date</th>
                <th>Shipment Location</th>
                <th>Gudang</th>
                <th>Total Item</th>
                <th>Remarks Shipping</th>
                <th>Nota Date</th>
                <th>Status</th>
                <th>Approval</th>
                <th>User Created</th>
                <th>Date Created</th>
                <th>User Modified</th>
                <th>Date Modified</th>
            </tr>
        </thead>
        <tbody>
            <?php if (mysqli_num_rows($query) == 0): ?>
                <tr><td colspan="22" class="text-center py-3">Tidak ada data Shipping ditemukan.</td></tr>
            <?php else: ?>
                <?php while ($row = mysqli_fetch_assoc($query)): ?>
                    <?php
                    $statusValue = $row['status'] ?? 'Open';
                    $status_class = (strtolower($statusValue) === 'open') ? 'badge-open' : 'badge-close';

                    $shipping_date_formatted = formatDateIndonesian($row['shipping_date'] ?? '');
                    $order_date_formatted = formatDateIndonesian($row['order_date'] ?? '');
                    $sop_date_formatted = formatDateIndonesian($row['sop_date'] ?? '');
                    $nota_date_formatted = formatDateIndonesian($row['nota_date'] ?? '');
                    $date_created_formatted = formatDateIndonesian($row['date_created'] ?? '');
                    $date_modified_formatted = formatDateIndonesian($row['date_modified'] ?? '');

                    $shippingNoUrl = urlencode($row['shipping_no'] ?? '');
                    $shippingNoJs = e($row['shipping_no'] ?? '');
                    ?>
                    <tr>
                        <td class="sticky-col-aksi">
                            <a href="index.php?page=edit_shipping&id=<?= $shippingNoUrl ?>" class="btn btn-sm btn-warning" style="padding: 2px 6px;" title="Edit">
                                <i class="fa fa-edit"></i>
                            </a>

                            <a href="javascript:void(0)" onclick="confirmDelete('<?= $shippingNoJs ?>')" class="btn btn-sm btn-danger" style="padding: 2px 6px;" title="Hapus">
                                <i class="fa fa-trash"></i>
                            </a>

                            <a href="index.php?page=cetak_shipping&id=<?= $shippingNoUrl ?>&type=slip" target="_blank" class="btn-action btn-cetak-shipping" title="Cetak">
                                <i class="fa fa-print"></i> Cetak
                            </a>
                        </td>
                        <td class="sticky-col-shipping"><?= e($row['shipping_no']) ?></td>
                        <td><?= e($shipping_date_formatted) ?></td>
                        <td><?= e($row['order_no']) ?></td>
                        <td><?= e($order_date_formatted) ?></td>
                        <td><?= e($row['customer_id']) ?></td>
                        <td><?= e($row['customer_name']) ?></td>
                        <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;" title="<?= e($row['customer_address']) ?>"><?= e($row['customer_address']) ?></td>
                        <td><?= e($row['customer_city']) ?></td>
                        <td><?= e($row['sop_id']) ?></td>
                        <td><?= e($sop_date_formatted) ?></td>
                        <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;" title="<?= e($row['shipment_location']) ?>"><?= e($row['shipment_location']) ?></td>
                        <td><?= e($row['gudang_name']) ?></td>
                        <td class="text-center"><?= e($row['total_detail']) ?></td>
                        <td style="max-width: 100px; overflow: hidden; text-overflow: ellipsis;" title="<?= e($row['remarks_shipping']) ?>"><?= e($row['remarks_shipping']) ?></td>
                        <td><?= e($nota_date_formatted) ?></td>
                        <td class="text-center">
                            <span class="badge-status <?= e($status_class) ?>"><?= e($statusValue) ?></span>
                        </td>
                        <td class="text-center">
                            <select class="approval-select" data-shipping="<?= e($row['shipping_no']) ?>">
                                <option value="Pending" <?= ($row['approval_status'] ?? '') === 'Pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="Reject" <?= ($row['approval_status'] ?? '') === 'Reject' ? 'selected' : '' ?>>Reject</option>
                                <option value="Approve" <?= ($row['approval_status'] ?? '') === 'Approve' ? 'selected' : '' ?>>Approve</option>
                            </select>
                        </td>
                        <td><?= e($row['create_user']) ?></td>
                        <td><?= e($date_created_formatted) ?></td>
                        <td><?= e($row['user_modified']) ?></td>
                        <td><?= e($date_modified_formatted) ?></td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function confirmDelete(shippingNo) {
    if (confirm('Yakin ingin menghapus Shipping ' + shippingNo + '?')) {
        window.location.href = 'index.php?page=delete_shipping&id=' + encodeURIComponent(shippingNo);
    }
}

$(document).on('change', '.approval-select', function() {
    var select = $(this);
    var shippingNo = select.data('shipping');
    var newStatus = select.val();
    var oldStatus = select.data('old-value') || select.val();

    if (!confirm('Ubah status approval untuk Shipping ' + shippingNo + ' menjadi ' + newStatus + '?')) {
        select.val(oldStatus);
        updateSelectStyle(select, oldStatus);
        return;
    }

    select.prop('disabled', true);

    $.ajax({
        url: 'modul/transaksi/update_shipping_approval.php',
        type: 'POST',
        data: {
            shipping_no: shippingNo,
            approval_status: newStatus
        },
        dataType: 'json',
        success: function(response) {
            if (response && response.success) {
                select.data('old-value', newStatus);
                updateSelectStyle(select, newStatus);
                showNotification('Status berhasil diubah menjadi ' + newStatus, 'success');
            } else {
                alert('Gagal: ' + ((response && response.message) ? response.message : 'Response tidak valid'));
                select.val(oldStatus);
                updateSelectStyle(select, oldStatus);
            }
        },
        error: function(xhr, status, error) {
            alert('Terjadi kesalahan: ' + error);
            select.val(oldStatus);
            updateSelectStyle(select, oldStatus);
        },
        complete: function() {
            select.prop('disabled', false);
        }
    });
});

function updateSelectStyle(select, status) {
    if (status === 'Approve') {
        select.css('background', '#28a745').css('color', '#fff');
    } else if (status === 'Reject') {
        select.css('background', '#dc3545').css('color', '#fff');
    } else {
        select.css('background', '#ffc107').css('color', '#000');
    }
}

function showNotification(message, type) {
    var notification = $('<div class="notification">' + message + '</div>');
    var bgColor = type === 'success' ? '#28a745' : '#dc3545';

    notification.css({
        position: 'fixed',
        top: '20px',
        right: '20px',
        padding: '12px 20px',
        background: bgColor,
        color: '#fff',
        borderRadius: '5px',
        zIndex: 9999,
        fontSize: '12px',
        boxShadow: '0 2px 5px rgba(0,0,0,0.2)'
    });

    $('body').append(notification);

    setTimeout(function() {
        notification.fadeOut(500, function() { $(this).remove(); });
    }, 3000);
}

$(document).ready(function() {
    $('.approval-select').each(function() {
        var select = $(this);
        updateSelectStyle(select, select.val());
        select.data('old-value', select.val());
    });

    if ($.fn.flatpickr) {
        $(".datepicker").flatpickr({
            dateFormat: "d-M-Y",
            altFormat: "d-M-Y",
            allowInput: true
        });
    }
});
</script>
