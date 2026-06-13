<?php
// ===== FILE: C:\xampp\htdocs\cahaya\modul\master\import_inventory_uom_csv.php =====
// ===== IMPORT CSV MULTIPLE UOM (SINKRON TABEL m_inventory_uom) =====

session_start();

// Error reporting untuk debugging internal sistem
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan ke browser untuk menjaga format JSON
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/import_uom_error.log');

header('Content-Type: application/json; charset=utf-8');

// Function untuk send JSON response standar
function sendJsonResponse($success, $message, $data = []) {
    // Bersihkan output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $response = array_merge([
        'success' => $success,
        'message' => $message
    ], $data);
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// Function untuk clean value (kosong menjadi null atau default numerik)
function cleanValue($value, $type = 'string') {
    if (!isset($value) || $value === '' || $value === '-' || $value === 'NULL') {
        return null;
    }
    
    $value = trim($value);
    
    if ($type === 'int') {
        return (int)$value;
    } elseif ($type === 'float' || $type === 'decimal') {
        return (float)$value;
    }
    
    return $value;
}

try {
    // ==================== 1. VALIDASI SESSION ====================
    if (!isset($_SESSION['username'])) {
        sendJsonResponse(false, 'Session tidak valid. Silakan login kembali.');
    }
    
    // ==================== 2. KONEKSI DATABASE ====================
    $koneksi_path = __DIR__ . '/../../koneksi.php';
    if (!file_exists($koneksi_path)) {
        sendJsonResponse(false, 'File koneksi.php tidak ditemukan');
    }
    
    require_once $koneksi_path;
    
    if (!isset($conn) || !$conn) {
        sendJsonResponse(false, 'Koneksi database gagal');
    }
    
    // ==================== 3. VALIDASI REQUEST METHOD ====================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Method tidak diizinkan. Gunakan POST.');
    }
    
    // ==================== 4. VALIDASI FILE UPLOAD ====================
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error_code = isset($_FILES['csv_file']['error']) ? $_FILES['csv_file']['error'] : 'unknown';
        sendJsonResponse(false, 'Upload file gagal. Error code: ' . $error_code);
    }
    
    $tmp_file = $_FILES['csv_file']['tmp_name'];
    $file_name = $_FILES['csv_file']['name'];
    $file_size = $_FILES['csv_file']['size'];
    
    // Validasi ekstensi file
    if (!preg_match('/\.csv$/i', $file_name)) {
        sendJsonResponse(false, 'File harus berekstensi .csv');
    }
    
    // Validasi ukuran file (max 10MB)
    if ($file_size > 10485760) {
        sendJsonResponse(false, 'Ukuran file terlalu besar (max 10MB)');
    }
    
    // ==================== 5. BUKA DAN BACA CSV ====================
    $handle = fopen($tmp_file, 'r');
    if (!$handle) {
        sendJsonResponse(false, 'Gagal membuka file CSV');
    }
    
    // Matikan foreign key check sementara untuk kelancaran transaksi massal
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");
    
    // ==================== 6. BACA HEADER (Baris Pertama) ====================
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    $row_num = 1;
    
    if (!$headers) {
        fclose($handle);
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        sendJsonResponse(false, 'Struktur baris pertama/header CSV kosong atau rusak');
    }

    // Normalisasi nama kolom header ke lowercase agar pencocokan indeks fleksibel
    $headers = array_map('strtolower', $headers);
    
    // Deteksi posisi indeks kolom penentu utama
    $idx_inventory_id = array_search('inventory_id', $headers);
    $idx_default_uom  = array_search('default_uom', $headers);

    if ($idx_inventory_id === FALSE || $idx_default_uom === FALSE) {
        fclose($handle);
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        sendJsonResponse(false, 'Gagal memetakan file! Kolom "inventory_id" atau "default_uom" tidak ditemukan pada header CSV.');
    }

    // Daftar nama kolom UOM sampingan yang valid untuk dipindai nilainya
    $uom_list = ['roll', 'kg', 'bal', 'zak', 'ikt', 'pak', 'lbr', 'm2'];
    $uom_indexes = [];
    foreach ($uom_list as $uom_name) {
        $idx = array_search($uom_name, $headers);
        if ($idx !== FALSE) {
            $uom_indexes[$uom_name] = $idx;
        }
    }

    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $warnings = [];
    
    // Prepare statement untuk INSERT data ke m_inventory_uom sesuai relasi tabel baru Anda
    $sql = "INSERT INTO m_inventory_uom (inventory_id, Uom, `Default`, `Value`) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        fclose($handle);
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        sendJsonResponse(false, 'Prepare statement gagal: ' . $conn->error);
    }
    
    // ==================== 7. PROSES SETIAP BARIS CSV ====================
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $row_num++;
        
        // Skip baris kosong atau tidak lengkap
        if (count($row) < 2 || (count($row) == 1 && empty(trim($row[0])))) {
            continue;
        }
        
        $inventory_id = isset($row[$idx_inventory_id]) ? trim($row[$idx_inventory_id]) : '';
        $default_uom  = isset($row[$idx_default_uom]) ? strtoupper(trim($row[$idx_default_uom])) : '';
        
        // Validasi data wajib baris
        if (empty($inventory_id)) {
            $error_count++;
            $errors[] = "Baris $row_num: Kolom inventory_id kosong";
            continue;
        }
        
        if (empty($default_uom)) {
            $error_count++;
            $errors[] = "Baris $row_num: Kolom default_uom kosong untuk ID '{$inventory_id}'";
            continue;
        }

        // Warning tracker: Berikan peringatan jika ID barang tidak terdaftar di master item m_inventory
        $inv_check = $conn->prepare("SELECT inventory_id FROM m_inventory WHERE inventory_id = ?");
        $inv_check->bind_param("s", $inventory_id);
        $inv_check->execute();
        if ($inv_check->get_result()->num_rows === 0) {
            $warnings[] = "Baris $row_num: Inventory ID '{$inventory_id}' belum terdaftar di tabel master m_inventory.";
        }
        $inv_check->close();

        // Bersihkan seluruh relasi record UOM lama untuk ID ini agar aman dari redudansi data ganda
        $del_stmt = $conn->prepare("DELETE FROM m_inventory_uom WHERE inventory_id = ?");
        $del_stmt->bind_param("s", $inventory_id);
        $del_stmt->execute();
        $del_stmt->close();
        
        // Memecah mapping data kolom horizontal menjadi entri baris tabel m_inventory_uom
        foreach ($uom_indexes as $uom_name => $idx) {
            $raw_value = isset($row[$idx]) ? $row[$idx] : '';
            $value = cleanValue($raw_value, 'decimal') ?? 0.0000;
            $uom_upper = strtoupper($uom_name);

            // Kondisi simpan: nilai konversi > 0 ATAU UOM tersebut merupakan default bawaan item
            if ($value > 0 || $uom_upper === $default_uom) {
                $is_default = ($uom_upper === $default_uom) ? 1 : 0;
                
                // Bind parameter: s (string inventory_id), s (string Uom), i (int Default), d (decimal Value)
                $stmt->bind_param("ssid", $inventory_id, $uom_upper, $is_default, $value);
                
                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $error_count++;
                    $errors[] = "Baris $row_num (Satuan $uom_upper): " . $stmt->error;
                }
            }
        }
    }
    
    // ==================== 8. CLEANUP ====================
    $stmt->close();
    fclose($handle);
    
    // Kembalikan status proteksi foreign key check
    $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    
    // ==================== 9. RESPONSE DATA GENERATOR ====================
    $message = "Proses import data UOM selesai!";
    $status = true;
    
    if ($success_count > 0) {
        $message .= " Berhasil memproses $success_count entri satuan baru.";
        if ($error_count > 0) {
            $message .= " Terdeteksi $error_count kegagalan baris data.";
        }
    } else {
        $message .= " Tidak ada entri data satuan yang masuk.";
        $status = false;
    }
    
    if (!empty($warnings)) {
        $message .= " Menemukan " . count($warnings) . " catatan peringatan data relasi.";
    }
    
    sendJsonResponse($status, $message, [
        'success_count'   => $success_count,
        'error_count'     => $error_count,
        'warnings'        => array_slice($warnings, 0, 30),
        'errors'          => array_slice($errors, 0, 30),
        'total_processed' => $row_num - 1
    ]);
    
} catch (Exception $e) {
    if (isset($conn) && $conn) {
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    }
    sendJsonResponse(false, 'Terjadi kegagalan fatal pada sistem: ' . $e->getMessage());
}

if (isset($conn) && $conn) {
    $conn->close();
}
?>