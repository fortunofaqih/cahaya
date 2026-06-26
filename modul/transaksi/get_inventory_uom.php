<?php
// modul/transaksi/get_inventory_uom.php

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

$query = mysqli_query($conn, "
    SELECT 
        inventory_id,
        unit,
        `Default` AS is_default,
        `Value` AS value_uom
    FROM m_inventory_uom
    ORDER BY inventory_id ASC, `Default` DESC, unit ASC
");

if (!$query) {
    echo json_encode([
        'success' => false,
        'message' => 'Query error: ' . mysqli_error($conn)
    ]);
    exit;
}

$data = [];

while ($row = mysqli_fetch_assoc($query)) {
    $inventoryId = $row['inventory_id'];

    if (!isset($data[$inventoryId])) {
        $data[$inventoryId] = [];
    }

    $data[$inventoryId][] = [
        'unit' => strtoupper(trim($row['unit'])),
        'default' => (int)$row['is_default'],
        'value' => (float)$row['value_uom']
    ];
}

echo json_encode([
    'success' => true,
    'data' => $data
]);
exit;
?>