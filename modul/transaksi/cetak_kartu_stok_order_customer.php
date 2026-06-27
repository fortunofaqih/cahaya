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

$todaySql = date('Y-m-d');
$start_date = normalizeDate($_GET['start_date'] ?? '', $todaySql);
$end_date   = normalizeDate($_GET['end_date'] ?? '', $todaySql);
if ($start_date > $end_date) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

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
        inv.cap
    FROM head_sales_order h
    INNER JOIN detail_sales_order d ON d.order_no = h.order_no
    LEFT JOIN m_inventory inv ON inv.inventory_id = d.inventory_id
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
      AND hs.shipping_date BETWEEN ? AND ?
      AND COALESCE(hs.status, 'Open') <> 'Cancel'
    ORDER BY hs.shipping_date ASC, hs.shipping_no ASC, ds.id ASC
";
$stmtShip = mysqli_prepare($conn, $sqlShip);
if (!$stmtShip) {
    die('Prepare shipping error: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmtShip, 'ssss', $order_no, $inventory_id, $start_date, $end_date);
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

    $shipments[] = [
        'shipping_no' => $ship['shipping_no'],
        'shipping_date' => $ship['shipping_date'],
        'BAL' => $bucket['BAL'],
        'OTHER' => $bucket['OTHER'],
        'KG' => $bucket['KG'],
    ];
}

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
$harga = (float)($order['price'] ?? 0);
if ($harga <= 0) {
    $harga = (float)($order['price_unit'] ?? 0);
}
if ($harga > 0) {
    $hargaText = fmtNum($harga) . ' / ' . strtoupper((string)($order['uom_pack'] ?: $order['uom'] ?: 'UNIT'));
    if (strtolower((string)$order['vat']) !== 'none' && (string)$order['vat'] !== '') {
        $hargaText .= ' + PPN';
    }
}

$ukuranText = shortSizeFromName($order['inventory_name'], $order['catalog']);
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

$keterangan = trim((string)($order['detail_remarks'] ?? ''));
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
            size: 33cm 21.5cm;
            margin: 5mm 6mm 5mm 6mm;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
            background: #fff;
        }

        body * { visibility: hidden !important; }
        .print-page, .print-page * { visibility: visible !important; }

        .print-page {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            min-height: 20.5cm;
            border: 1px dotted #000;
            padding: 5px 6px;
        }

        .top-table { width: 100%; border-collapse: collapse; }
        .doc-code {
            border: 1px solid #000;
            font-weight: bold;
            padding: 4px 6px;
            width: 130px;
            font-size: 12px;
        }
        .title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            text-decoration: underline;
            letter-spacing: .3px;
        }
        .rev { text-align: right; width: 210px; font-size: 11px; }

        .info { margin-top: 5px; width: 100%; border-collapse: collapse; }
        .info td { padding: 2px 4px; vertical-align: top; }
        .label { width: 105px; }
        .colon { width: 8px; }
        .right-label { width: 60px; }

        .ship-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            font-size: 10.5px;
        }
        .ship-table th,
        .ship-table td {
            border: 1px solid #000;
            padding: 4px;
            height: 23px;
        }
        .ship-table th { text-align: center; font-weight: normal; }
        .ship-table .section-title { text-align: center; font-weight: normal; height: 24px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .bold { font-weight: bold; }

        .sign-wrap {
            margin-top: 12px;
            display: flex;
            justify-content: space-around;
            text-align: center;
            font-size: 11px;
        }
        .sign-space { height: 35px; }

        .print-button {
            position: fixed;
            right: 10px;
            top: 10px;
            z-index: 99;
            visibility: visible !important;
            padding: 8px 12px;
            border: 0;
            border-radius: 4px;
            background: #0d6efd;
            color: #fff;
            cursor: pointer;
        }
        @media print {
            .print-button { display: none !important; }
            .print-page { border: 1px dotted #000; }
        }
    </style>
</head>
<body>
    <button class="print-button" onclick="window.print()">Print</button>

    <div class="print-page">
        <table class="top-table">
            <tr>
                <td class="doc-code">MCP/FM/MRRT/01</td>
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
                <td class="right-label">Sales</td><td class="colon">:</td><td><?= e($order['marketing_id'] ?: $order['sales_id'] ?: '-') ?></td>
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
                <th rowspan="2" style="width: 15%;">TGL.<br>KIRIM</th>
                <th rowspan="2" style="width: 20%;">NO. SJ</th>
                <th colspan="3">JUMLAH</th>
            </tr>
            <tr>
                <th style="width: 22%;">BAL</th>
                <th style="width: 22%;">Other</th>
                <th style="width: 21%;">KG</th>
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
