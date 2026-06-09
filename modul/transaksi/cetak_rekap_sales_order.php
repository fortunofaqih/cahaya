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

// Ambil parameter dari URL
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$customer_name = isset($_GET['customer_name']) ? $_GET['customer_name'] : '';
$sales_name = isset($_GET['sales_name']) ? $_GET['sales_name'] : '';
$marketing_name = isset($_GET['marketing_name']) ? $_GET['marketing_name'] : '';
$approval_status = isset($_GET['approval_status']) ? $_GET['approval_status'] : '';

// Build WHERE clause
$where = "WHERE 1=1";
if ($start_date && $end_date) {
    $where .= " AND h.order_date BETWEEN '$start_date' AND '$end_date'";
}
if ($customer_name) {
    $where .= " AND h.customer_name LIKE '%$customer_name%'";
}
if ($sales_name) {
    $where .= " AND s.sales_name LIKE '%$sales_name%'";
}
if ($marketing_name) {
    $where .= " AND m.marketing_name LIKE '%$marketing_name%'";
}
if ($approval_status) {
    $where .= " AND h.approval_status = '$approval_status'";
}

// Query data
$query = mysqli_query($conn, "
    SELECT 
        h.order_no,
        h.order_date,
        h.customer_name,
        h.customer_city,
        h.po,
        h.payment_term,
        h.remarks,
        m.marketing_name,
        s.sales_name,
        d.inventory_id,
        d.inventory_name,
        d.quantity,
        d.uom,
        d.quantity_pack,
        d.uom_pack,
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
$grand_total = 0;

while ($row = mysqli_fetch_assoc($query)) {
    $customer_key = $row['customer_name'];
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
            'remarks' => $row['remarks'],
            'items' => [],
            'order_total' => 0
        ];
    }
    
    if ($row['inventory_id']) {
        $grouped_data[$customer_key]['orders'][$order_key]['items'][] = $row;
        $grouped_data[$customer_key]['orders'][$order_key]['order_total'] += $row['subtotal'];
        $grand_total += $row['subtotal'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap Sales Order</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            background: white;
            font-size: 9px;
            padding: 3mm;
            margin: 0;
            line-height: 1.1;
        }
        
        .print-container {
            width: 100%;
            margin: 0;
        }
        
        .period-title {
            text-align: center;
            margin-bottom: 4px;
            font-size: 9px;
            font-weight: bold;
            padding-bottom: 2px;
            border-bottom: 1px solid #333;
        }
        
        /* Customer Group */
        .customer-group {
            margin-bottom: 4px;
            page-break-inside: avoid;
        }
        
        .customer-header-row {
            background: #1e3c72;
            color: black;
            font-weight: bold;
            font-size: 9px;
        }
        
        /* Tabel */
        .rekap-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 6.5px;
            table-layout: fixed;
            margin-bottom: 1px;
        }
        
        .rekap-table th,
        .rekap-table td {
            border: 0.5px solid #666;
            padding: 1px 1px;
            vertical-align: top;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .rekap-table th {
            background: #e9ecef;
            font-weight: bold;
            text-align: center;
            vertical-align: middle;
            height: 15px;
            line-height: 1.1;
            font-size: 9px;
        }
        
        .text-right {
            text-align: right;
            padding-right: 1px;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-left {
            text-align: left;
        }
        
        /* Lebar kolom - dioptimasi untuk landscape A4 */
        .col-order-id { width: 6.5%; }
        .col-order-date { width: 5%; }
        .col-marketing-sales { width: 7%; }
        .col-po { width: 2.5%; }
        .col-top { width: 2.5%; }
        .col-remarks { width: 4.5%; }
        .col-inv-id { width: 4.5%; }
        .col-inv-name { width: 8%; }
        .col-order-qty { width: 3.5%; }
        .col-uom { width: 2%; }
        .col-qty-pack { width: 3%; }
        .col-uom-pack { width: 2.5%; }
        .col-price-unit { width: 5%; }
        .col-price { width: 5%; }
        .col-price-kg { width: 4.5%; }
        .col-subtotal { width: 5.5%; }
        
        /* Baris total */
        .order-total-row {
            background: #f0f0f0;
            font-weight: bold;
            font-size: 9px;
        }
        
        .customer-total-row {
            background: #d9e4f5;
            font-weight: bold;
            font-size: 9px;
        }
        
        /* Wrap text untuk kolom Marketing/Sales */
      .wrap-text {
        white-space: normal !important;      /* Mengizinkan teks turun ke bawah */
        text-overflow: clip !important;      /* Menghilangkan efek titik-titik (...) */
        overflow: visible !important;        /* Memperlihatkan teks yang panjang */
        word-break: break-word;              /* Memotong kata yang terlalu panjang agar pas kolom */
      }
        
        .grand-total-section {
            margin-top: 4px;
            padding: 3px 6px;
            background: #2a5298;
            color: white;
            text-align: right;
            font-weight: bold;
            font-size: 8px;
        }
        
        @media print {
            html {
                margin: 0;
                padding: 0;
            }
            
            body {
                padding: 3mm;
                margin: 0;
            }
            
            .rekap-table th,
            .rekap-table td {
                border: 0.5px solid #666;
            }
            
            @page {
                size: A4 landscape;
                margin: 4mm 4mm 4mm 4mm;
            }
            
            .customer-group {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
<div class="print-container">
    <div class="period-title">
        REKAP SALES ORDER | Periode: <?= date('d/m/Y', strtotime($start_date)) ?> s/d <?= date('d/m/Y', strtotime($end_date)) ?>
        <?php if ($customer_name): ?>| Customer: <?= htmlspecialchars($customer_name) ?><?php endif; ?>
        <?php if ($approval_status): ?>| Status: <?= htmlspecialchars($approval_status) ?><?php endif; ?>
    </div>
    
    <?php if (empty($grouped_data)): ?>
        <div style="text-align: center; padding: 20px; font-size: 8px;">Tidak ada data Sales Order ditemukan.</div>
    <?php else: ?>
        <?php 
        $customer_grand_total = 0;
        foreach ($grouped_data as $customer): 
            $customer_total = 0;
        ?>
            <div class="customer-group">
                <table class="rekap-table">
                    <thead>
                        <tr class="customer-header-row">
                            <td colspan="16" style="padding: 2px 5px; font-size: 9px; font-weight: bold;">CUSTOMER: <?= strtoupper(htmlspecialchars(substr($customer['customer_name'], 0, 30))) ?> | AREA: <?= htmlspecialchars(substr($customer['customer_city'], 0, 15)) ?></td>
                        </tr>
                        <tr>
                            <th class="col-order-id">Order ID</th>
                            <th class="col-order-date">Order<br>Date</th>
                            <th class="col-marketing-sales">Marketing/<br>Sales</th>
                            <th class="col-po">PO</th>
                            <th class="col-top">TOP</th>
                            <th class="col-remarks">Remarks</th>
                            <th class="col-inv-id wrap-text ">Inv ID</th>
                            <th class="col-inv-name wrap-text">Inventory Name</th>
                            <th class="col-order-qty">Order<br>Qty</th>
                            <th class="col-uom">UoM</th>
                            <th class="col-qty-pack">Qty<br>Pack</th>
                            <th class="col-uom-pack">Pack<br>UoM</th>
                            <th class="col-price-unit">Price<br>Unit</th>
                            <th class="col-price">Price</th>
                            <th class="col-price-kg">Price<br>KG</th>
                            <th class="col-subtotal">Sub Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        foreach ($customer['orders'] as $order):
                            $item_count = count($order['items']);
                            $rowspan = $item_count + 1; // +1 untuk baris total order
                            
                            foreach ($order['items'] as $idx => $item):
                                // Hitung price/kg
                                $price_kg = ($item['quantity'] > 0) ? ($item['subtotal'] / $item['quantity']) : 0;
                                $marketing_sales = trim(($order['marketing_name'] ?: '-') . ' / ' . ($order['sales_name'] ?: '-'));
                        ?>
                            <tr>
                                <?php if ($idx == 0): ?>
                                    <td class="col-order-id wrap-text" rowspan="<?= $rowspan ?>" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= htmlspecialchars($order['order_no']) ?></td>
                                    <td class="col-order-date" rowspan="<?= $rowspan ?>" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= date('d/m/Y', strtotime($order['order_date'])) ?></td>
                                    <td class="col-marketing-sales wrap-text" rowspan="<?= $rowspan ?>" style="vertical-align: middle; font-size: 9px;"><?= htmlspecialchars(substr($marketing_sales, 0, 20)) ?></td>
                                    <td class="col-po" rowspan="<?= $rowspan ?>" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= htmlspecialchars(substr($order['po'] ?: '-', 0, 10)) ?></td>
                                    <td class="col-top" rowspan="<?= $rowspan ?>" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= htmlspecialchars(substr($order['payment_term'] ?: '-', 0, 10)) ?></td>
                                    <td class="col-remarks wrap-text" rowspan="<?= $rowspan ?>" style="vertical-align: middle; font-size: 9px;"><?= htmlspecialchars(substr($order['remarks'], 0, 15)) ?></td>
                                <?php endif; ?>
                                
                                <td class="col-inv-id wrap-text" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= htmlspecialchars($item['inventory_id']) ?></td>
                                <td class="col-inv-name wrap-text" style="font-size: 9px;"><?= htmlspecialchars($item['inventory_name']) ?></td>
                                <td class="col-order-qty text-right" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= number_format($item['quantity'], 1, ',', '.') ?></td>
                                <td class="col-uom text-center" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= htmlspecialchars(substr($item['uom'], 0, 3)) ?></td>
                                <td class="col-qty-pack text-right" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= number_format($item['quantity_pack'], 1, ',', '.') ?></td>
                                <td class="col-uom-pack text-center" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= htmlspecialchars(substr($item['uom_pack'], 0, 2)) ?></td>
                                <td class="col-price-unit text-right" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= number_format($item['price_unit'], 0, ',', '.') ?></td>
                                <td class="col-price text-right" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= number_format($item['price'], 0, ',', '.') ?></td>
                                <td class="col-price-kg text-right" style="text-align: center; vertical-align: middle; font-size: 9px;"><?= number_format($price_kg, 0, ',', '.') ?></td>
                                <td class="col-subtotal text-right" style="text-align: center; vertical-align: middle; font-size: 9px; font-weight: bold;"><?= number_format($item['subtotal'], 0, ',', '.') ?></td>
                            </tr>
                            <?php 
                            endforeach; 
                            ?>
                            <!-- Baris Total Order -->
                            <?php 
                            $customer_total += $order['order_total'];
                        endforeach; 
                        ?>
                        <!-- Baris Total Customer -->
                        <tr class="customer-total-row">
                            <td colspan="9" class="text-right" style="font-size: 9px; padding-right: 2px;">Total <?= strtoupper(htmlspecialchars(substr($customer['customer_name'], 0, 20))) ?>:</td>
                            <td class="text-right" style="font-size: 9px; padding-right: 2px;"><?= number_format($customer_total, 0, ',', '.') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php 
            $customer_grand_total += $customer_total;
        endforeach; 
        ?>
        
        <!-- Grand Total -->
        <div class="grand-total-section">
            GRAND TOTAL: Rp <?= number_format($grand_total, 0, ',', '.') ?>
        </div>
    <?php endif; ?>
</div>

<script>
    window.print();
</script>
</body>
</html>