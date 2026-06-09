<?php
// modul/transaksi/rekap_sales_order.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// Filter pencarian
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$customer_name = isset($_GET['customer_name']) ? $_GET['customer_name'] : '';
$sales_name = isset($_GET['sales_name']) ? $_GET['sales_name'] : '';
$marketing_name = isset($_GET['marketing_name']) ? $_GET['marketing_name'] : '';
$category = isset($_GET['category']) ? $_GET['category'] : '';
$approval_status = isset($_GET['approval_status']) ? $_GET['approval_status'] : '';

// Build WHERE clause untuk header
$where = "WHERE 1=1";
if ($start_date && $end_date) {
    $where .= " AND h.order_date BETWEEN '$start_date' AND '$end_date'";
}
if ($customer_name) {
    $where .= " AND h.customer_name LIKE '%$customer_name%'";
}
if ($approval_status) {
    $where .= " AND h.approval_status = '$approval_status'";
}

// Query untuk mendapatkan data sales order dengan detail
$query = mysqli_query($conn, "
    SELECT 
        h.order_no,
        h.order_date,
        h.customer_name,
        h.customer_address,
        h.customer_city,
        h.remarks,
        h.approval_status,
        m.marketing_name,
        s.sales_name,
        d.inventory_id,
        d.inventory_name,
        d.quantity,
        d.uom,
        d.quantity_pack,
        d.uom_pack,
        d.uom_detail,
        d.price_unit,
        d.price,
        d.subtotal
    FROM head_sales_order h
    LEFT JOIN m_marketing m ON h.marketing_id = m.marketing_id
    LEFT JOIN m_sales s ON h.sales_id = s.sales_id
    LEFT JOIN detail_sales_order d ON h.order_no = d.order_no
    $where
    ORDER BY h.order_date DESC, h.customer_name ASC, h.order_no ASC
");

// Group data by customer
$grouped_data = [];
while ($row = mysqli_fetch_assoc($query)) {
    $customer_key = $row['customer_name'];
    if (!isset($grouped_data[$customer_key])) {
        $grouped_data[$customer_key] = [
            'customer_name' => $row['customer_name'],
            'customer_address' => $row['customer_address'],
            'customer_city' => $row['customer_city'],
            'orders' => []
        ];
    }
    
    $order_key = $row['order_no'];
    if (!isset($grouped_data[$customer_key]['orders'][$order_key])) {
        $grouped_data[$customer_key]['orders'][$order_key] = [
            'order_no' => $row['order_no'],
            'order_date' => $row['order_date'],
            'marketing_name' => $row['marketing_name'],
            'sales_name' => $row['sales_name'],
            'remarks' => $row['remarks'],
            'approval_status' => $row['approval_status'],
            'items' => []
        ];
    }
    
    if ($row['inventory_id']) {
        $grouped_data[$customer_key]['orders'][$order_key]['items'][] = $row;
    }
}

// Hitung total keseluruhan
$grand_total = 0;
foreach ($grouped_data as $customer) {
    foreach ($customer['orders'] as $order) {
        foreach ($order['items'] as $item) {
            $grand_total += $item['subtotal'];
        }
    }
}

// Ambil data untuk dropdown filter
$sales_list = mysqli_query($conn, "SELECT sales_name FROM m_sales WHERE is_active='Checked' ORDER BY sales_name");
$marketing_list = mysqli_query($conn, "SELECT marketing_name FROM m_marketing WHERE is_active='Checked' ORDER BY marketing_name");
?>

<style>
    /* Style untuk halaman (layar) */
    .rekap-container {
        max-width: 100%;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        overflow: hidden;
        margin-top: 0;
    }
    
    .rekap-header {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        color: white;
        padding: 15px 20px;
    }
    
    .rekap-header h4 {
        margin: 0;
        font-size: 18px;
    }
    
    .filter-box {
        background: #f8f9fa;
        padding: 15px;
        border-bottom: 1px solid #dee2e6;
    }
    
    .customer-group {
        margin-bottom: 30px;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        overflow: hidden;
        page-break-inside: avoid;
    }
    
    .customer-header {
        background: #e9ecef;
        padding: 12px 15px;
        border-bottom: 2px solid #2a5298;
    }
    
    .customer-header h5 {
        margin: 0;
        font-size: 14px;
        font-weight: bold;
        color: #1e3c72;
    }
    
    .customer-header small {
        font-size: 11px;
        color: #6c757d;
    }
    
    .order-group {
        margin: 15px;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        overflow: hidden;
        page-break-inside: avoid;
    }
    
    .order-header {
        background: #f8f9fa;
        padding: 8px 12px;
        border-bottom: 1px solid #dee2e6;
        font-size: 12px;
        font-weight: bold;
    }
    
    .rekap-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
    }
    
    .rekap-table th,
    .rekap-table td {
        border: 1px solid #dee2e6;
        padding: 6px 4px;
        vertical-align: top;
    }
    
    .rekap-table th {
        background: #e9ecef;
        font-weight: bold;
        text-align: center;
    }
    
    .text-right {
        text-align: right;
    }
    
    .text-center {
        text-align: center;
    }
    
    .grand-total {
        background: #f8f9fa;
        padding: 10px 15px;
        text-align: right;
        font-weight: bold;
        border-top: 2px solid #dee2e6;
    }
    
    .btn-action {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 4px;
        margin: 0 2px;
    }
    
    /* Style untuk cetak A5 */
    @media print {
        body {
            background: white;
            padding: 0;
            margin: 0;
        }
        
        .no-print {
            display: none !important;
        }
        
        .rekap-container {
            box-shadow: none;
            border-radius: 0;
        }
        
        .customer-group {
            border: 1px solid #ccc;
            page-break-inside: avoid;
        }
        
        .rekap-table th,
        .rekap-table td {
            border: 1px solid #000;
        }
        
        @page {
            size: A5;
            margin: 10mm;
        }
    }
</style>
<div class="rekap-container">
    <div class="rekap-header no-print">
        <h4><i class="fa fa-file-alt"></i> Rekap Sales Order</h4>
    </div>
    
    <!-- FILTER PANEL (hanya tampil di layar) -->
    <div class="filter-box no-print">
        <form method="GET" action="index.php" class="row g-2">
            <input type="hidden" name="page" value="rekap_sales_order">
            <div class="col-md-2">
                <label class="form-label fw-bold small">Start Date</label>
                <input type="date" name="start_date" class="form-control form-control-sm" value="<?= $start_date ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold small">End Date</label>
                <input type="date" name="end_date" class="form-control form-control-sm" value="<?= $end_date ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold small">Customer Name</label>
                <input type="text" name="customer_name" class="form-control form-control-sm" placeholder="Cari customer..." value="<?= htmlspecialchars($customer_name) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold small">Sales</label>
                <select name="sales_name" class="form-select form-select-sm">
                    <option value="">-- Semua Sales --</option>
                    <?php while($s = mysqli_fetch_assoc($sales_list)): ?>
                        <option value="<?= htmlspecialchars($s['sales_name']) ?>" <?= $sales_name == $s['sales_name'] ? 'selected' : '' ?>><?= htmlspecialchars($s['sales_name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold small">Marketing</label>
                <select name="marketing_name" class="form-select form-select-sm">
                    <option value="">-- Semua Marketing --</option>
                    <?php while($m = mysqli_fetch_assoc($marketing_list)): ?>
                        <option value="<?= htmlspecialchars($m['marketing_name']) ?>" <?= $marketing_name == $m['marketing_name'] ? 'selected' : '' ?>><?= htmlspecialchars($m['marketing_name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label fw-bold small">Approval Status</label>
                <select name="approval_status" class="form-select form-select-sm">
                    <option value="">-- Semua Status --</option>
                    <option value="Pending" <?= $approval_status == 'Pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="Approve" <?= $approval_status == 'Approve' ? 'selected' : '' ?>>Approve</option>
                    <option value="Reject" <?= $approval_status == 'Reject' ? 'selected' : '' ?>>Reject</option>
                </select>
            </div>
            <div class="col-md-12 mt-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-search"></i> Tampilkan</button>
                <a href="modul/transaksi/cetak_rekap_sales_order.php?start_date=<?= $start_date ?>&end_date=<?= $end_date ?>&customer_name=<?= urlencode($customer_name) ?>&sales_name=<?= urlencode($sales_name) ?>&marketing_name=<?= urlencode($marketing_name) ?>&approval_status=<?= $approval_status ?>" target="_blank" class="btn btn-sm btn-success">
                    <i class="fa fa-print"></i> Cetak Rekap (A5)
                </a>
                <a href="index.php?page=sales_order" class="btn btn-sm btn-secondary"><i class="fa fa-arrow-left"></i> Kembali</a>
            </div>
        </form>
    </div>
    
    <!-- KONTEN REKAP -->
    <div style="padding: 15px;">
        <?php if (empty($grouped_data)): ?>
            <div class="alert alert-info text-center">Tidak ada data Sales Order ditemukan.</div>
        <?php else: ?>
            <!-- Judul untuk cetak -->
            <div style="text-align: center; margin-bottom: 20px; display: none;" class="print-title">
                <h3>PT MUTIARA CAHAYA PLASTINDO</h3>
                <h4>REKAP SALES ORDER</h4>
                <p>Periode: <?= date('d/m/Y', strtotime($start_date)) ?> - <?= date('d/m/Y', strtotime($end_date)) ?></p>
            </div>
            
            <?php foreach ($grouped_data as $customer): ?>
                <div class="customer-group">
                    <div class="customer-header">
                        <h5>
                            <?= htmlspecialchars($customer['customer_name']) ?>
                            <small> - <?= htmlspecialchars($customer['customer_city']) ?></small>
                        </h5>
                        <small><?= nl2br(htmlspecialchars(substr($customer['customer_address'], 0, 100))) ?></small>
                    </div>
                    
                    <?php foreach ($customer['orders'] as $order): ?>
                        <div class="order-group">
                            <div class="order-header">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong>Order ID:</strong> <?= htmlspecialchars($order['order_no']) ?> |
                                        <strong>Date:</strong> <?= date('d/m/Y', strtotime($order['order_date'])) ?>
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Marketing/Sales:</strong> <?= htmlspecialchars($order['marketing_name']) ?> / <?= htmlspecialchars($order['sales_name']) ?>
                                        <?php if ($order['remarks']): ?>
                                            | <strong>Remarks:</strong> <?= htmlspecialchars(substr($order['remarks'], 0, 50)) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <table class="rekap-table">
                                <thead>
                                    <tr>
                                        <th>Inventory ID</th>
                                        <th>Qty Order</th>
                                        <th>UoM</th>
                                        <th>Qty Pack</th>
                                        <th>UoM Pack</th>
                                        <th>Order Bal</th>
                                        <th>Price Unit</th>
                                        <th>Price Total</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $order_total = 0;
                                    foreach ($order['items'] as $item): 
                                        $order_total += $item['subtotal'];
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['inventory_id']) . ' - ' . htmlspecialchars(substr($item['inventory_name'], 0, 30)) ?></td>
                                        <td class="text-center"><?= number_format($item['quantity'], 2, ',', '.') ?></td>
                                        <td class="text-center"><?= htmlspecialchars($item['uom']) ?></td>
                                        <td class="text-center"><?= number_format($item['quantity_pack'], 2, ',', '.') ?></td>
                                        <td class="text-center"><?= htmlspecialchars($item['uom_pack']) ?></td>
                                        <td class="text-center">-</td>
                                        <td class="text-right">Rp <?= number_format($item['price_unit'], 2, ',', '.') ?></td>
                                        <td class="text-right">Rp <?= number_format($item['price'], 2, ',', '.') ?></td>
                                        <td class="text-right">Rp <?= number_format($item['subtotal'], 2, ',', '.') ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <tr style="background: #f0f0f0; font-weight: bold;">
                                        <td colspan="8" class="text-right">Total Order <?= htmlspecialchars($order['order_no']) ?>:</td>
                                        <td class="text-right">Rp <?= number_format($order_total, 2, ',', '.') ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            
            <div class="grand-total">
                GRAND TOTAL KESELURUHAN: Rp <?= number_format($grand_total, 2, ',', '.') ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Menambahkan judul saat print
$(document).ready(function() {
    $('.print-title').remove();
    var titleHtml = '<div class="print-title" style="text-align: center; margin-bottom: 20px;">' +
        '<h3>PT MUTIARA CAHAYA PLASTINDO</h3>' +
        '<h4>REKAP SALES ORDER</h4>' +
        '<p>Periode: <?= date('d/m/Y', strtotime($start_date)) ?> - <?= date('d/m/Y', strtotime($end_date)) ?></p>' +
        '<hr>' +
        '</div>';
    
    // Sembunyikan filter saat print
    var style = document.createElement('style');
    style.innerHTML = '@media print { .filter-box, .rekap-header, .no-print { display: none !important; } }';
    document.head.appendChild(style);
});
</script>