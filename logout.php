<?php
// logout.php
session_start();
include 'koneksi.php';

// Hapus session token di database
if (isset($_SESSION['id_user'])) {
    $id_user = $_SESSION['id_user'];
    mysqli_query($conn, "UPDATE sys_users SET session_token = NULL WHERE id_user = '$id_user'");
}

// Hapus session
$_SESSION = array();
session_destroy();

header("Location: login.php");
exit;
?>