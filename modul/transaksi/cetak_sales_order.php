<?php
// modul/transaksi/cetak_sales_order.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// Ambil parameter no_so dari URL
$no_so = isset($_GET['no_so']) ? mysqli_real_escape_string($conn, $_GET['no_so']) : '';

if (empty($no_so)) {
    echo "<script>alert('Nomor SO tidak ditemukan!'); window.location.href='index.php?page=sales_order';</script>";
    exit;
}

// Cari no_po di head_sales_order berdasarkan order_no
$q_cari_po = mysqli_query($conn, "SELECT po FROM head_sales_order WHERE order_no='$no_so'");
$data_so = mysqli_fetch_assoc($q_cari_po);

if (!$data_so || empty($data_so['po'])) {
    echo "<script>alert('PO Number tidak ditemukan untuk SO ini!'); window.location.href='index.php?page=sales_order';</script>";
    exit;
}

$no_po = $data_so['po'];

// Ambil data header dari hed_po berdasarkan no_po
$query_h = mysqli_query($conn, "SELECT * FROM hed_po WHERE no_po='$no_po'");
$h = mysqli_fetch_assoc($query_h);

// Ambil data detail dari det_po berdasarkan no_po
$d = mysqli_query($conn, "SELECT * FROM det_po WHERE no_po='$no_po'");

if (!$h) {
    echo "<script>alert('Data PO tidak ditemukan!'); window.location.href='index.php?page=sales_order';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Cetak Sales Order - <?= htmlspecialchars($no_so); ?></title>
    <style>
        /* Reset semua style */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Arial', sans-serif;
            background: white;
            padding: 20px;
        }
        
        /* Container cetak */
        .print-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
        }
        
        .company-name {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .title {
            text-align: center;
            font-size: 18px;
            font-weight: bold;
            text-decoration: underline;
            margin-bottom: 20px;
            text-transform: uppercase;
        }
        
        .info-table {
            width: 100%;
            margin-bottom: 20px;
            font-size: 12px;
            border-collapse: collapse;
        }
        
        .info-table td {
            padding: 3px 0;
        }
        
        .info-table td:first-child {
            width: 100px;
            font-weight: bold;
        }
        
        table.main-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 11px;
        }
        
        table.main-table th,
        table.main-table td {
            border: 1px solid black;
            padding: 6px;
        }
        
        table.main-table th {
            background: #e8e8e8;
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .text-center {
            text-align: center;
        }
        
        .total-row {
            background: #f0f0f0;
            font-weight: bold;
        }
        
        .signature-container {
            width: 100%;
            margin-top: 40px;
            overflow: hidden;
        }
        
        .sig-box {
            width: 48%;
            float: left;
            text-align: center;
            font-size: 11px;
        }
        
        .sig-box-right {
            width: 48%;
            float: right;
            text-align: center;
            font-size: 11px;
        }
        
        .sig-box p,
        .sig-box-right p {
            margin-bottom: 50px;
        }
        
        /* Tombol - HANYA TAMPIL DI LAYAR, TIDAK DI PRINT */
        .action-buttons {
            text-align: center;
            margin-top: 30px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            position: fixed;
            bottom: 20px;
            left: 0;
            right: 0;
            z-index: 1000;
        }
        
        .btn-print, .btn-back {
            display: inline-block;
            padding: 10px 20px;
            margin: 0 5px;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
            cursor: pointer;
            border: none;
        }
        
        .btn-print {
            background: #0d6efd;
            color: white;
        }
        
        .btn-print:hover {
            background: #0b5ed7;
        }
        
        .btn-back {
            background: #6c757d;
            color: white;
        }
        
        .btn-back:hover {
            background: #5c636a;
        }
        
        /* SAAT PRINT - SEMUA TOMBOL HILANG, UKURAN A4 */
        @media print {
            body {
                padding: 0;
                margin: 0;
            }
            
            .print-container {
                margin: 0;
                padding: 10mm;
                width: 100%;
            }
            
            .action-buttons {
                display: none !important;
            }
            
            @page {
                size: A4;
                margin: 15mm;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="company-name">PT MUTIARA CAHAYA PLASTINDO</div>
        <div class="title">SALES ORDER</div>
        
        <table class="info-table">
            <tr><td>Nomor SO</td><td>: <?= htmlspecialchars($no_so); ?></td></tr>
            <tr><td>Tanggal</td><td>: <?= date('d/m/Y', strtotime($h['tgl_order'])); ?></td></tr>
            <tr><td>Customer</td><td>: <?= htmlspecialchars($h['customer']); ?></td></tr>
        </table>
        
        <table class="main-table">
            <thead>
                <tr>
                    <th width="5%">No</th>
                    <th>Ukuran / Deskripsi</th>
                    <th width="15%">Jml Order</th>
                    <th width="20%">Harga</th>
                    <th width="20%">Harga/Kg</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1; 
                $total_harga = 0;
                while($row = mysqli_fetch_assoc($d)){ 
                    $jml_order = floatval(preg_replace('/[^0-9]/', '', $row['jml_order']));
                    $harga_item = floatval(preg_replace('/[^0-9]/', '', $row['harga']));
                    $subtotal = $jml_order * $harga_item;
                    $total_harga += $subtotal;
                ?>
                <tr>
                    <td class="text-center"><?= $no++ ?>.</td>
                    <td><?= htmlspecialchars($row['ukuran']); ?></td>
                    <td class="text-center"><?= htmlspecialchars($row['jml_order']); ?></td>
                    <td class="text-right">Rp <?= number_format($harga_item, 0, ',', '.'); ?></td>
                    <td class="text-right">
                        <?php 
                        if ($row['harga_kg'] > 0) {
                            echo 'Rp ' . number_format($row['harga_kg'], 0, ',', '.');
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                </tr>
                <?php } ?>
                <tr class="total-row">
                    <td colspan="4" class="text-right"><strong>TOTAL HARGA</strong></td>
                    <td class="text-right"><strong>Rp <?= number_format($total_harga, 0, ',', '.'); ?></strong></td>
                </tr>
            </tbody>
        </table>
        
        <div class="signature-container">
            <div class="sig-box">
                <p>Diperiksa Oleh :</p>
                <div>( ____________________ )</div>
            </div>
            <div class="sig-box-right">
                <p>Dibuat Oleh :</p>
                <div>( <?= strtoupper(htmlspecialchars($h['created_by'])); ?> )</div>
            </div>
        </div>
    </div>
    
    <!-- Tombol hanya muncul di layar, tidak ikut print -->
    <div class="action-buttons">
        <button class="btn-print" onclick="window.print(); return false;">🖨️ Cetak SO</button>
        <a href="http://localhost/cahaya/index.php?page=sales_order" class="btn-back">← Kembali ke List SO</a>
    </div>
    
    <script>
        // Otomatis membuka dialog print (opsional - hapus comment jika ingin auto print)
        // window.print();
    </script>
</body>
</html>