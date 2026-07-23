<?php
// modul/transaksi/kartu_piutang.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function parseReportDate($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    return $fallback;
}

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function appIcon($name) {
    $icons = [
        'report' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 2H6v16h12V8h-4V4Zm-5 8h6v2H9v-2Zm0 4h6v2H9v-2Zm0-8h3v2H9V8Z"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 4a6.5 6.5 0 0 1 5.18 10.43l4.45 4.44-1.42 1.42-4.44-4.45A6.5 6.5 0 1 1 10.5 4Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z"/></svg>',
        'reset' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.33 10H7.9A5 5 0 1 0 12 7H8.83l2.58 2.59L10 11 5 6l5-5 1.41 1.41L8.83 5H12Z"/></svg>',
        'print' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10v5H7V3Zm2 2v1h6V5H9ZM6 9h12a3 3 0 0 1 3 3v5h-4v4H7v-4H3v-5a3 3 0 0 1 3-3Zm3 7v3h6v-3H9Zm8-3h2v-1a1 1 0 0 0-1-1H6a1 1 0 0 0-1 1v3h2v-1h10v1h2v-2h-2Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'customer' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4 22a8 8 0 0 1 16 0h-2a6 6 0 0 0-12 0H4Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}

$today = date('Y-m-d');
$start_date = parseReportDate($_GET['start_date'] ?? '', $today);
$end_date = parseReportDate($_GET['end_date'] ?? '', $today);

if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$start_date_display = formatDateDisplay($start_date);
$end_date_display = formatDateDisplay($end_date);

$customer_id = trim((string)($_GET['customer_id'] ?? ''));

$customers = [];
$sqlCustomer = "
    SELECT customer_id, customer
    FROM m_customer
    WHERE COALESCE(is_active, 'Checked') = 'Checked'
    ORDER BY customer ASC
";
$resCustomer = mysqli_query($conn, $sqlCustomer);
if ($resCustomer) {
    while ($row = mysqli_fetch_assoc($resCustomer)) {
        $customers[] = $row;
    }
}

$customerData = null;
$rows = [];
$saldo_awal = 0;
$total_penjualan = 0;
$total_pembayaran = 0;
$saldo_akhir = 0;

if ($customer_id !== '') {
    $sqlCustDetail = "
        SELECT 
            mc.customer_id,
            mc.customer,
            mc.area_code,
            COALESCE(ma.area, mc.area_code, '') AS area_name
        FROM m_customer mc
        LEFT JOIN m_area ma 
            ON ma.kode COLLATE utf8mb4_general_ci = mc.area_code
        WHERE mc.customer_id = ?
        LIMIT 1
    ";
    $stmtCust = mysqli_prepare($conn, $sqlCustDetail);
    mysqli_stmt_bind_param($stmtCust, 's', $customer_id);
    mysqli_stmt_execute($stmtCust);
    $resCustDetail = mysqli_stmt_get_result($stmtCust);
    $customerData = mysqli_fetch_assoc($resCustDetail);
    mysqli_stmt_close($stmtCust);

   $amountExpr = "
    CASE
        WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
        WHEN COALESCE(hi.payment_balance, 0) > 0 THEN COALESCE(hi.payment_balance, 0)
        ELSE COALESCE(hi.grand_total, 0)
    END
";

  $sqlSaldo = "
    SELECT
        (
            COALESCE((
                SELECT SUM(
                    CASE
                        WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
                        WHEN COALESCE(hi.payment_balance, 0) > 0 THEN COALESCE(hi.payment_balance, 0)
                        ELSE COALESCE(hi.grand_total, 0)
                    END
                )
                FROM head_invoice hi
                WHERE hi.customer_id = ?
                  AND hi.invoice_date < ?
            ), 0)
            -
            COALESCE((
                SELECT SUM(db.bayar_amount)
                FROM detail_bayar db
                INNER JOIN head_bayar hb ON hb.bayar_no = db.bayar_no
                WHERE hb.customer_id = ?
                  AND hb.bayar_date < ?
            ), 0)
        ) AS saldo_awal
";
$stmtSaldo = mysqli_prepare($conn, $sqlSaldo);
mysqli_stmt_bind_param($stmtSaldo, 'ssss', $customer_id, $start_date, $customer_id, $start_date);
mysqli_stmt_execute($stmtSaldo);
$resSaldo = mysqli_stmt_get_result($stmtSaldo);
$rowSaldo = mysqli_fetch_assoc($resSaldo);
$saldo_awal = (float)($rowSaldo['saldo_awal'] ?? 0);
mysqli_stmt_close($stmtSaldo);

    $sqlRows = "
        SELECT
            trans_date,
            shipping_no,
            bayar_no,
            penjualan,
            pembayaran,
            sort_order,
            invoice_no_sort
        FROM
        (
            SELECT
                hi.invoice_date AS trans_date,
                COALESCE(di.shipping_no, '') AS shipping_no,
                '' AS bayar_no,
                CASE
                    WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
                    WHEN COALESCE(hi.payment_balance, 0) > 0 THEN COALESCE(hi.payment_balance, 0)
                    ELSE COALESCE(hi.grand_total, 0)
                END AS penjualan,
                0 AS pembayaran,
                1 AS sort_order,
                hi.invoice_no AS invoice_no_sort
            FROM head_invoice hi
            LEFT JOIN (
                SELECT
                    invoice_no,
                    GROUP_CONCAT(
                        DISTINCT shipping_no
                        ORDER BY shipping_no
                        SEPARATOR ', '
                    ) AS shipping_no
                FROM det_invoice
                GROUP BY invoice_no
            ) di ON di.invoice_no = hi.invoice_no
            WHERE hi.customer_id = ?
            AND hi.invoice_date BETWEEN ? AND ?

            UNION ALL

            SELECT
                hb.bayar_date AS trans_date,
                COALESCE(di.shipping_no, '') AS shipping_no,
                hb.bayar_no AS bayar_no,
                0 AS penjualan,
                db.bayar_amount AS pembayaran,
                2 AS sort_order,
                db.invoice_no AS invoice_no_sort
            FROM head_bayar hb
            INNER JOIN detail_bayar db ON db.bayar_no = hb.bayar_no
            LEFT JOIN (
                SELECT
                    invoice_no,
                    GROUP_CONCAT(
                        DISTINCT shipping_no
                        ORDER BY shipping_no
                        SEPARATOR ', '
                    ) AS shipping_no
                FROM det_invoice
                GROUP BY invoice_no
            ) di ON di.invoice_no = db.invoice_no
            WHERE hb.customer_id = ?
            AND hb.bayar_date BETWEEN ? AND ?
        ) x
        ORDER BY
            trans_date ASC,
            sort_order ASC,
            shipping_no ASC,
            invoice_no_sort ASC,
            bayar_no ASC
    ";
    $stmtRows = mysqli_prepare($conn, $sqlRows);
    mysqli_stmt_bind_param(
        $stmtRows,
        'ssssss',
        $customer_id,
        $start_date,
        $end_date,
        $customer_id,
        $start_date,
        $end_date
    );
    mysqli_stmt_execute($stmtRows);
    $resRows = mysqli_stmt_get_result($stmtRows);

    $rows = [];
    $total_penjualan = 0;
    $total_pembayaran = 0;
    $runningSaldo = $saldo_awal;

    while ($row = mysqli_fetch_assoc($resRows)) {
        $penjualan = (float)($row['penjualan'] ?? 0);
        $pembayaran = (float)($row['pembayaran'] ?? 0);

        $runningSaldo += $penjualan - $pembayaran;

        $row['sisa'] = $runningSaldo;

        $total_penjualan += $penjualan;
        $total_pembayaran += $pembayaran;

        $rows[] = $row;
    }

    mysqli_stmt_close($stmtRows);

    $saldo_akhir = $saldo_awal + $total_penjualan - $total_pembayaran;
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>


<style>
.kartu-piutang-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.kartu-piutang-wrap {
    background: #f0f2f5;
    padding: 12px;
    color: #212529;
    font-size: 11px;
}
.app-icon {
    width: 14px;
    height: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    vertical-align: -2px;
}
.app-icon svg {
    width: 14px;
    height: 14px;
    display: block;
    fill: currentColor;
}
.title-icon svg {
    width: 18px;
    height: 18px;
}
.crystal-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: #fff;
    padding: 10px 15px;
    border-radius: 5px;
}
.filter-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin-bottom: 10px;
}
.filter-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 8px;
    align-items: end;
}
.ff label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: #0d6efd;
    margin-bottom: 3px;
    text-transform: uppercase;
}
.ff input,
.ff select {
    width: 100%;
    border: 1px solid #ced4da;
    border-radius: 3px;
    padding: 6px 8px;
    font-size: 11px;
    background: #fff;
}
.btn-vs {
    padding: 6px 12px;
    font-size: 11px;
    font-weight: bold;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    line-height: 1;
    min-height: 30px;
}
.btn-vs:hover {
    filter: brightness(.95);
    text-decoration: none;
}
.btn-primary { background: #0d6efd; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-success { background: #198754; color: #fff; }
.btn-dark { background: #212529; color: #fff; }
.btn-disabled {
    background: #adb5bd;
    color: #fff;
    cursor: not-allowed;
    pointer-events: none;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(150px, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}
.summary-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
}
.summary-card .label {
    color: #6c757d;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 5px;
}
.summary-card .value {
    font-size: 13px;
    font-weight: 800;
    color: #1e3c72;
}
.table-wrap {
    max-height: 560px;
    overflow: auto;
    border: 1px solid #c0cddb;
    background: #fff;
}
.kartu-table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
    font-size: 9.5px;
}
.kartu-table th {
    position: sticky;
    top: 0;
    background: #e9ecef;
    color: #2b4c7e;
    border: 1px solid #c0cddb;
    padding: 5px 4px;
    text-align: center;
    white-space: nowrap;
    z-index: 2;
}
.money-cell {
    text-align: right;
    font-family: Arial, Helvetica, sans-serif;
    font-variant-numeric: tabular-nums;
}
.kartu-table td {
    border: 1px solid #d3d3d3;
    padding: 4px 4px;
    vertical-align: middle;
    white-space: nowrap;
}
.kartu-table tbody tr:hover td {
    background: #e8f2fe;
}
.kartu-table tfoot td {
    background: #f8f9fa;
    font-weight: bold;
}
.text-right { text-align: right; }
.text-center { text-align: center; }
.text-bold { font-weight: bold; }
.text-blue { color: #0d6efd; }
.customer-info {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin-bottom: 10px;
    line-height: 1.7;
}
@media (max-width: 900px) {
    .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
    .summary-grid {
        grid-template-columns: 1fr 1fr;
    }
}
.select2-container {
    width: 100% !important;
    font-size: 11px;
}

.select2-container--default .select2-selection--single {
    height: 30px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    display: flex;
    align-items: center;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 28px;
    color: #212529;
    padding-left: 8px;
    font-size: 11px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 28px;
}

.select2-dropdown {
    font-size: 11px;
}

.select2-search--dropdown .select2-search__field {
    font-size: 11px;
    padding: 5px;
}
</style>

<div class="kartu-piutang-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('report') ?></span>
            Kartu Piutang
        </h5>

        <?php if ($customer_id !== ''): ?>
            <a class="btn-vs btn-success"
               href="modul/transaksi/cetak_kartu_piutang.php?customer_id=<?= urlencode($customer_id) ?>&start_date=<?= urlencode($start_date_display) ?>&end_date=<?= urlencode($end_date_display) ?>"
               target="_blank">
                <span class="app-icon"><?= appIcon('print') ?></span>
                Cetak Kartu Piutang
            </a>
        <?php else: ?>
            <a class="btn-vs btn-disabled" href="javascript:void(0);">
                <span class="app-icon"><?= appIcon('print') ?></span>
                Cetak Kartu Piutang
            </a>
        <?php endif; ?>
    </div>

    <div class="filter-card">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="kartu_piutang">

            <div class="filter-grid">
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('customer') ?></span> Customer</label>
                   <select name="customer_id" id="customer_id" class="select2-customer" required>
                        <option value="">-- Ketik / Pilih Customer --</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?= h($cust['customer_id']) ?>" <?= $customer_id === $cust['customer_id'] ? 'selected' : '' ?>>
                                <?= h($cust['customer_id'] . ' - ' . $cust['customer']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Start Date</label>
                    <input type="text" name="start_date" class="js-date-picker" value="<?= h($start_date_display) ?>" autocomplete="off">
                </div>

                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> End Date</label>
                    <input type="text" name="end_date" class="js-date-picker" value="<?= h($end_date_display) ?>" autocomplete="off">
                </div>

                <div style="display:flex;gap:6px;">
                    <button type="submit" class="btn-vs btn-dark">
                        <span class="app-icon"><?= appIcon('search') ?></span>
                        Cari
                    </button>
                    <a href="index.php?page=kartu_piutang" class="btn-vs btn-secondary">
                        <span class="app-icon"><?= appIcon('reset') ?></span>
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <?php if ($customer_id === ''): ?>
        <div class="customer-info text-center" style="color:#777;">
            Silakan pilih customer dan periode terlebih dahulu.
        </div>
    <?php else: ?>
        <div class="customer-info">
            <div><b>Periode:</b> <?= h($start_date_display) ?> s/d <?= h($end_date_display) ?></div>
            <div><b>Area:</b> <?= h($customerData['area_name'] ?? '') ?></div>
            <div><b>Customer ID:</b> <?= h($customerData['customer_id'] ?? '') ?></div>
            <div><b>Nama Customer:</b> <?= h($customerData['customer'] ?? '') ?></div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="label">Saldo Awal</div>
                <div class="value">Rp <?= h(formatMoney($saldo_awal)) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Penjualan</div>
                <div class="value">Rp <?= h(formatMoney($total_penjualan)) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Pembayaran</div>
                <div class="value">Rp <?= h(formatMoney($total_pembayaran)) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Saldo Akhir</div>
                <div class="value">Rp <?= h(formatMoney($saldo_akhir)) ?></div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="kartu-table">
                <thead>
                    <tr>
                      <th>SALDO AWAL</th>
                    <th>TANGGAL</th>
                    <th>SHIPPING NO.</th>
                    <th>NO. BAYAR</th>
                    <th>PENJUALAN</th>
                    <th>PEMBAYARAN</th>
                    <th>SISA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="7" class="text-center" style="color:#777;padding:15px;">
                                Tidak ada data piutang pada periode ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $i => $row): ?>
                            <tr>
                                <td class="money-cell">
                                    <?= $i === 0 ? 'Rp ' . h(formatMoney($saldo_awal)) : '' ?>
                                </td>
                                <td class="text-center"><?= h(formatDateDisplay($row['trans_date'])) ?></td>
                                <td class="text-bold text-blue"><?= h($row['shipping_no']) ?></td>
                                <td class="text-center"><?= h($row['bayar_no']) ?></td>
                                <td class="money-cell">
                                    <?= ((float)$row['penjualan'] > 0) ? 'Rp ' . h(formatMoney($row['penjualan'])) : '' ?>
                                </td>
                                <td class="money-cell">
                                    <?= ((float)$row['pembayaran'] > 0) ? 'Rp ' . h(formatMoney($row['pembayaran'])) : '' ?>
                                </td>
                                <td class="money-cell text-bold">Rp <?= h(formatMoney($row['sisa'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" class="text-right">TOTAL</td>
                        <td class="money-cell">Rp <?= h(formatMoney($total_penjualan)) ?></td>
                        <td class="money-cell">Rp <?= h(formatMoney($total_pembayaran)) ?></td>
                        <td class="money-cell">Rp <?= h(formatMoney($saldo_akhir)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>


<script>
if (typeof flatpickr !== 'undefined') {
    flatpickr('.js-date-picker', {
        dateFormat: 'd-M-Y',
        allowInput: true,
        disableMobile: true
    });
}

$(document).ready(function () {
    $('.select2-customer').select2({
        placeholder: '-- Ketik / Pilih Customer --',
        allowClear: true,
        width: '100%'
    });
});
</script>
