<?php
// modul/transaksi/update_titip.php

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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithAlert('error', 'Invalid request.');
}

$titip_no = trim((string)($_POST['titip_no'] ?? ''));
$titip_date = parseDateInput($_POST['titip_date'] ?? '');
$total_titip = parseNumber($_POST['total_titip'] ?? 0);
$keterangan = trim((string)($_POST['keterangan'] ?? ''));
$bank_name = trim((string)($_POST['bank_name'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$username = $_SESSION['username'] ?? 'system';

if ($titip_no === '' || !$titip_date || $total_titip <= 0 || $keterangan === '') {
    redirectWithAlert('error', 'Data titip uang belum lengkap.', 'edit_titip&titip_no=' . urlencode($titip_no));
}

mysqli_begin_transaction($conn);

try {
    $sqlOld = "
        SELECT *
        FROM head_titip
        WHERE titip_no = ?
        LIMIT 1
    ";
    $stmtOld = mysqli_prepare($conn, $sqlOld);
    mysqli_stmt_bind_param($stmtOld, 's', $titip_no);
    mysqli_stmt_execute($stmtOld);
    $resOld = mysqli_stmt_get_result($stmtOld);
    $old = mysqli_fetch_assoc($resOld);
    mysqli_stmt_close($stmtOld);

    if (!$old) {
        throw new Exception('Data titip uang tidak ditemukan.');
    }

    $used_amount = (float)$old['used_amount'];

    if ($total_titip < $used_amount) {
        throw new Exception('Jumlah titip tidak boleh lebih kecil dari nominal yang sudah terpakai.');
    }

    $balance_amount = $total_titip - $used_amount;
    $status = $balance_amount <= 0 ? 'Closed' : 'Open';

    $sqlHead = "
        UPDATE head_titip
        SET
            titip_date = ?,
            total_titip = ?,
            balance_amount = ?,
            keterangan = ?,
            bank_name = ?,
            remarks = ?,
            status = ?,
            user_modified = ?,
            date_modified = NOW()
        WHERE titip_no = ?
    ";
    $stmtHead = mysqli_prepare($conn, $sqlHead);
    mysqli_stmt_bind_param(
        $stmtHead,
        'sddssssss',
        $titip_date,
        $total_titip,
        $balance_amount,
        $keterangan,
        $bank_name,
        $remarks,
        $status,
        $username,
        $titip_no
    );
    mysqli_stmt_execute($stmtHead);
    mysqli_stmt_close($stmtHead);

    $sqlDetail = "
        UPDATE detail_titip
        SET
            titip_date = ?,
            amount_in = ?,
            balance_after = ?,
            keterangan = ?,
            bank_name = ?,
            remarks = ?,
            user_modified = ?,
            date_modified = NOW()
        WHERE titip_no = ?
          AND transaction_type = 'TITIP'
          AND ref_no = ?
    ";
    $stmtDetail = mysqli_prepare($conn, $sqlDetail);
    mysqli_stmt_bind_param(
        $stmtDetail,
        'sddssssss',
        $titip_date,
        $total_titip,
        $balance_amount,
        $keterangan,
        $bank_name,
        $remarks,
        $username,
        $titip_no,
        $titip_no
    );
    mysqli_stmt_execute($stmtDetail);
    mysqli_stmt_close($stmtDetail);

    mysqli_commit($conn);

    redirectWithAlert('success', 'Titip uang berhasil diupdate.', 'titip_uang');

} catch (Exception $e) {
    mysqli_rollback($conn);
    redirectWithAlert('error', $e->getMessage(), 'edit_titip&titip_no=' . urlencode($titip_no));
}