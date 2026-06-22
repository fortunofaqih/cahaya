<?php
// modul/program/get_user_data.php
session_start();
include __DIR__ . '/../../koneksi.php';
header('Content-Type: application/json');

if (!isset($_SESSION['username']) || $_SESSION['id_role'] != 1) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$id_user = isset($_POST['id_user']) ? intval($_POST['id_user']) : 0;

if ($id_user <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

// Ambil data user
$query = "SELECT * FROM sys_users WHERE id_user = '$id_user'";
$result = mysqli_query($conn, $query);
$user = mysqli_fetch_assoc($result);

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'User not found']);
    exit;
}

// Ambil menu yang diakses user
$menuQuery = "SELECT menu_key FROM sys_user_menu_access WHERE id_user = '$id_user'";
$menuResult = mysqli_query($conn, $menuQuery);
$menus = [];
while ($row = mysqli_fetch_assoc($menuResult)) {
    $menus[] = $row['menu_key'];
}

echo json_encode([
    'success' => true,
    'user' => $user,
    'menus' => $menus
]);
?>