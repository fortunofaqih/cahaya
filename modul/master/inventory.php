<?php
// modul/master/inventory.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    // Jika session tidak ada, return JSON error, bukan redirect
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Session expired']);
        exit;
    }
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';;

// ====================================================================
// AJAX HANDLER - Ditempatkan di PALING ATAS sebelum HTML apapun
// ====================================================================
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    // Pastikan response AJAX bersih JSON murni.
    // Penting: AJAX jangan dipanggil lewat index.php setelah header.php ter-render.
    if (ob_get_length()) {
        @ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    
    // Handler untuk get_inventory_uom
    if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_inventory_uom' && isset($_GET['id'])) {
        $id = mysqli_real_escape_string($conn, $_GET['id']);
        
        // Cek koneksi database
        if (!$conn) {
            echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
            exit;
        }
        
        $query = mysqli_query($conn, "SELECT * FROM m_inventory_uom WHERE inventory_id = '$id'");
        
        if (!$query) {
            echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
            exit;
        }
        
        $data = [];
        while ($row = mysqli_fetch_assoc($query)) {
            // Gunakan nama kolom yang sesuai dengan struktur tabel
            $data[] = [
                'Uom' => $row['unit'] ?? $row['Uom'] ?? '',  // Sesuaikan dengan kolom yang ada
                'unit' => $row['unit'] ?? $row['Uom'] ?? '',
                'Default' => (int)($row['Default'] ?? 0),
                'is_default' => ($row['Default'] ?? 0) == 1 ? 'Checked' : 'Unchecked',
                'Value' => (float)($row['Value'] ?? 0),
                'value_roll' => (float)($row['Value'] ?? 0)
            ];
        }
        
        echo json_encode(['status' => 'success', 'data' => $data]);
        exit;
    }
    
    // Handler untuk get_uom_list
    if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_uom_list') {
        $query = mysqli_query($conn, "SELECT unit FROM m_uom WHERE is_active='Checked' ORDER BY unit ASC");
        if (!$query) {
            echo json_encode(['status' => 'error', 'message' => mysqli_error($conn)]);
            exit;
        }
        $list = [];
        while ($row = mysqli_fetch_assoc($query)) {
            $list[] = ['unit' => $row['unit']];
        }
        echo json_encode(['status' => 'success', 'data' => $list]);
        exit;
    }
    
    // Jika tidak ada handler yang cocok
    echo json_encode(['status' => 'error', 'message' => 'Invalid AJAX request']);
    exit;
}

// ====================================================================
// RENDER OPSI UOM UNTUK FORM
// ====================================================================
$uom_options = "";
$query_uom = mysqli_query($conn, "SELECT uom_id FROM m_uom WHERE is_active='Checked' ORDER BY uom_id ASC");
while ($row_uom = mysqli_fetch_assoc($query_uom)) {
    $uom_options .= "<option value='" . htmlspecialchars($row_uom['uom_id']) . "'>" . htmlspecialchars($row_uom['uom_id']) . "</option>";
}

// ====================================================================
// HANDLE DELETE ACTION
// ====================================================================
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $inventory_id = mysqli_real_escape_string($conn, trim($_GET['id']));
    
    if (empty($inventory_id)) {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>ID tidak valid!</div>";
        echo "<script>window.location.href='index.php?page=inventory';</script>";
        exit;
    }
    
    $cek = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id='$inventory_id' LIMIT 1");
    if (mysqli_num_rows($cek) == 0) {
        $_SESSION['alert'] = "<div class='alert alert-warning p-2 small'>Data tidak ditemukan!</div>";
        echo "<script>window.location.href='index.php?page=inventory';</script>";
        exit;
    }
    
    mysqli_begin_transaction($conn);
    
    mysqli_query($conn, "DELETE FROM m_inventory_uom WHERE inventory_id='$inventory_id'");
    $sql_delete = "DELETE FROM m_inventory WHERE inventory_id='$inventory_id'";
    
    if (mysqli_query($conn, $sql_delete)) {
        mysqli_commit($conn);
        $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>✓ Data Berhasil Dihapus!</div>";
    } else {
        mysqli_rollback($conn);
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
    }
    
    echo "<script>window.location.href='index.php?page=inventory';</script>";
    exit;
}

$alert_message = "";
if (isset($_SESSION['alert'])) {
    $alert_message = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

$category_list = mysqli_query($conn, "SELECT categori_id, name FROM m_category ORDER BY name ASC");

// ====================================================================
// HANDLE POST (INSERT/UPDATE)
// ====================================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_form'])) {
    $action_form = $_POST['action_form'];
    $inventory_id = mysqli_real_escape_string($conn, trim($_POST['inventory_id'] ?? ''));
    $inventory_name = mysqli_real_escape_string($conn, trim($_POST['inventory_name'] ?? ''));
    
    if (empty($inventory_name)) {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Inventory Name wajib diisi!</div>";
        echo "<script>window.location.href='index.php?page=inventory';</script>";
        exit;
    }
    
    $type = mysqli_real_escape_string($conn, trim($_POST['type'] ?? ''));
    $category = mysqli_real_escape_string($conn, trim($_POST['category'] ?? ''));
    $remarks = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    
    $cap = mysqli_real_escape_string($conn, trim($_POST['cap'] ?? ''));
    $colour = mysqli_real_escape_string($conn, trim($_POST['colour'] ?? ''));
    $quality = mysqli_real_escape_string($conn, trim($_POST['quality'] ?? ''));
    $volume_default = floatval($_POST['volume_default'] ?? 1.0000);
    $uom_pack = mysqli_real_escape_string($conn, trim($_POST['uom_pack'] ?? ''));
    $tolerance = floatval($_POST['tolerance'] ?? 0);
    $upper_tolerance = floatval($_POST['upper_tolerance'] ?? 0);
    $lower_tolerance = floatval($_POST['lower_tolerance'] ?? 0);
    
    $merk = mysqli_real_escape_string($conn, trim($_POST['merk'] ?? ''));
    $p = floatval($_POST['p'] ?? 0);
    $l = floatval($_POST['l'] ?? 0);
    $t = floatval($_POST['t'] ?? 0);
    $p2 = floatval($_POST['p2'] ?? 0);
    $tebal = floatval($_POST['tebal'] ?? 0);
    $ukuran = mysqli_real_escape_string($conn, trim($_POST['ukuran'] ?? ''));
    $density = floatval($_POST['density'] ?? 0);
    $strength = mysqli_real_escape_string($conn, trim($_POST['strength'] ?? ''));
    
    $ket_las = mysqli_real_escape_string($conn, trim($_POST['ket_las'] ?? ''));
    $re_order_point = floatval($_POST['re_order_point'] ?? 0);
    $dont_show_at_w48 = isset($_POST['dont_show_at_w48']) ? 'Checked' : 'Unchecked';
    $stokan = isset($_POST['stokan']) ? 'Checked' : 'Unchecked';
    
    $internal_name = mysqli_real_escape_string($conn, trim($_POST['internal_name'] ?? ''));
    $catalog = mysqli_real_escape_string($conn, trim($_POST['catalog'] ?? ''));
    $part_no = mysqli_real_escape_string($conn, trim($_POST['part_no'] ?? ''));
    $calculation = mysqli_real_escape_string($conn, trim($_POST['calculation'] ?? ''));
    $printing_type = mysqli_real_escape_string($conn, trim($_POST['printing_type'] ?? ''));
    $status = mysqli_real_escape_string($conn, trim($_POST['status'] ?? 'Active'));
    $origin = mysqli_real_escape_string($conn, trim($_POST['origin'] ?? ''));
    $supp_code = mysqli_real_escape_string($conn, trim($_POST['supp_code'] ?? ''));
    $description = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $nama_customer = mysqli_real_escape_string($conn, trim($_POST['nama_customer'] ?? ''));
    $type_rm = mysqli_real_escape_string($conn, trim($_POST['type_rm'] ?? ''));
    $minimum_stock = floatval($_POST['minimum_stock'] ?? 0);
    $maximum_stock = floatval($_POST['maximum_stock'] ?? 0);
    
    $uom_data = $_POST['uom_data'] ?? '[]';
    $user_now = $_SESSION['username'];
    $datetime_now = date('Y-m-d H:i:s');
    
    if ($action_form == 'insert') {
        if (empty($inventory_id) || $inventory_id == 'Auto') {
            $inventory_id = generateInventoryId($conn, $inventory_name, $type);
        } else {
            if (strpos($inventory_id, 'CP-') !== 0) {
                $inventory_id = 'CP-' . $inventory_id;
            }
        }
        
        mysqli_begin_transaction($conn);
        
        $cek = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id='$inventory_id' FOR UPDATE");
        if (mysqli_num_rows($cek) > 0) {
            mysqli_rollback($conn);
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>ID sudah ada!</div>";
            echo "<script>window.location.href='index.php?page=inventory';</script>";
            exit;
        }
        
        $sql_insert = "INSERT INTO m_inventory (
            inventory_id, inventory_name, type, category, remarks, 
            cap, colour, quality, volume_default, uom_pack,
            tolerance, upper_tolerance, lower_tolerance, 
            merk, p, l, t, p2, tebal, ukuran, 
            density, strength, ket_las, re_order_point, 
            dont_show_at_w48, stokan, 
            internal_name, catalog, part_no, calculation, printing_type, 
            status, origin, supp_code, description, nama_customer, type_rm, 
            minimum_stock, maximum_stock, create_user, date_created, user_modified, date_modified
        ) VALUES (
            '$inventory_id', '$inventory_name', '$type', '$category', '$remarks',
            '$cap', '$colour', '$quality', '$volume_default', '$uom_pack',
            '$tolerance', '$upper_tolerance', '$lower_tolerance', 
            '$merk', '$p', '$l', '$t', '$p2', '$tebal', '$ukuran', 
            '$density', '$strength', '$ket_las', '$re_order_point', 
            '$dont_show_at_w48', '$stokan', 
            '$internal_name', '$catalog', '$part_no', '$calculation', '$printing_type', 
            '$status', '$origin', '$supp_code', '$description', '$nama_customer', '$type_rm', 
            '$minimum_stock', '$maximum_stock', '$user_now', '$datetime_now', '$user_now', '$datetime_now'
        )";
        
        if (mysqli_query($conn, $sql_insert)) {
            $uom_array = json_decode($uom_data, true);
            if (is_array($uom_array)) {
                foreach ($uom_array as $uom) {
                    $unit = mysqli_real_escape_string($conn, $uom['unit'] ?? '');
                    $is_default = (isset($uom['is_default']) && $uom['is_default'] == 'Checked') ? 1 : 0;
                    $value = floatval($uom['value_roll'] ?? 0);
                    
                    if (!empty($unit)) {
                        mysqli_query($conn, "INSERT INTO m_inventory_uom (inventory_id, unit, `Default`, Value) 
                                            VALUES ('$inventory_id', '$unit', '$is_default', '$value')");
                    }
                }
            }
            mysqli_commit($conn);
            $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Inventory Berhasil Disimpan!</div>";
        } else {
            mysqli_rollback($conn);
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
        }
        
        echo "<script>window.location.href='index.php?page=inventory';</script>";
        exit;
        
    } elseif ($action_form == 'update') {
        $sql_update = "UPDATE m_inventory SET 
            inventory_name='$inventory_name', type='$type', category='$category', remarks='$remarks',
            cap='$cap', colour='$colour', quality='$quality', volume_default='$volume_default', uom_pack='$uom_pack',
            tolerance='$tolerance', upper_tolerance='$upper_tolerance', lower_tolerance='$lower_tolerance',
            merk='$merk', p='$p', l='$l', t='$t', p2='$p2', tebal='$tebal', ukuran='$ukuran', 
            density='$density', strength='$strength',
            ket_las='$ket_las', re_order_point='$re_order_point',
            dont_show_at_w48='$dont_show_at_w48', stokan='$stokan',
            internal_name='$internal_name', catalog='$catalog', part_no='$part_no', calculation='$calculation', 
            printing_type='$printing_type', status='$status', origin='$origin', supp_code='$supp_code',
            description='$description', nama_customer='$nama_customer', type_rm='$type_rm',
            minimum_stock='$minimum_stock', maximum_stock='$maximum_stock',
            user_modified='$user_now', date_modified='$datetime_now' 
        WHERE inventory_id='$inventory_id'";
        
        if (mysqli_query($conn, $sql_update)) {
            mysqli_query($conn, "DELETE FROM m_inventory_uom WHERE inventory_id='$inventory_id'");
            
            $uom_array = json_decode($uom_data, true);
            if (is_array($uom_array) && count($uom_array) > 0) {
                foreach ($uom_array as $uom) {
                    $unit = mysqli_real_escape_string($conn, $uom['unit'] ?? '');
                    $is_default = (isset($uom['is_default']) && $uom['is_default'] == 'Checked') ? 1 : 0;
                    $value = floatval($uom['value_roll'] ?? 0);
                    
                    if (!empty($unit)) {
                        mysqli_query($conn, "INSERT INTO m_inventory_uom (inventory_id, unit, `Default`, Value) 
                                            VALUES ('$inventory_id', '$unit', '$is_default', '$value')");
                    }
                }
            }
            $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Perubahan Data Inventory Berhasil Disimpan!</div>";
        } else {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
        }
        
        echo "<script>window.location.href='index.php?page=inventory';</script>";
        exit;
    }
}

// ====================================================================
// FILTER PENCARIAN + FILTER TANGGAL
// Default: tampilkan inventory yang dibuat hari ini agar load awal ringan.
// ====================================================================
$today = date('Y-m-d');
$first_day_of_year = date('Y-01-01');
$search_keyword = trim($_GET['search'] ?? '');

function parseInventoryFilterDate($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    // Format dari datepicker: 26-Jun-2026
    $dt = DateTime::createFromFormat('d-M-Y', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    // Kompatibilitas jika browser/cache lama masih mengirim 2026-06-26
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    // Kompatibilitas alternatif: 26-06-2026
    $dt = DateTime::createFromFormat('d-m-Y', $value);
    if ($dt instanceof DateTime) {
        return $dt->format('Y-m-d');
    }

    return $fallback;
}

$start_date = parseInventoryFilterDate($_GET['start_date'] ?? '', $first_day_of_year);
$end_date = parseInventoryFilterDate($_GET['end_date'] ?? '', $today);

// Nilai yang ditampilkan di input datepicker.
$start_date_display = date('d-M-Y', strtotime($start_date));
$end_date_display = date('d-M-Y', strtotime($end_date));

// Jika user salah input start lebih besar dari end, tukar otomatis.
if (strtotime($start_date) > strtotime($end_date)) {
    $tmp_date = $start_date;
    $start_date = $end_date;
    $end_date = $tmp_date;

    $tmp_display = $start_date_display;
    $start_date_display = $end_date_display;
    $end_date_display = $tmp_display;
}

$filter_conditions = [];
$search_keyword_sql = mysqli_real_escape_string($conn, $search_keyword);
$start_date_sql = mysqli_real_escape_string($conn, $start_date);
$end_date_sql = mysqli_real_escape_string($conn, $end_date);

// Filter tanggal menggunakan DATE(i.date_created), default hari ini.
$filter_conditions[] = "DATE(i.date_created) BETWEEN '$start_date_sql' AND '$end_date_sql'";

if ($search_keyword !== '') {
    $filter_conditions[] = "(
        i.inventory_id LIKE '%$search_keyword_sql%' OR
        i.inventory_name LIKE '%$search_keyword_sql%' OR
        i.category LIKE '%$search_keyword_sql%' OR
        i.nama_customer LIKE '%$search_keyword_sql%'
    )";
}

$where_clause = "WHERE " . implode(" AND ", $filter_conditions);

function generateInventoryId($conn, $inventory_name, $type) {
    $inventory_name = strtoupper($inventory_name);
    $year = date('Y');
    
    if ($type == 'Finish Good (FG)') {
        $rules = [
            'PE POTONG' => 'CP-FG/PE-2-',
            'PE ROLL' => 'CP-FG/PE-1-',
            'HD ROLL' => 'CP-FG/HD-1-',
            'HD POTONG' => 'CP-FG/HD-2-',
            'HD POTONG WARNA' => 'CP-FG/HD-4-',
            'HD ROLL WARNA' => 'CP-FG/HD-5-',
            'PP ROLL' => 'CP-FG/PP-1-',
            'PP POTONG' => 'CP-FG/PP-2-',
        ];
        
        $prefix = 'CP-FG/';
        foreach ($rules as $keyword => $pref) {
            if (strpos($inventory_name, $keyword) !== false) {
                $prefix = $pref;
                break;
            }
        }
        
        if ($prefix == 'CP-FG/') {
            if (strpos($inventory_name, 'ROLL') !== false) {
                $prefix = 'CP-FG/ROLL-';
            } elseif (strpos($inventory_name, 'POTONG') !== false) {
                $prefix = 'CP-FG/POTONG-';
            } else {
                $prefix = 'CP-FG/PCS-';
            }
        }
        
        $query = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id LIKE '{$prefix}%' ORDER BY CAST(SUBSTRING(inventory_id, LENGTH('{$prefix}') + 1) AS UNSIGNED) DESC LIMIT 1");
        $row = mysqli_fetch_assoc($query);
        $next_num = ($row && isset($row['inventory_id'])) ? intval(substr($row['inventory_id'], strlen($prefix))) + 1 : 1;
        
        return $prefix . str_pad($next_num, 6, '0', STR_PAD_LEFT);
    }
    
    $type_mapping = [
        'AKTIVA MESIN (AKT2)' => 'CP-AKT2',
        'AKTIVA INVENTARIS (AKT)' => 'CP-AKT',
        'ALAT PABRIK (ALTP)' => 'CP-ALTP',
        'BIAYA (AC)' => 'CP-AC',
        'Biaya Makloon (BM)' => 'CP-BM',
        'Jasa (JS)' => 'CP-JS',
        'Raw Material (RAW)' => 'CP-RAW',
    ];
    
    $prefix = $type_mapping[$type] ?? 'CP-INV';
    $pattern = $prefix . "/" . $year . "-";
    $query = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id LIKE '{$pattern}%' ORDER BY CAST(SUBSTRING(inventory_id, LENGTH('{$pattern}') + 1) AS UNSIGNED) DESC LIMIT 1");
    $row = mysqli_fetch_assoc($query);
    $next_num = ($row && isset($row['inventory_id'])) ? intval(substr($row['inventory_id'], strlen($pattern))) + 1 : 1;
    
    return $pattern . str_pad($next_num, 6, '0', STR_PAD_LEFT);
}
?>

    <style>
        .loading {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 9999;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 20px 30px;
            border-radius: 10px;
            display: none;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .loading i { font-size: 20px; }
        .spec-tabs {
            display: flex;
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 20px;
            background: white;
            border-radius: 5px 5px 0 0;
            flex-wrap: wrap;
        }
        .spec-tab {
            padding: 10px 24px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-bottom: none;
            margin-right: 5px;
            border-radius: 8px 8px 0 0;
            transition: all 0.2s;
            color: #495057;
        }
        .spec-tab.active {
            background: #0d6efd;
            color: white;
            border-color: #0d6efd;
        }
        .spec-tab:hover:not(.active) {
            background: #e9ecef;
            color: #0d6efd;
        }
        .spec-content {
            display: none;
            padding: 20px;
            border: 1px solid #dee2e6;
            border-top: none;
            border-radius: 0 0 10px 10px;
            background: white;
            margin-bottom: 20px;
        }
        .spec-content.active { display: block; }
        .spec-table {
            width: 100%;
            font-size: 12px;
            border-collapse: collapse;
        }
        .spec-table tr { border-bottom: 1px solid #eef2f7; }
        .spec-table td {
            padding: 10px 12px;
            vertical-align: middle;
        }
        .spec-table td:first-child {
            font-weight: 600;
            width: 180px;
            background: #f8f9fa;
            border-right: 1px solid #eef2f7;
        }
        .spec-table td:last-child { background: white; }
        .spec-table input, .spec-table select, .spec-table textarea {
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 6px 10px;
            font-size: 12px;
            width: 100%;
        }
        .spec-table textarea { resize: vertical; }
        .spec-table input[type="checkbox"] {
            width: auto;
            margin-right: 8px;
            transform: scale(1.1);
        }
        .modal-body { max-height: 70vh; overflow-y: auto; }
        .btn-vs {
            padding: 8px 20px !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            border-radius: 5px !important;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
        }
        .btn-vs i { margin-right: 8px; font-size: 14px; }
        .btn-vs:hover { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        .btn-excel { background: #1d6f42 !important; color: white !important; border: none; }
        .btn-excel:hover { background: #0f5a36 !important; }
        .btn-add { background: #0d6efd !important; color: white !important; border: none; }
        .btn-add:hover { background: #0b5ed7 !important; }
        .btn-info { background-color: #17a2b8; border-color: #17a2b8; color: white; }
        .btn-info:hover { background-color: #138496; border-color: #117a8b; }
        
        .table-inventory {
            font-size: 10px;
            border-collapse: collapse;
            width: 100%;
            white-space: nowrap;
        }
        .table-inventory th {
            background: #e9ecef;
            padding: 6px 4px;
            border: 1px solid #dee2e6;
            text-align: center;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .table-inventory td {
            padding: 4px 6px;
            border: 1px solid #dee2e6;
        }
        .btn-micro {
            padding: 2px 5px;
            font-size: 9px;
        }
        .table-inventory td.sticky-col-aksi,
        .table-inventory td.sticky-col-id,
        .table-inventory td.sticky-col-name,
        .table-inventory th.sticky-col-aksi,
        .table-inventory th.sticky-col-id,
        .table-inventory th.sticky-col-name {
            position: sticky !important;
            background: white;
            z-index: 2;
        }
        .sticky-col-aksi { left: 0; min-width: 55px; max-width: 55px; }
        .sticky-col-id { left: 55px; min-width: 100px; max-width: 100px; }
        .sticky-col-name { left: 155px; min-width: 180px; }
        .table-inventory th.sticky-col-aksi,
        .table-inventory th.sticky-col-id,
        .table-inventory th.sticky-col-name { z-index: 3; background: #e9ecef; }
        .table-inventory tr:hover td.sticky-col-aksi,
        .table-inventory tr:hover td.sticky-col-id,
        .table-inventory tr:hover td.sticky-col-name { background-color: #f1f3f5 !important; }
        
        .uom-table-container {
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 15px;
        }
        .uom-table {
            width: 100%;
            font-size: 11px;
            border-collapse: collapse;
        }
        .uom-table th {
            background: #e9ecef;
            padding: 8px;
            border: 1px solid #dee2e6;
            text-align: center;
            font-weight: bold;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .uom-table td {
            padding: 6px;
            border: 1px solid #dee2e6;
            text-align: center;
        }
        .uom-table input[type="radio"] { margin: 0; }
        .uom-table input[type="number"] {
            width: 70px;
            padding: 3px;
            font-size: 10px;
            text-align: right;
        }
        .btn-add-uom { margin-top: 10px; width: 100%; }
        .modal { z-index: 1050; }
        .modal-backdrop { z-index: 1040; }
        #modalUOMSelection { z-index: 1060; }
        .required { color: red; font-weight: bold; margin-left: 2px; }
    </style>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>
<body>

<div class="d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
        <h5 class="fw-bold text-dark m-0"><i class="fa fa-boxes text-info"></i> Master Data Inventory</h5>
        <div class="d-flex gap-2">
            <button class="btn-vs btn-info" onclick="showModalImportCSV()"><i class="fa fa-upload"></i> Import CSV</button>
            <button class="btn-vs btn-excel" onclick="window.location.href='modul/master/export_inventory.php'"><i class="fa fa-file-excel-o"></i> Export to Excel</button>
            <button class="btn-vs btn-add" onclick="showModalTambah()"><i class="fa fa-plus-circle"></i> Tambah Item</button>
        </div>
    </div>
    <div class="container-fluid">
        <?= $alert_message; ?>
    </div>
</div>

<!-- MODAL UOM SELECTION -->
<div class="modal fade" id="modalUOMSelection" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h5 class="modal-title"><i class="fa fa-list"></i> Daftar UOM</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small">
                    <i class="fa fa-info-circle"></i> Pilih salah satu UOM sebagai default, isi nilai konversi.
                </div>
                <div class="uom-table-container">
                    <table class="uom-table" id="uomSelectionTable">
                        <thead>
                            <tr><th style="width:60px">Default</th><th style="width:120px">Unit</th><th style="width:80px">Value</th><th style="width:50px">Aksi</th></tr>
                        </thead>
                        <tbody id="uomSelectionBody"></tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary btn-add-uom" onclick="addUOMRow()"><i class="fa fa-plus"></i> Tambah UOM</button>
            </div>
            <div class="modal-footer bg-light py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-sm btn-primary" onclick="applyUOMSelection()">Terapkan</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL IMPORT CSV -->
<div class="modal fade" id="modalImportCSV" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fa fa-upload"></i> Import Master Inventory dari CSV</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small">
                    <strong>Informasi:</strong>
                    <ul class="mb-0 mt-1">
                        <li>File harus berformat .CSV (delimiter: koma)</li>
                        <li>Baris pertama adalah header (akan di-skip)</li>
                        <li>Kolom inventory_id dan inventory_name wajib diisi</li>
                    </ul>
                </div>
                <div class="mb-3">
                    <a href="javascript:downloadTemplate()" class="btn btn-sm btn-outline-primary"><i class="fa fa-download"></i> Download Template CSV</a>
                </div>
                <form id="formImportCSV" enctype="multipart/form-data">
                    <div class="form-group">
                        <label class="form-label fw-bold">Pilih File CSV:</label>
                        <input type="file" class="form-control" id="csvFile" name="csv_file" accept=".csv" required>
                    </div>
                </form>
                <div id="progressContainer" style="display:none;" class="mt-3">
                    <div class="progress"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width:100%">Memproses...</div></div>
                </div>
                <div id="resultContainer" style="display:none;" class="mt-3"></div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-info" onclick="submitImportCSV()">Import Sekarang</button>
            </div>
        </div>
    </div>
</div>

<!-- FORM PENCARIAN -->
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="GET" action="index.php" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="inventory">
            <div class="col-md-3">
                <label class="form-label fw-bold small mb-1">Start Date</label>
                <input type="text" name="start_date" id="start_date" class="form-control form-control-sm js-date-picker" value="<?= htmlspecialchars($start_date_display) ?>" placeholder="26-Jun-2026" autocomplete="off">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold small mb-1">End Date</label>
                <input type="text" name="end_date" id="end_date" class="form-control form-control-sm js-date-picker" value="<?= htmlspecialchars($end_date_display) ?>" placeholder="26-Jun-2026" autocomplete="off">
            </div>
            <div class="col-md-4">
                <label class="form-label fw-bold small mb-1">Pencarian</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari ID, Nama Barang, Kategori, Customer..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" name="btn_search" value="1" class="btn btn-sm btn-dark w-50"><i class="fa fa-search"></i> Cari</button>
                <a href="index.php?page=inventory" class="btn btn-sm btn-outline-secondary w-50" title="Reset ke hari ini"><i class="fa fa-sync"></i> Reset</a>
            </div>
            <div class="col-12">
                <small class="text-muted">
                    Default menampilkan inventory yang dibuat hari ini. Ubah tanggal untuk melihat data lama.
                </small>
            </div>
        </form>
    </div>
</div>

<!-- TABEL INVENTORY -->
<div class="table-responsive" style="max-height: 550px; overflow-y: auto; border: 1px solid #dee2e6;">
    <table class="table table-inventory table-hover" style="min-width: 1200px;">
        <thead>
            <tr>
                <th class="sticky-col-aksi">Aksi</th>
                <th class="sticky-col-id">Inventory ID</th>
                <th class="sticky-col-name">Inventory Name</th>
                <th>Type</th><th>Category</th><th>UoM Pack</th><th>Status</th><th>Date Created</th><th>Create User</th>
            </tr>
        </thead>
        <tbody>
        <?php
            $sql = "SELECT i.*, c.name as category_name 
                    FROM m_inventory i 
                    LEFT JOIN m_category c ON i.category = c.categori_id
                    $where_clause
                    ORDER BY i.date_created DESC, i.inventory_id DESC";
            
            $query_list = mysqli_query($conn, $sql);
            $total_data = ($query_list) ? mysqli_num_rows($query_list) : 0;
            
            if (!$query_list) {
                echo "<tr><td colspan='9' class='text-danger'>Error: " . mysqli_error($conn) . "</td></tr>";
            } else if ($total_data == 0) {
                echo "<tr><td colspan='9' class='text-center text-muted py-3'>Tidak ada data inventory pada rentang tanggal " . htmlspecialchars(date('d-m-Y', strtotime($start_date))) . " s/d " . htmlspecialchars(date('d-m-Y', strtotime($end_date))) . ".</td></tr>";
            } else {
                echo "<tr class='d-print-none'><td colspan='9' class='small text-muted bg-light'>Menampilkan <b>" . number_format($total_data, 0, ',', '.') . "</b> data, urut dari inventory terbaru.</td></tr>";
                while ($d = mysqli_fetch_assoc($query_list)) {
        ?>
            <tr>
                <td class="sticky-col-aksi text-center">
                    <?php
                        $jsonData = htmlspecialchars(
                            json_encode($d, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        $deleteConfirm = htmlspecialchars(
                            json_encode('Hapus item ' . ($d['inventory_name'] ?? '') . '?'),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    ?>
                    <button type="button" class="btn btn-micro btn-warning text-dark" onclick="showModalEdit(<?= $jsonData ?>)"><i class="fa fa-edit"></i></button>
                    <a href="index.php?page=inventory&action=delete&id=<?= urlencode($d['inventory_id']) ?>" class="btn btn-micro btn-danger" onclick="return confirm(<?= $deleteConfirm ?>)"><i class="fa fa-trash"></i></a>
                </td>
                <td class="sticky-col-id fw-bold text-secondary"><?= htmlspecialchars($d['inventory_id']) ?></td>
                <td class="sticky-col-name fw-bold text-dark"><?= htmlspecialchars($d['inventory_name']) ?></td>
                <td><?= htmlspecialchars($d['type']) ?></td>
                
				<td><?= htmlspecialchars((string)($d['category_name'] ?? $d['category'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($d['uom_pack']) ?></td>
                <td><span class="badge bg-<?= $d['status']=='Active' ? 'success' : 'danger' ?>"><?= $d['status'] ?></span></td>
                <td class="small"><?= !empty($d['date_created']) ? date('d-m-Y', strtotime($d['date_created'])) : '-' ?></td>
                <td><?= htmlspecialchars($d['create_user']) ?></td>
            </tr>
        <?php } } ?>
        </tbody>
    </table>
</div>

<!-- MODAL FORM INVENTORY -->
<div class="modal fade d-print-none" id="modalInventory" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold" id="modalTitle"><i class="fa fa-boxes"></i> Form Master Inventory</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="formInventory" method="POST" action="index.php?page=inventory">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <input type="hidden" name="uom_data" id="uom_data" value="">
                <div class="modal-body p-4" style="background: #f8fafc;">
                    <!-- DATA UTAMA -->
                    <div class="card shadow-sm mb-4 border-0">
                        <div class="card-header bg-white py-2 px-3" style="border-left:4px solid #0d6efd"><b><i class="fa fa-database me-2"></i>A. DATA UTAMA</b></div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3"><label class="form-label fw-bold small">Inventory ID</label><input type="text" name="inventory_id" id="form_inventory_id" class="form-control form-control-sm" placeholder="Auto"><small class="text-muted">Kosongkan untuk auto generate</small></div>
                                <div class="col-md-5"><label class="form-label fw-bold small">Inventory Name <span class="text-danger">*</span></label><input type="text" name="inventory_name" id="form_inventory_name" class="form-control form-control-sm" required></div>
                                <div class="col-md-2"><label class="form-label fw-bold small">Type<span class="required">*</span></label><select name="type" id="form_type" class="form-select form-select-sm" required><option value="">-- Pilih Type --</option><option value="AKTIVA MESIN (AKT2)">AKTIVA MESIN (AKT2)</option><option value="AKTIVA INVENTARIS (AKT)">AKTIVA INVENTARIS (AKT)</option><option value="ALAT PABRIK (ALTP)">ALAT PABRIK (ALTP)</option><option value="BIAYA (AC)">BIAYA (AC)</option><option value="Biaya Makloon (BM)">Biaya Makloon (BM)</option><option value="Finish Good (FG)">Finish Good (FG)</option><option value="Jasa (JS)">Jasa (JS)</option><option value="Raw Material (RAW)">Raw Material (RAW)</option></select></div>
                                <div class="col-md-2"><label class="form-label fw-bold small">UOM Pack</label><input type="text" name="uom_pack" id="form_uom_pack" class="form-control form-control-sm" style="background:#e9ecef;"></div>
                                <div class="col-md-3"><label class="form-label fw-bold small">Category<span class="required">*</span></label><select name="category" id="form_category" class="form-select form-select-sm" required><option value="">-- Pilih Category --</option><?php $cat_list = mysqli_query($conn, "SELECT categori_id, name FROM m_category ORDER BY name ASC"); while($cat = mysqli_fetch_assoc($cat_list)): ?><option value="<?= $cat['categori_id'] ?>"><?= $cat['name'] ?></option><?php endwhile; ?></select></div>
                                <div class="col-md-4"><label class="form-label fw-bold small">Remarks</label><input type="text" name="remarks" id="form_remarks" class="form-control form-control-sm"></div>
                                <div class="col-md-3"><label class="form-label fw-bold small">Minimum Stock</label><input type="number" step="0.01" name="minimum_stock" id="form_minimum_stock" class="form-control form-control-sm" value="0.00"></div>
                                <div class="col-md-3"><label class="form-label fw-bold small">Maximum Stock</label><input type="number" step="0.01" name="maximum_stock" id="form_maximum_stock" class="form-control form-control-sm" value="0.00"></div>
                                <div class="col-md-3"><label class="form-label fw-bold small">Status</label><select name="status" id="form_status" class="form-select form-select-sm"><option value="Active">Active</option><option value="Inactive">Inactive</option></select></div>
                                <div class="col-md-12"><button type="button" class="btn btn-sm btn-primary" onclick="showUOMSelector()"><i class="fa fa-list"></i> Kelola UOM</button><button type="submit" class="btn btn-sm btn-success px-4 mx-2"><i class="fa fa-save"></i> Simpan</button><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> Batal</button></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- TABS -->
                    <div class="spec-tabs"><div class="spec-tab active" data-tab="specification">Specification</div><div class="spec-tab" data-tab="detail">Detail Specification</div><div class="spec-tab" data-tab="calculation">Calculation</div><div class="spec-tab" data-tab="others">Others</div></div>
                    
                    <div id="specification" class="spec-content active">
                        <table class="spec-table"><tr><td><b>Cap</b></td><td><input type="text" name="cap" id="form_cap"></td></tr>
                        <tr><td><b>Colour</b></td><td><input type="text" name="colour" id="form_colour"></td></tr>
                        <tr><td><b>Quality</b></td><td><select name="quality" id="form_quality" class="form-select">
						<option value="">-- Pilih Quality --</option>
						<option value="BIJI PLASTIK">BIJI PLASTIK</option>
						<option value="HD KRESEK">HD KRESEK</option>
						<option value="HD POTONG">HD POTONG</option>
						<option value="HD ROLL">HD ROLL</option>
						<option value="PE KRESEK">PE KRESEK</option>
						<option value="PE POTONG">PE POTONG</option>
						<option value="PE ROLL">PE ROLL</option>
						<option value="PP ROLL">PP ROLL</option>
						<option value="PP ROLL BOLA">PP ROLL BOLA</option>
						<option value="PP POTONG">PP POTONG</option>
						<option value="GEOMEMBRANE">GEOMEMBRANE</option>
						<option value="BOX">BOX</option>
						<option value="BRONGSONG PISANG">BRONGSONG PISANG</option>
						<option value="BUNGKUS">BUNGKUS</option>
						<option value="GEOMEMBRANE">GEOMEMBRANE</option>
						<option value="KAOS">KAOS</option>
						<option value="KOLAM">KOLAM</option>
						<option value="LEMPER">LEMPER</option>
						<option value="MULSA">MULSA</option>
						<option value="MULSA TAMBAK">MULSA TAMBAK</option>
						<option value="OBAT WARNA">OBAR WARNA</option>
						<option value="PAGER SAWAH">PAGER SAWAH</option>
						<option value="PLASTIK LOUNDRY">PLASTIK LOOUNDRY</option>
						<option value="PLASTIK SAMPAH">PLASTIK SAMPAH</option>
						<option value="PLASTIK SAYUR">PLASTIK SAYUR</option>
						<option value="PLASTIK SEMANGKA">PLASTIK SEMANGKA</option>
						<option value="POLYBAG">POLYBAG</option>
						<option value="PORPORATED">PORPORATED</option>
						<option value="SEDOTAN">SEDOTAN</option>
						<option value="SELANG">SELANG</option>
						<option value="SLONTONG">SLONTONG</option>
						<option value="TERPAL">TERPAL</option>
						<option value="UV1">UV1</option>
						<option value="UV2">UV2</option>
						</select></td></tr>
                        <tr><td><b>Volume Default</b></td><td><input type="number" step="0.0001" name="volume_default" id="form_volume_default" value="1.0000"></td></tr>
                        <tr><td><b>Tolerance (%)</b></td><td><input type="number" step="0.01" name="tolerance" id="form_tolerance" value="0"></td></tr>
                        <tr><td><b>Upper Tolerance</b></td><td><input type="number" step="0.01" name="upper_tolerance" id="form_upper_tolerance" value="0.00"></td></tr>
                        <tr><td><b>Lower Tolerance</b></td><td><input type="number" step="0.01" name="lower_tolerance" id="form_lower_tolerance" value="0.00"></td></tr></table>
                    </div>
                    
                    <div id="detail" class="spec-content">
                        <table class="spec-table"><tr><td><b>Merk</b></td><td><input type="text" name="merk" id="form_merk"></td></tr>
                        <tr><td><b>P (Panjang)</b></td><td><input type="number" step="0.001" name="p" id="form_p" value="0.000"></td></tr>
                        <tr><td><b>L (Lebar)</b></td><td><input type="number" step="0.001" name="l" id="form_l" value="0.000"></td></tr>
                        <tr><td><b>T (Tebal)</b></td><td><input type="number" step="0.001" name="t" id="form_t" value="0.000"></td></tr>
                        <tr><td><b>P2</b></td><td><input type="number" step="0.001" name="p2" id="form_p2" value="0.000"></td></tr>

                        <tr><td><b>Tebal</b></td><td><input type="number" step="0.0001" name="tebal" id="form_tebal" value="0.0000"></td></tr>
                        <tr><td><b>Ukuran</b></td><td><input type="text" name="ukuran" id="form_ukuran"></td></tr>
                        <tr><td><b>Density</b></td><td><input type="number" step="0.0001" name="density" id="form_density" value="0.0000"></td></tr>
                        <tr><td><b>Strength</b></td><td><input type="text" name="strength" id="form_strength"></td></tr></table>
                    </div>
                    
                    <div id="calculation" class="spec-content">
                        <table class="spec-table"><tr><td><b>Ket Las</b></td><td><textarea name="ket_las" id="form_ket_las" rows="2"></textarea></td></tr>
                        <tr><td><b>Re Order Point</b></td><td><input type="number" step="0.01" name="re_order_point" id="form_re_order_point" value="0.00"></td></tr>
                        <tr><td><b>Dont Show W48</b></td><td><input type="checkbox" name="dont_show_at_w48" id="form_dont_show_at_w48" value="Checked"></td></tr>
                        <tr><td><b>Stokan</b></td><td><input type="checkbox" name="stokan" id="form_stokan" value="Checked"></td></tr></table>
                    </div>
                    
                    <div id="others" class="spec-content">
                        <table class="spec-table"><tr><td><b>Internal Name</b></td><td><input type="text" name="internal_name" id="form_internal_name"></td></tr>
                        <tr><td><b>Catalog</b></td><td><input type="text" name="catalog" id="form_catalog"></td></tr>
                        <tr><td><b>Part No</b></td><td><input type="text" name="part_no" id="form_part_no"></td></tr>
                        <tr><td><b>Calculation</b></td><td><input type="text" name="calculation" id="form_calculation"></td></tr>
                        <tr><td><b>Printing Type</b></td><td><input type="text" name="printing_type" id="form_printing_type"></td></tr>
                        <tr><td><b>Origin</b></td><td><select name="origin" id="form_origin" class="form-select">
                            <option value="">-- Pilih Origin --</option>
                            <option value="Gudang Bahan Baku">Gudang Bahan Baku</option>
                            <option value="Gudang Bahan Jadi">Gudang Barang Jadi 1</option>
                            <option value="Gudang Bahan Jadi">Gudang Produksi</option>
                            <option value="Gudang Bahan Jadi">Gudang Barang Jadi 2</option>
                        </select></td></tr>
                        <tr><td><b>Supp Code</b></td><td><input type="text" name="supp_code" id="form_supp_code"></td></tr>
                        <tr><td><b>Description</b></td><td><textarea name="description" id="form_description" rows="2"></textarea></td></tr>
                        <tr><td><b>Nama Customer</b></td><td><input type="text" name="nama_customer" id="form_nama_customer"></td></tr>
                        <tr><td><b>Type RM</b></td><td><input type="text" name="type_rm" id="form_type_rm"></td></tr></table>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="loadingIndicator" class="loading"><i class="fa fa-spinner fa-spin"></i> Memproses...</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
var bootstrapModalInventory = null;
var bootstrapModalUOM = null;
var selectedUOMs = [];
var isModalOpen = false;

var uomList = <?php 
    $uom_array = [];
    // Gunakan 'unit' karena di tabel m_uom kolomnya 'unit'
    $query_uom = mysqli_query($conn, "SELECT unit FROM m_uom WHERE is_active='Checked' ORDER BY unit ASC");
    if ($query_uom) {
        while ($row_uom = mysqli_fetch_assoc($query_uom)) {
            $uom_array[] = $row_uom['unit'];
        }
    }
    echo json_encode($uom_array, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>;

document.addEventListener("DOMContentLoaded", function() {
    try {
        var modalInventory = document.getElementById('modalInventory');
        if (modalInventory) bootstrapModalInventory = new bootstrap.Modal(modalInventory, {backdrop: 'static', keyboard: true});
        var modalUOM = document.getElementById('modalUOMSelection');
        if (modalUOM) bootstrapModalUOM = new bootstrap.Modal(modalUOM, {backdrop: 'static', keyboard: true});
    } catch(e) { console.error("Error initializing modals:", e); }

    if (typeof flatpickr !== 'undefined') {
        flatpickr('.js-date-picker', {
            dateFormat: 'd-M-Y',
            allowInput: true
        });
    }
    
    document.querySelector('.spec-tabs')?.addEventListener('click', function(e) {
        var tab = e.target.closest('.spec-tab');
        if (!tab) return;
        e.preventDefault();
        var tabId = tab.getAttribute('data-tab');
        document.querySelectorAll('.spec-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.spec-content').forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        var targetContent = document.getElementById(tabId);
        if (targetContent) targetContent.classList.add('active');
    });
    
    document.getElementById('formInventory')?.addEventListener('submit', function(e) {
        var inventoryName = document.getElementById('form_inventory_name');
        if (!inventoryName || !inventoryName.value.trim()) {
            e.preventDefault();
            alert('Inventory Name wajib diisi!');
            return false;
        }
        document.getElementById('loadingIndicator').style.display = 'flex';
    });
    
    if (bootstrapModalInventory) {
        document.getElementById('modalInventory').addEventListener('hidden.bs.modal', function() {
            document.getElementById('loadingIndicator').style.display = 'none';
        });
    }
});

function showLoading(show) { var loader = document.getElementById('loadingIndicator'); if(loader) loader.style.display = show ? 'flex' : 'none'; }
function resetFormState() { selectedUOMs = []; var uomData = document.getElementById('uom_data'); if(uomData) uomData.value = '[]'; var uomPack = document.getElementById('form_uom_pack'); if(uomPack) uomPack.value = ''; }

function ensureBootstrapModals() {
    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap JS belum ter-load. Pastikan bootstrap.bundle.min.js sudah dipanggil di index.php/header.');
        return false;
    }

    var modalInventory = document.getElementById('modalInventory');
    var modalUOM = document.getElementById('modalUOMSelection');

    if (!bootstrapModalInventory && modalInventory) {
        bootstrapModalInventory = new bootstrap.Modal(modalInventory, {backdrop: 'static', keyboard: true});
    }

    if (!bootstrapModalUOM && modalUOM) {
        bootstrapModalUOM = new bootstrap.Modal(modalUOM, {backdrop: 'static', keyboard: true});
    }

    return true;
}

function showModalTambah() {
    ensureBootstrapModals();
    if(isModalOpen) return;
    try {
        var form = document.getElementById('formInventory'); if(form) form.reset();
        document.getElementById('action_form').value = 'insert';
        document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Tambah Item Inventory Baru';
        var invId = document.getElementById('form_inventory_id');
        if(invId) { invId.removeAttribute('readonly'); invId.style.background = '#ffffff'; invId.value = ''; }
        document.getElementById('form_status').value = 'Active';
        document.getElementById('form_volume_default').value = '1.0000';
        document.getElementById('form_uom_pack').value = '';
        document.querySelectorAll('#formInventory input[type="checkbox"]').forEach(cb => cb.checked = false);
        selectedUOMs = [];
        document.getElementById('uom_data').value = '[]';
        if(bootstrapModalInventory) { isModalOpen = true; bootstrapModalInventory.show(); setTimeout(() => { isModalOpen = false; }, 500); }
    } catch(e) { console.error(e); alert("Error: " + e.message); isModalOpen = false; }
}

function showModalEdit(data) {
    ensureBootstrapModals();
    if(isModalOpen) return;
    if(!data || !data.inventory_id) { alert("Data tidak valid"); return; }
    try {
        var form = document.getElementById('formInventory'); 
        if(form) form.reset();
        document.getElementById('action_form').value = 'update';
        document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Inventory: ' + (data.inventory_name || '');
        
        // Isi form
        populateForm(data);
        
        // Checkbox
        document.getElementById('form_dont_show_at_w48').checked = data.dont_show_at_w48 === 'Checked';
        document.getElementById('form_stokan').checked = data.stokan === 'Checked';
        
        var invId = document.getElementById('form_inventory_id');
        if(invId) { 
            invId.setAttribute('readonly', 'readonly'); 
            invId.style.background = '#e9ecef'; 
        }
        
        // LOAD UOM DATA
        if(data.inventory_id) {
            showLoading(true);
            var url = 'modul/master/inventory.php?ajax=get_inventory_uom&id=' + encodeURIComponent(data.inventory_id);
             console.log("Fetching URL:", url); // Debug
            fetch(url, {
                method: 'GET', 
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(function(response) { 
                console.log("Response status:", response.status); // Debug
                console.log("Response headers:", response.headers.get('content-type')); // Debug
                return response.text(); // Gunakan text() dulu untuk lihat response mentah
            })
              .then(function(text) {
            console.log("Raw response:", text); // Debug - lihat apa yang dikembalikan
            
            // Coba parse JSON
            try {
                var result = JSON.parse(text);
                console.log("Parsed result:", result);
                return result;
            } catch(e) {
                console.error("JSON Parse error:", e);
                throw new Error("Response bukan JSON: " + text.substring(0, 200));
            }
            })
            .then(function(result) {
                showLoading(false);
                console.log("UOM response:", result); // Debug
                
                if(result.status === 'success' && Array.isArray(result.data)) {
                    // Konversi data dari database ke format yang diinginkan
                    selectedUOMs = result.data.map(function(uom) {
                        return {
                            Uom: uom.Uom,
                            unit: uom.Uom,  // Tambahkan alias unit
                            Default: uom.Default,
                            is_default: uom.Default == 1 ? 'Checked' : 'Unchecked',
                            Value: uom.Value,
                            value_roll: uom.Value
                        };
                    });
                    
                    console.log("Processed UOMs:", selectedUOMs); // Debug
                    
                    var defaultUOM = selectedUOMs.find(function(u) { return u.Default == 1; });
                    if(defaultUOM) {
                        document.getElementById('form_uom_pack').value = defaultUOM.Uom;
                    } else if(selectedUOMs.length > 0) {
                        document.getElementById('form_uom_pack').value = selectedUOMs[0].Uom;
                    }
                    document.getElementById('uom_data').value = JSON.stringify(selectedUOMs);
                } else { 
                    console.warn("No UOM data:", result);
                    resetUOMData(); 
                }
            })
            .catch(function(error) { 
                showLoading(false); 
                resetUOMData(); 
                console.error("Error loading UOM:", error); 
                alert("Gagal load data UOM: " + error.message);
            });
        } else { 
            resetUOMData(); 
        }
        
        if(bootstrapModalInventory) { 
            isModalOpen = true; 
            bootstrapModalInventory.show(); 
            setTimeout(function(){ isModalOpen = false; }, 500); 
        }
    } catch(e) { 
        console.error(e); 
        alert("Error: " + e.message); 
        isModalOpen = false; 
    }
}

function populateForm(data) {
    // Untuk semua input, textarea, select
    for(var key in data) {
        if(data.hasOwnProperty(key)) {
            var el = document.getElementById('form_' + key);
            if(el) {
                console.log("Populating field:", key, "with value:", data[key]);
                
                if(el.tagName === 'SELECT') {
                    // Untuk select, cari option dengan value yang cocok
                    var optionExists = false;
                    for(var i = 0; i < el.options.length; i++) {
                        // Gunakan == (bukan ===) karena bisa beda tipe data (string vs number)
                        if(el.options[i].value == data[key]) {
                            el.selectedIndex = i;
                            optionExists = true;
                            console.log("Option found for", key, ":", el.options[i].value);
                            break;
                        }
                    }
                    if(!optionExists && el.options.length > 0) {
                        console.warn('Value "' + data[key] + '" not found in select ' + key);
                        console.log('Available options:', Array.from(el.options).map(o => o.value));
                        // Optional: Set ke option pertama jika tidak ditemukan
                        // el.selectedIndex = 0;
                    }
                } else if(el.type === 'checkbox') {
                    el.checked = data[key] === 'Checked' || data[key] === true || data[key] === 1;
                } else {
                    el.value = data[key] !== null ? data[key] : '';
                }
            } else {
                console.warn("Element form_" + key + " not found");
            }
        }
    }
}

function resetUOMData() { selectedUOMs = []; document.getElementById('uom_data').value = '[]'; document.getElementById('form_uom_pack').value = ''; }

function showUOMSelector() { 
    ensureBootstrapModals();
    if(bootstrapModalUOM) { 
        renderUOMTable(); 
        bootstrapModalUOM.show(); 
    } else {
        alert("Modal UOM tidak tersedia. Bootstrap JS belum siap.");
    }
}

function renderUOMTable() {
    var tbody = document.getElementById('uomSelectionBody');
    if(!tbody) return;
    tbody.innerHTML = '';
    
    console.log("Selected UOMs:", selectedUOMs); // Debug
    
    if(!selectedUOMs || selectedUOMs.length === 0) { 
        addUOMRow(); 
        return; 
    }
    
    var fragment = document.createDocumentFragment();
    selectedUOMs.forEach(function(uom, idx) { 
        console.log("Processing UOM:", uom); // Debug
        var row = createUOMRow(uom, idx); 
        if(row) fragment.appendChild(row); 
    });
    tbody.appendChild(fragment);
    syncUOMPack();
}

function createUOMRow(existingData, idx) {
    try {
        // PERBAIKAN: Prioritas Uom (dari database) lalu unit
        var unit = existingData ? (existingData.Uom || existingData.unit || '') : '';
        var isDefault = existingData ? (existingData.Default == 1 || existingData.is_default === 'Checked') : false;
        var value = existingData ? (existingData.Value || existingData.value_roll || 0) : 0;
        
        console.log("Creating UOM row:", {unit, isDefault, value}); // Debug
        
        var options = '<option value="">-- Pilih UOM --</option>';
        for(var i = 0; i < uomList.length; i++) {
            var selected = (unit === uomList[i]) ? ' selected' : '';
            options += '<option value="' + uomList[i] + '"' + selected + '>' + uomList[i] + '</option>';
        }
        
        var row = document.createElement('tr');
        row.innerHTML = '<td class="text-center"><input type="radio" name="default_uom" value="' + (idx||Date.now()) + '"' + (isDefault ? ' checked' : '') + '></td>' +
                       '<td><select class="form-select form-select-sm uom-unit" style="width:120px">' + options + '</select></td>' +
                       '<td><input type="number" step="0.01" class="form-control form-control-sm uom-value" value="' + value + '" style="width:70px"></td>' +
                       '<td class="text-center"><button type="button" class="btn btn-sm btn-danger" onclick="removeUOMRow(this)"><i class="fa fa-trash"></i></button></td>';
        
        var radio = row.querySelector('input[type="radio"]');
        var unitSelect = row.querySelector('.uom-unit');
        if(radio) radio.addEventListener('change', function() { syncUOMPack(); });
        if(unitSelect) unitSelect.addEventListener('change', function() { 
            if(document.getElementById('uomSelectionBody').children.length === 1 && radio) radio.checked = true; 
            syncUOMPack(); 
        });
        return row;
    } catch(e) { 
        console.error("Error in createUOMRow:", e); 
        return null; 
    }
}

function addUOMRow() { var tbody = document.getElementById('uomSelectionBody'); if(tbody) { var row = createUOMRow(null); if(row) { tbody.appendChild(row); if(tbody.children.length === 1) { var radio = row.querySelector('input[type="radio"]'); if(radio) radio.checked = true; } syncUOMPack(); } } }

function removeUOMRow(btn) { var row = btn.closest('tr'); if(row) row.remove(); var tbody = document.getElementById('uomSelectionBody'); if(tbody && tbody.children.length === 1) { var radio = tbody.children[0].querySelector('input[type="radio"]'); if(radio) radio.checked = true; } syncUOMPack(); }

function syncUOMPack() { var rows = document.querySelectorAll('#uomSelectionBody tr'); var defaultUnit = ''; for(var i=0;i<rows.length;i++) { var radio = rows[i].querySelector('input[type="radio"]'); var unitSelect = rows[i].querySelector('.uom-unit'); if(radio && radio.checked && unitSelect && unitSelect.value) { defaultUnit = unitSelect.value; break; } } var uomPack = document.getElementById('form_uom_pack'); if(uomPack) uomPack.value = defaultUnit; }

function applyUOMSelection() {
    var rows = document.querySelectorAll('#uomSelectionBody tr');
    var uomData = [];
    for(var i = 0; i < rows.length; i++) {
        var unitSelect = rows[i].querySelector('.uom-unit');
        var unit = unitSelect ? unitSelect.value : '';
        if(unit !== '') {
            var isDefault = rows[i].querySelector('input[type="radio"]')?.checked || false;
            var valueInput = rows[i].querySelector('.uom-value');
            var value = valueInput ? parseFloat(valueInput.value) || 0 : 0;
            
            // Format sesuai database (gunakan Uom, Default, Value)
            uomData.push({
                Uom: unit,           // untuk kolom di database
                unit: unit,          // untuk internal JS
                Default: isDefault ? 1 : 0,
                is_default: isDefault ? 'Checked' : 'Unchecked',
                Value: value,
                value_roll: value
            });
        }
    }
    
    // Set default jika belum ada
    if(uomData.length > 0 && !uomData.some(function(u) { return u.Default === 1; })) { 
        uomData[0].Default = 1; 
        uomData[0].is_default = 'Checked'; 
    }
    
    selectedUOMs = uomData;
    document.getElementById('uom_data').value = JSON.stringify(uomData);
    
    var defaultUOM = uomData.find(function(u) { return u.Default === 1; });
    document.getElementById('form_uom_pack').value = defaultUOM ? defaultUOM.Uom : '';
    
    if(bootstrapModalUOM) bootstrapModalUOM.hide();
}

function showModalImportCSV() { 
    if (typeof bootstrap === 'undefined') {
        alert('Bootstrap JS belum ter-load. Pastikan bootstrap.bundle.min.js sudah dipanggil.');
        return;
    }
    var el = document.getElementById('modalImportCSV');
    if (!el) {
        alert('Modal Import CSV tidak ditemukan.');
        return;
    }
    var modal = new bootstrap.Modal(el); 
    modal.show(); 
}
function downloadTemplate() { var headers = ['inventory_id','inventory_name','type','category','remarks','status']; var csv = headers.join(','); var blob = new Blob([csv], {type:'text/csv'}); var link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = 'template_inventory.csv'; link.click(); URL.revokeObjectURL(link.href); }

function submitImportCSV() {
    var fileInput = document.getElementById('csvFile');
    if(!fileInput || !fileInput.files.length) { alert('Pilih file terlebih dahulu!'); return; }
    var formData = new FormData(); formData.append('csv_file', fileInput.files[0]);
    document.getElementById('progressContainer').style.display = 'block';
    document.getElementById('resultContainer').style.display = 'none';
    fetch('modul/master/import_inventory_csv.php', {method:'POST', body:formData})
    .then(function(response) { return response.json(); })
    .then(function(data) {
        document.getElementById('progressContainer').style.display = 'none';
        var resultDiv = document.getElementById('resultContainer');
        resultDiv.style.display = 'block';
        resultDiv.innerHTML = '<div class="alert alert-' + (data.success ? 'success' : 'danger') + '">' + (data.message || (data.success ? 'Import Berhasil' : 'Import Gagal')) + '</div>';
        if(data.success) setTimeout(function() { location.reload(); }, 2000);
    })
    .catch(function(error) {
        document.getElementById('progressContainer').style.display = 'none';
        document.getElementById('resultContainer').style.display = 'block';
        document.getElementById('resultContainer').innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
    });
}
// ================================================================
// PERBAIKAN PENTING:
// Pastikan semua function yang dipanggil dari onclick="" tersedia
// di global scope window. Ini mencegah error:
// Uncaught ReferenceError: showModalTambah is not defined
// ================================================================
window.showModalTambah = showModalTambah;
window.showModalEdit = showModalEdit;
window.showUOMSelector = showUOMSelector;
window.addUOMRow = addUOMRow;
window.removeUOMRow = removeUOMRow;
window.applyUOMSelection = applyUOMSelection;
window.showModalImportCSV = showModalImportCSV;
window.downloadTemplate = downloadTemplate;
window.submitImportCSV = submitImportCSV;

//khusus import uom
/*function submitImportCSV() {
    var fileInput = document.getElementById('csvFile');

    if (!fileInput || !fileInput.files.length) {
        alert('Pilih file terlebih dahulu!');
        return;
    }

    var formData = new FormData();
    formData.append('csv_file', fileInput.files[0]);

    document.getElementById('progressContainer').style.display = 'block';
    document.getElementById('resultContainer').style.display = 'none';

    fetch('modul/master/import_uom_page.php', {
        method: 'POST',
        body: formData
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        document.getElementById('progressContainer').style.display = 'none';

        var resultDiv = document.getElementById('resultContainer');
        resultDiv.style.display = 'block';

        var detailHtml = '';

        if (data.errors && data.errors.length > 0) {
            detailHtml += '<hr><b>Error:</b><ul>';
            data.errors.forEach(function(err) {
                detailHtml += '<li>' + err + '</li>';
            });
            detailHtml += '</ul>';
        }

        if (data.warnings && data.warnings.length > 0) {
            detailHtml += '<hr><b>Warning:</b><ul>';
            data.warnings.forEach(function(warn) {
                detailHtml += '<li>' + warn + '</li>';
            });
            detailHtml += '</ul>';
        }

        resultDiv.innerHTML =
            '<div class="alert alert-' + (data.success ? 'success' : 'danger') + '">' +
            (data.message || (data.success ? 'Import Berhasil' : 'Import Gagal')) +
            detailHtml +
            '</div>';

        if (data.success) {
            setTimeout(function() {
                location.reload();
            }, 2000);
        }
    })
    .catch(function(error) {
        document.getElementById('progressContainer').style.display = 'none';
        document.getElementById('resultContainer').style.display = 'block';
        document.getElementById('resultContainer').innerHTML =
            '<div class="alert alert-danger">Error: ' + error.message + '</div>';
    });
}*/
</script>
