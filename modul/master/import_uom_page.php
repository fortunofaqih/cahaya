<?php
// ===== FILE: modul/master/import_inventory_uom_csv.php =====
// ===== IMPORT CSV UOM FORMAT VERTICAL =====
// Format CSV:
// inventory_id,unit,Default,Value
// FG/PE1-009492,KG,1,1
// FG/PE1-009492,ROLL,0,25

session_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/import_uom_error.log');

header('Content-Type: application/json; charset=utf-8');

function sendJsonResponse($success, $message, $data = []) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $data), JSON_PRETTY_PRINT);

    exit;
}

function normalizeHeader($header) {
    $header = trim($header);

    // Hilangkan BOM UTF-8 jika ada
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);

    $header = strtolower($header);
    $header = str_replace([' ', '-', '.'], '_', $header);

    return $header;
}

function cleanDecimal($value) {
    if (!isset($value)) {
        return 0.0000;
    }

    $value = trim((string)$value);

    if ($value === '' || $value === '-' || strtoupper($value) === 'NULL') {
        return 0.0000;
    }

    // Antisipasi format Indonesia: 1.000,50
    // Kalau ada koma, anggap koma sebagai desimal dan titik sebagai ribuan
    if (strpos($value, ',') !== false) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float)$value;
}

function cleanDefault($value) {
    if (!isset($value)) {
        return 0;
    }

    $value = strtoupper(trim((string)$value));

    if ($value === '1' || $value === 'YES' || $value === 'TRUE' || $value === 'CHECKED' || $value === 'DEFAULT') {
        return 1;
    }

    return 0;
}

try {
    // ==================== 1. VALIDASI SESSION ====================
    if (!isset($_SESSION['username'])) {
        sendJsonResponse(false, 'Session tidak valid. Silakan login kembali.');
    }

    // ==================== 2. KONEKSI DATABASE ====================
    $koneksi_path = __DIR__ . '/../../koneksi.php';

    if (!file_exists($koneksi_path)) {
        sendJsonResponse(false, 'File koneksi.php tidak ditemukan.');
    }

    require_once $koneksi_path;

    if (!isset($conn) || !$conn) {
        sendJsonResponse(false, 'Koneksi database gagal.');
    }

    // ==================== 3. VALIDASI METHOD ====================
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Method tidak diizinkan. Gunakan POST.');
    }

    // ==================== 4. VALIDASI FILE ====================
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error_code = isset($_FILES['csv_file']['error']) ? $_FILES['csv_file']['error'] : 'unknown';
        sendJsonResponse(false, 'Upload file gagal. Error code: ' . $error_code);
    }

    $tmp_file = $_FILES['csv_file']['tmp_name'];
    $file_name = $_FILES['csv_file']['name'];
    $file_size = $_FILES['csv_file']['size'];

    if (!preg_match('/\.csv$/i', $file_name)) {
        sendJsonResponse(false, 'File harus berekstensi .csv.');
    }

    if ($file_size > 10485760) {
        sendJsonResponse(false, 'Ukuran file terlalu besar. Maksimal 10MB.');
    }

    // ==================== 5. BUKA CSV ====================
    $handle = fopen($tmp_file, 'r');

    if (!$handle) {
        sendJsonResponse(false, 'Gagal membuka file CSV.');
    }

    // ==================== 6. BACA HEADER ====================
    $headers = fgetcsv($handle, 0, ',', '"', '\\');
    $row_num = 1;

    if (!$headers) {
        fclose($handle);
        sendJsonResponse(false, 'Header CSV kosong atau rusak.');
    }

    $headers = array_map('normalizeHeader', $headers);

    /*
        Header yang diterima:
        inventory_id
        unit (sebelumnya uom)
        default / is_default
        value
    */

    $idx_inventory_id = array_search('inventory_id', $headers);

    // Cari kolom unit (sebelumnya uom)
    $idx_unit = array_search('unit', $headers);
    if ($idx_unit === false) {
        $idx_unit = array_search('uom', $headers); // Backward compatibility
    }

    $idx_default = array_search('default', $headers);
    if ($idx_default === false) {
        $idx_default = array_search('is_default', $headers);
    }

    $idx_value = array_search('value', $headers);

    if ($idx_inventory_id === false || $idx_unit === false || $idx_default === false || $idx_value === false) {
        fclose($handle);

        sendJsonResponse(false, 'Gagal memetakan file! Header CSV wajib memiliki kolom: inventory_id, unit, Default, Value.', [
            'detected_headers' => $headers
        ]);
    }

    // ==================== 7. PREPARE QUERY ====================
    mysqli_begin_transaction($conn);

    $success_count = 0;
    $error_count = 0;
    $errors = [];
    $warnings = [];

    // Supaya delete hanya sekali per inventory_id
    $deleted_inventory_ids = [];

    $stmt_check = $conn->prepare("SELECT inventory_id FROM m_inventory WHERE inventory_id = ? LIMIT 1");
    if (!$stmt_check) {
        throw new Exception('Prepare check inventory gagal: ' . $conn->error);
    }

    $stmt_delete = $conn->prepare("DELETE FROM m_inventory_uom WHERE inventory_id = ?");
    if (!$stmt_delete) {
        throw new Exception('Prepare delete UOM gagal: ' . $conn->error);
    }

    $stmt_insert = $conn->prepare("
        INSERT INTO m_inventory_uom 
            (inventory_id, `unit`, `Default`, `Value`) 
        VALUES 
            (?, ?, ?, ?)
    ");

    if (!$stmt_insert) {
        throw new Exception('Prepare insert UOM gagal: ' . $conn->error);
    }

    // ==================== 8. PROSES ROW ====================
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $row_num++;

        // Skip baris kosong
        if (count($row) === 1 && trim($row[0]) === '') {
            continue;
        }

        $inventory_id = isset($row[$idx_inventory_id]) ? trim($row[$idx_inventory_id]) : '';
        $unit = isset($row[$idx_unit]) ? strtoupper(trim($row[$idx_unit])) : '';
        $is_default = isset($row[$idx_default]) ? cleanDefault($row[$idx_default]) : 0;
        $value = isset($row[$idx_value]) ? cleanDecimal($row[$idx_value]) : 0.0000;

        if ($inventory_id === '') {
            $error_count++;
            $errors[] = "Baris $row_num: inventory_id kosong.";
            continue;
        }

        if ($unit === '') {
            $error_count++;
            $errors[] = "Baris $row_num: unit kosong untuk inventory_id {$inventory_id}.";
            continue;
        }

        // Cek inventory_id ada di master inventory
        $stmt_check->bind_param("s", $inventory_id);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();

        if ($check_result->num_rows === 0) {
            $warnings[] = "Baris $row_num: Inventory ID '{$inventory_id}' belum ada di m_inventory.";
        }

        // Delete UOM lama hanya sekali per inventory_id
        if (!isset($deleted_inventory_ids[$inventory_id])) {
            $stmt_delete->bind_param("s", $inventory_id);

            if (!$stmt_delete->execute()) {
                $error_count++;
                $errors[] = "Baris $row_num: Gagal hapus UOM lama untuk {$inventory_id}: " . $stmt_delete->error;
                continue;
            }

            $deleted_inventory_ids[$inventory_id] = true;
        }

        // Insert UOM baru dengan kolom unit
        $stmt_insert->bind_param("ssid", $inventory_id, $unit, $is_default, $value);

        if ($stmt_insert->execute()) {
            $success_count++;
        } else {
            $error_count++;
            $errors[] = "Baris $row_num: Gagal insert {$inventory_id} - {$unit}: " . $stmt_insert->error;
        }
    }

    fclose($handle);

    $stmt_check->close();
    $stmt_delete->close();
    $stmt_insert->close();

    // ==================== 9. VALIDASI DEFAULT GANDA / KOSONG ====================
    // Jika ada inventory_id yang tidak punya Default=1, otomatis jadikan unit pertama sebagai default
    foreach (array_keys($deleted_inventory_ids) as $inventory_id) {
        $safe_inventory_id = mysqli_real_escape_string($conn, $inventory_id);

        $q_default = mysqli_query($conn, "
            SELECT COUNT(*) AS total_default 
            FROM m_inventory_uom 
            WHERE inventory_id = '$safe_inventory_id' 
              AND `Default` = 1
        ");

        $row_default = mysqli_fetch_assoc($q_default);
        $total_default = (int)($row_default['total_default'] ?? 0);

        if ($total_default === 0) {
            mysqli_query($conn, "
                UPDATE m_inventory_uom 
                SET `Default` = 1 
                WHERE inventory_id = '$safe_inventory_id' 
                ORDER BY id ASC 
                LIMIT 1
            ");

            $warnings[] = "Inventory ID '{$inventory_id}' tidak punya default. Sistem otomatis menjadikan unit pertama sebagai default.";
        } elseif ($total_default > 1) {
            // Kalau default lebih dari 1, sisakan default pertama saja
            $q_ids = mysqli_query($conn, "
                SELECT id 
                FROM m_inventory_uom 
                WHERE inventory_id = '$safe_inventory_id' 
                  AND `Default` = 1
                ORDER BY id ASC
            ");

            $keep_id = null;
            $ids_to_uncheck = [];

            while ($r = mysqli_fetch_assoc($q_ids)) {
                if ($keep_id === null) {
                    $keep_id = (int)$r['id'];
                } else {
                    $ids_to_uncheck[] = (int)$r['id'];
                }
            }

            if (!empty($ids_to_uncheck)) {
                $ids_str = implode(',', $ids_to_uncheck);

                mysqli_query($conn, "
                    UPDATE m_inventory_uom 
                    SET `Default` = 0 
                    WHERE id IN ($ids_str)
                ");

                $warnings[] = "Inventory ID '{$inventory_id}' punya default lebih dari satu. Sistem menyisakan default pertama saja.";
            }
        }

        // Sinkronkan m_inventory.uom_pack dari unit default
        $q_unit_pack = mysqli_query($conn, "
            SELECT `unit` 
            FROM m_inventory_uom 
            WHERE inventory_id = '$safe_inventory_id' 
              AND `Default` = 1 
            LIMIT 1
        ");

        if ($q_unit_pack && mysqli_num_rows($q_unit_pack) > 0) {
            $r_pack = mysqli_fetch_assoc($q_unit_pack);
            $unit_pack = mysqli_real_escape_string($conn, $r_pack['unit']);

            mysqli_query($conn, "
                UPDATE m_inventory 
                SET uom_pack = '$unit_pack'
                WHERE inventory_id = '$safe_inventory_id'
            ");
        }
    }

    mysqli_commit($conn);

    // ==================== 10. RESPONSE ====================
    $message = "Proses import UOM selesai. Berhasil memproses {$success_count} baris.";

    if ($error_count > 0) {
        $message .= " Ada {$error_count} error.";
    }

    if (!empty($warnings)) {
        $message .= " Ada " . count($warnings) . " warning.";
    }

    sendJsonResponse($success_count > 0, $message, [
        'success_count' => $success_count,
        'error_count' => $error_count,
        'warnings' => array_slice($warnings, 0, 30),
        'errors' => array_slice($errors, 0, 30),
        'total_processed' => $row_num - 1
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn) {
        mysqli_rollback($conn);
    }

    sendJsonResponse(false, 'Terjadi kegagalan fatal: ' . $e->getMessage());
}

if (isset($conn) && $conn) {
    $conn->close();
}
?>