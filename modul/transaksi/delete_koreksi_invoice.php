<?php
// modul/transaksi/delete_koreksi_invoice.php
// Soft delete: histori audit tetap disimpan, status diubah menjadi Cancelled.

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

function redirectWithAlert(string $type, string $message): void {
    $_SESSION['alert'] =
        '<div style="padding:10px;margin-bottom:10px;border-radius:4px;background:' .
        ($type === 'success' ? '#d1e7dd' : '#f8d7da') .
        ';color:' .
        ($type === 'success' ? '#0f5132' : '#842029') .
        ';border:1px solid ' .
        ($type === 'success' ? '#badbcc' : '#f5c2c7') .
        ';">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';

    header('Location: ../../index.php?page=koreksi_invoice');
    exit;
}

$correctionId = (int)($_GET['correction_id'] ?? $_POST['correction_id'] ?? 0);
$username = (string)($_SESSION['username'] ?? '');

if ($correctionId <= 0) {
    redirectWithAlert('danger', 'ID koreksi tidak valid.');
}

try {
    $stmtCheck = mysqli_prepare($conn, "
        SELECT correction_no, status
        FROM invoice_correction
        WHERE correction_id = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmtCheck, 'i', $correctionId);
    mysqli_stmt_execute($stmtCheck);
    $resCheck = mysqli_stmt_get_result($stmtCheck);
    $row = mysqli_fetch_assoc($resCheck);
    mysqli_stmt_close($stmtCheck);

    if (!$row) {
        redirectWithAlert('danger', 'Data koreksi invoice tidak ditemukan.');
    }

    if (strtolower((string)$row['status']) !== 'active') {
        redirectWithAlert('danger', 'Koreksi invoice sudah dibatalkan sebelumnya.');
    }

    $stmt = mysqli_prepare($conn, "
        UPDATE invoice_correction
        SET status = 'Cancelled',
            user_modified = ?,
            date_modified = NOW()
        WHERE correction_id = ?
          AND LOWER(COALESCE(status,'Active')) = 'active'
    ");
    mysqli_stmt_bind_param($stmt, 'si', $username, $correctionId);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected <= 0) {
        redirectWithAlert('danger', 'Koreksi invoice gagal dibatalkan atau status sudah berubah.');
    }

    redirectWithAlert(
        'success',
        'Koreksi invoice ' . (string)$row['correction_no'] . ' berhasil dibatalkan.'
    );

} catch (Throwable $e) {
    redirectWithAlert('danger', 'Gagal membatalkan koreksi invoice: ' . $e->getMessage());
}
