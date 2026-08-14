<?php
// modul/transaksi/register_penjualan_perincian.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function parseDateR($v, $fallback) {
    $v = trim((string)$v);

    if ($v === '') {
        return $fallback;
    }

    foreach (['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d instanceof DateTime) {
            return $d->format('Y-m-d');
        }
    }

    return $fallback;
}

function fmtDateR($d) {
    if (empty($d) || $d === '0000-00-00') {
        return '';
    }

    $t = strtotime($d);
    return $t ? date('d-m-Y', $t) : '';
}

function fmtMoneyR($v) {
    return number_format((float)$v, 2, '.', ',');
}

function fmtQtyR($v) {
    return number_format((float)$v, 2, '.', ',');
}

$today = date('Y-m-d');

$startDate = parseDateR(
    $_GET['start_date'] ?? '',
    date('Y-m-01')
);

$endDate = parseDateR(
    $_GET['end_date'] ?? '',
    $today
);

if (strtotime($startDate) > strtotime($endDate)) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

/*
 * Kelompok sesuai kebutuhan user:
 *
 * Perincian:
 * PP | KERTAS | PE | PE WARNA
 *
 * Perincian Lain Lain:
 * HD | HD WARNA | HD KRESEK | HD SABLON |
 * TALI KG | TALI LOS | BAHAN | TERPAL | BOX
 */
$mainCategories = [
    'PP',
    'KERTAS',
    'PE',
    'PE WARNA'
];

$detailCategories = [
    'HD',
    'HD WARNA',
    'HD KRESEK',
    'HD SABLON',
    'TALI KG',
    'TALI LOS',
    'BAHAN',
    'TERPAL',
    'BOX'
];

$allCategories = array_merge($mainCategories, $detailCategories);

$summary = [];

foreach ($allCategories as $cat) {
    $summary[$cat] = [
        'qty' => 0.0,
        'rp'  => 0.0
    ];
}

$sql = "
SELECT
  CASE
    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%WARNA%'
        THEN 'PE WARNA'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%KERTAS%'
        THEN 'KERTAS'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])PP([^A-Z]|$)'
        THEN 'PP'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])PE([^A-Z]|$)'
        THEN 'PE'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%WARNA%'
        THEN 'HD WARNA'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%KRESEK%'
        THEN 'HD KRESEK'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%SABLON%'
        THEN 'HD SABLON'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%TALI%'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])KG([^A-Z]|$)'
        THEN 'TALI KG'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%TALI%'
     AND UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%LOS%'
        THEN 'TALI LOS'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])BAHAN([^A-Z]|$)'
        THEN 'BAHAN'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) LIKE '%TERPAL%'
        THEN 'TERPAL'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])BOX([^A-Z]|$)'
        THEN 'BOX'

    WHEN UPPER(COALESCE(NULLIF(TRIM(ds.inventory_name), ''),NULLIF(TRIM(mi.inventory_name), ''),'')) REGEXP '(^|[^A-Z])HD([^A-Z]|$)'
        THEN 'HD'

    ELSE NULL
END AS category_group,
    SUM(
        CASE
            WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,''))) <> ''
             AND UPPER(TRIM(COALESCE(ds.uom_pack_shipping,''))) <> 'KG'
                THEN COALESCE(ds.qty_pack_shipping,0)

            WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,''))) <> ''
             AND UPPER(TRIM(COALESCE(ds.uom_detail_shipping,''))) <> 'KG'
                THEN COALESCE(ds.qty_detail_shipping,0)

            ELSE COALESCE(ds.qty_shipping,0)
        END
    ) AS category_qty,

    SUM(
        CASE
            WHEN COALESCE(ds.subtotal,0) <> 0
                THEN COALESCE(ds.subtotal,0)

            ELSE COALESCE(dso.price,0) *
                CASE
                    WHEN COALESCE(ds.qty_pack_shipping,0) > 0
                        THEN COALESCE(ds.qty_pack_shipping,0)
                    ELSE COALESCE(ds.qty_shipping,0)
                END
        END
    ) AS category_rp

FROM det_invoice di
INNER JOIN head_invoice hi
    ON hi.invoice_no = di.invoice_no

INNER JOIN hed_shipping hs
    ON hs.shipping_no = di.shipping_no

INNER JOIN det_shipping ds
    ON ds.shipping_no = di.shipping_no

LEFT JOIN m_inventory mi
    ON mi.inventory_id = ds.inventory_id

LEFT JOIN m_category mc
    ON TRIM(mc.categori_id) = TRIM(mi.category)

LEFT JOIN detail_sales_order dso
    ON dso.id = (
        SELECT MIN(dso2.id)
        FROM detail_sales_order dso2
        WHERE dso2.order_no = hs.order_no
          AND dso2.inventory_id = ds.inventory_id
    )

WHERE hi.invoice_date BETWEEN ? AND ?

GROUP BY category_group

ORDER BY category_group ASC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die(
        'SQL Register Penjualan Perincian Error: ' .
        h(mysqli_error($conn))
    );
}

mysqli_stmt_bind_param(
    $stmt,
    'ss',
    $startDate,
    $endDate
);

mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($res)) {
    $group = $row['category_group'] ?? null;

    if ($group !== null && isset($summary[$group])) {
        $summary[$group]['qty'] += (float)$row['category_qty'];
        $summary[$group]['rp']  += (float)$row['category_rp'];
    }
}

mysqli_stmt_close($stmt);

$mainQtyTotal = 0.0;
$mainRpTotal  = 0.0;

foreach ($mainCategories as $cat) {
    $mainQtyTotal += $summary[$cat]['qty'];
    $mainRpTotal  += $summary[$cat]['rp'];
}

$detailQtyTotal = 0.0;
$detailRpTotal  = 0.0;

foreach ($detailCategories as $cat) {
    $detailQtyTotal += $summary[$cat]['qty'];
    $detailRpTotal  += $summary[$cat]['rp'];
}

$grandQty = $mainQtyTotal + $detailQtyTotal;
$grandRp  = $mainRpTotal + $detailRpTotal;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Register Perincian</title>

<style>
@page {
    size: A4 portrait;
    margin: 10mm 12mm;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #fff;
    color: #000;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 10px;
}

.print-action {
    text-align: right;
    margin-bottom: 8px;
}

.print-btn {
    border: 0;
    border-radius: 3px;
    padding: 7px 14px;
    background: #1e3c72;
    color: #fff;
    font-size: 11px;
    font-weight: bold;
    cursor: pointer;
}

.report-title {
    text-align: center;
    margin-bottom: 14px;
}

.report-title h1 {
    margin: 0 0 3px;
    font-size: 20px;
    font-weight: 700;
}

.report-title .period {
    font-size: 12px;
    font-weight: 700;
}

.section {
    margin-top: 12px;
    page-break-inside: avoid;
}

.section-title {
    margin: 0 0 5px;
    font-size: 11px;
    font-weight: 700;
}

.summary-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.summary-table th,
.summary-table td {
    border: 1px solid #555;
    padding: 5px 6px;
    vertical-align: middle;
}

.summary-table th {
    background: #f0f0f0;
    text-align: center;
    font-weight: 700;
}

.col-uraian {
    width: 50%;
}

.col-quantum {
    width: 20%;
}

.col-total {
    width: 30%;
}

.text-right {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.total-row td {
    font-weight: 700;
    background: #f5f5f5;
}

.grand-section {
    margin-top: 14px;
}

.grand-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}

.grand-table td {
    border: 1px solid #444;
    padding: 6px;
    font-weight: 700;
}

@media print {
    /*
     * Sembunyikan seluruh elemen halaman aplikasi, termasuk:
     * header, menu, logout, ganti password, sidebar, dll.
     */
    body * {
        visibility: hidden !important;
    }

    /*
     * Hanya area laporan yang boleh terlihat saat print.
     */
    #printArea,
    #printArea * {
        visibility: visible !important;
    }

    #printArea {
        position: absolute !important;
        left: 0 !important;
        top: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .print-action {
        display: none !important;
    }

    body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    thead {
        display: table-header-group;
    }

    tr {
        page-break-inside: avoid;
    }
}
</style>
</head>

<body>

<div class="print-action">
    <button
        type="button"
        class="print-btn"
        onclick="window.print()"
    >
        Print
    </button>
</div>

<div id="printArea">
<div class="report-title">
    <h1>Register Perincian</h1>
    <div class="period">
        Periode <?= h(fmtDateR($startDate)) ?>
        s/d
        <?= h(fmtDateR($endDate)) ?>
    </div>
</div>

<div class="section">
    <div class="section-title">Perincian</div>

    <table class="summary-table">
        <thead>
            <tr>
                <th class="col-uraian">Uraian</th>
                <th class="col-quantum">Quantum</th>
                <th class="col-total">Total</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($mainCategories as $cat): ?>
                <tr>
                    <td><?= h($cat) ?></td>
                    <td class="text-right">
                        <?= h(fmtQtyR($summary[$cat]['qty'])) ?>
                    </td>
                    <td class="text-right">
                        Rp <?= h(fmtMoneyR($summary[$cat]['rp'])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <tr class="total-row">
                <td style="text-align:right;">TOTAL PERINCIAN</td>
                <td class="text-right">
                    <?= h(fmtQtyR($mainQtyTotal)) ?>
                </td>
                <td class="text-right">
                    Rp <?= h(fmtMoneyR($mainRpTotal)) ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="section">
    <div class="section-title">Perincian Lain Lain</div>

    <table class="summary-table">
        <thead>
            <tr>
                <th class="col-uraian">Uraian</th>
                <th class="col-quantum">Quantum</th>
                <th class="col-total">Total</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($detailCategories as $cat): ?>
                <tr>
                    <td><?= h($cat) ?></td>
                    <td class="text-right">
                        <?= h(fmtQtyR($summary[$cat]['qty'])) ?>
                    </td>
                    <td class="text-right">
                        Rp <?= h(fmtMoneyR($summary[$cat]['rp'])) ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <tr class="total-row">
                <td style="text-align:right;">TOTAL PERINCIAN LAIN LAIN</td>
                <td class="text-right">
                    <?= h(fmtQtyR($detailQtyTotal)) ?>
                </td>
                <td class="text-right">
                    Rp <?= h(fmtMoneyR($detailRpTotal)) ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<div class="grand-section">
    <table class="grand-table">
        <tr>
            <td style="width:50%;text-align:right;">GRAND TOTAL</td>
            <td style="width:20%;" class="text-right">
                <?= h(fmtQtyR($grandQty)) ?>
            </td>
            <td style="width:30%;" class="text-right">
                Rp <?= h(fmtMoneyR($grandRp)) ?>
            </td>
        </tr>
    </table>
</div>
</div>

<script>
window.addEventListener('load', function () {
    window.print();
});
</script>

</body>
</html>