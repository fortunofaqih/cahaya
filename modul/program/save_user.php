<?php
// modul/program/save_user.php
session_start();
include __DIR__ . '/../../koneksi.php';

// Cek session dan role
if (!isset($_SESSION['username']) || $_SESSION['id_role'] != 1) {
    header("Location: login.php");
    exit;
}

$edit_id = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : 0;
$username = mysqli_real_escape_string($conn, trim($_POST['username']));
$password = trim($_POST['password']);
$nama_lengkap = mysqli_real_escape_string($conn, trim($_POST['nama_lengkap']));
$is_active = mysqli_real_escape_string($conn, $_POST['is_active']);
$menus = isset($_POST['menus']) ? $_POST['menus'] : [];

// Validasi username tidak boleh kosong
if (empty($username)) {
    header("Location: ../../index.php?page=user-akses&msg=error");
    exit;
}

if ($edit_id == 0) {
    // INSERT user baru
    if (empty($password)) {
        header("Location: ../../index.php?page=user-akses&msg=error");
        exit;
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Cek apakah username sudah ada
    $check = mysqli_query($conn, "SELECT id_user FROM sys_users WHERE username = '$username'");
    if (mysqli_num_rows($check) > 0) {
        header("Location: ../../index.php?page=user-akses&msg=duplicate");
        exit;
    }
    
    $query = "INSERT INTO sys_users (username, password, nama_lengkap, is_active) 
              VALUES ('$username', '$hashed_password', '$nama_lengkap', '$is_active')";
    
    if (mysqli_query($conn, $query)) {
        $id_user = mysqli_insert_id($conn);
        
        // Hapus akses menu lama
        mysqli_query($conn, "DELETE FROM sys_user_menu_access WHERE id_user = '$id_user'");
        
        // Insert akses menu baru
        foreach ($menus as $menu_key) {
            $menu_key = mysqli_real_escape_string($conn, $menu_key);
            mysqli_query($conn, "INSERT INTO sys_user_menu_access (id_user, menu_key) VALUES ('$id_user', '$menu_key')");
        }
        
        header("Location: ../../index.php?page=user-akses&msg=added");
    } else {
        header("Location: ../../index.php?page=user-akses&msg=error");
    }
} else {
    // UPDATE user existing
    // Cek apakah username sudah digunakan oleh user lain
    $check = mysqli_query($conn, "SELECT id_user FROM sys_users WHERE username = '$username' AND id_user != '$edit_id'");
    if (mysqli_num_rows($check) > 0) {
        header("Location: ../../index.php?page=user-akses&msg=duplicate");
        exit;
    }
    
    // Build query update
    $update_fields = "username = '$username', nama_lengkap = '$nama_lengkap', is_active = '$is_active'";
    
    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $update_fields .= ", password = '$hashed_password'";
    }
    
    $query = "UPDATE sys_users SET $update_fields WHERE id_user = '$edit_id'";
    
    if (mysqli_query($conn, $query)) {
        // Hapus akses menu lama
        mysqli_query($conn, "DELETE FROM sys_user_menu_access WHERE id_user = '$edit_id'");
        
        // Insert akses menu baru
        foreach ($menus as $menu_key) {
            $menu_key = mysqli_real_escape_string($conn, $menu_key);
            mysqli_query($conn, "INSERT INTO sys_user_menu_access (id_user, menu_key) VALUES ('$edit_id', '$menu_key')");
        }
        
        header("Location: ../../index.php?page=user-akses&msg=updated");
    } else {
        header("Location: ../../index.php?page=user-akses&msg=error");
    }
}
?>