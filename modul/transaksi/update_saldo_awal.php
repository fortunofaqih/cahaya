<?php
// modul/transaksi/update_saldo_awal.php

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

function redirectWithAlert($type, $message, $page = 'saldo_awal') {
    $class = $type === 'success'
        ? 'alert alert-success'
        : 'alert alert-danger';

    $_SESSION['alert'] =
        '<div class="' . $class . '" style="padding:10px;margin-bottom:10px;border-radius:4px;">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '</div>';

    header('Location: ../../index.php?page=' . urlencode($page));
    exit;
}

function parseInputDate($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);

        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithAlert(
        'error',
        'Metode request tidak valid.',
        'saldo_awal'
    );
}

$opening_id = (int)($_POST['opening_id'] ?? 0);

$opening_date = parseInputDate(
    $_POST['opening_date'] ?? ''
);

$opening_balance_raw = trim(
    (string)($_POST['opening_balance'] ?? '')
);

$remarks = trim(
    (string)($_POST['remarks'] ?? '')
);

$status = trim(
    (string)($_POST['status'] ?? 'Active')
);

$username = trim(
    (string)($_SESSION['username'] ?? '')
);

if ($opening_id <= 0) {
    redirectWithAlert(
        'error',
        'Opening ID tidak valid.',
        'saldo_awal'
    );
}

if ($opening_date === '') {
    redirectWithAlert(
        'error',
        'Tanggal saldo tidak valid.',
        'edit_saldo_awal&opening_id=' . $opening_id
    );
}

if (
    $opening_balance_raw === ''
    || !is_numeric($opening_balance_raw)
) {
    redirectWithAlert(
        'error',
        'Saldo awal tidak valid.',
        'edit_saldo_awal&opening_id=' . $opening_id
    );
}

$opening_balance = (float)$opening_balance_raw;



$allowedStatuses = ['Active', 'Cancelled'];

if (!in_array($status, $allowedStatuses, true)) {
    redirectWithAlert(
        'error',
        'Status tidak valid.',
        'edit_saldo_awal&opening_id=' . $opening_id
    );
}

try {

    mysqli_begin_transaction($conn);

    $stmtCheck = mysqli_prepare(
        $conn,
        "
        SELECT
            opening_id,
            customer_id
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

    $existing =
        mysqli_fetch_assoc($resCheck);

    mysqli_stmt_close($stmtCheck);

    if (!$existing) {
        throw new Exception(
            'Data saldo awal tidak ditemukan.'
        );
    }

    /*
     * Customer tidak diubah pada mode Edit.
     * customer_name dan city tetap disegarkan dari master customer.
     */
    $customer_id =
        trim((string)$existing['customer_id']);

    $stmtCustomer = mysqli_prepare(
        $conn,
        "
        SELECT
            customer,
            city
        FROM m_customer
        WHERE customer_id = ?
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param(
        $stmtCustomer,
        's',
        $customer_id
    );

    mysqli_stmt_execute($stmtCustomer);

    $resCustomer =
        mysqli_stmt_get_result($stmtCustomer);

    $customer =
        mysqli_fetch_assoc($resCustomer);

    mysqli_stmt_close($stmtCustomer);

    $customer_name =
        trim((string)($customer['customer'] ?? ''));

    $customer_city =
        trim((string)($customer['city'] ?? ''));

    $stmtUpdate = mysqli_prepare(
        $conn,
        "
        UPDATE customer_opening_balance
        SET
            opening_date = ?,
            customer_name = ?,
            customer_city = ?,
            opening_balance = ?,
            remarks = ?,
            status = ?,
            user_modified = ?,
            date_modified = NOW()
        WHERE opening_id = ?
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param(
        $stmtUpdate,
        'sssdsssi',
        $opening_date,
        $customer_name,
        $customer_city,
        $opening_balance,
        $remarks,
        $status,
        $username,
        $opening_id
    );

    mysqli_stmt_execute($stmtUpdate);

    mysqli_stmt_close($stmtUpdate);

    mysqli_commit($conn);

    redirectWithAlert(
        'success',
        'Saldo awal customer berhasil diperbarui.',
        'saldo_awal'
    );

} catch (Throwable $e) {

    try {
        mysqli_rollback($conn);
    } catch (Throwable $rollbackError) {
        // Abaikan rollback error.
    }

    redirectWithAlert(
        'error',
        'Gagal memperbarui saldo awal: ' . $e->getMessage(),
        'edit_saldo_awal&opening_id=' . $opening_id
    );
}
