<?php
// modul/transaksi/save_koreksi_invoice.php

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

function parseDateInput($value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;

    foreach (['d-M-Y','Y-m-d','d-m-Y','d/m/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }
    return null;
}

function parseMoneyInput($value): float {
    $value = trim((string)$value);
    if ($value === '') return 0.0;

    $value = str_ireplace('Rp', '', $value);
    $value = str_replace([' ', '.'], '', $value);
    $value = str_replace(',', '.', $value);

    return is_numeric($value) ? (float)$value : 0.0;
}

$correctionCategories = [
    'PP',
    'KERTAS',
    'PE',
    'PE WARNA',
    'HD',
    'HD WARNA',
    'HD KRESEK',
    'HD SABLON',
    'PP SABLON',
    'TALI KG',
    'TALI LOS',
    'BAHAN',
    'TERPAL',
    'BOX',
    'SEDOTAN',
    'ONGKOS'
];

$action = strtolower(trim((string)($_POST['action'] ?? 'create')));
$username = (string)($_SESSION['username'] ?? '');

try {
    if ($action === 'delete') {
        $correctionId = (int)($_POST['correction_id'] ?? 0);
        if ($correctionId <= 0) {
            redirectWithAlert('danger', 'ID koreksi tidak valid.');
        }

        // Soft delete agar histori audit tidak hilang.
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
            redirectWithAlert('danger', 'Koreksi tidak ditemukan atau sudah dibatalkan.');
        }
        redirectWithAlert('success', 'Koreksi invoice berhasil dibatalkan.');
    }

    $correctionDate = parseDateInput($_POST['correction_date'] ?? '');
    $customerId = trim((string)($_POST['customer_id'] ?? ''));
    $category = strtoupper(trim((string)($_POST['category'] ?? '')));
    $invoiceNo = trim((string)($_POST['invoice_no'] ?? ''));
    $shippingNo = trim((string)($_POST['shipping_no'] ?? ''));
    $inputAmount = parseMoneyInput($_POST['correction_amount'] ?? '');

    if (!$correctionDate) {
        redirectWithAlert('danger', 'Tanggal koreksi tidak valid.');
    }
    if ($customerId === '') {
        redirectWithAlert('danger', 'Customer wajib dipilih.');
    }
    if ($category === '' || !in_array($category, $correctionCategories, true)) {
        redirectWithAlert('danger', 'Kategori koreksi wajib dipilih dan harus sesuai daftar yang tersedia.');
    }
    if ($inputAmount <= 0) {
        redirectWithAlert('danger', 'Nominal koreksi harus lebih besar dari 0. Sistem akan menyimpannya sebagai nilai minus.');
    }

    // Customer harus masih aktif di master.
    $stmtCust = mysqli_prepare($conn, "
        SELECT customer_id
        FROM m_customer
        WHERE customer_id = ?
          AND COALESCE(is_active, 'Checked') = 'Checked'
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmtCust, 's', $customerId);
    mysqli_stmt_execute($stmtCust);
    $custResult = mysqli_stmt_get_result($stmtCust);
    $customerExists = mysqli_fetch_assoc($custResult);
    mysqli_stmt_close($stmtCust);

    if (!$customerExists) {
        redirectWithAlert('danger', 'Customer tidak ditemukan atau sudah tidak aktif.');
    }

    // Field referensi invoice / shipping bersifat opsional.
    $invoiceNoDb = $invoiceNo !== '' ? $invoiceNo : null;
    $shippingNoDb = $shippingNo !== '' ? $shippingNo : null;

    // Rule bisnis: koreksi invoice SELALU minus.
    $correctionAmount = -1 * abs($inputAmount);

    if ($action === 'update') {
        $correctionId = (int)($_POST['correction_id'] ?? 0);
        if ($correctionId <= 0) {
            redirectWithAlert('danger', 'ID koreksi tidak valid.');
        }

        $stmt = mysqli_prepare($conn, "
            UPDATE invoice_correction
            SET correction_date = ?,
                customer_id = ?,
                category = ?,
                invoice_no = ?,
                shipping_no = ?,
                correction_amount = ?,
                user_modified = ?,
                date_modified = NOW()
            WHERE correction_id = ?
              AND LOWER(COALESCE(status,'Active')) = 'active'
        ");
        mysqli_stmt_bind_param(
            $stmt,
            'sssssdsi',
            $correctionDate,
            $customerId,
            $category,
            $invoiceNoDb,
            $shippingNoDb,
            $correctionAmount,
            $username,
            $correctionId
        );
        mysqli_stmt_execute($stmt);
        $affected = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        if ($affected < 0) {
            redirectWithAlert('danger', 'Gagal mengubah koreksi invoice.');
        }

        redirectWithAlert('success', 'Koreksi invoice berhasil diperbarui.');
    }

    // CREATE
    mysqli_begin_transaction($conn);

    // correction_no dibuat berdasarkan correction_id agar unik dan tidak bentrok.
    $tempNo = 'TMP-' . uniqid('', true);
    $stmt = mysqli_prepare($conn, "
        INSERT INTO invoice_correction
        (
            correction_no,
            correction_date,
            customer_id,
            category,
            invoice_no,
            shipping_no,
            correction_amount,
            reference_invoice_no,
            remarks,
            status,
            create_user,
            date_created
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, 'Active', ?, NOW())
    ");
    mysqli_stmt_bind_param(
        $stmt,
        'ssssssds',
        $tempNo,
        $correctionDate,
        $customerId,
        $category,
        $invoiceNoDb,
        $shippingNoDb,
        $correctionAmount,
        $username
    );
    mysqli_stmt_execute($stmt);
    $correctionId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $correctionNo = 'KI-' . str_pad((string)$correctionId, 9, '0', STR_PAD_LEFT);

    $stmtNo = mysqli_prepare($conn, "
        UPDATE invoice_correction
        SET correction_no = ?
        WHERE correction_id = ?
    ");
    mysqli_stmt_bind_param($stmtNo, 'si', $correctionNo, $correctionId);
    mysqli_stmt_execute($stmtNo);
    mysqli_stmt_close($stmtNo);

    mysqli_commit($conn);

    redirectWithAlert('success', 'Koreksi invoice ' . $correctionNo . ' berhasil disimpan.');

} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try { mysqli_rollback($conn); } catch (Throwable $ignore) {}
    }
    redirectWithAlert('danger', 'Gagal menyimpan koreksi invoice: ' . $e->getMessage());
}
