<?php
// modul/transaksi/ajax_return_shippings.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
 * Bersihkan output buffer supaya warning atau spasi dari file lain
 * tidak merusak response JSON.
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}

ob_start();

header('Content-Type: application/json; charset=utf-8');

function sendJson(array $data, int $statusCode = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

try {
    if (!isset($_SESSION['username'])) {
        sendJson([
            'success' => false,
            'message' => 'Session login telah berakhir. Silakan login kembali.'
        ], 401);
    }

    require_once __DIR__ . '/../../koneksi.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            'Koneksi database $conn tidak ditemukan.'
        );
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    mysqli_set_charset($conn, 'utf8mb4');

    $orderNo = trim((string)($_GET['order_no'] ?? ''));

    if ($orderNo === '') {
        sendJson([
            'success' => true,
            'data' => []
        ]);
    }

    $sql = "
        SELECT DISTINCT
            hs.shipping_no,
            hs.shipping_date,
            di.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name
        FROM hed_shipping hs
        INNER JOIN det_invoice di
            ON di.shipping_no = hs.shipping_no
        INNER JOIN head_invoice hi
            ON hi.invoice_no = di.invoice_no
        WHERE hs.order_no = ?
          AND COALESCE(hi.status, 'Open') <> 'Cancelled'
        ORDER BY
            hs.shipping_date DESC,
            hs.shipping_no DESC,
            di.invoice_no DESC
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal prepare query Shipping: ' . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, 's', $orderNo);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $data = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = [
            'shipping_no' => (string)($row['shipping_no'] ?? ''),
            'shipping_date' => (string)($row['shipping_date'] ?? ''),
            'invoice_no' => (string)($row['invoice_no'] ?? ''),
            'invoice_date' => (string)($row['invoice_date'] ?? ''),
            'customer_id' => (string)($row['customer_id'] ?? ''),
            'customer_name' => (string)($row['customer_name'] ?? '')
        ];
    }

    mysqli_stmt_close($stmt);

    sendJson([
        'success' => true,
        'data' => $data
    ]);
} catch (Throwable $e) {
    sendJson([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], 500);
}