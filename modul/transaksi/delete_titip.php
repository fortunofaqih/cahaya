<?php
// modul/transaksi/delete_titip.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function redirectWithAlert($type, $message) {
    $_SESSION['alert'] = "
        <div style='padding:10px;margin-bottom:10px;border-radius:4px;background:" . ($type === 'success' ? '#d1e7dd' : '#f8d7da') . ";color:" . ($type === 'success' ? '#0f5132' : '#842029') . ";border:1px solid " . ($type === 'success' ? '#badbcc' : '#f5c2c7') . ";'>
            " . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "
        </div>
    ";
    header("Location: ../../index.php?page=titip_uang");
    exit;
}

$titip_no = trim((string)($_GET['titip_no'] ?? ''));

if ($titip_no === '') {
    redirectWithAlert('error', 'No. Titip tidak ditemukan.');
}

mysqli_begin_transaction($conn);

try {
    $sqlCheck = "
        SELECT used_amount
        FROM head_titip
        WHERE titip_no = ?
        LIMIT 1
    ";
    $stmtCheck = mysqli_prepare($conn, $sqlCheck);
    mysqli_stmt_bind_param($stmtCheck, 's', $titip_no);
    mysqli_stmt_execute($stmtCheck);
    $resCheck = mysqli_stmt_get_result($stmtCheck);
    $data = mysqli_fetch_assoc($resCheck);
    mysqli_stmt_close($stmtCheck);

    if (!$data) {
        throw new Exception('Data titip uang tidak ditemukan.');
    }

    if ((float)$data['used_amount'] > 0) {
        throw new Exception('Titip uang tidak bisa dihapus karena sudah pernah digunakan.');
    }

    $sqlDelete = "DELETE FROM head_titip WHERE titip_no = ?";
    $stmtDelete = mysqli_prepare($conn, $sqlDelete);
    mysqli_stmt_bind_param($stmtDelete, 's', $titip_no);
    mysqli_stmt_execute($stmtDelete);
    mysqli_stmt_close($stmtDelete);

    mysqli_commit($conn);

    redirectWithAlert('success', 'Titip uang berhasil dihapus.');

} catch (Exception $e) {
    mysqli_rollback($conn);
    redirectWithAlert('error', $e->getMessage());
}