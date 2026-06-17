<?php
// modul/master/ajax_inventory.php
session_start();

// Matikan error reporting untuk output JSON bersih
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Session expired']);
    exit;
}

include __DIR__ . '/../../koneksi.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Handler untuk get_inventory_uom
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_inventory_uom' && isset($_GET['id'])) {
    $id = mysqli_real_escape_string($conn, $_GET['id']);
    
    $query = mysqli_query($conn, "SELECT * FROM m_inventory_uom WHERE inventory_id = '$id'");
    
    if (!$query) {
        echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
        exit;
    }
    
    $data = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $data[] = [
            'Uom' => $row['unit'],           // Kolom 'unit' dari database
            'unit' => $row['unit'],           // Alias untuk JavaScript
            'Default' => (int)$row['Default'],
            'is_default' => $row['Default'] == 1 ? 'Checked' : 'Unchecked',
            'Value' => (float)$row['Value'],
            'value_roll' => (float)$row['Value']
        ];
    }
    
    // Bersihkan output buffer
    while (ob_get_level()) ob_end_clean();
    
    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

// Handler untuk get_uom_list
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_uom_list') {
    $query = mysqli_query($conn, "SELECT unit FROM m_uom WHERE is_active='Checked' ORDER BY unit ASC");
    $list = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $list[] = ['unit' => $row['unit']];
    }
    
    while (ob_get_level()) ob_end_clean();
    
    echo json_encode(['status' => 'success', 'data' => $list]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Invalid AJAX request']);
exit;
?>