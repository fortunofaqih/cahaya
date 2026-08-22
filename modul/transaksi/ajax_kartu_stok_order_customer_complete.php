<?php
// modul/transaksi/ajax_kartu_stok_order_customer_complete.php
// Set / batalkan status "Complete Manual" untuk satu baris SO + inventory
// pada halaman Kartu Stok Order Customer.
//
// Status manual ini TIDAK mengubah data quantity/shipping asli sama sekali.
// Ini murni penanda ("flag") bahwa sisa outstanding dianggap tidak akan
// dikirim lagi, disimpan di tabel terpisah kso_manual_complete.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['username'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Session login telah berakhir. Silakan login kembali.'
    ]);
    exit;
}

include __DIR__ . '/../../koneksi.php';

// Pastikan tabel penampung status manual complete tersedia.
// (Aman dipanggil berulang kali karena pakai IF NOT EXISTS.)
$createTableSql = "
    CREATE TABLE IF NOT EXISTS `kso_manual_complete` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `order_no` VARCHAR(30) NOT NULL,
        `inventory_id` VARCHAR(50) NOT NULL,
        `remarks` TEXT NULL,
        `create_user` VARCHAR(100) NULL,
        `date_created` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_kso_manual_complete` (`order_no`, `inventory_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";
mysqli_query($conn, $createTableSql);

$orderNo     = trim((string)($_REQUEST['order_no'] ?? ''));
$inventoryId = trim((string)($_REQUEST['inventory_id'] ?? ''));
$action      = strtolower(trim((string)($_REQUEST['action'] ?? '')));
$remarks     = trim((string)($_REQUEST['remarks'] ?? ''));

if ($orderNo === '' || $inventoryId === '' || !in_array($action, ['set', 'unset'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Parameter tidak lengkap (order_no / inventory_id / action).',
        'debug'   => [
            'order_no'     => $orderNo,
            'inventory_id' => $inventoryId,
            'action'       => $action,
            'method'       => $_SERVER['REQUEST_METHOD'] ?? '',
            'raw_get'      => $_GET,
            'raw_post'     => $_POST,
        ],
    ]);
    exit;
}

$username = (string)($_SESSION['username'] ?? '');

if ($action === 'set') {
    $sql = "
        INSERT INTO kso_manual_complete
            (order_no, inventory_id, remarks, create_user, date_created)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            remarks      = VALUES(remarks),
            create_user  = VALUES(create_user),
            date_created = NOW()
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Prepare gagal: ' . mysqli_error($conn)]);
        exit;
    }

    mysqli_stmt_bind_param($stmt, 'ssss', $orderNo, $inventoryId, $remarks, $username);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        echo json_encode(['success' => false, 'message' => 'Gagal menyimpan status Complete Manual: ' . mysqli_error($conn)]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'status'  => 'complete_manual',
        'message' => 'Status berhasil diubah menjadi COMPLETE (Manual).'
    ]);
    exit;
}

// action === 'unset' -> hapus flag, kembali mengikuti status berdasarkan qty
$sqlDelete = "DELETE FROM kso_manual_complete WHERE order_no = ? AND inventory_id = ?";

$stmtDelete = mysqli_prepare($conn, $sqlDelete);
if (!$stmtDelete) {
    echo json_encode(['success' => false, 'message' => 'Prepare gagal: ' . mysqli_error($conn)]);
    exit;
}

mysqli_stmt_bind_param($stmtDelete, 'ss', $orderNo, $inventoryId);
$ok = mysqli_stmt_execute($stmtDelete);
mysqli_stmt_close($stmtDelete);

if (!$ok) {
    echo json_encode(['success' => false, 'message' => 'Gagal membatalkan status Complete Manual: ' . mysqli_error($conn)]);
    exit;
}

echo json_encode([
    'success' => true,
    'status'  => 'reverted',
    'message' => 'Status Complete Manual berhasil dibatalkan.'
]);
exit;
