<?php
// modul/transaksi/get_order_inventory.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Akses ditolak. Silakan login ulang.'
    ]);
    exit;
}

include __DIR__ . '/../../koneksi.php';

$order_no = isset($_POST['order_no']) ? trim($_POST['order_no']) : '';

if ($order_no === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Order No tidak valid'
    ]);
    exit;
}

$stmt = mysqli_prepare($conn, "
    SELECT 
        dso.id,
        dso.inventory_id,
        dso.inventory_name,
        dso.uom,
        dso.uom_pack,
        dso.uom_detail,
        dso.quantity,
        dso.quantity_pack,
        dso.remarks
    FROM detail_sales_order dso
    WHERE dso.order_no = ?
    ORDER BY dso.id ASC
");

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Prepare query error: ' . mysqli_error($conn)
    ]);
    exit;
}

mysqli_stmt_bind_param($stmt, 's', $order_no);
mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

if (!$result) {
    echo json_encode([
        'success' => false,
        'message' => 'Query error: ' . mysqli_error($conn)
    ]);
    exit;
}

$data = [];

while ($row = mysqli_fetch_assoc($result)) {
    $data[] = [
        'detail_id' => $row['id'],
        'inventory_id' => $row['inventory_id'],
        'inventory_name' => $row['inventory_name'],
        'uom' => $row['uom'],
        'uom_pack' => $row['uom_pack'],
        'uom_detail' => $row['uom_detail'],
        'quantity' => (float)$row['quantity'],
        'quantity_pack' => (float)$row['quantity_pack'],
        'qty_detail' => 0,
        'remarks' => $row['remarks']
    ];
}

mysqli_stmt_close($stmt);

echo json_encode([
    'success' => true,
    'order_no' => $order_no,
    'data' => $data,
    'total' => count($data)
]);
exit;
?>