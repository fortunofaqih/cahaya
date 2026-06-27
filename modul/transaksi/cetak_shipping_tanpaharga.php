<?php
// modul/transaksi/cetak_shipping.php
// Format cetak untuk Surat Jalan pre-printed Marketing.
// Kertas/form sudah ada garis dan judul, file ini hanya mencetak isi pada kolom kosong.

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

function fmtMoney($number) {
    $number = (float)$number;
    if (abs($number) < 0.000001) {
        return '';
    }

    return number_format($number, 0, ',', '.');
}

function formatDateForBlank($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    if (!$ts) {
        return '';
    }

    // Format singkat agar pas pada garis form: 27-06-2026
    return date('d-m-Y', $ts);
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
        // Pecah alamat agar masuk ke 2 baris kosong pada form.
        $wrapped = wordwrap($address, 58, "\n", false);
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

function buildUomDetailText($row) {
    $jsonText = safeText($row['uom_detail_text'] ?? '');

    if ($jsonText !== '') {
        return $jsonText;
    }

    // Fallback data lama dari det_shipping jika tabel det_shipping_uom_detail belum ada datanya.
    $oldUom = safeText($row['uom_detail_shipping'] ?? '');
    $oldQty = (float)($row['qty_detail_shipping'] ?? 0);

    if ($oldUom !== '' && strtoupper($oldUom) !== '-- PILIH UOM DETAIL --' && $oldQty > 0) {
        return fmtNumber($oldQty) . ' ' . $oldUom;
    }

    return '';
}


function normalizeSizeNumber($num) {
    $num = str_replace(',', '.', trim((string)$num));
    if (strpos($num, '.') !== false) {
        $num = rtrim(rtrim($num, '0'), '.');
    }
    return $num;
}

function cleanSizeText($text) {
    $text = preg_replace('/\s+/', ' ', trim((string)$text));
    $text = preg_replace('/\s*[xX×]\s*/', 'X', $text);
    $text = preg_replace_callback('/\d+(?:[\.,]\d+)?/', function ($m) {
        return normalizeSizeNumber($m[0]);
    }, $text);
    return trim($text);
}

function extractPrintableItemName($inventoryName, $catalog = '') {
    $source = safeText($catalog);
    if ($source === '') {
        $source = safeText($inventoryName);
    }

    $source = preg_replace('/\s+/', ' ', $source);
    $source = trim($source);

    if ($source === '') {
        return '';
    }

    // Format umum produk: 0.0400X58/40X65 CMHD BOLA HITAM
    // Yang dicetak: 0.04X40X65 CMHD BOLA HITAM
    if (preg_match('/(\d+(?:[\.,]\d+)?)\s*[xX×]\s*\d+(?:[\.,]\d+)?\s*\/\s*(.+)$/u', $source, $m)) {
        return cleanSizeText(normalizeSizeNumber($m[1]) . 'X' . $m[2]);
    }

    // Kalau ada ukuran tanpa slash, ambil mulai dari ukuran pertama sampai akhir nama.
    if (preg_match('/(\d+(?:[\.,]\d+)?\s*[xX×]\s*.+)$/u', $source, $m)) {
        return cleanSizeText($m[1]);
    }

    // Kalau tidak ada ukuran seperti BOX R10 GSM330, cetak semua.
    return cleanSizeText($source);
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
// Ambil detail shipping + multi UOM detail
// ===============================
$stmtDetail = mysqli_prepare($conn, "
    SELECT
        ds.*,
        mi.catalog AS inventory_catalog,
        (
            SELECT GROUP_CONCAT(
                CONCAT(
                    TRIM(TRAILING '.00' FROM TRIM(TRAILING '0' FROM CAST(dud.qty_detail AS CHAR))),
                    ' ',
                    dud.uom_detail
                )
                ORDER BY dud.id ASC
                SEPARATOR ', '
            )
            FROM det_shipping_uom_detail dud
            WHERE dud.shipping_no = ds.shipping_no
              AND dud.det_shipping_id = ds.id
        ) AS uom_detail_text
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

$shippingDate = formatDateForBlank($header['shipping_date'] ?? '');
$customerLines = splitAddressLines(
    $header['customer_name'] ?? '',
    $header['customer_address'] ?? '',
    $header['customer_city'] ?? ''
);

$vehicleText = safeText($header['transporter'] ?? '');
$truckNoText = safeText($header['truck_no'] ?? '');

// Maksimal baris mengikuti tinggi form pada foto.
$maxRows = 9;
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
            font-family: "Times New Roman", Times, serif;
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
        .btn-warning { background: #ffc107; color: #000; }

        .note {
            margin-top: 8px;
            font-size: 12px;
            color: #555;
        }

        /*
            Kertas mengikuti form Surat Jalan pada foto: kurang lebih A5 landscape.
            Jika hasil print bergeser, cukup ubah --offset-x dan --offset-y.
        */
        .page {
            --offset-x: 0mm;
            --offset-y: 0mm;

            position: relative;
            width: 210mm;
            height: 148mm;
            margin: 10mm auto;
            background: #fff;
            overflow: hidden;
        }

        .field {
            position: absolute;
            transform: translate(var(--offset-x), var(--offset-y));
            color: #000;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1.1;
        }

        .date-field {
            left: 134mm;
            top: 18.5mm;
            width: 30mm;
            font-size: 12pt;
            text-align: left;
        }

        .customer-line-1 {
            left: 114mm;
            top: 40mm;
            width: 73mm;
            font-size: 11pt;
        }

        .customer-line-2 {
            left: 114mm;
            top: 50.5mm;
            width: 73mm;
            font-size: 10.5pt;
        }

        .customer-line-3 {
            left: 114mm;
            top: 60.8mm;
            width: 73mm;
            font-size: 10.5pt;
        }

        .vehicle-field {
            left: 49mm;
            top: 75.4mm;
            width: 32mm;
            font-size: 10.5pt;
        }

        .truck-field {
            left: 90mm;
            top: 75.4mm;
            width: 36mm;
            font-size: 10.5pt;
        }

        .row-field {
            font-size: 10.2pt;
            height: 5.6mm;
            line-height: 5.6mm;
        }

        .qty-col-1 {
            left: 20.5mm;
            width: 15mm;
            text-align: center;
        }

        .qty-col-2 {
            left: 36.3mm;
            width: 16mm;
            text-align: center;
        }

        .qty-col-3 {
            left: 53.3mm;
            width: 16mm;
            text-align: center;
            font-size: 9.6pt;
        }

        .name-col {
            left: 70.5mm;
            width: 73mm;
            text-align: left;
        }

        .price-col {
            left: 146mm;
            width: 25mm;
            text-align: right;
            padding-right: 2mm;
        }

        .amount-col {
            left: 173mm;
            width: 25mm;
            text-align: right;
            padding-right: 2mm;
        }

        .extra-warning {
            position: absolute;
            left: 18mm;
            top: 134mm;
            font-size: 8pt;
            font-family: Arial, sans-serif;
            color: #000;
        }

        @page {
            size: 210mm 148mm;
            margin: 0;
        }

        @media print {
            @page {
                size: 210mm 148mm;
                margin: 0;
            }

            html,
            body {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                width: 210mm;
                height: 148mm;
                overflow: hidden !important;
            }

            /* Sembunyikan semua elemen bawaan layout ERP: header, menu, logout, ganti password, footer. */
            body * {
                visibility: hidden !important;
            }

            .page,
            .page * {
                visibility: visible !important;
            }

            header,
            footer,
            nav,
            aside,
            .navbar,
            .sidebar,
            .main-header,
            .main-sidebar,
            .content-header,
            .main-footer,
            .footer,
            .breadcrumb,
            .no-print {
                display: none !important;
                visibility: hidden !important;
            }

            .page {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                margin: 0 !important;
                width: 210mm !important;
                height: 148mm !important;
                box-shadow: none !important;
                background: transparent !important;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a class="btn btn-secondary" href="index.php?page=shipping">Kembali</a>
    <button class="btn btn-success" onclick="window.print()">Cetak / Print</button>
    <button class="btn btn-warning" onclick="location.reload()">Refresh</button>
    <div class="note">
        Format ini untuk form Surat Jalan pre-printed. Jika hasil bergeser, atur nilai <strong>--offset-x</strong> dan <strong>--offset-y</strong> di CSS.
    </div>
</div>

<div class="page">
    <!-- Header kosong pada form -->
    <div class="field date-field"><?= e($shippingDate) ?></div>

    <div class="field customer-line-1"><?= e($customerLines[0]) ?></div>
    <div class="field customer-line-2"><?= e($customerLines[1]) ?></div>
    <div class="field customer-line-3"><?= e($customerLines[2]) ?></div>

    <div class="field vehicle-field"><?= e($vehicleText) ?></div>
    <div class="field truck-field"><?= e($truckNoText) ?></div>

    <?php
    $startTop = 91.8; // mm; baris pertama tabel pada form
    $rowHeight = 6.35; // mm

    foreach ($printRows as $idx => $detail):
        $top = $startTop + ($idx * $rowHeight);

        $qtyPack = (float)($detail['qty_pack_shipping'] ?? 0);
        $uomPack = safeText($detail['uom_pack_shipping'] ?? '');
        $qtyBase = (float)($detail['qty_shipping'] ?? 0);
        $uomBase = safeText($detail['uom_shipping'] ?? '');

        // Kolom Banyaknya pada form punya 3 ruang kecil.
        // Utama: Qty Pack + UOM Pack. Ruang ketiga dipakai Qty/UOM dasar jika berbeda.
        if ($qtyPack > 0 || $uomPack !== '') {
            $qtyCol1 = fmtNumber($qtyPack);
            $qtyCol2 = $uomPack;
        } else {
            $qtyCol1 = fmtNumber($qtyBase);
            $qtyCol2 = $uomBase;
        }

        $qtyCol3 = '';
        if ($qtyBase > 0 && $uomBase !== '' && ($qtyBase != $qtyPack || strtoupper($uomBase) !== strtoupper($uomPack))) {
            $qtyCol3 = fmtNumber($qtyBase) . ' ' . $uomBase;
        }

        $uomDetailText = buildUomDetailText($detail);
        $inventoryName = safeText($detail['inventory_name'] ?? '');
        $inventoryCatalog = safeText($detail['inventory_catalog'] ?? '');
        $itemName = extractPrintableItemName($inventoryName, $inventoryCatalog);

        // Sesuai request: jika ada UOM Detail, tambahkan di kanan nama barang.
        // Contoh: 0.04X40X65 CMHD BOLA HITAM 2 ROLL
        if ($uomDetailText !== '') {
            $itemName .= ' ' . $uomDetailText;
        }

        $priceUnit = fmtMoney($detail['price_unit'] ?? 0);
        $subtotal = fmtMoney($detail['subtotal'] ?? 0);
    ?>
        <div class="field row-field qty-col-1" style="top: <?= $top ?>mm;"><?= e($qtyCol1) ?></div>
        <div class="field row-field qty-col-2" style="top: <?= $top ?>mm;"><?= e($qtyCol2) ?></div>
        <div class="field row-field qty-col-3" style="top: <?= $top ?>mm;"><?= e($qtyCol3) ?></div>
        <div class="field row-field name-col" style="top: <?= $top ?>mm;"><?= e($itemName) ?></div>
        <div class="field row-field price-col" style="top: <?= $top ?>mm;"><?= e($priceUnit) ?></div>
        <div class="field row-field amount-col" style="top: <?= $top ?>mm;"><?= e($subtotal) ?></div>
    <?php endforeach; ?>

    <?php if ($hasMoreRows): ?>
        <div class="extra-warning">* Item lebih dari <?= (int)$maxRows ?> baris. Lanjutkan pada surat jalan berikutnya.</div>
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
