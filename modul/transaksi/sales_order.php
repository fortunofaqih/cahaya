<?php
// modul/transaksi/sales_order.php

if (!isset($_SESSION['username'])) {
    header("Location: ../../login.php");
    exit;
}

include __DIR__ . '/../../koneksi.php';

// Filter pencarian
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$status = isset($_GET['status']) ? $_GET['status'] : '';
$approval_status = isset($_GET['approval_status']) ? $_GET['approval_status'] : '';
$so_id = isset($_GET['so_id']) ? $_GET['so_id'] : '';
$export_checked = isset($_GET['export_checked']) ? $_GET['export_checked'] : '';
$show_checked = isset($_GET['show_checked']) ? $_GET['show_checked'] : '';

// Build WHERE clause
$where = "WHERE 1=1";
if ($start_date && $end_date) {
    $where .= " AND order_date BETWEEN '$start_date' AND '$end_date'";
}
if ($status) {
    $where .= " AND status = '$status'";
}
if ($approval_status) {
    $where .= " AND approval_status = '$approval_status'";
}
if ($so_id) {
    $where .= " AND order_no LIKE '%$so_id%'";
}
if ($show_checked == 'on') {
    $where .= " AND export_flag = 'Checked'";
}

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
?>

<style>
    /* Crystal Report Style */
    .crystal-header {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        color: white;
        padding: 8px 15px;
        border-radius: 5px 5px 0 0;
        font-weight: bold;
    }
    .panel-crystal {
        border: 1px solid #dee2e6;
        border-radius: 5px;
        margin-bottom: 15px;
        background: white;
    }
    .panel-title {
        background: #f0f4f8;
        padding: 8px 12px;
        font-weight: bold;
        border-bottom: 1px solid #dee2e6;
        color: #2b4c7e;
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
    .btn-excel { background: #1d6f42 !important; color: white !important; border: none; }
    .btn-excel:hover { background: #0f5a36 !important; transform: translateY(-1px); }
    .btn-print { background: #6c757d !important; color: white !important; border: none; }
    .btn-print:hover { background: #5a6268 !important; transform: translateY(-1px); }
    .btn-add { background: #0d6efd !important; color: white !important; border: none; }
    .btn-add:hover { background: #0b5ed7 !important; transform: translateY(-1px); }
    .filter-box {
        background: #f8f9fa;
        padding: 12px;
        border-radius: 5px;
        margin-bottom: 15px;
        border: 1px solid #dee2e6;
    }
    .table-crystal {
        font-size: 11px;
        border-collapse: collapse;
        width: 100%;
    }
    .table-crystal th {
        background: #e9ecef;
        padding: 6px 4px;
        border: 1px solid #dee2e6;
        font-weight: bold;
        white-space: nowrap;
    }
    .table-crystal td {
        padding: 4px;
        border: 1px solid #dee2e6;
        white-space: nowrap;
    }
    .badge-open { background: #28a745; color: white; padding: 2px 8px; border-radius: 10px; font-size: 9px; }
    .badge-close { background: #dc3545; color: white; padding: 2px 8px; border-radius: 10px; font-size: 9px; }
    .badge-approve { background: #17a2b8; color: white; padding: 2px 8px; border-radius: 10px; font-size: 9px; }
    .badge-reject { background: #fd7e14; color: white; padding: 2px 8px; border-radius: 10px; font-size: 9px; }
    .badge-pending { background: #ffc107; color: #000; padding: 2px 8px; border-radius: 10px; font-size: 9px; }
    
    /* Style untuk tombol aksi */
    .btn-action {
        padding: 4px 8px !important;
        font-size: 10px !important;
        border-radius: 3px;
        margin: 0 2px;
        display: inline-block;
        text-decoration: none;
    }
    .btn-cetak-so { background: #17a2b8 !important; color: white !important; border: none; }
    .btn-cetak-so:hover { background: #138496 !important; transform: translateY(-1px); }
     /* Custom Button VB Style */
        .btn-vb { background: linear-gradient(to bottom, #ffffff, #e6e6e6); border: 1px solid #adadad; color: #333; }
        .btn-vb:hover { background: linear-gradient(to bottom, #e6e6e6, #cccccc); border-color: #adadad; color: #000; }
        .btn-vb-primary { background: linear-gradient(to bottom, #2b579a, #1e3d6b); border: 1px solid #183054; color: #fff; }
        .btn-vb-primary:hover { background: linear-gradient(to bottom, #23477d, #142948); border-color: #122542; color: #fff; }
        .btn-vb-success { background: linear-gradient(to bottom, #257b43, #19532d); border: 1px solid #123c20; color: #fff; }
        .btn-vb-success:hover { background: linear-gradient(to bottom, #1d6135, #113a1f); color: #fff; }
</style>

<div class="d-print-none">
    <div class="crystal-header mb-3">
        <h5 class="m-0"><i class="fa fa-file-invoice"></i> Sales Order (SO)</h5>
    </div>
    
    <!-- FILTER PANEL -->
    <div class="filter-box">
        <form method="GET" action="index.php" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="sales_order">
            <div class="col-md-2">
                <label class="form-label fw-bold small">Start Date</label>
                <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $start_date ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold small">End Date</label>
                <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $end_date ?>">
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
                <input type="text" name="so_id" class="form-control form-control-sm" placeholder="Search SO..." value="<?= $so_id ?>">
            </div>
            <div class="col-md-1">
                <!--<div class="form-check">
                    <input class="form-check-input" type="checkbox" name="export_checked" id="export_checked" <?= $export_checked == 'on' ? 'checked' : '' ?>>
                    <label class="form-check-label small">Export</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="show_checked" id="show_checked" <?= $show_checked == 'on' ? 'checked' : '' ?>>
                    <label class="form-check-label small">Show Checked</label>
                </div>-->
            </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-vb-primary btn-sm px-3 fw-bold shadow-sm w-100 mb-2">
                <i class="fa fa-search"></i> Search
            </button>

            <button 
                type="button"
                class="btn btn-vb-primary btn-sm px-3 fw-bold shadow-sm w-100"
                onclick="window.location.href='index.php?page=add_sales_order'"
            >
                <i class="fa fa-plus-circle"></i> Create New SO
            </button>
        </div>
        </form>
    </div>
    
    <!-- ACTION BUTTONS -->
    <div class="mb-3 d-flex justify-content-between">
        <div>
            <button class="btn-vs btn-excel" onclick="window.location.href='modul/transaksi/export_sales_order.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&status=<?= $status ?>&approval_status=<?= $approval_status ?>&so_id=<?= $so_id ?>'">
                <i class="fa fa-file-excel-o"></i> Export to Excel
            </button>
         
        </div>
        
    </div>
</div>

<!-- TABLE HEADER -->
<div class="table-responsive" style="max-height: 550px; overflow-y: auto;">
    <table class="table-crystal table-hover">
        <thead>
            <tr>
                <th style="position: sticky; left: 0; background: #e9ecef; z-index: 2;">Aksi</th>
                <th style="position: sticky; left: 133px; background: #e9ecef; z-index: 2;">Order No</th>
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
                    
                    // Get Marketing Name
                    $mkt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT marketing_name FROM m_marketing WHERE marketing_id='{$row['marketing_id']}'"));
                    $marketing_name = $mkt ? $mkt['marketing_name'] : '';
                    
                    // Get Sales Name
                    $sls = mysqli_fetch_assoc(mysqli_query($conn, "SELECT sales_name FROM m_sales WHERE sales_id='{$row['sales_id']}'"));
                    $sales_name = $sls ? $sls['sales_name'] : '';
            ?>
            <tr>
                <td style="position: sticky; left: 0; background: white; z-index: 1; white-space: nowrap;">
                    <a href="index.php?page=edit_sales_order&id=<?= $row['order_no'] ?>" class="btn btn-sm btn-warning" style="padding: 2px 6px;"><i class="fa fa-edit"></i></a>
                    <a href="javascript:void(0)" onclick="confirmDelete('<?= $row['order_no'] ?>')" class="btn btn-sm btn-danger" style="padding: 2px 6px;"><i class="fa fa-trash"></i></a>
                    <a href="modul/transaksi/cetak_sales_order.php?no_so=<?= urlencode($row['order_no']) ?>" target="_blank" class="btn-action btn-cetak-so"><i class="fa fa-print"></i> Cetak</a>
                </td>
                <td style="position: sticky; left: 133px; background: white; z-index: 1; font-weight: bold;"><?= $row['order_no'] ?></td>
                <td><?= date('d-m-Y', strtotime($row['order_date'])) ?></td>
                
                <td><?= $marketing_name ?></td>
                <td><?= $sales_name ?></td>
                <td><?= $row['customer_id'] ?></td>
                <td><?= $row['customer_name'] ?></td>
                <td style="max-width: 150px; overflow: hidden; text-overflow: ellipsis;"><?= $row['customer_address'] ?></td>
                <td><?= $row['customer_city'] ?></td>
                <td><?= $row['station'] ?></td>
                <td><?= $row['shipment_due_date'] ? date('d-m-Y', strtotime($row['shipment_due_date'])) : '' ?></td>
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
                        <option value="Pending" <?= $row['approval_status'] == 'Pending' ? 'selected' : '' ?> style="background: #ffc107; color: #000;">Pending</option>
                        <option value="Approve" <?= $row['approval_status'] == 'Approve' ? 'selected' : '' ?> style="background: #28a745; color: #fff;">Approve</option>
                        <option value="Reject" <?= $row['approval_status'] == 'Reject' ? 'selected' : '' ?> style="background: #dc3545; color: #fff;">Reject</option>
                    </select>
                </td>
                <td style="max-width: 100px; overflow: hidden; text-overflow: ellipsis;"><?= $row['remarks'] ?></td>
                <td><?= $row['create_user'] ?></td>
                <td><?= date('d-m-Y H:i', strtotime($row['date_created'])) ?></td>
                <td><?= $row['user_modified'] ?></td>
                <td><?= $row['date_modified'] ? date('d-m-Y H:i', strtotime($row['date_modified'])) : '' ?></td>
            </tr>
            <?php } } ?>
        </tbody>
    </table>
</div>

<script>
function confirmDelete(orderNo) {
    if (confirm('Yakin ingin menghapus Sales Order ' + orderNo + '?')) {
        window.location.href = 'index.php?page=sales_order&action=delete&id=' + orderNo;
    }
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
