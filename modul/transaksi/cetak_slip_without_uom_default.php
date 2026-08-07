<?php
// modul/transaksi/cetak_slip_without_uom_default.php
// Format cetak untuk Surat Jalan pre-printed Marketing - Mode Default UOM
// UPDATE: Menampilkan remarks shipping dengan format vertical

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

/**
 * Fungsi untuk memformat remarks shipping menjadi multi-baris
 * Mendukung format:
 * 1. BR : 1347.5    BB : 44.2   NT : 1303.3
 * 2. BR : 1347.5\r\nBB : 44.2\r\nNT : 1303.3
 * 3. BR : 1347.5\nBB : 44.2\nNT : 1303.3
 */
function formatRemarksText($text) {
    $text = safeText($text);
    
    if ($text === '') {
        return '';
    }
    
    // Cek apakah ada newline character
    if (strpos($text, "\r\n") !== false || strpos($text, "\n") !== false || strpos($text, "\r") !== false) {
        // Ubah semua line ending ke \n
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        return $text;
    }
    
    // Jika tidak ada newline, coba parsing berdasarkan pola
    // BR : 1347.5    BB : 44.2   NT : 1303.3
    // atau BR:1347.5 BB:44.2 NT:1303.3
    
    // Cek apakah ada pola "BR", "BB", "NT" dengan titik dua
    if (preg_match_all('/(BR|BB|NT)\s*:\s*([0-9.,]+)/', $text, $matches, PREG_SET_ORDER)) {
        $lines = [];
        foreach ($matches as $match) {
            $lines[] = $match[1] . ' : ' . $match[2];
        }
        return implode("\n", $lines);
    }
    
    // Jika tidak ada pola yang cocok, return text asli
    return $text;
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
// Tidak memakai ellipsis (...). Teks akan diteruskan ke baris berikutnya.
function wrapItemName($text, $maxWidthMm = 70, $fontPt = 11, $maxLines = 4) {
    $text = preg_replace('/\s+/', ' ', trim((string)$text));

    if ($text === '') {
        return [''];
    }

    // Estimasi lebar karakter untuk area cetak.
    // Nilai 0.50 dibuat sedikit lebih longgar agar teks tidak terlalu cepat turun baris.
    $charWidthMm = $fontPt * 0.50 * 0.3528;
    $maxChars = max(1, (int)floor($maxWidthMm / $charWidthMm));

    // false = jangan memotong kata normal di tengah.
    // Jika ada satu token sangat panjang tanpa spasi, baru dipecah manual di bawah.
    $wrapped = wordwrap($text, $maxChars, "\n", false);
    $lines = explode("\n", $wrapped);

    $result = [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        // Antisipasi kode/nama tanpa spasi yang lebih panjang dari area kolom.
        while (mb_strlen($line) > $maxChars) {
            $result[] = mb_substr($line, 0, $maxChars);
            $line = mb_substr($line, $maxChars);
        }

        if ($line !== '') {
            $result[] = $line;
        }
    }

    if (empty($result)) {
        $result[] = '';
    }

    // Batas maksimal mengikuti kapasitas fisik nota.
    // Tidak menambahkan "..."; jika lebih panjang, mekanisme $maxRowSlots
    // tetap mencegah item berikutnya menabrak area bawah nota.
    return array_slice($result, 0, $maxLines);
}

function normalizeUomName($uom) {
    return strtoupper(trim((string)$uom));
}

function addQtyUomEntry(&$entries, $qty, $uom) {
    $uom = normalizeUomName($uom);

    if ($uom === '' || !is_numeric(str_replace(',', '.', trim((string)$qty)))) {
        return;
    }

    $qty = (float)str_replace(',', '.', trim((string)$qty));

    if ($qty <= 0) {
        return;
    }

    if (!isset($entries[$uom])) {
        $entries[$uom] = $qty;
    }
}

function parseCombinedQtyUomText($text, &$entries) {
    $text = safeText($text);

    if ($text === '') {
        return;
    }

    $parts = preg_split('/\s*(?:\||,|;)\s*/', $text);

    foreach ($parts as $part) {
        if (preg_match('/^\s*(-?\d+(?:[.,]\d+)?)\s+([A-Za-z]+)\s*$/', $part, $match)) {
            addQtyUomEntry($entries, $match[1], $match[2]);
        }
    }
}

function addSeparatedDetailUoms($qtyText, $uomText, &$entries) {
    $qtyText = safeText($qtyText);
    $uomText = safeText($uomText);

    if ($qtyText === '' || $uomText === '') {
        return;
    }

    $qtyParts = preg_split('/\s*(?:\||,|;)\s*/', $qtyText);
    $uomParts = preg_split('/\s*(?:\||,|;)\s*/', $uomText);

    if (count($qtyParts) > 1 && count($qtyParts) === count($uomParts)) {
        foreach ($qtyParts as $index => $qtyPart) {
            addQtyUomEntry($entries, $qtyPart, $uomParts[$index] ?? '');
        }
        return;
    }

    addQtyUomEntry($entries, $qtyText, $uomText);
}

function getQtyDisplay($detail) {
    $entries = [];

    $multiUomText = safeText(
        $detail['multi_uom_detail_text'] ?? ''
    );

    parseCombinedQtyUomText($multiUomText, $entries);

    addQtyUomEntry(
        $entries,
        $detail['qty_pack_shipping'] ?? 0,
        $detail['uom_pack_shipping'] ?? ''
    );

    addQtyUomEntry(
        $entries,
        $detail['qty_shipping'] ?? 0,
        $detail['uom_shipping'] ?? ''
    );

    if ($multiUomText === '') {
        addQtyUomEntry(
            $entries,
            $detail['qty_detail_shipping'] ?? 0,
            $detail['uom_detail_shipping'] ?? ''
        );
    }

    $soUomPack = normalizeUomName(
        $detail['so_uom_pack'] ?? ''
    );

    $qtyCol1 = isset($entries['BAL'])
        ? fmtNumber($entries['BAL']) . ' BAL'
        : '';

    $qtyCol2 = '';

    $otherPriority = [
        'ROLL' => 10,
        'ROL'  => 10,
        'PAK'  => 20,
        'PACK' => 20,
        'LBR'  => 30,
        'IKT'  => 40,
        'KRG'  => 50,
        'DUS'  => 60,
    ];

    $otherEntries = array_filter(
        $entries,
        function ($uom) {
            return $uom !== 'BAL' && $uom !== 'KG';
        },
        ARRAY_FILTER_USE_KEY
    );

    uksort(
        $otherEntries,
        function ($a, $b) use ($otherPriority) {
            $priorityA = $otherPriority[$a] ?? 100;
            $priorityB = $otherPriority[$b] ?? 100;

            if ($priorityA === $priorityB) {
                return strcmp($a, $b);
            }

            return $priorityA <=> $priorityB;
        }
    );

    foreach ($otherEntries as $entryUom => $entryQty) {
        $qtyCol2 =
            fmtNumber($entryQty) . ' ' . $entryUom;
        break;
    }

    $qtyCol3 = '';

    if ($soUomPack === 'KG' && isset($entries['KG'])) {
        $qtyCol3 =
            fmtNumber($entries['KG']) . ' KG';
    }

    return [
        $qtyCol1,
        $qtyCol2,
        $qtyCol3
    ];
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
        ds.remarks_inventory_shipping,

        dso.uom_pack AS so_uom_pack,

        (
            SELECT GROUP_CONCAT(
                CONCAT(dud.qty_detail, ' ', dud.uom_detail)
                ORDER BY
                    CASE UPPER(dud.uom_detail)
                        WHEN 'BAL' THEN 1
                        WHEN 'ROLL' THEN 2
                        WHEN 'ROL' THEN 2
                        WHEN 'KG' THEN 3
                        ELSE 99
                    END,
                    dud.id ASC
                SEPARATOR ' | '
            )
            FROM det_shipping_uom_detail dud
            WHERE
                dud.det_shipping_id = ds.id
                OR (
                    dud.det_shipping_id IS NULL
                    AND dud.shipping_no = ds.shipping_no
                    AND dud.inventory_id = ds.inventory_id
                )
        ) AS multi_uom_detail_text

    FROM det_shipping ds

    INNER JOIN hed_shipping hs
        ON hs.shipping_no = ds.shipping_no

    LEFT JOIN m_inventory mi
        ON mi.inventory_id = ds.inventory_id

    LEFT JOIN detail_sales_order dso
        ON dso.order_no = hs.order_no
        AND dso.inventory_id = ds.inventory_id

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
$orderNoText = safeText($header['order_no'] ?? '');

$customerLines = splitAddressLines(
    $header['customer_name'] ?? '',
    $header['customer_address'] ?? '',
    $header['customer_city'] ?? ''
);

$vehicleText = safeText($header['transporter'] ?? '');
$truckNoText = safeText($header['truck_no'] ?? '');

// Proses remarks shipping dengan formatRemarksText
$remarksShippingRaw = safeText($header['remarks_shipping'] ?? '');
$remarksShippingText = formatRemarksText($remarksShippingRaw);

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

        .order-no-field {
            left: 10mm;
            top: 30mm;
            width: 110mm;
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
            width: 29mm;
            padding: 0 0.5mm;
            text-align: center;
            overflow: visible;
            font-size: 10.5pt;
        }

        .name-col {
            left: 70mm;
            width: 70mm;
            overflow: visible;
            text-align: left;
            white-space: normal !important;
            word-wrap: break-word;
            overflow-wrap: break-word;
            word-break: normal;
            text-overflow: clip;
        }

        .remarks-shipping-field {
            position: absolute;
            left: 89mm;
            width: 115mm;
            max-height: 30mm;
            overflow: hidden;
            color: #000;
            font-size: 10pt;
            line-height: 5mm;
            white-space: normal !important;
            word-wrap: break-word;
            word-break: break-word;
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
    <div class="field order-no-field"><?= e($orderNoText) ?></div>
    <div class="field customer-line-2"><?= e($customerLines[1]) ?></div>
    <div class="field customer-line-3"><?= e($customerLines[2]) ?></div>

    <div class="field vehicle-field"><?= e($vehicleText) ?></div>
    <div class="field truck-field"><?= e($truckNoText) ?></div>

    <?php
    $startTop = 85;
    $rowHeight = 5.0;
    $currentTop = $startTop;
    $usedSlots = 0;
    $hasMoreRows = false;
    $isFirstItem = true;

    foreach ($details as $detail):
        $itemName = getItemNameWithRemarks($detail);
        $nameLines = wrapItemName($itemName, 70, 11, 4);
        $lineCount = count($nameLines);

        if ($usedSlots + $lineCount > $maxRowSlots) {
            $hasMoreRows = true;
            break;
        }

        $qtyParts = getQtyDisplay($detail);

        $qtyCol1 = $qtyParts[0] ?? '';
        $qtyCol2 = $qtyParts[1] ?? '';
        $qtyCol3 = $qtyParts[2] ?? '';

        $topOffset = ($isFirstItem) ? 0 : 0.5;
        $qtyTop = $currentTop;
        $qtyCol2Top = $currentTop + $topOffset;
        $qtyCol3Top = $currentTop + $topOffset;
    ?>

        <div
            class="field row-field qty-col-1"
            style="top: <?= e($qtyTop) ?>mm;"
        ><?= e($qtyCol1) ?></div>

        <div
            class="field row-field qty-col-2"
            style="top: <?= e($qtyCol2Top) ?>mm;"
        ><?= e($qtyCol2) ?></div>

        <div
            class="field row-field qty-col-3"
            style="top: <?= e($qtyCol3Top) ?>mm;"
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
        $isFirstItem = false;
    endforeach;
    ?>

    <?php
    $remarksTop = $currentTop + 15;
    ?>

    <?php if ($remarksShippingText !== ''): ?>
        <div
            class="remarks-shipping-field"
            style="top: <?= e($remarksTop) ?>mm;"
        ><?= nl2br(e($remarksShippingText)) ?></div>
    <?php endif; ?>

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