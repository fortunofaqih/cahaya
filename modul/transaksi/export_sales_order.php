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
$customer_id = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';

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
if ($customer_id) {
    $where .= " AND customer_id = '$customer_id'";
}

if (ob_get_level()) ob_end_clean();
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Sales_Order_Report_" . date('Ymd_His') . ".xls");
header("Cache-Control: no-cache, must-revalidate");
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Export Sales Order</title>
    <style>
        /* Gaya untuk tampilan Excel */
        body {
            font-family: 'Consolas', 'Courier New', monospace;
            font-size: 10px;
        }
        
        .report-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            text-align: center;
        }
        
        .report-info {
            font-size: 9px;
            margin-bottom: 15px;
            color: #666;
        }
        
        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 9px;
        }
        
        th {
            background: #4472C4;
            color: #ffffff;
            font-weight: bold;
            border: 1px solid #333;
            padding: 6px 4px;
            text-align: center;
            white-space: nowrap;
        }
        
        td {
            border: 1px solid #999;
            padding: 4px 3px;
            vertical-align: middle;
        }
        
        .text-left { text-align: left; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        
        .total-row {
            background: #f2f2f2;
            font-weight: bold;
        }
        
        .grand-total {
            background: #e6f0fa;
            font-weight: bold;
            font-size: 11px;
        }
        
        .status-open { color: #008000; }
        .status-close { color: #ff0000; }
        .status-pending { color: #ff8c00; }
        .status-approve { color: #008000; }
        .status-reject { color: #ff0000; }
        
        /* Untuk spacing di Excel */
        .excel-row { height: 18px; }
        
        .summary-section {
            margin-top: 20px;
            border-top: 2px solid #333;
            padding-top: 10px;
        }
        
        .summary-table td {
            border: none;
            padding: 2px 10px;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <div class="report-title">📊 SALES ORDER REPORT</div>
    <div class="report-info">
        <strong>Periode:</strong> 
        <?= $start_date ? date('d/m/Y', strtotime($start_date)) : 'Awal' ?> 
        s.d. 
        <?= $end_date ? date('d/m/Y', strtotime($end_date)) : 'Sekarang' ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Export Date:</strong> <?= date('d/m/Y H:i:s') ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>User:</strong> <?= htmlspecialchars($_SESSION['username']) ?>
    </div>

    <table border="1">
        <thead>
            <tr>
                <th>No</th>
                <th>Order No</th>
                <th>Order Date</th>
                <th>PO Number</th>
                <th>Marketing</th>
                <th>Sales</th>
                <th>Customer Name</th>
                <th>Customer Address</th>
                <th>Customer City</th>
                <th>Status</th>
                <th>Approval</th>
                <th>Payment Type</th>
                <th>Term (Days)</th>
                <th>Payment Term</th>
                <th>Currency</th>
                <th>Grand Total</th>
                <th>Down Payment</th>
                <th>Balance</th>
                <th>User Created</th>
                <th>Date Created</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query = mysqli_query($conn, "SELECT h.*, m.marketing_name, s.sales_name 
                FROM head_sales_order h
                LEFT JOIN m_marketing m ON h.marketing_id = m.marketing_id
                LEFT JOIN m_sales s ON h.sales_id = s.sales_id
                $where 
                ORDER BY h.order_date DESC, h.order_no DESC");

            if (!$query) {
                die("Query Error: " . mysqli_error($conn));
            }
            
            $no = 1;
            $total_grand = 0;
            $total_downpayment = 0;
            $total_balance = 0;
            
            while ($row = mysqli_fetch_assoc($query)) {
                $balance = ($row['grand_total'] ?? 0) - ($row['down_payment'] ?? 0);
                $total_grand += $row['grand_total'] ?? 0;
                $total_downpayment += $row['down_payment'] ?? 0;
                $total_balance += $balance;
                
                // Status styling
                $status_color = '';
                $status_class = '';
                if (($row['status'] ?? '') == 'Open') {
                    $status_class = 'status-open';
                } elseif (($row['status'] ?? '') == 'Close') {
                    $status_class = 'status-close';
                }
                
                $approval_class = '';
                if (($row['approval_status'] ?? '') == 'Pending') {
                    $approval_class = 'status-pending';
                } elseif (($row['approval_status'] ?? '') == 'Approve') {
                    $approval_class = 'status-approve';
                } elseif (($row['approval_status'] ?? '') == 'Reject') {
                    $approval_class = 'status-reject';
                }
                
                echo "<tr>";
                echo "<td class='text-center'>" . $no++ . "</td>";
                echo "<td class='text-center'><strong>" . htmlspecialchars($row['order_no'] ?? '') . "</strong></td>";
                echo "<td class='text-center'>" . date('d/m/Y', strtotime($row['order_date'] ?? '')) . "</td>";
                echo "<td class='text-center'>" . htmlspecialchars($row['po'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['marketing_name'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['sales_name'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['customer_name'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['customer_address'] ?? '') . "</td>";
                echo "<td>" . htmlspecialchars($row['customer_city'] ?? '') . "</td>";
                echo "<td class='text-center $status_class'>" . htmlspecialchars($row['status'] ?? '') . "</td>";
                echo "<td class='text-center $approval_class'>" . htmlspecialchars($row['approval_status'] ?? '') . "</td>";
                echo "<td class='text-center'>" . htmlspecialchars($row['payment_type'] ?? '') . "</td>";
                echo "<td class='text-center'>" . htmlspecialchars($row['days'] ?? '') . "</td>";
                echo "<td class='text-center'>" . htmlspecialchars($row['payment_term'] ?? '') . "</td>";
                echo "<td class='text-center'>" . htmlspecialchars($row['currency'] ?? 'IDR') . "</td>";
                echo "<td class='text-right'>" . number_format($row['grand_total'] ?? 0, 0, ',', '.') . "</td>";
                echo "<td class='text-right'>" . number_format($row['down_payment'] ?? 0, 0, ',', '.') . "</td>";
                echo "<td class='text-right'>" . number_format($balance, 0, ',', '.') . "</td>";
                echo "<td>" . htmlspecialchars($row['create_user'] ?? '') . "</td>";
                echo "<td class='text-center'>" . date('d/m/Y H:i', strtotime($row['date_created'] ?? '')) . "</td>";
                echo "<td>" . htmlspecialchars($row['remarks'] ?? '') . "</td>";
                echo "</tr>";
            }
            
            // Grand Total Row
            echo "<tr class='grand-total'>";
            echo "<td colspan='15' class='text-right' style='font-size:11px;'>TOTAL :</td>";
            echo "<td class='text-right'>" . number_format($total_grand, 0, ',', '.') . "</td>";
            echo "<td class='text-right'>" . number_format($total_downpayment, 0, ',', '.') . "</td>";
            echo "<td class='text-right'>" . number_format($total_balance, 0, ',', '.') . "</td>";
            echo "<td colspan='3'></td>";
            echo "</tr>";
            ?>
        </tbody>
    </table>

    <!-- Summary Section -->
    <div class="summary-section">
        <table class="summary-table">
            <tr>
                <td><strong>Total Records:</strong></td>
                <td><?= $no - 1 ?> Sales Order</td>
                <td style="width:50px;"></td>
                <td><strong>Total Grand Total:</strong></td>
                <td>Rp <?= number_format($total_grand, 0, ',', '.') ?></td>
                <td style="width:50px;"></td>
                <td><strong>Total Down Payment:</strong></td>
                <td>Rp <?= number_format($total_downpayment, 0, ',', '.') ?></td>
                <td style="width:50px;"></td>
                <td><strong>Total Balance:</strong></td>
                <td>Rp <?= number_format($total_balance, 0, ',', '.') ?></td>
            </tr>
            <tr>
                <td><strong>Export Generated:</strong></td>
                <td colspan="10"><?= date('d/m/Y H:i:s') ?> by <?= htmlspecialchars($_SESSION['username']) ?></td>
            </tr>
        </table>
    </div>
</body>
</html>