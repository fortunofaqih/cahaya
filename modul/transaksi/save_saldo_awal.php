<?php
// modul/transaksi/save_saldo_awal.php

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

    $formats = [
        'd-M-Y',
        'Y-m-d',
        'd-m-Y',
        'd/m/Y'
    ];

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

$customer_id = trim((string)($_POST['customer_id'] ?? ''));
$opening_date = parseInputDate(
    $_POST['opening_date'] ?? ''
);
$opening_balance_raw = trim(
    (string)($_POST['opening_balance'] ?? '')
);
$remarks = trim(
    (string)($_POST['remarks'] ?? '')
);

if ($customer_id === '') {
    redirectWithAlert(
        'error',
        'Customer wajib dipilih.',
        'add_saldo_awal'
    );
}

if ($opening_date === '') {
    redirectWithAlert(
        'error',
        'Tanggal saldo tidak valid.',
        'add_saldo_awal'
    );
}

if (
    $opening_balance_raw === ''
    || !is_numeric($opening_balance_raw)
) {
    redirectWithAlert(
        'error',
        'Saldo awal tidak valid.',
        'add_saldo_awal'
    );
}

$opening_balance = (float)$opening_balance_raw;

if ($opening_balance < 0) {
    redirectWithAlert(
        'error',
        'Saldo awal tidak boleh negatif.',
        'add_saldo_awal'
    );
}

$username = trim(
    (string)($_SESSION['username'] ?? '')
);

if ($username === '') {
    redirectWithAlert(
        'error',
        'Session login tidak valid.',
        'add_saldo_awal'
    );
}

try {

    mysqli_begin_transaction($conn);

    /*
     * Ambil customer langsung dari master.
     * Jangan percaya customer_name dari browser.
     */
    $stmtCustomer = mysqli_prepare(
        $conn,
        "
        SELECT
            customer_id,
            customer,
            city
        FROM m_customer
        WHERE customer_id = ?
          AND COALESCE(is_active, 'Checked') = 'Checked'
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

    if (!$customer) {
        throw new Exception(
            'Customer tidak ditemukan atau sudah tidak aktif.'
        );
    }

    /*
     * Satu customer hanya boleh mempunyai satu saldo awal.
     */
    $stmtCheck = mysqli_prepare(
        $conn,
        "
        SELECT opening_id
        FROM customer_opening_balance
        WHERE customer_id = ?
        LIMIT 1
        FOR UPDATE
        "
    );

    mysqli_stmt_bind_param(
        $stmtCheck,
        's',
        $customer_id
    );

    mysqli_stmt_execute($stmtCheck);

    $resCheck =
        mysqli_stmt_get_result($stmtCheck);

    $existing =
        mysqli_fetch_assoc($resCheck);

    mysqli_stmt_close($stmtCheck);

    if ($existing) {
        throw new Exception(
            'Customer ini sudah mempunyai Saldo Awal. Silakan gunakan menu Edit.'
        );
    }

    $customer_name =
        trim((string)($customer['customer'] ?? ''));

    $customer_city =
        trim((string)($customer['city'] ?? ''));

    $status = 'Active';

    $stmtInsert = mysqli_prepare(
        $conn,
        "
        INSERT INTO customer_opening_balance
        (
            opening_date,
            customer_id,
            customer_name,
            customer_city,
            opening_balance,
            remarks,
            status,
            create_user,
            date_created
        )
        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
        "
    );

    mysqli_stmt_bind_param(
        $stmtInsert,
        'ssssdsss',
        $opening_date,
        $customer_id,
        $customer_name,
        $customer_city,
        $opening_balance,
        $remarks,
        $status,
        $username
    );

    mysqli_stmt_execute($stmtInsert);

    mysqli_stmt_close($stmtInsert);

    mysqli_commit($conn);

    redirectWithAlert(
        'success',
        'Saldo awal customer berhasil disimpan.',
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
        'Gagal menyimpan saldo awal: ' . $e->getMessage(),
        'add_saldo_awal'
    );
}
