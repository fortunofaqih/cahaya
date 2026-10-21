<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

function h($v){
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function parseDateR($v, $fallback){
    $v = trim((string)$v);
    if ($v === '') return $fallback;

    foreach (['d-M-Y','Y-m-d','d-m-Y','d/m/Y'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d instanceof DateTime) return $d->format('Y-m-d');
    }

    return $fallback;
}

function fmtDateR($d){
    if (empty($d) || $d === '0000-00-00') return '';
    $t = strtotime($d);
    return $t ? date('d-M-Y', $t) : '';
}

function fmtMoneyR($v){
    // Buang pecahan desimal tanpa pembulatan.
    // Contoh: 400.412.886,50 -> 400.412.886,00
    $v=(float)$v;
    $whole=$v<0 ? ceil($v) : floor($v);
    return number_format($whole,2,',','.');
}

function fmtQtyR($v){
    $s = number_format((float)$v, 2, ',', '.');
    return rtrim(rtrim($s, '0'), ',');
}

function classifyCategoryDetailR($inventoryName, $inventoryCategory=''){
    $n = strtoupper(trim((string)$inventoryName));
    $c = strtoupper(trim((string)$inventoryCategory));

    /*
     * KHUSUS KOLOM BAHAN
     * Prioritas dari category master inventory
     */
    if (
        $c === 'BIJI PLASTIK' ||
        $c === 'BAHAN PACKING'
    ) {
        return 'BAHAN';
    }

    /*
     * Fallback berdasarkan inventory_name.
     * Contoh:
     * LLDPE RESIN TITANVENE LL0209 SC
     */
    if (
        strpos($n, 'RESIN') !== false ||
        strpos($n, 'LLDPE') !== false
    ) {
        return 'BAHAN';
    }

    if($n==='') return null;

    // =============================
    // RULE EXISTING JANGAN DIUBAH
    // =============================

    if(strpos($n,'SEDOTAN')!==false) return 'SEDOTAN';

    if(preg_match('/(^|[^A-Z])PP([^A-Z]|$)/',$n) && strpos($n,'SABLON')!==false) return 'PP SABLON';

    if(preg_match('/(^|[^A-Z])HD([^A-Z]|$)/',$n) && strpos($n,'WARNA')!==false) return 'HD WARNA';
    if(preg_match('/(^|[^A-Z])HD([^A-Z]|$)/',$n) && strpos($n,'KRESEK')!==false) return 'HD KRESEK';
    if(preg_match('/(^|[^A-Z])HD([^A-Z]|$)/',$n) && strpos($n,'SABLON')!==false) return 'HD SABLON';

    if(strpos($n,'TALI')!==false && preg_match('/(^|[^A-Z])KG([^A-Z]|$)/',$n)) return 'TALI KG';
    if(strpos($n,'TALI')!==false && strpos($n,'LOS')!==false) return 'TALI LOS';

    if(preg_match('/(^|[^A-Z])BAHAN([^A-Z]|$)/',$n)) return 'BAHAN';

    if(strpos($n,'TERPAL')!==false) return 'TERPAL';
    if(preg_match('/(^|[^A-Z])BOX([^A-Z]|$)/',$n)) return 'BOX';
    if(preg_match('/(^|[^A-Z])HD([^A-Z]|$)/',$n)) return 'HD';

    if(strpos($n,'KERTAS')!==false) return null;

    if(
        preg_match('/(^|[^A-Z])PE([^A-Z]|$)/',$n) &&
        strpos($n,'WARNA')!==false
    ) return null;

    if(preg_match('/(^|[^A-Z])PE([^A-Z]|$)/',$n)) return null;
    if(preg_match('/(^|[^A-Z])PP([^A-Z]|$)/',$n)) return null;

    return 'LAIN-LAIN';
}

function emptyCatR(){
    return ['qty'=>0.0, 'qty_kg'=>0.0, 'rp'=>0.0];
}

function emptyGrandR(){
    return [
        'total'=>0.0,
        'penjualan'=>0.0,
        'HD'=>emptyCatR(),
        'HD WARNA'=>emptyCatR(),
        'HD KRESEK'=>emptyCatR(),
        'HD SABLON'=>emptyCatR(),
        'PP SABLON'=>emptyCatR(),
        'TALI KG'=>emptyCatR(),
        'TALI LOS'=>emptyCatR(),
        'BAHAN'=>emptyCatR(),
        'TERPAL'=>emptyCatR(),
        'BOX'=>emptyCatR(),
        'SEDOTAN'=>emptyCatR(),
        'LAIN-LAIN'=>emptyCatR()
    ];
}

$today = date('Y-m-d');
$startDate = parseDateR($_GET['start_date'] ?? '', date('Y-m-01'));
$endDate   = parseDateR($_GET['end_date'] ?? '', $today);

if (strtotime($startDate) > strtotime($endDate)) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$sort = strtolower(trim((string)($_GET['sort'] ?? 'shipping_no')));
$dir  = strtolower(trim((string)($_GET['dir'] ?? 'asc')));

$allowedSort = ['shipping_no', 'invoice_no'];
$allowedDir  = ['asc', 'desc'];

if (!in_array($sort, $allowedSort, true)) $sort = 'shipping_no';
if (!in_array($dir, $allowedDir, true)) $dir = 'asc';

$orderColumn = $sort === 'invoice_no' ? 'di.invoice_no' : 'di.shipping_no';
$orderDirection = strtoupper($dir);

/*
 * Register Penjualan Global Detail berbasis INVOICE:
 * - TOTAL       = det_invoice.total
 * - PENJUALAN   = det_invoice.subtotal
 * - Qty         = det_shipping
 * - Qty KG      = invoice_qty_current jika dikoreksi, selain itu det_shipping
 * - Rp kategori = dialokasikan dari det_invoice.subtotal
 *
 * Jika satu shipping hanya memiliki satu kategori detail, seluruh subtotal
 * invoice masuk ke kategori tersebut. Jika multi kategori, subtotal invoice
 * dibagi proporsional memakai nilai referensi detail barang, sehingga jumlah
 * seluruh Rp kategori tetap sama dengan subtotal invoice.
 */
$sql = "
SELECT
    di.invoice_no,
    di.shipping_no,
    hi.invoice_date,
    hi.customer_name,
    COALESCE(di.total,0) AS total_invoice_shipping,
    COALESCE(di.subtotal,0) AS penjualan_shipping,
    ds.id AS shipping_detail_id,
    COALESCE(
        NULLIF(TRIM(ds.inventory_name),''),
        NULLIF(TRIM(mi.inventory_name),''),
        ''
    ) AS inventory_name,
    COALESCE(NULLIF(TRIM(mi.category),''),'') AS inventory_category,

    CASE
        WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,'')))<>'' 
         AND UPPER(TRIM(COALESCE(ds.uom_pack_shipping,'')))<>'KG'
            THEN COALESCE(ds.qty_pack_shipping,0)
        WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,'')))<>'' 
         AND UPPER(TRIM(COALESCE(ds.uom_detail_shipping,'')))<>'KG'
            THEN COALESCE(ds.qty_detail_shipping,0)
        ELSE COALESCE(ds.qty_shipping,0)
    END AS category_qty,

    CASE
        WHEN UPPER(TRIM(COALESCE(ds.uom_shipping,'')))='KG'
            THEN COALESCE(qic.qty_invoice, ds.qty_shipping, 0)
        WHEN UPPER(TRIM(COALESCE(ds.uom_pack_shipping,'')))='KG'
            THEN COALESCE(ds.qty_pack_shipping,0)
        WHEN UPPER(TRIM(COALESCE(ds.uom_detail_shipping,'')))='KG'
            THEN COALESCE(ds.qty_detail_shipping,0)
        ELSE 0
    END AS category_qty_kg,

    CASE
        WHEN COALESCE(ds.subtotal,0)<>0
            THEN ABS(COALESCE(ds.subtotal,0))

        /* LAIN-LAIN tanpa qty tetap punya basis Rupiah dari nilai tagihan. */
        WHEN COALESCE(ds.qty_shipping,0)=0
         AND COALESCE(ds.qty_pack_shipping,0)=0
         AND COALESCE(ds.qty_detail_shipping,0)=0
            THEN ABS(
                CASE
                    WHEN COALESCE(dso.price_unit,0)<>0 THEN COALESCE(dso.price_unit,0)
                    WHEN COALESCE(dso.price,0)<>0 THEN COALESCE(dso.price,0)
                    ELSE 0
                END
            )

        WHEN COALESCE(dso.price,0)<>0
            THEN ABS(
                COALESCE(dso.price,0) *
                CASE
                    WHEN COALESCE(ds.qty_pack_shipping,0)>0
                        THEN COALESCE(ds.qty_pack_shipping,0)
                    ELSE COALESCE(ds.qty_shipping,0)
                END
            )
        ELSE 0
    END AS allocation_basis

FROM det_invoice di
INNER JOIN head_invoice hi
    ON TRIM(hi.invoice_no)=TRIM(di.invoice_no)
INNER JOIN hed_shipping hs
    ON TRIM(hs.shipping_no)=TRIM(di.shipping_no)
INNER JOIN det_shipping ds
    ON TRIM(ds.shipping_no)=TRIM(di.shipping_no)
/* Koreksi Qty Invoice hanya untuk Qty KG; tidak memengaruhi Qty Pack atau nominal. */
LEFT JOIN invoice_qty_current qic
    ON qic.invoice_no = di.invoice_no
   AND qic.shipping_detail_id = ds.id
LEFT JOIN m_inventory mi
    ON TRIM(mi.inventory_id)=TRIM(ds.inventory_id)
LEFT JOIN detail_sales_order dso
    ON dso.id=(
        SELECT MIN(dso2.id)
        FROM detail_sales_order dso2
        WHERE TRIM(dso2.order_no)=TRIM(hs.order_no)
          AND TRIM(dso2.inventory_id)=TRIM(ds.inventory_id)
    )

WHERE hi.invoice_date BETWEEN ? AND ?
  AND UPPER(COALESCE(di.invoice_no,'')) NOT LIKE '%CP-MCP%'
  AND UPPER(COALESCE(di.shipping_no,'')) NOT LIKE '%CP-MCP%'
  AND UPPER(COALESCE(hs.order_no,'')) NOT LIKE '%CP-MCP%'

ORDER BY
    {$orderColumn} {$orderDirection},
    hi.invoice_date ASC,
    di.shipping_no ASC,
    di.invoice_no ASC,
    ds.id ASC
";

$stmt=mysqli_prepare($conn,$sql);
if(!$stmt) die('SQL Register Penjualan Global Detail Error: '.h(mysqli_error($conn)));
mysqli_stmt_bind_param($stmt,'ss',$startDate,$endDate);
mysqli_stmt_execute($stmt);
$res=mysqli_stmt_get_result($stmt);

$cats=['HD','HD WARNA','HD KRESEK','HD SABLON','PP SABLON','TALI KG','TALI LOS','BAHAN','TERPAL','BOX','SEDOTAN','LAIN-LAIN'];
$rows=[];
$grand=emptyGrandR();
$shippingGroups=[];

while($item=mysqli_fetch_assoc($res)){
    $g=classifyCategoryDetailR($item['inventory_name'], $item['inventory_category']);

    // Global Detail hanya menampilkan kategori yang termasuk daftar detail.
    if($g===null || !in_array($g,$cats,true)){
        continue;
    }

    $key=trim((string)$item['invoice_no']).'|'.trim((string)$item['shipping_no']);

    if(!isset($shippingGroups[$key])){
        $shippingGroups[$key]=[
            'shipping_no'=>$item['shipping_no'],
            'invoice_no'=>$item['invoice_no'],
            'customer_name'=>$item['customer_name'],
            'total'=>(float)$item['total_invoice_shipping'],
            'penjualan'=>(float)$item['penjualan_shipping'],
            'items'=>[]
        ];
    }

    $shippingGroups[$key]['items'][]=[
        'category'=>$g,
        // LAIN-LAIN hanya menampilkan nilai Rupiah. Qty dan Qty KG wajib 0.
        'qty'=>$g==='LAIN-LAIN' ? 0.0 : (float)$item['category_qty'],
        'qty_kg'=>$g==='LAIN-LAIN' ? 0.0 : (float)$item['category_qty_kg'],
        'basis'=>(float)$item['allocation_basis']
    ];
}

mysqli_stmt_close($stmt);

foreach($shippingGroups as $key=>$ship){
    $rows[$key]=[
        'shipping_no'=>$ship['shipping_no'],
        'invoice_no'=>$ship['invoice_no'],
        'customer_name'=>$ship['customer_name'],
        'total'=>$ship['total'],
        'penjualan'=>$ship['penjualan']
    ];

    foreach($cats as $cat){
        $rows[$key][$cat]=emptyCatR();
    }

    $grand['total'] += $ship['total'];
    $grand['penjualan'] += $ship['penjualan'];

    $basisByCat=[];
    $totalBasis=0.0;

    foreach($ship['items'] as $it){
        $g=$it['category'];

        $rows[$key][$g]['qty'] += $it['qty'];
        $rows[$key][$g]['qty_kg'] += $it['qty_kg'];

        if(!isset($basisByCat[$g])) $basisByCat[$g]=0.0;
        $basisByCat[$g] += abs($it['basis']);
        $totalBasis += abs($it['basis']);
    }

    $catsInShipping=array_keys($basisByCat);

    if(count($catsInShipping)===1){
        $rows[$key][$catsInShipping[0]]['rp'] += $ship['penjualan'];
    } elseif($totalBasis>0){
        $allocated=0.0;
        $last=count($catsInShipping)-1;

        foreach($catsInShipping as $i=>$g){
            if($i===$last){
                $amount=$ship['penjualan']-$allocated;
            }else{
                $amount=$ship['penjualan']*($basisByCat[$g]/$totalBasis);
                $allocated += $amount;
            }

            $rows[$key][$g]['rp'] += $amount;
        }
    } elseif(!empty($catsInShipping)){
        // Safety fallback bila semua harga referensi 0.
        $rows[$key][$catsInShipping[0]]['rp'] += $ship['penjualan'];
    }

    foreach($cats as $g){
        $grand[$g]['qty'] += $rows[$key][$g]['qty'];
        $grand[$g]['qty_kg'] += $rows[$key][$g]['qty_kg'];
        $grand[$g]['rp'] += $rows[$key][$g]['rp'];
    }
}

/*
 * KOREKSI INVOICE
 * ------------------------------------------------------------
 * Source of truth: invoice_correction.
 *
 * Rule:
 * - correction_amount sudah signed NEGATIF;
 * - category menentukan kolom Rp pada Global Detail;
 * - Qty dan Qty KG selalu 0 karena koreksi adalah mutasi nilai, bukan barang;
 * - invoice_no / shipping_no memakai referensi koreksi bila diisi;
 * - customer_name diambil dari m_customer;
 * - hanya status Active dan correction_date dalam periode filter;
 * - ONGKOS dipetakan ke LAIN-LAIN karena struktur Global Detail existing
 *   tidak mempunyai kolom ONGKOS tersendiri.
 *
 * Kategori PP/KERTAS/PE/PE WARNA memang tidak menjadi kolom pada Global Detail
 * existing, sehingga koreksi kategori tersebut tidak dimasukkan di laporan ini.
 */
$sqlCorrection = "
    SELECT
        ic.correction_id,
        ic.correction_no,
        ic.correction_date,
        ic.customer_id,
        COALESCE(mc.customer, ic.customer_id) AS customer_name,
        COALESCE(ic.invoice_no,'') AS invoice_no,
        COALESCE(ic.shipping_no,'') AS shipping_no,
        UPPER(TRIM(COALESCE(ic.category,''))) AS category,
        COALESCE(ic.correction_amount,0) AS correction_amount
    FROM invoice_correction ic
    LEFT JOIN m_customer mc
        ON mc.customer_id COLLATE utf8mb4_general_ci
         = ic.customer_id COLLATE utf8mb4_general_ci
    WHERE ic.correction_date BETWEEN ? AND ?
      AND LOWER(COALESCE(ic.status,'Active'))='active'
";

if ($sort === 'invoice_no') {
    $sqlCorrection .= " ORDER BY ic.invoice_no " . $orderDirection . ", ic.correction_date ASC, ic.correction_id ASC ";
} else {
    $sqlCorrection .= " ORDER BY ic.shipping_no " . $orderDirection . ", ic.correction_date ASC, ic.correction_id ASC ";
}

$stmtCorrection = mysqli_prepare($conn, $sqlCorrection);
if (!$stmtCorrection) {
    die('SQL Koreksi Invoice Register Penjualan Global Detail Error: '.h(mysqli_error($conn)));
}
mysqli_stmt_bind_param($stmtCorrection,'ss',$startDate,$endDate);
mysqli_stmt_execute($stmtCorrection);
$resCorrection = mysqli_stmt_get_result($stmtCorrection);

while($corr = mysqli_fetch_assoc($resCorrection)){
    $g = strtoupper(trim((string)($corr['category'] ?? '')));

    if($g === 'ONGKOS'){
        $g = 'LAIN-LAIN';
    }

    if($g === '' || !in_array($g,$cats,true)){
        continue;
    }

    $amount = (float)($corr['correction_amount'] ?? 0);

    /*
     * Key khusus supaya tidak bertabrakan dengan invoice/shipping normal
     * maupun koreksi lain yang referensinya sama/kosong.
     */
    $key = 'CORRECTION|' . (int)$corr['correction_id'];

    $rows[$key] = [
        'shipping_no' => (string)($corr['shipping_no'] ?? ''),
        'invoice_no' => (string)($corr['invoice_no'] ?? ''),
        'customer_name' => (string)($corr['customer_name'] ?? ''),
        'total' => $amount,
        'penjualan' => $amount
    ];

    foreach($cats as $cat){
        $rows[$key][$cat] = emptyCatR();
    }

    $rows[$key][$g]['rp'] = $amount;

    $grand['total'] += $amount;
    $grand['penjualan'] += $amount;
    $grand[$g]['rp'] += $amount;
}

mysqli_stmt_close($stmtCorrection);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Register Penjualan Global Detail</title>

<style>
@page{size:14.875in 11in;margin:6mm}

* {
    box-sizing: border-box;
    margin: 2mm;
    padding: 2mm;
}

body {
    margin: 0;
    font-family: Arial, Helvetica, sans-serif;
    color: #000;
    background: #f3f5f7;
    font-size: 10.5px;
    padding: 18px 28px;
}

.print-actions {
    margin: 10px;
    text-align: right;
}

.print-btn {
    border: 0;
    border-radius: 3px;
    background: #1e3c72;
    color: #fff;
    padding: 7px 14px;
    font-size: 12px;
    font-weight: bold;
    cursor: pointer;
}

.register-page {
    width: 100%;
    max-width: 1920px;
    margin: 0 auto 22px;
    padding: 16px 18px;
    background: #fff;
    border: 1px solid #d9dee5;
    border-radius: 6px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
}

.register-page:not(:last-child) {
    page-break-after: always;
    break-after: page;
}

.print-header {
    text-align: center;
    margin-bottom: 8px;
}

.print-header h2 {
    margin: 0 0 3px;
    font-size: 15px;
    text-transform: uppercase;
}

.print-header .register-title {
    margin: 2px 0 2px;
    font-size: 11px;
    font-weight: bold;
}

.print-header .period {
    font-size: 10px;
    font-weight: bold;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 10px;
}

.report-table th,
.report-table td {
    border: 0.6px solid #444;
    padding: 3px 2px;
    vertical-align: middle;
}

.report-table th {
    background: #ececec;
    font-weight: bold;
    text-align: center;
    line-height: 1.15;
}

.report-table td {
    white-space: nowrap;
}

.shipping-col { width: 74px; }
.invoice-col  { width: 98px; }
.customer-col { width: 128px; }
.total-col    { width: 72px; }

.customer-cell {
    white-space: normal !important;
    overflow-wrap: anywhere;
    line-height: 1.15;
}

.qty-cell,
.money-cell {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.category-head {
    font-size: 10px;
}

.sub-head {
    font-size: 10px;
}

tfoot td {
    font-weight: bold;
    background: #f2f2f2;
}

.no-data {
    text-align: center;
    padding: 14px !important;
    font-size: 10px;
}

@media print {
    .print-actions {
        display: none !important;
    }

    body {
        padding: 0 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .register-page {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
    }

    .register-page:not(:last-child) {
        page-break-after: always !important;
        break-after: page !important;
    }

    thead {
        display: table-header-group;
    }

    tfoot {
        display: table-row-group;
    }

    tr {
        page-break-inside: avoid;
        break-inside: avoid;
    }
}
</style>
</head>

<body>

<div class="print-actions">
    <button type="button" class="print-btn" onclick="window.print()">Print</button>
</div>

<?php
$registerGroups = [
    [
        'title' => 'REGISTER 1 - HD / HD WARNA / HD KRESEK',
        'cats' => ['HD', 'HD WARNA', 'HD KRESEK'],
    ],
    [
        'title' => 'REGISTER 2 - HD SABLON / PP SABLON',
        'cats' => ['HD SABLON', 'PP SABLON'],
    ],
    [
        'title' => 'REGISTER 3 - TALI / BAHAN',
        'cats' => ['TALI KG', 'TALI LOS', 'BAHAN'],
    ],
    [
        'title' => 'REGISTER 4 - TERPAL / BOX / SEDOTAN / LAIN-LAIN',
        'cats' => ['TERPAL', 'BOX', 'SEDOTAN', 'LAIN-LAIN'],
    ],
];

function rowHasRegisterData(array $row, array $groupCats): bool {
    foreach ($groupCats as $cat) {
        if (
            abs((float)($row[$cat]['qty'] ?? 0)) > 0.0001 ||
            abs((float)($row[$cat]['qty_kg'] ?? 0)) > 0.0001 ||
            abs((float)($row[$cat]['rp'] ?? 0)) > 0.0001
        ) {
            return true;
        }
    }
    return false;
}

function registerTotals(array $rows, array $groupCats): array {
    $totals = [
        'total' => 0.0,
        'penjualan' => 0.0,
    ];

    foreach ($groupCats as $cat) {
        $totals[$cat] = emptyCatR();
    }

    foreach ($rows as $row) {
        if (!rowHasRegisterData($row, $groupCats)) {
            continue;
        }

        $totals['total'] += (float)($row['total'] ?? 0);
        $totals['penjualan'] += (float)($row['penjualan'] ?? 0);

        foreach ($groupCats as $cat) {
            $totals[$cat]['qty'] += (float)($row[$cat]['qty'] ?? 0);
            $totals[$cat]['qty_kg'] += (float)($row[$cat]['qty_kg'] ?? 0);
            $totals[$cat]['rp'] += (float)($row[$cat]['rp'] ?? 0);
        }
    }

    return $totals;
}
?>

<?php foreach ($registerGroups as $register): ?>
    <?php
        $groupCats = $register['cats'];
        $groupRows = [];

        foreach ($rows as $row) {
            if (rowHasRegisterData($row, $groupCats)) {
                $groupRows[] = $row;
            }
        }

        $groupTotals = registerTotals($rows, $groupCats);
        $colspan = 5 + (count($groupCats) * 3);
    ?>

    <section class="register-page">
        <div class="print-header">
            <h2>Register Penjualan Global Detail</h2>
            <div class="register-title">
                <?= h($register['title']) ?>
            </div>
            <div class="period">
                Periode <?= h(fmtDateR($startDate)) ?> s/d <?= h(fmtDateR($endDate)) ?>
            </div>
        </div>

        <table class="report-table">
            <thead>
                <tr>
                    <th rowspan="2" class="shipping-col">SHIPPING NO.</th>
                    <th rowspan="2" class="invoice-col">INVOICE NO.</th>
                    <th rowspan="2" class="customer-col">NAMA CUST.</th>
                    <th rowspan="2" class="total-col">TOTAL</th>
                    <th rowspan="2" class="total-col">PENJUALAN</th>

                    <?php foreach ($groupCats as $cat): ?>
                        <th colspan="3" class="category-head"><?= h($cat) ?></th>
                    <?php endforeach; ?>
                </tr>

                <tr>
                    <?php foreach ($groupCats as $cat): ?>
                        <th class="sub-head">Qty</th>
                        <th class="sub-head">Qty KG</th>
                        <th class="sub-head">Rp</th>
                    <?php endforeach; ?>
                </tr>
            </thead>

            <tbody>
            <?php if (empty($groupRows)): ?>
                <tr>
                    <td colspan="<?= (int)$colspan ?>" class="no-data">
                        Tidak ada data untuk kelompok kategori ini pada periode yang dipilih.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($groupRows as $row): ?>
                    <tr>
                        <td><?= h($row['shipping_no']) ?></td>
                        <td><?= h($row['invoice_no']) ?></td>
                        <td class="customer-cell"><?= h($row['customer_name']) ?></td>
                        <td class="money-cell"><?= h(fmtMoneyR($row['total'])) ?></td>
                        <td class="money-cell"><?= h(fmtMoneyR($row['penjualan'])) ?></td>

                        <?php foreach ($groupCats as $cat): ?>
                            <td class="qty-cell"><?= h(fmtQtyR($row[$cat]['qty'])) ?></td>
                            <td class="qty-cell"><?= h(fmtQtyR($row[$cat]['qty_kg'])) ?></td>
                            <td class="money-cell"><?= h(fmtMoneyR($row[$cat]['rp'])) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>

            <tfoot>
                <tr>
                    <td colspan="3" style="text-align:right;">TOTAL</td>
                    <td class="money-cell"><?= h(fmtMoneyR($groupTotals['total'])) ?></td>
                    <td class="money-cell"><?= h(fmtMoneyR($groupTotals['penjualan'])) ?></td>

                    <?php foreach ($groupCats as $cat): ?>
                        <td class="qty-cell"><?= h(fmtQtyR($groupTotals[$cat]['qty'])) ?></td>
                        <td class="qty-cell"><?= h(fmtQtyR($groupTotals[$cat]['qty_kg'])) ?></td>
                        <td class="money-cell"><?= h(fmtMoneyR($groupTotals[$cat]['rp'])) ?></td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
        </table>
    </section>
<?php endforeach; ?>

<script>
window.addEventListener('load', function () {
    window.print();
});
</script>

</body>
</html>
