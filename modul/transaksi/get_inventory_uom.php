<?php
// modul/transaksi/get_inventory_uom.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    header("HTTP/1.0 403 Forbidden");
    exit;
}

include __DIR__ . '/../../koneksi.php';

$query = mysqli_query($conn, "
    SELECT 
        inventory_id,
        unit,
        `Default`,
        Value
    FROM m_inventory_uom
    ORDER BY inventory_id ASC, `Default` DESC, unit ASC
");

if (!$query) {
    echo json_encode(['success' => false, 'message' => 'Query error: ' . mysqli_error($conn)]);
    exit;
}

$data = [];
while ($row = mysqli_fetch_assoc($query)) {
    $inventoryId = $row['inventory_id'];
    
    if (!isset($data[$inventoryId])) {
        $data[$inventoryId] = [];
    }
    
    $data[$inventoryId][] = [
        'unit' => $row['unit'],
        'default' => (int)$row['Default'],
        'value' => (float)$row['Value']
    ];
}

echo json_encode([
    'success' => true,
    'data' => $data
]);
?>