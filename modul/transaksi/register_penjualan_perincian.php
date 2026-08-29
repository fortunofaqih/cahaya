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
    $v = (float)$v;
    $whole = $v < 0 ? ceil($v) : floor($v);
    return number_format($whole, 2, '.', ',');
}

function fmtQtyR($v) {
    return number_format((float)$v, 2, '.', ',');
}

function classifyCategoryR($inventoryName) {
    $n = strtoupper(trim((string)$inventoryName));
    if ($n === '') return null;

    if (strpos($n, 'SEDOTAN') !== false) return 'SEDOTAN';

    // Nama normal.
    if (preg_match('/(^|[^A-Z])PE([^A-Z]|$)/', $n) && strpos($n, 'WARNA') !== false) return 'PE WARNA';
    if (strpos($n, 'KERTAS') !== false) return 'KERTAS';
    if (preg_match('/(^|[^A-Z])PP([^A-Z]|$)/', $n)) return 'PP';
    if (preg_match('/(^|[^A-Z])PE([^A-Z]|$)/', $n)) return 'PE';

    // Nama legacy / CP-MCP sering tanpa spasi, contoh:
    // 0.03X17X100MPP  -> PP
    // 66X530MPEMULSA... -> PE
    if (strpos($n, 'MPP') !== false) return 'PP';
    if (strpos($n, 'MPE') !== false) return 'PE';
    if (preg_match('/(^|[^A-Z])HD([^A-Z]|$)/', $n) && strpos($n, 'WARNA') !== false) return 'HD WARNA';
    if (preg_match('/(^|[^A-Z])HD([^A-Z]|$)/', $n) && strpos($n, 'KRESEK') !== false) return 'HD KRESEK';
    if (preg_match('/(^|[^A-Z])HD([^A-Z]|$)/', $n) && strpos($n, 'SABLON') !== false) return 'HD SABLON';
    if (strpos($n, 'TALI') !== false && preg_match('/(^|[^A-Z])KG([^A-Z]|$)/', $n)) return 'TALI KG';
    if (strpos($n, 'TALI') !== false && strpos($n, 'LOS') !== false) return 'TALI LOS';
    if (preg_match('/(^|[^A-Z])BAHAN([^A-Z]|$)/', $n)) return 'BAHAN';
    if (strpos($n, 'TERPAL') !== false) return 'TERPAL';
    if (preg_match('/(^|[^A-Z])BOX([^A-Z]|$)/', $n)) return 'BOX';
    if (preg_match('/(^|[^A-Z])HD([^A-Z]|$)/', $n)) return 'HD';
    return null;
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
 * PP | KERTAS | PE | PE WARNA | LAIN LAIN
 *
 * LAIN LAIN = ringkasan total dari Perincian Lain Lain.
 *
 * Perincian Lain Lain:
 * HD | HD WARNA | HD KRESEK | HD SABLON |
 * TALI KG | TALI LOS | BAHAN | TERPAL | BOX | SEDOTAN
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
    'BOX',
    'SEDOTAN'
];

$allCategories = array_merge($mainCategories, $detailCategories);

$summary = [];

foreach ($allCategories as $cat) {
    $summary[$cat] = [
        'qty' => 0.0,
        'rp'  => 0.0
    ];
}

/*
 * Nilai penjualan mengikuti invoice.
 * Shipping dipakai hanya untuk mengetahui inventory, kategori, dan quantity.
 */
$sql = "
SELECT
    di.invoice_no,
    di.shipping_no,
    di.subtotal AS invoice_subtotal,
    ds.id AS shipping_detail_id,
    COALESCE(NULLIF(TRIM(ds.inventory_name),''), NULLIF(TRIM(mi.inventory_name),''), '') AS inventory_name,
    CASE
        WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,''))) <> ''
         AND UPPER(TRIM(COALESCE(ds.uom_pack_shipping,''))) <> 'KG'
            THEN COALESCE(ds.qty_pack_shipping,0)
        WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,''))) <> ''
         AND UPPER(TRIM(COALESCE(ds.uom_detail_shipping,''))) <> 'KG'
            THEN COALESCE(ds.qty_detail_shipping,0)
        ELSE COALESCE(ds.qty_shipping,0)
    END AS row_qty,
    CASE
        WHEN COALESCE(ds.subtotal,0) <> 0 THEN ABS(COALESCE(ds.subtotal,0))
        WHEN COALESCE(dso.price,0) <> 0 THEN ABS(
            COALESCE(dso.price,0) *
            CASE
                WHEN COALESCE(ds.qty_pack_shipping,0) > 0 THEN COALESCE(ds.qty_pack_shipping,0)
                ELSE COALESCE(ds.qty_shipping,0)
            END
        )
        ELSE 0
    END AS allocation_basis
FROM det_invoice di
INNER JOIN head_invoice hi
    ON TRIM(hi.invoice_no) = TRIM(di.invoice_no)
INNER JOIN hed_shipping hs
    ON TRIM(hs.shipping_no) = TRIM(di.shipping_no)
INNER JOIN det_shipping ds
    ON TRIM(ds.shipping_no) = TRIM(di.shipping_no)
LEFT JOIN m_inventory mi
    ON TRIM(mi.inventory_id) = TRIM(ds.inventory_id)
LEFT JOIN detail_sales_order dso
    ON dso.id = (
        SELECT MIN(dso2.id)
        FROM detail_sales_order dso2
        WHERE TRIM(dso2.order_no) = TRIM(hs.order_no)
          AND TRIM(dso2.inventory_id) = TRIM(ds.inventory_id)
    )
WHERE hi.invoice_date BETWEEN ? AND ?
  AND UPPER(COALESCE(di.invoice_no,'')) NOT LIKE '%CP-MCP%'
  AND UPPER(COALESCE(di.shipping_no,'')) NOT LIKE '%CP-MCP%'
  AND UPPER(COALESCE(hs.order_no,'')) NOT LIKE '%CP-MCP%'
ORDER BY di.invoice_no, di.shipping_no, ds.id
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die('SQL Register Penjualan Perincian Error: ' . h(mysqli_error($conn)));
}
mysqli_stmt_bind_param($stmt, 'ss', $startDate, $endDate);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$shippingGroups = [];

while ($row = mysqli_fetch_assoc($res)) {
    $key = trim((string)$row['invoice_no']) . '|' . trim((string)$row['shipping_no']);

    if (!isset($shippingGroups[$key])) {
        $shippingGroups[$key] = [
            'invoice_subtotal' => (float)$row['invoice_subtotal'],
            'items' => []
        ];
    }

    $cat = classifyCategoryR($row['inventory_name']);
    if ($cat === null || !isset($summary[$cat])) continue;

    $shippingGroups[$key]['items'][] = [
        'category' => $cat,
        'qty' => (float)$row['row_qty'],
        'basis' => (float)$row['allocation_basis']
    ];
}

mysqli_stmt_close($stmt);

foreach ($shippingGroups as $ship) {
    if (empty($ship['items'])) continue;

    $invoiceSubtotal = (float)$ship['invoice_subtotal'];

    foreach ($ship['items'] as $item) {
        $summary[$item['category']]['qty'] += $item['qty'];
    }

    $categoryBasis = [];
    $totalBasis = 0.0;

    foreach ($ship['items'] as $item) {
        if (!isset($categoryBasis[$item['category']])) $categoryBasis[$item['category']] = 0.0;
        $categoryBasis[$item['category']] += abs($item['basis']);
        $totalBasis += abs($item['basis']);
    }

    $catsInShipping = array_keys($categoryBasis);

    if (count($catsInShipping) === 1) {
        $summary[$catsInShipping[0]]['rp'] += $invoiceSubtotal;
    } elseif ($totalBasis > 0) {
        $allocated = 0.0;
        $last = count($catsInShipping) - 1;

        foreach ($catsInShipping as $i => $cat) {
            if ($i === $last) {
                $amount = $invoiceSubtotal - $allocated;
            } else {
                $amount = $invoiceSubtotal * ($categoryBasis[$cat] / $totalBasis);
                $allocated += $amount;
            }
            $summary[$cat]['rp'] += $amount;
        }
    } else {
        $summary[$catsInShipping[0]]['rp'] += $invoiceSubtotal;
    }
}

/*
 * RETUR pada periode yang sama.
 * Termasuk retur CP-MCP karena sumber resminya memang berada di
 * head_retur_invoice/detail_retur_invoice.
 * Hanya status Cancelled yang tidak dihitung.
 */
$returnSummary = [];

$sqlReturn = "
SELECT
    hri.return_id,
    dri.inventory_name,
    dri.return_quantity,
    dri.uom,
    dri.return_quantity_pack,
    dri.uom_pack,
    dri.return_quantity_detail,
    dri.uom_detail,
    dri.return_subtotal
FROM head_retur_invoice hri
INNER JOIN detail_retur_invoice dri
    ON TRIM(dri.return_id) = TRIM(hri.return_id)
WHERE hri.return_date BETWEEN ? AND ?
  AND LOWER(COALESCE(hri.status,'Open')) <> 'cancelled'
ORDER BY hri.return_date, hri.return_id, dri.id
";

$stmtReturn = mysqli_prepare($conn, $sqlReturn);
if (!$stmtReturn) {
    die('SQL Retur Register Penjualan Perincian Error: ' . h(mysqli_error($conn)));
}
mysqli_stmt_bind_param($stmtReturn, 'ss', $startDate, $endDate);
mysqli_stmt_execute($stmtReturn);
$resReturn = mysqli_stmt_get_result($stmtReturn);

while ($ret = mysqli_fetch_assoc($resReturn)) {
    $cat = classifyCategoryR($ret['inventory_name']);
    if ($cat === null) continue;

    if (!isset($returnSummary[$cat])) {
        $returnSummary[$cat] = ['qty' => 0.0, 'rp' => 0.0];
    }

    if (trim((string)$ret['uom_pack']) !== '' && strtoupper(trim((string)$ret['uom_pack'])) !== 'KG') {
        $qty = (float)$ret['return_quantity_pack'];
    } elseif (trim((string)$ret['uom_detail']) !== '' && strtoupper(trim((string)$ret['uom_detail'])) !== 'KG') {
        $qty = (float)$ret['return_quantity_detail'];
    } else {
        $qty = (float)$ret['return_quantity'];
    }

    $returnSummary[$cat]['qty'] += $qty;
    $returnSummary[$cat]['rp'] += (float)$ret['return_subtotal'];
}

mysqli_stmt_close($stmtReturn);

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

/*
 * Baris LAIN LAIN pada tabel Perincian merupakan ringkasan dari seluruh
 * tabel Perincian Lain Lain, bukan kategori baru.
 */
$lainLainQty = $detailQtyTotal;
$lainLainRp  = $detailRpTotal;

/*
 * Jumlah Perincian utama = PP + KERTAS + PE + PE WARNA + LAIN LAIN.
 */
$mainWithLainQtyTotal = $mainQtyTotal + $lainLainQty;
$mainWithLainRpTotal  = $mainRpTotal + $lainLainRp;

$returnQtyTotal = 0.0;
$returnRpTotal  = 0.0;
foreach ($returnSummary as $ret) {
    $returnQtyTotal += (float)$ret['qty'];
    $returnRpTotal  += (float)$ret['rp'];
}

$grandQty = $mainWithLainQtyTotal - $returnQtyTotal;
$grandRp  = $mainWithLainRpTotal - $returnRpTotal;


/*
 * Pengelompokan Perincian Lain Lain untuk kolom "Jumlah Rp".
 * Baris kategori tetap ditampilkan satu per satu, sedangkan Jumlah Rp
 * ditampilkan pada baris subtotal tiap kelompok.
 */
$detailGroups = [
    [
        'label' => 'JUMLAH HD',
        'cats'  => ['HD', 'HD WARNA', 'HD KRESEK']
    ],
    [
        'label' => 'JUMLAH TALI',
        'cats'  => ['TALI KG', 'TALI LOS']
    ],
    [
        'label' => 'JUMLAH BAHAN / SABLON',
        'cats'  => ['BAHAN', 'HD SABLON']
    ],
    [
        'label' => 'JUMLAH TERPAL',
        'cats'  => ['TERPAL']
    ],
    [
        'label' => 'JUMLAH BOX',
        'cats'  => ['BOX']
    ],
    [
        'label' => 'JUMLAH SEDOTAN',
        'cats'  => ['SEDOTAN']
    ],
];

$detailGroupedTotal = 0.0;
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
    width: 42%;
}

.col-quantum {
    width: 18%;
}

.col-total {
    width: 20%;
}

.col-jumlah {
    width: 20%;
}

.text-right {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.total-row td {
    font-weight: 700;
    background: #f5f5f5;
}

.return-label-row td {
    font-weight: 700;
    background: #f5f5f5;
}

.return-item td:first-child {
    padding-left: 20px;
}

.group-subtotal td {
    font-weight: 700;
    background: #f7f7f7;
}

.grand-perincian td {
    font-weight: 700;
    background: #ececec;
    border-top: 2px solid #333;
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
                <th class="col-jumlah">Jumlah Rp</th>
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
                    <td></td>
                </tr>
            <?php endforeach; ?>

            <!-- LAIN LAIN = ringkasan seluruh isi tabel Perincian Lain Lain -->
            <tr>
                <td>LAIN LAIN</td>
                <td class="text-right">
                    <?= h(fmtQtyR($lainLainQty)) ?>
                </td>
                <td class="text-right">
                    Rp <?= h(fmtMoneyR($lainLainRp)) ?>
                </td>
                <td></td>
            </tr>

            <!-- Subtotal seluruh PP + KERTAS + PE + PE WARNA + LAIN LAIN -->
            <tr class="group-subtotal">
                <td style="text-align:right;">JUMLAH PERINCIAN</td>
                <td class="text-right"><?= h(fmtQtyR($mainWithLainQtyTotal)) ?></td>
                <td class="text-right">Rp <?= h(fmtMoneyR($mainWithLainRpTotal)) ?></td>
                <td class="text-right">Rp <?= h(fmtMoneyR($mainWithLainRpTotal)) ?></td>
            </tr>

            <?php if (!empty($returnSummary)): ?>
                <tr class="return-label-row">
                    <td><strong>RETUR :</strong></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>

                <?php foreach ($returnSummary as $retCat => $retData): ?>
                    <?php if (abs((float)$retData['qty']) > 0.000001): ?>
                        <tr class="return-item">
                            <td><?= h($retCat) ?></td>
                            <td class="text-right">
                                -<?= h(fmtQtyR(abs($retData['qty']))) ?>
                            </td>
                            <td class="text-right">
                                - Rp <?= h(fmtMoneyR(abs($retData['rp']))) ?>
                            </td>
                            <td></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>

                <tr class="group-subtotal">
                    <td style="text-align:right;">JUMLAH RETUR</td>
                    <td class="text-right">
                        -<?= h(fmtQtyR(abs($returnQtyTotal))) ?>
                    </td>
                    <td class="text-right">
                        - Rp <?= h(fmtMoneyR(abs($returnRpTotal))) ?>
                    </td>
                    <td class="text-right">
                        - Rp <?= h(fmtMoneyR(abs($returnRpTotal))) ?>
                    </td>
                </tr>
            <?php endif; ?>

            <!-- Grand Total Perincian = Penjualan + Retur (retur sebagai minus) -->
            <tr class="grand-perincian">
                <td style="text-align:right;">GRAND TOTAL PERINCIAN</td>
                <td class="text-right">
                    <?= h(fmtQtyR($mainWithLainQtyTotal - $returnQtyTotal)) ?>
                </td>
                <td></td>
                <td class="text-right">
                    Rp <?= h(fmtMoneyR($mainWithLainRpTotal - $returnRpTotal)) ?>
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
                <th class="col-jumlah">Jumlah Rp</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($detailGroups as $group): ?>
                <?php
                    $groupQty = 0.0;
                    $groupRp  = 0.0;

                    foreach ($group['cats'] as $cat) {
                        $groupQty += (float)$summary[$cat]['qty'];
                        $groupRp  += (float)$summary[$cat]['rp'];
                    }

                    $detailGroupedTotal += $groupRp;
                ?>

                <?php foreach ($group['cats'] as $cat): ?>
                    <tr>
                        <td><?= h($cat) ?></td>
                        <td class="text-right">
                            <?= h(fmtQtyR($summary[$cat]['qty'])) ?>
                        </td>
                        <td class="text-right">
                            Rp <?= h(fmtMoneyR($summary[$cat]['rp'])) ?>
                        </td>
                        <td></td>
                    </tr>
                <?php endforeach; ?>

                <tr class="group-subtotal">
                    <td style="text-align:right;"><?= h($group['label']) ?></td>
                    <td class="text-right"><?= h(fmtQtyR($groupQty)) ?></td>
                    <td class="text-right">Rp <?= h(fmtMoneyR($groupRp)) ?></td>
                    <td class="text-right">Rp <?= h(fmtMoneyR($groupRp)) ?></td>
                </tr>
            <?php endforeach; ?>

            <tr class="grand-perincian">
                <td style="text-align:right;">TOTAL PERINCIAN LAIN LAIN</td>
                <td class="text-right"><?= h(fmtQtyR($detailQtyTotal)) ?></td>
                <td></td>
                <td class="text-right">Rp <?= h(fmtMoneyR($detailRpTotal)) ?></td>
            </tr>
        </tbody>
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