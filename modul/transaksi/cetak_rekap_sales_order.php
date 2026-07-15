<?php
// modul/transaksi/cetak_rekap_sales_order.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// ==========================================
// HELPER DATE & REMARKS
// ==========================================
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

    return date('d', $timestamp) . '-' . $bulan[(int)date('m', $timestamp)] . '-' . date('Y', $timestamp);
}

function convertFilterDateToMysql($date) {
    if ($date === null || trim($date) === '') {
        return '';
    }

    $date = trim($date);

    // Format database: 2026-06-18
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

    // Format dari datepicker: 18-Jun-2026
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

function isStokanRemark($remark) {
    return strtoupper(trim((string)$remark)) === 'STOKAN';
}

// ==========================================
// AMBIL PARAMETER DARI URL
// ==========================================
$start_date_raw = isset($_GET['start_date']) && trim($_GET['start_date']) !== ''
    ? trim($_GET['start_date'])
    : formatDateIndonesian(date('Y-m-01'));

$end_date_raw = isset($_GET['end_date']) && trim($_GET['end_date']) !== ''
    ? trim($_GET['end_date'])
    : formatDateIndonesian(date('Y-m-t'));

$start_date_sql = convertFilterDateToMysql($start_date_raw);
$end_date_sql = convertFilterDateToMysql($end_date_raw);

if ($start_date_sql === '') {
    $start_date_sql = date('Y-m-01');
    $start_date_raw = formatDateIndonesian($start_date_sql);
}

if ($end_date_sql === '') {
    $end_date_sql = date('Y-m-t');
    $end_date_raw = formatDateIndonesian($end_date_sql);
}

if ($start_date_sql > $end_date_sql) {
    $tmp_sql = $start_date_sql;
    $start_date_sql = $end_date_sql;
    $end_date_sql = $tmp_sql;

    $tmp_raw = $start_date_raw;
    $start_date_raw = $end_date_raw;
    $end_date_raw = $tmp_raw;
}

$start_date_safe = mysqli_real_escape_string($conn, $start_date_sql);
$end_date_safe = mysqli_real_escape_string($conn, $end_date_sql);

$customer_name = isset($_GET['customer_name']) ? trim($_GET['customer_name']) : '';
$sales_name = isset($_GET['sales_name']) ? trim($_GET['sales_name']) : '';
$marketing_name = isset($_GET['marketing_name']) ? trim($_GET['marketing_name']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$approval_status = isset($_GET['approval_status']) ? trim($_GET['approval_status']) : '';

$customer_name_safe = mysqli_real_escape_string($conn, $customer_name);
$sales_name_safe = mysqli_real_escape_string($conn, $sales_name);
$marketing_name_safe = mysqli_real_escape_string($conn, $marketing_name);
$category_safe = mysqli_real_escape_string($conn, $category);
$approval_status_safe = mysqli_real_escape_string($conn, $approval_status);

// ==========================================
// BUILD WHERE CLAUSE
// ==========================================
$where = "WHERE 1=1";
$where .= " AND DATE(h.order_date) BETWEEN '$start_date_safe' AND '$end_date_safe'";

if ($customer_name_safe !== '') {
    $where .= " AND h.customer_name LIKE '%$customer_name_safe%'";
}

if ($sales_name_safe !== '') {
    $where .= " AND s.sales_name = '$sales_name_safe'";
}

if ($marketing_name_safe !== '') {
    $where .= " AND m.marketing_name = '$marketing_name_safe'";
}

if ($category_safe !== '') {
    $where .= " AND mi.category = '$category_safe'";
}

if ($approval_status_safe !== '') {
    $where .= " AND h.approval_status = '$approval_status_safe'";
}

// ==========================================
// QUERY DATA
// ==========================================
$query = mysqli_query($conn, "
    SELECT 
        h.order_no,
        h.order_date,
        h.customer_name,
        h.customer_city,
        h.po,
        h.payment_term,
        h.remarks AS header_remarks,
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
        d.subtotal,
        d.remarks AS detail_remarks,

        mi.category
    FROM head_sales_order h
    LEFT JOIN m_marketing m ON h.marketing_id = m.marketing_id
    LEFT JOIN m_sales s ON h.sales_id = s.sales_id
    LEFT JOIN detail_sales_order d ON h.order_no = d.order_no
    LEFT JOIN m_inventory mi ON d.inventory_id = mi.inventory_id
    $where
    ORDER BY h.order_date DESC, h.customer_name ASC, h.order_no ASC, d.inventory_id ASC
");

if (!$query) {
    die('Query Cetak Rekap Sales Order Error: ' . mysqli_error($conn));
}

// ==========================================
// GROUPING DATA
// ==========================================
$grouped_data = [];

$grand_total_qty = 0;
$grand_total_bal = 0;
$grand_total_subtotal = 0;

while ($row = mysqli_fetch_assoc($query)) {
    $customer_key = $row['customer_name'] ?: 'UNKNOWN CUSTOMER';

    if (!isset($grouped_data[$customer_key])) {
        $grouped_data[$customer_key] = [
            'customer_name' => $row['customer_name'],
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
            'po' => $row['po'],
            'payment_term' => $row['payment_term'],
            'remarks' => $row['header_remarks'],
            'approval_status' => $row['approval_status'],
            'items' => []
        ];
    }

    if (!empty($row['inventory_id'])) {
        $grouped_data[$customer_key]['orders'][$order_key]['items'][] = $row;

        $quantity = (float)($row['quantity'] ?? 0);
        $quantity_pack = (float)($row['quantity_pack'] ?? 0);
        $subtotal = (float)($row['subtotal'] ?? 0);

        $detail_remarks = trim((string)($row['detail_remarks'] ?? ''));
        $header_remarks = trim((string)($row['header_remarks'] ?? ''));

        $remark_for_check = $detail_remarks !== '' ? $detail_remarks : $header_remarks;
        $is_stokan = isStokanRemark($remark_for_check);

        // STOKAN tidak ikut total Order Qty
        $grand_total_qty += $is_stokan ? 0 : $quantity;
        $grand_total_bal += $quantity_pack;
        $grand_total_subtotal += $subtotal;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Rekap Sales Order</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8px;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 10px;
        }

        .print-container {
            width: 100%;
            margin: 0 auto;
        }

        .company-header {
            text-align: center;
            margin-bottom: 8px;
        }

        .company-header h3 {
            margin: 0;
            font-size: 13px;
            font-weight: bold;
        }

        .company-header h4 {
            margin: 3px 0;
            font-size: 11px;
            font-weight: bold;
            text-decoration: underline;
        }

        .company-header p {
            margin: 2px 0;
            font-size: 8px;
        }

        .filter-info {
            width: 100%;
            margin-bottom: 8px;
            border-collapse: collapse;
            font-size: 8px;
        }

        .filter-info td {
            padding: 2px 4px;
            vertical-align: top;
        }

        .customer-section {
            margin-bottom: 10px;
            page-break-inside: avoid;
        }

        .customer-header {
            background: #d9eaf7;
            border: 1px solid #000;
            padding: 4px 6px;
            font-weight: bold;
            font-size: 8px;
            margin-top: 8px;
        }

        .order-info {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
            font-size: 7.5px;
        }

        .order-info td {
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            padding: 3px 5px;
            vertical-align: top;
        }

        .order-info .label {
            font-weight: bold;
            width: 70px;
        }

        .rekap-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            table-layout: fixed;
            font-size: 7px;
        }

        .rekap-table th,
        .rekap-table td {
            border: 1px solid #000;
            padding: 3px 3px;
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .rekap-table th {
            background: #efefef;
            font-weight: bold;
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .text-left {
            text-align: left;
        }

        .order-total-row {
            background: #f0f0f0;
            font-weight: bold;
        }

        .grand-total-section {
            margin-top: 12px;
            padding: 6px;
            border: 2px solid #000;
            background: #e8e8e8;
            font-weight: bold;
            text-align: right;
            font-size: 9px;
        }

        .small-note {
            font-size: 6px;
            color: #c00000;
        }

        .no-data {
            text-align: center;
            font-weight: bold;
            padding: 20px;
            border: 1px solid #000;
            margin-top: 20px;
        }

        .action-buttons {
            text-align: center;
            margin: 15px 0;
            padding: 10px;
            background: #f8f9fa;
            border: 1px solid #ddd;
        }

        .btn-print,
        .btn-back {
            display: inline-block;
            padding: 7px 14px;
            margin: 0 4px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
            border: none;
            cursor: pointer;
        }

        .btn-print {
            background: #0d6efd;
            color: #fff;
        }

        .btn-back {
            background: #6c757d;
            color: #fff;
        }

        .col-inv-id { width: 12%; }
        .col-inv-name { width: 24%; }
        .col-remarks { width: 8%; }
        .col-order-qty { width: 8%; }
        .col-uom { width: 5%; }
        .col-order-bal { width: 8%; }
        .col-uom-pack { width: 6%; }
        .col-price-unit { width: 8%; }
        .col-price { width: 8%; }
        .col-price-kg { width: 7%; }
        .col-subtotal { width: 10%; }

        @media print {
            body {
                margin: 0;
                padding: 0;
                font-size: 7px;
            }

            .print-container {
                padding: 0;
                width: 100%;
            }

            .action-buttons {
                display: none !important;
            }

            .customer-section {
                page-break-inside: avoid;
            }

            @page {
                size: A4 landscape;
                margin: 8mm;
            }
        }
        .col-price-unit { 
        width: 10%; 
        min-width: 65px;
    }
    .col-price { 
        width: 10%; 
        min-width: 65px;
    }
    .col-price-kg { 
        width: 9%; 
        min-width: 60px;
    }
    .col-subtotal { 
        width: 11%; 
        min-width: 75px;
    }
    </style>
</head>

<body>
<div class="print-container">

    <div class="company-header">
        <h4>REKAP SALES ORDER (CP)</h4>
        <p>Periode: <?= htmlspecialchars($start_date_raw) ?> s/d <?= htmlspecialchars($end_date_raw) ?></p>
    </div>

    <table class="filter-info">
        <tr>
            <td style="width: 15%;"><strong>Customer</strong></td>
            <td style="width: 35%;">: <?= $customer_name !== '' ? htmlspecialchars($customer_name) : 'ALL' ?></td>

            <td style="width: 15%;"><strong>Sales</strong></td>
            <td style="width: 35%;">: <?= $sales_name !== '' ? htmlspecialchars($sales_name) : 'ALL' ?></td>
        </tr>
        <tr>
            <td><strong>Marketing</strong></td>
            <td>: <?= $marketing_name !== '' ? htmlspecialchars($marketing_name) : 'ALL' ?></td>

            <td><strong>Category</strong></td>
            <td>: <?= $category !== '' ? htmlspecialchars($category) : 'ALL' ?></td>
        </tr>
        <tr>
            <td><strong>Approval Status</strong></td>
            <td>: <?= $approval_status !== '' ? htmlspecialchars($approval_status) : 'ALL' ?></td>

            <td><strong>Printed By</strong></td>
            <td>: <?= htmlspecialchars($_SESSION['username'] ?? '-') ?> / <?= date('d-M-Y') ?></td>
        </tr>
    </table>

    <?php if (empty($grouped_data)): ?>

        <div class="no-data">
            Tidak ada data Sales Order pada periode/filter tersebut.
        </div>

    <?php else: ?>

        <?php foreach ($grouped_data as $customer): ?>

            <div class="customer-section">
                <div class="customer-header">
                    CUSTOMER:
                    <?= htmlspecialchars($customer['customer_name'] ?: '-') ?>
                    <?php if (!empty($customer['customer_city'])): ?>
                        - <?= htmlspecialchars($customer['customer_city']) ?>
                    <?php endif; ?>
                </div>

                <?php foreach ($customer['orders'] as $order): ?>

                    <table class="order-info">
                        <tr>
                            <td style="width: 25%;">
                                <span class="label">Order No</span>
                                : <?= htmlspecialchars($order['order_no'] ?: '-') ?>
                            </td>
                            <td style="width: 20%;">
                                <span class="label">Order Date</span>
                                : <?= formatDateIndonesian($order['order_date']) ?>
                            </td>
                            <td style="width: 20%;">
                                <span class="label">PO</span>
                                : <?= htmlspecialchars($order['po'] ?: '-') ?>
                            </td>
                            <td style="width: 20%;">
                                <span class="label">TOP</span>
                                : <?= htmlspecialchars($order['payment_term'] ?: '-') ?>
                            </td>
                            <td style="width: 15%;">
                                <span class="label">Status</span>
                                : <?= htmlspecialchars($order['approval_status'] ?: '-') ?>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <span class="label">Marketing</span>
                                : <?= htmlspecialchars($order['marketing_name'] ?: '-') ?>
                            </td>
                            <td>
                                <span class="label">Sales</span>
                                : <?= htmlspecialchars($order['sales_name'] ?: '-') ?>
                            </td>
                            <td colspan="3">
                                <span class="label">Remarks</span>
                                : <?= htmlspecialchars($order['remarks'] ?: '-') ?>
                            </td>
                        </tr>
                    </table>

                    <table class="rekap-table">
                        <thead>
                            <tr>
                                <th class="col-inv-id">Inventory ID</th>
                                <th class="col-inv-name">Inventory Name</th>
                                <th class="col-remarks">Remarks</th>
                                <th class="col-order-qty">Order Qty</th>
                                <th class="col-uom">UoM</th>
                                <th class="col-order-bal">Order Bal</th>
                                <th class="col-uom-pack">UoM Pack</th>
                                <th class="col-price-unit">Price Unit</th>
                                <th class="col-price">Price</th>
                                <th class="col-price-kg">Price KG</th>
                                <th class="col-subtotal">Subtotal</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php
                            $order_total_qty = 0;
                            $order_total_bal = 0;
                            $order_total_subtotal = 0;
                            ?>

                            <?php foreach ($order['items'] as $item): ?>
                                <?php
                                $quantity = (float)($item['quantity'] ?? 0);
                                $quantity_pack = (float)($item['quantity_pack'] ?? 0);
                                $price_unit = (float)($item['price_unit'] ?? 0);
                                $price = (float)($item['price'] ?? 0);
                                $subtotal = (float)($item['subtotal'] ?? 0);

                                $detail_remarks = trim((string)($item['detail_remarks'] ?? ''));
                                $header_remarks = trim((string)($order['remarks'] ?? ''));

                                // Prioritas remarks detail. Kalau kosong, pakai remarks header.
                                $remarks_display = $detail_remarks !== '' ? $detail_remarks : $header_remarks;
                                $is_stokan = isStokanRemark($remarks_display);

                                // STOKAN tetap tampil qty asli di baris, tapi total Order Qty dianggap 0.
                                $quantity_for_total = $is_stokan ? 0 : $quantity;

                                // Price KG = subtotal / quantity
                                $price_kg = 0;
                                if ($quantity > 0 && $subtotal > 0) {
                                    $price_kg = $subtotal / $quantity;
                                }

                                $order_total_qty += $quantity_for_total;
                                $order_total_bal += $quantity_pack;
                                $order_total_subtotal += $subtotal;

                                $uom_pack_display = $item['uom_pack'];
                                if (($uom_pack_display === null || $uom_pack_display === '') && !empty($item['uom_detail'])) {
                                    $uom_pack_display = $item['uom_detail'];
                                }
                                ?>

                                <tr>
                                    <td><?= htmlspecialchars($item['inventory_id'] ?: '-') ?></td>

                                    <td><?= htmlspecialchars($item['inventory_name'] ?: '-') ?></td>

                                    <td class="text-center">
                                        <?= htmlspecialchars($remarks_display ?: '-') ?>
                                    </td>

                                    <td class="text-right">
                                        <?= number_format($quantity, 2, ',', '.') ?>
                                      
                                    </td>

                                    <td class="text-center">
                                        <?= htmlspecialchars($item['uom'] ?: '-') ?>
                                    </td>

                                    <td class="text-right">
                                        <?= number_format($quantity_pack, 2, ',', '.') ?>
                                    </td>

                                    <td class="text-center">
                                        <?= htmlspecialchars($uom_pack_display ?: '-') ?>
                                    </td>

                                    <td class="text-right">
                                        <?php if ($price_unit > 0): ?>
                                            Rp <?= number_format($price_unit, 2, ',', '.') ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-right">
                                        <?php if ($price > 0): ?>
                                            Rp <?= number_format($price, 2, ',', '.') ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-right">
                                        <?php if ($price_kg > 0): ?>
                                            Rp <?= number_format($price_kg, 2, ',', '.') ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-right">
                                        Rp <?= number_format($subtotal, 2, ',', '.') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <tr class="order-total-row">
                            <td colspan="2"></td>
                            <td class="text-center">Total :</td>
                            <td class="text-right">
                                <?= number_format($order_total_qty, 2, ',', '.') ?>
                            </td>
                            <td></td>
                            <td class="text-right">
                                <?= number_format($order_total_bal, 2, ',', '.') ?>
                            </td>
                            <td colspan="4"></td>
                            <td class="text-right">
                                Rp <?= number_format($order_total_subtotal, 2, ',', '.') ?>
                            </td>
                        </tr>
                        </tbody>
                    </table>

                <?php endforeach; ?>
            </div>

        <?php endforeach; ?>

        <div class="grand-total-section">
            GRAND TOTAL KESELURUHAN:
            &nbsp; Order Qty: <?= number_format($grand_total_qty, 2, ',', '.') ?>
            &nbsp; | &nbsp; Order Bal: <?= number_format($grand_total_bal, 2, ',', '.') ?>
            &nbsp; | &nbsp; Subtotal: Rp <?= number_format($grand_total_subtotal, 2, ',', '.') ?>
        </div>

    <?php endif; ?>

    <div class="action-buttons">
        <button type="button" class="btn-print" onclick="window.print()">Print</button>
        <button type="button" class="btn-back" onclick="window.close()">Close</button>
    </div>

</div>

<script>
    window.onload = function() {
        // Auto print saat halaman dibuka.
        // Kalau tidak mau auto print, comment baris di bawah ini.
        window.print();
    };
</script>
</body>
</html>