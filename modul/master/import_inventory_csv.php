<?php
// ===== FILE: C:\xampp\htdocs\cahaya\modul\master\import_inventory_csv.php =====
// ===== IMPORT CSV LENGKAP DENGAN SEMUA KOLOM (SINKRON 47 KOLOM BARU) =====
// ===== VERSI OPTIMASI: Bulk pre-check (tidak ada query per-baris di dalam loop) =====

session_start();

// Error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Jangan tampilkan ke browser
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/import_error.log');

header('Content-Type: application/json; charset=utf-8');

// Function untuk send JSON response
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

// Function untuk clean value (kosong menjadi null)
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

    $tmp_file  = $_FILES['csv_file']['tmp_name'];
    $file_name = $_FILES['csv_file']['name'];
    $file_size = $_FILES['csv_file']['size'];

    // Validasi ekstensi file
    if (!preg_match('/\.csv$/i', $file_name)) {
        sendJsonResponse(false, 'File harus berekstensi .csv');
    }

    // Validasi ukuran file (max 50MB).
    // Catatan: naikkan/samakan juga dengan upload_max_filesize & post_max_size di php.ini,
    // kalau tidak, file besar akan gagal duluan sebelum sampai baris ini ($_FILES kosong).
    $max_file_size = 52428800; // 50 MB
    if ($file_size > $max_file_size) {
        sendJsonResponse(false, 'Ukuran file terlalu besar (max ' . round($max_file_size / 1048576) . 'MB)');
    }

    // Naikkan batas eksekusi & memori khusus untuk request import ini saja
    // (tidak mengubah php.ini global, hanya berlaku untuk script ini).
    @set_time_limit(300);
    @ini_set('memory_limit', '256M');

    // ==================== 5. BUKA CSV ====================
    $handle = fopen($tmp_file, 'r');
    if (!$handle) {
        sendJsonResponse(false, 'Gagal membuka file CSV');
    }

    // ==================== 6. BACA HEADER (Baris Pertama) ====================
    $headers = fgetcsv($handle, 0, ',', '"', '\\');

    // ==================== 7. PASS 1: BACA SELURUH BARIS KE MEMORY & KUMPULKAN ID/CATEGORY ====================
    // File CSV max 50MB masih aman di-load penuh ke memory (bukan file raksasa berjuta baris).
    // Tujuannya: kumpulkan semua inventory_id & category dulu, supaya bisa dicek sekaligus
    // lewat satu query bulk, bukan query per-baris di dalam loop utama.
    $rows = [];
    $csv_ids = [];
    $csv_categories = [];
    $row_num = 1;

    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $row_num++;

        // Skip baris kosong
        if (count($row) < 2 || (count($row) == 1 && empty(trim($row[0])))) {
            continue;
        }

        $inventory_id   = isset($row[0]) ? trim($row[0]) : '';
        $inventory_name = isset($row[1]) ? trim($row[1]) : '';

        $rows[] = [
            'row_num' => $row_num,
            'data'    => $row,
        ];

        if ($inventory_id !== '') {
            $csv_ids[$inventory_id] = true;
        }

        $category = cleanValue($row[4] ?? null);
        if ($category !== null) {
            $csv_categories[$category] = true;
        }
    }
    fclose($handle);

    if (empty($rows)) {
        sendJsonResponse(false, 'File CSV tidak memiliki data (hanya header atau kosong).');
    }

    // ==================== 8. BULK CHECK: inventory_id yang SUDAH ADA di database ====================
    $existing_ids = [];
    $id_list = array_keys($csv_ids);

    // Query IN(...) dipecah per-batch (500 id per batch) untuk menghindari query yang terlalu panjang.
    foreach (array_chunk($id_list, 500) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $types = str_repeat('s', count($chunk));

        $stmt_check = $conn->prepare("SELECT inventory_id FROM m_inventory WHERE inventory_id IN ($placeholders)");
        if (!$stmt_check) {
            sendJsonResponse(false, 'Prepare bulk check ID gagal: ' . $conn->error);
        }
        $stmt_check->bind_param($types, ...$chunk);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();
        while ($r = $res_check->fetch_assoc()) {
            $existing_ids[$r['inventory_id']] = true;
        }
        $stmt_check->close();
    }

    // ==================== 9. BULK CHECK: category yang VALID (ada di m_category) ====================
    $valid_categories = [];
    $category_list = array_keys($csv_categories);

    if (!empty($category_list)) {
        foreach (array_chunk($category_list, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $types = str_repeat('s', count($chunk));

            $stmt_cat = $conn->prepare("SELECT categori_id FROM m_category WHERE categori_id IN ($placeholders)");
            if (!$stmt_cat) {
                sendJsonResponse(false, 'Prepare bulk check category gagal: ' . $conn->error);
            }
            $stmt_cat->bind_param($types, ...$chunk);
            $stmt_cat->execute();
            $res_cat = $stmt_cat->get_result();
            while ($r = $res_cat->fetch_assoc()) {
                $valid_categories[$r['categori_id']] = true;
            }
            $stmt_cat->close();
        }
    }

    // ==================== 10. PREPARE STATEMENT INSERT (dipakai berulang di loop) ====================
    $sql = "INSERT INTO m_inventory (
        inventory_id, inventory_name, uom, type, category, remarks,
        cap, colour, quality, volume_default, uom_pack, conversion_rate,
        base_uom, pack_uom, tolerance, upper_tolerance, lower_tolerance,
        merk, p, l, t, p2, density, description, origin, status,
        supp_code, re_order_point, minimum_stock, maximum_stock, dont_show_at_w48,
        stokan, internal_name, catalog, part_no, printing_type, calculation,
        nama_customer, type_rm, tebal, ukuran, strength, create_user,
        date_created, user_modified, date_modified, ket_las
    ) VALUES (
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
        ?, ?, ?, ?, ?, ?, ?
    )";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        sendJsonResponse(false, 'Prepare statement insert gagal: ' . $conn->error);
    }

    // Matikan foreign key check sementara untuk menghindari error constraint
    $conn->query("SET FOREIGN_KEY_CHECKS = 0");

    // Pakai transaction supaya insert massal lebih cepat (commit sekali di akhir)
    // dan lebih aman kalau terjadi error fatal di tengah jalan.
    $conn->begin_transaction();

    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $warnings = [];
    $seen_in_file = []; // deteksi duplikat ID di dalam file CSV itu sendiri

    // ==================== 11. PASS 2: PROSES INSERT PER BARIS (TANPA QUERY DI DALAM LOOP) ====================
    foreach ($rows as $item) {
        $row_num = $item['row_num'];
        $row = $item['data'];

        $inventory_id   = isset($row[0]) ? trim($row[0]) : '';
        $inventory_name = isset($row[1]) ? trim($row[1]) : '';

        if (empty($inventory_id)) {
            $error_count++;
            $errors[] = "Baris $row_num: inventory_id kosong";
            continue;
        }

        if (empty($inventory_name)) {
            $error_count++;
            $errors[] = "Baris $row_num: inventory_name kosong untuk ID '{$inventory_id}'";
            continue;
        }

        // Cek duplikat terhadap database (hasil bulk check di Pass 1)
        if (isset($existing_ids[$inventory_id])) {
            $error_count++;
            $errors[] = "Baris $row_num: inventory_id '{$inventory_id}' sudah ada di database (skip)";
            continue;
        }

        // Cek duplikat di dalam file CSV itu sendiri (baris ganda)
        if (isset($seen_in_file[$inventory_id])) {
            $error_count++;
            $errors[] = "Baris $row_num: inventory_id '{$inventory_id}' duplikat di dalam file CSV (skip)";
            continue;
        }
        $seen_in_file[$inventory_id] = true;

        // Mapping data dari CSV
        $data = [
            'inventory_id'     => $inventory_id,
            'inventory_name'   => $inventory_name,
            'uom'              => cleanValue($row[2] ?? null),
            'type'             => cleanValue($row[3] ?? null),
            'category'         => cleanValue($row[4] ?? null),
            'remarks'          => cleanValue($row[5] ?? null),
            'cap'              => cleanValue($row[6] ?? null),
            'colour'           => cleanValue($row[7] ?? null),
            'quality'          => cleanValue($row[8] ?? null),
            'volume_default'   => cleanValue($row[9] ?? null, 'decimal') ?? 1.0000,
            'uom_pack'         => cleanValue($row[10] ?? null),
            'conversion_rate'  => cleanValue($row[11] ?? null),
            'base_uom'         => cleanValue($row[12] ?? null) ?? 'KG',
            'pack_uom'         => cleanValue($row[13] ?? null) ?? 'PCS',
            'tolerance'        => cleanValue($row[14] ?? null, 'int') ?? 0,
            'upper_tolerance'  => cleanValue($row[15] ?? null, 'decimal') ?? 0.00,
            'lower_tolerance'  => cleanValue($row[16] ?? null, 'decimal') ?? 0.00,
            'merk'             => cleanValue($row[17] ?? null),
            'p'                => cleanValue($row[18] ?? null, 'decimal') ?? 0.00,
            'l'                => cleanValue($row[19] ?? null, 'decimal') ?? 0.00,
            't'                => cleanValue($row[20] ?? null, 'decimal') ?? 0.00,
            'p2'               => cleanValue($row[21] ?? null, 'decimal') ?? 0.00,
            'density'          => cleanValue($row[22] ?? null, 'decimal') ?? 0.00,
            'description'      => cleanValue($row[23] ?? null),
            'origin'           => cleanValue($row[24] ?? null),
            'status'           => cleanValue($row[25] ?? null) ?? 'Active',
            'supp_code'        => cleanValue($row[26] ?? null),
            're_order_point'   => cleanValue($row[27] ?? null, 'decimal') ?? 0.00,
            'minimum_stock'    => cleanValue($row[28] ?? null, 'decimal') ?? 0.00,
            'maximum_stock'    => cleanValue($row[29] ?? null, 'decimal') ?? 0.00,
            'dont_show_at_w48' => cleanValue($row[30] ?? null) ?? 'Unchecked',
            'stokan'           => cleanValue($row[31] ?? null) ?? 'Unchecked',
            'internal_name'    => cleanValue($row[32] ?? null),
            'catalog'          => cleanValue($row[33] ?? null),
            'part_no'          => cleanValue($row[34] ?? null),
            'printing_type'    => cleanValue($row[35] ?? null),
            'calculation'      => cleanValue($row[36] ?? null),
            'nama_customer'    => cleanValue($row[37] ?? null),
            'type_rm'          => cleanValue($row[38] ?? null),
            'tebal'            => cleanValue($row[39] ?? null, 'decimal') ?? 0.0000,
            'ukuran'           => cleanValue($row[40] ?? null),
            'strength'         => cleanValue($row[41] ?? null),
            'create_user'      => cleanValue($row[42] ?? null) ?? $_SESSION['username'],
            'date_created'     => cleanValue($row[43] ?? null) ?? date('Y-m-d H:i:s'),
            'user_modified'    => cleanValue($row[44] ?? null),
            'date_modified'    => cleanValue($row[45] ?? null),
            'ket_las'          => cleanValue($row[46] ?? null),
        ];

        // Pemastian konversi tipe data
        $data['volume_default']  = (float)$data['volume_default'];
        $data['tolerance']       = (int)$data['tolerance'];
        $data['upper_tolerance'] = (float)$data['upper_tolerance'];
        $data['lower_tolerance'] = (float)$data['lower_tolerance'];
        $data['p']               = (float)$data['p'];
        $data['l']               = (float)$data['l'];
        $data['t']               = (float)$data['t'];
        $data['p2']              = (float)$data['p2'];
        $data['density']         = (float)$data['density'];
        $data['re_order_point']  = (float)$data['re_order_point'];
        $data['minimum_stock']   = (float)$data['minimum_stock'];
        $data['maximum_stock']   = (float)$data['maximum_stock'];
        $data['tebal']           = (float)$data['tebal'];

        // Bind parameters (47 kolom, semua tipe 's' untuk kemudahan mapping)
        $types = str_repeat('s', 47);
        $params = [$types];
        foreach ($data as $key => $value) {
            $params[] = &$data[$key];
        }
        call_user_func_array([$stmt, 'bind_param'], $params);

        if ($stmt->execute()) {
            $success_count++;

            // Warning category tidak ditemukan — dicek dari hasil bulk check (Pass 1),
            // tanpa query tambahan.
            if ($data['category'] !== null && !isset($valid_categories[$data['category']])) {
                $warnings[] = "Baris $row_num: Category '{$data['category']}' tidak ditemukan di tabel referensi.";
            }
        } else {
            $error_count++;
            $errors[] = "Baris $row_num: " . $stmt->error;
        }
    }

    // ==================== 12. CLEANUP & COMMIT ====================
    $stmt->close();

    if ($success_count > 0) {
        $conn->commit();
    } else {
        $conn->rollback();
    }

    $conn->query("SET FOREIGN_KEY_CHECKS = 1");

    // ==================== 13. RESPONSE ====================
    $message = "Import selesai!";
    $status = true;

    if ($success_count > 0) {
        $message .= " $success_count data berhasil diimport.";
        if ($error_count > 0) {
            $message .= " $error_count data gagal (duplicate atau error).";
        }
    } else {
        $message .= " Tidak ada data yang berhasil diimport.";
        $status = false;
    }

    if (!empty($warnings)) {
        $message .= " " . count($warnings) . " warning(s) terdeteksi.";
    }

    sendJsonResponse($status, $message, [
        'success_count'   => $success_count,
        'error_count'     => $error_count,
        'warnings'        => array_slice($warnings, 0, 30),
        'errors'          => array_slice($errors, 0, 30),
        'total_processed' => count($rows),
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        if ($conn->connect_error === null) {
            @$conn->rollback();
        }
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
    }
    sendJsonResponse(false, 'Error fatal: ' . $e->getMessage());
}

if (isset($conn) && $conn) {
    $conn->close();
}