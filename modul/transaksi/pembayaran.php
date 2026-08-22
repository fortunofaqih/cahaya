<?php
// modul/transaksi/pembayaran.php

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
    if ($value === '') return $fallback;

    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) return $dt->format('Y-m-d');
    }

    return $fallback;
}

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function appIcon($name) {
    $icons = [
        'payment' => '<svg viewBox="0 0 24 24"><path d="M3 5h18v14H3V5Zm2 4h14V7H5v2Zm0 3v5h14v-5H5Zm2 2h5v2H7v-2Z"/></svg>',
        'search' => '<svg viewBox="0 0 24 24"><path d="M10.5 4a6.5 6.5 0 0 1 5.18 10.43l4.45 4.44-1.42 1.42-4.44-4.45A6.5 6.5 0 1 1 10.5 4Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z"/></svg>',
        'reset' => '<svg viewBox="0 0 24 24"><path d="M12 5a7 7 0 1 1-6.33 10H7.9A5 5 0 1 0 12 7H8.83l2.58 2.59L10 11 5 6l5-5 1.41 1.41L8.83 5H12Z"/></svg>',
        'add' => '<svg viewBox="0 0 24 24"><path d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2h6Z"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24"><path d="M5 19h1.4L16.7 8.7l-1.4-1.4L5 17.6V19Zm-2 2v-4.25L16.7 3.05a1 1 0 0 1 1.4 0l2.85 2.85a1 1 0 0 1 0 1.4L7.25 21H3Z"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24"><path d="M7 21a2 2 0 0 1-2-2V7H4V5h5V3h6v2h5v2h-1v12a2 2 0 0 1-2 2H7Zm10-14H7v12h10V7ZM9 9h2v8H9V9Zm4 0h2v8h-2V9Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'customer' => '<svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4 22a8 8 0 0 1 16 0h-2a6 6 0 0 0-12 0H4Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}

$defaultStart = date('Y-01-01');
$defaultEnd = date('Y-m-d');

$start_date = parseReportDate($_GET['start_date'] ?? '', $defaultStart);
$end_date = parseReportDate($_GET['end_date'] ?? '', $defaultEnd);

if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$customer_id = trim((string)($_GET['customer_id'] ?? ''));

// ============================================================
// MASTER CUSTOMER
// ============================================================
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

// ============================================================
// SALDO TITIP AKTIF PER CUSTOMER
// Dipakai hanya 1x per customer agar tidak double.
// ============================================================
$titipBalances = [];
$sqlTitip = "
    SELECT
        customer_id,
        MAX(customer_name) AS customer_name,
        SUM(COALESCE(balance_amount, 0)) AS saldo_titip
    FROM head_titip
    WHERE COALESCE(balance_amount, 0) > 0
    GROUP BY customer_id
    HAVING SUM(COALESCE(balance_amount, 0)) > 0
";
$resTitip = mysqli_query($conn, $sqlTitip);
if ($resTitip) {
    while ($row = mysqli_fetch_assoc($resTitip)) {
        $cid = trim((string)($row['customer_id'] ?? ''));
        if ($cid === '') continue;

        $titipBalances[$cid] = [
            'customer_name' => (string)($row['customer_name'] ?? ''),
            'saldo_titip'   => (float)($row['saldo_titip'] ?? 0),
        ];
    }
}

// ============================================================
// DATA PEMBAYARAN
// REVISI:
// - Pembayaran yang memiliki Retur tetap ditampilkan.
// - Satu bayar_no dapat memiliki banyak detail_bayar.
// - Retur standalone disimpan hanya pada salah satu detail_bayar,
//   sehingga nanti di level tampilan cukup diambil return_id non-empty.
// ============================================================
$where = "
    WHERE hb.bayar_date BETWEEN ? AND ?
";
$params = [$start_date, $end_date];
$types = "ss";

if ($customer_id !== '') {
    $where .= " AND hb.customer_id = ? ";
    $params[] = $customer_id;
    $types .= "s";
}

$sql = "
    SELECT
        hb.bayar_no,
        hb.bayar_date,
        hb.customer_id,
        hb.customer_name,
        hb.customer_city,
        hb.total_bayar,
        hb.keterangan,
        hb.bank_name,
        COALESCE(db.shipping_no, di.shipping_no, '') AS shipping_no,
        COALESCE(db.opening_id, 0) AS opening_id,
        COALESCE(cob.opening_balance, 0) AS opening_balance,
        COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE') AS payment_source,
        COALESCE(TRIM(db.return_id), '') AS return_id,
        COALESCE(db.cash_amount, 0) AS cash_amount,
        COALESCE(db.titip_amount, 0) AS titip_amount_used,
        COALESCE(db.bayar_amount, 0) AS bayar_amount
    FROM head_bayar hb
    LEFT JOIN detail_bayar db ON db.bayar_no = hb.bayar_no
    LEFT JOIN customer_opening_balance cob
        ON cob.opening_id = db.opening_id
    LEFT JOIN (
        SELECT
            invoice_no,
            GROUP_CONCAT(DISTINCT shipping_no ORDER BY shipping_no SEPARATOR ', ') AS shipping_no
        FROM det_invoice
        GROUP BY invoice_no
    ) di ON di.invoice_no = db.invoice_no
    $where
    ORDER BY hb.bayar_date DESC, hb.bayar_no DESC, db.id ASC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die('<div class="alert alert-danger">SQL Pembayaran Error: ' . h(mysqli_error($conn)) . '</div>');
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

// ============================================================
// GABUNGKAN DETAIL BAYAR MENJADI 1 BARIS PER bayar_no
// supaya nominal head_bayar tidak ikut double jika bayar memiliki
// lebih dari satu detail invoice/shipping.
// ============================================================
$paymentRows = [];

while ($row = mysqli_fetch_assoc($res)) {
    $bayarNo = (string)($row['bayar_no'] ?? '');
    if ($bayarNo === '') continue;

    if (!isset($paymentRows[$bayarNo])) {
        $paymentRows[$bayarNo] = [
            'bayar_no'       => $bayarNo,
            'bayar_date'     => $row['bayar_date'],
            'customer_id'    => (string)($row['customer_id'] ?? ''),
            'customer_name'  => (string)($row['customer_name'] ?? ''),
            'customer_city'  => (string)($row['customer_city'] ?? ''),
            'total_bayar'    => (float)($row['total_bayar'] ?? 0),
            'keterangan'     => (string)($row['keterangan'] ?? ''),
            'bank_name'      => (string)($row['bank_name'] ?? ''),
            'shipping_arr'   => [],
            'source_arr'     => [],
            'return_id'      => '',
            'cash_amount'    => 0.0,
            'bayar_amount'   => 0.0,
            'titip_display'  => 0.0,
            'is_titip_only'  => false,
        ];
    }

    $paymentSource = strtoupper(
        trim((string)($row['payment_source'] ?? 'INVOICE'))
    );
    if ($paymentSource === '') $paymentSource = 'INVOICE';

    if ($paymentSource === 'OPENING') {
        $paymentSource =
            (float)($row['opening_balance'] ?? 0) < 0
                ? 'OPENING (-)'
                : 'OPENING (+)';
    }

    $paymentRows[$bayarNo]['source_arr'][$paymentSource] = true;

    $returnId = trim((string)($row['return_id'] ?? ''));
    if (
        $returnId !== '' &&
        $paymentRows[$bayarNo]['return_id'] === ''
    ) {
        $paymentRows[$bayarNo]['return_id'] = $returnId;
    }

    $shippingNo = trim((string)($row['shipping_no'] ?? ''));
    if ($shippingNo !== '') {
        foreach (array_map('trim', explode(',', $shippingNo)) as $ship) {
            if ($ship !== '') {
                $paymentRows[$bayarNo]['shipping_arr'][$ship] = true;
            }
        }
    }

    // Detail dijumlahkan karena 1 bayar_no dapat mempunyai beberapa detail.
    $paymentRows[$bayarNo]['cash_amount'] += (float)($row['cash_amount'] ?? 0);
    $paymentRows[$bayarNo]['bayar_amount'] += (float)($row['bayar_amount'] ?? 0);
}
mysqli_stmt_close($stmt);

// ============================================================
// Tentukan baris pembayaran terbaru per customer.
// Saldo titip hanya ditempel sekali di sini agar tidak double.
// ============================================================
$latestPaymentByCustomer = [];
foreach ($paymentRows as $bayarNo => $row) {
    $cid = trim((string)$row['customer_id']);
    if ($cid === '') continue;

    if (!isset($latestPaymentByCustomer[$cid])) {
        $latestPaymentByCustomer[$cid] = $bayarNo;
    }
}

$rows = [];
$customersWithPayment = [];

foreach ($paymentRows as $bayarNo => $row) {
    $cid = trim((string)$row['customer_id']);
    if ($cid !== '') {
        $customersWithPayment[$cid] = true;
    }

    $row['shipping_no'] = implode(', ', array_keys($row['shipping_arr']));

    $sources = array_keys($row['source_arr']);
    $row['payment_source'] = implode(', ', $sources);

    if (in_array('OPENING (+)', $sources, true) || in_array('OPENING (-)', $sources, true)) {
        $row['shipping_no'] =
            trim($row['shipping_no']) !== ''
                ? $row['shipping_no'] . ', SALDO AWAL'
                : 'SALDO AWAL';
    }

    unset($row['shipping_arr'], $row['source_arr']);

    // Titip yang tampil adalah SALDO YANG BELUM DIPAKAI,
    // bukan db.titip_amount (karena db.titip_amount adalah titip yang SUDAH dipakai).
    // Saldo hanya tampil sekali di pembayaran terbaru customer.
    if (
        $cid !== '' &&
        isset($latestPaymentByCustomer[$cid]) &&
        $latestPaymentByCustomer[$cid] === $bayarNo &&
        isset($titipBalances[$cid])
    ) {
        $row['titip_display'] = max(0, (float)$titipBalances[$cid]['saldo_titip']);
    } else {
        $row['titip_display'] = 0.0;
    }

    $rows[] = $row;
}

// ============================================================
// PENDEKATAN 2:
// Customer yang MASIH mempunyai saldo titip tetapi tidak mempunyai
// pembayaran pada periode filter tetap ditampilkan sebagai baris khusus.
// ============================================================
foreach ($titipBalances as $cid => $titipInfo) {
    if ($customer_id !== '' && $customer_id !== $cid) {
        continue;
    }

    if (isset($customersWithPayment[$cid])) {
        continue;
    }

    $saldoTitip = max(0, (float)($titipInfo['saldo_titip'] ?? 0));
    if ($saldoTitip <= 0) {
        continue;
    }

    // Ambil nama/city customer dari master bila tersedia.
    $customerName = (string)($titipInfo['customer_name'] ?? '');
    $customerCity = '';

    $stmtCustInfo = mysqli_prepare($conn, "
        SELECT customer, city
        FROM m_customer
        WHERE customer_id = ?
        LIMIT 1
    ");
    if ($stmtCustInfo) {
        mysqli_stmt_bind_param($stmtCustInfo, 's', $cid);
        mysqli_stmt_execute($stmtCustInfo);
        $resCustInfo = mysqli_stmt_get_result($stmtCustInfo);
        if ($custInfo = mysqli_fetch_assoc($resCustInfo)) {
            if (trim((string)($custInfo['customer'] ?? '')) !== '') {
                $customerName = (string)$custInfo['customer'];
            }
            $customerCity = (string)($custInfo['city'] ?? '');
        }
        mysqli_stmt_close($stmtCustInfo);
    }

    $rows[] = [
        'bayar_no'      => '-',
        'bayar_date'    => '',
        'customer_id'   => $cid,
        'customer_name' => $customerName,
        'customer_city' => $customerCity,
        'total_bayar'   => 0.0,
        'keterangan'    => 'Saldo titip belum digunakan',
        'bank_name'     => '',
        'shipping_no'   => '-',
        'payment_source'=> 'TITIP',
        'return_id'     => '',
        'cash_amount'   => 0.0,
        'bayar_amount'  => 0.0,
        'titip_display' => $saldoTitip,
        'is_titip_only' => true,
    ];
}

// ============================================================
// TOTAL FOOTER
// ============================================================
$total_bayar = 0.0;
$total_cash = 0.0;
$total_titip = 0.0;
$total_bayar_detail = 0.0;

foreach ($rows as $row) {
    $total_bayar += (float)$row['total_bayar'];
    $total_cash += (float)$row['cash_amount'];
    $total_titip += (float)$row['titip_display'];
    $total_bayar_detail += (float)$row['bayar_amount'];
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
.pembayaran-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.pembayaran-wrap {
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
    grid-template-columns: 1fr 1fr 2fr auto;
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
.btn-success { background: #198754; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-warning { background: #ffc107; color: #000; }
.btn-danger { background: #dc3545; color: #fff; }
.btn-dark { background: #212529; color: #fff; }

.table-wrap {
    max-height: 560px;
    overflow: auto;
    border: 1px solid #c0cddb;
    background: #fff;
}
.pay-table {
    width: 100%;
    min-width: 1150px;
    border-collapse: collapse;
    font-size: 9.5px;
}
.pay-table th {
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
.pay-table td {
    border: 1px solid #d3d3d3;
    padding: 4px 4px;
    vertical-align: middle;
    white-space: nowrap;
}
.pay-table tbody tr:hover td {
    background: #e8f2fe;
}
.pay-table tfoot td {
    background: #f8f9fa;
    font-weight: bold;
}
.row-titip-only td {
    background: #fff8e1;
}
.text-center { text-align: center; }
.text-right { text-align: right; }
.text-bold { font-weight: bold; }
.money-cell {
    text-align: right;
    font-family: Arial, Helvetica, sans-serif;
    font-variant-numeric: tabular-nums;
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
@media (max-width: 900px) {
    .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
}
</style>

<div class="pembayaran-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('payment') ?></span>
            Pembayaran Piutang
        </h5>

        <a class="btn-vs btn-success" href="index.php?page=add_bayar">
            <span class="app-icon"><?= appIcon('add') ?></span>
            Add Pembayaran
        </a>
    </div>

    <div class="filter-card">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="pembayaran">

            <div class="filter-grid">
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Start Date</label>
                    <input type="text" name="start_date" class="js-date-picker" value="<?= h(formatDateDisplay($start_date)) ?>" autocomplete="off">
                </div>

                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> End Date</label>
                    <input type="text" name="end_date" class="js-date-picker" value="<?= h(formatDateDisplay($end_date)) ?>" autocomplete="off">
                </div>

                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('customer') ?></span> Nama Pelanggan</label>
                    <select name="customer_id" class="select2-customer">
                        <option value="">-- Semua Customer --</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?= h($cust['customer_id']) ?>" <?= $customer_id === $cust['customer_id'] ? 'selected' : '' ?>>
                                <?= h($cust['customer_id'] . ' - ' . $cust['customer']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:flex;gap:6px;">
                    <button type="submit" class="btn-vs btn-dark">
                        <span class="app-icon"><?= appIcon('search') ?></span>
                        Cari
                    </button>
                    <a href="index.php?page=pembayaran" class="btn-vs btn-secondary">
                        <span class="app-icon"><?= appIcon('reset') ?></span>
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="pay-table">
            <thead>
                <tr>
                    <th style="width:40px;">No</th>
                    <th>Tanggal</th>
                    <th>No. Bayar</th>
                    <th>No. Retur</th>
                    
                    <th>Shipping No.</th>
                    <th>Customer ID</th>
                    <th>Nama Customer</th>
                    <th>City</th>
                    <th>Keterangan</th>
                    <th>Bank</th>
                    <th>Nominal Bayar</th>
                    <th>Cash/Transfer</th>
                    <th>Titip</th>
                    <th>Total Bayar</th>
                    <th style="width:120px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="16" class="text-center" style="padding:15px;color:#777;">
                            Tidak ada data pembayaran / saldo titip.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <?php $isTitipOnly = !empty($row['is_titip_only']); ?>
                        <tr class="<?= $isTitipOnly ? 'row-titip-only' : '' ?>">
                            <td class="text-center"><?= $i + 1 ?></td>
                            <td class="text-center"><?= h(formatDateDisplay($row['bayar_date'])) ?></td>
                            <td class="text-bold"><?= h($row['bayar_no']) ?></td>
                            <td class="text-bold">
                                <?= h(
                                    trim((string)($row['return_id'] ?? '')) !== ''
                                        ? $row['return_id']
                                        : '-'
                                ) ?>
                            </td>
                            
                            <td><?= h($row['shipping_no']) ?></td>
                            <td><?= h($row['customer_id']) ?></td>
                            <td><?= h($row['customer_name']) ?></td>
                            <td><?= h($row['customer_city']) ?></td>
                            <td class="text-center"><?= h($row['keterangan']) ?></td>
                            <td><?= h($row['bank_name']) ?></td>
                            <td class="money-cell">Rp <?= h(formatMoney($row['total_bayar'])) ?></td>
                            <td class="money-cell">Rp <?= h(formatMoney($row['cash_amount'])) ?></td>
                            <td class="money-cell">Rp <?= h(formatMoney($row['titip_display'])) ?></td>
                            <td class="money-cell">Rp <?= h(formatMoney($row['bayar_amount'])) ?></td>
                            <td class="text-center">
                                <?php if (!$isTitipOnly): ?>
                                    <a class="btn-vs btn-warning" href="index.php?page=edit_bayar&bayar_no=<?= urlencode($row['bayar_no']) ?>">
                                        <span class="app-icon"><?= appIcon('edit') ?></span>
                                        Edit
                                    </a>
                                    <a class="btn-vs btn-danger"
                                       href="modul/transaksi/delete_bayar.php?bayar_no=<?= urlencode($row['bayar_no']) ?>"
                                       onclick="return confirm('Yakin ingin menghapus pembayaran <?= h($row['bayar_no']) ?> ?')">
                                        <span class="app-icon"><?= appIcon('delete') ?></span>
                                        Delete
                                    </a>
                                <?php else: ?>
                                    <span style="color:#856404;font-weight:700;">Saldo Titip</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="11" class="text-right">TOTAL</td>
                    <td class="money-cell">Rp <?= h(formatMoney($total_bayar)) ?></td>
                    <td class="money-cell">Rp <?= h(formatMoney($total_cash)) ?></td>
                    <td class="money-cell">Rp <?= h(formatMoney($total_titip)) ?></td>
                    <td class="money-cell">Rp <?= h(formatMoney($total_bayar_detail)) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
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
        placeholder: '-- Pilih Customer --',
        allowClear: true,
        width: '100%'
    });
});
</script>