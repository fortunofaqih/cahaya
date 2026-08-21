<?php
// modul/transaksi/ajax_kartu_stok_order_customer_detail.php
// Expand Kartu Stok Order Customer
//
// B  = BAL / Ball
// Ot = Other
// KG = Kilogram
//
// Alur:
// - Baris ORDER masuk ke kolom Roll / Ptg / Lain
// - Baris SHIPPING masuk ke kolom SJ
// - Saldo = Order - Shipping kumulatif

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Session login telah berakhir. Silakan login kembali.'
    ]);
    exit;
}

include __DIR__ . '/../../koneksi.php';

function e($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function fmtNum($value)
{
    $num = (float)($value ?? 0);

    // Jika bulat -> tidak tampil desimal
    if (abs($num - round($num)) < 0.00001) {
        return number_format($num, 0, '.', ',');
    }

    // Maksimal 2 decimal
    return rtrim(
        rtrim(
            number_format($num, 2, '.', ','),
            '0'
        ),
        '.'
    );
}

function fmtDate($date)
{
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }

    $ts = strtotime($date);

    return $ts ? date('d/m/Y', $ts) : '-';
}

/**
 * Tentukan grup inventory:
 * ROLL
 * PTG
 * LAIN
 */
function getInventoryGroup($internalName, $inventoryName, $category = '', $type = '')
{
    $text = strtoupper(
        trim(
            $internalName . ' ' .
            $inventoryName . ' ' .
            $category . ' ' .
            $type
        )
    );

    // ROLL / ROL
    if (
        strpos($text, 'ROLL') !== false ||
        strpos($text, ' ROL ') !== false
    ) {
        return 'ROLL';
    }

    // POTONG / PTG
    if (
        strpos($text, 'POTONG') !== false ||
        strpos($text, 'PTG') !== false
    ) {
        return 'PTG';
    }

    return 'LAIN';
}

/**
 * Kelompok UOM:
 * B   = BAL / BALL
 * KG  = KG
 * OT  = selain BAL dan KG
 */
function getUomGroup($uom)
{
    $uom = strtoupper(trim((string)$uom));

    if ($uom === '') {
        return '';
    }

    if (
        $uom === 'BAL' ||
        $uom === 'BALL' ||
        $uom === 'BALE'
    ) {
        return 'B';
    }

    if (
        $uom === 'KG' ||
        $uom === 'KGS' ||
        $uom === 'KILOGRAM'
    ) {
        return 'KG';
    }

    return 'OT';
}

/**
 * Tambahkan qty berdasarkan UOM
 */
function addQtyByUom(&$bucket, $qty, $uom)
{
    $qty = (float)($qty ?? 0);

    if (abs($qty) < 0.00001) {
        return;
    }

    $group = getUomGroup($uom);

    if ($group === 'B') {
        $bucket['b'] += $qty;
    } elseif ($group === 'KG') {
        $bucket['kg'] += $qty;
    } elseif ($group === 'OT') {
        $bucket['ot'] += $qty;
    }
}

/**
 * Isi quantity, quantity_pack, quantity_detail
 * sekaligus ke bucket B / Ot / KG.
 */
function addThreeUom(
    &$bucket,
    $qty,
    $uom,
    $qtyPack,
    $uomPack,
    $qtyDetail,
    $uomDetail
) {
    addQtyByUom($bucket, $qty, $uom);
    addQtyByUom($bucket, $qtyPack, $uomPack);
    addQtyByUom($bucket, $qtyDetail, $uomDetail);
}

function newBucket()
{
    return [
        'b'  => 0.0,
        'ot' => 0.0,
        'kg' => 0.0
    ];
}

function displayQty($value)
{
    $value = (float)$value;

    if (abs($value) < 0.00001) {
        return '';
    }

    return fmtNum($value);
}


// ============================================================
// PARAMETER
// ============================================================

$orderNo = trim((string)($_GET['order_no'] ?? ''));
$inventoryId = trim((string)($_GET['inventory_id'] ?? ''));

if ($orderNo === '' || $inventoryId === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Order No atau Inventory ID tidak lengkap.'
    ]);
    exit;
}


// ============================================================
// DATA SALES ORDER
// ============================================================
//
// Digroup supaya jika inventory yang sama kebetulan ada lebih dari
// satu detail pada SO, quantity tetap dijumlahkan.
//

$sqlSo = "
    SELECT
        h.order_no,
        h.order_date,
        h.po,
        h.customer_id,

        COALESCE(
            NULLIF(mc.customer, ''),
            h.customer_name
        ) AS customer_name,

        COALESCE(
            NULLIF(mm.marketing_name, ''),
            NULLIF(h.marketing_id, ''),
            NULLIF(h.sales_id, ''),
            '-'
        ) AS marketing_name,

        d.inventory_id,

        MAX(d.inventory_name) AS inventory_name,

        COALESCE(
            NULLIF(MAX(mi.internal_name), ''),
            MAX(d.inventory_name)
        ) AS internal_name,

        MAX(mi.category) AS category,
        MAX(mi.type) AS inventory_type,

        SUM(COALESCE(d.quantity, 0)) AS quantity,
        MAX(d.uom) AS uom,

        SUM(COALESCE(d.quantity_pack, 0)) AS quantity_pack,
        MAX(d.uom_pack) AS uom_pack,

        SUM(COALESCE(d.quantity_detail, 0)) AS quantity_detail,
        MAX(d.uom_detail) AS uom_detail

    FROM head_sales_order h

    INNER JOIN detail_sales_order d
        ON d.order_no = h.order_no

    LEFT JOIN m_inventory mi
        ON mi.inventory_id = d.inventory_id

    LEFT JOIN m_customer mc
        ON mc.customer_id = h.customer_id

    LEFT JOIN m_marketing mm
        ON mm.marketing_id = COALESCE(
            NULLIF(h.marketing_id, ''),
            h.sales_id
        )

    WHERE h.order_no = ?
      AND d.inventory_id = ?

    GROUP BY
        h.order_no,
        h.order_date,
        h.po,
        h.customer_id,
        mc.customer,
        h.customer_name,
        mm.marketing_name,
        h.marketing_id,
        h.sales_id,
        d.inventory_id

    LIMIT 1
";

$stmtSo = mysqli_prepare($conn, $sqlSo);

if (!$stmtSo) {
    echo json_encode([
        'success' => false,
        'message' => 'Prepare Sales Order gagal: ' . mysqli_error($conn)
    ]);
    exit;
}

mysqli_stmt_bind_param(
    $stmtSo,
    'ss',
    $orderNo,
    $inventoryId
);

mysqli_stmt_execute($stmtSo);

$rsSo = mysqli_stmt_get_result($stmtSo);

$so = mysqli_fetch_assoc($rsSo);

mysqli_stmt_close($stmtSo);

if (!$so) {
    echo json_encode([
        'success' => false,
        'message' => 'Detail Sales Order tidak ditemukan.'
    ]);
    exit;
}


// ============================================================
// TENTUKAN INVENTORY TERMASUK ROLL / PTG / LAIN
// ============================================================

$inventoryGroup = getInventoryGroup(
    $so['internal_name'],
    $so['inventory_name'],
    $so['category'],
    $so['inventory_type']
);


// ============================================================
// TOTAL ORDER BERDASARKAN UOM
// ============================================================

$orderBucket = newBucket();

addThreeUom(
    $orderBucket,

    $so['quantity'],
    $so['uom'],

    $so['quantity_pack'],
    $so['uom_pack'],

    $so['quantity_detail'],
    $so['uom_detail']
);


// ============================================================
// DATA SHIPPING
// ============================================================
//
// Satu Shipping No dapat saja mempunyai lebih dari satu detail
// inventory yang sama. Karena itu digroup per Shipping No.
//

$sqlShip = "
    SELECT
        hs.shipping_no,
        hs.shipping_date,

        SUM(COALESCE(ds.qty_shipping, 0)) AS qty_shipping,
        MAX(ds.uom_shipping) AS uom_shipping,

        SUM(COALESCE(ds.qty_pack_shipping, 0)) AS qty_pack_shipping,
        MAX(ds.uom_pack_shipping) AS uom_pack_shipping,

        SUM(COALESCE(ds.qty_detail_shipping, 0)) AS qty_detail_shipping,
        MAX(ds.uom_detail_shipping) AS uom_detail_shipping

    FROM hed_shipping hs

    INNER JOIN det_shipping ds
        ON ds.shipping_no = hs.shipping_no

    WHERE hs.order_no = ?
      AND ds.inventory_id = ?
      AND COALESCE(hs.status, 'Open') <> 'Cancel'

    GROUP BY
        hs.shipping_no,
        hs.shipping_date

    ORDER BY
        hs.shipping_date ASC,
        hs.shipping_no ASC
";

$stmtShip = mysqli_prepare($conn, $sqlShip);

if (!$stmtShip) {
    echo json_encode([
        'success' => false,
        'message' => 'Prepare Shipping gagal: ' . mysqli_error($conn)
    ]);
    exit;
}

mysqli_stmt_bind_param(
    $stmtShip,
    'ss',
    $orderNo,
    $inventoryId
);

mysqli_stmt_execute($stmtShip);

$rsShip = mysqli_stmt_get_result($stmtShip);


// ============================================================
// BENTUK TRANSAKSI
// ============================================================
//
// Baris pertama = ORDER
// Berikutnya = SHIPPING
//

$transactions = [];


// ------------------------------------------------------------
// ORDER
// ------------------------------------------------------------

$orderTransaction = [
    'date'        => $so['order_date'],
    'sort_type'   => 0,
    'shipping_no' => '',

    'roll' => newBucket(),
    'ptg'  => newBucket(),
    'lain' => newBucket(),
    'sj'   => newBucket()
];

if ($inventoryGroup === 'ROLL') {
    $orderTransaction['roll'] = $orderBucket;
} elseif ($inventoryGroup === 'PTG') {
    $orderTransaction['ptg'] = $orderBucket;
} else {
    $orderTransaction['lain'] = $orderBucket;
}

$transactions[] = $orderTransaction;


// ------------------------------------------------------------
// SHIPPING
// ------------------------------------------------------------

while ($ship = mysqli_fetch_assoc($rsShip)) {

    $sjBucket = newBucket();

    addThreeUom(
        $sjBucket,

        $ship['qty_shipping'],
        $ship['uom_shipping'],

        $ship['qty_pack_shipping'],
        $ship['uom_pack_shipping'],

        $ship['qty_detail_shipping'],
        $ship['uom_detail_shipping']
    );

    $transactions[] = [
        'date'        => $ship['shipping_date'],
        'sort_type'   => 1,
        'shipping_no' => $ship['shipping_no'],

        'roll' => newBucket(),
        'ptg'  => newBucket(),
        'lain' => newBucket(),
        'sj'   => $sjBucket
    ];
}

mysqli_stmt_close($stmtShip);


// ============================================================
// SORT TRANSAKSI BERDASARKAN TANGGAL
// ============================================================
//
// Jika Order dan Shipping tanggalnya sama:
// ORDER ditempatkan lebih dahulu.
//

usort($transactions, function ($a, $b) {

    $dateA = strtotime($a['date'] ?? '1970-01-01');
    $dateB = strtotime($b['date'] ?? '1970-01-01');

    if ($dateA === $dateB) {

        if ($a['sort_type'] === $b['sort_type']) {
            return strcmp(
                (string)$a['shipping_no'],
                (string)$b['shipping_no']
            );
        }

        return $a['sort_type'] <=> $b['sort_type'];
    }

    return $dateA <=> $dateB;
});


// ============================================================
// TOTAL
// ============================================================

$totalRoll = newBucket();
$totalPtg  = newBucket();
$totalLain = newBucket();
$totalSj   = newBucket();

$runningSaldo = newBucket();


// ============================================================
// OUTPUT HTML
// ============================================================

ob_start();
?>

<style>
    .kso-detail-info {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        padding: 10px 12px;
        margin-bottom: 10px;
        font-size: 12px;
    }

    .kso-detail-info strong {
        color: #1e3c72;
    }

    .kso-ledger-wrap {
        overflow-x: auto;
        border: 1px solid #cfd6df;
        background: #ffffff;
    }

    .kso-ledger {
        min-width: 1350px;
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
        margin: 0;
    }

    .kso-ledger th,
    .kso-ledger td {
        border: 1px solid #cfd6df;
        padding: 5px 6px;
        vertical-align: middle;
    }

    .kso-ledger thead th {
        background: #e9edf3 !important;
        color: #222 !important;
        font-weight: 700;
        text-align: center;
        white-space: nowrap;
    }

    .kso-ledger tbody td {
        text-align: right;
        white-space: nowrap;
    }

    .kso-ledger tbody td:first-child {
        text-align: center;
    }

    .kso-ledger tbody tr:hover {
        background: #eef4ff;
    }

    .kso-ledger .order-row {
        background: #f8fbff;
    }

    .kso-ledger .shipping-row {
        background: #ffffff;
    }

    .kso-ledger tfoot td {
        background: #f1f3f5;
        font-weight: 700;
        text-align: right;
        border-top: 2px solid #7a8797;
    }

    .kso-ledger tfoot td:first-child {
        text-align: center;
    }

    .kso-shipping-note {
        display: block;
        color: #6c757d;
        font-size: 9px;
        margin-top: 2px;
    }

    .saldo-positive {
        font-weight: 700;
    }

    .saldo-zero {
        color: #198754;
        font-weight: 700;
    }

    .saldo-minus {
        color: #dc3545;
        font-weight: 700;
    }
</style>


<div class="kso-detail-info">
    <div class="row">
        <div class="col-md-4 mb-1">
            <strong>No. SO:</strong>
            <?= e($so['order_no']) ?>
        </div>

        <div class="col-md-4 mb-1">
            <strong>Order Date:</strong>
            <?= e(fmtDate($so['order_date'])) ?>
        </div>

        <div class="col-md-4 mb-1">
            <strong>PO No.:</strong>
            <?= e($so['po'] ?: '-') ?>
        </div>

        <div class="col-md-4 mb-1">
            <strong>Customer:</strong>
            <?= e($so['customer_name']) ?>
        </div>

        <div class="col-md-4 mb-1">
            <strong>Marketing:</strong>
            <?= e($so['marketing_name']) ?>
        </div>

        <div class="col-md-4 mb-1">
            <strong>Jenis:</strong>
            <?= e($inventoryGroup) ?>
        </div>

        <div class="col-12">
            <strong>Ukuran:</strong>
            <?= e($so['internal_name']) ?>
        </div>
    </div>
</div>


<div class="kso-ledger-wrap">

    <table class="kso-ledger">

        <thead>
            <tr>

                <th rowspan="2" style="width:95px;">
                    Tanggal
                </th>

                <th colspan="3">
                    Roll
                </th>

                <th colspan="3">
                    Ptg
                </th>

                <th colspan="3">
                    Lain
                </th>

                <th colspan="3">
                    SJ
                </th>

                <th colspan="3">
                    Saldo
                </th>

            </tr>

            <tr>

                <th>B</th>
                <th>Ot</th>
                <th>KG</th>

                <th>B</th>
                <th>Ot</th>
                <th>KG</th>

                <th>BAL</th>
                <th>Other</th>
                <th>KG</th>

                <th>BAL</th>
                <th>Ot</th>
                <th>KG</th>

                <th>B</th>
                <th>Ot</th>
                <th>KG</th>

            </tr>
        </thead>


        <tbody>

        <?php foreach ($transactions as $trx): ?>

            <?php

            // ==================================================
            // TOTAL TRANSAKSI ORDER
            // ==================================================

            $totalRoll['b']  += $trx['roll']['b'];
            $totalRoll['ot'] += $trx['roll']['ot'];
            $totalRoll['kg'] += $trx['roll']['kg'];

            $totalPtg['b']  += $trx['ptg']['b'];
            $totalPtg['ot'] += $trx['ptg']['ot'];
            $totalPtg['kg'] += $trx['ptg']['kg'];

            $totalLain['b']  += $trx['lain']['b'];
            $totalLain['ot'] += $trx['lain']['ot'];
            $totalLain['kg'] += $trx['lain']['kg'];


            // ==================================================
            // TAMBAH ORDER KE SALDO
            // ==================================================

            $orderB =
                $trx['roll']['b'] +
                $trx['ptg']['b'] +
                $trx['lain']['b'];

            $orderOt =
                $trx['roll']['ot'] +
                $trx['ptg']['ot'] +
                $trx['lain']['ot'];

            $orderKg =
                $trx['roll']['kg'] +
                $trx['ptg']['kg'] +
                $trx['lain']['kg'];

            $runningSaldo['b'] += $orderB;
            $runningSaldo['ot'] += $orderOt;
            $runningSaldo['kg'] += $orderKg;


            // ==================================================
            // SHIPPING
            // ==================================================

            $totalSj['b'] += $trx['sj']['b'];
            $totalSj['ot'] += $trx['sj']['ot'];
            $totalSj['kg'] += $trx['sj']['kg'];


            // Shipping mengurangi saldo
            $runningSaldo['b'] -= $trx['sj']['b'];
            $runningSaldo['ot'] -= $trx['sj']['ot'];
            $runningSaldo['kg'] -= $trx['sj']['kg'];


            $saldoClassB =
                abs($runningSaldo['b']) < 0.00001
                    ? 'saldo-zero'
                    : (
                        $runningSaldo['b'] < 0
                            ? 'saldo-minus'
                            : 'saldo-positive'
                    );

            $saldoClassOt =
                abs($runningSaldo['ot']) < 0.00001
                    ? 'saldo-zero'
                    : (
                        $runningSaldo['ot'] < 0
                            ? 'saldo-minus'
                            : 'saldo-positive'
                    );

            $saldoClassKg =
                abs($runningSaldo['kg']) < 0.00001
                    ? 'saldo-zero'
                    : (
                        $runningSaldo['kg'] < 0
                            ? 'saldo-minus'
                            : 'saldo-positive'
                    );

            ?>

            <tr class="<?= $trx['sort_type'] === 0 ? 'order-row' : 'shipping-row' ?>">

                <!-- TANGGAL -->
                <td>
                    <?= e(fmtDate($trx['date'])) ?>

                    <?php if ($trx['shipping_no'] !== ''): ?>
                        <span
                            class="kso-shipping-note"
                            title="<?= e($trx['shipping_no']) ?>"
                        >
                            <?= e($trx['shipping_no']) ?>
                        </span>
                    <?php endif; ?>
                </td>


                <!-- ROLL -->
                <td><?= e(displayQty($trx['roll']['b'])) ?></td>
                <td><?= e(displayQty($trx['roll']['ot'])) ?></td>
                <td><?= e(displayQty($trx['roll']['kg'])) ?></td>


                <!-- PTG -->
                <td><?= e(displayQty($trx['ptg']['b'])) ?></td>
                <td><?= e(displayQty($trx['ptg']['ot'])) ?></td>
                <td><?= e(displayQty($trx['ptg']['kg'])) ?></td>


                <!-- LAIN -->
                <td><?= e(displayQty($trx['lain']['b'])) ?></td>
                <td><?= e(displayQty($trx['lain']['ot'])) ?></td>
                <td><?= e(displayQty($trx['lain']['kg'])) ?></td>


                <!-- SJ -->
                <td><?= e(displayQty($trx['sj']['b'])) ?></td>
                <td><?= e(displayQty($trx['sj']['ot'])) ?></td>
                <td><?= e(displayQty($trx['sj']['kg'])) ?></td>


                <!-- SALDO -->
                <td class="<?= e($saldoClassB) ?>">
                    <?= e(displayQty($runningSaldo['b'])) ?>
                </td>

                <td class="<?= e($saldoClassOt) ?>">
                    <?= e(displayQty($runningSaldo['ot'])) ?>
                </td>

                <td class="<?= e($saldoClassKg) ?>">
                    <?= e(displayQty($runningSaldo['kg'])) ?>
                </td>

            </tr>

        <?php endforeach; ?>

        </tbody>


        <!-- =====================================================
             TOTAL
        ====================================================== -->

      <tfoot>
    <tr>
        <td>
            TOTAL
        </td>

        <!-- ROLL -->
        <td><?= e(displayQty($totalRoll['b'])) ?></td>
        <td><?= e(displayQty($totalRoll['ot'])) ?></td>
        <td><?= e(displayQty($totalRoll['kg'])) ?></td>

        <!-- PTG -->
        <td><?= e(displayQty($totalPtg['b'])) ?></td>
        <td><?= e(displayQty($totalPtg['ot'])) ?></td>
        <td><?= e(displayQty($totalPtg['kg'])) ?></td>

        <!-- LAIN -->
        <td><?= e(displayQty($totalLain['b'])) ?></td>
        <td><?= e(displayQty($totalLain['ot'])) ?></td>
        <td><?= e(displayQty($totalLain['kg'])) ?></td>

        <!-- SJ -->
        <td><?= e(displayQty($totalSj['b'])) ?></td>
        <td><?= e(displayQty($totalSj['ot'])) ?></td>
        <td><?= e(displayQty($totalSj['kg'])) ?></td>

        <!-- SALDO TIDAK DITOTAL -->
        <td>&nbsp;</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
    </tr>
</tfoot>

    </table>

</div>


<div class="mt-2 text-muted" style="font-size:10px;">
    <strong>Keterangan:</strong>
    B = Ball/BAL,
    Ot = Other,
    KG = Kilogram,
    SJ = Surat Jalan.
    Saldo merupakan Order dikurangi Shipping secara kumulatif.
</div>

<?php

$html = ob_get_clean();

$title =
    'Detail Kartu Stok Order Customer - ' .
    $orderNo;

$printUrl =
    'index.php?page=cetak_kartu_stok_order_customer'
    . '&order_no=' . urlencode($orderNo)
    . '&inventory_id=' . urlencode($inventoryId);

echo json_encode([
    'success'   => true,
    'title'     => $title,
    'html'      => $html,
    'print_url' => $printUrl
]);

exit;