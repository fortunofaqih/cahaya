<?php
// modul/transaksi/get_order_inventory.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

include __DIR__ . '/../../koneksi.php';

$order_no = isset($_POST['order_no']) ? mysqli_real_escape_string($conn, $_POST['order_no']) : '';

if (empty($order_no)) {
    echo json_encode(['success' => false, 'message' => 'Order No tidak valid']);
    exit;
}

// Ambil data inventory dari detail_sales_order
$query = mysqli_query($conn, "
    SELECT 
        dso.inventory_id,
        dso.inventory_name,
        dso.uom,
        dso.uom_pack,
        dso.uom_detail,
        dso.quantity,
        dso.quantity_pack,
        dso.remarks
    FROM detail_sales_order dso
    WHERE dso.order_no = '$order_no'
    ORDER BY dso.id ASC
");

if (!$query) {
    echo json_encode(['success' => false, 'message' => 'Query error: ' . mysqli_error($conn)]);
    exit;
}

$data = [];
while ($row = mysqli_fetch_assoc($query)) {
    $data[] = [
        'inventory_id' => $row['inventory_id'],
        'inventory_name' => $row['inventory_name'],
        'uom' => $row['uom'],
        'uom_pack' => $row['uom_pack'],
        'uom_detail' => $row['uom_detail'],
        'quantity' => $row['quantity'],
        'quantity_pack' => $row['quantity_pack'],
        'remarks' => $row['remarks']
    ];
}

echo json_encode([
    'success' => true,
    'data' => $data,
    'total' => count($data)
]);
?>