<?php
// modul/transaksi/generate_shipping_no.php

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

$yearShort = date('y');
$prefix = 'SJ/' . $yearShort . '/';

$stmt = mysqli_prepare($conn, "
    SELECT shipping_no
    FROM hed_shipping
    WHERE shipping_no LIKE CONCAT(?, '%')
    ORDER BY
        CAST(SUBSTRING_INDEX(shipping_no, '/', -1) AS UNSIGNED) DESC
    LIMIT 1
");

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Prepare query gagal: ' . mysqli_error($conn)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $prefix);

if (!mysqli_stmt_execute($stmt)) {
    echo json_encode([
        'success' => false,
        'message' => 'Query gagal dijalankan: ' . mysqli_stmt_error($stmt)
    ]);
    mysqli_stmt_close($stmt);
    exit;
}

$result = mysqli_stmt_get_result($stmt);
$row = mysqli_fetch_assoc($result);

mysqli_stmt_close($stmt);

$nextNumber = 1;

if (!empty($row['shipping_no'])) {
    $parts = explode('/', $row['shipping_no']);
    $lastPart = end($parts);

    if (is_numeric($lastPart)) {
        $nextNumber = ((int)$lastPart) + 1;
    }
}

$shippingNo = $prefix . str_pad(
    (string)$nextNumber,
    6,
    '0',
    STR_PAD_LEFT
);

/*
 * Cek kembali untuk menghindari kemungkinan nomor sudah dipakai.
 */
while (true) {
    $checkStmt = mysqli_prepare(
        $conn,
        "SELECT COUNT(*) AS total
         FROM hed_shipping
         WHERE shipping_no = ?"
    );

    if (!$checkStmt) {
        echo json_encode([
            'success' => false,
            'message' => 'Prepare pengecekan nomor gagal: ' . mysqli_error($conn)
        ]);
        exit;
    }

    mysqli_stmt_bind_param($checkStmt, 's', $shippingNo);
    mysqli_stmt_execute($checkStmt);

    $checkResult = mysqli_stmt_get_result($checkStmt);
    $checkRow = mysqli_fetch_assoc($checkResult);

    mysqli_stmt_close($checkStmt);

    if ((int)($checkRow['total'] ?? 0) === 0) {
        break;
    }

    $nextNumber++;

    $shippingNo = $prefix . str_pad(
        (string)$nextNumber,
        6,
        '0',
        STR_PAD_LEFT
    );
}

echo json_encode([
    'success' => true,
    'shipping_no' => $shippingNo
]);
exit;