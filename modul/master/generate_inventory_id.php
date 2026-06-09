<?php
// modul/master/generate_inventory_id.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

include __DIR__ . '/../../koneksi.php';

$inventory_name = $_POST['inventory_name'] ?? '';
$type = $_POST['type'] ?? '';

if (empty($inventory_name) || empty($type)) {
    echo json_encode(['status' => 'error', 'message' => 'Missing data']);
    exit;
}

// Fungsi generate ID (copy dari atas)
function generateInventoryId($conn, $inventory_name, $type) {
    $inventory_name = strtoupper($inventory_name);
    $year = date('Y');
    
    // Jika type adalah FINISH GOOD (FG)
    if ($type == 'Finish Good (FG)') {
        $rules = [
            'PE POTONG'     => 'FG/PE-2-',
            'PE ROLL'       => 'FG/PE-1-',
            'HD ROLL'       => 'FG/HD-1-',
            'HD POTONG'     => 'FG/HD-2-',
            'HD POTONG WARNA' => 'FG/HD-4-',
            'HD ROLL WARNA' => 'FG/HD-5-',
            'PP ROLL'       => 'FG/PP-1-',
            'PP POTONG'     => 'FG/PP-2-',
        ];
        
        $prefix = 'FG/';
        
        foreach ($rules as $keyword => $pref) {
            if (strpos($inventory_name, $keyword) !== false) {
                $prefix = $pref;
                break;
            }
        }
        
        if ($prefix == 'FG/') {
            if (strpos($inventory_name, 'ROLL') !== false) {
                $prefix = 'FG/ROLL-';
            } elseif (strpos($inventory_name, 'POTONG') !== false) {
                $prefix = 'FG/POTONG-';
            } else {
                $prefix = 'FG/PCS-';
            }
        }
        
        $query = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id LIKE '$prefix%' ORDER BY inventory_id DESC LIMIT 1");
        $row = mysqli_fetch_assoc($query);
        
        if ($row) {
            $last_num = intval(str_replace($prefix, '', $row['inventory_id']));
            $next_num = $last_num + 1;
        } else {
            $next_num = 1;
        }
        
        return $prefix . str_pad($next_num, 6, '0', STR_PAD_LEFT);
    }
    
    // Untuk type selain Finish Good
    $type_mapping = [
        'AKTIVA MESIN (AKT2)' => 'AKT2',
        'AKTIVA INVENTARIS (AKT)' => 'AKT',
        'ALAT PABRIK (ALTP)' => 'ALTP',
        'BIAYA (AC)' => 'AC',
        'Biaya Makloon (BM)' => 'BM',
        'Jasa (JS)' => 'JS',
        'Raw Material (RAW)' => 'RAW',
    ];
    
    $prefix = $type_mapping[$type] ?? 'INV';
    
    $query = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id LIKE '$prefix/$year-%' ORDER BY inventory_id DESC LIMIT 1");
    $row = mysqli_fetch_assoc($query);
    
    if ($row) {
        $last_num = intval(explode('-', $row['inventory_id'])[1]);
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    
    return $prefix . "/" . $year . "-" . str_pad($next_num, 6, '0', STR_PAD_LEFT);
}

$inventory_id = generateInventoryId($conn, $inventory_name, $type);

echo json_encode(['status' => 'success', 'inventory_id' => $inventory_id]);
?>