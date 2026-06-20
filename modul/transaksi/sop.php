<?php
// modul/transaksi/sop.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    $_SESSION['username'] = 'Admin ERP'; 
}

// JANGAN GUNAKAN __DIR__ yang mundur terlalu jauh jika di index.php sudah terdefinisi.
// Cukup pastikan koneksi aman (karena index.php sudah meng-include koneksi.php di atas)
if (!isset($conn)) {
    include 'koneksi.php'; 
}
function normalizeMysqlDate($date) {
    if ($date === null || trim($date) === '') return '';

    $date = trim($date);

    // Format dari input SQL flatpickr: 2026-06-18
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    // Format display: 18-Jun-2026
    $months = [
        'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
        'May' => '05', 'Mei' => '05', 'Jun' => '06', 'Jul' => '07',
        'Aug' => '08', 'Agu' => '08', 'Sep' => '09', 'Oct' => '10',
        'Okt' => '10', 'Nov' => '11', 'Dec' => '12', 'Des' => '12'
    ];

    $parts = explode('-', $date);

    if (count($parts) === 3 && isset($months[$parts[1]])) {
        return $parts[2] . '-' . $months[$parts[1]] . '-' . str_pad($parts[0], 2, '0', STR_PAD_LEFT);
    }

    return '';
}

function isRollOrBallUom($uom) {
    $uom = strtoupper(trim((string)$uom));

    return in_array($uom, ['ROLL', 'ROL', 'BALL', 'BAL']);
}

function extractSopBaseId($sop_id) {
    // Contoh:
    // CP-SOP/2026/00001
    // CP-SOP/2026/00001 A
    // CP-SOP/2026/00001 A-R
    // CP-SOP/2026/00001 R
    if (preg_match('/^(CP-SOP\/\d{4}\/\d{5})/', $sop_id, $m)) {
        return $m[1];
    }

    return '';
}

function extractSopBaseNumber($sop_id) {
    if (preg_match('/CP-SOP\/\d{4}\/(\d{5})/', $sop_id, $m)) {
        return (int)$m[1];
    }

    return 0;
}

function getSopLetterFromId($sop_id) {
    // Contoh:
    // CP-SOP/2026/00001 A
    // CP-SOP/2026/00001 A-R
    if (preg_match('/\s([A-Z])(?:-R)?$/', $sop_id, $m)) {
        return $m[1];
    }

    return '';
}

function sopHasR($sop_id) {
    return preg_match('/(?:\sR|\-R)$/', $sop_id) === 1;
}

function makeSopIdWithSuffix($base_id, $letter_suffix, $has_roll_ball) {
    $suffix = '';

    if ($letter_suffix !== '') {
        $suffix = $letter_suffix;
    }

    if ($has_roll_ball) {
        $suffix = $suffix !== '' ? $suffix . '-R' : 'R';
    }

    return $suffix !== '' ? $base_id . ' ' . $suffix : $base_id;
}

function getNextFreshSopBaseId($conn, $tahun) {
    $prefix = "CP-SOP/$tahun/";

    $q = mysqli_query($conn, "
        SELECT sop_id
        FROM head_sop
        WHERE sop_id LIKE '$prefix%'
        ORDER BY sop_id DESC
        FOR UPDATE
    ");

    $max = 0;

    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $num = extractSopBaseNumber($row['sop_id']);

            if ($num > $max) {
                $max = $num;
            }
        }
    }

    return $prefix . str_pad($max + 1, 5, '0', STR_PAD_LEFT);
}

function getExistingBaseIdByOrderNo($conn, $order_no) {
    $order_safe = mysqli_real_escape_string($conn, $order_no);

    $q = mysqli_query($conn, "
        SELECT sop_id
        FROM head_sop
        WHERE order_no = '$order_safe'
        ORDER BY date_created ASC, sop_id ASC
        LIMIT 1
    ");

    if ($q && mysqli_num_rows($q) > 0) {
        $row = mysqli_fetch_assoc($q);
        return extractSopBaseId($row['sop_id']);
    }

    return '';
}

function ensureExistingOrderNoHasLetterA($conn, $order_no) {
    $order_safe = mysqli_real_escape_string($conn, $order_no);

    $q = mysqli_query($conn, "
        SELECT sop_id
        FROM head_sop
        WHERE order_no = '$order_safe'
        ORDER BY date_created ASC, sop_id ASC
    ");

    if (!$q) return;

    while ($row = mysqli_fetch_assoc($q)) {
        $old_sop_id = $row['sop_id'];

        // Kalau sudah punya huruf A/B/C, tidak perlu diubah
        if (getSopLetterFromId($old_sop_id) !== '') {
            continue;
        }

        $base_id = extractSopBaseId($old_sop_id);

        if ($base_id === '') {
            continue;
        }

        $old_has_roll_ball = false;

        // Cek dari SOP ID lama dulu
        if (sopHasR($old_sop_id)) {
            $old_has_roll_ball = true;
        } else {
            // Cek dari detail SOP
            $old_safe = mysqli_real_escape_string($conn, $old_sop_id);

            $detail_q = mysqli_query($conn, "
                SELECT uom_detail
                FROM det_sop
                WHERE sop_id = '$old_safe'
            ");

            if ($detail_q) {
                while ($d = mysqli_fetch_assoc($detail_q)) {
                    if (isRollOrBallUom($d['uom_detail'])) {
                        $old_has_roll_ball = true;
                        break;
                    }
                }
            }
        }

        $new_sop_id = makeSopIdWithSuffix($base_id, 'A', $old_has_roll_ball);

        if ($new_sop_id === $old_sop_id) {
            continue;
        }

        $old_safe = mysqli_real_escape_string($conn, $old_sop_id);
        $new_safe = mysqli_real_escape_string($conn, $new_sop_id);

        $check = mysqli_query($conn, "
            SELECT sop_id 
            FROM head_sop 
            WHERE sop_id = '$new_safe' 
            LIMIT 1
        ");

        if ($check && mysqli_num_rows($check) == 0) {
            mysqli_query($conn, "
                UPDATE head_sop 
                SET sop_id = '$new_safe' 
                WHERE sop_id = '$old_safe'
            ");

            mysqli_query($conn, "
                UPDATE det_sop 
                SET sop_id = '$new_safe' 
                WHERE sop_id = '$old_safe'
            ");
        }
    }
}

function getNextOrderNoLetterSuffix($conn, $order_no) {
    $order_safe = mysqli_real_escape_string($conn, $order_no);

    $q = mysqli_query($conn, "
        SELECT sop_id
        FROM head_sop
        WHERE order_no = '$order_safe'
        ORDER BY date_created ASC, sop_id ASC
    ");

    $count = 0;
    $max_index = -1;

    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $count++;

            $letter = getSopLetterFromId($row['sop_id']);

            if ($letter !== '') {
                $idx = ord($letter) - ord('A');

                if ($idx > $max_index) {
                    $max_index = $idx;
                }
            }
        }
    }

    // Belum pernah dipakai: tidak pakai huruf
    if ($count == 0) {
        return '';
    }

    // Sudah pernah dipakai tapi SOP lama belum punya huruf:
    // SOP lama akan dijadikan A, SOP baru jadi B.
    if ($max_index < 0) {
        return 'B';
    }

    return chr(ord('A') + $max_index + 1);
}
// ==========================================
// 1. HANDLER AJAX (HANYA SATU BLOK, ANTI-DUPLIKASI)
// ==========================================
if (isset($_GET['action'])) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');

// ==========================================
// ENDPOINT: AMBIL DATA SOP UNTUK COPY
// ==========================================
if ($_GET['action'] == 'get_sop_for_copy' && isset($_GET['sop_id'])) {
    $sop_id = mysqli_real_escape_string($conn, $_GET['sop_id']);
    
    // Ambil data header SOP
    $head_query = mysqli_query($conn, "SELECT * FROM head_sop WHERE sop_id = '$sop_id' LIMIT 1");
    $head = mysqli_fetch_assoc($head_query);
    
    if (!$head) {
        echo json_encode(['error' => 'SOP not found']);
        exit;
    }
    
    // Ambil data detail items dari det_sop dengan semua kolom spesifikasi
    $items_query = mysqli_query($conn, "SELECT 
        id, inventory_id, inventory_name, qty, uom, qty_pack, uom_detail, price, shipment_due_date, remarks,
        berat_jenis_potong, spec_potong, ukuran_potong, jml_order_potong, isi_pakbal_potong,
        keterangan_potong, no_mesin_potong, nat_warna_potong, 
        berat_rol_warna, code_potong, jarak_seal,
        berat_jenis_rol, ukuran_rol, berat_rol, isi_bal_rol, jml_order_rol, treat_rol,
        nat_warna_rol, bobin_krepyak_rol, kirim_las_rol, standar_cek_rol,
        gramatur_asli_rol, tebal_asli_rol, spec_rol, gramatur_rol, tebal_rol,
        keterangan_rol, gramatur_plus_rol, gramatur_min_rol, tebal_plus_rol, tebal_minus_rol,
        no_mesin_rol, code_rol
        FROM det_sop WHERE sop_id = '$sop_id' ORDER BY id ASC");
    
    $items = [];
    if ($items_query) {
        while($row = mysqli_fetch_assoc($items_query)) {
            $items[] = $row;
        }
    }
    
    // Kirim response lengkap untuk copy
    echo json_encode([
        'status' => 'success',
        'head' => $head,
        'items' => $items
    ]);
    exit;
}
    // ==========================================
// ENDPOINT BARU: AMBIL DATA LENGKAP SOP (termasuk spesifikasi)
// ==========================================
if ($_GET['action'] == 'get_sop_detail_complete' && isset($_GET['sop_id'])) {
    $sop_id = mysqli_real_escape_string($conn, $_GET['sop_id']);
    
    // Ambil data header SOP
    $head_query = mysqli_query($conn, "SELECT * FROM head_sop WHERE sop_id = '$sop_id' LIMIT 1");
    $head = mysqli_fetch_assoc($head_query);
    
    if (!$head) {
        echo json_encode(['error' => 'SOP not found']);
        exit;
    }
    
    // Ambil data detail items dari det_sop dengan semua kolom spesifikasi
    $items_query = mysqli_query($conn, "SELECT 
        id, sop_id, inventory_id, inventory_name, qty, uom, qty_pack, uom_detail, price, density, remarks,
        previous_inventory, previous_inventory_name, component, value, shipment_due_date, remarks_shipment,
        
       
        berat_jenis_potong, spec_potong, ukuran_potong, jml_order_potong, isi_pakbal_potong,
        keterangan_potong, no_mesin_potong, nat_warna_potong, 
        berat_rol_warna, code_potong, jarak_seal,
        
        
        berat_jenis_rol, ukuran_rol, berat_rol, isi_bal_rol, jml_order_rol, treat_rol,
        nat_warna_rol, bobin_krepyak_rol, kirim_las_rol, standar_cek_rol,
        gramatur_asli_rol, tebal_asli_rol, spec_rol, gramatur_rol, tebal_rol,
        keterangan_rol, gramatur_plus_rol, gramatur_min_rol, tebal_plus_rol, tebal_minus_rol,
        no_mesin_rol, code_rol
        
        FROM det_sop WHERE sop_id = '$sop_id' ORDER BY id ASC");
    
    $items = [];
    if ($items_query) {
        while($row = mysqli_fetch_assoc($items_query)) {
            $items[] = $row;
        }
    }
    
    // Kirim response lengkap
    echo json_encode([
        'status' => 'success',
        'head' => $head,
        'items' => $items
    ]);
    exit;
}
    
  // MATCH ACTION: AMBIL DATA LINKAGE CORES
if ($_GET['action'] == 'get_so_list') {
    $so_start_raw = isset($_GET['so_start']) ? trim($_GET['so_start']) : '';
    $so_end_raw   = isset($_GET['so_end']) ? trim($_GET['so_end']) : '';

    $so_start = mysqli_real_escape_string($conn, normalizeMysqlDate($so_start_raw));
    $so_end   = mysqli_real_escape_string($conn, normalizeMysqlDate($so_end_raw));

    $sql = "
        SELECT 
            dso.order_no,
            hso.order_date,
            COALESCE(hso.customer_name, 'No Customer Name') AS customer_name,
            COALESCE(mc.old_code, '-') AS old_code,
            COUNT(dso.id) AS item_count,
            GROUP_CONCAT(DISTINCT hs.sop_id ORDER BY hs.sop_id SEPARATOR ', ') AS existing_sop
        FROM detail_sales_order dso
        LEFT JOIN head_sales_order hso ON dso.order_no = hso.order_no
        LEFT JOIN m_customer mc ON hso.customer_id = mc.customer_id
        LEFT JOIN head_sop hs ON dso.order_no = hs.order_no
        WHERE dso.order_no IS NOT NULL 
          AND dso.order_no != ''
    ";

    if ($so_start !== '' && $so_end !== '') {
        $sql .= " AND DATE(hso.order_date) BETWEEN '$so_start' AND '$so_end'";
    } elseif ($so_start !== '') {
        $sql .= " AND DATE(hso.order_date) >= '$so_start'";
    } elseif ($so_end !== '') {
        $sql .= " AND DATE(hso.order_date) <= '$so_end'";
    }

    $sql .= "
        GROUP BY 
            dso.order_no,
            hso.order_date,
            hso.customer_name,
            mc.old_code
        ORDER BY 
            hso.order_date DESC,
            dso.order_no DESC
    ";

    $query = mysqli_query($conn, $sql);
    $data = [];

    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $data[] = [
                'order_no' => $row['order_no'],
                'order_date' => $row['order_date'] ? date('d/m/Y', strtotime($row['order_date'])) : '-',
                'customer_name' => $row['customer_name'],
                'old_code' => $row['old_code'],
                'sop_id' => $row['existing_sop'] ?: '-',
                'item_count' => (int)$row['item_count']
            ];
        }
    } else {
        echo json_encode(['error' => mysqli_error($conn), 'data' => []]);
        exit;
    }

    echo json_encode(['data' => $data]);
    exit;
}

// MATCH ACTION: DETAIL DATA UNTUK INPUT GRID
if ($_GET['action'] == 'get_so_detail' && isset($_GET['order_no'])) {
    $order_no = mysqli_real_escape_string($conn, $_GET['order_no']);

    $head_query = mysqli_query($conn, "
        SELECT 
            h.order_no,
            h.customer_id,
            h.customer_name,
            h.remarks,
            h.order_date,
            m.old_code
        FROM head_sales_order h
        LEFT JOIN m_customer m ON h.customer_id = m.customer_id
        WHERE h.order_no = '$order_no'
        LIMIT 1
    ");

    if (!$head_query) {
        echo json_encode([
            'success' => false,
            'error' => 'Query error: ' . mysqli_error($conn)
        ]);
        exit;
    }

    $head = mysqli_fetch_assoc($head_query);

    if (!$head) {
        echo json_encode([
            'success' => false,
            'error' => 'Sales Order tidak ditemukan.'
        ]);
        exit;
    }

    $details = [];

    $res_det = mysqli_query($conn, "
        SELECT 
            dso.id AS detail_id,
            dso.inventory_id,
            dso.inventory_name,
            dso.quantity,
            dso.uom,
            dso.quantity_pack,
            dso.uom_pack,
            dso.uom_detail,
            dso.price,
            dso.remarks,
            COALESCE(mi.category, '') AS inventory_category,
            COALESCE(mi.l, 0) AS inventory_l,
            COALESCE(mi.density, 0) AS inventory_density,
            COALESCE(mi.catalog, '') AS inventory_catalog,
            COALESCE(mi.colour, '') AS inventory_colour,
            COALESCE(mi.t, 0) AS inventory_t,
            COALESCE((
                SELECT CONCAT(REPLACE(FORMAT(miu.Value, 2), ',', ''), ' KG/ROL')
                FROM m_inventory_uom miu
                WHERE miu.inventory_id = dso.inventory_id
                  AND UPPER(TRIM(miu.unit)) IN ('ROLL', 'ROL')
                ORDER BY 
                    CASE WHEN UPPER(TRIM(miu.unit)) = 'ROLL' THEN 0 ELSE 1 END,
                    miu.id ASC
                LIMIT 1
            ), '') AS inventory_berat_rol
        FROM detail_sales_order dso
        LEFT JOIN m_inventory mi ON dso.inventory_id = mi.inventory_id
        WHERE dso.order_no = '$order_no'
        ORDER BY dso.id ASC
    ");

    if ($res_det) {
        while ($d = mysqli_fetch_assoc($res_det)) {
            $details[] = $d;
        }
    }

    echo json_encode([
        'success' => true,
        'head' => $head,
        'details' => $details
    ]);
    exit;
}
    // MATCH ACTION: DETAIL UNTUK VIEW MASTER EXPAND ROW
    if ($_GET['action'] == 'get_sop_items' && isset($_GET['sop_id'])) {
        $sop_id = mysqli_real_escape_string($conn, $_GET['sop_id']);
        $query = mysqli_query($conn, "SELECT id, sop_id, inventory_id, inventory_name, qty, uom, qty_pack, uom_detail, price, density, remarks, previous_inventory, previous_inventory_name FROM det_sop WHERE sop_id = '$sop_id'");
        $items = [];
        if ($query) { while($row = mysqli_fetch_assoc($query)) { $items[] = $row; } }
        echo json_encode($items); 
        exit;
    }
    // MATCH ACTION: DELETE DATA SOP (HEADER & DETAIL)
    if ($_GET['action'] == 'delete_sop' && isset($_POST['sop_id'])) {
        $sop_id = mysqli_real_escape_string($conn, $_POST['sop_id']);
        
        // Mulai Database Transaction agar jika salah satu gagal, bisa di-rollback
        mysqli_begin_transaction($conn);

        try {
            // 1. Hapus data detail terlebih dahulu di tabel det_sop
            $delete_detail = mysqli_query($conn, "DELETE FROM det_sop WHERE sop_id = '$sop_id'");
            
            if (!$delete_detail) {
                throw new Exception("Gagal menghapus baris detail material.");
            }

            // 2. Hapus data header di tabel head_sop
            $delete_header = mysqli_query($conn, "DELETE FROM head_sop WHERE sop_id = '$sop_id'");
            
            if (!$delete_header) {
                throw new Exception("Gagal menghapus dokumen header SOP.");
            }

            // Jika semua lolos, komit datanya ke database
            mysqli_commit($conn);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Dokumen SOP ' . $sop_id . ' berhasil dihapus dari sistem.'
            ]);

        } catch (Exception $e) {
            // Jika ada yang gagal, batalkan semua perubahan
            mysqli_rollback($conn);
            
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
        }
        exit;
    }
}

// ==========================================
// 2. HANDLER CRUD (SAVE / UPDATE)
// ==========================================
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_sop'])) {
    $is_edit         = !empty($_POST['is_edit']) ? true : false;
    $sop_id          = mysqli_real_escape_string($conn, $_POST['sop_id']);
    $sop_date        = mysqli_real_escape_string($conn, $_POST['sop_date']);
    $target_date     = mysqli_real_escape_string($conn, $_POST['target_date']);
    $no_urut_roll    = mysqli_real_escape_string($conn, $_POST['no_urut_roll']);
    $no_urut_potong  = mysqli_real_escape_string($conn, $_POST['no_urut_potong']);
    $order_no        = mysqli_real_escape_string($conn, $_POST['order_no']);
    $customer        = mysqli_real_escape_string($conn, $_POST['customer']);
    $old_code        = mysqli_real_escape_string($conn, $_POST['old_code']);
    $remarks_head    = mysqli_real_escape_string($conn, $_POST['remarks_head']);
    $user            = $_SESSION['username'];
    $current_time    = date('Y-m-d H:i:s');

    mysqli_begin_transaction($conn);

    try {
      if (!$is_edit) {
        $sop_date_mysql = normalizeMysqlDate($sop_date);
        $target_date_mysql = normalizeMysqlDate($target_date);

        if ($sop_date_mysql === '') {
            throw new Exception("Format SOP Posting Date tidak valid.");
        }

        if ($target_date_mysql === '') {
            throw new Exception("Format Target Production Finish tidak valid.");
        }

        $sop_date = $sop_date_mysql;
        $target_date = $target_date_mysql;

        $tahun = date('Y', strtotime($sop_date));

        // Cek apakah item yang dipilih mengandung UoM Pack Roll/Bal/Ball
        $has_roll_ball = false;

        if (isset($_POST['items']) && is_array($_POST['items'])) {
            foreach ($_POST['items'] as $item_check) {
                $uom_check = $item_check['uom_det'] ?? '';

                if (isRollOrBallUom($uom_check)) {
                    $has_roll_ball = true;
                    break;
                }
            }
        }

        // Cek apakah order_no ini sudah pernah dibuat SOP
        $existing_base_id = getExistingBaseIdByOrderNo($conn, $order_no);

        if ($existing_base_id !== '') {
            // Order no sudah pernah dipakai.
            // SOP lama yang belum punya huruf diubah jadi A / A-R.
            ensureExistingOrderNoHasLetterA($conn, $order_no);

            // SOP baru pakai base id yang sama, lanjut huruf B/C/D.
            $base_sop_id = $existing_base_id;
            $letter_suffix = getNextOrderNoLetterSuffix($conn, $order_no);
        } else {
            // Order no belum pernah dipakai.
            // Buat nomor SOP baru tanpa huruf.
            $base_sop_id = getNextFreshSopBaseId($conn, $tahun);
            $letter_suffix = '';
        }

        $sop_id = makeSopIdWithSuffix($base_sop_id, $letter_suffix, $has_roll_ball);
        $sop_id_safe = mysqli_real_escape_string($conn, $sop_id);

        $double_check = mysqli_query($conn, "
            SELECT sop_id 
            FROM head_sop 
            WHERE sop_id = '$sop_id_safe'
            LIMIT 1
        ");

        if ($double_check && mysqli_num_rows($double_check) > 0) {
            throw new Exception("Nomor SOP $sop_id sudah terpakai!");
        }

        $sql_head = "INSERT INTO head_sop (
                sop_id, sop_date, target_date, no_urut_roll, no_urut_potong,
                order_no, customer, old_code, remarks, create_user, date_created
            ) VALUES (
                '$sop_id_safe', '$sop_date', '$target_date', '$no_urut_roll', '$no_urut_potong',
                '$order_no', '$customer', '$old_code', '$remarks_head', '$user', '$current_time'
            )";
    
        } else {
            $sop_date_mysql = normalizeMysqlDate($sop_date);
            $target_date_mysql = normalizeMysqlDate($target_date);

            if ($sop_date_mysql !== '') {
                $sop_date = $sop_date_mysql;
            }

            if ($target_date_mysql !== '') {
                $target_date = $target_date_mysql;
            }

            $sql_head = "UPDATE head_sop SET sop_date='$sop_date', target_date='$target_date', no_urut_roll='$no_urut_roll', no_urut_potong='$no_urut_potong', 
                        order_no='$order_no', customer='$customer', old_code='$old_code', remarks='$remarks_head', user_modified='$user', date_modified='$current_time' 
                        WHERE sop_id='$sop_id'";
            mysqli_query($conn, "DELETE FROM det_sop WHERE sop_id = '$sop_id'");
        }

        if (!mysqli_query($conn, $sql_head)) throw new Exception(mysqli_error($conn));

       if (isset($_POST['items']) && is_array($_POST['items'])) {
    foreach ($_POST['items'] as $index => $item) {
        $inv_id    = mysqli_real_escape_string($conn, $item['inv_id']);
        $inv_name  = mysqli_real_escape_string($conn, $item['inv_name']);
        $qty       = (float)$item['qty'];
        $uom       = mysqli_real_escape_string($conn, $item['uom']);
        $qty_pack  = (float)$item['qty_pack'];
        $uom_det   = mysqli_real_escape_string($conn, $item['uom_det']);
        $price     = (float)$item['price'];
        $rem       = mysqli_real_escape_string($conn, $item['remarks']);
        
        // Kolom Spesifikasi Potong
        $berat_jenis_potong   = (float)($item['berat_jenis_potong'] ?? 0);
        $spec_potong          = mysqli_real_escape_string($conn, $item['spec_potong'] ?? '');
        $ukuran_potong        = mysqli_real_escape_string($conn, $item['ukuran_potong'] ?? '');
        $jml_order_potong     = mysqli_real_escape_string($conn, $item['jml_order_potong'] ?? '');
        $isi_pakbal_potong    = mysqli_real_escape_string($conn, $item['isi_pakbal_potong'] ?? '');
        $keterangan_potong    = mysqli_real_escape_string($conn, $item['keterangan_potong'] ?? '');
        $shipment_due_date_raw   = $item['shipment_due_date'] ?? '';
        $shipment_due_date_mysql  = normalizeMysqlDate($shipment_due_date_raw);
        $shipment_due_date        = $shipment_due_date_mysql !== '' ? mysqli_real_escape_string($conn, $shipment_due_date_mysql) : NULL;
        $no_mesin_potong      = mysqli_real_escape_string($conn, $item['no_mesin_potong'] ?? '');
        $nat_warna_potong     = mysqli_real_escape_string($conn, $item['nat_warna_potong'] ?? '');
        $berat_rol_warna      = (float)($item['berat_rol_warna'] ?? 0);
        $code_potong          = mysqli_real_escape_string($conn, $item['code_potong'] ?? '');
        $jarak_seal           = (float)($item['jarak_seal'] ?? 0);
        
        // Kolom Spesifikasi Roll
        $berat_jenis_rol      = mysqli_real_escape_string($conn, $item['berat_jenis_rol'] ?? '');
        $ukuran_rol           = mysqli_real_escape_string($conn, $item['ukuran_rol'] ?? '');
        $berat_rol            = mysqli_real_escape_string($conn, $item['berat_rol'] ?? '');
        $isi_bal_rol          = mysqli_real_escape_string($conn, $item['isi_bal_rol'] ?? '');
        $jml_order_rol        = mysqli_real_escape_string($conn, $item['jml_order_rol'] ?? '');
        $treat_rol            = mysqli_real_escape_string($conn, $item['treat_rol'] ?? '');
        $nat_warna_rol        = mysqli_real_escape_string($conn, $item['nat_warna_rol'] ?? '');
        $bobin_krepyak_rol    = mysqli_real_escape_string($conn, $item['bobin_krepyak_rol'] ?? '');
        $kirim_las_rol        = mysqli_real_escape_string($conn, $item['kirim_las_rol'] ?? '');
        $standar_cek_rol      = mysqli_real_escape_string($conn, $item['standar_cek_rol'] ?? '');
        $gramatur_asli_rol    = (float)($item['gramatur_asli_rol'] ?? 0);
        $tebal_asli_rol       = (float)($item['tebal_asli_rol'] ?? 0);
        $spec_rol             = mysqli_real_escape_string($conn, $item['spec_rol'] ?? '');
        $gramatur_rol         = mysqli_real_escape_string($conn, $item['gramatur_rol'] ?? '');
        $tebal_rol            = mysqli_real_escape_string($conn, $item['tebal_rol'] ?? '');
        $keterangan_rol       = mysqli_real_escape_string($conn, $item['keterangan_rol'] ?? '');
        $gramatur_plus_rol    = (float)($item['gramatur_plus_rol'] ?? 0);
        $gramatur_min_rol     = (float)($item['gramatur_min_rol'] ?? 0);
        $tebal_plus_rol       = (float)($item['tebal_plus_rol'] ?? 0);
        $tebal_minus_rol      = (float)($item['tebal_minus_rol'] ?? 0);
        $no_mesin_rol         = mysqli_real_escape_string($conn, $item['no_mesin_rol'] ?? '');
        $code_rol             = mysqli_real_escape_string($conn, $item['code_rol'] ?? '');

        $sql_det = "INSERT INTO det_sop (
            sop_id, inventory_id, inventory_name, qty, uom, qty_pack, uom_detail, price, shipment_due_date, remarks,
            berat_jenis_potong, spec_potong, ukuran_potong, jml_order_potong, isi_pakbal_potong,
            keterangan_potong, no_mesin_potong, nat_warna_potong, 
            berat_rol_warna, code_potong, jarak_seal,
            berat_jenis_rol, ukuran_rol, berat_rol, isi_bal_rol, jml_order_rol, treat_rol,
            nat_warna_rol, bobin_krepyak_rol, kirim_las_rol, standar_cek_rol,
            gramatur_asli_rol, tebal_asli_rol, spec_rol, gramatur_rol, tebal_rol,
            keterangan_rol, gramatur_plus_rol, gramatur_min_rol, tebal_plus_rol, tebal_minus_rol,
            no_mesin_rol, code_rol
        ) VALUES (
            '$sop_id', '$inv_id', '$inv_name', $qty, '$uom', $qty_pack, '$uom_det', $price, " . ($shipment_due_date ? "'$shipment_due_date'" : "NULL") . ", '$rem',
            $berat_jenis_potong, '$spec_potong', '$ukuran_potong', '$jml_order_potong', '$isi_pakbal_potong',
            '$keterangan_potong', '$no_mesin_potong', '$nat_warna_potong',
            $berat_rol_warna, '$code_potong', $jarak_seal,
            '$berat_jenis_rol', '$ukuran_rol', '$berat_rol', '$isi_bal_rol', '$jml_order_rol', '$treat_rol',
            '$nat_warna_rol', '$bobin_krepyak_rol', '$kirim_las_rol', '$standar_cek_rol',
            $gramatur_asli_rol, $tebal_asli_rol, '$spec_rol', '$gramatur_rol', '$tebal_rol',
            '$keterangan_rol', $gramatur_plus_rol, $gramatur_min_rol, $tebal_plus_rol, $tebal_minus_rol,
            '$no_mesin_rol', '$code_rol'
        )";
        
        if (!mysqli_query($conn, $sql_det)) throw new Exception(mysqli_error($conn));
    }
}

        mysqli_commit($conn);
        $message = "<div class='alert alert-success py-2 px-3 mb-2 rounded-0 fs-7'><i class='bi bi-check-circle-fill'></i> SOP $sop_id berhasil diproses.</div>";
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $message = "<div class='alert alert-danger py-2 px-3 mb-2 rounded-0 fs-7'><i class='bi bi-exclamation-triangle-fill'></i> Error: " . $e->getMessage() . "</div>";
    }
}

// Global Filter Master List SOP (Jika Diperlukan untuk Grid Utama di Luar Modal)
// ==========================================
// GLOBAL FILTER MASTER LIST SOP
// Default hari ini + format datepicker d-M-Y
// ==========================================
function formatDateIndonesian($date) {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }

    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return '';
    }

    $tanggal = date('d', $timestamp);
    $bulan_num = (int)date('m', $timestamp);
    $tahun = date('Y', $timestamp);

    return $tanggal . '-' . $bulan[$bulan_num] . '-' . $tahun;
}

function convertFilterDateToMysql($date) {
    if ($date === null || trim($date) === '') {
        return '';
    }

    $date = trim($date);

    // Kalau sudah format database: 2026-06-17
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    $months = [
        'Jan' => '01',
        'Feb' => '02',
        'Mar' => '03',
        'Apr' => '04',
        'May' => '05',
        'Mei' => '05',
        'Jun' => '06',
        'Jul' => '07',
        'Aug' => '08',
        'Agu' => '08',
        'Sep' => '09',
        'Oct' => '10',
        'Okt' => '10',
        'Nov' => '11',
        'Dec' => '12',
        'Des' => '12'
    ];

    // Format dari datepicker: 17-Jun-2026
    $parts = explode('-', $date);

    if (count($parts) === 3) {
        $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $monthText = $parts[1];
        $year = $parts[2];

        if (isset($months[$monthText])) {
            return $year . '-' . $months[$monthText] . '-' . $day;
        }
    }

    return '';
}

// Raw value untuk tampilan form
$f_start_raw = isset($_GET['f_start']) && trim($_GET['f_start']) !== ''
    ? trim($_GET['f_start'])
    : formatDateIndonesian(date('Y-m-d'));

$f_end_raw = isset($_GET['f_end']) && trim($_GET['f_end']) !== ''
    ? trim($_GET['f_end'])
    : formatDateIndonesian(date('Y-m-d'));

// Value SQL untuk query
$f_start_sql = convertFilterDateToMysql($f_start_raw);
$f_end_sql = convertFilterDateToMysql($f_end_raw);

// Fallback kalau tanggal gagal convert
if ($f_start_sql === '') {
    $f_start_sql = date('Y-m-d');
    $f_start_raw = formatDateIndonesian($f_start_sql);
}

if ($f_end_sql === '') {
    $f_end_sql = date('Y-m-d');
    $f_end_raw = formatDateIndonesian($f_end_sql);
}

// Kalau start lebih besar dari end, tukar
if ($f_start_sql > $f_end_sql) {
    $tmp_sql = $f_start_sql;
    $f_start_sql = $f_end_sql;
    $f_end_sql = $tmp_sql;

    $tmp_raw = $f_start_raw;
    $f_start_raw = $f_end_raw;
    $f_end_raw = $tmp_raw;
}

$f_so = isset($_GET['f_so']) ? trim($_GET['f_so']) : '';

$f_start_safe = mysqli_real_escape_string($conn, $f_start_sql);
$f_end_safe = mysqli_real_escape_string($conn, $f_end_sql);
$f_so_safe = mysqli_real_escape_string($conn, $f_so);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Surat Order Produksi - Crystal Report Desktop Mode</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        /* VB Desktop & Crystal Report Theme Rules */
        body { font-family: "Segoe UI", "-apple-system", Arial, sans-serif; font-size: 11.5px; background-color: #f0f0f4; color: #222; }
        .fs-7 { font-size: 11px !important; }
        .form-control-sm, .btn-sm, .form-select-sm { font-size: 11.5px !important; border-radius: 2px !important; }
        .form-control-sm:focus, .form-select-sm:focus { border-color: #7da2ce; box-shadow: 0 0 0 2px rgba(125,162,206,0.3); }
        
        /* Container Form/Tabel bergaya Window Box */
        .vb-window { border: 1px solid #9499a2; background-color: #ffffff; border-radius: 3px; box-shadow: inset 0 1px 0 #fff, 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 15px; }
        .vb-window-header { background: linear-gradient(to bottom, #f6f7fa, #e2e4ea); border-bottom: 1px solid #a6a9b1; padding: 6px 10px; font-weight: 6px; font-weight: bold; color: #333; display: flex; align-items: center; justify-content: space-between; }
        .vb-window-body { padding: 10px; }
        
        /* Grid Table Crystal Report Style */
        .cr-table { width: 100% !important; margin-bottom: 0 !important; }
        .cr-table th { background: linear-gradient(to bottom, #eaecf0, #d5d9e0) !important; color: #111 !important; font-weight: 600; text-transform: uppercase; font-size: 11px; padding: 5px 6px !important; border: 1px solid #b2b6bf !important; text-align: center; vertical-align: middle; }
        .cr-table td { padding: 4px 6px !important; border: 1px solid #dcdfe5 !important; vertical-align: middle; white-space: nowrap; }
        .cr-table tbody tr:nth-of-type(odd) { background-color: #ffffff; }
        .cr-table tbody tr:nth-of-type(even) { background-color: #f7f8fa; }
        .cr-table tbody tr:hover { background-color: #e3ecfa !important; }
        
        /* Indicator Status */
        td.details-control { text-align: center; cursor: pointer; color: #2b579a; font-weight: bold; font-size: 13px; width: 30px; }
        .audit-text { font-size: 10.5px; color: #666; font-style: italic; }
        .badge-status { font-size: 10px; padding: 2px 5px; border-radius: 2px; font-weight: 500; text-transform: uppercase; }
        
        /* Custom Button VB Style */
        .btn-vb { background: linear-gradient(to bottom, #ffffff, #e6e6e6); border: 1px solid #adadad; color: #333; }
        .btn-vb:hover { background: linear-gradient(to bottom, #e6e6e6, #cccccc); border-color: #adadad; color: #000; }
        .btn-vb-primary { background: linear-gradient(to bottom, #2b579a, #1e3d6b); border: 1px solid #183054; color: #fff; }
        .btn-vb-primary:hover { background: linear-gradient(to bottom, #23477d, #142948); border-color: #122542; color: #fff; }
        .btn-vb-success { background: linear-gradient(to bottom, #257b43, #19532d); border: 1px solid #123c20; color: #fff; }
        .btn-vb-success:hover { background: linear-gradient(to bottom, #1d6135, #113a1f); color: #fff; }
    </style>
</head>
<body>

<div class="container-fluid pt-3 px-3">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h5 class="m-0 fw-bold text-dark" style="letter-spacing: -0.5px;"><i class="bi bi-file-earmark-ruled text-secondary"></i> Surat Order Produksi (SOP)</h5>
        <button type="button" class="btn btn-vb-primary btn-sm px-3 fw-bold shadow-sm" onclick="openSOPModal(false)">
            <i class="bi bi-plus-lg"></i> Create New SOP
        </button>
    </div>

    <?= $message ?>

    <div class="vb-window">
        <div class="vb-window-header"><i class="bi bi-sliders"></i> Search Parameters & Filters</div>
        <div class="vb-window-body">
            <form method="GET" action="index.php" class="row g-2">
                <input type="hidden" name="page" value="sop">
                
                <div class="col-md-2">
                    <label class="form-label mb-0 text-muted fw-bold fs-7">SOP Start Date</label>
                    <input 
                        type="text" 
                        name="f_start" 
                        class="form-control form-control-sm datepicker" 
                        value="<?= htmlspecialchars($f_start_raw) ?>"
                        autocomplete="off"
                    >
                </div>

                <div class="col-md-2">
                    <label class="form-label mb-0 text-muted fw-bold fs-7">SOP End Date</label>
                    <input 
                        type="text" 
                        name="f_end" 
                        class="form-control form-control-sm datepicker" 
                        value="<?= htmlspecialchars($f_end_raw) ?>"
                        autocomplete="off"
                    >
                </div>
                <div class="col-md-3">
                    <label class="form-label mb-0 text-muted fw-bold fs-7">Sales Order Reference (Order No)</label>
                    <input 
                        type="text" 
                        name="f_so" 
                        class="form-control form-control-sm" 
                        placeholder="Ketik nomor SO..." 
                        value="<?= htmlspecialchars($f_so) ?>"
                    >
                </div>
                <div class="col-md-4 d-flex align-items-end gap-1">
                    <button type="submit" class="btn btn-vb btn-sm px-3 fw-bold"><i class="bi bi-funnel"></i> Apply Filter</button>
                    <a href="index.php?page=sop" class="btn btn-vb btn-sm px-3">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="vb-window">
        <div class="vb-window-header">
            <span><i class="bi bi-grid-3x3-gap"></i> Document Ledger Registry</span>
            <span class="badge bg-secondary rounded-0 fs-7">A4 Landscape Report Grid</span>
        </div>
        <div class="vb-window-body p-0">
            <div class="table-responsive">
                <table id="sopMasterTable" class="table cr-table table-hover">
                    <thead>
                        <tr>
                            <th></th>
                            <th>SOP ID</th>
                            <th>SOP Date</th>
                            <th>Order No.</th>
                            <th>Customer ID</th>
                            <th>Customer Name</th>
                            <th>Customer Old Code</th>
                            <th>Target Date</th>
                            <th>Remarks</th>
                            <th>Created Date</th>
                            <th>Created By</th>
                            <th>Modified Date</th>
                            <th>Modified By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Query Filter Logic
                            $where_clause = "WHERE 1=1";

                            // Default selalu hari ini, kecuali user ubah filter.
                            // Pakai DATE() supaya aman kalau sop_date bertipe DATETIME.
                            $where_clause .= " AND DATE(h.sop_date) BETWEEN '$f_start_safe' AND '$f_end_safe'";

                            if ($f_so_safe !== '') {
                                $where_clause .= " AND h.order_no LIKE '%$f_so_safe%'";
                            }

                        // Relasi pencarian data customer ID dari head_sales_order
                        $sql_query = "
                            SELECT h.*, s.customer_id as cust_id_ref
                            FROM head_sop h
                            LEFT JOIN head_sales_order s ON h.order_no = s.order_no
                            $where_clause
                            ORDER BY h.sop_date DESC, h.sop_id DESC
                        ";
                        
                         $q_sop = mysqli_query($conn, $sql_query);

                            if (!$q_sop) {
                                die("Query SOP Error: " . mysqli_error($conn));
                            }

                            while ($row = mysqli_fetch_assoc($q_sop)):
                        
                        ?>
                        <tr data-sop-id="<?= htmlspecialchars($row['sop_id']) ?>">
                            <td class="details-control"><i class="bi bi-plus-square"></i></td>
                            <td class="fw-bold text-dark"><?= $row['sop_id'] ?></td>
                            <td class="text-center"><?= date('d/m/Y', strtotime($row['sop_date'])) ?></td>
                            <td><span class="text-primary fw-bold"><?= $row['order_no'] ?></span></td>
                            <td class="text-dark"><?= htmlspecialchars($row['cust_id_ref'] ?: '-') ?></td>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($row['customer']) ?></td>
                            <td class="text-center"><?= htmlspecialchars($row['old_code'] ?: '-') ?></td>
                            <td class="text-center text-danger fw-bold"><?= date('d/m/Y', strtotime($row['target_date'])) ?></td>
                            <td><span class="text-muted text-wrap d-inline-block text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($row['remarks']) ?>"><?= htmlspecialchars($row['remarks']) ?></span></td>
                            <td class="audit-text text-center"><?= $row['date_created'] ? date('d/m/Y H:i', strtotime($row['date_created'])) : '-' ?></td>
                            <td class="audit-text"><?= htmlspecialchars($row['create_user'] ?: '-') ?></td>
                            <td class="audit-text text-center"><?= $row['date_modified'] ? date('d/m/Y H:i', strtotime($row['date_modified'])) : '-' ?></td>
                            <td class="audit-text"><?= htmlspecialchars($row['user_modified'] ?: '-') ?></td>
                            <td class="text-nowrap">
                                <button type="button" class="btn btn-info btn-sm py-0 px-2 fw-bold" 
                                        onclick="window.open('modul/transaksi/print_sop_rol.php?sop_id=<?= urlencode($row['sop_id']) ?>', '_blank', 'width=800,height=600')">
                                    <i class="bi bi-printer"></i> Print Roll
                                </button>
                                <button type="button" class="btn btn-warning btn-sm py-0 px-2 fw-bold" 
                                        onclick="window.open('modul/transaksi/print_sop_potong.php?sop_id=<?= urlencode($row['sop_id']) ?>', '_blank', 'width=800,height=600')">
                                    <i class="bi bi-printer"></i> Print Potong
                                </button>
                                <button type="button" class="btn btn-primary btn-sm py-0 px-2 fw-bold" onclick='editSOP(<?= json_encode($row); ?>)'>
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm py-0 px-2 fw-bold" onclick="copySOP('<?= $row['sop_id']; ?>')">
                                    <i class="bi bi-files"></i> Copy
                                </button>
                                <button type="button" class="btn btn-danger btn-sm py-0 px-2 fw-bold" onclick="deleteSOP('<?= $row['sop_id']; ?>')">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="sopModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form id="sopForm" method="POST" class="modal-content rounded-1 border-dark">
            <input type="hidden" name="save_sop" value="1">
            <input type="hidden" name="is_edit" id="form_is_edit" value="0">
            
            <div class="modal-header p-2" style="background: linear-gradient(to bottom, #507aa6, #385980); color:#fff; border-bottom:1px solid #233953;">
                <h6 class="modal-title fw-bold fs-7" id="modalTitle"><i class="bi bi-window-stack"></i> Document Data Entry [SOP Form]</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="font-size:10px;"></button>
            </div>
            <div class="modal-body bg-light" style="padding: 12px;">
                <div class="row g-2 border p-2 bg-white mb-2 shadow-sm">
                    <div class="col-md-3">
                        <label class="form-label mb-0 fw-bold fs-7">SOP ID (System Generated)</label>
                        <input type="text" id="form_sop_id" name="sop_id" class="form-control form-control-sm bg-light fw-bold text-secondary" readonly placeholder="[Auto]">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-0 fw-bold fs-7">SOP Date</label>
                        <input 
                        type="text" 
                        id="form_sop_date" 
                        name="sop_date" 
                        class="form-control form-control-sm datepicker-sql" 
                        required 
                        value="<?= date('Y-m-d') ?>"
                        autocomplete="off"
                    >
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-0 fw-bold fs-7">Target Date</label>
                        <input 
                            type="text" 
                            id="form_target_date" 
                            name="target_date" 
                            class="form-control form-control-sm datepicker-sql" 
                            required 
                            value="<?= date('Y-m-d') ?>"
                            autocomplete="off"
>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-0 text-primary fw-bold fs-7">Linkage Sales Order No.</label>
                        <div class="input-group input-group-sm">
                            <input type="text" id="form_order_no" name="order_no" class="form-control bg-light fw-bold text-primary" readonly required placeholder="<- Select Reference SO">
                            <button class="btn btn-vb fw-bold text-primary" type="button" id="btnBrowseSO" onclick="browseSO()"><i class="bi bi-search"></i> Select</button>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label mb-0 fw-bold fs-7">Customer ID</label>
                        <input type="text" id="customer_id" class="form-control form-control-sm bg-light text-center" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label mb-0 fw-bold fs-7">Customer Name</label>
                        <input type="text" id="customer_name" name="customer" class="form-control form-control-sm bg-light" readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label mb-0 fw-bold fs-7">Old System Code Reference</label>
                        <input type="text" id="old_code" name="old_code" class="form-control form-control-sm bg-light" readonly>
                    </div>

                    <div class="col-md-3">
                        <label class="form-label mb-0 text-muted fs-7">No Urut Roll</label>
                        <input type="text" id="form_no_urut_roll" name="no_urut_roll" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-0 text-muted fs-7">No Urut Potong</label>
                        <input type="text" id="form_no_urut_potong" name="no_urut_potong" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label mb-0 text-muted fs-7">Production Line Remarks / Memo</label>
                        <input type="text" id="remarks_head" name="remarks_head" class="form-control form-control-sm" placeholder="Catatan tambahan spesifikasi kerja mesin...">
                    </div>
                </div>

                <!-- Tab Navigation untuk Spesifikasi -->
                <ul class="nav nav-tabs mb-2" id="specTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="bom-tab" data-bs-toggle="tab" data-bs-target="#bom" type="button" role="tab">Bill of Materials</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="potong-tab" data-bs-toggle="tab" data-bs-target="#potong" type="button" role="tab">Spesifikasi POTONG</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="roll-tab" data-bs-toggle="tab" data-bs-target="#roll" type="button" role="tab">Spesifikasi ROLL</button>
                    </li>
                </ul>

<div class="tab-content">
    <!-- Tab 1: Bill of Materials -->
    <div class="tab-pane fade show active" id="bom" role="tabpanel">
        <div class="table-responsive bg-white border">
            <table class="table cr-table" id="formItemGrid">
                <thead>
                    <tr>
                        <th width="15%">Inventory ID</th>
                        <th>Inventory Structural Name</th>
                        <th width="12%">Order Qty</th>
                        <th width="8%">UoM</th>
                        <th width="12%">Qty Pack</th>
                        <th width="10%">UoM Pack</th>
                        <th width="20%">Technical Specs Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td colspan="7" class="text-center text-muted py-3 bg-light">No reference document selected. Please pick Sales Order first.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Tab 2: Spesifikasi POTONG -->
    <div class="tab-pane fade" id="potong" role="tabpanel">
        <div class="table-responsive bg-white border">
            <table class="table cr-table">
                <tbody id="potong_tbody">
                    <tr><td width="30%"><b>Berat Jenis Potong</b></td><td><input type="text" id="berat_jenis_potong" class="form-control form-control-sm" placeholder="Berat jenis"></td></tr>
                    <tr><td><b>Spec Potong</b></td><td><input type="text" id="spec_potong" class="form-control form-control-sm" placeholder="Gram: +/-%  Tebal: +/-%"></td></tr>
                    <tr><td><b>Ukuran Potong</b></td><td><input type="text" id="ukuran_potong" class="form-control form-control-sm" placeholder="Ukuran potong"></td></tr>
                    <tr><td><b>Jumlah Order Potong</b></td><td><input type="text" id="jml_order_potong" class="form-control form-control-sm" placeholder="Jumlah order potong"></td></tr>
                    <tr><td><b>Isi Pak/Bal Potong</b></td><td><input type="text" id="isi_pakbal_potong" class="form-control form-control-sm" placeholder="Isi per pak/bal"></td></tr>
                    <tr><td><b>Keterangan Potong</b></td><td><textarea id="keterangan_potong" rows="2" class="form-control form-control-sm" placeholder="Keterangan potong"></textarea></td></tr>
                    <tr><td><b>Tanggal Kirim</b></td><td><input type="date" id="shipment_due_date_potong" class="form-control form-control-sm shipment-due-date-input"></td></tr>
                    <tr><td><b>No. Mesin Potong</b></td><td><input type="text" id="no_mesin_potong" class="form-control form-control-sm" placeholder="Nomor mesin"></td></tr>
                    <tr><td><b>Nat/Warna Potong</b></td><td><input type="text" id="nat_warna_potong" class="form-control form-control-sm" placeholder="Natural/Warna"></td></tr>
                    <tr><td><b>Berat/Rol Potong</b></td><td><input type="number" step="0.0001" id="berat_rol_warna" class="form-control form-control-sm" value="0"></td></tr>
                    <tr><td><b>Code</b></td><td><input type="text" id="code_potong" class="form-control form-control-sm" placeholder="Kode potong"></td></tr>
                    <tr><td><b>Jarak Seal</b></td><td><input type="number" step="0.01" id="jarak_seal" class="form-control form-control-sm" value="0"></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Tab 3: Spesifikasi ROLL -->
    <div class="tab-pane fade" id="roll" role="tabpanel">
        <div class="table-responsive bg-white border" style="max-height: 500px; overflow-y: auto;">
            <table class="table cr-table">
                <tbody id="roll_tbody">
                    <tr><td width="30%"><b>Berat Jenis Roll</b></td><td><input type="text" id="berat_jenis_rol" class="form-control form-control-sm" placeholder="Berat jenis roll"></td></tr>
                    <tr><td><b>Ukuran Roll</b></td><td><input type="text" id="ukuran_rol" class="form-control form-control-sm" placeholder="Ukuran roll"></td></tr>
                    <tr><td><b>Berat/Rol</b></td><td><input type="text" id="berat_rol" class="form-control form-control-sm" placeholder="Berat per roll"></td></tr>
                    <tr><td><b>Isi/Bal</b></td><td><input type="text" id="isi_bal_rol" class="form-control form-control-sm" placeholder="Isi per bal"></td></tr>
                    <tr><td><b>Jumlah Order</b></td><td><input type="text" id="jml_order_rol" class="form-control form-control-sm" value=""></td></tr>
                    <tr><td><b>Treat/Tdk</b></td><td>
                        <select id="treat_rol" class="form-select form-select-sm">
                            <option value="">-- Pilih --</option>
                            <option value="Treat">Treat</option>
                            <option value="Non Treat">Non Treat</option>
                        </select>
                    </td></tr>
                    <tr><td><b>Nat/Warna Roll</b></td><td><input type="text" id="nat_warna_rol" class="form-control form-control-sm" placeholder="Natural/Warna"></td></tr>
                    <tr><td><b>Bobin/Kreprak</b></td><td><input type="text" id="bobin_krepyak_rol" class="form-control form-control-sm" placeholder="Bobin/Kreprak"></td></tr>
                    <tr><td><b>Kirim/Las</b></td><td><input type="text" id="kirim_las_rol" class="form-control form-control-sm" placeholder="Kirim/Las"></td></tr>
                    <tr><td><b>Standar Pengecekan</b></td><td><input type="text" id="standar_cek_rol" class="form-control form-control-sm" placeholder="Standar cek"></td></tr>
                    <tr><td><b>Gramatur Asli</b></td><td><input type="number" step="0.0001" id="gramatur_asli_rol" class="form-control form-control-sm" value="0"></td></tr>
                    <tr><td><b>Tebal Asli</b></td><td><input type="number" step="0.0001" id="tebal_asli_rol" class="form-control form-control-sm" value="0"></td></tr>
                    <tr><td><b>Spesifikasi Roll</b></td><td><input type="text" id="spec_rol" class="form-control form-control-sm" placeholder="Spesifikasi roll"></td></tr>
                    <tr><td><b>Gramatur Roll</b></td><td><input type="text" id="gramatur_rol" class="form-control form-control-sm" value=""></td></tr>
                    <tr><td><b>Tebal Roll</b></td><td><input type="text" id="tebal_rol" class="form-control form-control-sm" value=""></td></tr>
                    <tr><td><b>Keterangan Roll</b></td><td><textarea id="keterangan_rol" rows="2" class="form-control form-control-sm" placeholder="Keterangan roll"></textarea></td></tr>
                    <tr><td><b>Gramatur (+)</b></td><td><input type="number" step="0.0001" id="gramatur_plus_rol" class="form-control form-control-sm" value="0"></td></tr>
                    <tr><td><b>Gramatur (-)</b></td><td><input type="number" step="0.0001" id="gramatur_min_rol" class="form-control form-control-sm" value="0"></td></tr>
                    <tr><td><b>Tebal (+)</b></td><td><input type="number" step="0.0001" id="tebal_plus_rol" class="form-control form-control-sm" value="0"></td></tr>
                    <tr><td><b>Tebal (-)</b></td><td><input type="number" step="0.0001" id="tebal_minus_rol" class="form-control form-control-sm" value="0"></td></tr>
                    <tr><td><b>Tanggal Kirim</b></td><td><input type="date" id="shipment_due_date_rol" class="form-control form-control-sm shipment-due-date-input"></td></tr>
                    <tr><td><b>No. Mesin Roll</b></td><td><input type="text" id="no_mesin_rol" class="form-control form-control-sm" placeholder="Nomor mesin roll"></td></tr>
                    <tr><td><b>Code Roll</b></td><td><input type="text" id="code_rol" class="form-control form-control-sm" placeholder="Kode roll"></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
            </div>
            <div class="modal-footer p-2 bg-light border-top">
                <button type="button" class="btn btn-vb btn-sm px-4 fw-bold" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-vb-success btn-sm px-4 fw-bold"><i class="bi bi-save2"></i> Commit & Save SOP</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="soBrowseModal" tabindex="-1" aria-labelledby="soBrowseModalLabel" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-dark text-white p-3">
                <h5 class="modal-title fs-6" id="soBrowseModalLabel"><i class="bi bi-search"></i> Linkage Engine: Select Sales Order Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-3">
                
                <div class="card bg-light border mb-3">
                    <div class="card-body p-2">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label mb-0 text-muted fw-bold" style="font-size: 11px;">SO Start Date</label>
                                <input 
                                    type="text" 
                                    id="modal_so_start" 
                                    class="form-control form-control-sm datepicker-sql"
                                    autocomplete="off"
                                >
                            </div>
                            <div class="col-md-3">
                                <label class="form-label mb-0 text-muted fw-bold" style="font-size: 11px;">SO End Date</label>
                                <input 
                                    type="text" 
                                    id="modal_so_end" 
                                    class="form-control form-control-sm datepicker-sql"
                                    autocomplete="off"
                                >
                            </div>
                            <div class="col-md-4 d-flex gap-1">
                                <button type="button" id="btnFilterSOModal" class="btn btn-primary btn-sm px-3 fw-bold"><i class="bi bi-funnel"></i> Filter</button>
                                <button type="button" id="btnResetSOModal" class="btn btn-secondary btn-sm px-3"><i class="bi bi-arrow-counterclockwise"></i> Clear</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="soBrowseTable" class="table table-bordered table-hover w-100 table-sm" style="font-size: 12px;">
                        <thead class="table-light">
                           <tr>
                            <th>Order No</th>
                            <th class="text-center">Order Date</th>
                            <th>Customer Name</th>
                            <th class="text-center">Items</th>
                            <th class="text-center">Existing SOP</th>
                            <th class="text-center">Old Code</th>
                            <th class="text-center">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                            </tbody>
                    </table>
                    <div id="soItemSelectorBox" class="mt-3" style="display:none;">
                    <div class="card border-primary">
                        <div class="card-header bg-primary text-white py-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold">
                                <i class="bi bi-list-check"></i> Pilih Item Sales Order untuk SOP
                                <span id="selectedSOInfo" class="ms-2"></span>
                            </span>
                            <button type="button" class="btn btn-light btn-sm py-0 px-2" onclick="closeSOItemSelector()">
                                Tutup
                            </button>
                        </div>

                        <div class="card-body p-2">
                            <div class="mb-2 d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-vb" onclick="checkAllSOItems(true)">
                                    Check All
                                </button>
                                <button type="button" class="btn btn-sm btn-vb" onclick="checkAllSOItems(false)">
                                    Uncheck All
                                </button>
                                <button type="button" class="btn btn-sm btn-success fw-bold" onclick="commitSelectedSOItems()">
                                    <i class="bi bi-check-lg"></i> Masukkan ke Bill of Material
                                </button>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0" style="font-size:11px;">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:40px;" class="text-center">Pilih</th>
                                            <th>Inventory ID</th>
                                            <th>Inventory Name</th>
                                            <th class="text-end">Qty</th>
                                            <th class="text-center">UoM</th>
                                            <th class="text-end">Qty Pack</th>
                                            <th class="text-center">UoM Pack</th>
                                            <th>Remarks</th>
                                        </tr>
                                    </thead>
                                    <tbody id="soItemSelectorBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                </div>

            </div>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// 1. DEKLARASI GLOBAL VARIABLE & INITIALIZATION BOOTSTRAP OBJECTS
let masterTable;
let soTable;
let sopModal, soBrowseModal;
let currentRollAutoItem = null;

$(document).ready(function() {
    flatpickr(".datepicker", {
    dateFormat: "d-M-Y",
    allowInput: true,
    disableMobile: true
    });

    flatpickr(".datepicker-sql", {
        dateFormat: "Y-m-d",
        altInput: true,
        altFormat: "d-M-Y",
        allowInput: true,
        disableMobile: true
    })

    sopModal = new bootstrap.Modal(document.getElementById('sopModal'));
    soBrowseModal = new bootstrap.Modal(document.getElementById('soBrowseModal'));
     sopModal = new bootstrap.Modal(document.getElementById('sopModal'));
    soBrowseModal = new bootstrap.Modal(document.getElementById('soBrowseModal'));

    // PERBAIKAN: Destroy existing DataTable jika ada
    if ($.fn.DataTable.isDataTable('#sopMasterTable')) {
        $('#sopMasterTable').DataTable().destroy();
    }

    // Inisialisasi ulang DataTable
    masterTable = $('#sopMasterTable').DataTable({
        "ordering": false,
        "pageLength": 15,
        "processing": true,
        "dom": '<"d-flex align-items-center justify-content-between p-2 border-bottom bg-light"fl>rt<"d-flex align-items-center justify-content-between p-2 border-top bg-light"ip>',
        "language": {
            "search": "Cari:",
            "lengthMenu": "Tampilkan _MENU_ data",
            "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
            "infoEmpty": "Tidak ada data",
            "zeroRecords": "Data tidak ditemukan"
        }
    });

    // PERBAIKAN: Event handler untuk expand row
    $('#sopMasterTable tbody').off('click').on('click', 'td.details-control', function(e) {
        e.preventDefault();
        let tr = $(this).closest('tr');
        let row = masterTable.row(tr);
        let sopId = tr.data('sop-id');
        
        console.log("Expand clicked for SOP:", sopId); // Debug

        if (row.child.isShown()) {
            row.child.hide();
            tr.find('td.details-control i').removeClass('bi-minus-square').addClass('bi-plus-square');
        } else {
            tr.find('td.details-control i').removeClass('bi-plus-square').addClass('bi-hourglass-split');
            
            $.ajax({
                url: 'index.php?page=sop&action=get_sop_items&sop_id=' + encodeURIComponent(sopId),
                method: 'GET',
                dataType: 'json',
                success: function(items) {
                    console.log("Items received:", items); // Debug
                    row.child(formatExpandRow(items)).show();
                    tr.find('td.details-control i').removeClass('bi-hourglass-split').addClass('bi-minus-square');
                },
                error: function(xhr) {
                    console.error("Error:", xhr.responseText);
                    alert('Gagal memuat data detail SOP.');
                    tr.find('td.details-control i').removeClass('bi-hourglass-split').addClass('bi-plus-square');
                }
            });
        }
    });

    // Auto update kalkulasi roll saat user mengubah standar pengecekan, berat jenis, atau spesifikasi toleransi.
    $(document).on('input change', '#berat_jenis_rol, #standar_cek_rol', function() {
        refreshRollCalculatedFields();
    });

    $(document).on('input change', '#spec_rol, #gramatur_asli_rol, #tebal_asli_rol', function() {
        updateRollSpecDerivedFields();
    });

    // Event listener untuk form submit - update hidden fields sebelum submit
let isSubmitting = false;
$('#sopForm').on('submit', function() {
    if (isSubmitting) {
        return false;
    }

    isSubmitting = true;

    if ($('#formItemGrid tbody input[name^="items["][name$="[inv_id]"]').length === 0) {
        alert('Pilih minimal 1 item Sales Order untuk dibuat SOP.');
        isSubmitting = false;
        return false;
    }

    // Update semua hidden fields dengan nilai dari specification tabs
    $('input[name^="items["]').each(function() {
        let name = $(this).attr('name');
        if (name && name.includes('[inv_id]')) {
            let index = name.match(/\[(\d+)\]/)[1];
            
            // Update potong specifications
            $('input[name="items[' + index + '][berat_jenis_potong]"]').val($('#berat_jenis_potong').val());
            $('input[name="items[' + index + '][spec_potong]"]').val($('#spec_potong').val());
            $('input[name="items[' + index + '][ukuran_potong]"]').val($('#ukuran_potong').val());
            $('input[name="items[' + index + '][jml_order_potong]"]').val($('#jml_order_potong').val());
            $('input[name="items[' + index + '][isi_pakbal_potong]"]').val($('#isi_pakbal_potong').val());
            $('input[name="items[' + index + '][keterangan_potong]"]').val($('#keterangan_potong').val());
            $('input[name="items[' + index + '][shipment_due_date]"]').val(getShipmentDueDateValue());
            $('input[name="items[' + index + '][no_mesin_potong]"]').val($('#no_mesin_potong').val());
            $('input[name="items[' + index + '][nat_warna_potong]"]').val($('#nat_warna_potong').val());
            $('input[name="items[' + index + '][berat_rol_warna]"]').val($('#berat_rol_warna').val());
            $('input[name="items[' + index + '][code_potong]"]').val($('#code_potong').val());
            $('input[name="items[' + index + '][jarak_seal]"]').val($('#jarak_seal').val());
            
            // Update roll specifications
            $('input[name="items[' + index + '][berat_jenis_rol]"]').val($('#berat_jenis_rol').val());
            $('input[name="items[' + index + '][ukuran_rol]"]').val($('#ukuran_rol').val());
            $('input[name="items[' + index + '][berat_rol]"]').val($('#berat_rol').val());
            $('input[name="items[' + index + '][isi_bal_rol]"]').val($('#isi_bal_rol').val());
            $('input[name="items[' + index + '][jml_order_rol]"]').val($('#jml_order_rol').val());
            $('input[name="items[' + index + '][treat_rol]"]').val($('#treat_rol').val());
            $('input[name="items[' + index + '][nat_warna_rol]"]').val($('#nat_warna_rol').val());
            $('input[name="items[' + index + '][bobin_krepyak_rol]"]').val($('#bobin_krepyak_rol').val());
            $('input[name="items[' + index + '][kirim_las_rol]"]').val($('#kirim_las_rol').val());
            $('input[name="items[' + index + '][standar_cek_rol]"]').val($('#standar_cek_rol').val());
            $('input[name="items[' + index + '][gramatur_asli_rol]"]').val($('#gramatur_asli_rol').val());
            $('input[name="items[' + index + '][tebal_asli_rol]"]').val($('#tebal_asli_rol').val());
            $('input[name="items[' + index + '][spec_rol]"]').val($('#spec_rol').val());
            $('input[name="items[' + index + '][gramatur_rol]"]').val($('#gramatur_rol').val());
            $('input[name="items[' + index + '][tebal_rol]"]').val($('#tebal_rol').val());
            $('input[name="items[' + index + '][keterangan_rol]"]').val($('#keterangan_rol').val());
            $('input[name="items[' + index + '][gramatur_plus_rol]"]').val($('#gramatur_plus_rol').val());
            $('input[name="items[' + index + '][gramatur_min_rol]"]').val($('#gramatur_min_rol').val());
            $('input[name="items[' + index + '][tebal_plus_rol]"]').val($('#tebal_plus_rol').val());
            $('input[name="items[' + index + '][tebal_minus_rol]"]').val($('#tebal_minus_rol').val());
            $('input[name="items[' + index + '][no_mesin_rol]"]').val($('#no_mesin_rol').val());
            $('input[name="items[' + index + '][code_rol]"]').val($('#code_rol').val());
        }
    });
    
    return true;
});

});

// FORMAT BARIS EXPAND DENGAN KOLOM DETAIL MEMO PRODUKSI LENGKAP
function formatExpandRow(data) {
    if(data.length === 0) return '<div class="p-2 text-danger text-center bg-white border">No material rows bounded.</div>';
    
    let html = `<div class="p-2 bg-light border-start border-dark border-3 ms-4 my-1">
        <div class="fw-bold text-secondary mb-1" style="font-size:10.5px;"><i class="bi bi-arrow-return-right"></i> Sub-Report View Line Details:</div>
        <table class="table cr-table bg-white shadow-sm">
            <thead>
                <tr>
                    <th>Inventory ID</th>
                    <th>Inventory Name</th>
                    <th>Internal Name</th>
                    <th class="text-end">Qty</th>
                    <th>UoM</th>
                    <th class="text-end">Density</th>
                    <th class="text-end">Qty Pack</th>
                    <th>UoM Pack</th>
                    <th class="text-end">Price</th>
                    <th>Remark</th>
                    <th>Previous Inventory</th>
                    <th>Previous Inv Name</th>
                </tr>
            </thead>
            <tbody>`;
    data.forEach(item => {
        html += `<tr>
            <td class="text-wrap">${item.inventory_id || '-'}</td>
            <td class="text-wrap">${item.inventory_name || '-'}</td>
            <td class="text-wrap">-</td> 
            <td class="text-end fw-bold text-success">${parseFloat(item.qty || 0).toLocaleString('id-ID')}</td>
            <td class="text-center"><span class="badge bg-light text-dark border">${item.uom || '-'}</span></td>
            <td class="text-end text-muted">${parseFloat(item.density || 0).toFixed(4)}</td>
            <td class="text-end">${parseFloat(item.qty_pack || 0).toLocaleString('id-ID')}</td>
            <td class="text-center">${item.uom_detail || '-'}</td>
            <td class="text-end fw-bold">${parseFloat(item.price || 0).toLocaleString('id-ID')}</td>
            <td class="text-wrap text-muted"><small>${item.remarks || '-'}</small></td>
            <td><code>${item.previous_inventory || '-'}</code></td>
            <td class="text-wrap">${item.previous_inventory_name || '-'}</td>
        </tr>`;
    });
    html += `</tbody></table></div>`;
    return html;
}

function openSOPModal(isEdit = false, isCopy = false) {
    $('#sopForm')[0].reset();
    $('#formItemGrid tbody').html('<tr><td colspan="7" class="text-center text-muted py-3 bg-light">No reference document selected. Please pick Sales Order first.</td></tr>');
    
    if(!isEdit) {
        if(isCopy) {
            $('#modalTitle').html('<i class="bi bi-files"></i> Copy SOP [Create New from Existing]');
        } else {
            $('#modalTitle').html('<i class="bi bi-window-stack"></i> Document Data Entry [Create New SOP]');
        }
        $('#form_is_edit').val('0');
        $('#form_sop_id').val('');
        if ($('#form_sop_date')[0]._flatpickr) {
        $('#form_sop_date')[0]._flatpickr.setDate(new Date(), true);
        } else {
            $('#form_sop_date').val(new Date().toISOString().slice(0, 10));
        }

        if ($('#form_target_date')[0]._flatpickr) {
         $('#form_target_date')[0]._flatpickr.setDate(new Date(), true);
        } else {
            $('#form_target_date').val(new Date().toISOString().slice(0, 10));
        }
        $('#btnBrowseSO').show();
        $('#form_sop_date').prop('readonly', false);
        
        // Reset spesifikasi untuk create mode
        resetSpecificationForms();
    }
    sopModal.show();
}

// Pastikan fungsi browseSO dan event filternya diatur seperti ini:
function browseSO() {
    soBrowseModal.show();
    let today = new Date();

        if ($('#modal_so_start')[0]._flatpickr && !$('#modal_so_start').val()) {
            $('#modal_so_start')[0]._flatpickr.setDate(today, true);
        }

        if ($('#modal_so_end')[0]._flatpickr && !$('#modal_so_end').val()) {
            $('#modal_so_end')[0]._flatpickr.setDate(today, true);
        }

        closeSOItemSelector();
    // Destroy existing DataTable jika ada, untuk menghindari konflik
    if ($.fn.DataTable.isDataTable('#soBrowseTable')) {
        $('#soBrowseTable').DataTable().destroy();
        $('#soBrowseTable tbody').empty();
    }
    
    // Inisialisasi DataTable baru
    soTable = $('#soBrowseTable').DataTable({
        "processing": true,
        "serverSide": false, // Client-side processing
        "ajax": {
            "url": "index.php?page=sop&action=get_so_list",
            "type": "GET",
            "data": function (d) {
                // Kirim filter tanggal
                d.so_start = $('#modal_so_start').val();
                d.so_end = $('#modal_so_end').val();
            },
            "dataSrc": function (json) {
                // Debug response
                console.log("Response from server:", json);
                
                if (json.error) {
                    console.error("Server error:", json.error);
                    return [];
                }
                
                // Pastikan format response sesuai (DataTables expects array in 'data' property)
                if (json.data) {
                    return json.data;
                }
                return [];
            },
            "error": function (xhr, status, error) {
                console.error("SO Grid Error Log:");
                console.error("Status:", status);
                console.error("Error:", error);
                console.error("Response:", xhr.responseText);
                alert("Gagal memuat data Sales Order. Silakan refresh halaman.");
            }
        },
        "columns": [
            {
                "data": "order_no",
                "render": function(data) {
                    return data ? `<span class="fw-bold text-primary font-monospace">${data}</span>` : '-';
                }
            },
            {
                "data": "order_date",
                "className": "text-center",
                "render": function(data) {
                    return data || '-';
                }
            },
            {
                "data": "customer_name",
                "render": function(data) {
                    return data || '-';
                }
            },
            {
                "data": "item_count",
                "className": "text-center fw-bold",
                "render": function(data) {
                    return `<span class="badge bg-secondary">${data || 0} item</span>`;
                }
            },
            {
                "data": "sop_id",
                "className": "text-center fw-bold",
                "render": function(data) {
                    if (!data || data === '-') {
                        return `<span class="text-muted">-</span>`;
                    }
                    return `<span class="badge bg-info text-dark font-monospace">${data}</span>`;
                }
            },
            {
                "data": "old_code",
                "defaultContent": "-",
                "className": "text-center font-monospace"
            },
            {
                "data": "order_no",
                "className": "text-center",
                "render": function(data) {
                    return `<button type="button" class="btn btn-success btn-sm px-2 py-0 fw-bold" style="font-size:11px;" onclick="openSOItemSelector('${data}')">
                                <i class="bi bi-check2-square"></i> Pilih Item
                            </button>`;
                }
            }
        ],
        "order": [[1, "desc"], [0, "desc"]],
        "pageLength": 5,
        "lengthMenu": [5, 10, 25, 50],
        "dom": '<"d-flex align-items-center justify-content-between mb-2"lf>rt<"d-flex align-items-center justify-content-between mt-2"ip>',
        "language": {
            "search": "<b>Cari:</b>",
            "searchPlaceholder": "Ketik nomor SO, nama customer...",
            "lengthMenu": "Tampilkan _MENU_ data",
            "info": "Menampilkan _START_ sampai _END_ dari _TOTAL_ data",
            "infoEmpty": "Tidak ada data",
            "zeroRecords": "Data tidak ditemukan"
        }
    });
}
let currentSOHead = null;
let currentSODetails = [];

function openSOItemSelector(orderNo) {
    $('#soItemSelectorBox').show();
    $('#selectedSOInfo').text('Loading ' + orderNo + '...');
    $('#soItemSelectorBody').html(`
        <tr>
            <td colspan="8" class="text-center py-3">
                <i class="bi bi-hourglass-split"></i> Loading item...
            </td>
        </tr>
    `);

    $.get('index.php?page=sop&action=get_so_detail&order_no=' + encodeURIComponent(orderNo))
        .done(function(res) {
            if (!res || !res.success) {
                alert(res.error || 'Gagal memuat detail Sales Order.');
                return;
            }

            currentSOHead = res.head;
            currentSODetails = res.details || [];

            $('#selectedSOInfo').text('[' + orderNo + '] - ' + (currentSOHead.customer_name || ''));

            if (!currentSODetails.length) {
                $('#soItemSelectorBody').html(`
                    <tr>
                        <td colspan="8" class="text-center text-danger py-3">
                            Sales Order ini tidak memiliki detail item.
                        </td>
                    </tr>
                `);
                return;
            }

            let html = '';

            currentSODetails.forEach(function(item, index) {
                html += `
                    <tr>
                        <td class="text-center">
                            <input type="checkbox" class="so-item-check" value="${index}" checked>
                        </td>
                        <td><code>${escapeHtmlJS(item.inventory_id || '-')}</code></td>
                        <td class="text-wrap">${escapeHtmlJS(item.inventory_name || '-')}</td>
                        <td class="text-end">${parseFloat(item.quantity || 0).toLocaleString('id-ID')}</td>
                        <td class="text-center">${escapeHtmlJS(item.uom || '-')}</td>
                        <td class="text-end">${parseFloat(item.quantity_pack || 0).toLocaleString('id-ID')}</td>
                        <td class="text-center fw-bold">${escapeHtmlJS(item.uom_pack || item.uom_detail || '-')}</td>
                        <td class="text-wrap">${escapeHtmlJS(item.remarks || '-')}</td>
                    </tr>
                `;
            });

            $('#soItemSelectorBody').html(html);
        })
        .fail(function(xhr) {
            console.error(xhr.responseText);
            alert('Gagal memuat detail Sales Order.');
        });
}

function closeSOItemSelector() {
    $('#soItemSelectorBox').hide();
    $('#selectedSOInfo').text('');
    $('#soItemSelectorBody').html('');
    currentSOHead = null;
    currentSODetails = [];
}

function checkAllSOItems(checked) {
    $('.so-item-check').prop('checked', checked);
}

function commitSelectedSOItems() {
    if (!currentSOHead) {
        alert('Data Sales Order belum dipilih.');
        return;
    }

    let selectedItems = [];

    $('.so-item-check:checked').each(function() {
        let index = parseInt($(this).val());

        if (currentSODetails[index]) {
            selectedItems.push(currentSODetails[index]);
        }
    });

    if (!selectedItems.length) {
        alert('Pilih minimal 1 item untuk dibuat SOP.');
        return;
    }

    // Isi header SOP
    $('#customer_id').val(currentSOHead.customer_id || '');
    $('#customer_name').val(currentSOHead.customer_name || '');
    $('#old_code').val(currentSOHead.old_code || '');
    $('#remarks_head').val(currentSOHead.remarks || '');
    $('#form_order_no').val(currentSOHead.order_no || '');

    // Render hanya item yang dicentang
    let html = '';

    selectedItems.forEach(function(item, index) {
        html += renderDetailRowForCreate(item, index);
    });

    $('#formItemGrid tbody').html(html);

    resetSpecificationForms();

    // Create New SOP: isi otomatis tab Spesifikasi Roll dari item pertama yang dipilih.
    // Nilai tetap bisa diubah manual sebelum save jika dibutuhkan.
    let firstSelectedItem = selectedItems[0] || {};
    fillRollAutoFieldsFromItem(firstSelectedItem);
    fillPotongAutoFieldsFromItem(firstSelectedItem);

    if (soBrowseModal) {
        soBrowseModal.hide();
    }

    closeSOItemSelector();
}

function escapeHtmlJS(str) {
    if (str === null || str === undefined) return '';

    return String(str).replace(/[&<>"']/g, function(m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[m];
    });
}

// Agar function lama tetap aman kalau masih ada onclick selectSO()
function selectSO(orderNo) {
    openSOItemSelector(orderNo);
}
// REGISTER EVENT LISTENER (Taruh di dalam $(document).ready jika ada, atau di luar secara independen)
$(document).ready(function() {

    // Tombol Clear / Reset di Dalam Modal Klik Handler
    $(document).on('click', '#btnResetSOModal', function() {
        $('#modal_so_start').val('');
        $('#modal_so_end').val('');
        if(typeof soTable !== 'undefined') {
            soTable.ajax.reload(); // Kembalikan ke data default tanpa filter tanggal
        }
    });
    // Tombol Filter di Dalam Modal - PERBAIKAN
    $('#btnFilterSOModal').off('click').on('click', function() {
        console.log("Filter button clicked");
        console.log("Start date:", $('#modal_so_start').val());
        console.log("End date:", $('#modal_so_end').val());
        
        if (typeof soTable !== 'undefined' && soTable !== null) {
            // Reload DataTable dengan filter baru
            soTable.ajax.reload();
        } else {
            // Jika DataTable belum ada, panggil browseSO lagi
            browseSO();
        }
    });

    // Tombol Reset Filter
    $('#btnResetSOModal').off('click').on('click', function() {
        $('#modal_so_start').val('');
        $('#modal_so_end').val('');
        
        if (typeof soTable !== 'undefined' && soTable !== null) {
            soTable.ajax.reload();
        } else {
            browseSO();
        }
    });
});

function selectSO(orderNo) {
    if (soBrowseModal) soBrowseModal.hide();
    
    // Reset specification forms untuk create mode
    resetSpecificationForms();
    
    // Tampilkan loading
    $('#customer_id').val('Loading...');
    $('#customer_name').val('Loading...');
    
    console.log("Mengambil data untuk SO:", orderNo);
    
    $.get('index.php?page=sop&action=get_so_detail&order_no=' + encodeURIComponent(orderNo))
        .done(function(res) {
            console.log("Response lengkap:", res);
            console.log("Head object:", res.head);
            console.log("Customer ID dari response:", res.head?.customer_id);
            console.log("Customer Name dari response:", res.head?.customer_name);
            
            if (res.head) {
                // Isi form dengan data dari response
                $('#customer_id').val(res.head.customer_id || '');
                $('#customer_name').val(res.head.customer_name || '');
                $('#old_code').val(res.head.old_code || '');
                $('#remarks_head').val(res.head.remarks || '');
                
                // Set order_no
                $('#form_order_no').val(res.head.order_no);
                
                console.log("Set customer_id ke:", res.head.customer_id);
                console.log("Set customer_name ke:", res.head.customer_name);
                
                // Render detail items
                let html = '';
                if(res.details && res.details.length > 0) {
                    res.details.forEach((item, index) => {
                        html += renderDetailRowForCreate(item, index);
                    });
                } else {
                    html = '<tr><td colspan="7" class="text-center text-danger py-3">Selected SO contains no bill structural lines.ucih</td></tr>';
                }
                $('#formItemGrid tbody').html(html);

                if (res.details && res.details.length > 0) {
                    fillRollAutoFieldsFromItem(res.details[0]);
                }
                
                // Cek apakah field sudah terisi
                console.log("Field customer_id value:", $('#customer_id').val());
                console.log("Field customer_name value:", $('#customer_name').val());
                
            } else {
                console.error("Invalid response structure - no head:", res);
                alert("Gagal memuat data SO. Response tidak sesuai format.");
                $('#customer_id').val('');
                $('#customer_name').val('');
            }
        })
        .fail(function(xhr, status, error) {
            console.error("AJAX Error - Status:", status);
            console.error("AJAX Error - Error:", error);
            console.error("AJAX Error - Response:", xhr.responseText);
            alert("Terjadi kesalahan sistem saat mengambil detail Sales Order: " + error);
            $('#customer_id').val('');
            $('#customer_name').val('');
        });
}

// Helper function untuk menentukan Standar Pengecekan Roll otomatis saat Create New SOP
function getAutoStandarCekRol(item) {
    let category = String(item.inventory_category || item.category || '').toUpperCase();
    let lebar = parseFloat(item.inventory_l || item.l || 0);

    // Prioritas PP: selalu 100, tidak melihat lebar.
    if (category.includes('PP')) {
        return '100';
    }

    // PE / HD: berdasarkan lebar.
    if (category.includes('PE') || category.includes('HD')) {
        return lebar < 100 ? '50' : '25';
    }

    // Selain PE / HD / PP: user isi sendiri.
    return '';
}

// Helper function untuk mengambil Density dari m_inventory ke input Berat Jenis Roll
function getAutoBeratJenisRol(item) {
    let density = item.inventory_density ?? item.density ?? '';

    if (density === null || density === undefined || density === '') {
        return '';
    }

    return String(density);
}

// Helper function untuk mengambil Catalog dari m_inventory ke input Ukuran Roll
function getAutoUkuranRol(item) {
    return String(item.inventory_catalog || item.catalog || '');
}

// Helper function untuk mengambil Value ROLL/ROL dari m_inventory_uom ke input Berat/Rol
// Contoh hasil: 16.70 KG/ROL
function getAutoBeratRol(item) {
    return String(item.inventory_berat_rol || item.berat_rol_auto || '');
}

function parseDecimalJS(value) {
    if (value === null || value === undefined) return 0;

    let str = String(value).trim().replace(',', '.');
    let match = str.match(/-?\d+(?:\.\d+)?/);

    return match ? parseFloat(match[0]) : 0;
}

function formatNumber2(value) {
    let num = parseFloat(value);

    if (!isFinite(num)) return '';

    return num.toFixed(2);
}

function formatQtyText(value) {
    let num = parseDecimalJS(value);

    if (!isFinite(num)) return '';

    if (Math.abs(num - Math.round(num)) < 0.000001) {
        return String(Math.round(num));
    }

    return String(num).replace(/\.?(0+)$/, '');
}

function getInventoryCategory(item) {
    return String(item.inventory_category || item.category || '').toUpperCase();
}

function getAutoJumlahOrderRol(item) {
    let qtyPack = formatQtyText(item.quantity_pack ?? item.qty_pack ?? 0);
    let uomPack = String(item.uom_pack || item.uom_detail || '').trim();

    if (qtyPack === '' && uomPack === '') return '';

    return (qtyPack + ' ' + uomPack).trim();
}

function getAutoNatWarnaRol(item) {
    return String(item.inventory_colour || item.colour || '');
}

function getAutoGramaturAsliRol(item) {
    let tebal = parseDecimalJS(item.inventory_t || item.t || 0);
    let lebar = parseDecimalJS(item.inventory_l || item.l || 0);
    let beratJenis = parseDecimalJS($('#berat_jenis_rol').val() || getAutoBeratJenisRol(item));
    let standarCek = parseDecimalJS($('#standar_cek_rol').val() || getAutoStandarCekRol(item));

    if (tebal <= 0 || lebar <= 0 || beratJenis <= 0 || standarCek <= 0) {
        return '';
    }

    return formatNumber2((tebal * 100 * lebar * beratJenis * standarCek) / 10000);
}

function getAutoTebalAsliRol(item) {
    let category = getInventoryCategory(item);
    let tebal = parseDecimalJS(item.inventory_t || item.t || 0);
    let density = parseDecimalJS($('#berat_jenis_rol').val() || getAutoBeratJenisRol(item));
    let pembagi = 0;

    if (category.includes('PP')) {
        pembagi = 17;
    } else if (category.includes('PE') || category.includes('HD')) {
        pembagi = 18;
    }

    if (tebal <= 0 || density <= 0 || pembagi <= 0) {
        return '';
    }

    return formatNumber2((tebal * 100 * density) / pembagi);
}

function getDefaultSpecRolText() {
    return 'Gram: +/-%  Tebal: +/-%';
}

function parseRollSpecPercent(specText) {
    let spec = String(specText || '').toUpperCase().replace(/,/g, '.');
    let result = {
        gramPlus: 0,
        gramMinus: 0,
        tebalPlus: 0,
        tebalMinus: 0
    };

    let gramRange = spec.match(/GRAM\s*:?\s*-\s*(\d+(?:\.\d+)?)\s*\+\s*(\d+(?:\.\d+)?)\s*%?/i);
    let gramPlusMinus = spec.match(/GRAM\s*:?\s*\+\/-\s*(\d+(?:\.\d+)?)\s*%?/i);

    if (gramRange) {
        result.gramMinus = parseFloat(gramRange[1]) || 0;
        result.gramPlus = parseFloat(gramRange[2]) || 0;
    } else if (gramPlusMinus) {
        result.gramMinus = parseFloat(gramPlusMinus[1]) || 0;
        result.gramPlus = parseFloat(gramPlusMinus[1]) || 0;
    }

    let tebalRange = spec.match(/TEBAL\s*:?\s*-\s*(\d+(?:\.\d+)?)\s*\+\s*(\d+(?:\.\d+)?)\s*%?/i);
    let tebalPlusMinus = spec.match(/TEBAL\s*:?\s*\+\/-\s*(\d+(?:\.\d+)?)\s*%?/i);

    if (tebalRange) {
        result.tebalMinus = parseFloat(tebalRange[1]) || 0;
        result.tebalPlus = parseFloat(tebalRange[2]) || 0;
    } else if (tebalPlusMinus) {
        result.tebalMinus = parseFloat(tebalPlusMinus[1]) || 0;
        result.tebalPlus = parseFloat(tebalPlusMinus[1]) || 0;
    }

    return result;
}

function calculateToleranceRange(baseValue, minusPercent, plusPercent) {
    let base = parseDecimalJS(baseValue);
    let minPercent = parseDecimalJS(minusPercent);
    let maxPercent = parseDecimalJS(plusPercent);

    if (base <= 0) return '';

    let minValue = base - (base * minPercent / 100);
    let maxValue = base + (base * maxPercent / 100);

    return formatNumber2(minValue) + ' -- ' + formatNumber2(maxValue);
}

function updateRollSpecDerivedFields() {
    let percent = parseRollSpecPercent($('#spec_rol').val());

    $('#gramatur_plus_rol').val(percent.gramPlus || 0);
    $('#gramatur_min_rol').val(percent.gramMinus || 0);
    $('#tebal_plus_rol').val(percent.tebalPlus || 0);
    $('#tebal_minus_rol').val(percent.tebalMinus || 0);

    $('#gramatur_rol').val(calculateToleranceRange($('#gramatur_asli_rol').val(), percent.gramMinus, percent.gramPlus));
    $('#tebal_rol').val(calculateToleranceRange($('#tebal_asli_rol').val(), percent.tebalMinus, percent.tebalPlus));
}

function refreshRollCalculatedFields() {
    let item = currentRollAutoItem || {};

    $('#gramatur_asli_rol').val(getAutoGramaturAsliRol(item));
    $('#tebal_asli_rol').val(getAutoTebalAsliRol(item));
    updateRollSpecDerivedFields();
}


function getDefaultSpecPotongText() {
    return getDefaultSpecRolText();
}

function getAutoBeratJenisPotong(item) {
    return getAutoBeratJenisRol(item);
}

function getAutoUkuranPotong(item) {
    return getAutoUkuranRol(item);
}

function getAutoJumlahOrderPotong(item) {
    return getAutoJumlahOrderRol(item);
}

function fillPotongAutoFieldsFromItem(item) {
    let currentItem = item || {};

    $('#berat_jenis_potong').val(getAutoBeratJenisPotong(currentItem));
    $('#ukuran_potong').val(getAutoUkuranPotong(currentItem));
    $('#jml_order_potong').val(getAutoJumlahOrderPotong(currentItem));

    if (!$('#spec_potong').val()) {
        $('#spec_potong').val(getDefaultSpecPotongText());
    }
}

function fillRollAutoFieldsFromItem(item) {
    currentRollAutoItem = item || {};

    $('#berat_jenis_rol').val(getAutoBeratJenisRol(currentRollAutoItem));
    $('#ukuran_rol').val(getAutoUkuranRol(currentRollAutoItem));
    $('#berat_rol').val(getAutoBeratRol(currentRollAutoItem));
    $('#jml_order_rol').val(getAutoJumlahOrderRol(currentRollAutoItem));
    $('#nat_warna_rol').val(getAutoNatWarnaRol(currentRollAutoItem));
    $('#standar_cek_rol').val(getAutoStandarCekRol(currentRollAutoItem));

    if (!$('#spec_rol').val()) {
        $('#spec_rol').val(getDefaultSpecRolText());
    }

    refreshRollCalculatedFields();
}

// Helper function untuk render row di create mode
function renderDetailRowForCreate(item, index) {
    let uomPackValue = item.uom_pack || item.uom_detail || '';
    let autoStandarCekRol = getAutoStandarCekRol(item);
    let autoBeratJenisRol = getAutoBeratJenisRol(item);
    let autoUkuranRol = getAutoUkuranRol(item);
    let autoBeratRol = getAutoBeratRol(item);
    let autoJumlahOrderRol = getAutoJumlahOrderRol(item);
    let autoNatWarnaRol = getAutoNatWarnaRol(item);
    let autoGramaturAsliRol = getAutoGramaturAsliRol(item);
    let autoTebalAsliRol = getAutoTebalAsliRol(item);
    let autoSpecRol = getDefaultSpecRolText();
    let autoBeratJenisPotong = getAutoBeratJenisPotong(item);
    let autoSpecPotong = getDefaultSpecPotongText();
    let autoUkuranPotong = getAutoUkuranPotong(item);
    let autoJumlahOrderPotong = getAutoJumlahOrderPotong(item);

    return `<tr>
        <td>
            <input type="hidden" name="items[${index}][so_detail_id]" value="${item.detail_id || ''}">
            <input type="hidden" name="items[${index}][inv_id]" value="${escapeHtmlJS(item.inventory_id || '')}">
            <code>${escapeHtmlJS(item.inventory_id || '-')}</code>
        </td>

        <td class="text-wrap">
            ${escapeHtmlJS(item.inventory_name || '-')}
            <input type="hidden" name="items[${index}][inv_name]" value="${escapeHtmlJS(item.inventory_name || '')}">
        </td>

        <td>
            <input type="number" step="any" name="items[${index}][qty]" 
                   class="form-control form-control-sm text-end fw-bold text-success" 
                   value="${item.quantity || item.qty || 0}" required>
        </td>

        <td>
            <input type="hidden" name="items[${index}][uom]" value="${escapeHtmlJS(item.uom || '')}">
            <input type="text" class="form-control form-control-sm bg-light text-center" 
                   value="${escapeHtmlJS(item.uom || '')}" readonly>
        </td>

        <td>
            <input type="number" step="any" name="items[${index}][qty_pack]" 
                   class="form-control form-control-sm text-end" 
                   value="${item.quantity_pack || item.qty_pack || 0}">
        </td>

        <td>
            <input type="text" name="items[${index}][uom_det]" 
                   class="form-control form-control-sm text-center fw-bold" 
                   value="${escapeHtmlJS(uomPackValue)}">
        </td>

        <td>
            <input type="hidden" name="items[${index}][price]" value="${item.price || 0}">
            <input type="text" name="items[${index}][remarks]" 
                   class="form-control form-control-sm" 
                   value="${escapeHtmlJS(item.remarks || '')}" 
                   placeholder="Machine/Color codes...">
        </td>

        <input type="hidden" name="items[${index}][berat_jenis_potong]" value="${escapeHtmlJS(autoBeratJenisPotong)}">
        <input type="hidden" name="items[${index}][spec_potong]" value="${escapeHtmlJS(autoSpecPotong)}">
        <input type="hidden" name="items[${index}][ukuran_potong]" value="${escapeHtmlJS(autoUkuranPotong)}">
        <input type="hidden" name="items[${index}][jml_order_potong]" value="${escapeHtmlJS(autoJumlahOrderPotong)}">
        <input type="hidden" name="items[${index}][isi_pakbal_potong]" value="">
        <input type="hidden" name="items[${index}][keterangan_potong]" value="">
        <input type="hidden" name="items[${index}][shipment_due_date]" value="">
        <input type="hidden" name="items[${index}][no_mesin_potong]" value="">
        <input type="hidden" name="items[${index}][nat_warna_potong]" value="">
        <input type="hidden" name="items[${index}][berat_rol_warna]" value="0">
        <input type="hidden" name="items[${index}][code_potong]" value="">
        <input type="hidden" name="items[${index}][jarak_seal]" value="0">

        <input type="hidden" name="items[${index}][berat_jenis_rol]" value="${escapeHtmlJS(autoBeratJenisRol)}">
        <input type="hidden" name="items[${index}][ukuran_rol]" value="${escapeHtmlJS(autoUkuranRol)}">
        <input type="hidden" name="items[${index}][berat_rol]" value="${escapeHtmlJS(autoBeratRol)}">
        <input type="hidden" name="items[${index}][isi_bal_rol]" value="">
        <input type="hidden" name="items[${index}][jml_order_rol]" value="${escapeHtmlJS(autoJumlahOrderRol)}">
        <input type="hidden" name="items[${index}][treat_rol]" value="">
        <input type="hidden" name="items[${index}][nat_warna_rol]" value="${escapeHtmlJS(autoNatWarnaRol)}">
        <input type="hidden" name="items[${index}][bobin_krepyak_rol]" value="">
        <input type="hidden" name="items[${index}][kirim_las_rol]" value="">
        <input type="hidden" name="items[${index}][standar_cek_rol]" value="${escapeHtmlJS(autoStandarCekRol)}">
        <input type="hidden" name="items[${index}][gramatur_asli_rol]" value="${escapeHtmlJS(autoGramaturAsliRol)}">
        <input type="hidden" name="items[${index}][tebal_asli_rol]" value="${escapeHtmlJS(autoTebalAsliRol)}">
        <input type="hidden" name="items[${index}][spec_rol]" value="${escapeHtmlJS(autoSpecRol)}">
        <input type="hidden" name="items[${index}][gramatur_rol]" value="0">
        <input type="hidden" name="items[${index}][tebal_rol]" value="0">
        <input type="hidden" name="items[${index}][keterangan_rol]" value="">
        <input type="hidden" name="items[${index}][gramatur_plus_rol]" value="0">
        <input type="hidden" name="items[${index}][gramatur_min_rol]" value="0">
        <input type="hidden" name="items[${index}][tebal_plus_rol]" value="0">
        <input type="hidden" name="items[${index}][tebal_minus_rol]" value="0">
        <input type="hidden" name="items[${index}][no_mesin_rol]" value="">
        <input type="hidden" name="items[${index}][code_rol]" value="">
    </tr>`;
}

function editSOP(rowData) {
    console.log("Edit SOP called with:", rowData); // Debug
    
    // 1. Inisialisasi awal modal dalam mode Edit
    openSOPModal(true);
    
    // 2. Mapping data header
    $('#modalTitle').html('<i class="bi bi-window-stack"></i> Document Data Entry [Edit SOP: ' + rowData.sop_id + ']');
    $('#form_is_edit').val('1');
    $('#form_sop_id').val(rowData.sop_id);
    $('#form_sop_date').val(rowData.sop_date).prop('readonly', true); 
    $('#form_target_date').val(rowData.target_date);
    $('#form_order_no').val(rowData.order_no);
    $('#customer_id').val(rowData.cust_id_ref || '-');
    $('#customer_name').val(rowData.customer);
    $('#old_code').val(rowData.old_code);
    $('#form_no_urut_roll').val(rowData.no_urut_roll);
    $('#form_no_urut_potong').val(rowData.no_urut_potong);
    $('#remarks_head').val(rowData.remarks);
    $('#btnBrowseSO').hide();

    // 3. Reset form specifications terlebih dahulu
    resetSpecificationForms();
    
    // 4. Tampilkan loading indicator di semua tab
    $('#formItemGrid tbody').html('<tr><td colspan="7" class="text-center py-3"><i class="bi bi-hourglass-split"></i> Loading item lines...</td></tr>');
    $('#potong_tbody').html('<tr><td colspan="2" class="text-center py-3"><i class="bi bi-hourglass-split"></i> Loading specification data...</td></tr>');
    $('#roll_tbody').html('<tr><td colspan="2" class="text-center py-3"><i class="bi bi-hourglass-split"></i> Loading specification data...</td></tr>');
    
    // 5. Gunakan endpoint untuk mengambil data lengkap
    let urlComplete = 'index.php?page=sop&action=get_sop_detail_complete&sop_id=' + encodeURIComponent(rowData.sop_id);
    console.log("Fetching from:", urlComplete); // Debug
    
    $.ajax({
        url: urlComplete,
        method: 'GET',
        dataType: 'json',
        success: function(response) {
            console.log("Response received:", response); // Debug
            
            if (response.status === 'success') {
                if (response.items && response.items.length > 0) {
                    let item = response.items[0]; // Ambil item pertama
                    
                    // Render Bill of Materials tab
                    let bomHtml = renderBOMRow(item, 0);
                    $('#formItemGrid tbody').html(bomHtml);
                    
                    // Isi data spesifikasi POTONG
                    console.log("Populating Potong Spec with:", item);
                    populatePotongSpec(item);
                    
                    // Isi data spesifikasi ROLL
                    console.log("Populating Roll Spec with:", item);
                    populateRollSpec(item);
                    
                    // Hapus loading indicator dengan data kosong jika perlu
                    if (!item.berat_jenis_potong && !item.spec_potong) {
                        $('#potong_tbody').html(`
                            <tr><td colspan="2" class="text-center text-muted py-3">Tidak ada data spesifikasi potong. Silakan isi manual.</td></tr>
                            ${$('#potong_tbody').html()}
                        `);
                    }
                    
                    if (!item.berat_jenis_rol && !item.ukuran_rol) {
                        $('#roll_tbody').html(`
                            <tr><td colspan="2" class="text-center text-muted py-3">Tidak ada data spesifikasi roll. Silakan isi manual.</td></tr>
                            ${$('#roll_tbody').html()}
                        `);
                    }
                    
                } else {
                    console.warn("No items found in response");
                    $('#formItemGrid tbody').html('<tr><td colspan="7" class="text-center text-warning py-3">No material items found for this SOP.您的美学</td></tr>');
                    $('#potong_tbody').html('<tr><td colspan="2" class="text-center text-warning py-3">No specification data found.</td></tr>');
                    $('#roll_tbody').html('<tr><td colspan="2" class="text-center text-warning py-3">No specification data found.</td></tr>');
                }
            } else {
                console.error('Invalid response:', response);
                $('#formItemGrid tbody').html('<tr><td colspan="7" class="text-center text-danger py-3">Failed to load SOP detail data. Error: ' + (response.error || 'Unknown') + '</td></tr>');
                $('#potong_tbody').html('<tr><td colspan="2" class="text-center text-danger py-3">Failed to load specification data.</td></tr>');
                $('#roll_tbody').html('<tr><td colspan="2" class="text-center text-danger py-3">Failed to load specification data.</td></tr>');
            }
        },
        error: function(xhr, status, error) {
            console.error("AJAX Error:", {
                status: status,
                error: error,
                responseText: xhr.responseText
            });
            $('#formItemGrid tbody').html('<td><td colspan="7" class="text-center text-danger py-3">Error: ' + error + '. Check console for details.</td></tr>');
            $('#potong_tbody').html('<tr><td colspan="2" class="text-center text-danger py-3">Error loading data.</td></tr>');
            $('#roll_tbody').html('<tr><td colspan="2" class="text-center text-danger py-3">Error loading data.</td></tr>');
            alert('Gagal mengambil data detail item untuk koreksi. Cek konsol browser (F12).');
        }
    });
}
// ==========================================
// FUNGSI COPY SOP
// ==========================================
function copySOP(sopId) {
    if (confirm("Salin SOP " + sopId + " ke dokumen baru?\n\nSemua data item dan spesifikasi akan digandakan.")) {
        // Tampilkan loading
        $('#formItemGrid tbody').html('<tr><td colspan="7" class="text-center py-3"><i class="bi bi-hourglass-split"></i> Loading data for copy...</td></tr>');
        $('#potong_tbody').html('<tr><td colspan="2" class="text-center py-3"><i class="bi bi-hourglass-split"></i> Loading specification data...</td></tr>');
        $('#roll_tbody').html('<tr><td colspan="2" class="text-center py-3"><i class="bi bi-hourglass-split"></i> Loading specification data...</td></tr>');
        
        $.ajax({
            url: 'index.php?page=sop&action=get_sop_for_copy&sop_id=' + encodeURIComponent(sopId),
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success' && response.items) {
                    // Reset form untuk create mode
                    openSOPModal(false, true);
                    
                    // Isi data header (dengan modifikasi)
                    $('#form_sop_date').val(new Date().toISOString().slice(0,10));
                    $('#form_target_date').val(response.head.target_date || '');
                    
                    // PERBAIKAN: Kosongkan order_no agar user bisa memilih SO baru
                    $('#form_order_no').val(''); 
                    
                    // PERBAIKAN: Jangan isi customer dari SOP lama
                    $('#customer_id').val('');
                    $('#customer_name').val('');
                    $('#old_code').val('');
                    
                    $('#form_no_urut_roll').val(response.head.no_urut_roll || '');
                    $('#form_no_urut_potong').val(response.head.no_urut_potong || '');
                    $('#remarks_head').val(response.head.remarks || '');
                    
                    // Set jenis pekerjaan (suffix)
                    if (response.head.sop_type) {
                        $('#sop_type').val(response.head.sop_type);
                    }
                    
                    // Reset specification forms
                    resetSpecificationForms();
                    
                    // Isi data items dan specifications
                    let items = response.items;
                    items.forEach((item, index) => {
                        let bomHtml = renderBOMRowForCopy(item, index);
                        $('#formItemGrid tbody').html(bomHtml);
                        
                        if (index === 0) {
                            populatePotongSpec(item);
                            populateRollSpec(item);
                        }
                    });
                    
                    // Tampilkan pesan sukses dengan instruksi tambahan
                    alert("Data SOP berhasil disalin. Silakan pilih Sales Order baru sebelum menyimpan.");
                    
                } else {
                    console.error('Invalid response:', response);
                    alert('Gagal memuat data untuk disalin.');
                }
            },
            error: function(xhr, status, error) {
                console.error("Copy Error Log:", xhr.responseText);
                alert('Terjadi kesalahan saat menyalin data SOP.');
            }
        });
    }
}

// Helper function untuk render BOM row untuk copy (tanpa hidden fields yang sudah ada)
function renderBOMRowForCopy(item, index) {
    return `<tr>
        <td>
            <input type="hidden" name="items[${index}][inv_id]" value="${item.inventory_id || ''}">
            <code>${item.inventory_id || '-'}</code>
        </td>
        <td class="text-wrap">${item.inventory_name || '-'}
            <input type="hidden" name="items[${index}][inv_name]" value="${item.inventory_name || ''}">
        </td>
        <td>
            <input type="number" step="any" name="items[${index}][qty]" class="form-control form-control-sm text-end fw-bold text-success" value="${item.qty || 0}" required>
        </td>
        <td>
            <input type="hidden" name="items[${index}][uom]" value="${item.uom || ''}">
            <input type="text" class="form-control form-control-sm bg-light text-center" value="${item.uom || ''}" readonly>
        </td>
        <td>
            <input type="number" step="any" name="items[${index}][qty_pack]" class="form-control form-control-sm text-end" value="${item.qty_pack || 0}">
        </td>
        <td>
            <input type="text" name="items[${index}][uom_det]" class="form-control form-control-sm text-center" value="${item.uom_detail || ''}">
        </td>
        <td>
            <input type="hidden" name="items[${index}][price]" value="${item.price || 0}">
            <input type="text" name="items[${index}][remarks]" class="form-control form-control-sm" value="${item.remarks || ''}" placeholder="Machine/Color codes...">
        </td>
        
        <!-- Hidden fields untuk specifications -->
        <input type="hidden" name="items[${index}][berat_jenis_potong]" value="${item.berat_jenis_potong || 0}">
        <input type="hidden" name="items[${index}][spec_potong]" value="${item.spec_potong || ''}">
        <input type="hidden" name="items[${index}][ukuran_potong]" value="${item.ukuran_potong || ''}">
        <input type="hidden" name="items[${index}][jml_order_potong]" value="${item.jml_order_potong || ''}">
        <input type="hidden" name="items[${index}][isi_pakbal_potong]" value="${item.isi_pakbal_potong || ''}">
        <input type="hidden" name="items[${index}][keterangan_potong]" value="${item.keterangan_potong || ''}">
        <input type="hidden" name="items[${index}][shipment_due_date]" value="${item.shipment_due_date || ''}">
        <input type="hidden" name="items[${index}][no_mesin_potong]" value="${item.no_mesin_potong || ''}">
        <input type="hidden" name="items[${index}][nat_warna_potong]" value="${item.nat_warna_potong || ''}">
        <input type="hidden" name="items[${index}][berat_rol_warna]" value="${item.berat_rol_warna || 0}">
        <input type="hidden" name="items[${index}][code_potong]" value="${item.code_potong || ''}">
        <input type="hidden" name="items[${index}][jarak_seal]" value="${item.jarak_seal || 0}">
        
        <input type="hidden" name="items[${index}][berat_jenis_rol]" value="${item.berat_jenis_rol || ''}">
        <input type="hidden" name="items[${index}][ukuran_rol]" value="${item.ukuran_rol || ''}">
        <input type="hidden" name="items[${index}][berat_rol]" value="${item.berat_rol || ''}">
        <input type="hidden" name="items[${index}][isi_bal_rol]" value="${item.isi_bal_rol || ''}">
        <input type="hidden" name="items[${index}][jml_order_rol]" value="${item.jml_order_rol || 0}">
        <input type="hidden" name="items[${index}][treat_rol]" value="${item.treat_rol || ''}">
        <input type="hidden" name="items[${index}][nat_warna_rol]" value="${item.nat_warna_rol || ''}">
        <input type="hidden" name="items[${index}][bobin_krepyak_rol]" value="${item.bobin_krepyak_rol || ''}">
        <input type="hidden" name="items[${index}][kirim_las_rol]" value="${item.kirim_las_rol || ''}">
        <input type="hidden" name="items[${index}][standar_cek_rol]" value="${item.standar_cek_rol || ''}">
        <input type="hidden" name="items[${index}][gramatur_asli_rol]" value="${item.gramatur_asli_rol || 0}">
        <input type="hidden" name="items[${index}][tebal_asli_rol]" value="${item.tebal_asli_rol || 0}">
        <input type="hidden" name="items[${index}][spec_rol]" value="${item.spec_rol || ''}">
        <input type="hidden" name="items[${index}][gramatur_rol]" value="${item.gramatur_rol || 0}">
        <input type="hidden" name="items[${index}][tebal_rol]" value="${item.tebal_rol || 0}">
        <input type="hidden" name="items[${index}][keterangan_rol]" value="${item.keterangan_rol || ''}">
        <input type="hidden" name="items[${index}][gramatur_plus_rol]" value="${item.gramatur_plus_rol || 0}">
        <input type="hidden" name="items[${index}][gramatur_min_rol]" value="${item.gramatur_min_rol || 0}">
        <input type="hidden" name="items[${index}][tebal_plus_rol]" value="${item.tebal_plus_rol || 0}">
        <input type="hidden" name="items[${index}][tebal_minus_rol]" value="${item.tebal_minus_rol || 0}">
        <input type="hidden" name="items[${index}][no_mesin_rol]" value="${item.no_mesin_rol || ''}">
        <input type="hidden" name="items[${index}][code_rol]" value="${item.code_rol || ''}">
    </tr>`;
}

// Helper function untuk reset form specifications
function resetSpecificationForms() {
    currentRollAutoItem = null;
    // Reset POTONG fields
    $('#berat_jenis_potong').val('');
    $('#spec_potong').val('');
    $('#ukuran_potong').val('');
    $('#jml_order_potong').val('');
    $('#isi_pakbal_potong').val('');
    $('#keterangan_potong').val('');
    $('#shipment_due_date_potong, #shipment_due_date_rol').val('');
    $('#no_mesin_potong').val('');
    $('#nat_warna_potong').val('');
    $('#berat_rol_warna').val(0);
    $('#code_potong').val('');
    $('#jarak_seal').val(0);
    
    // Reset ROLL fields
    $('#berat_jenis_rol').val('');
    $('#ukuran_rol').val('');
    $('#berat_rol').val('');
    $('#isi_bal_rol').val('');
    $('#jml_order_rol').val('');
    $('#treat_rol').val('');
    $('#nat_warna_rol').val('');
    $('#bobin_krepyak_rol').val('');
    $('#kirim_las_rol').val('');
    $('#standar_cek_rol').val('');
    $('#gramatur_asli_rol').val('');
    $('#tebal_asli_rol').val('');
    $('#spec_rol').val('');
    $('#gramatur_rol').val('');
    $('#tebal_rol').val('');
    $('#keterangan_rol').val('');
    $('#gramatur_plus_rol').val(0);
    $('#gramatur_min_rol').val(0);
    $('#tebal_plus_rol').val(0);
    $('#tebal_minus_rol').val(0);
    $('#no_mesin_rol').val('');
    $('#code_rol').val('');
}

// Helper function untuk render BOM row
function renderBOMRow(item, index) {
    return `<tr>
        <td>
            <input type="hidden" name="items[${index}][inv_id]" value="${item.inventory_id || ''}">
            <code>${item.inventory_id || '-'}</code>
        </td>
        <td class="text-wrap">${item.inventory_name || '-'}
            <input type="hidden" name="items[${index}][inv_name]" value="${item.inventory_name || ''}">
        </td>
        <td>
            <input type="number" step="any" name="items[${index}][qty]" class="form-control form-control-sm text-end fw-bold text-success" value="${item.qty || 0}" required>
        </td>
        <td>
            <input type="hidden" name="items[${index}][uom]" value="${item.uom || ''}">
            <input type="text" class="form-control form-control-sm bg-light text-center" value="${item.uom || ''}" readonly>
        </td>
        <td>
            <input type="number" step="any" name="items[${index}][qty_pack]" class="form-control form-control-sm text-end" value="${item.qty_pack || 0}">
        </td>
        <td>
            <input type="text" name="items[${index}][uom_det]" class="form-control form-control-sm text-center" value="${item.uom_detail || ''}">
        </td>
        <td>
            <input type="hidden" name="items[${index}][price]" value="${item.price || 0}">
            <input type="text" name="items[${index}][remarks]" class="form-control form-control-sm" value="${item.remarks || ''}" placeholder="Machine/Color codes...">
        </td>
        <!-- Hidden fields untuk specifications yang akan di-submit -->
        <input type="hidden" name="items[${index}][berat_jenis_potong]" id="hidden_berat_jenis_potong_${index}" value="${item.berat_jenis_potong || 0}">
        <input type="hidden" name="items[${index}][spec_potong]" id="hidden_spec_potong_${index}" value="${item.spec_potong || ''}">
        <input type="hidden" name="items[${index}][ukuran_potong]" id="hidden_ukuran_potong_${index}" value="${item.ukuran_potong || ''}">
        <input type="hidden" name="items[${index}][jml_order_potong]" id="hidden_jml_order_potong_${index}" value="${item.jml_order_potong || ''}">
        <input type="hidden" name="items[${index}][isi_pakbal_potong]" id="hidden_isi_pakbal_potong_${index}" value="${item.isi_pakbal_potong || ''}">
        <input type="hidden" name="items[${index}][keterangan_potong]" id="hidden_keterangan_potong_${index}" value="${item.keterangan_potong || ''}">
        <input type="hidden" name="items[${index}][shipment_due_date]" id="hidden_shipment_due_date_${index}" value="${item.shipment_due_date || ''}">
        <input type="hidden" name="items[${index}][no_mesin_potong]" id="hidden_no_mesin_potong_${index}" value="${item.no_mesin_potong || ''}">
        <input type="hidden" name="items[${index}][nat_warna_potong]" id="hidden_nat_warna_potong_${index}" value="${item.nat_warna_potong || ''}">
        <input type="hidden" name="items[${index}][berat_rol_warna]" id="hidden_berat_rol_warna_${index}" value="${item.berat_rol_warna || 0}">
        <input type="hidden" name="items[${index}][code_potong]" id="hidden_code_potong_${index}" value="${item.code_potong || ''}">
        <input type="hidden" name="items[${index}][jarak_seal]" id="hidden_jarak_seal_${index}" value="${item.jarak_seal || 0}">
        
        <input type="hidden" name="items[${index}][berat_jenis_rol]" id="hidden_berat_jenis_rol_${index}" value="${item.berat_jenis_rol || ''}">
        <input type="hidden" name="items[${index}][ukuran_rol]" id="hidden_ukuran_rol_${index}" value="${item.ukuran_rol || ''}">
        <input type="hidden" name="items[${index}][berat_rol]" id="hidden_berat_rol_${index}" value="${item.berat_rol || ''}">
        <input type="hidden" name="items[${index}][isi_bal_rol]" id="hidden_isi_bal_rol_${index}" value="${item.isi_bal_rol || ''}">
        <input type="hidden" name="items[${index}][jml_order_rol]" id="hidden_jml_order_rol_${index}" value="${item.jml_order_rol || 0}">
        <input type="hidden" name="items[${index}][treat_rol]" id="hidden_treat_rol_${index}" value="${item.treat_rol || ''}">
        <input type="hidden" name="items[${index}][nat_warna_rol]" id="hidden_nat_warna_rol_${index}" value="${item.nat_warna_rol || ''}">
        <input type="hidden" name="items[${index}][bobin_krepyak_rol]" id="hidden_bobin_krepyak_rol_${index}" value="${item.bobin_krepyak_rol || ''}">
        <input type="hidden" name="items[${index}][kirim_las_rol]" id="hidden_kirim_las_rol_${index}" value="${item.kirim_las_rol || ''}">
        <input type="hidden" name="items[${index}][standar_cek_rol]" id="hidden_standar_cek_rol_${index}" value="${item.standar_cek_rol || ''}">
        <input type="hidden" name="items[${index}][gramatur_asli_rol]" id="hidden_gramatur_asli_rol_${index}" value="${item.gramatur_asli_rol || 0}">
        <input type="hidden" name="items[${index}][tebal_asli_rol]" id="hidden_tebal_asli_rol_${index}" value="${item.tebal_asli_rol || 0}">
        <input type="hidden" name="items[${index}][spec_rol]" id="hidden_spec_rol_${index}" value="${item.spec_rol || ''}">
        <input type="hidden" name="items[${index}][gramatur_rol]" id="hidden_gramatur_rol_${index}" value="${item.gramatur_rol || 0}">
        <input type="hidden" name="items[${index}][tebal_rol]" id="hidden_tebal_rol_${index}" value="${item.tebal_rol || 0}">
        <input type="hidden" name="items[${index}][keterangan_rol]" id="hidden_keterangan_rol_${index}" value="${item.keterangan_rol || ''}">
        <input type="hidden" name="items[${index}][gramatur_plus_rol]" id="hidden_gramatur_plus_rol_${index}" value="${item.gramatur_plus_rol || 0}">
        <input type="hidden" name="items[${index}][gramatur_min_rol]" id="hidden_gramatur_min_rol_${index}" value="${item.gramatur_min_rol || 0}">
        <input type="hidden" name="items[${index}][tebal_plus_rol]" id="hidden_tebal_plus_rol_${index}" value="${item.tebal_plus_rol || 0}">
        <input type="hidden" name="items[${index}][tebal_minus_rol]" id="hidden_tebal_minus_rol_${index}" value="${item.tebal_minus_rol || 0}">
        <input type="hidden" name="items[${index}][no_mesin_rol]" id="hidden_no_mesin_rol_${index}" value="${item.no_mesin_rol || ''}">
        <input type="hidden" name="items[${index}][code_rol]" id="hidden_code_rol_${index}" value="${item.code_rol || ''}">
    </tr>`;
}

// Helper function untuk populate spesifikasi POTONG
function populatePotongSpec(item) {
    console.log("populatePotongSpec called with:", item);
    
    // HAPUS LOADING INDICATOR - ganti dengan HTML form yang benar
    let potongHtml = `
        <tr>
            <td width="30%"><b>Berat Jenis Potong</b></td><td><input type="text" id="berat_jenis_potong" class="form-control form-control-sm" value="${item.berat_jenis_potong || 0}"></td>
        </tr>
        <tr>
            <td><b>Spec Potong</b></td><td><input type="text" id="spec_potong" class="form-control form-control-sm" value="${item.spec_potong || ''}"></td>
        </tr>
        <tr>
            <td><b>Ukuran Potong</b></td><td><input type="text" id="ukuran_potong" class="form-control form-control-sm" value="${item.ukuran_potong || ''}"></td>
        </tr>
        <tr>
            <td><b>Jumlah Order Potong</b></td><td><input type="text" id="jml_order_potong" class="form-control form-control-sm" value="${item.jml_order_potong || ''}"></td>
        </tr>
        <tr>
            <td><b>Isi Pak/Bal Potong</b></td><td><input type="text" id="isi_pakbal_potong" class="form-control form-control-sm" value="${item.isi_pakbal_potong || ''}"></td>
        </tr>
        <tr>
            <td><b>Keterangan Potong</b></td><td><textarea id="keterangan_potong" rows="2" class="form-control form-control-sm">${item.keterangan_potong || ''}</textarea></td>
        </tr>
        <tr>
            <td><b>Tanggal Kirim</b></td><td><input type="date" id="shipment_due_date_potong" class="form-control form-control-sm shipment-due-date-input" value="${item.shipment_due_date || ''}"></td>
        </tr>
        <tr>
            <td><b>No. Mesin Potong</b></td><td><input type="text" id="no_mesin_potong" class="form-control form-control-sm" value="${item.no_mesin_potong || ''}"></td>
        </tr>
        <tr>
            <td><b>Nat/Warna Potong</b></td><td><input type="text" id="nat_warna_potong" class="form-control form-control-sm" value="${item.nat_warna_potong || ''}"></td>
        </tr>
        <tr>
            <td><b>Berat/Rol Potong</b></td><td><input type="number" step="0.0001" id="berat_rol_warna" class="form-control form-control-sm" value="${item.berat_rol_warna || 0}"></td>
        </tr>
        <tr>
            <td><b>Code Potong</b></td><td><input type="text" id="code_potong" class="form-control form-control-sm" value="${item.code_potong || ''}"></td>
        </tr>
        <tr>
            <td><b>Jarak Seal</b></td><td><input type="number" step="0.01" id="jarak_seal" class="form-control form-control-sm" value="${item.jarak_seal || 0}"></td>
        </tr>
    `;
    
    $('#potong_tbody').html(potongHtml);
    console.log("Potong specs populated");
}

// Helper function untuk populate spesifikasi ROLL
function populateRollSpec(item) {
    console.log("populateRollSpec called with:", item);
    
    let rollHtml = `
        <tr>
            <td width="30%"><b>Berat Jenis Roll</b></td><td><input type="number" step="0.0001" id="berat_jenis_rol" class="form-control form-control-sm" value="${item.berat_jenis_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Ukuran Roll</b></td><td><input type="text" id="ukuran_rol" class="form-control form-control-sm" value="${item.ukuran_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Berat/Rol</b></td><td><input type="number" step="0.0001" id="berat_rol" class="form-control form-control-sm" value="${item.berat_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Isi/Bal</b></td><td><input type="text" id="isi_bal_rol" class="form-control form-control-sm" value="${item.isi_bal_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Jumlah Order Roll</b></td><td><input type="text" id="jml_order_rol" class="form-control form-control-sm" value="${item.jml_order_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Treat/Tdk</b></td><td>
                <select id="treat_rol" class="form-select form-select-sm">
                    <option value="">-- Pilih --</option>
                    <option value="Treat" ${item.treat_rol === 'Treat' ? 'selected' : ''}>Treat</option>
                    <option value="Non Treat" ${item.treat_rol === 'Non Treat' ? 'selected' : ''}>Non Treat</option>
                </select>
            </td>
        </tr>
        <tr>
            <td><b>Nat/Warna Roll</b></td><td><input type="text" id="nat_warna_rol" class="form-control form-control-sm" value="${item.nat_warna_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Bobin/Kreprak</b></td><td><input type="text" id="bobin_krepyak_rol" class="form-control form-control-sm" value="${item.bobin_krepyak_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Kirim/Las</b></td><td><input type="text" id="kirim_las_rol" class="form-control form-control-sm" value="${item.kirim_las_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Standar Pengecekan</b></td><td><input type="text" id="standar_cek_rol" class="form-control form-control-sm" value="${item.standar_cek_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Gramatur Asli</b></td><td><input type="number" step="0.0001" id="gramatur_asli_rol" class="form-control form-control-sm" value="${item.gramatur_asli_rol || 0}"></td>
        </tr>
        <tr>
            <td><b>Tebal Asli</b></td><td><input type="number" step="0.0001" id="tebal_asli_rol" class="form-control form-control-sm" value="${item.tebal_asli_rol || 0}"></td>
        </tr>
        <tr>
            <td><b>Spesifikasi Roll</b></td><td><input type="text" id="spec_rol" class="form-control form-control-sm" value="${item.spec_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Gramatur Roll</b></td><td><input type="text" id="gramatur_rol" class="form-control form-control-sm" value="${item.gramatur_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Tebal Roll</b></td><td><input type="text" id="tebal_rol" class="form-control form-control-sm" value="${item.tebal_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Keterangan Roll</b></td><td><textarea id="keterangan_rol" rows="2" class="form-control form-control-sm">${item.keterangan_rol || ''}</textarea></td>
        </tr>
        <tr>
            <td><b>Gramatur (+)</b></td><td><input type="number" step="0.0001" id="gramatur_plus_rol" class="form-control form-control-sm" value="${item.gramatur_plus_rol || 0}"></td>
        </tr>
        <tr>
            <td><b>Gramatur (-)</b></td><td><input type="number" step="0.0001" id="gramatur_min_rol" class="form-control form-control-sm" value="${item.gramatur_min_rol || 0}"></td>
        </tr>
        <tr>
            <td><b>Tebal (+)</b></td><td><input type="number" step="0.0001" id="tebal_plus_rol" class="form-control form-control-sm" value="${item.tebal_plus_rol || 0}"></td>
        </tr>
        <tr>
            <td><b>Tebal (-)</b></td><td><input type="number" step="0.0001" id="tebal_minus_rol" class="form-control form-control-sm" value="${item.tebal_minus_rol || 0}"></td>
        </tr>
        <tr>
            <td><b>Tanggal Kirim</b></td><td><input type="date" id="shipment_due_date_rol" class="form-control form-control-sm shipment-due-date-input" value="${item.shipment_due_date || ''}"></td>
        </tr>
        <tr>
            <td><b>No. Mesin Roll</b></td><td><input type="text" id="no_mesin_rol" class="form-control form-control-sm" value="${item.no_mesin_rol || ''}"></td>
        </tr>
        <tr>
            <td><b>Code Roll</b></td><td><input type="text" id="code_rol" class="form-control form-control-sm" value="${item.code_rol || ''}"></td>
        </tr>
    `;
    
    $('#roll_tbody').html(rollHtml);
    console.log("Roll specs populated");
}

function deleteSOP(sopId) {
    if (confirm("Apakah Anda yakin ingin menghapus dokumen SOP " + sopId + "?")) {
        $.ajax({
            url: 'index.php?page=sop&action=delete_sop',
            type: 'POST',
            data: { sop_id: sopId },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    alert(response.message);
                    // FIX: Segarkan halaman secara total agar PHP me-render ulang tabel tanpa data yang dihapus
                    location.reload(); 
                } else {
                    alert("Gagal menghapus: " + response.message);
                }
            },
            error: function(xhr, status, error) {
                // Jika error, kita bisa intip apa yang sebenarnya keluar dari PHP
                console.error("Respon PHP Rusak:", xhr.responseText);
                alert("Terjadi kesalahan sistem saat mencoba menghapus data.");
            }
        });
    }
}

function getShipmentDueDateValue() {
    return $('#shipment_due_date_potong').val() || $('#shipment_due_date_rol').val() || '';
}

$(document).on('change input', '.shipment-due-date-input', function() {
    $('.shipment-due-date-input').val($(this).val());
});

// Tambahkan fungsi untuk mengumpulkan data dari semua tab
function collectSpecificationData() {
    return {
        // Potong specifications
        berat_jenis_potong: $('#berat_jenis_potong').val(),
        spec_potong: $('#spec_potong').val(),
        ukuran_potong: $('#ukuran_potong').val(),
        jml_order_potong: $('#jml_order_potong').val(),
        isi_pakbal_potong: $('#isi_pakbal_potong').val(),
        keterangan_potong: $('#keterangan_potong').val(),
        shipment_due_date: getShipmentDueDateValue(),
        no_mesin_potong: $('#no_mesin_potong').val(),
        nat_warna_potong: $('#nat_warna_potong').val(),
        berat_rol_warna: $('#berat_rol_warna').val(),
        code_potong: $('#code_potong').val(),
        jarak_seal: $('#jarak_seal').val(),
        
        // Roll specifications
        berat_jenis_rol: $('#berat_jenis_rol').val(),
        ukuran_rol: $('#ukuran_rol').val(),
        berat_rol: $('#berat_rol').val(),
        isi_bal_rol: $('#isi_bal_rol').val(),
        jml_order_rol: $('#jml_order_rol').val(),
        treat_rol: $('#treat_rol').val(),
        nat_warna_rol: $('#nat_warna_rol').val(),
        bobin_krepyak_rol: $('#bobin_krepyak_rol').val(),
        kirim_las_rol: $('#kirim_las_rol').val(),
        standar_cek_rol: $('#standar_cek_rol').val(),
        gramatur_asli_rol: $('#gramatur_asli_rol').val(),
        tebal_asli_rol: $('#tebal_asli_rol').val(),
        spec_rol: $('#spec_rol').val(),
        gramatur_rol: $('#gramatur_rol').val(),
        tebal_rol: $('#tebal_rol').val(),
        keterangan_rol: $('#keterangan_rol').val(),
        gramatur_plus_rol: $('#gramatur_plus_rol').val(),
        gramatur_min_rol: $('#gramatur_min_rol').val(),
        tebal_plus_rol: $('#tebal_plus_rol').val(),
        tebal_minus_rol: $('#tebal_minus_rol').val(),
        no_mesin_rol: $('#no_mesin_rol').val(),
        code_rol: $('#code_rol').val()
    };
}

// Fungsi untuk mengisi data spesifikasi saat edit
function populateSpecificationData(data) {
    $('#berat_jenis_potong').val(data.berat_jenis_potong || '');
    $('#spec_potong').val(data.spec_potong || '');
    $('#ukuran_potong').val(data.ukuran_potong || '');
    $('#jml_order_potong').val(data.jml_order_potong || '');
    $('#isi_pakbal_potong').val(data.isi_pakbal_potong || '');
    $('#keterangan_potong').val(data.keterangan_potong || '');
    $('#shipment_due_date_potong, #shipment_due_date_rol').val(data.shipment_due_date || '');
    $('#no_mesin_potong').val(data.no_mesin_potong || '');
    $('#nat_warna_potong').val(data.nat_warna_potong || '');
    $('#berat_rol_warna').val(data.berat_rol_warna || 0);
    $('#code_potong').val(data.code_potong || '');
    $('#jarak_seal').val(data.jarak_seal || 0);
    
    $('#berat_jenis_rol').val(data.berat_jenis_rol || '');
    $('#ukuran_rol').val(data.ukuran_rol || '');
    $('#berat_rol').val(data.berat_rol || 0);
    $('#isi_bal_rol').val(data.isi_bal_rol || '');
    $('#jml_order_rol').val(data.jml_order_rol || 0);
    $('#treat_rol').val(data.treat_rol || '');
    $('#nat_warna_rol').val(data.nat_warna_rol || '');
    $('#bobin_krepyak_rol').val(data.bobin_krepyak_rol || '');
    $('#kirim_las_rol').val(data.kirim_las_rol || '');
    $('#standar_cek_rol').val(data.standar_cek_rol || '');
    $('#gramatur_asli_rol').val(data.gramatur_asli_rol || 0);
    $('#tebal_asli_rol').val(data.tebal_asli_rol || 0);
    $('#spec_rol').val(data.spec_rol || '');
    $('#gramatur_rol').val(data.gramatur_rol || 0);
    $('#tebal_rol').val(data.tebal_rol || 0);
    $('#keterangan_rol').val(data.keterangan_rol || '');
    $('#gramatur_plus_rol').val(data.gramatur_plus_rol || 0);
    $('#gramatur_min_rol').val(data.gramatur_min_rol || 0);
    $('#tebal_plus_rol').val(data.tebal_plus_rol || 0);
    $('#tebal_minus_rol').val(data.tebal_minus_rol || 0);
    $('#no_mesin_rol').val(data.no_mesin_rol || '');
    $('#code_rol').val(data.code_rol || '');
}

</script>
</body>
</html>