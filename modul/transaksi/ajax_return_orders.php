<?php
// modul/transaksi/ajax_return_orders.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Session login sudah berakhir.'
    ]);
    exit;
}

include __DIR__ . '/../../koneksi.php';
mysqli_set_charset($conn, 'utf8mb4');

$customerId = trim((string)($_GET['customer_id'] ?? ''));
if ($customerId === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Customer ID wajib diisi.',
        'data' => []
    ]);
    exit;
}

/*
 * Order hanya diambil bila:
 * 1. Customer sesuai.
 * 2. Memiliki Shipping.
 * 3. Shipping sudah masuk Invoice.
 * 4. Invoice tidak Cancelled.
 *
 * inventory_summary digunakan sebagai informasi pada dropdown Order.
 */
$sql = "
    SELECT
        hs.order_no,
        MAX(hs.order_date) AS order_date,
        MAX(hs.customer_id) AS customer_id,
        MAX(hs.customer_name) AS customer_name,
        COUNT(DISTINCT hs.shipping_no) AS shipping_count,
        GROUP_CONCAT(
            DISTINCT CONCAT(
                COALESCE(ds.inventory_id, ''),
                CASE
                    WHEN COALESCE(ds.inventory_name, '') <> ''
                    THEN CONCAT(' - ', ds.inventory_name)
                    ELSE ''
                END
            )
            ORDER BY ds.inventory_id, ds.inventory_name
            SEPARATOR '; '
        ) AS inventory_summary
    FROM hed_shipping hs
    INNER JOIN det_invoice di
        ON di.shipping_no = hs.shipping_no
    INNER JOIN head_invoice hi
        ON hi.invoice_no = di.invoice_no
    INNER JOIN det_shipping ds
        ON ds.shipping_no = hs.shipping_no
    WHERE hi.customer_id = ?
      AND COALESCE(hi.status, 'Open') <> 'Cancelled'
    GROUP BY hs.order_no
    ORDER BY MAX(hs.order_date) DESC, hs.order_no DESC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Gagal menyiapkan query Sales Order: ' . mysqli_error($conn),
        'data' => []
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $customerId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$data = [];
while ($row = mysqli_fetch_assoc($result)) {
    $data[] = [
        'order_no' => (string)($row['order_no'] ?? ''),
        'order_date' => (string)($row['order_date'] ?? ''),
        'customer_id' => (string)($row['customer_id'] ?? ''),
        'customer_name' => (string)($row['customer_name'] ?? ''),
        'shipping_count' => (int)($row['shipping_count'] ?? 0),
        'inventory_summary' => (string)($row['inventory_summary'] ?? '')
    ];
}

mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'data' => $data
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
