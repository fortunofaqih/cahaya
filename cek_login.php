<?php
// cek_login.php
session_start();
include 'koneksi.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Mengamankan inputan dari SQL Injection
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password = trim($_POST['password']);

    // Query mencari user dan relasi ke tabel role untuk hak akses menu nantinya
    $query = "SELECT u.*, r.nama_role 
              FROM sys_users u 
              LEFT JOIN sys_role r ON u.id_role = r.id_role 
              WHERE u.username = '$username'";
              
    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) === 1) {
        // Menggunakan variabel $d sesuai standarisasi kode Anda
        $d = mysqli_fetch_assoc($result);

        // Cek status aktif user
        if ($d['is_active'] !== 'Checked') {
            $_SESSION['error'] = "Akun Anda dinonaktifkan oleh Admin!";
            header("Location: login.php");
            exit;
        }

        // Verifikasi password Bcrypt
        if (password_verify($password, $d['password'])) {
            // Jika berhasil, buat session user
            $_SESSION['id_user']   = $d['id_user'];
            $_SESSION['username']  = $d['username'];
            $_SESSION['id_role']   = $d['id_role'];
            $_SESSION['nama_role'] = $d['nama_role'];

            // Alihkan ke halaman dashboard utama
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