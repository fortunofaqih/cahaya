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

// Ambil data detail SOP + inventory name untuk deteksi PE / PP / HD
// Catatan: jika nama kolom relasi inventory di det_sop bukan inventory_id, sesuaikan bagian ON mi.inventory_id = ds.inventory_id.
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

// Helper agar aman untuk kolom yang kadang berisi angka + satuan, contoh: "0.12 KG/ROL" atau "10 ROL".
function isPlainNumericValue($value) {
    if ($value === null) return false;
    $value = trim((string)$value);
    return $value !== '' && preg_match('/^-?\d+(?:[.,]\d+)?$/', $value);
}

function safeNumber($value) {
    if ($value === null || trim((string)$value) === '') return 0;

    $value = trim((string)$value);

    if (preg_match('/-?\d+(?:[.,]\d+)?/', $value, $m)) {
        return (float) str_replace(',', '.', $m[0]);
    }

    return 0;
}

function formatNumberOrText($value, $decimals = 2, $default = '-') {
    if ($value === null || trim((string)$value) === '') return $default;

    $value = trim((string)$value);

    if (isPlainNumericValue($value)) {
        return number_format(safeNumber($value), $decimals);
    }

    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatBeratRol($value) {
    if ($value === null || trim((string)$value) === '') return '-';

    $value = trim((string)$value);

    // Kalau sudah ada satuan seperti KG/ROL, tampilkan apa adanya agar tidak dobel satuan.
    if (!isPlainNumericValue($value)) {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    return number_format(safeNumber($value), 2) . ' KG/ROL';
}

function formatPercentValue($value) {
    $num = safeNumber($value);
    if (abs($num - round($num)) < 0.000001) {
        return (string)(int)round($num);
    }
    return rtrim(rtrim(number_format($num, 4, '.', ''), '0'), '.');
}

function buildSpecTextFromTolerance($gramMinus, $gramPlus, $tebalMinus, $tebalPlus) {
    $gm = safeNumber($gramMinus);
    $gp = safeNumber($gramPlus);
    $tm = safeNumber($tebalMinus);
    $tp = safeNumber($tebalPlus);

    if ($gm <= 0 && $gp <= 0 && $tm <= 0 && $tp <= 0) {
        return '';
    }

    if ($gm == $gp && $gm > 0) {
        $gramText = '+/-' . formatPercentValue($gp) . '%';
    } else {
        $gramText = '-' . formatPercentValue($gm) . '+' . formatPercentValue($gp) . '%';
    }

    if ($tm == $tp && $tm > 0) {
        $tebalText = '+/-' . formatPercentValue($tp) . '%';
    } else {
        $tebalText = '-' . formatPercentValue($tm) . '+' . formatPercentValue($tp) . '%';
    }

    return 'Gram: ' . $gramText . '  Tebal: ' . $tebalText;
}

function getRollSpecText($detail) {
    $fromTolerance = buildSpecTextFromTolerance(
        $detail['gramatur_min_rol'] ?? 0,
        $detail['gramatur_plus_rol'] ?? 0,
        $detail['tebal_minus_rol'] ?? 0,
        $detail['tebal_plus_rol'] ?? 0
    );

    if ($fromTolerance !== '') {
        return $fromTolerance;
    }

    return trim((string)($detail['spec_rol'] ?? ''));
}

function displayField($value, $fallback = '-') {
    $value = trim((string)($value ?? ''));
    return htmlspecialchars($value !== '' ? $value : $fallback, ENT_QUOTES, 'UTF-8');
}

function displayMultilineField($value, $fallback = '-') {
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8');
    }

    return nl2br(
        htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
        false
    );
}

function getMaterialPrefixFromInventoryName($inventoryName) {
    $name = strtoupper(trim((string)$inventoryName));

    if ($name === '') {
        return '';
    }

    // Ambil hanya kata PE, PP, atau HD sebagai kata terpisah.
    // Contoh cocok:
    // PE SLONTONG
    // SLONTONG PE
    // PE ROLL STOKAN 0.2000X300/160X51 M PAKAI UV 5%
    // PP ROLL
    // HD POTONG
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
        return htmlspecialchars($beratJenis, ENT_QUOTES, 'UTF-8');
    }

    if ($prefix !== '') {
        return htmlspecialchars($prefix . ' ' . $beratJenis, ENT_QUOTES, 'UTF-8');
    }

    return htmlspecialchars($beratJenis, ENT_QUOTES, 'UTF-8');
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$tgl_order = !empty($head['sop_date']) ? date('d-M-Y', strtotime($head['sop_date'])) : '-';
$tgl_kirim = !empty($detail['shipment_due_date']) ? date('d-M-Y', strtotime($detail['shipment_due_date'])) : '-';
$spec_rol_display = getRollSpecText($detail ?: []);
$order_no = trim((string)($head['order_no'] ?? ''));
$order_no_display = $order_no !== '' ? $order_no : '-';

header("Content-Type: text/html; charset=UTF-8");
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>SOP ROLL - <?= htmlspecialchars($sop_id, ENT_QUOTES, 'UTF-8') ?></title>
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
            size: landscape; /* Biarkan printer yang menentukan ukuran kertasnya, CSS hanya mengunci posisi tidurnya */
            margin: 5mm 6mm; /* Atas-Bawah 5mm, Kiri-Kanan 6mm */
        }
        
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10.5pt;
            background: white;
            color: #000;
            line-height: 1.05;
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
            page-break-inside: avoid;
            flex-direction: column;
        }
        
        /* Header */
        .header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
            margin-bottom: 3px;
            white-space: nowrap;
        }
        
        .header h1 {
            font-size: 11pt;
            font-weight: bold;
            text-decoration: underline;
        }
        
        .header .order-no {
            font-size: 10.5pt;
            font-weight: bold;
            text-align: right;
            text-decoration: none;
            flex-shrink: 0;
        }

        /* Info Box / Spesifikasi */
        .info-box {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 3px;
            font-size: 10.5pt;
        }
        
        .info-box td {
            padding: 0 2px;
            vertical-align: top;
        }
        
        .info-box td.label { width: 30%; }
        .info-box td.titik { width: 3%; }
        .info-box td.val   { width: 67%; }
        .info-box td.multiline-value {
            white-space: normal;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }
        
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
            font-size: 9pt;
        }
        
        .produksi-table tbody td {
            height: 17px; /* Disesuaikan agar pas di sisa ruang vertikal */
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
            font-size: 10.5pt;
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
        
       @media print {
        .print-btn { display: none; }
        body { 
            padding: 0; 
            margin: 0; 
            width: 32cm; /* Kunci sedikit di bawah 33cm untuk toleransi margin fisik printer */
        }
        .flex-container {
            width: 100%;
        }
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
                <div class="order-no">Order No: <?= h($order_no_display) ?></div>
            </div>
            
            <table class="info-box">
                <tr><td class="label">Tgl Order</td><td class="titik">:</td><td class="val"><?= $tgl_order ?></td></tr>
                <tr><td class="label">Nama Customer</td><td class="titik">:</td><td class="val"><?= displayField($head['customer'] ?? '') ?></td></tr>
                <tr><td class="label">Berat Jenis</td><td class="titik">:</td><td class="val"><?= formatBeratJenisWithMaterial($detail['berat_jenis_rol'] ?? '', $detail['inventory_name'] ?? '') ?></td></tr>
                <tr><td class="label">Ukuran</td><td class="titik">:</td><td class="val"><?= displayField($detail['ukuran_rol'] ?? '') ?></td></tr>
                <tr><td class="label">Berat/Rol</td><td class="titik">:</td><td class="val"><?= formatBeratRol($detail['berat_rol'] ?? '') ?></td></tr>
                <tr><td class="label">Isi/Bal</td><td class="titik">:</td><td class="val"><?= displayField($detail['isi_bal_rol'] ?? '') ?></td></tr>
                <tr><td class="label">Jumlah Order</td><td class="titik">:</td><td class="val"><?= displayField($detail['jml_order_rol'] ?? '') ?></td></tr>
                <tr><td class="label">Treat/Tidak</td><td class="titik">:</td><td class="val"><?= displayField($detail['treat_rol'] ?? '', '') ?></td></tr>
                <tr><td class="label">Nat/Warna</td><td class="titik">:</td><td class="val"><?= displayField(($detail['nat_warna_rol'] ?? '') !== '' ? $detail['nat_warna_rol'] : ($detail['nat_warna_potong'] ?? '')) ?></td></tr>
                <tr><td class="label">Bobin/Krepyak</td><td class="titik">:</td><td class="val"><?= displayField($detail['bobin_krepyak_rol'] ?? '') ?></td></tr>
                <tr><td class="label">Kirim/Las</td><td class="titik">:</td><td class="val"><?= displayField($detail['kirim_las_rol'] ?? '') ?></td></tr>
                <tr><td class="label">Std Pengecekan</td><td class="titik">:</td><td class="val"><?= displayField($detail['standar_cek_rol'] ?? '') ?></td></tr>
                <tr><td class="label">Gramatur Asli</td><td class="titik">:</td><td class="val"><?= number_format(safeNumber($detail['gramatur_asli_rol'] ?? 0), 2) ?></td></tr>
                <tr><td class="label">Tebal Asli</td><td class="titik">:</td><td class="val"><?= number_format(safeNumber($detail['tebal_asli_rol'] ?? 0), 2) ?></td></tr>
                <tr><td class="label">Spesifikasi</td><td class="titik">:</td><td class="val"><?= displayField($spec_rol_display) ?></td></tr>
                <tr><td class="label">Gramatur</td><td class="titik">:</td><td class="val"><?= formatNumberOrText($detail['gramatur_rol'] ?? '', 2, '-') ?></td></tr>
                <tr><td class="label">Tebal</td><td class="titik">:</td><td class="val"><?= formatNumberOrText($detail['tebal_rol'] ?? '', 2, '-') ?></td></tr>
                <tr>
                    <td class="label">Keterangan</td>
                    <td class="titik">:</td>
                    <td class="val multiline-value"><?= displayMultilineField(($detail['keterangan_rol'] ?? '') !== '' ? $detail['keterangan_rol'] : ($detail['keterangan_potong'] ?? '')) ?></td>
                </tr>
                <tr><td class="label">Kode</td><td class="titik">:</td><td class="val"><?= displayField(($detail['code_rol'] ?? '') !== '' ? $detail['code_rol'] : ($detail['code_potong'] ?? '')) ?></td></tr>
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
            
            <div class="footer" style="margin-top: 10px; font-size: 7pt; text-align: center; border-top: 1px solid #ccc; padding-top: 2px;">
                Dicetak pada: <?= date('d-m-Y') ?> | SOP: <?= h($sop_id) ?>
            </div>
        </div>
        
    </div>
</body>
</html>