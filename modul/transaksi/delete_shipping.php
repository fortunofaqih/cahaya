<?php
// modul/transaksi/delete_shipping.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// Ambil shipping_no dari URL
$shipping_no = isset($_GET['id']) ? mysqli_real_escape_string($conn, trim($_GET['id'])) : '';

if (empty($shipping_no)) {
    $_SESSION['alert'] = '<div class="alert alert-danger">Shipping No tidak ditemukan!</div>';
    echo "<script>window.location.href='index.php?page=shipping';</script>";
    exit;
}

// Cek apakah shipping_no ada di database
$check_query = mysqli_query($conn, "SELECT shipping_no, status FROM hed_shipping WHERE shipping_no = '$shipping_no'");
if (mysqli_num_rows($check_query) == 0) {
    $_SESSION['alert'] = '<div class="alert alert-danger">Data shipping tidak ditemukan!</div>';
    echo "<script>window.location.href='index.php?page=shipping';</script>";
    exit;
}

$shipping_data = mysqli_fetch_assoc($check_query);

// Cek apakah shipping sudah memiliki status Close (tidak bisa dihapus)
if ($shipping_data['status'] == 'Close') {
    $_SESSION['alert'] = '<div class="alert alert-warning">Shipping ' . $shipping_no . ' sudah memiliki status Close dan tidak dapat dihapus!</div>';
    echo "<script>
        alert('Shipping " . addslashes($shipping_no) . " sudah memiliki status Close dan tidak dapat dihapus!');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;
}

// Konfirmasi via GET parameter (untuk keamanan double confirmation)
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if ($confirm !== 'yes') {
    // Tampilkan halaman konfirmasi
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Konfirmasi Hapus Shipping</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
        <style>
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: #f0f2f5;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
            }
            .confirm-box {
                background: #fff;
                border-radius: 8px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                padding: 30px;
                max-width: 500px;
                width: 100%;
                text-align: center;
            }
            .confirm-box .icon {
                font-size: 60px;
                color: #dc3545;
                margin-bottom: 20px;
            }
            .confirm-box h3 {
                color: #333;
                margin-bottom: 10px;
            }
            .confirm-box p {
                color: #666;
                margin-bottom: 5px;
                font-size: 14px;
            }
            .confirm-box .shipping-info {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin: 15px 0;
                text-align: left;
            }
            .confirm-box .shipping-info table {
                width: 100%;
                font-size: 13px;
            }
            .confirm-box .shipping-info td {
                padding: 4px 8px;
            }
            .confirm-box .shipping-info td:first-child {
                font-weight: bold;
                color: #555;
                width: 40%;
            }
            .btn-group {
                display: flex;
                gap: 10px;
                justify-content: center;
                margin-top: 20px;
            }
            .btn {
                padding: 10px 25px;
                border: none;
                border-radius: 4px;
                font-size: 14px;
                font-weight: bold;
                cursor: pointer;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 8px;
            }
            .btn-danger {
                background: #dc3545;
                color: #fff;
            }
            .btn-danger:hover {
                background: #c82333;
            }
            .btn-secondary {
                background: #6c757d;
                color: #fff;
            }
            .btn-secondary:hover {
                background: #5a6268;
            }
            .btn-success {
                background: #28a745;
                color: #fff;
            }
            .btn-success:hover {
                background: #218838;
            }
            .warning-text {
                color: #dc3545;
                font-size: 12px;
                margin-top: 10px;
            }
        </style>
    </head>
    <body>
        <div class="confirm-box">
            <div class="icon">
                <i class="fa fa-exclamation-triangle"></i>
            </div>
            <h3>Konfirmasi Hapus Data</h3>
            <p>Anda yakin ingin menghapus data shipping berikut?</p>
            
            <div class="shipping-info">
                <table>
                    <tr>
                        <td>Shipping No</td>
                        <td><strong><?= htmlspecialchars($shipping_no) ?></strong></td>
                    </tr>
                    <tr>
                        <td>Order No</td>
                        <td><?= htmlspecialchars($shipping_data['order_no'] ?? '-') ?></td>
                    </tr>
                    <tr>
                        <td>Status</td>
                        <td>
                            <span style="background: #ffc107; padding: 2px 10px; border-radius: 10px; font-size: 12px;">
                                <?= htmlspecialchars($shipping_data['status'] ?? 'Open') ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            
            <p class="warning-text">
                <i class="fa fa-info-circle"></i> 
                Data yang dihapus akan hilang secara permanen!
            </p>
            
            <div class="btn-group">
                <a href="index.php?page=shipping" class="btn btn-secondary">
                    <i class="fa fa-times"></i> Batal
                </a>
                <a href="index.php?page=delete_shipping&id=<?= urlencode($shipping_no) ?>&confirm=yes" 
                   class="btn btn-danger"
                   onclick="return confirm('Hapus shipping <?= addslashes($shipping_no) ?> secara permanen?')">
                    <i class="fa fa-trash"></i> Hapus Permanen
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// =============================================
// PROSES DELETE (setelah konfirmasi)
// =============================================

// Mulai transaksi
mysqli_begin_transaction($conn);

try {
    // 1. Hapus detail shipping (karena foreign key cascade, sebenarnya otomatis)
    // Tapi kita hapus manual untuk memastikan
    $query_delete_detail = "DELETE FROM det_shipping WHERE shipping_no = '$shipping_no'";
    if (!mysqli_query($conn, $query_delete_detail)) {
        throw new Exception("Gagal menghapus detail shipping: " . mysqli_error($conn));
    }

    // 2. Hapus header shipping
    $query_delete_header = "DELETE FROM hed_shipping WHERE shipping_no = '$shipping_no'";
    if (!mysqli_query($conn, $query_delete_header)) {
        throw new Exception("Gagal menghapus header shipping: " . mysqli_error($conn));
    }

    // Commit transaksi
    mysqli_commit($conn);

    $_SESSION['alert'] = '<div class="alert alert-success">Shipping ' . $shipping_no . ' berhasil dihapus!</div>';
    
    echo "<script>
        alert('Shipping " . addslashes($shipping_no) . " berhasil dihapus!');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;

} catch (Exception $e) {
    // Rollback jika ada error
    mysqli_rollback($conn);
    
    $error_message = $e->getMessage();
    $_SESSION['alert'] = '<div class="alert alert-danger">Error: ' . $error_message . '</div>';
    
    echo "<script>
        alert('Error: " . addslashes($error_message) . "');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;
}
?>