<?php
// modul/transaksi/print_sop_potong.php

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
$user_name = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$sop_id = isset($_GET['sop_id']) ? mysqli_real_escape_string($conn, $_GET['sop_id']) : '';
if (empty($sop_id)) {
    die("SOP ID tidak ditemukan");
}

// Ambil data header SOP
$head_query = mysqli_query($conn, "SELECT * FROM head_sop WHERE sop_id = '$sop_id' LIMIT 1");
$head = mysqli_fetch_assoc($head_query);
if (!$head) {
    die("Data SOP tidak ditemukan");
}

// Ambil data detail SOP + nama inventory untuk kebutuhan prefix PE/PP/HD
$detail_query = mysqli_query($conn, "
    SELECT 
        ds.*,
        mi.inventory_name
    FROM det_sop ds
    LEFT JOIN m_inventory mi ON mi.inventory_id = ds.inventory_id
    WHERE ds.sop_id = '$sop_id'
    LIMIT 1
");
$detail = mysqli_fetch_assoc($detail_query);
if (!$detail) {
    die("Detail SOP tidak ditemukan");
}

$tgl_order = !empty($head['sop_date']) ? date('d-M-Y', strtotime($head['sop_date'])) : '-';
$tgl_kirim = !empty($detail['shipment_due_date']) ? date('d-M-Y', strtotime($detail['shipment_due_date'])) : '-';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function displayText($value, $fallback = '-') {
    $value = trim((string)($value ?? ''));
    return $value !== '' ? h($value) : h($fallback);
}
function displayTextWithZeroCheck($value, $fallback = '-') {
    $value = trim((string)($value ?? ''));
    
    // Jika kosong atau NULL
    if ($value === '') {
        return h($fallback);
    }
    
    // Jika nilai 0 (nol) atau 0.00
    if (is_numeric($value) && (float)$value == 0) {
        return h($fallback);
    }
    
    return h($value);
}
function firstNonEmptyValue($row, $keys, $fallback = '') {
    foreach ($keys as $key) {
        if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
            return $row[$key];
        }
    }

    return $fallback;
}

function isPlainNumericValuePotong($value) {
    if ($value === null) return false;

    $value = trim((string)$value);
    return $value !== '' && preg_match('/^-?\d+(?:[.,]\d+)?$/', $value);
}

function safeNumberPotong($value) {
    if ($value === null || trim((string)$value) === '') return 0;

    $value = trim((string)$value);

    if (preg_match('/-?\d+(?:[.,]\d+)?/', $value, $m)) {
        return (float) str_replace(',', '.', $m[0]);
    }

    return 0;
}

function displayOrderPotong($value) {
    $value = trim((string)($value ?? ''));

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

function formatBeratRolPotong($value) {
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return '-';
    }

    // Kalau sudah ada satuan seperti KG/ROL, tampilkan apa adanya agar tidak dobel satuan.
    if (!isPlainNumericValuePotong($value)) {
        return h($value);
    }

    return number_format(safeNumberPotong($value), 2) . ' KG/ROL';
}

function getMaterialPrefixFromInventoryName($inventoryName) {
    $name = strtoupper(trim((string)($inventoryName ?? '')));

    if ($name === '') {
        return '';
    }

    // Ambil hanya kata PE, PP, atau HD sebagai kata terpisah.
    // Contoh cocok: PE SLONTONG, SLONTONG PE, PE ROLL STOKAN, PP ROLL, HD POTONG.
    if (preg_match('/\b(PP|PE|HD)\b/', $name, $match)) {
        return $match[1];
    }

    return '';
}

function formatBeratJenisWithMaterial($beratJenis, $inventoryName) {
    $beratJenis = trim((string)($beratJenis ?? ''));

    if ($beratJenis === '') {
        return '-';
    }

    $prefix = getMaterialPrefixFromInventoryName($inventoryName);

    // Kalau sudah diawali PE / PP / HD, jangan dobel.
    if (preg_match('/^(PP|PE|HD)\s+/i', $beratJenis)) {
        return h($beratJenis);
    }

    if ($prefix !== '') {
        return h($prefix . ' ' . $beratJenis);
    }

    return h($beratJenis);
}

// Field tambahan Potong
$warna_potong = firstNonEmptyValue($detail, ['warna_potong', 'nat_warna_potong', 'nat_warna_rol']);
$berat_rol_potong = firstNonEmptyValue($detail, ['berat_rol_potong', 'berat_rol']);
$jarak_seal_potong = firstNonEmptyValue($detail, ['jarak_seal_potong', 'jarak_seal', 'seal_potong', 'jarakseal_potong']);

header("Content-Type: text/html; charset=UTF-8");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SOP POTONG - <?= h($sop_id) ?></title>
<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    /* ====================================================
       SETTING UKURAN KERTAS SECARA FLEKSIBEL (LANDSCAPE)
       ==================================================== */
    @page {
        size: landscape; /* Biarkan browser mendeteksi orientasi landscape secara otomatis */
        margin: 5mm 6mm; /* Margin atas-bawah 5mm, kiri-kanan 6mm */
    }
    
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 9pt;
        background: white;
        color: #000;
        line-height: 1.1;
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
        margin-bottom: 3px;
    }
    
    .header h1 {
        font-size: 10pt;
        font-weight: bold;
        text-decoration: underline;
    }
    
    /* Info Box / Spesifikasi */
    .info-box {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 3px;
        font-size: 9pt;
    }
    
    .info-box td {
        padding: 0.3px 2px;
        vertical-align: top;
    }
    
    .info-box td.label { width: 30%; }
    .info-box td.titik { width: 3%; }
    .info-box td.val   { width: 67%; }
    
    /* Global Table Style */
    .produksi-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 3px;
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
        font-size: 7.5pt;
    }
    
    .produksi-table tbody td {
        height: 18px; /* Disesuaikan agar pas di sisa ruang vertikal */
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
        margin-top: 8px;
        width: 100%;
        font-size: 9pt;
    }
    
    .print-btn {
        display: block;
        text-align: center;
        margin-bottom: 10px;
    }
    
    .btn-print {
        background: #007bff;
        color: white;
        border: none;
        padding: 6px 15px;
        cursor: pointer;
        font-size: 9pt;
        border-radius: 5px;
    }
    
    /* ====================================================
       PENGATURAN AMAN SAAT PRINT DI EPSON / CANON
       ==================================================== */
    @media print {
        .print-btn { 
            display: none; 
        }
        body { 
            padding: 0; 
            margin: 0; 
            width: 32cm; /* Dikunci sedikit di bawah 33cm agar pas di area cetak fisik printer */
        }
        .flex-container {
            width: 100%;
        }
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
                <tr><td class="label">Tgl Order</td><td class="titik">:</td><td class="val"><?= h($tgl_order) ?></td></tr>
                <tr><td class="label">Nama Customer</td><td class="titik">:</td><td class="val"><?= displayText($head['customer'] ?? '') ?></td></tr>
                <tr><td class="label">Berat Jenis</td><td class="titik">:</td><td class="val"><?= formatBeratJenisWithMaterial($detail['berat_jenis_potong'] ?? '', $detail['inventory_name'] ?? '') ?></td></tr>
                <tr><td class="label">Spec</td><td class="titik">:</td><td class="val"><?= displayText($detail['spec_potong'] ?? '') ?></td></tr>
                <tr><td class="label">Ukuran</td><td class="titik">:</td><td class="val"><?= displayText(($detail['ukuran_potong'] ?? '') !== '' ? $detail['ukuran_potong'] : ($detail['ukuran_rol'] ?? '')) ?></td></tr>
                <tr><td class="label">Jumlah Order</td><td class="titik">:</td><td class="val"><?= displayOrderPotong($detail['jml_order_potong'] ?? '') ?></td></tr>
                <tr><td class="label">Isi per pak & Bal</td><td class="titik">:</td><td class="val"><?= displayText($detail['isi_pakbal_potong'] ?? '') ?></td></tr>
                <tr><td class="label">Keterangan</td><td class="titik">:</td><td class="val"><?= displayText(($detail['keterangan_potong'] ?? '') !== '' ? $detail['keterangan_potong'] : ($detail['keterangan_rol'] ?? '')) ?></td></tr>
                <tr><td class="label">Nat/Warna</td><td class="titik">:</td><td class="val"><?= displayText($warna_potong) ?></td></tr>
                <tr><td class="label">Berat/Rol</td><td class="titik">:</td><td class="val"><?= formatBeratRolPotong($berat_rol_potong) ?></td></tr>
                <tr><td class="label">Jarak Seal</td><td class="titik">:</td><td class="val"><?= displayTextWithZeroCheck($jarak_seal_potong) ?></td></tr>
                <tr><td class="label">Kode</td><td class="titik">:</td><td class="val"><?= displayText(($detail['code_potong'] ?? '') !== '' ? $detail['code_potong'] : ($detail['code_rol'] ?? '')) ?></td></tr>
                <tr><td class="label">Tgl Kirim</td><td class="titik">:</td><td class="val"><?= h($tgl_kirim) ?></td></tr>
            </table>
            
            <table class="produksi-table">
                <thead>
                    <tr>
                        <th colspan="3" style="width: 23%;">Bahan Potong</th>
                        <th colspan="9" style="width: 77%;">Hasil Produksi Potong</th>
                    </tr>
                    <tr>
                        <th rowspan="2" class="col-tgl">Tgl</th>
                        <th colspan="2" style="height: 10px;">Jumlah Bahan</th>
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
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                    <?php endfor; ?>
                </tbody>
            </table>
            
            <table class="ttd-section">
                <tr>
                    <td style="text-align: left; padding-left: 25px;">
                        Dibuat Oleh,<br><br><br><br>
                        <?php if (!empty($user_name)): ?>
                            ( <?= h($user_name) ?> )
                        <?php else: ?>
                            ( &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; )
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right; padding-right: 20px;">
                        Diperiksa Oleh,<br><br><br><br>
                        ( &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; )
                    </td>
                </tr>
            </table>
            
            <div class="footer" style="margin-top: 10px; font-size: 8pt; text-align: center; border-top: 1px solid #ccc; padding-top: 2px;">
                Dicetak pada: <?= date('d-m-Y') ?> | SOP: <?= h($sop_id) ?>
            </div>
        </div>
    </div>
</body>
</html>