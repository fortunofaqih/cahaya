<?php
// export_sales_order.php

session_start();
if (!isset($_SESSION['username'])) {
    die("Akses ditolak!");
}

include __DIR__ . '/../../koneksi.php';

// Get filter parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$approval_status = isset($_GET['approval_status']) ? $_GET['approval_status'] : '';
$so_id = isset($_GET['so_id']) ? $_GET['so_id'] : '';

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

if (ob_get_level()) ob_end_clean();
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Sales_Order_Report_" . date('Ymd_His') . ".xls");
header("Cache-Control: no-cache, must-revalidate");
?>

<style>
    th { background: #f2f2f2; font-weight: bold; border: 1px solid #999; padding: 5px; }
    td { border: 1px solid #ccc; padding: 4px; }
</style>
<table border="1">
    <thead>
        <tr>
            <th>Order No</th><th>Order Date</th><th>SOP</th><th>SOP Date</th><th>PO</th>
            <th>Marketing</th><th>Sales</th><th>Customer ID</th><th>Customer Name</th>
            <th>Customer Address</th><th>Customer City</th><th>Station</th>
            <th>Shipment Due Date</th><th>Shipment Location</th><th>Tolerance</th>
            <th>Payment Type</th><th>Payment Term</th><th>Currency</th>
            <th>Grand Total</th><th>Down Payment</th><th>Status</th>
            <th>Approval</th><th>Remarks</th><th>User Created</th><th>Date Created</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $query = mysqli_query($conn, "SELECT h.*, m.marketing_name, s.sales_name 
            FROM head_sales_order h
            LEFT JOIN m_marketing m ON h.marketing_id = m.marketing_id
            LEFT JOIN m_sales s ON h.sales_id = s.sales_id
            $where ORDER BY h.order_date DESC");
        
        while ($row = mysqli_fetch_assoc($query)) {
            echo "<tr>";
            echo "<td>" . $row['order_no'] . "</td>";
            echo "<td>" . $row['order_date'] . "</td>";
            echo "<td>" . $row['sop'] . "</td>";
            echo "<td>" . $row['sop_date'] . "</td>";
            echo "<td>" . $row['po'] . "</td>";
            echo "<td>" . ($row['marketing_name'] ?? '') . "</td>";
            echo "<td>" . ($row['sales_name'] ?? '') . "</td>";
            echo "<td>" . $row['customer_id'] . "</td>";
            echo "<td>" . $row['customer_name'] . "</td>";
            echo "<td>" . $row['customer_address'] . "</td>";
            echo "<td>" . $row['customer_city'] . "</td>";
            echo "<td>" . $row['station'] . "</td>";
            echo "<td>" . $row['shipment_due_date'] . "</td>";
            echo "<td>" . $row['shipment_location'] . "</td>";
            echo "<td>" . $row['tolerance'] . "%" . "</td>";
            echo "<td>" . $row['payment_type'] . "</td>";
            echo "<td>" . $row['payment_term'] . "</td>";
            echo "<td>" . $row['currency'] . "</td>";
            echo "<td>" . number_format($row['grand_total'], 2) . "</td>";
            echo "<td>" . number_format($row['down_payment'], 2) . "</td>";
            echo "<td>" . $row['status'] . "</td>";
            echo "<td>" . $row['approval_status'] . "</td>";
            echo "<td>" . $row['remarks'] . "</td>";
            echo "<td>" . $row['create_user'] . "</td>";
            echo "<td>" . $row['date_created'] . "</td>";
            echo "</tr>";
        }
        ?>
    </tbody>
</table>