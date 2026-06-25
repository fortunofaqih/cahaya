<?php
// modul/transaksi/shipping.php

if (!isset($_SESSION['username'])) {
    header("Location: ../../login.php");
    exit;
}

include __DIR__ . '/../../koneksi.php';

// =============================================
// FILTER PENCARIAN - DEFAULT HARI INI
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
$shipping_id = isset($_GET['shipping_id']) ? mysqli_real_escape_string($conn, trim($_GET['shipping_id'])) : '';

$start_date_safe = mysqli_real_escape_string($conn, $start_date_sql);
$end_date_safe = mysqli_real_escape_string($conn, $end_date_sql);

// =============================================
// BUILD WHERE CLAUSE
// =============================================
$where = "WHERE 1=1";

// Pakai DATE() supaya aman jika shipping_date bertipe DATETIME
$where .= " AND DATE(h.shipping_date) BETWEEN '$start_date_safe' AND '$end_date_safe'";

if ($status !== '') {
    $where .= " AND h.status = '$status'";
}

if ($approval_status !== '') {
    $where .= " AND h.approval_status = '$approval_status'";
}

if ($shipping_id !== '') {
    $where .= " AND h.shipping_no LIKE '%$shipping_id%'";
}

// =============================================
// QUERY UTAMA (JOIN dengan m_gudang)
// =============================================
$query = mysqli_query($conn, "
    SELECT h.*,
           g.name AS gudang_name
    FROM hed_shipping h
    LEFT JOIN m_gudang g ON h.gudang_id = g.gudang_id
    $where
    ORDER BY h.shipping_date DESC, h.shipping_no DESC
");

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
               
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label fw-bold small">Shipping ID</label>
                <input 
                    type="text" 
                    name="shipping_id" 
                    class="form-control form-control-sm" 
                    placeholder="Search Shipping..." 
                    value="<?= htmlspecialchars($shipping_id) ?>"
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
                <th>Transporter</th>
                <th>Driver Name</th>
                <th>Truck No</th>
                <th>Gudang</th>
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
            <?php
            if (mysqli_num_rows($query) == 0) {
                echo "<tr><td colspan='24' class='text-center py-3'>Tidak ada data Shipping ditemukan.</td></tr>";
            } else {
                while ($row = mysqli_fetch_assoc($query)) {
                    $status_class = $row['status'] == 'Open' ? 'badge-open' : 'badge-close';
                    $approval_class = $row['approval_status'] == 'Approve' ? 'badge-approve' : ($row['approval_status'] == 'Reject' );
                    
                    // Gunakan fungsi format tanggal
                    $shipping_date_formatted = formatDateIndonesian($row['shipping_date']);
                    $order_date_formatted = formatDateIndonesian($row['order_date']);
                    $sop_date_formatted = formatDateIndonesian($row['sop_date']);
                    $nota_date_formatted = formatDateIndonesian($row['nota_date']);
                    $date_created_formatted = formatDateIndonesian($row['date_created']);
                    $date_modified_formatted = formatDateIndonesian($row['date_modified']);
            ?>
            <tr>
                <td class="sticky-col-aksi">
                    <a href="index.php?page=edit_shipping&id=<?= $row['shipping_no'] ?>" class="btn btn-sm btn-warning" style="padding: 2px 6px;"><i class="fa fa-edit"></i></a>
                    
                    <a href="javascript:void(0)" onclick="confirmDelete('<?= $row['shipping_no'] ?>')" class="btn btn-sm btn-danger" style="padding: 2px 6px;">
                        <i class="fa fa-trash"></i>
                    </a>
    
                    <a href="index.php?page=cetak_shipping&id=<?= urlencode($row['shipping_no']) ?>&type=slip" target="_blank" class="btn-action btn-cetak-shipping">
                        <i class="fa fa-print"></i> Cetak
                    </a>
                </td>
                <td class="sticky-col-shipping"><?= $row['shipping_no'] ?></td>
                <td><?= $shipping_date_formatted ?></td>
                <td><?= $row['order_no'] ?></td>
                <td><?= $order_date_formatted ?></td>
                <td><?= $row['customer_id'] ?></td>
                <td><?= $row['customer_name'] ?></td>
                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;"><?= $row['customer_address'] ?></td>
                <td><?= $row['customer_city'] ?></td>
                <td><?= $row['sop_id'] ?></td>
                <td><?= $sop_date_formatted ?></td>
                <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;"><?= $row['shipment_location'] ?></td>
                <td><?= $row['transporter'] ?></td>
                <td><?= $row['driver_name'] ?></td>
                <td><?= $row['truck_no'] ?></td>
                <td><?= $row['gudang_name'] ?></td>
                <td style="max-width: 100px; overflow: hidden; text-overflow: ellipsis;"><?= $row['remarks_shipping'] ?></td>
                <td><?= $nota_date_formatted ?></td>
                <td class="text-center"><span class="<?= $status_class ?>"><?= $row['status'] ?></span></td>
                <td class="text-center">
                    <select class="approval-select" data-shipping="<?= $row['shipping_no'] ?>" style="padding: 4px 8px; border-radius: 4px; border: 1px solid #ddd; font-size: 11px; cursor: pointer;">
                        <option value="Reject" <?= $row['approval_status'] == 'Reject' ? 'selected' : '' ?> style="background: #dc3545; color: #fff;">Reject</option>
                        <option value="Approve" <?= $row['approval_status'] == 'Approve' ? 'selected' : '' ?> style="background: #28a745; color: #fff;">Approve</option>
                    </select>
                </td>
                <td><?= $row['create_user'] ?></td>
                <td><?= $date_created_formatted ?></td>
                <td><?= $row['user_modified'] ?></td>
                <td><?= $date_modified_formatted ?></td>
            </tr>
            <?php } } ?>
        </tbody>
    </table>
</div>

<script>
function confirmDelete(shippingNo) {
    if (confirm('Yakin ingin menghapus Shipping ' + shippingNo + '?')) {
        window.location.href = 'index.php?page=delete_shipping&id=' + shippingNo;
    }
}

// Update approval status via AJAX
$(document).on('change', '.approval-select', function() {
    var select = $(this);
    var shippingNo = select.data('shipping');
    var newStatus = select.val();
    var oldStatus = select.data('old-value') || select.find('option:selected').text();
    
    // Konfirmasi
    if (!confirm('Ubah status approval untuk Shipping ' + shippingNo + ' menjadi ' + newStatus + '?')) {
        select.val(oldStatus);
        return;
    }
    
    // Simpan nilai lama
    select.data('old-value', newStatus);
    
    // Disable dropdown sambil proses
    select.prop('disabled', true);
    
    // Kirim AJAX
    $.ajax({
        url: 'modul/transaksi/update_shipping_approval.php',
        type: 'POST',
        data: {
            shipping_no: shippingNo,
            approval_status: newStatus
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                // Update style warna dropdown
                updateSelectStyle(select, newStatus);
                
                // Notifikasi sukses
                showNotification('Status berhasil diubah menjadi ' + newStatus, 'success');
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
    
    // Hapus detail terlebih dahulu (karena foreign key)
    mysqli_query($conn, "DELETE FROM det_shipping WHERE shipping_no='$id'");
    mysqli_query($conn, "DELETE FROM hed_shipping WHERE shipping_no='$id'");
    
    echo "<script>
        showNotification('Shipping $id berhasil dihapus', 'success');
        setTimeout(function() {
            window.location.href='index.php?page=shipping';
        }, 1000);
    </script>";
    exit;
}
?>