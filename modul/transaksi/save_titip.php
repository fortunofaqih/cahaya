<?php
// modul/transaksi/save_titip.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function redirectWithAlert($type, $message, $page = 'titip_uang') {
    $_SESSION['alert'] = "
        <div style='padding:10px;margin-bottom:10px;border-radius:4px;background:" . ($type === 'success' ? '#d1e7dd' : '#f8d7da') . ";color:" . ($type === 'success' ? '#0f5132' : '#842029') . ";border:1px solid " . ($type === 'success' ? '#badbcc' : '#f5c2c7') . ";'>
            " . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "
        </div>
    ";
    header("Location: ../../index.php?page=" . $page);
    exit;
}

function parseDateInput($value) {
    $value = trim((string)$value);
    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) return $dt->format('Y-m-d');
    }
    return null;
}

function parseNumber($value) {
    $value = trim((string)$value);
    $value = str_replace(['Rp', ' ', '.'], '', $value);
    $value = str_replace(',', '.', $value);
    return (float)$value;
}

function generateTitipNo($conn) {
    $prefix = 'T-';
    $sql = "
        SELECT titip_no 
        FROM head_titip 
        WHERE titip_no LIKE 'T-%'
        ORDER BY CAST(SUBSTRING(titip_no, 3) AS UNSIGNED) DESC
        LIMIT 1
        FOR UPDATE
    ";
    $res = mysqli_query($conn, $sql);
    $lastNumber = 0;

    if ($res && $row = mysqli_fetch_assoc($res)) {
        $lastNumber = (int)substr($row['titip_no'], 2);
    }

    return $prefix . str_pad($lastNumber + 1, 9, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithAlert('error', 'Invalid request.');
}

$titip_date = parseDateInput($_POST['titip_date'] ?? '');
$customer_id = trim((string)($_POST['customer_id'] ?? ''));
$customer_name = trim((string)($_POST['customer_name'] ?? ''));
$customer_address = trim((string)($_POST['customer_address'] ?? ''));
$customer_city = trim((string)($_POST['customer_city'] ?? ''));
$total_titip = parseNumber($_POST['total_titip'] ?? 0);
$keterangan = trim((string)($_POST['keterangan'] ?? ''));
$bank_name = trim((string)($_POST['bank_name'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$username = $_SESSION['username'] ?? 'system';

if (!$titip_date || $customer_id === '' || $customer_name === '' || $total_titip <= 0 || $keterangan === '') {
    redirectWithAlert('error', 'Data titip uang belum lengkap.', 'add_titip');
}

mysqli_begin_transaction($conn);

try {
    $titip_no = generateTitipNo($conn);

    $sqlHead = "
        INSERT INTO head_titip
        (
            titip_no,
            titip_date,
            customer_id,
            customer_name,
            customer_address,
            customer_city,
            total_titip,
            used_amount,
            balance_amount,
            keterangan,
            bank_name,
            remarks,
            status,
            create_user,
            date_created
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, 'Open', ?, NOW())
    ";
    $stmtHead = mysqli_prepare($conn, $sqlHead);
    mysqli_stmt_bind_param(
        $stmtHead,
        'ssssssddssss',
        $titip_no,
        $titip_date,
        $customer_id,
        $customer_name,
        $customer_address,
        $customer_city,
        $total_titip,
        $total_titip,
        $keterangan,
        $bank_name,
        $remarks,
        $username
    );
    mysqli_stmt_execute($stmtHead);
    mysqli_stmt_close($stmtHead);

    $sqlDetail = "
        INSERT INTO detail_titip
        (
            titip_no,
            titip_date,
            customer_id,
            customer_name,
            transaction_type,
            ref_no,
            amount_in,
            amount_out,
            balance_after,
            keterangan,
            bank_name,
            remarks,
            create_user,
            date_created
        )
        VALUES (?, ?, ?, ?, 'TITIP', ?, ?, 0, ?, ?, ?, ?, ?, NOW())
    ";
    $stmtDetail = mysqli_prepare($conn, $sqlDetail);
    $ref_no = $titip_no;
    mysqli_stmt_bind_param(
        $stmtDetail,
        'sssssddssss',
        $titip_no,
        $titip_date,
        $customer_id,
        $customer_name,
        $ref_no,
        $total_titip,
        $total_titip,
        $keterangan,
        $bank_name,
        $remarks,
        $username
    );
    mysqli_stmt_execute($stmtDetail);
    mysqli_stmt_close($stmtDetail);

    mysqli_commit($conn);

    redirectWithAlert('success', 'Titip uang berhasil disimpan dengan No. Titip ' . $titip_no . '.', 'titip_uang');

} catch (Exception $e) {
    mysqli_rollback($conn);
    redirectWithAlert('error', $e->getMessage(), 'add_titip');
}