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

// Fungsi format tanggal Indonesia: DD NamaBulan YYYY (contoh: 06 Juli 2026)
function formatDateIndonesia($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    if (!$ts) {
        return '';
    }

    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    $d = date('d', $ts);
    $m = (int)date('m', $ts);
    $y = date('Y', $ts);

    return $d . ' ' . $bulan[$m] . ' ' . $y;
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

// Format Indonesia (contoh: 06 Juli 2026)
$shippingDate = formatDateIndonesia($header['shipping_date'] ?? '');
$customerLines = splitAddressLines(
    $header['customer_name'] ?? '',
    $header['customer_address'] ?? '',
    $header['customer_city'] ?? ''
);

$vehicleText = safeText($header['transporter'] ?? '');
$truckNoText = safeText($header['truck_no'] ?? '');

// Berdasarkan foto nota terdapat 10 baris kosong di tabel item
$maxRows = 10;
$printRows = array_slice($details, 0, $maxRows);
$hasMoreRows = count($details) > $maxRows;
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
            font-family: "Courier New", Courier, monospace; /* Tipikal font dot-matrix */
            font-weight: bold;
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

        /* Surabaya, [Tanggal] -> PASTI: 1,5cm dari atas, 13cm dari kiri kertas nota */
        .date-field {
            left: 130mm;
            top: 15mm;
            width: 70mm;
            font-size: 11pt;
        }

        /* Kepada Yth. baris 1 -> PASTI: label "Kepada Yth." @2,5cm + 1cm gap = 3,5cm; 13cm dari kiri */
        .customer-line-1 {
            left: 130mm;
            top: 35mm;
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
            left: 130mm;
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

        /* Berdasarkan foto, kolom Banyaknya dibagi sub-kolom (digeser +1cm) */
        .qty-col-1 {
            left: 15mm;
            width: 15mm;
            text-align: center;
        }

        .qty-col-2 {
            left: 31mm;
            width: 15mm;
            text-align: center;
        }

        /* Kolom cadangan jaga-jaga jika ada 3 pecahan qty/UOM */
        .qty-col-3 {
            left: 47mm;
            width: 15mm;
            text-align: center;
        }

        /* Kolom NAMA BARANG (digeser +1cm lagi, total +2cm dari semula) */
        .name-col {
            left: 70mm;
            width: 85mm;
            text-align: left;
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

            html, body {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                width: 215mm;
                height: 330mm;
            }

            body * {
                visibility: hidden !important;
            }

            .page, .page * {
                visibility: visible !important;
            }

            .no-print {
                display: none !important;
                visibility: hidden !important;
            }

            .page {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                margin: 0 !important;
                width: 215mm !important;
                height: 330mm !important;
                background: transparent !important;
                box-shadow: none !important;
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
    // PASTI: baris pertama tabel = 4cm (40mm) dari judul "Surat Jalan"
    // Estimasi posisi judul ~55mm dari atas kertas nota -> baris pertama tabel = 95mm
    $startTop = 95;
    // PASTI: tinggi tiap baris tabel = 0,5cm sesuai tanda di foto
    $rowHeight = 5.0;

    foreach ($printRows as $idx => $detail):
        $top = $startTop + ($idx * $rowHeight);
        $itemName = getItemNameWithRemarks($detail);
        $qtyDisplay = getQtyDisplay($detail);

        // Membagi qty display menjadi maksimal 3 sub-kolom (jaga-jaga jika suatu saat
        // getQtyDisplay() menghasilkan 3 pecahan, misal PACK | DETAIL | BASE)
        $qtyParts = explode(' | ', $qtyDisplay);
        $qtyCol1 = isset($qtyParts[0]) ? $qtyParts[0] : '';
        $qtyCol2 = isset($qtyParts[1]) ? $qtyParts[1] : '';
        $qtyCol3 = isset($qtyParts[2]) ? $qtyParts[2] : '';
    ?>
        <div class="field row-field qty-col-1" style="top: <?= $top ?>mm;"><?= e($qtyCol1) ?></div>
        <div class="field row-field qty-col-2" style="top: <?= $top ?>mm;"><?= e($qtyCol2) ?></div>
        <div class="field row-field qty-col-3" style="top: <?= $top ?>mm;"><?= e($qtyCol3) ?></div>
        <div class="field row-field name-col" style="top: <?= $top ?>mm;"><?= e($itemName) ?></div>
    <?php endforeach; ?>

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