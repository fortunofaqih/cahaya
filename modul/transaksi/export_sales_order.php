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
            <th>Order No</th><th>Order Date</th><th>PO</th>
            <th>Marketing</th><th>Sales</th><th>Customer Name</th>
            <th>Customer Address</th><th>Customer City</th>
            <th>Payment Type</th><th>Days</th>
            <th>Grand Total</th><th>Down Payment</th>
            <th>Remarks</th><th>User Created</th><th>Date Created</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $query = mysqli_query($conn, "SELECT h.*, m.marketing_name, s.sales_name 
            FROM head_sales_order h
            LEFT JOIN m_marketing m ON h.marketing_id = m.marketing_id
            LEFT JOIN m_sales s ON h.sales_id = s.sales_id
            $where 
            ORDER BY h.order_date DESC");

        if (!$query) {
            die("Query Error: " . mysqli_error($conn));
        }
        
        while ($row = mysqli_fetch_assoc($query)) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['order_no'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['order_date'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['po'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['marketing_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['sales_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['customer_name'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['customer_address'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['customer_city'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['payment_type'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['days'] ?? '') . "</td>";
            echo "<td>" . number_format($row['grand_total'] ?? 0, 2) . "</td>";
            echo "<td>" . number_format($row['down_payment'] ?? 0, 2) . "</td>";
            echo "<td>" . htmlspecialchars($row['remarks'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['create_user'] ?? '') . "</td>";
            echo "<td>" . htmlspecialchars($row['date_created'] ?? '') . "</td>";
            echo "</tr>";
        }
        ?>
    </tbody>
</table>