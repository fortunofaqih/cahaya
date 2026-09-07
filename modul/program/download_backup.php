<?php
// modul/program/download_backup.php - DIRECT DOWNLOAD HANDLER

session_start();

// 1. Proteksi Akses
if (!isset($_SESSION['username']) || $_SESSION['id_role'] != 1) {
    die("Akses ditolak.");
}

// 2. Tentukan Path File Backup
$rootDir = realpath(__DIR__ . '/../../'); 
$backupPath = str_replace('\\', '/', $rootDir) . '/backup/';

if (isset($_GET['file']) && !empty($_GET['file'])) {
    // Ambil nama file & amankan dari directory traversal
    $filename = basename($_GET['file']);
    $filepath = $backupPath . $filename;

    if (file_exists($filepath)) {
        // Bersihkan seluruh buffer
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Header Paksa Download
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));

        readfile($filepath);
        exit;
    } else {
        echo "<script>alert('File tidak ditemukan!'); window.history.back();</script>";
        exit;
    }
} else {
    echo "<script>alert('Parameter file tidak valid!'); window.history.back();</script>";
    exit;
}