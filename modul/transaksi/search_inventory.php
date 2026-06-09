<?php
// modul/transaksi/search_inventory.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    exit;
}

include __DIR__ . '/../../koneksi.php';

$search = isset($_GET['q']) ? mysqli_real_escape_string($conn, $_GET['q']) : '';
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

if (!empty($search)) {
    $where = "WHERE inventory_id LIKE '%$search%' OR inventory_name LIKE '%$search%'";
} else {
    $where = "WHERE 1=1";  // Tampilkan semua data
}

$query = mysqli_query($conn, "SELECT COUNT(*) as total FROM m_inventory $where");
if (!$query) {
    echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
    exit;
}
$total = mysqli_fetch_assoc($query)['total'];

$query = mysqli_query($conn, "SELECT inventory_id, inventory_name, uom, uom_pack 
                              FROM m_inventory 
                              $where 
                              ORDER BY inventory_name 
                              LIMIT $offset, $limit");

if (!$query) {
    echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
    exit;
}

$results = [];
while ($row = mysqli_fetch_assoc($query)) {
    $results[] = [
        'id' => $row['inventory_id'],
        'text' => $row['inventory_id'] . ' — ' . $row['inventory_name'],
        'inventory_id' => $row['inventory_id'],
        'inventory_name' => $row['inventory_name'],
        'uom' => $row['uom'],
        'uom_pack' => $row['uom_pack']
    ];
}

header('Content-Type: application/json');
echo json_encode([
    'results' => $results,
    'pagination' => ['more' => ($offset + $limit) < $total]
]);
?>