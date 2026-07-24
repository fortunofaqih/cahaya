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

    // Format datepicker: 18-Jun-2026
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
    // Dianggap STOKAN apabila kata "STOKAN" terdapat di mana pun dalam remarks,
    // misalnya: "STOKAN", "AMBIL STOKAN", atau "ORDER STOKAN CUSTOMER".
    return stripos((string)$remark, 'STOKAN') !== false;
}

/**
 * Menentukan Order Qty yang boleh masuk ke total.
 *
 * Ketentuan:
 * 1. UoM kosong dan Price = 0 => Order Qty tidak ditotalkan.
 * 2. Order Qty = 1, UoM = KG, Price = 0 => tidak ditotalkan.
 * 3. Order Qty = 1, UoM = KG, Price > 1, tanpa STOKAN => ditotalkan.
 * 4. Order Qty = 1, UoM = KG, Price > 1, dengan STOKAN => tidak ditotalkan.
 * 5. Item lain yang memiliki remark STOKAN tetap tidak ditotalkan.
 */
function getQuantityForTotal($quantity, $uom, $price, $remark) {
    $quantity = (float)$quantity;
    $price = (float)$price;
    $uom = strtoupper(trim((string)$uom));
    $is_stokan = isStokanRemark($remark);

    // UoM tidak diisi dan Price = 0 tidak masuk Total Order Qty.
    if ($uom === '' && abs($price) < 0.000001) {
        return 0;
    }

    $is_qty_one_kg = abs($quantity - 1.0) < 0.000001 && $uom === 'KG';

    if ($is_qty_one_kg) {
        // Price 0 (dan nilai sampai dengan 1 sesuai ketentuan sebelumnya)
        // tidak ikut ditotalkan.
        if ($price <= 1) {
            return 0;
        }

        // Price > 1 hanya ditotalkan apabila tidak ada remark STOKAN.
        return $is_stokan ? 0 : $quantity;
    }

    return $is_stokan ? 0 : $quantity;
}

/**
 * Menentukan Order Bal yang boleh masuk ke total.
 *
 * Jika Order Bal = 1, UoM Pack = KG, dan Price = 0,
 * Order Bal tidak masuk Total Order Bal.
 */
function getQuantityPackForTotal($quantityPack, $uomPack, $price) {
    $quantityPack = (float)$quantityPack;
    $price = (float)$price;
    $uomPack = strtoupper(trim((string)$uomPack));

    $is_excluded =
        abs($quantityPack - 1.0) < 0.000001 &&
        $uomPack === 'KG' &&
        abs($price) < 0.000001;

    return $is_excluded ? 0 : $quantityPack;
}

// ==========================================
// FILTER PENCARIAN - DEFAULT TODAY
// ==========================================
// Ambil tanggal hari ini dalam format MySQL (YYYY-MM-DD)
$today_mysql = date('Y-m-d');
// Format untuk tampilan (DD-MMM-YYYY)
$today_display = formatDateIndonesian($today_mysql);

// Cek apakah ada parameter GET, jika tidak maka default ke hari ini
$start_date_raw = isset($_GET['start_date']) && trim($_GET['start_date']) !== ''
    ? trim($_GET['start_date'])
    : $today_display; // Default ke hari ini

$end_date_raw = isset($_GET['end_date']) && trim($_GET['end_date']) !== ''
    ? trim($_GET['end_date'])
    : $today_display; // Default ke hari ini

$start_date_sql = convertFilterDateToMysql($start_date_raw);
$end_date_sql = convertFilterDateToMysql($end_date_raw);

// Validasi: jika konversi gagal, gunakan hari ini
if ($start_date_sql === '') {
    $start_date_sql = $today_mysql;
    $start_date_raw = $today_display;
}

if ($end_date_sql === '') {
    $end_date_sql = $today_mysql;
    $end_date_raw = $today_display;
}

// Pastikan start_date tidak lebih besar dari end_date
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

// Build WHERE clause untuk header
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

// Query untuk mendapatkan data sales order dengan detail
$query = mysqli_query($conn, "
    SELECT 
        h.order_no,
        h.order_date,
        h.customer_name,
        h.customer_address,
        h.customer_city,
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
    die('Query Rekap Sales Order Error: ' . mysqli_error($conn));
}

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
            'remarks' => $row['header_remarks'],
            'approval_status' => $row['approval_status'],
            'items' => []
        ];
    }
    
    if ($row['inventory_id']) {
        $grouped_data[$customer_key]['orders'][$order_key]['items'][] = $row;
    }
}

// Hitung total keseluruhan
$grand_total_qty = 0;
$grand_total_bal = 0;
$grand_total_subtotal = 0;

foreach ($grouped_data as $customer) {
    foreach ($customer['orders'] as $order) {
        foreach ($order['items'] as $item) {
            $quantity = (float)($item['quantity'] ?? 0);
            $quantity_pack = (float)($item['quantity_pack'] ?? 0);
            $subtotal = (float)($item['subtotal'] ?? 0);

            $detail_remarks = trim((string)($item['detail_remarks'] ?? ''));
            $header_remarks = trim((string)($order['remarks'] ?? ''));

            $remark_for_check = $detail_remarks !== '' ? $detail_remarks : $header_remarks;

            $quantity_for_total = getQuantityForTotal(
                $quantity,
                $item['uom'] ?? '',
                $item['price'] ?? 0,
                $remark_for_check
            );

            $quantity_pack_for_total = getQuantityPackForTotal(
                $quantity_pack,
                $item['uom_pack'] ?? '',
                $item['price'] ?? 0
            );

            $grand_total_qty += $quantity_for_total;
            $grand_total_bal += $quantity_pack_for_total;
            $grand_total_subtotal += $subtotal;
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
        height: calc(100vh - 20px);
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        overflow: hidden;
        margin-top: 0;
        display: flex;
        flex-direction: column;
    }

    /* PANEL ATAS FREEZE: header + filter tetap terlihat saat data discroll */
    .rekap-sticky-panel {
        flex: 0 0 auto;
        position: sticky;
        top: 0;
        z-index: 1050;
        background: #ffffff;
        box-shadow: 0 2px 8px rgba(0,0,0,0.12);
    }

    .rekap-content-scroll {
        flex: 1 1 auto;
        min-height: 0;
        padding: 15px;
        overflow-y: auto;
        overflow-x: hidden;
        background: #ffffff;
    }
    
    .rekap-header {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
        color: white;
        padding: 15px 20px;
    }
    
    .rekap-header h4 {
        margin: 0;
        font-size: 18px;
        font-family: inherit;
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
        font-family: inherit;
    }
    
    .customer-header small {
        font-size: 11px;
        color: #6c757d;
        font-family: inherit;
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
        font-family: inherit;
    }
    
    .rekap-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 10px;
        font-family: inherit;
    }
    
    .rekap-table th,
    .rekap-table td {
        border: 1px solid #dee2e6;
        padding: 6px 4px;
        vertical-align: top;
        font-family: inherit;
    }
    
    .rekap-table th {
        background: #e9ecef;
        font-weight: bold;
        text-align: center;
        font-family: inherit;
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
        font-family: inherit;
    }
    
    .btn-action {
        padding: 6px 12px;
        font-size: 12px;
        border-radius: 4px;
        margin: 0 2px;
    }
    
    /* HAPUS style khusus untuk currency - gunakan font yang sama dengan tabel */
    .rekap-table .col-currency {
        white-space: nowrap !important;
        text-align: right;
        font-family: inherit !important; /* Gunakan font yang sama dengan tabel */
        font-size: 10px;
        min-width: 110px;
        padding: 4px 6px;
    }

    .rekap-table .col-currency .currency-symbol {
        font-size: 10px; /* Sama dengan ukuran font tabel */
        color: #333; /* Warna lebih gelap agar terbaca */
        margin-right: 2px;
        font-family: inherit !important; /* Gunakan font yang sama */
        font-weight: normal;
    }

    .rekap-table .col-currency .amount {
        font-weight: 500;
        font-family: inherit !important;
    }

    /* Untuk kolom yang lebih kecil */
    .rekap-table .col-currency-small {
        min-width: 85px;
    }

    /* Untuk kolom subtotal */
    .rekap-table .col-currency-subtotal {
        min-width: 120px;
        font-weight: bold;
    }
    
    /* Style untuk cetak A5 */
    @media print {
        body {
            background: white;
            padding: 0;
            margin: 0;
            font-family: inherit;
        }
        
        .no-print {
            display: none !important;
        }
        
        .rekap-container {
            height: auto !important;
            box-shadow: none;
            border-radius: 0;
            display: block !important;
            overflow: visible !important;
        }

        .rekap-sticky-panel {
            position: static !important;
            box-shadow: none !important;
        }

        .rekap-content-scroll {
            height: auto !important;
            padding: 0 !important;
            overflow: visible !important;
        }
        
        .customer-group {
            border: 1px solid #ccc;
            page-break-inside: avoid;
        }
        
        .rekap-table th,
        .rekap-table td {
            border: 1px solid #000;
            font-family: inherit;
        }
        
        @page {
            size: A5;
            margin: 10mm;
        }
    }
    
    .rekap-table .col-inventory-name {
        white-space: normal !important;
        word-break: break-word;
        overflow-wrap: anywhere;
        min-width: 220px;
        max-width: 360px;
        line-height: 1.35;
        font-family: inherit;
    }
    
    /* Responsive untuk zoom */
    @media screen and (max-width: 1400px) {
        .rekap-table {
            font-size: 9px;
        }
        
        .rekap-table .col-currency {
            min-width: 80px;
            font-size: 9px;
        }
        
        .rekap-table .col-currency .currency-symbol {
            font-size: 9px;
        }
    }

    @media screen and (max-width: 1200px) {
        .rekap-table .col-currency {
            min-width: 70px;
            font-size: 8px;
        }
        
        .rekap-table .col-currency .currency-symbol {
            font-size: 8px;
        }
    }

    /* Style untuk filter label */
    .filter-box .form-label {
        font-family: inherit;
    }
    
    /* Style untuk input dan select di filter */
    .filter-box .form-control,
    .filter-box .form-select {
        font-family: inherit;
        font-size: 14px;
    }
</style>

<div class="rekap-container">
    <div class="rekap-sticky-panel no-print">
        <div class="rekap-header">
            <h4><i class="fa fa-file-alt"></i> Rekap Sales Order</h4>
        </div>
        
        <!-- FILTER PANEL (hanya tampil di layar) -->
        <div class="filter-box">
        <form method="GET" action="index.php" class="row g-2">
            <input type="hidden" name="page" value="rekap_sales_order">
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
                       <a href="modul/transaksi/cetak_rekap_sales_order.php?start_date=<?= urlencode($start_date_raw) ?>&end_date=<?= urlencode($end_date_raw) ?>&customer_name=<?= urlencode($customer_name) ?>&sales_name=<?= urlencode($sales_name) ?>&marketing_name=<?= urlencode($marketing_name) ?>&category=<?= urlencode($category) ?>&approval_status=<?= urlencode($approval_status) ?>" target="_blank" class="btn btn-sm btn-success">
                    <i class="fa fa-print"></i> Cetak Rekap (A5)
                </a>
                <a href="index.php?page=sales_order" class="btn btn-sm btn-secondary"><i class="fa fa-arrow-left"></i> Kembali</a>
            </div>
        </form>
        </div>
    </div>
    
    <!-- KONTEN REKAP -->
    <div class="rekap-content-scroll">
        <?php if (empty($grouped_data)): ?>
            <div class="alert alert-info text-center">Tidak ada data Sales Order ditemukan.</div>
        <?php else: ?>
            <!-- Judul untuk cetak -->
            <div style="text-align: center; margin-bottom: 20px; display: none;" class="print-title">
                <h3>PT MUTIARA CAHAYA PLASTINDO</h3>
                <h4>REKAP SALES ORDER</h4>
                <p>Periode: <?= htmlspecialchars($start_date_raw) ?> - <?= htmlspecialchars($end_date_raw) ?></p>
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
                                        <th>Inventory Name</th>
                                        <th>Remarks</th>
                                        <th>Order Qty</th>
                                        <th>UoM</th>
                                        <th>Order Bal</th>
                                        <th>UoM Pack</th>
                                        <th>Price Unit</th>
                                        <th>Price</th>
                                        <th>Price KG</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $order_total_qty = 0;
                                    $order_total_bal = 0;
                                    $order_total_subtotal = 0;

                                    foreach ($order['items'] as $item): 
                                        $quantity = (float)($item['quantity'] ?? 0);
                                        $quantity_pack = (float)($item['quantity_pack'] ?? 0);
                                        $price_unit = (float)($item['price_unit'] ?? 0);
                                        $price = (float)($item['price'] ?? 0);
                                        $subtotal = (float)($item['subtotal'] ?? 0);

                                        $detail_remarks = trim((string)($item['detail_remarks'] ?? ''));
                                        $header_remarks = trim((string)($order['remarks'] ?? ''));

                                        // Prioritas remarks detail. Kalau kosong, pakai remarks header.
                                        $remarks_display = $detail_remarks !== '' ? $detail_remarks : $header_remarks;

                                        // Hitung nilai yang boleh masuk ke Total Order Qty.
                                        $quantity_for_total = getQuantityForTotal(
                                            $quantity,
                                            $item['uom'] ?? '',
                                            $price,
                                            $remarks_display
                                        );

                                        // Hitung nilai yang boleh masuk ke Total Order Bal.
                                        $quantity_pack_for_total = getQuantityPackForTotal(
                                            $quantity_pack,
                                            $item['uom_pack'] ?? '',
                                            $price
                                        );

                                        // Price KG = subtotal / quantity
                                        $price_kg = 0;
                                        if ($quantity > 0 && $subtotal > 0) {
                                            $price_kg = $subtotal / $quantity;
                                        }

                                        $order_total_qty += $quantity_for_total;
                                        $order_total_bal += $quantity_pack_for_total;
                                        $order_total_subtotal += $subtotal;
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($item['inventory_id']) ?></td>

                                        <td><?= htmlspecialchars($item['inventory_name']) ?></td>

                                        <td class="text-center">
                                            <?= htmlspecialchars($remarks_display) ?>
                                        </td>

                                        <td class="text-right">
                                            <?= number_format($quantity, 2, ',', '.') ?>
                                            
                                        </td>

                                        <td class="text-center">
                                            <?= htmlspecialchars($item['uom']) ?>
                                        </td>

                                        <td class="text-right">
                                            <?= number_format($quantity_pack, 2, ',', '.') ?>
                                        </td>

                                        <td class="text-center">
                                            <?= htmlspecialchars($item['uom_pack']) ?>
                                        </td>

                                       <td class="text-right col-currency">
                                            <?php if ($price_unit > 0): ?>
                                                <span class="currency-symbol">Rp</span><span class="amount"><?= number_format($price_unit, 2, ',', '.') ?></span>
                                            <?php else: ?>
                                                <span style="color: #999;">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-right col-currency">
                                            <?php if ($price > 0): ?>
                                                <span class="currency-symbol">Rp</span><span class="amount"><?= number_format($price, 2, ',', '.') ?></span>
                                            <?php else: ?>
                                                <span style="color: #999;">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-right col-currency">
                                            <?php if ($price_kg > 0): ?>
                                                <span class="currency-symbol">Rp</span><span class="amount"><?= number_format($price_kg, 2, ',', '.') ?></span>
                                            <?php else: ?>
                                                <span style="color: #999;">-</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-right col-currency col-currency-subtotal">
                                            <span class="currency-symbol">Rp</span><span class="amount"><?= number_format($subtotal, 2, ',', '.') ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>

                                    <tr style="background: #f0f0f0; font-weight: bold;">
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
                                        <td class="text-right col-currency col-currency-subtotal">
                                            <span class="currency-symbol">Rp</span><span class="amount"><?= number_format($order_total_subtotal, 2, ',', '.') ?></span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                           
                            
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            
           <div class="grand-total">
            GRAND TOTAL KESELURUHAN:
            &nbsp; Order Qty: <strong><?= number_format($grand_total_qty, 2, ',', '.') ?></strong>
            &nbsp; | &nbsp; Order Bal: <strong><?= number_format($grand_total_bal, 2, ',', '.') ?></strong>
            &nbsp; | &nbsp; Subtotal: <strong>Rp <?= number_format($grand_total_subtotal, 2, ',', '.') ?></strong>
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
      '<p>Periode: <?= htmlspecialchars($start_date_raw) ?> - <?= htmlspecialchars($end_date_raw) ?></p>' +
        '<hr>' +
        '</div>';
    
    // Sembunyikan filter saat print
    var style = document.createElement('style');
    style.innerHTML = '@media print { .filter-box, .rekap-header, .no-print { display: none !important; } }';
    document.head.appendChild(style);
});

</script>
<script>
// Menambahkan judul saat print
$(document).ready(function() {
    flatpickr(".datepicker", {
        dateFormat: "d-M-Y",
        allowInput: true,
        disableMobile: true
    });

    $('.print-title').remove();

    var titleHtml = '<div class="print-title" style="text-align: center; margin-bottom: 20px;">' +
        '<h3>CP</h3>' +
        '<h4>REKAP SALES ORDER</h4>' +
        '<p>Periode: <?= htmlspecialchars($start_date_raw) ?> - <?= htmlspecialchars($end_date_raw) ?></p>' +
        '<hr>' +
        '</div>';

    var style = document.createElement('style');
    style.innerHTML = '@media print { .filter-box, .rekap-header, .no-print { display: none !important; } }';
    document.head.appendChild(style);
});
</script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>