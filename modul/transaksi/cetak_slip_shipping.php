<?php
// modul/transaksi/cetak_slip_shipping.php
// Format cetak untuk Surat Jalan pre-printed Marketing - Mode Default UOM
// REVISI: koordinat CSS dikalibrasi ulang berdasarkan pengukuran manual pada foto nota fisik

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

$shipping_no = isset($_GET['id']) ? trim($_GET['id']) : '';

if ($shipping_no === '') {
    die('Shipping No tidak ditemukan!');
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safeText($value) {
    return trim((string)($value ?? ''));
}

function fmtNumber($number, $decimals = 2) {
    $number = (float)$number;
    if (abs($number) < 0.000001) {
        return '';
    }

    $formatted = number_format($number, $decimals, ',', '.');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return $formatted;
}

// Format tanggal: DD-MM-YYYY (contoh: 14-07-2026)
function formatShippingDate($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    return $ts ? date('d-m-Y', $ts) : '';
}

function splitAddressLines($name, $address, $city) {
    $lines = [];

    $name = safeText($name);
    $address = preg_replace('/\s+/', ' ', safeText($address));
    $city = safeText($city);

    if ($name !== '') {
        $lines[] = $name;
    }

    if ($address !== '') {
        $wrapped = wordwrap($address, 50, "\n", false);
        foreach (explode("\n", $wrapped) as $line) {
            if (count($lines) >= 3) {
                break;
            }
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
    }

    if ($city !== '' && count($lines) < 3) {
        $lines[] = $city;
    }

    while (count($lines) < 3) {
        $lines[] = '';
    }

    return array_slice($lines, 0, 3);
}

function getItemNameWithRemarks($detail) {
    $internalName = safeText($detail['internal_name'] ?? '');
    $remarks = safeText($detail['remarks_inventory_shipping'] ?? '');

    if ($remarks !== '') {
        return $internalName . ' ' . $remarks;
    }
    return $internalName;
}

// Pecah nama barang panjang menjadi beberapa baris (bukan dikecilkan fontnya),
// karena tiap baris fisik nota berjarak tetap 5mm (0,5cm). Baris ke-2/3 nama
// barang akan "turun" memakai slot baris fisik berikutnya. $maxLines membatasi
// supaya 1 item tidak memakan terlalu banyak baris; kalau masih kepanjangan,
// baris terakhir dipotong dengan "...".
function wrapItemName($text, $maxWidthMm = 70, $fontPt = 11, $maxLines = 3) {
    $text = trim((string)$text);
    if ($text === '') {
        return [''];
    }

    // Estimasi lebar per karakter untuk font Courier New bold (monospace):
    // ~0.6 x fontSizePt, dikonversi pt -> mm (1pt = 0.3528mm)
    $charWidthMm = $fontPt * 0.6 * 0.3528;
    $maxChars = max(1, (int)floor($maxWidthMm / $charWidthMm));

    // cut=true supaya kata/kode yang sangat panjang tanpa spasi tetap dipotong
    $wrapped = wordwrap($text, $maxChars, "\n", true);
    $lines = explode("\n", $wrapped);

    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $lastIdx = $maxLines - 1;
        $cut = max(0, $maxChars - 3);
        $lines[$lastIdx] = mb_substr($lines[$lastIdx], 0, $cut) . '...';
    }

    return $lines;
}

function getQtyDisplay($detail) {
    $qtyPack = (float)($detail['qty_pack_shipping'] ?? 0);
    $uomPack = safeText($detail['uom_pack_shipping'] ?? '');
    $qtyDetail = (float)($detail['qty_detail_shipping'] ?? 0);
    $uomDetail = safeText($detail['uom_detail_shipping'] ?? '');

    $result = [];

    if ($qtyPack > 0 && $uomPack !== '') {
        $result[] = fmtNumber($qtyPack) . ' ' . $uomPack;
    }

    if ($qtyDetail > 0 && $uomDetail !== '') {
        $result[] = fmtNumber($qtyDetail) . ' ' . $uomDetail;
    }

    return implode(' | ', $result);
}

// ===============================
// Ambil header shipping
// ===============================
$stmt = mysqli_prepare($conn, "
    SELECT
        hs.*,
        mg.name AS gudang_name
    FROM hed_shipping hs
    LEFT JOIN m_gudang mg ON hs.gudang_id = mg.gudang_id
    WHERE hs.shipping_no = ?
    LIMIT 1
");

if (!$stmt) {
    die('Prepare header gagal: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, 's', $shipping_no);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$header = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$header) {
    die('Data shipping tidak ditemukan!');
}

// ===============================
// Ambil detail shipping
// ===============================
$stmtDetail = mysqli_prepare($conn, "
    SELECT
        ds.*,
        mi.internal_name,
        mi.uom_pack,
        mi.base_uom,
        mi.pack_uom,
        mi.uom,
        ds.qty_shipping,
        ds.uom_shipping,
        ds.qty_pack_shipping,
        ds.uom_pack_shipping,
        ds.qty_detail_shipping,
        ds.uom_detail_shipping,
        ds.remarks_inventory_shipping
    FROM det_shipping ds
    LEFT JOIN m_inventory mi ON mi.inventory_id = ds.inventory_id
    WHERE ds.shipping_no = ?
    ORDER BY ds.id ASC
");

if (!$stmtDetail) {
    die('Prepare detail gagal: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmtDetail, 's', $shipping_no);
mysqli_stmt_execute($stmtDetail);
$resultDetail = mysqli_stmt_get_result($stmtDetail);

$details = [];
while ($row = mysqli_fetch_assoc($resultDetail)) {
    $details[] = $row;
}
mysqli_stmt_close($stmtDetail);

// Format tanggal cetak: DD-MM-YYYY
$shippingDate = formatShippingDate($header['shipping_date'] ?? '');
$customerLines = splitAddressLines(
    $header['customer_name'] ?? '',
    $header['customer_address'] ?? '',
    $header['customer_city'] ?? ''
);

$vehicleText = safeText($header['transporter'] ?? '');
$truckNoText = safeText($header['truck_no'] ?? '');

// Nota fisik punya 10 baris fisik (masing-masing 5mm). Nama barang yang wrap
// ke lebih dari 1 baris akan memakai lebih dari 1 slot baris fisik -> logika
// pembagian slot dihitung langsung di loop cetak di bawah.
$maxRowSlots = 10;
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Cetak Surat Jalan - <?= e($shipping_no) ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: #f5f5f5;
            font-family: Arial, Helvetica, sans-serif;
            font-weight: normal;
        }

        .no-print {
            font-family: Arial, sans-serif;
            text-align: center;
            padding: 12px;
            background: #fff;
            border-bottom: 1px solid #ddd;
        }

        .btn {
            display: inline-block;
            padding: 8px 16px;
            margin: 0 4px;
            border-radius: 4px;
            text-decoration: none;
            border: 0;
            cursor: pointer;
            font-size: 13px;
        }

        .btn-secondary { background: #6c757d; color: #fff; }
        .btn-success { background: #28a745; color: #fff; }

        .note {
            margin-top: 8px;
            font-size: 12px;
            color: #555;
        }

        /* Container halaman fisik kertas F4 (PORTRAIT) */
        .page {
            position: relative;
            width: 215mm;
            height: 330mm;
            margin: 0 auto;
            background: #fff;
            overflow: hidden;
        }

        /* Elemen teks cetak */
        .field {
            position: absolute;
            color: #000;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1;
        }

        /* =====================================================
           KOORDINAT HASIL KALIBRASI ULANG BERDASARKAN FOTO NOTA
           Nota fisik "Half Letter" lebar 21cm, ditempel di pojok
           kiri-atas kertas F4 landscape.
           ===================================================== */

        /* Shipping date dinaikkan 0,5cm */
        .date-field {
            left: 140mm;
            top: 15mm;
            width: 70mm;
            font-size: 11pt;
        }

        /* Customer baris 1 dinaikkan 0,5cm */
        .customer-line-1 {
            left: 130mm;
            top: 30mm;
            width: 80mm;
            font-size: 11pt;
        }

        /* Kepada Yth. baris 2 -> PASTI: +1cm dari baris 1 = 4,5cm; 13cm dari kiri */
        .customer-line-2 {
            left: 130mm;
            top: 45mm;
            width: 80mm;
            font-size: 11pt;
        }

        /* Kepada Yth. baris 3 (opsional) -> ESTIMASI: hapus div ini di HTML
           jika nota Anda hanya punya 2 baris kosong seperti terlihat di foto */
        .customer-line-3 {
            left: 160mm;
            top: 55mm;
            width: 80mm;
            font-size: 11pt;
        }

        /* Kendaraan & No Polisi -> ESTIMASI (foto tidak memberi angka cm
           persis di sini, hanya total 4cm dari judul "Surat Jalan" ke tabel).
           Silakan geser +/- beberapa mm setelah test print di kertas biasa. */
        .vehicle-field {
            left: 45mm;
            top: 75mm;
            width: 30mm;
            font-size: 11pt;
        }

        .truck-field {
            left: 80mm;
            top: 75mm;
            width: 30mm;
            font-size: 11pt;
        }

        /* Pengaturan Baris Item Tabel */
        .row-field {
            font-size: 11pt;
            height: 5mm;
            line-height: 5mm;
        }

        /* Kolom qty digeser 0,8cm ke kiri */
        .qty-col-1 {
            left: 7mm;
            width: 15mm;
            text-align: center;
        }

        .qty-col-2 {
            left: 23mm;
            width: 15mm;
            text-align: center;
        }

        /* Kolom cadangan jaga-jaga jika ada 3 pecahan qty/UOM */
        .qty-col-3 {
            left: 39mm;
            width: 15mm;
            text-align: center;
        }

        /* Kolom NAMA BARANG -> lebar fisik kolom cuma 7cm, font akan auto-shrink
           untuk nama barang panjang (lihat getNameFontSize() di PHP) */
        .name-col {
            left: 70mm;
            width: 70mm;
            text-align: left;
            text-overflow: ellipsis;
        }

        .extra-warning {
            position: absolute;
            left: 10mm;
            top: 155mm;
            font-size: 9pt;
            color: #000;
        }

        /* ====================================================
           SETTING UKURAN KERTAS F4 PORTRAIT UTUH (21.5cm x 33cm)
           ==================================================== */
        @page {
            size: 21.5cm 33cm portrait;
            margin: 5mm 6mm 5mm 6mm;
        }

        @media print {
            @page {
                size: 21.5cm 33cm portrait;
                margin: 0;
            }

            html,
            body {
                margin: 0 !important;
                padding: 0 !important;
                width: 215mm !important;
                height: auto !important;
                min-height: 0 !important;
                background: #fff !important;
                overflow: visible !important;
            }

            /*
             * Sembunyikan seluruh layout ERP saat print, termasuk header,
             * navbar, tombol logout, sidebar, breadcrumb, dan footer.
             * Elemen tetap berada di DOM agar halaman slip tidak rusak.
             */
            body * {
                visibility: hidden !important;
            }

            .no-print,
            header,
            footer,
            nav,
            aside,
            .navbar,
            .topbar,
            .sidebar,
            .main-header,
            .main-footer,
            .content-header,
            .breadcrumb,
            #header,
            #footer,
            #sidebar {
                display: none !important;
            }

            /*
             * Tampilkan hanya slip dan tempelkan ke pojok kiri atas area
             * cetak. Position fixed mencegah wrapper halaman ERP memberi
             * jarak tambahan atau memunculkan halaman kedua.
             */
            .page,
            .page * {
                visibility: visible !important;
            }

            .page {
                display: block !important;
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                width: 215mm !important;
                height: 165mm !important;
                min-height: 165mm !important;
                max-height: 165mm !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                background: transparent !important;
                box-shadow: none !important;
                z-index: 999999 !important;
                break-inside: avoid-page !important;
                page-break-inside: avoid !important;
                page-break-after: avoid !important;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a class="btn btn-secondary" href="index.php?page=shipping">Kembali</a>
    <button class="btn btn-success" onclick="window.print()">Cetak / Print</button>
    <div class="note">
        Format ini dikonfigurasi untuk kertas <strong>F4 Portrait</strong> dengan area cetak nota pojok kiri atas sebesar <strong>21cm x 16,5cm</strong>. Pastikan orientasi kertas di dialog print juga diset <strong>Portrait</strong>.<br>
        Koordinat sudah dikalibrasi ulang sesuai foto nota (06/07/2026). Beberapa titik (kendaraan/no polisi & baris ke-3 Kepada Yth) masih estimasi — cek dengan test print di kertas kosong lalu tempel ke nota asli sebelum cetak massal.
    </div>
</div>

<div class="page">
    <div class="field date-field"><?= e($shippingDate) ?></div>

    <div class="field customer-line-1"><?= e($customerLines[0]) ?></div>
    <div class="field customer-line-2"><?= e($customerLines[1]) ?></div>
    <div class="field customer-line-3"><?= e($customerLines[2]) ?></div>

    <div class="field vehicle-field"><?= e($vehicleText) ?></div>
    <div class="field truck-field"><?= e($truckNoText) ?></div>

    <?php
    // Baris pertama tabel -> dinaikkan 1cm dari hasil test print (95mm -> 85mm)
    $startTop = 85;
    // PASTI: tinggi tiap baris tabel = 0,5cm sesuai tanda di foto
    $rowHeight = 5.0;

    $currentTop = $startTop;
    $usedSlots = 0;
    $hasMoreRows = false;

    foreach ($details as $detail):
        $itemName = getItemNameWithRemarks($detail);
        $nameLines = wrapItemName($itemName, 70, 11, 3); // kolom nama barang lebar 7cm, maks 3 baris
        $lineCount = count($nameLines);

        if ($usedSlots + $lineCount > $maxRowSlots) {
            $hasMoreRows = true;
            break;
        }

        $qtyDisplay = getQtyDisplay($detail);

        // Membagi qty display menjadi maksimal 3 sub-kolom (jaga-jaga jika suatu saat
        // getQtyDisplay() menghasilkan 3 pecahan, misal PACK | DETAIL | BASE)
        $qtyParts = explode(' | ', $qtyDisplay);
        $qtyCol1 = isset($qtyParts[0]) ? $qtyParts[0] : '';
        $qtyCol2 = isset($qtyParts[1]) ? $qtyParts[1] : '';
        $qtyCol3 = isset($qtyParts[2]) ? $qtyParts[2] : '';
    ?>
        <div class="field row-field qty-col-1" style="top: <?= $currentTop ?>mm;"><?= e($qtyCol1) ?></div>
        <div class="field row-field qty-col-2" style="top: <?= $currentTop ?>mm;"><?= e($qtyCol2) ?></div>
        <div class="field row-field qty-col-3" style="top: <?= $currentTop ?>mm;"><?= e($qtyCol3) ?></div>
        <?php foreach ($nameLines as $lineIdx => $nameLine): ?>
        <div class="field row-field name-col" style="top: <?= $currentTop + ($lineIdx * $rowHeight) ?>mm;"><?= e($nameLine) ?></div>
        <?php endforeach; ?>
    <?php
        $currentTop += $rowHeight * $lineCount;
        $usedSlots += $lineCount;
    endforeach;
    ?>

    <?php if ($hasMoreRows): ?>
        <div class="extra-warning">* Item melebihi kapasitas baris nota. Mohon gunakan lembar Surat Jalan baru.</div>
    <?php endif; ?>
</div>

<script>
<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
window.onload = function () {
    setTimeout(function () {
        window.print();
    }, 400);
};
<?php endif; ?>
</script>

</body>
</html>