<?php
// cek_login.php
session_start();
include 'koneksi.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password = trim($_POST['password']);

    $query = "SELECT u.*, r.nama_role 
              FROM sys_users u 
              LEFT JOIN sys_role r ON u.id_role = r.id_role 
              WHERE u.username = '$username'";
              
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) === 1) {
        $d = mysqli_fetch_assoc($result);

        if ($d['is_active'] !== 'Checked') {
            $_SESSION['error'] = "Akun Anda dinonaktifkan oleh Admin!";
            header("Location: login.php");
            exit;
        }

        if (password_verify($password, $d['password'])) {
            // CEK SINGLE LOGIN: Hapus session lain dengan username yang sama
            // Generate unique session token
            $session_token = bin2hex(random_bytes(32));
            
            // Simpan token di database dan session
            $updateToken = "UPDATE sys_users SET session_token = '$session_token', last_login = NOW() WHERE id_user = '" . $d['id_user'] . "'";
            mysqli_query($conn, $updateToken);
            
            // Simpan di session
            $_SESSION['id_user']   = $d['id_user'];
            $_SESSION['username']  = $d['username'];
            $_SESSION['id_role']   = $d['id_role'];
            $_SESSION['nama_role'] = $d['nama_role'];
            $_SESSION['session_token'] = $session_token;

            header("Location: index.php");
            exit;
        } else {
            $_SESSION['error'] = "Username atau Password salah!";
            header("Location: login.php");
            exit;
        }
    } else {
        $_SESSION['error'] = "Username atau Password salah!";
        header("Location: login.php");
        exit;
    }
} else {
    header("Location: login.php");
    exit;
}
?>