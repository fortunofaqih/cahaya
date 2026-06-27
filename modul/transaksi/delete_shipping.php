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

function redirectWithAlert($type, $message, $url = 'index.php?page=shipping') {
    $_SESSION['alert'] = '<div class="alert alert-' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    echo "<script>window.location.href='" . addslashes($url) . "';</script>";
    exit;
}

function fetchShippingHeader(mysqli $conn, string $shipping_no): ?array {
    $stmt = mysqli_prepare($conn, "
        SELECT
            shipping_no,
            order_no,
            shipping_date,
            customer_id,
            customer_name,
            status,
            approval_status
        FROM hed_shipping
        WHERE shipping_no = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Gagal prepare cek shipping: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 's', $shipping_no);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return $row ?: null;
}

function countShippingDetails(mysqli $conn, string $shipping_no): int {
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM det_shipping WHERE shipping_no = ?");

    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, 's', $shipping_no);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    return (int)($row['total'] ?? 0);
}

function deleteByShippingNo(mysqli $conn, string $table, string $shipping_no): void {
    // Nama tabel sengaja whitelist supaya tidak bisa diinjeksi lewat parameter.
    $allowedTables = [
        'det_shipping_uom_detail',
        'det_shipping',
        'hed_shipping'
    ];

    if (!in_array($table, $allowedTables, true)) {
        throw new Exception('Nama tabel delete tidak valid.');
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM {$table} WHERE shipping_no = ?");

    if (!$stmt) {
        throw new Exception("Gagal prepare hapus {$table}: " . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 's', $shipping_no);

    if (!mysqli_stmt_execute($stmt)) {
        $error = mysqli_stmt_error($stmt);
        mysqli_stmt_close($stmt);
        throw new Exception("Gagal menghapus {$table}: " . $error);
    }

    mysqli_stmt_close($stmt);
}

$shipping_no = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
$confirm = isset($_GET['confirm']) ? trim((string)$_GET['confirm']) : '';

if ($shipping_no === '') {
    redirectWithAlert('danger', 'Shipping No tidak ditemukan!');
}

try {
    $shipping_data = fetchShippingHeader($conn, $shipping_no);
} catch (Exception $e) {
    redirectWithAlert('danger', 'Error: ' . $e->getMessage());
}

if (!$shipping_data) {
    redirectWithAlert('danger', 'Data shipping tidak ditemukan!');
}

$status = $shipping_data['status'] ?? 'Open';
$approval_status = $shipping_data['approval_status'] ?? 'Pending';

// Status Close tidak boleh dihapus.
if (strcasecmp($status, 'Close') === 0 || strcasecmp($status, 'Closed') === 0) {
    $msg = 'Shipping ' . $shipping_no . ' sudah memiliki status Close dan tidak dapat dihapus!';
    $_SESSION['alert'] = '<div class="alert alert-warning">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
    echo "<script>
        alert('" . addslashes($msg) . "');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;
}

// Opsional: jika nanti approval sudah dipakai ketat, aktifkan blok ini.
// if (strcasecmp($approval_status, 'Approved') === 0) {
//     $msg = 'Shipping ' . $shipping_no . ' sudah Approved dan tidak dapat dihapus!';
//     $_SESSION['alert'] = '<div class="alert alert-warning">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
//     echo "<script>
//         alert('" . addslashes($msg) . "');
//         window.location.href='index.php?page=shipping';
//     </script>";
//     exit;
// }

$detail_count = countShippingDetails($conn, $shipping_no);

if ($confirm !== 'yes') {
    $safe_shipping_no = htmlspecialchars($shipping_no, ENT_QUOTES, 'UTF-8');
    $safe_order_no = htmlspecialchars($shipping_data['order_no'] ?? '-', ENT_QUOTES, 'UTF-8');
    $safe_customer_name = htmlspecialchars($shipping_data['customer_name'] ?? '-', ENT_QUOTES, 'UTF-8');
    $safe_shipping_date = htmlspecialchars($shipping_data['shipping_date'] ?? '-', ENT_QUOTES, 'UTF-8');
    $safe_status = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
    $safe_approval_status = htmlspecialchars($approval_status, ENT_QUOTES, 'UTF-8');
    $safe_detail_count = htmlspecialchars((string)$detail_count, ENT_QUOTES, 'UTF-8');
    $confirm_url = 'index.php?page=delete_shipping&id=' . urlencode($shipping_no) . '&confirm=yes';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
                max-width: 540px;
                width: 100%;
                text-align: center;
            }
            .confirm-box .icon {
                font-size: 58px;
                color: #dc3545;
                margin-bottom: 18px;
            }
            .confirm-box h3 {
                color: #333;
                margin: 0 0 10px;
            }
            .confirm-box p {
                color: #666;
                margin-bottom: 5px;
                font-size: 14px;
            }
            .shipping-info {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin: 15px 0;
                text-align: left;
                border: 1px solid #e9ecef;
            }
            .shipping-info table {
                width: 100%;
                font-size: 13px;
                border-collapse: collapse;
            }
            .shipping-info td {
                padding: 6px 8px;
                vertical-align: top;
                border-bottom: 1px solid #eee;
            }
            .shipping-info tr:last-child td {
                border-bottom: none;
            }
            .shipping-info td:first-child {
                font-weight: bold;
                color: #555;
                width: 38%;
            }
            .badge {
                display: inline-block;
                padding: 3px 10px;
                border-radius: 10px;
                font-size: 12px;
                background: #ffc107;
                color: #333;
            }
            .btn-group {
                display: flex;
                gap: 10px;
                justify-content: center;
                margin-top: 20px;
                flex-wrap: wrap;
            }
            .btn {
                padding: 10px 24px;
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
            .btn-danger { background: #dc3545; color: #fff; }
            .btn-danger:hover { background: #c82333; }
            .btn-secondary { background: #6c757d; color: #fff; }
            .btn-secondary:hover { background: #5a6268; }
            .warning-text {
                color: #dc3545;
                font-size: 12px;
                margin-top: 10px;
                line-height: 1.5;
            }
        </style>
    </head>
    <body>
        <div class="confirm-box">
            <div class="icon"><i class="fa fa-exclamation-triangle"></i></div>
            <h3>Konfirmasi Hapus Data</h3>
            <p>Anda yakin ingin menghapus data shipping berikut?</p>

            <div class="shipping-info">
                <table>
                    <tr>
                        <td>Shipping No</td>
                        <td><strong><?= $safe_shipping_no ?></strong></td>
                    </tr>
                    <tr>
                        <td>Shipping Date</td>
                        <td><?= $safe_shipping_date ?></td>
                    </tr>
                    <tr>
                        <td>Order No</td>
                        <td><?= $safe_order_no ?></td>
                    </tr>
                    <tr>
                        <td>Customer</td>
                        <td><?= $safe_customer_name ?></td>
                    </tr>
                    <tr>
                        <td>Jumlah Detail</td>
                        <td><?= $safe_detail_count ?> item</td>
                    </tr>
                    <tr>
                        <td>Status</td>
                        <td><span class="badge"><?= $safe_status ?></span></td>
                    </tr>
                    <tr>
                        <td>Approval</td>
                        <td><span class="badge"><?= $safe_approval_status ?></span></td>
                    </tr>
                </table>
            </div>

            <p class="warning-text">
                <i class="fa fa-info-circle"></i>
                Data header, detail shipping, dan detail multi UOM akan dihapus permanen.
            </p>

            <div class="btn-group">
                <a href="index.php?page=shipping" class="btn btn-secondary">
                    <i class="fa fa-times"></i> Batal
                </a>
                <a href="<?= htmlspecialchars($confirm_url, ENT_QUOTES, 'UTF-8') ?>"
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

mysqli_begin_transaction($conn);

try {
    // Re-check di dalam transaksi supaya data/status terbaru tetap aman.
    $shipping_data = fetchShippingHeader($conn, $shipping_no);

    if (!$shipping_data) {
        throw new Exception('Data shipping tidak ditemukan atau sudah dihapus.');
    }

    $current_status = $shipping_data['status'] ?? 'Open';

    if (strcasecmp($current_status, 'Close') === 0 || strcasecmp($current_status, 'Closed') === 0) {
        throw new Exception('Shipping ' . $shipping_no . ' sudah memiliki status Close dan tidak dapat dihapus.');
    }

    // Urutan delete wajib: child paling bawah -> detail -> header.
    deleteByShippingNo($conn, 'det_shipping_uom_detail', $shipping_no);
    deleteByShippingNo($conn, 'det_shipping', $shipping_no);
    deleteByShippingNo($conn, 'hed_shipping', $shipping_no);

    mysqli_commit($conn);

    $msg = 'Shipping ' . $shipping_no . ' berhasil dihapus!';
    $_SESSION['alert'] = '<div class="alert alert-success">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';

    echo "<script>
        alert('" . addslashes($msg) . "');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;

} catch (Exception $e) {
    mysqli_rollback($conn);

    $error_message = $e->getMessage();
    $_SESSION['alert'] = '<div class="alert alert-danger">Error: ' . htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') . '</div>';

    echo "<script>
        alert('Error: " . addslashes($error_message) . "');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;
}
?>
