<?php
// modul/transaksi/cetak_slip_shipping.php
// Format cetak untuk Surat Jalan pre-printed Marketing - Mode Default UOM
// REVISI: koordinat CSS dikalibrasi ulang berdasarkan pengukuran manual pada foto nota fisik
// REVISI QTY: kolom paling kiri menampilkan $qtyCol2, lalu $qtyCol1

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
        return trim($internalName . ' ' . $remarks);
    }

    return $internalName;
}

// Pecah nama barang panjang menjadi beberapa baris.
function wrapItemName($text, $maxWidthMm = 70, $fontPt = 11, $maxLines = 3) {
    $text = trim((string)$text);

    if ($text === '') {
        return [''];
    }

    // Estimasi lebar karakter font Courier New bold.
    $charWidthMm = $fontPt * 0.6 * 0.3528;
    $maxChars = max(1, (int)floor($maxWidthMm / $charWidthMm));

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
    LEFT JOIN m_inventory mi
        ON mi.inventory_id = ds.inventory_id
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

// Nota fisik mempunyai 10 slot baris.
$maxRowSlots = 10;
?>
<!DOCTYPE html>
<html lang="id">
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
            padding: 12px;
            background: #fff;
            border-bottom: 1px solid #ddd;
            font-family: Arial, sans-serif;
            text-align: center;
        }

        .btn {
            display: inline-block;
            margin: 0 4px;
            padding: 8px 16px;
            border: 0;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }

        .btn-success {
            background: #28a745;
            color: #fff;
        }

        .note {
            margin-top: 8px;
            color: #555;
            font-size: 12px;
        }

        /* Container halaman fisik kertas F4 portrait. */
        .page {
            position: relative;
            width: 215mm;
            height: 330mm;
            margin: 0 auto;
            overflow: hidden;
            background: #fff;
        }

        .field {
            position: absolute;
            overflow: hidden;
            color: #000;
            line-height: 1;
            white-space: nowrap;
        }

        .date-field {
            left: 140mm;
            top: 15mm;
            width: 70mm;
            font-size: 11pt;
        }

        .customer-line-1 {
            left: 130mm;
            top: 30mm;
            width: 80mm;
            font-size: 11pt;
        }

        .customer-line-2 {
            left: 130mm;
            top: 45mm;
            width: 80mm;
            font-size: 11pt;
        }

        .customer-line-3 {
            left: 160mm;
            top: 55mm;
            width: 80mm;
            font-size: 11pt;
        }

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

        .row-field {
            height: 5mm;
            font-size: 11pt;
            line-height: 5mm;
        }

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

        .qty-col-3 {
            left: 39mm;
            width: 15mm;
            text-align: center;
        }

        .name-col {
            left: 70mm;
            width: 70mm;
            overflow: hidden;
            text-align: left;
            text-overflow: ellipsis;
        }

        .extra-warning {
            position: absolute;
            left: 10mm;
            top: 155mm;
            color: #000;
            font-size: 9pt;
        }

        @page {
            size: 21.5cm 33cm portrait;
            margin: 5mm 6mm;
        }

        @media print {
            @page {
                size: 21.5cm 33cm portrait;
                margin: 0;
            }

            html,
            body {
                width: 215mm !important;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                background: #fff !important;
            }

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

            .page,
            .page * {
                visibility: visible !important;
            }

            .page {
                display: block !important;
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                z-index: 999999 !important;
                width: 215mm !important;
                height: 165mm !important;
                min-height: 165mm !important;
                max-height: 165mm !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                break-inside: avoid-page !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                background: transparent !important;
                box-shadow: none !important;
            }
        }
    </style>
</head>

<body>

<div class="no-print">
    <a class="btn btn-secondary" href="index.php?page=shipping">Kembali</a>

    <button type="button" class="btn btn-success" onclick="window.print()">
        Cetak / Print
    </button>

    <div class="note">
        Format dikonfigurasi untuk kertas <strong>F4 Portrait</strong> dengan area
        cetak nota di pojok kiri atas sebesar <strong>21cm × 16,5cm</strong>.
        Pastikan orientasi pada dialog print juga menggunakan <strong>Portrait</strong>.
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
    // Baris pertama tabel.
    $startTop = 85;

    // Tinggi tiap baris tabel = 5mm.
    $rowHeight = 5.0;

    $currentTop = $startTop;
    $usedSlots = 0;
    $hasMoreRows = false;

    foreach ($details as $detail):
        $itemName = getItemNameWithRemarks($detail);
        $nameLines = wrapItemName($itemName, 70, 11, 3);
        $lineCount = count($nameLines);

        if ($usedSlots + $lineCount > $maxRowSlots) {
            $hasMoreRows = true;
            break;
        }

        $qtyDisplay = getQtyDisplay($detail);
        $qtyParts = explode(' | ', $qtyDisplay);

        $qtyCol1 = isset($qtyParts[0]) ? $qtyParts[0] : '';
        $qtyCol2 = isset($qtyParts[1]) ? $qtyParts[1] : '';
        $qtyCol3 = isset($qtyParts[2]) ? $qtyParts[2] : '';
    ?>

        <!--
            REVISI:
            Kolom fisik paling kiri menampilkan $qtyCol2.
            Kolom fisik berikutnya menampilkan $qtyCol1.
        -->
        <div
            class="field row-field qty-col-1"
            style="top: <?= e($currentTop) ?>mm;"
        ><?= e($qtyCol2) ?></div>

        <div
            class="field row-field qty-col-2"
            style="top: <?= e($currentTop) ?>mm;"
        ><?= e($qtyCol1) ?></div>

        <div
            class="field row-field qty-col-3"
            style="top: <?= e($currentTop) ?>mm;"
        ><?= e($qtyCol3) ?></div>

        <?php foreach ($nameLines as $lineIdx => $nameLine): ?>
            <div
                class="field row-field name-col"
                style="top: <?= e($currentTop + ($lineIdx * $rowHeight)) ?>mm;"
            ><?= e($nameLine) ?></div>
        <?php endforeach; ?>

    <?php
        $currentTop += $rowHeight * $lineCount;
        $usedSlots += $lineCount;
    endforeach;
    ?>

    <?php if ($hasMoreRows): ?>
        <div class="extra-warning">
            * Item melebihi kapasitas baris nota. Mohon gunakan lembar Surat Jalan baru.
        </div>
    <?php endif; ?>
</div>

<script>
<?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 400);
});
<?php endif; ?>
</script>

</body>
</html>
