<?php
// modul/transaksi/generate_numbers.php
// Endpoint AJAX untuk generate PO Number yang aman dari race condition
session_start();
if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

include __DIR__ . '/../../koneksi.php';

$type = $_GET['type'] ?? '';

// ── Generate PO Number ──────────────────────────────────────────────
function generatePONumber($conn) {
    $tahun = date('Y');

    // Lock dengan transaction untuk hindari race condition
    mysqli_begin_transaction($conn);

    try {
        // Lock tabel sementara baca nomor terakhir
        $q = mysqli_query($conn, "SELECT no_po FROM hed_po 
                                  WHERE YEAR(created_at) = '$tahun' 
                                  ORDER BY id DESC LIMIT 1 
                                  FOR UPDATE");

        if (!$q) throw new Exception('Query error: ' . mysqli_error($conn));

        $row = mysqli_fetch_assoc($q);

        if ($row) {
            // Ambil bagian nomor urut (sebelum /PO/)
            $parts = explode('/', $row['no_po']);
            $urut  = intval($parts[0]) + 1;
        } else {
            $urut = 1;
        }

        $no_po = str_pad($urut, 3, '0', STR_PAD_LEFT) . '/PO/' . $tahun;

        // Cek apakah nomor ini sudah ada (double check)
        $cek = mysqli_query($conn, "SELECT no_po FROM hed_po WHERE no_po = '$no_po' LIMIT 1");
        if (mysqli_num_rows($cek) > 0) {
            // Kalau sudah ada, increment lagi
            $urut++;
            $no_po = str_pad($urut, 3, '0', STR_PAD_LEFT) . '/PO/' . $tahun;
        }

        mysqli_commit($conn);
        return $no_po;

    } catch (Exception $e) {
        mysqli_rollback($conn);
        return null;
    }
}


// ── Response ────────────────────────────────────────────────────────
header('Content-Type: application/json');

if ($type === 'po') {
    $no = generatePONumber($conn);
    echo json_encode($no ? ['success' => true, 'number' => $no] : ['error' => 'Gagal generate PO Number']);

} else {
    echo json_encode(['error' => 'Type tidak valid. Gunakan: po']);
}