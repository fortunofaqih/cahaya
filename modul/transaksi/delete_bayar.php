<?php
// modul/transaksi/delete_bayar.php

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
    header("Location: ../../index.php?page=pembayaran");
    exit;
}
function rollbackTitipUsage($conn, $bayar_no, $username) {
    $sqlOldUsage = "
        SELECT titip_no, amount_out
        FROM detail_titip
        WHERE transaction_type = 'PAKAI'
          AND ref_no = ?
    ";
    $stmtOldUsage = mysqli_prepare($conn, $sqlOldUsage);
    mysqli_stmt_bind_param($stmtOldUsage, 's', $bayar_no);
    mysqli_stmt_execute($stmtOldUsage);
    $resOldUsage = mysqli_stmt_get_result($stmtOldUsage);

    while ($row = mysqli_fetch_assoc($resOldUsage)) {
        $titip_no = $row['titip_no'];
        $amount_out = (float)$row['amount_out'];

        $sqlReturn = "
            UPDATE head_titip
            SET
                used_amount = GREATEST(used_amount - ?, 0),
                balance_amount = balance_amount + ?,
                status = 'Open',
                user_modified = ?,
                date_modified = NOW()
            WHERE titip_no = ?
        ";
        $stmtReturn = mysqli_prepare($conn, $sqlReturn);
        mysqli_stmt_bind_param($stmtReturn, 'ddss', $amount_out, $amount_out, $username, $titip_no);
        mysqli_stmt_execute($stmtReturn);
        mysqli_stmt_close($stmtReturn);
    }

    mysqli_stmt_close($stmtOldUsage);

    $sqlDeleteUsage = "
        DELETE FROM detail_titip
        WHERE transaction_type = 'PAKAI'
          AND ref_no = ?
    ";
    $stmtDeleteUsage = mysqli_prepare($conn, $sqlDeleteUsage);
    mysqli_stmt_bind_param($stmtDeleteUsage, 's', $bayar_no);
    mysqli_stmt_execute($stmtDeleteUsage);
    mysqli_stmt_close($stmtDeleteUsage);
}

$bayar_no = trim((string)($_GET['bayar_no'] ?? ''));
$username = $_SESSION['username'] ?? 'system';

if ($bayar_no === '') {
    redirectWithAlert('error', 'No. Bayar tidak ditemukan.');
}

mysqli_begin_transaction($conn);

try {
    $sqlOld = "
        SELECT invoice_no
        FROM detail_bayar
        WHERE bayar_no = ?
        LIMIT 1
    ";
    $stmtOld = mysqli_prepare($conn, $sqlOld);
    mysqli_stmt_bind_param($stmtOld, 's', $bayar_no);
    mysqli_stmt_execute($stmtOld);
    $resOld = mysqli_stmt_get_result($stmtOld);
    $old = mysqli_fetch_assoc($resOld);
    mysqli_stmt_close($stmtOld);

    if (!$old) {
        throw new Exception('Data pembayaran tidak ditemukan.');
    }

    $invoice_no = $old['invoice_no'];
    rollbackTitipUsage($conn, $bayar_no, $username);
    $sqlDelete = "DELETE FROM head_bayar WHERE bayar_no = ?";
    $stmtDelete = mysqli_prepare($conn, $sqlDelete);
    mysqli_stmt_bind_param($stmtDelete, 's', $bayar_no);
    mysqli_stmt_execute($stmtDelete);
    mysqli_stmt_close($stmtDelete);

    $sqlInv = "
        SELECT
            invoice_no,
            CASE
            WHEN COALESCE(piutang, 0) > 0 THEN COALESCE(piutang, 0)
            WHEN COALESCE(grand_total, 0) > 0 THEN COALESCE(grand_total, 0)
            ELSE COALESCE(subtotal, 0)
        END AS invoice_amount
        FROM head_invoice
        WHERE invoice_no = ?
        LIMIT 1
    ";
    $stmtInv = mysqli_prepare($conn, $sqlInv);
    mysqli_stmt_bind_param($stmtInv, 's', $invoice_no);
    mysqli_stmt_execute($stmtInv);
    $resInv = mysqli_stmt_get_result($stmtInv);
    $inv = mysqli_fetch_assoc($resInv);
    mysqli_stmt_close($stmtInv);

    if ($inv) {
        $invoice_amount = (float)$inv['invoice_amount'];

        $sqlPaid = "
            SELECT COALESCE(SUM(bayar_amount), 0) AS paid_amount
            FROM detail_bayar
            WHERE invoice_no = ?
        ";
        $stmtPaid = mysqli_prepare($conn, $sqlPaid);
        mysqli_stmt_bind_param($stmtPaid, 's', $invoice_no);
        mysqli_stmt_execute($stmtPaid);
        $resPaid = mysqli_stmt_get_result($stmtPaid);
        $paid = mysqli_fetch_assoc($resPaid);
        mysqli_stmt_close($stmtPaid);

        $paid_amount = (float)($paid['paid_amount'] ?? 0);
        $payment_balance = $invoice_amount - $paid_amount;

        if ($paid_amount <= 0) {
            $status = 'Open';
        } elseif ($payment_balance <= 0) {
            $status = 'Paid';
        } else {
            $status = 'Partial';
        }

        $sqlUpdateInv = "
            UPDATE head_invoice
            SET
                payment_balance = ?,
                status = ?,
                user_modified = ?,
                date_modified = NOW()
            WHERE invoice_no = ?
        ";
        $stmtUpdateInv = mysqli_prepare($conn, $sqlUpdateInv);
        mysqli_stmt_bind_param($stmtUpdateInv, 'dsss', $payment_balance, $status, $username, $invoice_no);
        mysqli_stmt_execute($stmtUpdateInv);
        mysqli_stmt_close($stmtUpdateInv);
    }

    mysqli_commit($conn);

    redirectWithAlert('success', 'Pembayaran berhasil dihapus.');

} catch (Exception $e) {
    mysqli_rollback($conn);
    redirectWithAlert('error', $e->getMessage());
}