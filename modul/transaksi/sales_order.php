<?php
// modul/transaksi/sales_order.php

if (!isset($_SESSION['username'])) {
    header("Location: ../../login.php");
    exit;
}

include __DIR__ . '/../../koneksi.php';

// =============================================
// FILTER PENCARIAN - DEFAULT HARI INI
// =============================================
// Di bagian atas, tambahkan variabel untuk menangkap filter customer
$customer_name_filter = isset($_GET['customer_name']) ? mysqli_real_escape_string($conn, trim($_GET['customer_name'])) : '';
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

    // Jika sudah format database: 2026-06-17
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    $months = [
        'Jan' => '01',
        'Feb' => '02',
        'Mar' => '03',
        'Apr' => '04',
        'May' => '05',
        'Mei' => '05',
        'Jun' => '06',
        'Jul' => '07',
        'Aug' => '08',
        'Agu' => '08',
        'Sep' => '09',
        'Oct' => '10',
        'Okt' => '10',
        'Nov' => '11',
        'Dec' => '12',
        'Des' => '12'
    ];

    // Format dari datepicker: 17-Jun-2026
    $parts = explode('-', $date);

    if (count($parts) === 3) {
        $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $monthText = $parts[1];
        $year = $parts[2];

        if (isset($months[$monthText])) {
            return $year . '-' . $months[$monthText] . '-' . $day;
        }
    }

    return '';
}

// Raw value untuk ditampilkan kembali di form
$start_date_raw = isset($_GET['start_date']) && trim($_GET['start_date']) !== ''
    ? trim($_GET['start_date'])
    : formatDateIndonesian(date('Y-m-d'));

$end_date_raw = isset($_GET['end_date']) && trim($_GET['end_date']) !== ''
    ? trim($_GET['end_date'])
    : formatDateIndonesian(date('Y-m-d'));

// Value SQL untuk query
$start_date_sql = convertFilterDateToMysql($start_date_raw);
$end_date_sql = convertFilterDateToMysql($end_date_raw);

// Jika gagal convert, fallback hari ini
if ($start_date_sql === '') {
    $start_date_sql = date('Y-m-d');
    $start_date_raw = formatDateIndonesian($start_date_sql);
}

if ($end_date_sql === '') {
    $end_date_sql = date('Y-m-d');
    $end_date_raw = formatDateIndonesian($end_date_sql);
}

// Validasi silang tanggal SQL
if ($start_date_sql > $end_date_sql) {
    $temp_sql = $start_date_sql;
    $start_date_sql = $end_date_sql;
    $end_date_sql = $temp_sql;

    $temp_raw = $start_date_raw;
    $start_date_raw = $end_date_raw;
    $end_date_raw = $temp_raw;
}

// Escape input
$status = isset($_GET['status']) ? mysqli_real_escape_string($conn, trim($_GET['status'])) : '';
$approval_status = isset($_GET['approval_status']) ? mysqli_real_escape_string($conn, trim($_GET['approval_status'])) : '';
$so_id = isset($_GET['so_id']) ? mysqli_real_escape_string($conn, trim($_GET['so_id'])) : '';
$export_checked = isset($_GET['export_checked']) ? mysqli_real_escape_string($conn, trim($_GET['export_checked'])) : '';
$show_checked = isset($_GET['show_checked']) ? mysqli_real_escape_string($conn, trim($_GET['show_checked'])) : '';

$start_date_safe = mysqli_real_escape_string($conn, $start_date_sql);
$end_date_safe = mysqli_real_escape_string($conn, $end_date_sql);

// =============================================
// BUILD WHERE CLAUSE
// =============================================
$where = "WHERE 1=1";

// Pakai DATE() supaya aman jika order_date bertipe DATETIME
$where .= " AND DATE(h.order_date) BETWEEN '$start_date_safe' AND '$end_date_safe'";

if ($status !== '') {
    $where .= " AND h.status = '$status'";
}

if ($approval_status !== '') {
    $where .= " AND h.approval_status = '$approval_status'";
}

if ($so_id !== '') {
    $where .= " AND h.order_no LIKE '%$so_id%'";
}

// TAMBAHAN: Filter berdasarkan Customer Name
if ($customer_name_filter !== '') {
    $where .= " AND h.customer_name LIKE '%$customer_name_filter%'";
}

if ($show_checked == 'on') {
    $where .= " AND h.export_flag = 'Checked'";
}

// =============================================
// QUERY UTAMA (sudah JOIN dengan m_marketing dan m_sales)
// =============================================
$query = mysqli_query($conn, "
    SELECT h.*,
           m.marketing_name,
           s.sales_name
    FROM head_sales_order h
    LEFT JOIN m_marketing m ON h.marketing_id = m.marketing_id
    LEFT JOIN m_sales s ON h.sales_id = s.sales_id
    $where
    ORDER BY h.order_date DESC, h.order_no DESC
");

if (!$query) {
    die("Query Sales Order Error: " . mysqli_error($conn));
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

    .btn-vs {
        padding: 8px 20px !important;
        font-size: 12px !important;
        font-weight: 600 !important;
        border-radius: 4px !important;
        transition: all 0.2s ease;
        margin-right: 5px;
    }

    .btn-vs i {
        margin-right: 6px;
    }

    .btn-excel {
        background: #1d6f42 !important;
        color: #fff !important;
        border: none;
    }

    .btn-excel:hover {
        background: #0f5a36 !important;
        color: #fff !important;
        transform: translateY(-1px);
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

    .btn-cetak-so {
        background: #17a2b8 !important;
        color: #fff !important;
        border: none;
    }

    .btn-cetak-so:hover {
        background: #138496 !important;
        color: #fff !important;
        transform: translateY(-1px);
    }

    .table-wrapper-so {
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

    .sticky-col-order {
        position: sticky;
        left: 135px;
        z-index: 6;
        min-width: 130px;
        width: 130px;
        max-width: 130px;
        background: #fff !important;
        font-weight: 700;
    }

    .table-crystal th.sticky-col-order {
        background: #e9ecef !important;
        z-index: 8;
    }

    .text-ellipsis-100 {
        max-width: 100px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .text-ellipsis-120 {
        max-width: 120px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .text-ellipsis-150 {
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .badge-open,
    .badge-close,
    .badge-approve,
    .badge-reject,
    .badge-pending {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 9px;
        font-weight: 600;
        line-height: 1.4;
    }

    .badge-open {
        background: #28a745;
        color: #fff;
    }

    .badge-close {
        background: #dc3545;
        color: #fff;
    }

    .badge-approve {
        background: #17a2b8;
        color: #fff;
    }

    .badge-reject {
        background: #fd7e14;
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
    .btn-secondary {
    background: #6c757d !important;
    color: #fff !important;
    border: 1px solid #6c757d !important;
    }

    .btn-secondary:hover {
        background: #5a6268 !important;
        color: #fff !important;
        transform: translateY(-1px);
    }
</style>

<div class="d-print-none">
    <div class="crystal-header mb-3">
        <h5 class="m-0"><i class="fa fa-file-invoice"></i> Sales Order (SO)</h5>
    </div>
    
  <!-- FILTER PANEL -->
<div class="filter-box">
    <form method="GET" action="index.php" class="row g-2 align-items-end" id="filterForm">
        <input type="hidden" name="page" value="sales_order">

        <div class="col-md-2">
            <label class="form-label fw-bold small">Start Date</label>
            <input 
                type="text" 
                name="start_date" 
                class="form-control form-control-sm datepicker" 
                value="<?= htmlspecialchars($start_date_raw) ?>"
                autocomplete="off"
            >
        </div>

        <div class="col-md-2">
            <label class="form-label fw-bold small">End Date</label>
            <input 
                type="text" 
                name="end_date" 
                class="form-control form-control-sm datepicker" 
                value="<?= htmlspecialchars($end_date_raw) ?>"
                autocomplete="off"
            >
        </div>

        <div class="col-md-1">
            <label class="form-label fw-bold small">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="Open" <?= $status == 'Open' ? 'selected' : '' ?>>Open</option>
                <option value="Close" <?= $status == 'Close' ? 'selected' : '' ?>>Close</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label fw-bold small">Approval Status</label>
            <select name="approval_status" class="form-select form-select-sm">
                <option value="">All</option>
                <option value="Approve" <?= $approval_status == 'Approve' ? 'selected' : '' ?>>Approve</option>
                <option value="Reject" <?= $approval_status == 'Reject' ? 'selected' : '' ?>>Reject</option>
                <option value="Pending" <?= $approval_status == 'Pending' ? 'selected' : '' ?>>Pending</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label fw-bold small">SO ID</label>
            <input 
                type="text" 
                name="so_id" 
                class="form-control form-control-sm" 
                placeholder="Search SO..." 
                value="<?= htmlspecialchars($so_id) ?>"
            >
        </div>

        <!-- Filter Customer Name -->
        <div class="col-md-2">
            <label class="form-label fw-bold small">Customer Name</label>
            <input 
                type="text" 
                name="customer_name" 
                class="form-control form-control-sm" 
                placeholder="Search Customer..." 
                value="<?= htmlspecialchars($customer_name_filter) ?>"
            >
        </div>

        <!-- Tombol Aksi -->
        <div class="col-md-3">
            <div class="row g-1">
                <div class="col-6">
                    <button type="submit" class="btn btn-vb-primary btn-sm px-3 fw-bold shadow-sm w-100 mb-2">
                        <i class="fa fa-search"></i> Search
                    </button>
                </div>
                <div class="col-6">
                    <!-- TAMBAHAN: Tombol Reset Filter -->
                    <button type="button" class="btn btn-secondary btn-sm px-3 fw-bold shadow-sm w-100 mb-2" onclick="resetFilter()">
                        <i class="fa fa-undo"></i> Reset
                    </button>
                </div>
                <div class="col-12">
                    <button 
                        type="button"
                        class="btn btn-vb-primary btn-sm px-3 fw-bold shadow-sm w-100"
                        onclick="window.location.href='index.php?page=add_sales_order'"
                    >
                        <i class="fa fa-plus-circle"></i> Create New SO
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>
    
    <!-- ACTION BUTTONS -->
    <div class="mb-3 d-flex justify-content-between">
        <div>
           <button class="btn-vs btn-excel" onclick="window.location.href='modul/transaksi/export_sales_order.php?start_date=<?= urlencode($start_date_raw) ?>&end_date=<?= urlencode($end_date_raw) ?>&status=<?= urlencode($status) ?>&approval_status=<?= urlencode($approval_status) ?>&so_id=<?= urlencode($so_id) ?>'">
            <i class="fa fa-file-excel-o"></i> Export to Excel
        </button>
         
        </div>
        
    </div>
</div>

<!-- TABLE HEADER -->
<div class="table-wrapper-so">
    <table class="table-crystal table-hover">
        <thead>
            <tr>
                <th class="sticky-col-aksi">Aksi</th>
                <th class="sticky-col-order">Order No</th>
                <th>Order Date</th>
                
                <th>Marketing</th>
                <th>Sales</th>
                <th>Customer ID</th>
                <th>Customer Name</th>
                <th>Customer Address</th>
                <th>Customer City</th>
                <th>Station</th>
                <th>Shipment Due Date</th>
                <th>Shipment Location</th>
                <th>Tolerance</th>
                <th>Payment Type</th>
                <th>Payment Term</th>
                <th>Currency</th>
                <th>Kurs</th>
                <th>Grand Total</th>
                <th>Down Payment</th>
                <th>Status</th>
                <th>Approval</th>
                <th>Remarks</th>
                <th>User Created</th>
                <th>Date Created</th>
                <th>User Modified</th>
                <th>Date Modified</th>
            </tr>
        </thead>
        <tbody>
            <?php
            if (mysqli_num_rows($query) == 0) {
                echo "<tr><td colspan='29' class='text-center py-3'>Tidak ada data Sales Order ditemukan.</td></tr>";
            } else {
                while ($row = mysqli_fetch_assoc($query)) {
    $status_class = $row['status'] == 'Open' ? 'badge-open' : 'badge-close';
    $approval_class = $row['approval_status'] == 'Approve' ? 'badge-approve' : ($row['approval_status'] == 'Reject' ? 'badge-reject' : 'badge-pending');
    
    // Gunakan fungsi format tanggal
    $order_date_formatted = formatDateIndonesian($row['order_date']);
    $shipment_due_formatted = formatDateIndonesian($row['shipment_due_date']);
    $date_created_formatted = formatDateIndonesian($row['date_created']);
    $date_modified_formatted = formatDateIndonesian($row['date_modified']);
?>
<tr>
    <td class="sticky-col-aksi">
        <a href="index.php?page=edit_sales_order&id=<?= $row['order_no'] ?>" class="btn btn-sm btn-warning" style="padding: 2px 6px;"><i class="fa fa-edit"></i></a>
        <a href="javascript:void(0)" onclick="confirmDelete('<?= $row['order_no'] ?>')" class="btn btn-sm btn-danger" style="padding: 2px 6px;"><i class="fa fa-trash"></i></a>
        <a href="modul/transaksi/cetak_sales_order.php?no_so=<?= urlencode($row['order_no']) ?>" target="_blank" class="btn-action btn-cetak-so"><i class="fa fa-print"></i> Cetak</a>
    </td>
    <td class="sticky-col-order"><?= $row['order_no'] ?></td>
    <td><?= $order_date_formatted ?></td>  <!-- FORMAT BARU -->
    <td><?= $row['marketing_name'] ?></td>
    <td><?= $row['sales_name'] ?></td>
    <td><?= $row['customer_id'] ?></td>
    <td><?= $row['customer_name'] ?></td>
    <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;"><?= $row['customer_address'] ?></td>
    <td><?= $row['customer_city'] ?></td>
    <td><?= $row['station'] ?></td>
    <td><?= $shipment_due_formatted ?></td>  <!-- FORMAT BARU -->
    <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;"><?= $row['shipment_location'] ?></td>
    <td class="text-end"><?= number_format($row['tolerance'], 2) ?>%</td>
    <td><?= $row['payment_type'] ?></td>
    <td><?= $row['payment_term'] ?></td>
    <td><?= $row['currency'] ?></td>
    <td class="text-end">1</td>
    <td class="text-end fw-bold text-primary"><?= number_format($row['grand_total'], 2) ?></td>
    <td class="text-end"><?= number_format($row['down_payment'], 2) ?></td>
    <td class="text-center"><span class="<?= $status_class ?>"><?= $row['status'] ?></span></td>
    <td class="text-center">
        <select class="approval-select" data-order="<?= $row['order_no'] ?>" style="padding: 4px 8px; border-radius: 4px; border: 1px solid #ddd; font-size: 11px; cursor: pointer;">
            <option value="Reject" <?= $row['approval_status'] == 'Reject' ? 'selected' : '' ?> style="background: #dc3545; color: #fff;">Reject</option>
            <option value="Approve" <?= $row['approval_status'] == 'Approve' ? 'selected' : '' ?> style="background: #28a745; color: #fff;">Approve</option>
            
        </select>
    </td>
    <td style="max-width: 100px; overflow: hidden; text-overflow: ellipsis;"><?= $row['remarks'] ?></td>
    <td><?= $row['create_user'] ?></td>
    <td><?= $date_created_formatted ?></td>  <!-- FORMAT BARU -->
    <td><?= $row['user_modified'] ?></td>
    <td><?= $date_modified_formatted ?></td>  <!-- FORMAT BARU -->
</tr>
<?php } }?>
        </tbody>
    </table>
</div>

<script>
function confirmDelete(orderNo) {
    if (confirm('Yakin ingin menghapus Sales Order ' + orderNo + '?')) {
        window.location.href = 'index.php?page=sales_order&action=delete&id=' + orderNo;
    }
}
// Fungsi Reset Filter
function resetFilter() {
    // Reset semua input text
    document.querySelectorAll('#filterForm input[type="text"]').forEach(function(input) {
        input.value = '';
    });
    
    // Reset semua select
    document.querySelectorAll('#filterForm select').forEach(function(select) {
        select.value = '';
    });
    
    // Set date ke hari ini
    var today = new Date();
    var day = String(today.getDate()).padStart(2, '0');
    var monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    var month = monthNames[today.getMonth()];
    var year = today.getFullYear();
    var dateString = day + '-' + month + '-' + year;
    
    // Set tanggal start dan end ke hari ini
    var dateInputs = document.querySelectorAll('#filterForm .datepicker');
    if (dateInputs.length >= 2) {
        dateInputs[0].value = dateString;
        dateInputs[1].value = dateString;
    }
    
    // Submit form
    document.getElementById('filterForm').submit();
}
// Fungsi untuk update approval status via AJAX
// Update approval status via AJAX
$(document).on('change', '.approval-select', function() {
    var select = $(this);
    var orderNo = select.data('order');
    var newStatus = select.val();
    var oldStatus = select.data('old-value') || select.find('option:selected').text();
    
    // Konfirmasi
    if (!confirm('Ubah status approval untuk SO ' + orderNo + ' menjadi ' + newStatus + '?')) {
        select.val(oldStatus);
        return;
    }
    
    // Simpan nilai lama
    select.data('old-value', newStatus);
    
    // Disable dropdown sambil proses
    select.prop('disabled', true);
    
    // Kirim AJAX
    $.ajax({
        url: 'modul/transaksi/update_approval.php',
        type: 'POST',
        data: {
            order_no: orderNo,
            approval_status: newStatus
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Update style warna dropdown
                updateSelectStyle(select, newStatus);
                
                // Notifikasi sukses
                showNotification('Status berhasil diubah menjadi ' + newStatus, 'success');
                
                // Optional: refresh halaman setelah 1 detik
                // setTimeout(function() { location.reload(); }, 1000);
            } else {
                alert('Gagal: ' + response.message);
                select.val(oldStatus);
            }
        },
        error: function(xhr, status, error) {
            alert('Terjadi kesalahan: ' + error);
            select.val(oldStatus);
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

// Inisialisasi style saat halaman dimuat
$(document).ready(function() {
    $('.approval-select').each(function() {
        var select = $(this);
        updateSelectStyle(select, select.val());
        select.data('old-value', select.val());
    });
});
$(document).ready(function() {
    // Inisialisasi datepicker dengan format d-M-Y
    $(".datepicker").flatpickr({
        dateFormat: "d-M-Y",
        altFormat: "d-M-Y",
        allowInput: true
    });
});
</script>

<?php
// Handle delete
if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $id = mysqli_real_escape_string($conn, $_GET['id']);
    mysqli_query($conn, "DELETE FROM detail_sales_order WHERE order_no='$id'");
    mysqli_query($conn, "DELETE FROM head_sales_order WHERE order_no='$id'");
    echo "<script>window.location.href='index.php?page=sales_order';</script>";
    exit;
}?>
