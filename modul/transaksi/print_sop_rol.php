<?php
// modul/transaksi/print_sop_rol.php
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

$tgl_order = date('d-M-Y', strtotime($head['sop_date']));
$tgl_kirim = !empty($detail['shipment_due_date']) ? date('d-M-Y', strtotime($detail['shipment_due_date'])) : '-';

header("Content-Type: text/html; charset=UTF-8");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SPK GABUNGAN - <?= $sop_id ?></title>
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
            font-family: 'Courier New', 'Segoe UI', monospace;
            font-size: 8.5pt;
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
            font-size: 8.5pt;
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
            font-size: 8.5pt;
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
        <button class="btn-print" onclick="window.print();">🖨️ Cetak Roll</button>
    </div>
    
    <div class="flex-container">
        
        <div class="sisi-cetak">
            <div class="header">
                <h1>Surat Perintah Kerja Rol (CP)</h1>
            </div>
            
            <table class="info-box">
                <tr><td class="label">Tgl Order</td><td class="titik">:</td><td class="val"><?= $tgl_order ?></td></tr>
                <tr><td class="label">Nama Customer</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($head['customer'] ?: '-') ?></td></tr>
                <tr><td class="label">Berat Jenis</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['berat_jenis_rol'] ?: $detail['spec_rol'] ?: '-') ?></td></tr>
                <tr><td class="label">Ukuran</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['ukuran_rol'] ?: '-') ?></td></tr>
                <tr><td class="label">Berat/Rol</td><td class="titik">:</td><td class="val"><?= number_format($detail['berat_rol'] ?: 0, 2) ?> KG/ROL</td></tr>
                <tr><td class="label">Isi/Bal</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['isi_bal_rol'] ?: '-') ?></td></tr>
                <tr><td class="label">Jumlah Order</td><td class="titik">:</td><td class="val"><?= number_format($detail['jml_order_rol'] ?: 0, 0) ?> ROLL ROLL ( KG )</td></tr>
                <tr><td class="label">Treat/Tidak</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['treat_rol'] ?: '-') ?></td></tr>
                <tr><td class="label">Nat/Warna</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['nat_warna_rol'] ?: $detail['nat_warna_potong'] ?: '-') ?></td></tr>
                <tr><td class="label">Bobin/Krepyak</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['bobin_krepyak_rol'] ?: '-') ?></td></tr>
                <tr><td class="label">Kirim/Las</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['kirim_las_rol'] ?: '-') ?></td></tr>
                <tr><td class="label">Std Pengecekan</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['standar_cek_rol'] ?: '-') ?></td></tr>
                <tr><td class="label">Gramatur Asli</td><td class="titik">:</td><td class="val"><?= number_format($detail['gramatur_asli_rol'] ?: 0, 2) ?></td></tr>
                <tr><td class="label">Tebal Asli</td><td class="titik">:</td><td class="val"><?= number_format($detail['tebal_asli_rol'] ?: 0, 2) ?></td></tr>
                <tr><td class="label">Spesifikasi</td><td class="titik">:</td><td class="val">Gram : +/-3% &nbsp;&nbsp; Tebal : +/-10%</td></tr>
                <tr><td class="label">Gramatur</td><td class="titik">:</td><td class="val"><?= number_format($detail['gramatur_rol'] ?: 0, 2) ?> -- <?= number_format($detail['gramatur_plus_rol'] ?: 0, 2) ?></td></tr>
                <tr><td class="label">Tebal</td><td class="titik">:</td><td class="val"><?= number_format($detail['tebal_rol'] ?: 0, 2) ?> -- <?= number_format($detail['tebal_plus_rol'] ?: 0, 2) ?></td></tr>
                <tr><td class="label">Keterangan</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['keterangan_rol'] ?: $detail['keterangan_potong'] ?: '-') ?></td></tr>
                <tr><td class="label">Kode</td><td class="titik">:</td><td class="val"><?= htmlspecialchars($detail['code_rol'] ?: $detail['code_potong'] ?: '-') ?></td></tr>
                <tr><td class="label">Tgl Kirim</td><td class="titik">:</td><td class="val"><?= $tgl_kirim ?></td></tr>
            </table>
            
            <table class="produksi-table">
                <thead>
                    <tr>
                        <th rowspan="2" class="col-rol-tgl">Tgl</th>
                        <th colspan="3" style="height: 15px;">Hasil Produksi Rol</th>
                        <th rowspan="2" class="col-rol-total">Total</th>
                        <th rowspan="2" class="col-rol-sisa">Sisa</th>
                        <th rowspan="2" class="col-rol-prf">Prf</th>
                    </tr>
                    <tr>
                        <th class="col-rol-shf" style="height: 15px;">1</th>
                        <th class="col-rol-shf">2</th>
                        <th class="col-rol-shf">3</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for($i=1; $i<=8; $i++): ?>
                        <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
            
            <table class="ttd-section">
                <tr>
                    <td style="text-align: left;">
                        Dibuat Oleh,<br><br><br><br>
                        ( &nbsp; &nbsp; &nbsp; admin &nbsp; &nbsp; &nbsp; )
                    </td>
                </tr>
            </table>
        </div>
        
      
       
    </div>
</body>
</html>