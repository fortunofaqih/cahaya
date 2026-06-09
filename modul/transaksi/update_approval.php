<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}

include __DIR__ . '/../../koneksi.php';

$order_no = isset($_POST['order_no']) ? mysqli_real_escape_string($conn, $_POST['order_no']) : '';
$approval_status = isset($_POST['approval_status']) ? mysqli_real_escape_string($conn, $_POST['approval_status']) : '';

if (empty($order_no) || empty($approval_status)) {
    echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
    exit;
}

$allowed = ['Pending', 'Approve', 'Reject'];
if (!in_array($approval_status, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Status tidak valid']);
    exit;
}

$query = "UPDATE head_sales_order 
          SET approval_status = '$approval_status', 
              user_modified = '{$_SESSION['username']}', 
              date_modified = NOW() 
          WHERE order_no = '$order_no'";

if (mysqli_query($conn, $query)) {
    echo json_encode(['success' => true, 'message' => 'Status berhasil diupdate']);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal update: ' . mysqli_error($conn)]);
}
?>