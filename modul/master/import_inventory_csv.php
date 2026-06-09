<?php
// import_inventory_csv.php
// Script untuk import master data inventory dari file CSV
// Author: Heron
// Features: Validasi data, prevent SQL injection, handle empty fields, duplicate detection

session_start();
header('Content-Type: application/json');

// Koneksi database (sesuaikan dengan koneksi existing Anda)
require_once('koneksi.php'); // atau lokasi file koneksi Anda

// Cek request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Validasi file upload
if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload failed or no file selected']);
    exit;
}

$file = $_FILES['csv_file']['tmp_name'];
$filename = $_FILES['csv_file']['name'];

// Validasi ekstensi file
if (!preg_match('/\.csv$/i', $filename)) {
    echo json_encode(['success' => false, 'message' => 'File harus berekstensi .csv']);
    exit;
}

// Validasi file size (max 5MB)
if ($_FILES['csv_file']['size'] > 5242880) {
    echo json_encode(['success' => false, 'message' => 'File size terlalu besar (max 5MB)']);
    exit;
}

try {
    // Buka file CSV
    if (!is_readable($file)) {
        throw new Exception('File tidak dapat dibaca');
    }
    
    $handle = fopen($file, 'r');
    if ($handle === false) {
        throw new Exception('Gagal membuka file CSV');
    }
    
    // Array untuk menyimpan hasil import
    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $row_num = 0;
    
    // Header mapping (sesuaikan dengan urutan kolom CSV Anda)
    $expected_headers = [
        'inventory_id', 'inventory_name', 'uom', 'type', 'category', 'remarks', 
        'cap', 'colour', 'quality', 'volume_default', 'uom_pack', 'conversion_rate',
        'base_uom', 'pack_uom', 'tolerance', 'upper_tolerance', 'lower_tolerance',
        'merk', 'p', 'l', 't', 'p2', 'density', 'description', 'origin', 'status',
        'supp_code', 're_order_point', 'minimum_stock', 'maximum_stock', 'shelf_life_days',
        'is_sub', 'is_job_order', 'dont_show_at_w48', 'stokan', 'internal_name',
        'catalog', 'part_no', 'printing_type', 'calculation', 'nama_customer',
        'type_rm', 'tebal', 'ukuran', 'strength', 'create_user', 'date_created',
        'user_modified', 'date_modified', 'ket_las'
    ];
    
    while (($row = fgetcsv($handle, 4096, ',', '"')) !== false) {
        $row_num++;
        
        // Skip header row (baris pertama)
        if ($row_num === 1) {
            // Optional: Validasi header
            // if ($row !== $expected_headers) {
            //     throw new Exception('Format CSV tidak sesuai dengan template');
            // }
            continue;
        }
        
        // Skip empty rows
        if (empty(array_filter($row))) {
            continue;
        }
        
        // Validasi minimal: inventory_id dan inventory_name harus ada
        if (empty(trim($row[0])) || empty(trim($row[1]))) {
            $error_count++;
            $errors[] = "Baris " . $row_num . ": inventory_id atau inventory_name kosong";
            continue;
        }
        
        // Mapping data dari CSV ke variable
        $data = [
            'inventory_id' => trim($row[0]),
            'inventory_name' => trim($row[1]),
            'uom' => !empty(trim($row[2])) ? trim($row[2]) : NULL,
            'type' => !empty(trim($row[3])) ? trim($row[3]) : NULL,
            'category' => !empty(trim($row[4])) ? trim($row[4]) : NULL,
            'remarks' => !empty(trim($row[5])) ? trim($row[5]) : NULL,
            'cap' => !empty(trim($row[6])) ? trim($row[6]) : NULL,
            'colour' => !empty(trim($row[7])) ? trim($row[7]) : NULL,
            'quality' => !empty(trim($row[8])) ? trim($row[8]) : NULL,
            'volume_default' => !empty(trim($row[9])) ? (float)$row[9] : 1.0000,
            'uom_pack' => !empty(trim($row[10])) ? trim($row[10]) : NULL,
            'conversion_rate' => !empty(trim($row[11])) ? (float)$row[11] : 1.0000,
            'base_uom' => !empty(trim($row[12])) ? trim($row[12]) : 'KG',
            'pack_uom' => !empty(trim($row[13])) ? trim($row[13]) : 'PCS',
            'tolerance' => !empty(trim($row[14])) ? (int)$row[14] : 0,
            'upper_tolerance' => !empty(trim($row[15])) ? (float)$row[15] : 0.00,
            'lower_tolerance' => !empty(trim($row[16])) ? (float)$row[16] : 0.00,
            'merk' => !empty(trim($row[17])) ? trim($row[17]) : NULL,
            'p' => !empty(trim($row[18])) ? (float)$row[18] : 0.00,
            'l' => !empty(trim($row[19])) ? (float)$row[19] : 0.00,
            't' => !empty(trim($row[20])) ? (float)$row[20] : 0.00,
            'p2' => !empty(trim($row[21])) ? (float)$row[21] : 0.00,
            'density' => !empty(trim($row[22])) ? (float)$row[22] : 0.00,
            'description' => !empty(trim($row[23])) ? trim($row[23]) : NULL,
            'origin' => !empty(trim($row[24])) ? trim($row[24]) : NULL,
            'status' => !empty(trim($row[25])) ? trim($row[25]) : 'Active',
            'supp_code' => !empty(trim($row[26])) ? trim($row[26]) : NULL,
            're_order_point' => !empty(trim($row[27])) ? (float)$row[27] : 0.00,
            'minimum_stock' => !empty(trim($row[28])) ? (float)$row[28] : 0.00,
            'maximum_stock' => !empty(trim($row[29])) ? (float)$row[29] : 0.00,
            'shelf_life_days' => !empty(trim($row[30])) ? (int)$row[30] : 0,
            'is_sub' => !empty(trim($row[31])) ? trim($row[31]) : 'Unchecked',
            'is_job_order' => !empty(trim($row[32])) ? trim($row[32]) : 'Unchecked',
            'dont_show_at_w48' => !empty(trim($row[33])) ? trim($row[33]) : 'Unchecked',
            'stokan' => !empty(trim($row[34])) ? trim($row[34]) : 'Unchecked',
            'internal_name' => !empty(trim($row[35])) ? trim($row[35]) : NULL,
            'catalog' => !empty(trim($row[36])) ? trim($row[36]) : NULL,
            'part_no' => !empty(trim($row[37])) ? trim($row[37]) : NULL,
            'printing_type' => !empty(trim($row[38])) ? trim($row[38]) : NULL,
            'calculation' => !empty(trim($row[39])) ? trim($row[39]) : NULL,
            'nama_customer' => !empty(trim($row[40])) ? trim($row[40]) : NULL,
            'type_rm' => !empty(trim($row[41])) ? trim($row[41]) : NULL,
            'tebal' => !empty(trim($row[42])) ? (float)$row[42] : 0.0000,
            'ukuran' => !empty(trim($row[43])) ? trim($row[43]) : NULL,
            'strength' => !empty(trim($row[44])) ? trim($row[44]) : NULL,
            'create_user' => !empty(trim($row[45])) ? trim($row[45]) : $_SESSION['username'] ?? 'SYSTEM',
            'date_created' => !empty(trim($row[46])) ? trim($row[46]) : date('Y-m-d H:i:s'),
            'user_modified' => !empty(trim($row[47])) ? trim($row[47]) : NULL,
            'date_modified' => !empty(trim($row[48])) ? trim($row[48]) : NULL,
            'ket_las' => !empty(trim($row[49])) ? trim($row[49]) : NULL,
        ];
        
        // Cek duplikat berdasarkan inventory_id
        $check_query = "SELECT inventory_id FROM m_inventory WHERE inventory_id = ? LIMIT 1";
        $check_stmt = $conn->prepare($check_query);
        
        if (!$check_stmt) {
            throw new Exception('Prepare statement gagal: ' . $conn->error);
        }
        
        $check_stmt->bind_param('s', $data['inventory_id']);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            // Data sudah ada, skip atau update (di sini skip)
            $error_count++;
            $errors[] = "Baris " . $row_num . ": inventory_id '{$data['inventory_id']}' sudah ada";
            $check_stmt->close();
            continue;
        }
        $check_stmt->close();
        
        // Prepare INSERT statement
        $insert_query = "INSERT INTO m_inventory (
            inventory_id, inventory_name, uom, type, category, remarks,
            cap, colour, quality, volume_default, uom_pack, conversion_rate,
            base_uom, pack_uom, tolerance, upper_tolerance, lower_tolerance,
            merk, p, l, t, p2, density, description, origin, status,
            supp_code, re_order_point, minimum_stock, maximum_stock, shelf_life_days,
            is_sub, is_job_order, dont_show_at_w48, stokan, internal_name,
            catalog, part_no, printing_type, calculation, nama_customer,
            type_rm, tebal, ukuran, strength, create_user, date_created,
            user_modified, date_modified, ket_las
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($insert_query);
        
        if (!$stmt) {
            $error_count++;
            $errors[] = "Baris " . $row_num . ": Prepare statement gagal - " . $conn->error;
            continue;
        }
        
        // Bind parameters dengan type string (s = string, d = double, i = integer)
        // Order harus sesuai dengan query INSERT
        $bind_types = 'sssssssssdssssdddsdddddsssssdsddissssssssssdsssss';
        
        $bind_params = [
            $data['inventory_id'],
            $data['inventory_name'],
            $data['uom'],
            $data['type'],
            $data['category'],
            $data['remarks'],
            $data['cap'],
            $data['colour'],
            $data['quality'],
            $data['volume_default'],
            $data['uom_pack'],
            $data['conversion_rate'],
            $data['base_uom'],
            $data['pack_uom'],
            $data['tolerance'],
            $data['upper_tolerance'],
            $data['lower_tolerance'],
            $data['merk'],
            $data['p'],
            $data['l'],
            $data['t'],
            $data['p2'],
            $data['density'],
            $data['description'],
            $data['origin'],
            $data['status'],
            $data['supp_code'],
            $data['re_order_point'],
            $data['minimum_stock'],
            $data['maximum_stock'],
            $data['shelf_life_days'],
            $data['is_sub'],
            $data['is_job_order'],
            $data['dont_show_at_w48'],
            $data['stokan'],
            $data['internal_name'],
            $data['catalog'],
            $data['part_no'],
            $data['printing_type'],
            $data['calculation'],
            $data['nama_customer'],
            $data['type_rm'],
            $data['tebal'],
            $data['ukuran'],
            $data['strength'],
            $data['create_user'],
            $data['date_created'],
            $data['user_modified'],
            $data['date_modified'],
            $data['ket_las'],
        ];
        
        // Bind dengan array unpacking
        $stmt->bind_param($bind_types, ...$bind_params);
        
        try {
            if ($stmt->execute()) {
                $success_count++;
            } else {
                $error_count++;
                $errors[] = "Baris " . $row_num . ": Execute gagal - " . $stmt->error;
            }
        } catch (Exception $e) {
            $error_count++;
            $errors[] = "Baris " . $row_num . ": " . $e->getMessage();
        }
        
        $stmt->close();
    }
    
    fclose($handle);
    
    // Buat response message
    $message = "Import selesai! " . $success_count . " data berhasil diimport";
    
    if ($error_count > 0) {
        $message .= ", " . $error_count . " data gagal.";
    } else {
        $message .= ".";
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'success_count' => $success_count,
        'error_count' => $error_count,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

$conn->close();
?>