<?php
// modul/transaksi/register_penjualan_global_return.php

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

/*
|--------------------------------------------------------------------------
| HELPER
|--------------------------------------------------------------------------
*/

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

    return $t
        ? date('d-M-Y', $t)
        : '';
}

function fmtMoneyR($v) {
    return number_format((float)$v, 2, ',', '.');
}

function fmtQtyR($v) {
    $s = number_format((float)$v, 2, ',', '.');

    return rtrim(
        rtrim($s, '0'),
        ','
    );
}

function emptyCatR() {
    return [
        'qty'    => 0.0,
        'qty_kg' => 0.0,
        'rp'     => 0.0
    ];
}

function emptyGrandR() {
    return [
        'total'     => 0.0,
        'penjualan' => 0.0,
        'PP'        => emptyCatR(),
        'KERTAS'    => emptyCatR(),
        'PE'        => emptyCatR(),
        'PE WARNA'  => emptyCatR(),
        'LAIN LAIN' => emptyCatR()
    ];
}

/*
|--------------------------------------------------------------------------
| FILTER TANGGAL
|--------------------------------------------------------------------------
*/

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
|--------------------------------------------------------------------------
| SORTING
|--------------------------------------------------------------------------
| Default dan satu-satunya sorting:
| - Return ID terbaru ke lama.
|--------------------------------------------------------------------------
*/

$sort = 'return_id';

$dir = strtolower(
    trim((string)($_GET['dir'] ?? 'desc'))
);

$allowedDir = [
    'asc',
    'desc'
];

if (!in_array($dir, $allowedDir, true)) {
    $dir = 'desc';
}

$orderDirection = strtoupper($dir);

/*
|--------------------------------------------------------------------------
| ORDER COLUMN
|--------------------------------------------------------------------------
*/

if ($sort === 'return_id') {

    /*
     * RETURN ID mempunyai dua pola:
     *
     * 1. CP-MCP
     *    R02AGT/26
     *
     * 2. CP Normal
     *    15/CP/VII/2026
     *
     * Sorting:
     * Tahun -> Bulan -> Nomor/Hari
     */
    $orderColumn = "

        CASE

            /* Format CP normal: 15/CP/VII/2026 */
            WHEN UPPER(TRIM(hri.return_id)) LIKE '%/CP/%'
            THEN CAST(
                RIGHT(TRIM(hri.return_id), 4)
                AS UNSIGNED
            )

            /* Format CP-MCP: R02AGT/26 */
            ELSE
                2000 + CAST(
                    RIGHT(TRIM(hri.return_id), 2)
                    AS UNSIGNED
                )

        END {$orderDirection},

        CASE

            /*
             * CP Normal
             * Contoh: 15/CP/VII/2026
             */
            WHEN UPPER(TRIM(hri.return_id)) LIKE '%/CP/%'
            THEN

                CASE
                    /*
                     * Urutan harus dicek dari angka romawi
                     * terpanjang dahulu agar VIII tidak terbaca
                     * sebagai I.
                     */
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/XII/%'  THEN 12
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/XI/%'   THEN 11
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/VIII/%' THEN 8
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/VII/%'  THEN 7
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/VI/%'   THEN 6
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/IV/%'   THEN 4
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/III/%'  THEN 3
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/IX/%'   THEN 9
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/X/%'    THEN 10
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/V/%'    THEN 5
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/II/%'   THEN 2
                    WHEN UPPER(TRIM(hri.return_id)) LIKE '%/I/%'    THEN 1
                    ELSE 0
                END

            /*
             * CP-MCP
             * Contoh: R02AGT/26
             */
            ELSE

                CASE SUBSTRING(
                    UPPER(TRIM(hri.return_id)),
                    4,
                    3
                )
                    WHEN 'JAN' THEN 1
                    WHEN 'FEB' THEN 2
                    WHEN 'MAR' THEN 3
                    WHEN 'APR' THEN 4
                    WHEN 'MEI' THEN 5
                    WHEN 'JUN' THEN 6
                    WHEN 'JUL' THEN 7
                    WHEN 'AGT' THEN 8
                    WHEN 'SEP' THEN 9
                    WHEN 'OKT' THEN 10
                    WHEN 'NOV' THEN 11
                    WHEN 'DES' THEN 12
                    ELSE 0
                END

        END {$orderDirection},

        CASE

            /* CP Normal: 15/CP/VII/2026 */
            WHEN UPPER(TRIM(hri.return_id)) LIKE '%/CP/%'
            THEN CAST(
                SUBSTRING_INDEX(
                    TRIM(hri.return_id),
                    '/',
                    1
                )
                AS UNSIGNED
            )

            /* CP-MCP: R02AGT/26 */
            ELSE CAST(
                SUBSTRING(
                    TRIM(hri.return_id),
                    2,
                    2
                )
                AS UNSIGNED
            )

        END {$orderDirection},

        TRIM(hri.return_id) {$orderDirection}
    ";

} elseif ($sort === 'invoice_no') {

    $orderColumn = "
        hri.invoice_no {$orderDirection}
    ";

} else {

    $orderColumn = "
        hri.shipping_no {$orderDirection}
    ";
}

/*
|--------------------------------------------------------------------------
| URL SORTING
|--------------------------------------------------------------------------
*/

function sortUrlR(
    $column,
    $currentSort,
    $currentDir,
    $startDate,
    $endDate
) {
    $nextDir =
        ($currentSort === $column && $currentDir === 'asc')
            ? 'desc'
            : 'asc';

    return 'index.php?' . http_build_query([
        'page'       => 'register_penjualan_global_return',
        'start_date' => fmtDateR($startDate),
        'end_date'   => fmtDateR($endDate),
        'sort'       => $column,
        'dir'        => $nextDir,
    ]);
}

function sortIconR(
    $column,
    $currentSort,
    $currentDir
) {
    if ($currentSort !== $column) {
        return '↕';
    }

    return $currentDir === 'asc'
        ? '▲'
        : '▼';
}

/*
|--------------------------------------------------------------------------
| DATA RETUR
|--------------------------------------------------------------------------
| - Header  : head_retur_invoice
| - Detail  : detail_retur_invoice
| - Cancelled tidak dihitung.
|
| Rule nilai:
| - CP-MCP = grand_total
| - CP normal = return_amount
|
| Kategori:
| - PP
| - KERTAS
| - PE
| - PE WARNA
| - LAIN LAIN
|--------------------------------------------------------------------------
*/

$sql = "
SELECT

    hri.return_id,
    hri.return_date,
    hri.invoice_no,
    hri.shipping_no,
    hri.customer_name,

    MAX(
        COALESCE(
            hri.grand_total,
            0
        )
    ) AS total_return,

    MAX(
        CASE

            WHEN
                UPPER(
                    COALESCE(
                        hri.invoice_no,
                        ''
                    )
                ) LIKE '%CP-MCP%'

                OR

                UPPER(
                    COALESCE(
                        hri.shipping_no,
                        ''
                    )
                ) LIKE '%CP-MCP%'

            THEN COALESCE(
                hri.grand_total,
                0
            )

            ELSE COALESCE(
                hri.return_amount,
                0
            )

        END
    ) AS penjualan_return,

    /*
    |--------------------------------------------------------------------------
    | CATEGORY
    |--------------------------------------------------------------------------
    */
    CASE

        /*
         * PE WARNA harus dicek sebelum PE.
         */
        WHEN

            UPPER(
                COALESCE(
                    NULLIF(
                        TRIM(dri.inventory_name),
                        ''
                    ),
                    NULLIF(
                        TRIM(mi.inventory_name),
                        ''
                    ),
                    ''
                )
            )
            REGEXP '(^|[^A-Z])PE([^A-Z]|$)'

            AND

            UPPER(
                COALESCE(
                    NULLIF(
                        TRIM(dri.inventory_name),
                        ''
                    ),
                    NULLIF(
                        TRIM(mi.inventory_name),
                        ''
                    ),
                    ''
                )
            )
            LIKE '%WARNA%'

        THEN 'PE WARNA'

        WHEN

            UPPER(
                COALESCE(
                    NULLIF(
                        TRIM(dri.inventory_name),
                        ''
                    ),
                    NULLIF(
                        TRIM(mi.inventory_name),
                        ''
                    ),
                    ''
                )
            )
            LIKE '%KERTAS%'

        THEN 'KERTAS'

        WHEN

            UPPER(
                COALESCE(
                    NULLIF(
                        TRIM(dri.inventory_name),
                        ''
                    ),
                    NULLIF(
                        TRIM(mi.inventory_name),
                        ''
                    ),
                    ''
                )
            )
            REGEXP '(^|[^A-Z])PP([^A-Z]|$)'

        THEN 'PP'

        WHEN

            UPPER(
                COALESCE(
                    NULLIF(
                        TRIM(dri.inventory_name),
                        ''
                    ),
                    NULLIF(
                        TRIM(mi.inventory_name),
                        ''
                    ),
                    ''
                )
            )
            REGEXP '(^|[^A-Z])PE([^A-Z]|$)'

        THEN 'PE'

        ELSE 'LAIN LAIN'

    END AS category_group,

    /*
    |--------------------------------------------------------------------------
    | QTY UTAMA
    |--------------------------------------------------------------------------
    */
    SUM(
        CASE

            WHEN
                UPPER(
                    TRIM(
                        COALESCE(
                            dri.uom_pack,
                            ''
                        )
                    )
                ) <> ''

                AND

                UPPER(
                    TRIM(
                        COALESCE(
                            dri.uom_pack,
                            ''
                        )
                    )
                ) <> 'KG'

            THEN COALESCE(
                dri.return_quantity_pack,
                0
            )

            WHEN
                UPPER(
                    TRIM(
                        COALESCE(
                            dri.uom_detail,
                            ''
                        )
                    )
                ) <> ''

                AND

                UPPER(
                    TRIM(
                        COALESCE(
                            dri.uom_detail,
                            ''
                        )
                    )
                ) <> 'KG'

            THEN COALESCE(
                dri.return_quantity_detail,
                0
            )

            ELSE COALESCE(
                dri.return_quantity,
                0
            )

        END
    ) AS category_qty,

    /*
    |--------------------------------------------------------------------------
    | QTY KG
    |--------------------------------------------------------------------------
    */
    SUM(
        CASE

            WHEN
                UPPER(
                    TRIM(
                        COALESCE(
                            dri.uom,
                            ''
                        )
                    )
                ) = 'KG'

            THEN COALESCE(
                dri.return_quantity,
                0
            )

            WHEN
                UPPER(
                    TRIM(
                        COALESCE(
                            dri.uom_pack,
                            ''
                        )
                    )
                ) = 'KG'

            THEN COALESCE(
                dri.return_quantity_pack,
                0
            )

            WHEN
                UPPER(
                    TRIM(
                        COALESCE(
                            dri.uom_detail,
                            ''
                        )
                    )
                ) = 'KG'

            THEN COALESCE(
                dri.return_quantity_detail,
                0
            )

            ELSE 0

        END
    ) AS category_qty_kg,

    /*
    |--------------------------------------------------------------------------
    | NILAI CATEGORY
    |--------------------------------------------------------------------------
    */
    SUM(
        COALESCE(
            dri.return_subtotal,
            0
        )
    ) AS category_rp

FROM head_retur_invoice hri

INNER JOIN detail_retur_invoice dri
    ON TRIM(dri.return_id) = TRIM(hri.return_id)

LEFT JOIN m_inventory mi
    ON mi.inventory_id = dri.inventory_id

WHERE
    hri.return_date BETWEEN ? AND ?

    AND LOWER(
        COALESCE(
            hri.status,
            'Open'
        )
    ) <> 'cancelled'

GROUP BY
    hri.return_id,
    hri.return_date,
    hri.invoice_no,
    hri.shipping_no,
    hri.customer_name,
    category_group

ORDER BY
    {$orderColumn},
    hri.return_date DESC,
    category_group ASC
";

$stmt = mysqli_prepare(
    $conn,
    $sql
);

if (!$stmt) {
    die(
        'SQL Register Penjualan Global Return Error: ' .
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

/*
|--------------------------------------------------------------------------
| CATEGORY
|--------------------------------------------------------------------------
*/

$cats = [
    'PP',
    'KERTAS',
    'PE',
    'PE WARNA',
    'LAIN LAIN'
];

/*
|--------------------------------------------------------------------------
| BUILD ROW
|--------------------------------------------------------------------------
*/

$rows = [];
$grand = emptyGrandR();

while ($item = mysqli_fetch_assoc($res)) {

    /*
     * Satu Return ID = satu baris register.
     *
     * Invoice / Shipping yang sama boleh memiliki
     * beberapa Return ID berbeda.
     */
    $key = (string)$item['return_id'];

    if (!isset($rows[$key])) {

        $rows[$key] = [

            'return_id' => $item['return_id'],

            'return_date' => $item['return_date'],

            'customer_name' => $item['customer_name'],

            'total' => (float)$item['total_return'],

            'penjualan' => (float)$item['penjualan_return'],

            'PP' => emptyCatR(),

            'KERTAS' => emptyCatR(),

            'PE' => emptyCatR(),

            'PE WARNA' => emptyCatR(),

            'LAIN LAIN' => emptyCatR()
        ];

        $grand['total'] +=
            (float)$item['total_return'];

        $grand['penjualan'] +=
            (float)$item['penjualan_return'];
    }

    $g = $item['category_group'];

    if (!isset($rows[$key][$g])) {
        $g = 'LAIN LAIN';
    }

    $rows[$key][$g]['qty'] +=
        (float)$item['category_qty'];

    $rows[$key][$g]['qty_kg'] +=
        (float)$item['category_qty_kg'];

    $rows[$key][$g]['rp'] +=
        (float)$item['category_rp'];

    $grand[$g]['qty'] +=
        (float)$item['category_qty'];

    $grand[$g]['qty_kg'] +=
        (float)$item['category_qty_kg'];

    $grand[$g]['rp'] +=
        (float)$item['category_rp'];
}

mysqli_stmt_close($stmt);
?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css"
>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>
.rpg-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}

.rpg-wrap {
    padding: 12px;
    background: #f0f2f5;
    color: #212529;
    font-size: 11px;
}

.rpg-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    padding: 11px 15px;
    border-radius: 5px;
    color: #fff;
    background: linear-gradient(
        135deg,
        #1e3c72,
        #2a5298
    );
}

.rpg-head h5 {
    margin: 0;
    font-size: 15px;
}

.filter-card {
    margin-bottom: 10px;
    padding: 10px;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    background: #fff;
}

.filter-grid {
    display: grid;
    grid-template-columns: 1fr 1fr auto;
    gap: 8px;
    align-items: end;
}

.ff label {
    display: block;
    margin-bottom: 3px;
    color: #0d6efd;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

.ff input {
    width: 100%;
    padding: 6px 8px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    font-size: 11px;
}

.btn-vs {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 30px;
    padding: 6px 12px;
    border: 0;
    border-radius: 3px;
    color: #fff;
    font-size: 11px;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
}

.btn-dark {
    background: #212529;
}

.btn-secondary {
    background: #6c757d;
}

.btn-success {
    background: #198754;
}

.table-wrap {
    max-height: 610px;
    overflow: auto;
    border: 1px solid #bac7d5;
    background: #fff;
}

.report-table {
    width: 100%;
    min-width: 1700px;
    border-collapse: collapse;
    font-size: 9px;
}

.report-table th {
    position: sticky;
    z-index: 3;
    padding: 5px 3px;
    border: 1px solid #9faebd;
    background: #e9ecef;
    color: #253c5c;
    text-align: center;
    white-space: nowrap;
}

.report-table thead tr:first-child th {
    top: 0;
}

.report-table thead tr:nth-child(2) th {
    top: 27px;
}

.report-table td {
    padding: 4px 3px;
    border: 1px solid #d3d3d3;
    white-space: nowrap;
}

.report-table tbody tr:hover td {
    background: #fff1f1;
}

.report-table tfoot td {
    position: sticky;
    bottom: 0;
    z-index: 2;
    padding: 5px 3px;
    border: 1px solid #9faebd;
    background: #f2f2f2;
    font-weight: bold;
}

.money-cell,
.qty-cell {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.text-center {
    text-align: center;
}

.customer-cell {
    max-width: 230px;
    overflow: hidden;
    text-overflow: ellipsis;
}

.return-id-cell {
    font-weight: 700;
    color: #8b0000;
}

.sort-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    color: #253c5c;
    text-decoration: none;
    font-weight: 700;
}

.sort-link:hover {
    color: #0d6efd;
    text-decoration: none;
}

.sort-icon {
    font-size: 9px;
    line-height: 1;
}

.return-row td {
    color: #8b0000;
}

@media (max-width: 800px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }

    .rpg-head {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>

<div class="rpg-wrap">

    <!-- HEADER -->
    <div class="rpg-head">

        <h5>
            Register Penjualan Global Return
        </h5>

        <a
            class="btn-vs btn-success"
            href="modul/transaksi/cetak_register_penjualan_global_return.php?start_date=<?= urlencode(fmtDateR($startDate)) ?>&end_date=<?= urlencode(fmtDateR($endDate)) ?>&sort=<?= urlencode($sort) ?>&dir=<?= urlencode($dir) ?>"
            target="_blank"
        >
            Cetak Register
        </a>

    </div>

    <!-- FILTER -->
    <div class="filter-card">

        <form
            method="GET"
            action="index.php"
        >

            <input
                type="hidden"
                name="page"
                value="register_penjualan_global_return"
            >

            <input
                type="hidden"
                name="sort"
                value="<?= h($sort) ?>"
            >

            <input
                type="hidden"
                name="dir"
                value="<?= h($dir) ?>"
            >

            <div class="filter-grid">

                <div class="ff">

                    <label>
                        Start Date
                    </label>

                    <input
                        type="text"
                        name="start_date"
                        class="date-picker"
                        value="<?= h(fmtDateR($startDate)) ?>"
                        autocomplete="off"
                    >

                </div>

                <div class="ff">

                    <label>
                        End Date
                    </label>

                    <input
                        type="text"
                        name="end_date"
                        class="date-picker"
                        value="<?= h(fmtDateR($endDate)) ?>"
                        autocomplete="off"
                    >

                </div>

                <div style="display:flex;gap:6px">

                    <button
                        type="submit"
                        class="btn-vs btn-dark"
                    >
                        Search
                    </button>

                    <a
                        href="index.php?page=register_penjualan_global_return"
                        class="btn-vs btn-secondary"
                    >
                        Reset
                    </a>

                </div>

            </div>

        </form>

    </div>

    <!-- TABLE -->
    <div class="table-wrap">

        <table class="report-table">

            <thead>

                <!-- HEADER BARIS 1 -->
                <tr>

                    <th rowspan="2">

                        <a
                            class="sort-link"
                            href="<?= h(
                                sortUrlR(
                                    'return_id',
                                    $sort,
                                    $dir,
                                    $startDate,
                                    $endDate
                                )
                            ) ?>"
                            title="Urutkan Return ID"
                        >

                            RETURN ID

                            <span class="sort-icon">
                                <?= h(
                                    sortIconR(
                                        'return_id',
                                        $sort,
                                        $dir
                                    )
                                ) ?>
                            </span>

                        </a>

                    </th>

                    <th rowspan="2">
                        NAMA CUST.
                    </th>

                    <th rowspan="2">
                        TOTAL
                    </th>

                    <th rowspan="2">
                        PENJUALAN
                    </th>

                    <?php foreach ($cats as $cat): ?>

                        <th colspan="3">
                            <?= h($cat) ?>
                        </th>

                    <?php endforeach; ?>

                </tr>

                <!-- HEADER BARIS 2 -->
                <tr>

                    <?php foreach ($cats as $cat): ?>

                        <th>
                            Qty
                        </th>

                        <th>
                            Qty KG
                        </th>

                        <th>
                            Rp
                        </th>

                    <?php endforeach; ?>

                </tr>

            </thead>

            <tbody>

            <?php if (empty($rows)): ?>

                <tr>

                    <td
                        colspan="19"
                        class="text-center"
                        style="padding:18px;color:#777"
                    >
                        Tidak ada data return penjualan pada periode ini.
                    </td>

                </tr>

            <?php else: ?>

                <?php foreach ($rows as $row): ?>

                    <tr
                        class="return-row"
                        title="Return ID: <?= h($row['return_id']) ?> | Tanggal: <?= h(fmtDateR($row['return_date'])) ?>"
                    >

                        <!-- RETURN ID -->
                        <td class="return-id-cell">
                            <?= h($row['return_id']) ?>
                        </td>

                        <!-- CUSTOMER -->
                        <td
                            class="customer-cell"
                            title="<?= h($row['customer_name']) ?>"
                        >
                            <?= h($row['customer_name']) ?>
                        </td>

                        <!-- TOTAL -->
                        <td class="money-cell">
                            <?= h(
                                fmtMoneyR(
                                    $row['total']
                                )
                            ) ?>
                        </td>

                        <!-- PENJUALAN -->
                        <td class="money-cell">
                            <?= h(
                                fmtMoneyR(
                                    $row['penjualan']
                                )
                            ) ?>
                        </td>

                        <!-- CATEGORY -->
                        <?php foreach ($cats as $cat): ?>

                            <td class="qty-cell">
                                <?= h(
                                    fmtQtyR(
                                        $row[$cat]['qty']
                                    )
                                ) ?>
                            </td>

                            <td class="qty-cell">
                                <?= h(
                                    fmtQtyR(
                                        $row[$cat]['qty_kg']
                                    )
                                ) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(
                                    fmtMoneyR(
                                        $row[$cat]['rp']
                                    )
                                ) ?>
                            </td>

                        <?php endforeach; ?>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

            <!-- TOTAL -->
            <tfoot>

                <tr>

                    <td
                        colspan="2"
                        style="text-align:right"
                    >
                        TOTAL RETURN
                    </td>

                    <td class="money-cell">
                        <?= h(
                            fmtMoneyR(
                                $grand['total']
                            )
                        ) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(
                            fmtMoneyR(
                                $grand['penjualan']
                            )
                        ) ?>
                    </td>

                    <?php foreach ($cats as $cat): ?>

                        <td class="qty-cell">
                            <?= h(
                                fmtQtyR(
                                    $grand[$cat]['qty']
                                )
                            ) ?>
                        </td>

                        <td class="qty-cell">
                            <?= h(
                                fmtQtyR(
                                    $grand[$cat]['qty_kg']
                                )
                            ) ?>
                        </td>

                        <td class="money-cell">
                            <?= h(
                                fmtMoneyR(
                                    $grand[$cat]['rp']
                                )
                            ) ?>
                        </td>

                    <?php endforeach; ?>

                </tr>

            </tfoot>

        </table>

    </div>

</div>

<script>
if (typeof flatpickr !== 'undefined') {

    flatpickr(
        '.date-picker',
        {
            dateFormat: 'd-M-Y',
            allowInput: true,
            disableMobile: true
        }
    );

}
</script>