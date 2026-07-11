<?php
// modul/transaksi/cetak_invoice.php
// Cetak invoice memakai kertas fisik "Surat Jalan" pre-printed yang sama dengan
// cetak_slip_shipping.php (F4 portrait, koordinat absolute mm). Setiap shipping_no
// yang tergabung dalam 1 invoice akan dicetak sebagai 1 halaman/nota fisik terpisah,
// ditambah kolom Harga Satuan (price) dan Jumlah (subtotal) di sebelah kanan Nama Barang.

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

function fmtMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

// Auto-shrink font untuk kolom angka (Harga Satuan / Jumlah) supaya tetap
// muat 1 baris di kolom sempit.
function fitFontSize($text, $maxWidthMm, $basePt = 10, $minPt = 6) {
    $len = mb_strlen((string)$text);
    if ($len <= 0) {
        return $basePt;
    }
    $charWidthFactor = 0.6 * 0.3528;
    $fitFont = $maxWidthMm / ($len * $charWidthFactor);
    $fontSize = min($basePt, $fitFont);
    $fontSize = max($minPt, $fontSize);
    return round($fontSize, 1);
}

function getInvoiceItemsByShipping($conn, $shippingNo) {
    $sql = "
        SELECT
            hs.shipping_no,
            hs.order_no,
            hs.transporter,
            hs.truck_no,
            COALESCE(mg.name, 'GUDANG BARANG JADI 1') AS warehouse_name,
            ds.inventory_id,
            ds.inventory_name,
            ds.qty_shipping,
            ds.uom_shipping,
            ds.qty_pack_shipping,
            ds.uom_pack_shipping,
            ds.qty_detail_shipping,
            ds.uom_detail_shipping,
            ds.remarks_inventory_shipping,
            ds.note,
            dso.price_unit AS so_price_unit,
            dso.price AS so_price,
            dso.subtotal AS so_subtotal,
            CASE
                WHEN COALESCE(dso.price, 0) > 0 THEN COALESCE(dso.price, 0)
                WHEN COALESCE(dso.quantity_pack, 0) > 0 THEN COALESCE(dso.subtotal, 0) / NULLIF(dso.quantity_pack, 0)
                ELSE 0
            END AS invoice_price,
            (
                COALESCE(ds.qty_pack_shipping, 0) *
                CASE
                    WHEN COALESCE(dso.price, 0) > 0 THEN COALESCE(dso.price, 0)
                    WHEN COALESCE(dso.quantity_pack, 0) > 0 THEN COALESCE(dso.subtotal, 0) / NULLIF(dso.quantity_pack, 0)
                    ELSE 0
                END
            ) AS invoice_subtotal
        FROM det_shipping ds
        INNER JOIN hed_shipping hs ON hs.shipping_no = ds.shipping_no
        LEFT JOIN m_gudang mg ON mg.gudang_id = hs.gudang_id
        LEFT JOIN detail_sales_order dso
               ON dso.order_no = hs.order_no
              AND dso.inventory_id = ds.inventory_id
        WHERE ds.shipping_no = ?
        ORDER BY ds.id ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 's', $shippingNo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $items = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $items[] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    return $items;
}

$invoiceNo = trim($_GET['invoice_no'] ?? $_GET['id'] ?? '');
if ($invoiceNo === '') {
    die('Invoice No kosong.');
}

$stmtHead = mysqli_prepare($conn, "SELECT * FROM head_invoice WHERE invoice_no = ? LIMIT 1");
if (!$stmtHead) die('Gagal prepare invoice: ' . mysqli_error($conn));
mysqli_stmt_bind_param($stmtHead, 's', $invoiceNo);
mysqli_stmt_execute($stmtHead);
$resHead = mysqli_stmt_get_result($stmtHead);
$head = $resHead ? mysqli_fetch_assoc($resHead) : null;
mysqli_stmt_close($stmtHead);

if (!$head) {
    die('Invoice tidak ditemukan.');
}

$stmtDet = mysqli_prepare($conn, "
    SELECT di.*, hs.gudang_id, COALESCE(mg.name, 'GUDANG BARANG JADI 1') AS warehouse_name
    FROM det_invoice di
    LEFT JOIN hed_shipping hs ON hs.shipping_no = di.shipping_no
    LEFT JOIN m_gudang mg ON mg.gudang_id = hs.gudang_id
    WHERE di.invoice_no = ?
    ORDER BY di.shipping_date ASC, di.shipping_no ASC
");
if (!$stmtDet) die('Gagal prepare detail invoice: ' . mysqli_error($conn));
mysqli_stmt_bind_param($stmtDet, 's', $invoiceNo);
mysqli_stmt_execute($stmtDet);
$resDet = mysqli_stmt_get_result($stmtDet);
$shippingList = [];
while ($resDet && $row = mysqli_fetch_assoc($resDet)) {
    $shippingList[] = $row;
}
mysqli_stmt_close($stmtDet);

$maxRowSlots = 10; // 10 baris fisik x 5mm per baris di nota
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Cetak Invoice - <?= e($invoiceNo) ?></title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: #f5f5f5;
            font-family: "Courier New", Courier, monospace;
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

        /* Container halaman fisik kertas F4 (PORTRAIT) - 1 halaman per shipping_no */
        .page {
            position: relative;
            width: 215mm;
            height: 330mm;
            margin: 0 auto 20px;
            background: #fff;
            overflow: hidden;
        }

        .field {
            position: absolute;
            color: #000;
            white-space: nowrap;
            overflow: hidden;
            line-height: 1;
        }

        /* ===== Koordinat sama persis dengan cetak_slip_shipping.php (hasil kalibrasi test print) ===== */

        .row-field {
            font-size: 11pt;
            height: 5mm;
            line-height: 5mm;
        }

        /* ===== BARU: kolom Harga Satuan & Jumlah, 0,5cm di kanan name-col.
           Lebar masih ESTIMASI (belum ada ukuran cm pasti dari foto nota untuk
           kolom ini) -> silakan koreksi setelah test print.
           Qty/Nama Barang TIDAK dicetak ulang di sini karena kertas fisik ini
           sudah dicetak sebelumnya oleh proses shipping. ===== */
        .price-col {
            left: 135mm; /* digeser 1cm ke kiri dari 145mm */
            width: 30mm;
            text-align: right;
        }

        .subtotal-col {
            left: 170mm; /* digeser 1cm ke kiri dari 180mm */
            width: 30mm;
            text-align: right;
        }

        .extra-warning {
            position: absolute;
            left: 10mm;
            top: 155mm;
            font-size: 9pt;
            color: #000;
        }

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
                position: relative !important;
                margin: 0 !important;
                width: 215mm !important;
                height: 330mm !important;
                background: transparent !important;
                box-shadow: none !important;
                page-break-after: always;
            }

            .page:last-of-type {
                page-break-after: auto;
            }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a class="btn btn-secondary" href="index.php?page=invoice">Kembali</a>
    <button class="btn btn-success" onclick="window.print()">Cetak / Print</button>

</div>

<?php foreach ($shippingList as $ship):
    $items = getInvoiceItemsByShipping($conn, $ship['shipping_no']);
?>
<div class="page">
    <?php
    $startTop = 82; // dinaikkan 0,3cm dari 85mm
    $rowHeight = 5.0;
    $currentTop = $startTop;
    $usedSlots = 0;
    $hasMoreRows = false;

    foreach ($items as $item):
        if ($usedSlots + 1 > $maxRowSlots) {
            $hasMoreRows = true;
            break;
        }

        $priceText = 'Rp ' . fmtMoney($item['invoice_price'] ?? 0);
        $subtotalText = 'Rp ' . fmtMoney($item['invoice_subtotal'] ?? 0);
        $priceFontSize = fitFontSize($priceText, 30, 10, 6);
        $subtotalFontSize = fitFontSize($subtotalText, 30, 10, 6);
    ?>
        <div class="field row-field price-col" style="top: <?= $currentTop ?>mm; font-size: <?= $priceFontSize ?>pt;"><?= e($priceText) ?></div>
        <div class="field row-field subtotal-col" style="top: <?= $currentTop ?>mm; font-size: <?= $subtotalFontSize ?>pt;"><?= e($subtotalText) ?></div>
    <?php
        $currentTop += $rowHeight;
        $usedSlots += 1;
    endforeach;
    ?>

    <?php if ($hasMoreRows): ?>
        <div class="extra-warning">* Item melebihi kapasitas baris nota. Mohon gunakan lembar tambahan.</div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

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