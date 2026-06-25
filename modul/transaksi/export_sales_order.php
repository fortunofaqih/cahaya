<?php
// modul/transaksi/export_sales_order.php

session_start();

if (!isset($_SESSION['username'])) {
    die("Akses ditolak!");
}

include __DIR__ . '/../../koneksi.php';

// =====================================================
// FUNCTION: Escape HTML
// =====================================================
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// =====================================================
// FUNCTION: Format tanggal untuk tampilan Excel
// =====================================================
function formatDateExcel($date) {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return '';
    }

    return date('d/m/Y', $timestamp);
}

// =====================================================
// FUNCTION: Format tanggal jam untuk tampilan Excel
// =====================================================
function formatDateTimeExcel($date) {
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return '';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return '';
    }

    return date('d/m/Y H:i', $timestamp);
}

// =====================================================
// FUNCTION: Convert tanggal filter ke format MySQL
// Mendukung:
// - 2026-06-25
// - 25-Jun-2026
// - 25-Mei-2026
// - 25/06/2026
// =====================================================
function convertFilterDateToMysql($date) {
    if ($date === null || trim($date) === '') {
        return '';
    }

    $date = trim($date);

    // Jika sudah format database: 2026-06-25
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    // Jika format dd/mm/yyyy: 25/06/2026
    if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $date)) {
        $parts = explode('/', $date);

        $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
        $year = $parts[2];

        return $year . '-' . $month . '-' . $day;
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

    // Format dari flatpickr datepicker: 25-Jun-2026
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

// =====================================================
// AMBIL PARAMETER FILTER DARI URL
// =====================================================
$start_date_raw = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date_raw = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

$start_date = convertFilterDateToMysql($start_date_raw);
$end_date = convertFilterDateToMysql($end_date_raw);

$status = isset($_GET['status'])
    ? mysqli_real_escape_string($conn, trim($_GET['status']))
    : '';

$approval_status = isset($_GET['approval_status'])
    ? mysqli_real_escape_string($conn, trim($_GET['approval_status']))
    : '';

$so_id = isset($_GET['so_id'])
    ? mysqli_real_escape_string($conn, trim($_GET['so_id']))
    : '';

$customer_id = isset($_GET['customer_id'])
    ? mysqli_real_escape_string($conn, trim($_GET['customer_id']))
    : '';

$start_date_safe = mysqli_real_escape_string($conn, $start_date);
$end_date_safe = mysqli_real_escape_string($conn, $end_date);

// =====================================================
// BUILD WHERE CLAUSE
// =====================================================
$where = "WHERE 1=1";

// Pakai DATE(h.order_date) supaya tetap aman jika order_date bertipe DATETIME
if ($start_date_safe !== '' && $end_date_safe !== '') {
    $where .= " AND DATE(h.order_date) BETWEEN '$start_date_safe' AND '$end_date_safe'";
}

if ($status !== '') {
    $where .= " AND h.status = '$status'";
}

if ($approval_status !== '') {
    $where .= " AND h.approval_status = '$approval_status'";
}

if ($so_id !== '') {
    $where .= " AND h.order_no LIKE '%$so_id%'";
}

if ($customer_id !== '') {
    $where .= " AND h.customer_id = '$customer_id'";
}

// =====================================================
// QUERY UTAMA
// =====================================================
$sql = "
    SELECT 
        h.*,
        m.marketing_name,
        s.sales_name
    FROM head_sales_order h
    LEFT JOIN m_marketing m ON h.marketing_id = m.marketing_id
    LEFT JOIN m_sales s ON h.sales_id = s.sales_id
    $where
    ORDER BY h.order_date DESC, h.order_no DESC
";

$query = mysqli_query($conn, $sql);

if (!$query) {
    die("Query Export Sales Order Error: " . mysqli_error($conn) . "<br><br>SQL:<br>" . e($sql));
}

// =====================================================
// HEADER EXPORT EXCEL
// Jangan ada echo/output sebelum header ini
// =====================================================
if (ob_get_level()) {
    ob_end_clean();
}

$filename = "Sales_Order_Report_" . date('Ymd_His') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// BOM supaya karakter UTF-8 lebih aman saat dibuka di Excel
echo "\xEF\xBB\xBF";

// =====================================================
// VARIABEL TOTAL
// =====================================================
$no = 1;
$total_grand = 0;
$total_downpayment = 0;
$total_balance = 0;
$total_records = mysqli_num_rows($query);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Export Sales Order</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
        }

        .report-title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            text-align: center;
        }

        .report-info {
            font-size: 10px;
            margin-bottom: 15px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 10px;
        }

        th {
            background: #4472C4;
            color: #ffffff;
            font-weight: bold;
            border: 1px solid #333333;
            padding: 6px 4px;
            text-align: center;
            white-space: nowrap;
        }

        td {
            border: 1px solid #999999;
            padding: 4px 3px;
            vertical-align: top;
        }

        .text-left {
            text-align: left;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .grand-total {
            background: #e6f0fa;
            font-weight: bold;
        }

        .summary-section {
            margin-top: 20px;
            border-top: 2px solid #333333;
            padding-top: 10px;
        }

        .summary-table td {
            border: none;
            padding: 3px 8px;
            font-size: 10px;
        }
		.money-text {
			mso-number-format: "\@";
		}
    </style>
</head>

<body>

    <div class="report-title">SALES ORDER REPORT</div>

    <div class="report-info">
        <strong>Periode:</strong>
        <?= $start_date !== '' ? formatDateExcel($start_date) : 'Awal' ?>
        s.d.
        <?= $end_date !== '' ? formatDateExcel($end_date) : 'Sekarang' ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Status:</strong> <?= $status !== '' ? e($status) : 'All' ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Approval:</strong> <?= $approval_status !== '' ? e($approval_status) : 'All' ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>SO ID:</strong> <?= $so_id !== '' ? e($so_id) : 'All' ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>Export Date:</strong> <?= date('d/m/Y H:i:s') ?>
        &nbsp;&nbsp;|&nbsp;&nbsp;
        <strong>User:</strong> <?= e($_SESSION['username']) ?>
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
                <th>Customer ID</th>
                <th>Customer Name</th>
                <th>Customer Address</th>
                <th>Customer City</th>
                <th>Station</th>
                <th>Shipment Due Date</th>
                <th>Shipment Location</th>
                <th>Tolerance</th>
                <th>Status</th>
                <th>Approval</th>
                <th>Payment Type</th>
                <th>Term Days</th>
                <th>Payment Term</th>
                <th>Currency</th>
                <th>Kurs</th>
                <th>Grand Total</th>
                <th>Down Payment</th>
                <th>Balance</th>
                <th>User Created</th>
                <th>Date Created</th>
                <th>User Modified</th>
                <th>Date Modified</th>
                <th>Remarks</th>
            </tr>
        </thead>

        <tbody>
            <?php if ($total_records == 0): ?>
                <tr>
                    <td colspan="29" class="text-center">
                        Tidak ada data Sales Order ditemukan.
                    </td>
                </tr>
            <?php else: ?>
                <?php while ($row = mysqli_fetch_assoc($query)): ?>
                    <?php
                    $grand_total = (float)($row['grand_total'] ?? 0);
                    $down_payment = (float)($row['down_payment'] ?? 0);
                    $balance = $grand_total - $down_payment;

                    $total_grand += $grand_total;
                    $total_downpayment += $down_payment;
                    $total_balance += $balance;
                    ?>

                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="text-center"><strong><?= e($row['order_no'] ?? '') ?></strong></td>
                        <td class="text-center"><?= formatDateExcel($row['order_date'] ?? '') ?></td>
                        <td class="text-center"><?= e($row['po'] ?? '') ?></td>
                        <td><?= e($row['marketing_name'] ?? '') ?></td>
                        <td><?= e($row['sales_name'] ?? '') ?></td>
                        <td class="text-center"><?= e($row['customer_id'] ?? '') ?></td>
                        <td><?= e($row['customer_name'] ?? '') ?></td>
                        <td><?= e($row['customer_address'] ?? '') ?></td>
                        <td><?= e($row['customer_city'] ?? '') ?></td>
                        <td><?= e($row['station'] ?? '') ?></td>
                        <td class="text-center"><?= formatDateExcel($row['shipment_due_date'] ?? '') ?></td>
                        <td><?= e($row['shipment_location'] ?? '') ?></td>
                        <td class="text-right"><?= number_format((float)($row['tolerance'] ?? 0), 2, ',', '.') ?>%</td>
                        <td class="text-center"><?= e($row['status'] ?? '') ?></td>
                        <td class="text-center"><?= e($row['approval_status'] ?? '') ?></td>
                        <td class="text-center"><?= e($row['payment_type'] ?? '') ?></td>
                        <td class="text-center"><?= e($row['days'] ?? '') ?></td>
                        <td class="text-center"><?= e($row['payment_term'] ?? '') ?></td>
                        <td class="text-center"><?= e($row['currency'] ?? 'IDR') ?></td>
                        <td class="text-right"><?= number_format((float)($row['kurs'] ?? 1), 2, ',', '.') ?></td>
                        <td class="text-right money-text"><?= number_format($grand_total, 0, ',', '.') ?></td>
						<td class="text-right money-text"><?= number_format($down_payment, 0, ',', '.') ?></td>
						<td class="text-right money-text"><?= number_format($balance, 0, ',', '.') ?></td>
                        <td><?= e($row['create_user'] ?? '') ?></td>
                        <td class="text-center"><?= formatDateTimeExcel($row['date_created'] ?? '') ?></td>
                        <td><?= e($row['user_modified'] ?? '') ?></td>
                        <td class="text-center"><?= formatDateTimeExcel($row['date_modified'] ?? '') ?></td>
                        <td><?= e($row['remarks'] ?? '') ?></td>
                    </tr>
                <?php endwhile; ?>

                <tr class="grand-total">
                    <td colspan="21" class="text-right">TOTAL :</td>
                    <td class="text-right money-text"><?= number_format($total_grand, 0, ',', '.') ?></td>
					<td class="text-right money-text"><?= number_format($total_downpayment, 0, ',', '.') ?></td>
					<td class="text-right money-text"><?= number_format($total_balance, 0, ',', '.') ?></td>
                    <td colspan="5"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="summary-section">
        <table class="summary-table">
            <tr>
                <td><strong>Total Records</strong></td>
                <td>: <?= number_format($total_records, 0, ',', '.') ?> Sales Order</td>
            </tr>
            <tr>
                <td><strong>Total Grand Total</strong></td>
                <td>: Rp <?= number_format($total_grand, 0, ',', '.') ?></td>
            </tr>
            <tr>
                <td><strong>Total Down Payment</strong></td>
                <td>: Rp <?= number_format($total_downpayment, 0, ',', '.') ?></td>
            </tr>
            <tr>
                <td><strong>Total Balance</strong></td>
                <td>: Rp <?= number_format($total_balance, 0, ',', '.') ?></td>
            </tr>
            <tr>
                <td><strong>Export Generated</strong></td>
                <td>: <?= date('d/m/Y H:i:s') ?> by <?= e($_SESSION['username']) ?></td>
            </tr>
        </table>
    </div>

</body>
</html>