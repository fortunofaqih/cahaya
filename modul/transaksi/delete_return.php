<?php
// modul/transaksi/delete_return.php
// Versi mandiri tanpa return_bootstrap.php

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

function redirectDeleteReturn(string $type, string $message, string $url): void
{
    $_SESSION['alert'] =
        '<div class="alert alert-' .
        htmlspecialchars($type, ENT_QUOTES, 'UTF-8') .
        '">' .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '</div>';

    echo '<script>';
    echo 'window.location.href = ' .
        json_encode(
            $url,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) .
        ';';
    echo '</script>';

    echo '<noscript>';
    echo '<meta http-equiv="refresh" content="0;url=' .
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8') .
        '">';
    echo '</noscript>';

    exit;
}

$returnId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnId = trim((string)($_POST['return_id'] ?? ''));
} else {
    $returnId = trim((string)($_GET['return_id'] ?? ''));

    if ($returnId === '') {
        $returnId = trim((string)($_GET['id'] ?? ''));
    }
}

if ($returnId === '') {
   redirectDeleteReturn(
    'danger',
    'Return ID tidak valid.',
    '../../index.php?page=return_invoice'
);
}

try {
    mysqli_begin_transaction($conn);

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            return_id,
            approval_status,
            status
        FROM head_retur_invoice
        WHERE return_id = ?
        LIMIT 1
        FOR UPDATE
        "
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal prepare data retur: ' . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, 's', $returnId);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $header = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    if (!$header) {
        throw new RuntimeException('Data Sales Return tidak ditemukan.');
    }

    if (($header['approval_status'] ?? 'Pending') !== 'Pending') {
        throw new RuntimeException(
            'Hanya Sales Return berstatus Pending yang dapat dihapus.'
        );
    }

    if (strtolower((string)($header['status'] ?? 'Open')) === 'cancelled') {
        throw new RuntimeException(
            'Sales Return yang sudah dibatalkan tidak dapat dihapus.'
        );
    }

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM detail_retur_invoice WHERE return_id = ?"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal prepare hapus detail retur: ' . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, 's', $returnId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM head_retur_invoice WHERE return_id = ?"
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal prepare hapus header retur: ' . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, 's', $returnId);
    mysqli_stmt_execute($stmt);

    if (mysqli_stmt_affected_rows($stmt) <= 0) {
        mysqli_stmt_close($stmt);
        throw new RuntimeException('Data Sales Return gagal dihapus.');
    }

    mysqli_stmt_close($stmt);
    mysqli_commit($conn);

   redirectDeleteReturn(
    'success',
    "Sales Return {$returnId} berhasil dihapus.",
    '../../index.php?page=return_invoice'
);
} catch (Throwable $e) {
    try {
        mysqli_rollback($conn);
    } catch (Throwable $rollbackError) {
        // Abaikan error rollback agar pesan utama tetap tampil.
    }

    redirectDeleteReturn(
    'danger',
    'Error: ' . $e->getMessage(),
    '../../index.php?page=return_invoice'
);
}
