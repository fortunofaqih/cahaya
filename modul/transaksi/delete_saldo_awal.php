<?php
// modul/transaksi/delete_saldo_awal.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    header('Location: ../../login.php');
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

function redirectWithAlert($type, $message) {
    $class = $type === 'success'
        ? 'alert alert-success'
        : 'alert alert-danger';

    $_SESSION['alert'] =
        '<div class="' . $class . '" style="padding:10px;margin-bottom:10px;border-radius:4px;">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '</div>';

    header('Location: ../../index.php?page=saldo_awal');
    exit;
}

$opening_id = isset($_GET['opening_id'])
    ? (int)$_GET['opening_id']
    : 0;

if ($opening_id <= 0) {
    redirectWithAlert(
        'error',
        'Opening ID tidak valid.'
    );
}

try {

    mysqli_begin_transaction($conn);

    /*
     * Pastikan data benar-benar ada sebelum dihapus.
     */
    $stmtCheck = mysqli_prepare(
        $conn,
        "
        SELECT
            opening_id,
            customer_id,
            customer_name
        FROM customer_opening_balance
        WHERE opening_id = ?
        LIMIT 1
        FOR UPDATE
        "
    );

    mysqli_stmt_bind_param(
        $stmtCheck,
        'i',
        $opening_id
    );

    mysqli_stmt_execute($stmtCheck);

    $resCheck =
        mysqli_stmt_get_result($stmtCheck);

    $data =
        mysqli_fetch_assoc($resCheck);

    mysqli_stmt_close($stmtCheck);

    if (!$data) {
        throw new Exception(
            'Data saldo awal tidak ditemukan.'
        );
    }

    /*
     * Hard delete sesuai pola tombol Delete pada saldo_awal.php.
     * Setelah dihapus, customer dapat diinput ulang melalui Add Saldo Awal.
     */
    $stmtDelete = mysqli_prepare(
        $conn,
        "
        DELETE FROM customer_opening_balance
        WHERE opening_id = ?
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param(
        $stmtDelete,
        'i',
        $opening_id
    );

    mysqli_stmt_execute($stmtDelete);

    mysqli_stmt_close($stmtDelete);

    mysqli_commit($conn);

    redirectWithAlert(
        'success',
        'Saldo awal customer berhasil dihapus.'
    );

} catch (Throwable $e) {

    try {
        mysqli_rollback($conn);
    } catch (Throwable $rollbackError) {
        // Abaikan rollback error.
    }

    redirectWithAlert(
        'error',
        'Gagal menghapus saldo awal: ' . $e->getMessage()
    );
}
