<?php
// koneksi.php

// Jika database berbeda server dalam 1 jaringan kantor, ganti localhost dengan IP Server DB
$host = "localhost";
$user = "root";
$pass = "";
$db   = "db_cahaya";
$port = 3306; // Gunakan integer tanpa tanda kutip untuk port

$conn = mysqli_connect($host, $user, $pass, $db, $port);

if (!$conn) {
    die("Koneksi ke Database Gagal: " . mysqli_connect_error());
}
?>

