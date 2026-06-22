<?php
// modul/program/reset_password.php
session_start();
include __DIR__ . '/../../koneksi.php';
if (!isset($_SESSION['username']) || $_SESSION['id_role'] != 1) {
    header("Location: login.php");
    exit;
}

$id_user = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id_user > 0) {
    $default_password = password_hash('123456', PASSWORD_DEFAULT);
    $query = "UPDATE sys_users SET password = '$default_password' WHERE id_user = '$id_user'";
    mysqli_query($conn, $query);
}

header("Location: ../../index.php?page=user-akses&msg=password_changed");
exit;
?>