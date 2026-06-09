<?php
// get_detail_po.php
session_start();
if (!isset($_SESSION['username'])) {
    echo json_encode([]);
    exit;
}

include __DIR__ . '/../../koneksi.php';

$no_po = mysqli_real_escape_string($conn, $_GET['no_po'] ?? '');
$data = [];

if ($no_po) {
    // Query ke tabel det_po (sudah benar)
    $query = mysqli_query($conn, "SELECT ukuran, jml_order, harga, harga_kg FROM det_po WHERE no_po='$no_po'");
    
    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $data[] = [
                'ukuran' => $row['ukuran'],
                'jml_order' => $row['jml_order'],
                'harga' => $row['harga'],
                'harga_kg' => $row['harga_kg']
            ];
        }
    }
}

header('Content-Type: application/json');
echo json_encode($data);
?>