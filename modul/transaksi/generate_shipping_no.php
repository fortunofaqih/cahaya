<?php
// modul/transaksi/generate_shipping_no.php
// Nomor yang dihasilkan di sini hanya PREVIEW.
// Nomor final tetap ditentukan kembali di save_shipping.php
// menggunakan database lock.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Session login sudah berakhir. Silakan login kembali.'
    ]);
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

try {
    $yearShort = date('y');
    $prefix = 'SJ/' . $yearShort . '/';

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT shipping_no
        FROM hed_shipping
        WHERE shipping_no LIKE CONCAT(?, '%')
        ORDER BY
            CAST(
                SUBSTRING_INDEX(shipping_no, '/', -1)
                AS UNSIGNED
            ) DESC
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param($stmt, 's', $prefix);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);

    mysqli_stmt_close($stmt);

    $nextNumber = 1;

    if (!empty($row['shipping_no'])) {
        $parts = explode('/', $row['shipping_no']);
        $lastPart = end($parts);

        if (ctype_digit((string)$lastPart)) {
            $nextNumber = ((int)$lastPart) + 1;
        }
    }

    $shippingNo = $prefix . str_pad(
        (string)$nextNumber,
        6,
        '0',
        STR_PAD_LEFT
    );

    echo json_encode([
        'success' => true,
        'shipping_no' => $shippingNo,
        'is_preview' => true,
        'message' => 'Nomor preview berhasil dibuat. Nomor final ditentukan saat Save.'
    ]);
} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Gagal membuat preview Shipping No: ' . $e->getMessage()
    ]);
}

exit;