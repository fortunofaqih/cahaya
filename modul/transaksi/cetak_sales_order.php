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

// Ambil data dari detail_sales_order berdasarkan order_no
$query_detail_so = mysqli_query($conn, "SELECT * FROM detail_sales_order WHERE order_no='$no_so'");

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
$query_det_po = mysqli_query($conn, "SELECT * FROM det_po WHERE no_po='$no_po'");

if (!$h) {
    echo "<script>alert('Data PO tidak ditemukan!'); window.location.href='index.php?page=sales_order';</script>";
    exit;
}

// Ambil semua data detail_sales_order ke array
$detail_so_array = [];
while ($row_detail = mysqli_fetch_assoc($query_detail_so)) {
    $detail_so_array[] = $row_detail;
}

// Ambil semua data det_po ke array
$det_po_array = [];
while ($row_det_po = mysqli_fetch_assoc($query_det_po)) {
    $det_po_array[] = $row_det_po;
}

// Pastikan jumlah data sama, jika tidak, ambil jumlah minimal
$total_rows = min(count($detail_so_array), count($det_po_array));
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
            max-width: 900px;
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
            font-size: 10px;
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
            <tr>
                <td>Nomor SO</td>
                <td>: <?= htmlspecialchars($no_so); ?></td>
             </tr>
             <tr>
                <td>Tanggal</td>
                <td>: <?= date('d/m/Y', strtotime($h['tgl_order'])); ?></td>
             </tr>
             <tr>
                <td>Customer</td>
                <td>: <?= htmlspecialchars($h['customer']); ?></td>
             </tr>
             <tr>
                <td>PO Customer</td>
                <td>: <?= htmlspecialchars($no_po); ?></td>
             </tr>
         </table>
         
         <table class="main-table">
            <thead>
                <tr>
                    <th width="3%">No</th>
                    <th width="30%">Ukuran / Deskripsi</th>
                    <th width="10%">Qty</th>
                    <th width="10%">Qty Pack</th>
                    <th width="10%">UoM Pack</th>
                    <th width="15%">Harga</th>
                    <th width="15%">Harga/Kg</th>
                </tr>
            </thead>
           <tbody>
            <?php 
            $no = 1; 
            $total_harga = 0;
            
            // Loop berdasarkan jumlah data yang tersedia
            for ($i = 0; $i < $total_rows; $i++) {
                $det_po = $det_po_array[$i];
                $detail_so = $detail_so_array[$i];

                // ================================
                // DATA DASAR DARI DETAIL SO
                // ================================
                $qty_raw = isset($detail_so['quantity']) ? (float)$detail_so['quantity'] : 0;
                $qty_pack_raw = isset($detail_so['quantity_pack']) ? (float)$detail_so['quantity_pack'] : 0;
                $uom_pack_raw = isset($detail_so['uom_pack']) ? trim($detail_so['uom_pack']) : '';

                $price_unit_raw = isset($detail_so['price_unit']) ? (float)$detail_so['price_unit'] : 0;
                $price_raw = isset($detail_so['price']) ? (float)$detail_so['price'] : 0;
                $subtotal_raw = isset($detail_so['subtotal']) ? (float)$detail_so['subtotal'] : 0;

                // ================================
                // KOLOM HARGA
                // Jika price_unit diisi, ambil dari price_unit
                // Jika price_unit = 0, ambil dari price
                // ================================
                $harga = 0;
                
                if ($price_unit_raw > 0) {
                    // Jika price_unit diisi, gunakan price_unit
                    $harga = $price_unit_raw;
                } elseif ($price_raw > 0) {
                    // Jika price_unit 0, gunakan price
                    $harga = $price_raw;
                }

                // ================================
                // KOLOM HARGA/KG
                // Rumus: Subtotal / Qty (jika Qty > 0)
                // ================================
                $harga_kg = 0;
                
                if ($qty_raw > 0 && $subtotal_raw > 0) {
                    $harga_kg = $subtotal_raw / $qty_raw;
                }

                // ================================
                // CEK APAKAH HARGA KOSONG
                // Jika harga = 0, maka semua kolom dikosongkan
                // ================================
                if ($harga == 0) {
                    $qty_raw = 0;
                    $qty_pack_raw = 0;
                    $uom_pack_raw = '';
                }

                // ================================
                // FORMAT TAMPILAN
                // ================================
                $qty = $qty_raw > 0 ? number_format($qty_raw, 2, ',', '.') : '-';
                $qty_pack = $qty_pack_raw > 0 ? number_format($qty_pack_raw, 2, ',', '.') : '-';
                $uom_pack = $uom_pack_raw !== '' ? htmlspecialchars($uom_pack_raw) : '-';

                $ukuran = isset($det_po['ukuran']) && $det_po['ukuran'] !== ''
                    ? htmlspecialchars($det_po['ukuran'])
                    : htmlspecialchars($detail_so['inventory_name'] ?? '-');
                
                $harga_display = $harga > 0 ? 'Rp ' . number_format($harga, 0, ',', '.') : '-';
                $harga_kg_display = $harga_kg > 0 ? 'Rp ' . number_format($harga_kg, 0, ',', '.') : '-';
                
                // Akumulasi total harga
                $total_harga += $harga;
            ?>
            <tr>
                <td class="text-center"><?= $no++ ?>.</td>
                <td><?= $ukuran; ?></td>
                <td class="text-center"><?= $qty; ?></td>
                <td class="text-center"><?= $qty_pack; ?></td>
                <td class="text-center"><?= $uom_pack; ?></td>
                <td class="text-right"><?= $harga_display; ?></td>
                <td class="text-right"><?= $harga_kg_display; ?></td>
            </tr>
            <?php } ?>

            <?php if ($total_rows == 0) { ?>
            <tr>
                <td colspan="7" class="text-center">Tidak ada data</td>
            </tr>
            <?php } else { ?>
            <!-- Total Row -->
            <tr class="total-row">
                <td colspan="5" class="text-right">TOTAL</td>
                <td class="text-right">Rp <?= number_format($total_harga, 0, ',', '.') ?></td>
                <td></td>
            </tr>
            <?php } ?>
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