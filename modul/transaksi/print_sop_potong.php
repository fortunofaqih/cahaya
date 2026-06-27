<?php
// modul/transaksi/print_sop_gabungan.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Path ke koneksi.php
if (!isset($conn)) {
    include_once __DIR__ . '/../../koneksi.php';
}

if (!isset($conn) || !$conn) {
    die("Koneksi database gagal. Silakan periksa file koneksi.php");
}

$sop_id = isset($_GET['sop_id']) ? mysqli_real_escape_string($conn, $_GET['sop_id']) : '';
if (empty($sop_id)) {
    die("SOP ID tidak ditemukan");
}

// Ambil data header & detail SOP
$head_query = mysqli_query($conn, "SELECT * FROM head_sop WHERE sop_id = '$sop_id' LIMIT 1");
$head = mysqli_fetch_assoc($head_query);
if (!$head) {
    die("Data SOP tidak ditemukan");
}

$detail_query = mysqli_query($conn, "SELECT * FROM det_sop WHERE sop_id = '$sop_id' LIMIT 1");
$detail = mysqli_fetch_assoc($detail_query);
if (!$detail) {
    die("Detail SOP tidak ditemukan");
}

$tgl_order = date('d-M-Y', strtotime($head['sop_date']));
$tgl_kirim = !empty($detail['shipment_due_date']) ? date('d-M-Y', strtotime($detail['shipment_due_date'])) : '-';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function displayText($value, $fallback = '-') {
    $value = trim((string)$value);
    return $value !== '' ? h($value) : h($fallback);
}

function displayOrderPotong($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return '-';
    }

    // Kalau value sudah berisi satuan, contoh: "10 ROL", "500 LBR", "10 BAL", tampilkan apa adanya.
    // number_format hanya dipakai untuk angka murni supaya tidak error saat value bertipe string.
    $numeric = str_replace(',', '.', $value);
    if (is_numeric($numeric)) {
        return number_format((float)$numeric, 0) . ' PCS';
    }

    return h($value);
}

header("Content-Type: text/html; charset=UTF-8");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SOP POTONG - <?= $sop_id ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        /* ====================================================
           SETTING UKURAN KERTAS F4 LANDSCAPE UTUH (33cm x 21.5cm)
           ==================================================== */
        @page {
            size: 33cm 21.5cm; /* Ukuran standar kertas Folio / F4 posisi tidur */
            margin: 5mm 6mm 5mm 6mm;
        }
        
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            background: white;
            color: #000;
            line-height: 1.15;
            width: 100%;
        }
        
        /* Container Utama Flexbox untuk membagi 2 kolom berdampingan */
        .flex-container {
            display: flex;
            width: 100%;
            justify-content: space-between;
        }
        
        /* Sisi Kiri (Rol) & Sisi Kanan (Potong) masing-masing max 48% agar ada jarak di tengah */
        .sisi-cetak {
            width: 48%;
            display: flex;
            flex-direction: column;
        }
        
        /* Header */
        .header {
            text-align: left;
            margin-bottom: 5px;
        }
        
        .header h1 {
            font-size: 11pt;
            font-weight: bold;
            text-decoration: underline;
        }
        
        /* Info Box / Spesifikasi */
        .info-box {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
            font-size: 10pt;
        }
        
        .info-box td {
            padding: 0.5px 2px;
            vertical-align: top;
        }
        
        .info-box td.label { width: 30%; }
        .info-box td.titik { width: 3%; }
        .info-box td.val   { width: 67%; }
        
        /* Global Table Style */
        .produksi-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }
        
        .produksi-table th, 
        .produksi-table td {
            border: 1px solid #000;
            padding: 0; 
            text-align: center;
            vertical-align: middle;
        }
        
        .produksi-table th {
            font-weight: normal;
            font-size: 8pt;
        }
        
        .produksi-table tbody td {
            height: 22px; /* Disesuaikan agar pas di sisa ruang vertikal */
        }
        
        /* Kolom khusus Tabel Rol (Kiri) */
        .col-rol-tgl   { width: 10%; }
        .col-rol-shf   { width: 15%; }
        .col-rol-total { width: 17%; }
        .col-rol-sisa  { width: 16%; }
        .col-rol-prf   { width: 12%; }
        
        /* Kolom khusus Tabel Potong (Kanan) */
        .col-pot-tgl   { width: 7%; }
        .col-pot-rol   { width: 9%; }
        .col-pot-kg    { width: 9%; }
        .col-pot-shf   { width: 9%; }
        
        /* Footer TTD Area */
        .ttd-section {
            margin-top: 12px;
            width: 100%;
            font-size: 10pt;
        }
        
        .print-btn {
            display: block;
            text-align: center;
            margin-bottom: 15px;
        }
        
        .btn-print {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 20px;
            cursor: pointer;
            font-size: 11pt;
            border-radius: 5px;
        }
        
        @media print {
            .print-btn { display: none; }
            body { padding: 0; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="print-btn">
        <button class="btn-print" onclick="window.print();">🖨️ Cetak / Print</button>
    </div>
    <div class="flex-container">
    <div class="sisi-cetak">
            <div class="header">
                <h1>Surat Perintah Kerja Potong (CP)</h1>
            </div>
        
                    <table class="info-box">
                        <tr><td class="label">Tgl Order</td><td class="titik">:</td><td class="val"><?= $tgl_order ?></td></tr>
                        <tr><td class="label">Nama Customer</td><td class="titik">:</td><td class="val"><?= displayText($head['customer'] ?? '') ?></td></tr>
                        <tr><td class="label">Berat Jenis</td><td class="titik">:</td><td class="val"><?= displayText($detail['berat_jenis_potong'] ?? '') ?></td></tr>
                        <tr><td class="label">Spec</td><td class="titik">:</td><td class="val"><?= displayText($detail['spec_potong'] ?? '') ?></td></tr>
                        <tr><td class="label">Ukuran</td><td class="titik">:</td><td class="val"><?= displayText(($detail['ukuran_potong'] ?? '') !== '' ? $detail['ukuran_potong'] : ($detail['ukuran_rol'] ?? '')) ?></td></tr>
                        <tr><td class="label">Jumlah Order</td><td class="titik">:</td><td class="val"><?= displayOrderPotong($detail['jml_order_potong'] ?? '') ?></td></tr>
                        <tr><td class="label">Isi per pak & Bal</td><td class="titik">:</td><td class="val"><?= displayText($detail['isi_pakbal_potong'] ?? '') ?></td></tr>
                        <tr><td class="label">Keterangan</td><td class="titik">:</td><td class="val"><?= displayText(($detail['keterangan_potong'] ?? '') !== '' ? $detail['keterangan_potong'] : ($detail['keterangan_rol'] ?? '')) ?></td></tr>
                        <tr><td class="label">Kode</td><td class="titik">:</td><td class="val"><?= displayText(($detail['code_potong'] ?? '') !== '' ? $detail['code_potong'] : ($detail['code_rol'] ?? '')) ?></td></tr>
                        <tr><td class="label">Tgl Kirim</td><td class="titik">:</td><td class="val"><?= $tgl_kirim ?></td></tr>
                    </table>
            
        <table class="produksi-table">
            <thead>
                <tr>
                    <th colspan="3" style="width: 23%;">Bahan Potong</th>
                    <th colspan="9" style="width: 77%;">Hasil Produksi Potong</th>
                </tr>
                <tr>
                    <th rowspan="2" class="col-tgl">Tgl</th>
                    <th colspan="2" style="height: 14px;">Jumlah Bahan</th>
                    <th colspan="3">Shift I</th>
                    <th colspan="3">Shift II</th>
                    <th colspan="3">Shift III</th>
                </tr>
                <tr>
                    <th class="col-bahan">Rol</th>
                    <th class="col-bahan">Kg</th>
                    <th class="col-hasil">Hasil</th>
                    <th class="col-afv">Afv</th>
                    <th class="col-pct">Afv%</th>
                    <th class="col-hasil">Hasil</th>
                    <th class="col-afv">Afv</th>
                    <th class="col-pct">Afv%</th>
                    <th class="col-hasil">Hasil</th>
                    <th class="col-afv">Afv</th>
                    <th class="col-pct">Afv%</th>
                </tr>
            </thead>
            <tbody>
                <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            </tbody>
        </table>
        
        <table class="ttd-section">
            <tr>
                <td style="text-align: left; padding-left: 25px;">
                    Dibuat Oleh,<br><br><br><br>
                    ( &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; )
                </td>
                <td style="text-align: right; padding-right: 20px;">
                    Diperiksa Oleh,<br><br><br><br>
                    ( &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; )
                </td>
            </tr>
        </table>
        
        <div class="footer" style="margin-top: 15px; font-size: 7.5pt; text-align: center; border-top: 1px solid #ccc; padding-top: 3px;">
            Dicetak pada: <?= date('d-m-Y H:i:s') ?> | SOP: <?= $sop_id ?>
        </div>
    </div>
</body>
</html>