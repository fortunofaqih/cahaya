<?php
// modul/transaksi/cetak_shipping.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// Ambil parameter
$shipping_no = isset($_GET['id']) ? mysqli_real_escape_string($conn, trim($_GET['id'])) : '';
$type = isset($_GET['type']) ? $_GET['type'] : 'slip'; // slip atau slip_without_uom

if (empty($shipping_no)) {
    die("Shipping No tidak ditemukan!");
}

// Fungsi format tanggal ke Indonesia
function formatDateIndonesian($date) {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }
    
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    
    $timestamp = strtotime($date);
    if (!$timestamp) return '';
    
    $tanggal = date('d', $timestamp);
    $bulan_num = (int)date('m', $timestamp);
    $tahun = date('Y', $timestamp);
    
    return $tanggal . ' ' . $bulan[$bulan_num] . ' ' . $tahun;
}

function formatDateShort($date) {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }
    
    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];
    
    $timestamp = strtotime($date);
    if (!$timestamp) return '';
    
    $tanggal = date('d', $timestamp);
    $bulan_num = (int)date('m', $timestamp);
    $tahun = date('Y', $timestamp);
    
    return $tanggal . '-' . $bulan[$bulan_num] . '-' . $tahun;
}

// Ambil data shipping
$query = mysqli_query($conn, "
    SELECT 
        hs.*,
        mg.name AS gudang_name
    FROM hed_shipping hs
    LEFT JOIN m_gudang mg ON hs.gudang_id = mg.gudang_id
    WHERE hs.shipping_no = '$shipping_no'
");

if (!$query || mysqli_num_rows($query) == 0) {
    die("Data shipping tidak ditemukan!");
}

$header = mysqli_fetch_assoc($query);

// Ambil detail shipping
$query_detail = mysqli_query($conn, "
    SELECT *
    FROM det_shipping
    WHERE shipping_no = '$shipping_no'
    ORDER BY id ASC
");

$details = [];
while ($row = mysqli_fetch_assoc($query_detail)) {
    $details[] = $row;
}

// Format data
$shipping_no_display = $header['shipping_no'];
$shipping_date_display = formatDateIndonesian($header['shipping_date']);
$shipping_date_short = formatDateShort($header['shipping_date']);
$order_date_display = formatDateShort($header['order_date']);
$customer_name = $header['customer_name'];
$customer_address = $header['customer_address'];
$customer_city = $header['customer_city'];
$order_no = $header['order_no'];

// Tentukan judul berdasarkan tipe
$title = ($type == 'slip_without_uom') ? 'SLIP WITHOUT UOM DEFAULT' : 'SLIP';
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Cetak Shipping - <?= $shipping_no_display ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', Courier, monospace;
            background: #fff;
            padding: 20px;
            font-size: 12px;
        }
        
        .print-container {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 30px 40px;
            border: 1px solid #ddd;
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .company-name {
            font-size: 16px;
            font-weight: bold;
            letter-spacing: 1px;
        }
        
        .doc-title {
            font-size: 14px;
            font-weight: bold;
            text-align: right;
        }
        
        .doc-title .no {
            font-size: 12px;
            font-weight: normal;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 12px;
        }
        
        .info-row .label {
            font-weight: bold;
        }
        
        .customer-section {
            margin: 15px 0 20px 0;
            padding: 10px 0;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }
        
        .customer-section .row {
            margin-bottom: 3px;
            font-size: 12px;
        }
        
        .customer-section .label {
            font-weight: bold;
        }
        
        .customer-section .customer-name {
            font-weight: bold;
            font-size: 13px;
        }
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 11px;
        }
        
        .items-table th {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 6px 4px;
            text-align: center;
            font-weight: bold;
        }
        
        .items-table td {
            padding: 4px;
            border-bottom: 1px solid #ccc;
            vertical-align: top;
        }
        
        .items-table .text-center {
            text-align: center;
        }
        
        .items-table .text-right {
            text-align: right;
        }
        
        .items-table .col-no { width: 30px; }
        .items-table .col-inventory { width: 60%; }
        .items-table .col-qty { width: 80px; }
        .items-table .col-uom { width: 60px; }
        .items-table .col-qty-pack { width: 80px; }
        .items-table .col-uom-pack { width: 60px; }
        .items-table .col-adjustment { width: 80px; }
        
        .signature-section {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            padding-top: 20px;
        }
        
        .signature-section .sign-box {
            text-align: center;
            width: 30%;
        }
        
        .signature-section .sign-box .line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 5px;
            font-size: 10px;
        }
        
        .print-btn {
            display: block;
            margin: 20px auto;
            padding: 10px 30px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
        }
        
        .print-btn:hover {
            background: #0056b3;
        }
        
        .btn-group {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .btn-group .btn {
            padding: 8px 20px;
            margin: 0 5px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #007bff;
            color: #fff;
        }
        
        .btn-primary:hover {
            background: #0056b3;
        }
        
        .btn-success {
            background: #28a745;
            color: #fff;
        }
        
        .btn-success:hover {
            background: #218838;
        }
        
        .btn-warning {
            background: #ffc107;
            color: #000;
        }
        
        .btn-warning:hover {
            background: #e0a800;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        @media print {
            body {
                padding: 0;
                background: #fff;
            }
            .print-container {
                border: none;
                padding: 20px 30px;
            }
            .no-print {
                display: none !important;
            }
            .items-table td {
                border-bottom: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>

<!-- Tombol Aksi (Tidak Muncul Saat Print) -->
<div class="btn-group no-print">
    <a href="index.php?page=shipping" class="btn btn-secondary">
        <i class="fa fa-arrow-left"></i> Kembali
    </a>
    <a href="index.php?page=cetak_shipping&id=<?= urlencode($shipping_no) ?>&type=slip" class="btn btn-primary <?= ($type == 'slip') ? 'active' : '' ?>">
        Slip
    </a>
    <a href="index.php?page=cetak_shipping&id=<?= urlencode($shipping_no) ?>&type=slip_without_uom" class="btn btn-warning <?= ($type == 'slip_without_uom') ? 'active' : '' ?>">
        Slip Without UoM Default
    </a>
    <button onclick="window.print()" class="btn btn-success">
        <i class="fa fa-print"></i> Cetak / Print
    </button>
</div>

<!-- KONTEN PRINT -->
<div class="print-container" id="printArea">
    
    <!-- HEADER -->
    <div class="header-top">
        <div class="company-name">PT. MUTIARACAHAYA PLASTINDO</div>
        <div class="doc-title">
            <?= $title ?>
            <div class="no">No: <?= $shipping_no_display ?></div>
        </div>
    </div>
    
    <!-- INFO TANGGAL & ORDER -->
    <div class="info-row">
        <span><?= $shipping_date_display ?></span>
        <span>Order Date: <?= $order_date_display ?></span>
        <span>Order No: <?= $order_no ?></span>
    </div>
    
    <!-- CUSTOMER INFO -->
    <div class="customer-section">
        <div class="row">
            <span class="label">Kepada Yth.</span>
        </div>
        <div class="row">
            <span class="customer-name"><?= htmlspecialchars($customer_name) ?></span>
        </div>
        <div class="row">
            <span><?= htmlspecialchars($customer_address) ?></span>
        </div>
        <div class="row">
            <span><?= htmlspecialchars($customer_city) ?></span>
        </div>
    </div>
    
    <!-- TABLE ITEMS -->
    <table class="items-table">
        <thead>
            <tr>
                <th class="col-no">No</th>
                <th class="col-inventory">Nama Barang</th>
                <?php if ($type == 'slip'): ?>
                <th class="col-qty">Qty</th>
                <th class="col-uom">UoM</th>
                <th class="col-qty-pack">Qty Pack</th>
                <th class="col-uom-pack">UoM Pack</th>
                <th class="col-adjustment">Adjustment</th>
                <?php else: ?>
                <th class="col-qty">Qty</th>
                <th class="col-uom">UoM</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php 
            $no = 1;
            $total_qty = 0;
            $total_qty_pack = 0;
            
            foreach ($details as $detail): 
                $total_qty += floatval($detail['qty_shipping']);
                $total_qty_pack += floatval($detail['qty_pack_shipping']);
            ?>
            <tr>
                <td class="text-center"><?= $no++ ?></td>
                <td><?= htmlspecialchars($detail['inventory_name']) ?></td>
                <?php if ($type == 'slip'): ?>
                <td class="text-right"><?= number_format($detail['qty_shipping'], 2, ',', '.') ?></td>
                <td class="text-center"><?= htmlspecialchars($detail['uom_shipping']) ?></td>
                <td class="text-right"><?= number_format($detail['qty_pack_shipping'], 2, ',', '.') ?></td>
                <td class="text-center"><?= htmlspecialchars($detail['uom_pack_shipping']) ?></td>
                <td class="text-right"><?= number_format($detail['adjustment_shipping'], 2, ',', '.') ?></td>
                <?php else: ?>
                <td class="text-right"><?= number_format($detail['qty_shipping'], 2, ',', '.') ?></td>
                <td class="text-center"><?= htmlspecialchars($detail['uom_shipping']) ?></td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <?php if ($type == 'slip'): ?>
        <tfoot>
            <tr>
                <td colspan="2" style="text-align:right; font-weight:bold; border-top:2px solid #000; padding-top:8px;">TOTAL</td>
                <td style="text-align:right; font-weight:bold; border-top:2px solid #000; padding-top:8px;">
                    <?= number_format($total_qty, 2, ',', '.') ?>
                </td>
                <td style="border-top:2px solid #000; padding-top:8px;"></td>
                <td style="text-align:right; font-weight:bold; border-top:2px solid #000; padding-top:8px;">
                    <?= number_format($total_qty_pack, 2, ',', '.') ?>
                </td>
                <td style="border-top:2px solid #000; padding-top:8px;"></td>
                <td style="border-top:2px solid #000; padding-top:8px;"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    
    <!-- TANDA TANGAN -->
    <div class="signature-section">
        <div class="sign-box">
            <div class="line">Hormat Kami,</div>
            <div style="margin-top:30px; font-weight:bold;">( _________________ )</div>
        </div>
        <div class="sign-box">
            <div class="line">Penerima,</div>
            <div style="margin-top:30px; font-weight:bold;">( _________________ )</div>
        </div>
        <div class="sign-box">
            <div class="line">Driver,</div>
            <div style="margin-top:30px; font-weight:bold;">( _________________ )</div>
        </div>
    </div>
    
    <!-- FOOTER PRINT -->
    <div style="margin-top:20px; font-size:9px; color:#999; text-align:center; border-top:1px solid #eee; padding-top:10px;">
        Dicetak: <?= date('d-m-Y H:i:s') ?> | <?= $_SESSION['username'] ?? 'System' ?>
    </div>
</div>

<script>
// Auto print jika ada parameter print=1
<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
window.onload = function() {
    setTimeout(function() {
        window.print();
    }, 500);
};
<?php endif; ?>
</script>

</body>
</html>