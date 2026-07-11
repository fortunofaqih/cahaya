<?php
// modul/transaksi/detail_titip.php

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

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

$customer_id = trim((string)($_GET['customer_id'] ?? ''));

if ($customer_id === '') {
    echo "<script>alert('Customer belum dipilih.'); window.location.href='index.php?page=titip_uang';</script>";
    exit;
}

$sqlCustomer = "
    SELECT customer_id, customer, address, city
    FROM m_customer
    WHERE customer_id = ?
    LIMIT 1
";
$stmtCustomer = mysqli_prepare($conn, $sqlCustomer);
mysqli_stmt_bind_param($stmtCustomer, 's', $customer_id);
mysqli_stmt_execute($stmtCustomer);
$resCustomer = mysqli_stmt_get_result($stmtCustomer);
$customer = mysqli_fetch_assoc($resCustomer);
mysqli_stmt_close($stmtCustomer);

$sql = "
    SELECT
        titip_no,
        titip_date,
        transaction_type,
        ref_no,
        amount_in,
        amount_out,
        balance_after,
        keterangan,
        bank_name,
        remarks
    FROM detail_titip
    WHERE customer_id = ?
    ORDER BY titip_date ASC, id ASC
";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $customer_id);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$rows = [];
$total_in = 0;
$total_out = 0;

while ($row = mysqli_fetch_assoc($res)) {
    $rows[] = $row;
    $total_in += (float)$row['amount_in'];
    $total_out += (float)$row['amount_out'];
}

mysqli_stmt_close($stmt);

$saldo_titip = $total_in - $total_out;
?>

<style>
.detail-titip-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.detail-titip-wrap {
    background: #f0f2f5;
    padding: 12px;
    color: #212529;
    font-size: 11px;
}
.crystal-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: #fff;
    padding: 10px 15px;
    border-radius: 5px;
}
.info-card,
.table-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin-top: 10px;
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
    min-height: 30px;
}
.btn-secondary { background: #6c757d; color: #fff; }
.detail-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 9.5px;
}
.detail-table th {
    background: #e9ecef;
    color: #2b4c7e;
    border: 1px solid #c0cddb;
    padding: 5px 4px;
    text-align: center;
}
.detail-table td {
    border: 1px solid #d3d3d3;
    padding: 4px;
}
.text-center { text-align: center; }
.text-right { text-align: right; }
.text-bold { font-weight: bold; }
.money-cell {
    text-align: right;
    font-family: Arial, Helvetica, sans-serif;
    font-variant-numeric: tabular-nums;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(160px, 1fr));
    gap: 8px;
    margin-top: 10px;
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
}
.summary-card .value {
    font-size: 13px;
    font-weight: 800;
    color: #1e3c72;
}
</style>

<div class="detail-titip-wrap">
    <div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h5 style="margin:0;">Detail Riwayat Titip Uang</h5>
        <a class="btn-vs btn-secondary" href="index.php?page=titip_uang">Kembali</a>
    </div>

    <div class="info-card">
        <div><b>Customer ID:</b> <?= h($customer['customer_id'] ?? $customer_id) ?></div>
        <div><b>Nama Customer:</b> <?= h($customer['customer'] ?? '') ?></div>
        <div><b>City:</b> <?= h($customer['city'] ?? '') ?></div>
        <div><b>Address:</b> <?= h($customer['address'] ?? '') ?></div>
    </div>

    <div class="summary-grid">
        <div class="summary-card">
            <div class="label">Total Titip</div>
            <div class="value">Rp <?= h(formatMoney($total_in)) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Terpakai</div>
            <div class="value">Rp <?= h(formatMoney($total_out)) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Sisa Titip</div>
            <div class="value">Rp <?= h(formatMoney($saldo_titip)) ?></div>
        </div>
    </div>

    <div class="table-card">
        <table class="detail-table">
            <thead>
                <tr>
                    <th>No</th>
                    <th>Tanggal</th>
                    <th>No. Titip</th>
                    <th>Type</th>
                    <th>Ref No</th>
                    <th>Masuk</th>
                    <th>Keluar</th>
                    <th>Saldo</th>
                    <th>Keterangan</th>
                    <th>Bank</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="11" class="text-center" style="padding:15px;color:#777;">
                            Belum ada riwayat titip uang.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <tr>
                            <td class="text-center"><?= $i + 1 ?></td>
                            <td class="text-center"><?= h(formatDateDisplay($row['titip_date'])) ?></td>
                            <td><?= h($row['titip_no']) ?></td>
                            <td class="text-center"><?= h($row['transaction_type']) ?></td>
                            <td><?= h($row['ref_no']) ?></td>
                            <td class="money-cell"><?= (float)$row['amount_in'] > 0 ? 'Rp ' . h(formatMoney($row['amount_in'])) : '' ?></td>
                            <td class="money-cell"><?= (float)$row['amount_out'] > 0 ? 'Rp ' . h(formatMoney($row['amount_out'])) : '' ?></td>
                            <td class="money-cell text-bold">Rp <?= h(formatMoney($row['balance_after'])) ?></td>
                            <td class="text-center"><?= h($row['keterangan']) ?></td>
                            <td><?= h($row['bank_name']) ?></td>
                            <td><?= h($row['remarks']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>