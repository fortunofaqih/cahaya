<?php
// modul/transaksi/cetak_kartu_stok_order_customer.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmtNum($value, $decimals = 2) {
    return number_format((float)($value ?? 0), $decimals, '.', ',');
}

function fmtDate($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '-';
}


function normalizeDate($date, $default = '') {
    $date = trim((string)$date);
    if ($date === '') {
        return $default;
    }

    // Format dari datepicker: 27-Jun-2026
    $dt = DateTime::createFromFormat('d-M-Y', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    // Format HTML/native: 2026-06-27
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    $ts = strtotime($date);
    return $ts ? date('Y-m-d', $ts) : $default;
}

function shortSizeFromName($inventoryName, $catalog = '') {
    $catalog = trim((string)$catalog);
    if ($catalog !== '') {
        return $catalog;
    }

    $name = trim((string)$inventoryName);
    if ($name === '') {
        return '-';
    }

    // Ambil mulai dari pola ukuran pertama, contoh: 0.0400X58/40X65 CMHD BOLA HITAM
    if (preg_match('/(\d+(?:\.\d+)?\s*[xX]\s*[^\s]+(?:\s+.*)?)/', $name, $m)) {
        $result = strtoupper(trim($m[1]));
        $result = preg_replace_callback('/\d+\.\d+/', function ($match) {
            $n = rtrim(rtrim($match[0], '0'), '.');
            return $n === '' ? '0' : $n;
        }, $result);
        return $result;
    }

    return $name;
}

function appendUnit(&$bucket, $qty, $unit) {
    $qty = (float)($qty ?? 0);
    $unit = strtoupper(trim((string)$unit));
    if ($qty == 0 || $unit === '') {
        return;
    }

    if ($unit === 'BAL') {
        $bucket['BAL'] += $qty;
    } elseif ($unit === 'KG') {
        $bucket['KG'] += $qty;
    } else {
        $bucket['OTHER'] += $qty;
    }
}

$order_no = trim((string)($_GET['order_no'] ?? ''));
$inventory_id = trim((string)($_GET['inventory_id'] ?? ''));

if ($order_no === '' || $inventory_id === '') {
    die('Parameter order_no dan inventory_id wajib diisi.');
}

$sql = "
    SELECT
        h.*,
        d.id AS so_detail_id,
        d.inventory_id,
        d.inventory_name,
        d.quantity,
        d.uom,
        d.quantity_pack,
        d.uom_pack,
        d.quantity_detail,
        d.uom_detail,
        d.price_unit,
        d.price,
        d.subtotal,
        d.remarks AS detail_remarks,
        inv.catalog,
        inv.p,
        inv.l,
        inv.t,
        inv.quality,
        inv.colour,
        inv.cap,
        mm.marketing_name,
        dp.ukuran AS po_ukuran,
        dsop.keterangan_rol,
        dsop.keterangan_potong
    FROM head_sales_order h
    INNER JOIN detail_sales_order d ON d.order_no = h.order_no
    LEFT JOIN m_inventory inv ON inv.inventory_id = d.inventory_id
    LEFT JOIN m_marketing mm
        ON mm.marketing_id = COALESCE(NULLIF(h.marketing_id, ''), h.sales_id)
    LEFT JOIN det_po dp
        ON dp.no_po = h.po
       AND (
            dp.ukuran = d.inventory_name
            OR dp.ukuran = inv.internal_name
            OR dp.ukuran LIKE CONCAT('%', d.inventory_name, '%')
            OR d.inventory_name LIKE CONCAT('%', dp.ukuran, '%')
       )
    LEFT JOIN head_sop hsop
        ON hsop.order_no = h.order_no
    LEFT JOIN det_sop dsop
        ON dsop.sop_id = hsop.sop_id
       AND dsop.inventory_id = d.inventory_id
    WHERE h.order_no = ? AND d.inventory_id = ?
    ORDER BY d.id ASC
    LIMIT 1
";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die('Prepare error: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, 'ss', $order_no, $inventory_id);
mysqli_stmt_execute($stmt);
$order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$order) {
    die('Data order tidak ditemukan.');
}

// Panjang roll dari m_inventory_uom ROLL
$rollLengthText = '-';
$sqlRoll = "SELECT Value FROM m_inventory_uom WHERE inventory_id = ? AND UPPER(unit) = 'ROLL' LIMIT 1";
$stmtRoll = mysqli_prepare($conn, $sqlRoll);
if ($stmtRoll) {
    mysqli_stmt_bind_param($stmtRoll, 's', $inventory_id);
    mysqli_stmt_execute($stmtRoll);
    $rollRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtRoll));
    if ($rollRow && (float)$rollRow['Value'] > 0) {
        $rollLengthText = fmtNum($rollRow['Value']) . ' KG/ROLL';
    }
}

// Riwayat shipping untuk order + inventory
$sqlShip = "
    SELECT
        hs.shipping_no,
        hs.shipping_date,
        ds.id AS det_shipping_id,
        ds.qty_shipping,
        ds.uom_shipping,
        ds.qty_pack_shipping,
        ds.uom_pack_shipping,
        ds.qty_detail_shipping,
        ds.uom_detail_shipping
    FROM hed_shipping hs
    INNER JOIN det_shipping ds ON ds.shipping_no = hs.shipping_no
    WHERE hs.order_no = ?
      AND ds.inventory_id = ?
      AND COALESCE(hs.status, 'Open') <> 'Cancel'
    ORDER BY hs.shipping_date ASC, hs.shipping_no ASC, ds.id ASC
";
$stmtShip = mysqli_prepare($conn, $sqlShip);
if (!$stmtShip) {
    die('Prepare shipping error: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmtShip, 'ss', $order_no, $inventory_id);
mysqli_stmt_execute($stmtShip);
$shippingRows = mysqli_stmt_get_result($stmtShip);

$shipments = [];
$totalShip = ['BAL' => 0.0, 'OTHER' => 0.0, 'KG' => 0.0];

while ($ship = mysqli_fetch_assoc($shippingRows)) {
    $bucket = ['BAL' => 0.0, 'OTHER' => 0.0, 'KG' => 0.0];

    // Ambil multi UOM detail kalau ada
    $sqlDetailUom = "SELECT uom_detail, qty_detail FROM det_shipping_uom_detail WHERE det_shipping_id = ? ORDER BY id ASC";
    $stmtDetailUom = mysqli_prepare($conn, $sqlDetailUom);
    $hasMultiDetail = false;
    if ($stmtDetailUom) {
        $detId = (int)$ship['det_shipping_id'];
        mysqli_stmt_bind_param($stmtDetailUom, 'i', $detId);
        mysqli_stmt_execute($stmtDetailUom);
        $detailRes = mysqli_stmt_get_result($stmtDetailUom);
        while ($du = mysqli_fetch_assoc($detailRes)) {
            appendUnit($bucket, $du['qty_detail'], $du['uom_detail']);
            $hasMultiDetail = true;
        }
        mysqli_stmt_close($stmtDetailUom);
    }

    if (!$hasMultiDetail) {
        appendUnit($bucket, $ship['qty_detail_shipping'], $ship['uom_detail_shipping']);
    }

    // Qty Pack untuk Other/BAL kalau belum terwakili detail
    if ((float)$ship['qty_pack_shipping'] != 0) {
        appendUnit($bucket, $ship['qty_pack_shipping'], $ship['uom_pack_shipping']);
    }

    // Qty base biasanya KG
    if ((float)$ship['qty_shipping'] != 0) {
        appendUnit($bucket, $ship['qty_shipping'], $ship['uom_shipping']);
    }

    $totalShip['BAL'] += $bucket['BAL'];
    $totalShip['OTHER'] += $bucket['OTHER'];
    $totalShip['KG'] += $bucket['KG'];

    // Satu Shipping No bisa mempunyai lebih dari satu det_shipping
    // untuk inventory yang sama. Gabungkan agar cetak hanya 1 baris per SJ.
    $shipKey = (string)$ship['shipping_no'];

    if (!isset($shipments[$shipKey])) {
        $shipments[$shipKey] = [
            'shipping_no'   => $ship['shipping_no'],
            'shipping_date' => $ship['shipping_date'],
            'BAL'           => 0.0,
            'OTHER'         => 0.0,
            'KG'            => 0.0,
        ];
    }

    $shipments[$shipKey]['BAL']   += $bucket['BAL'];
    $shipments[$shipKey]['OTHER'] += $bucket['OTHER'];
    $shipments[$shipKey]['KG']    += $bucket['KG'];
}

// Kembalikan menjadi indexed array untuk proses cetak.
$shipments = array_values($shipments);

$orderBucket = ['BAL' => 0.0, 'OTHER' => 0.0, 'KG' => 0.0];
appendUnit($orderBucket, $order['quantity_detail'], $order['uom_detail']);
appendUnit($orderBucket, $order['quantity_pack'], $order['uom_pack']);
appendUnit($orderBucket, $order['quantity'], $order['uom']);

// Outstanding dihitung dari data yang diprint/periode filter.
// Untuk kolom yang memang tidak ada di order, tampilkan kosong agar tidak muncul angka minus seperti -1 BAL.
$outstanding = [
    'BAL' => $orderBucket['BAL'] != 0 ? ($orderBucket['BAL'] - $totalShip['BAL']) : 0,
    'OTHER' => $orderBucket['OTHER'] != 0 ? ($orderBucket['OTHER'] - $totalShip['OTHER']) : 0,
    'KG' => $orderBucket['KG'] != 0 ? ($orderBucket['KG'] - $totalShip['KG']) : 0,
];

$hargaText = '-';

// Harga/KG berdasarkan:
// detail_sales_order.subtotal / detail_sales_order.quantity
$qty_raw = (float)($order['quantity'] ?? 0);
$subtotal_raw = (float)($order['subtotal'] ?? 0);

$harga_kg = 0.0;

if ($qty_raw > 0 && $subtotal_raw > 0) {
    $harga_kg = $subtotal_raw / $qty_raw;

    // Cek pecahan terhadap kelipatan 1.000
    $nilai_ribuan = $harga_kg / 1000;
    $pecahan_ribuan = $nilai_ribuan - floor($nilai_ribuan);

    // Jika pecahannya 0,98 atau lebih, bulatkan ke ribuan berikutnya
    if ($pecahan_ribuan >= 0.98) {
        $harga_kg = ceil($nilai_ribuan) * 1000;
    }
}

if ($harga_kg > 0) {
    $hargaText = fmtNum($harga_kg) . ' / KG';

    if (strtolower((string)$order['vat']) !== 'none' && trim((string)$order['vat']) !== '') {
        $hargaText .= ' + PPN';
    }
}

$ukuranText =  shortSizeFromName($order['inventory_name'], $order['catalog']);
$jumlahOrderText = formatJumlahOrderText($order ?? []);

function formatJumlahOrderText($order) {
    $parts = [];
    if ((float)($order['quantity_pack'] ?? 0) != 0) {
        $parts[] = fmtNum($order['quantity_pack']) . ' ' . strtoupper((string)($order['uom_pack'] ?? ''));
    }
    if ((float)($order['quantity_detail'] ?? 0) != 0) {
        $parts[] = fmtNum($order['quantity_detail']) . ' ' . strtoupper((string)($order['uom_detail'] ?? ''));
    }
    if (!$parts && (float)($order['quantity'] ?? 0) != 0) {
        $parts[] = fmtNum($order['quantity']) . ' ' . strtoupper((string)($order['uom'] ?? ''));
    }
    return $parts ? implode(' = ', $parts) : '-';
}

$isiBalText = '-';
if (strtoupper((string)$order['uom_detail']) === 'BAL' && (float)$order['quantity_detail'] > 0 && (float)$order['quantity_pack'] > 0) {
    $isiBalText = '@' . fmtNum(((float)$order['quantity_pack'] / (float)$order['quantity_detail'])) . ' ' . strtoupper((string)$order['uom_pack']) . '/BAL';
}

// Keterangan diambil dari det_sop:
// - Inventory ROLL   -> keterangan_rol
// - Inventory POTONG -> keterangan_potong
$inventoryText = strtoupper(trim(
    (string)($order['inventory_name'] ?? '') . ' ' .
    (string)($order['quality'] ?? '') . ' ' .
    (string)($order['catalog'] ?? '')
));

if (strpos($inventoryText, 'ROLL') !== false || strpos($inventoryText, ' ROL ') !== false) {
    $keterangan = trim((string)($order['keterangan_rol'] ?? ''));
} elseif (strpos($inventoryText, 'POTONG') !== false || strpos($inventoryText, 'PTG') !== false) {
    $keterangan = trim((string)($order['keterangan_potong'] ?? ''));
} else {
    // Fallback jika nama inventory tidak mengandung ROLL/POTONG
    $keterangan = trim((string)($order['detail_remarks'] ?? ''));
}

if ($keterangan === '') {
    $keterangan = '-';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Cetak Kartu Stok Order Customer - <?= e($order_no) ?></title>
    <style>
        @page {
            size: A4 landscape;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            color: #000;
            background: #fff;
        }

        /*
         * Kertas printer: A4 landscape = 297 x 210 mm.
         * Kartu dibuat 148 mm (setengah lebar A4) sehingga hasil
         * berada di sisi kiri seperti contoh fisik.
         */
        .print-page {
            width: 148mm;
            padding: 5mm 4mm 4mm 5mm;
            font-size: 8pt;
            line-height: 1.15;
            background: #fff;
        }

        .top-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .top-table td {
            vertical-align: middle;
        }

        .doc-code {
            width: 32mm;
            border: 0.3mm solid #000;
            font-weight: 700;
            padding: 1.4mm 1mm;
            white-space: nowrap;
            text-align: center;
            font-size: 7.4pt;
        }

        .title {
            width: 68mm;
            text-align: center;
            font-size: 9.2pt;
            font-weight: 700;
            white-space: nowrap;
            padding: 0 1mm;
        }

        .rev {
            width: 39mm;
            text-align: right;
            white-space: nowrap;
            font-size: 6.8pt;
        }

        .info {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1.5mm;
            table-layout: fixed;
        }

        .info td {
            padding: 0.55mm 0.6mm;
            vertical-align: top;
            font-size: 7.4pt;
            line-height: 1.15;
        }

        .info .label {
            width: 24mm;
            white-space: nowrap;
        }

        .info .colon {
            width: 3mm;
            padding-left: 0;
            padding-right: 0;
        }

        .info .right-label {
            width: 16mm;
            white-space: nowrap;
        }

        .ship-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin-top: 3mm;
            font-size: 7.3pt;
        }

        .ship-table th,
        .ship-table td {
            border: 0.25mm solid #000;
            padding: 1.1mm 1mm;
            height: 6.4mm;
            vertical-align: middle;
        }

        .ship-table th {
            text-align: center;
            font-weight: 400;
        }

        .ship-table .section-title {
            text-align: center;
            font-weight: 400;
            height: 5.8mm;
            padding: 1mm;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .bold {
            font-weight: 700;
        }

        .sign-wrap {
            margin-top: 2.5mm;
            width: 100%;
            display: flex;
            justify-content: space-around;
            text-align: center;
            font-size: 7.2pt;
        }

        .sign-wrap > div {
            width: 45%;
        }

        .sign-space {
            height: 10mm;
        }

        .print-button {
            position: fixed;
            right: 10px;
            top: 10px;
            z-index: 999;
            padding: 8px 12px;
            border: 0;
            border-radius: 4px;
            background: #0d6efd;
            color: #fff;
            cursor: pointer;
            font-size: 12px;
        }

        @media screen {
            body {
                background: #ececec;
                padding: 10px;
            }

            .print-page {
                min-height: 200mm;
                box-shadow: 0 0 6px rgba(0,0,0,.25);
            }
        }

        @media print {
            html,
            body {
                width: 297mm;
                height: 210mm;
                overflow: hidden;
                background: #fff;
            }

            body {
                padding: 0;
            }

            .print-button {
                display: none !important;
            }

            .print-page {
                position: absolute;
                left: 0;
                top: 0;
                width: 148mm;
                margin: 0;
                box-shadow: none;
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <button class="print-button" onclick="window.print()">Print</button>

    <div class="print-page">
        <table class="top-table">
            <tr>
                <td class="doc-code">MCP/FM/MRKT/01</td>
                <td class="title">KARTU STOK ORDER CUSTOMER</td>
                <td class="rev">Rev : 00&nbsp;&nbsp;Tgl : 01/06/2010</td>
            </tr>
        </table>

        <table class="info">
            <tr>
                <td class="label">TGL. ORDER</td><td class="colon">:</td><td><?= e(fmtDate($order['order_date'])) ?></td>
                <td class="right-label">MCP</td><td class="colon">:</td><td><?= e($order['sop'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="label">NAMA CUSTOMER</td><td class="colon">:</td><td><?= e($order['customer_name']) ?></td>
                <td class="right-label"></td><td></td><td></td>
            </tr>
            <tr>
                <td class="label">NO. PO</td><td class="colon">:</td><td><?= e($order['po'] ?: '-') ?></td>
                <td class="right-label">Marketing</td><td class="colon">:</td><td><?= e($order['marketing_name'] ?: $order['marketing_id'] ?: $order['sales_id'] ?: '-') ?></td>
            </tr>
            <tr>
                <td class="label">HARGA</td><td class="colon">:</td><td><?= e($hargaText) ?></td>
                <td></td><td></td><td></td>
            </tr>
            <tr>
                <td class="label">UKURAN</td><td class="colon">:</td><td colspan="4"><?= e($ukuranText) ?></td>
            </tr>
            <tr>
                <td class="label">PANJANG ROL</td><td class="colon">:</td><td colspan="4"><?= e($rollLengthText) ?></td>
            </tr>
            <tr>
                <td class="label">JUMLAH ORDER</td><td class="colon">:</td><td colspan="4"><?= e($jumlahOrderText) ?></td>
            </tr>
            <tr>
                <td class="label">ISI / BAL</td><td class="colon">:</td><td colspan="4"><?= e($isiBalText) ?></td>
            </tr>
            <tr>
                <td class="label">KETERANGAN</td><td class="colon">:</td><td colspan="4"><?= nl2br(e($keterangan)) ?></td>
            </tr>
            <tr>
                <td class="label">CODE</td><td class="colon">:</td><td colspan="4">&nbsp;</td>
            </tr>
            <tr>
                <td class="label">TANGGAL KIRIM</td><td class="colon">:</td><td colspan="4"><?= e(fmtDate($order['shipment_due_date'])) ?></td>
            </tr>
        </table>

        <table class="ship-table">
            <tr>
                <th colspan="5" class="section-title">DATA PENGIRIMAN BARANG</th>
            </tr>
            <tr>
                <th rowspan="2" style="width: 18%;">TGL.<br>KIRIM</th>
                <th rowspan="2" style="width: 22%;">NO. SJ</th>
                <th colspan="3">JUMLAH</th>
            </tr>
            <tr>
                <th style="width: 20%;">BAL</th>
                <th style="width: 20%;">Other</th>
                <th style="width: 20%;">KG</th>
            </tr>

            <?php
                $minRows = 1;
                $rowCount = 0;
                foreach ($shipments as $s):
                    $rowCount++;
            ?>
                <tr>
                    <td class="text-center"><?= e(fmtDate($s['shipping_date'])) ?></td>
                    <td class="text-center"><?= e($s['shipping_no']) ?></td>
                    <td class="text-right"><?= $s['BAL'] != 0 ? e(fmtNum($s['BAL'])) : '&nbsp;' ?></td>
                    <td class="text-right"><?= $s['OTHER'] != 0 ? e(fmtNum($s['OTHER'])) : '&nbsp;' ?></td>
                    <td class="text-right"><?= $s['KG'] != 0 ? e(fmtNum($s['KG'])) : '&nbsp;' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php for ($i = $rowCount; $i < $minRows; $i++): ?>
                <tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
            <?php endfor; ?>

            <tr>
                <td colspan="2" class="bold">Total Shipping</td>
                <td class="text-right bold"><?= $totalShip['BAL'] != 0 ? e(fmtNum($totalShip['BAL'])) : '&nbsp;' ?></td>
                <td class="text-right bold"><?= $totalShip['OTHER'] != 0 ? e(fmtNum($totalShip['OTHER'])) : '&nbsp;' ?></td>
                <td class="text-right bold"><?= $totalShip['KG'] != 0 ? e(fmtNum($totalShip['KG'])) : '&nbsp;' ?></td>
            </tr>
            <tr>
                <td colspan="2" class="bold">Jumlah Order</td>
                <td class="text-right bold"><?= $orderBucket['BAL'] != 0 ? e(fmtNum($orderBucket['BAL'])) : '&nbsp;' ?></td>
                <td class="text-right bold"><?= $orderBucket['OTHER'] != 0 ? e(fmtNum($orderBucket['OTHER'])) : '&nbsp;' ?></td>
                <td class="text-right bold"><?= $orderBucket['KG'] != 0 ? e(fmtNum($orderBucket['KG'])) : '&nbsp;' ?></td>
            </tr>
            <tr>
                <td colspan="2" class="bold">Outstanding Order</td>
                <td class="text-right bold"><?= $outstanding['BAL'] != 0 ? e(fmtNum($outstanding['BAL'])) : '&nbsp;' ?></td>
                <td class="text-right bold"><?= $outstanding['OTHER'] != 0 ? e(fmtNum($outstanding['OTHER'])) : '&nbsp;' ?></td>
                <td class="text-right bold"><?= $outstanding['KG'] != 0 ? e(fmtNum($outstanding['KG'])) : '&nbsp;' ?></td>
            </tr>
        </table>

        <div class="sign-wrap">
            <div>
                Dibuat oleh,<br>
                <div class="sign-space"></div>
                (____________________)<br>
                Adm. Marketing
            </div>
            <div>
                Disetujui oleh,<br>
                <div class="sign-space"></div>
                (____________________)<br>
                Staf Marketing
            </div>
        </div>
    </div>
</body>
</html>
