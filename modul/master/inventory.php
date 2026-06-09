<?php
// modul/master/inventory.php

if (!isset($_SESSION['username'])) {
    header("Location: ../../login.php");
    exit;
}

include __DIR__ . '/../../koneksi.php';

// ----------------------------------------------------
// TAMPILKAN ALERT DARI SESSION (PRG Pattern)
// ----------------------------------------------------
$alert_message = "";
if (isset($_SESSION['alert'])) {
    $alert_message = $_SESSION['alert'];
    unset($_SESSION['alert']); // Hapus setelah ditampilkan
}


// Ambil data UOM untuk dropdown
$uom_list = mysqli_query($conn, "SELECT unit FROM m_uom WHERE is_active='Checked' ORDER BY unit ASC");
$category_list = mysqli_query($conn, "SELECT categori_id, name FROM m_category ORDER BY name ASC");
// ----------------------------------------------------
// LOGIC BACKEND (INSERT, UPDATE, DELETE)
// ----------------------------------------------------

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_form'])) {
    $action_form        = $_POST['action_form'];
    $inventory_id       = mysqli_real_escape_string($conn, trim($_POST['inventory_id'] ?? ''));
    $inventory_name     = mysqli_real_escape_string($conn, trim($_POST['inventory_name'] ?? ''));
    $uom                = mysqli_real_escape_string($conn, trim($_POST['uom'] ?? ''));
    $type               = mysqli_real_escape_string($conn, trim($_POST['type'] ?? ''));
   $category            = mysqli_real_escape_string($conn, trim($_POST['category'] ?? ''));
    $remarks            = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    
    // Tab Specification
    $cap                = mysqli_real_escape_string($conn, trim($_POST['cap'] ?? ''));
    $colour             = mysqli_real_escape_string($conn, trim($_POST['colour'] ?? ''));
    $quality            = mysqli_real_escape_string($conn, trim($_POST['quality'] ?? ''));
    $volume_default     = floatval($_POST['volume_default'] ?? 0);
    $uom_pack           = mysqli_real_escape_string($conn, trim($_POST['uom_pack'] ?? ''));
    $tolerance          = floatval($_POST['tolerance'] ?? 0);
    $upper_tolerance    = floatval($_POST['upper_tolerance'] ?? 0);
    $lower_tolerance    = floatval($_POST['lower_tolerance'] ?? 0);
    
    // Tab Detail Specification
    $merk               = mysqli_real_escape_string($conn, trim($_POST['merk'] ?? ''));
    $p                  = floatval($_POST['p'] ?? 0);
    $l                  = floatval($_POST['l'] ?? 0);
    $t                  = floatval($_POST['t'] ?? 0);
    $p2                 = floatval($_POST['p2'] ?? 0);
    $tebal              = floatval($_POST['tebal'] ?? 0);
    $ukuran             = mysqli_real_escape_string($conn, trim($_POST['ukuran'] ?? ''));
    $density            = floatval($_POST['density'] ?? 0);
    $strength           = mysqli_real_escape_string($conn, trim($_POST['strength'] ?? ''));
    $shelf_life_days    = intval($_POST['shelf_life_days'] ?? 0);
    
    // Tab Calculation
    $ket_las            = mysqli_real_escape_string($conn, trim($_POST['ket_las'] ?? ''));
    $re_order_point     = floatval($_POST['re_order_point'] ?? 0);
    $is_sub             = isset($_POST['is_sub']) ? 'Checked' : 'Unchecked';
    $is_job_order       = isset($_POST['is_job_order']) ? 'Checked' : 'Unchecked';
    $dont_show_at_w48   = isset($_POST['dont_show_at_w48']) ? 'Checked' : 'Unchecked';
    $stokan             = isset($_POST['stokan']) ? 'Checked' : 'Unchecked';
    
    // Tab Others
    $internal_name      = mysqli_real_escape_string($conn, trim($_POST['internal_name'] ?? ''));
    $catalog            = mysqli_real_escape_string($conn, trim($_POST['catalog'] ?? ''));
    $part_no            = mysqli_real_escape_string($conn, trim($_POST['part_no'] ?? ''));
    $calculation        = mysqli_real_escape_string($conn, trim($_POST['calculation'] ?? ''));
    $printing_type      = mysqli_real_escape_string($conn, trim($_POST['printing_type'] ?? ''));
    $status             = mysqli_real_escape_string($conn, trim($_POST['status'] ?? ''));
    $origin             = mysqli_real_escape_string($conn, trim($_POST['origin'] ?? ''));
    $supp_code          = mysqli_real_escape_string($conn, trim($_POST['supp_code'] ?? ''));
    $description        = mysqli_real_escape_string($conn, trim($_POST['description'] ?? ''));
    $nama_customer      = mysqli_real_escape_string($conn, trim($_POST['nama_customer'] ?? ''));
    $type_rm            = mysqli_real_escape_string($conn, trim($_POST['type_rm'] ?? ''));
    $minimum_stock      = floatval($_POST['minimum_stock'] ?? 0);
    $maximum_stock      = floatval($_POST['maximum_stock'] ?? 0);
    
    $user_now           = $_SESSION['username'];
    $datetime_now       = date('Y-m-d H:i:s');

 if ($action_form == 'insert') {
    if (empty($inventory_id) || $inventory_id == 'Auto') {
        $inventory_id = generateInventoryId($conn, $inventory_name, $type);
    } else {
        // Tambahkan prefix CP- jika belum ada
        if (strpos($inventory_id, 'CP-') !== 0) {
            $inventory_id = 'CP-' . $inventory_id;
        }
    }
    
    // PERBAIKAN: Cek dengan locking untuk mencegah race condition
    mysqli_begin_transaction($conn);
    
    $cek = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id='$inventory_id' FOR UPDATE");
    if (mysqli_num_rows($cek) > 0) {
        // Jika ID sudah ada, generate ulang dengan loop sampai dapat ID unik
        $counter = 0;
        $max_attempts = 10;
        do {
            // Generate ID baru
            $inventory_id = generateInventoryId($conn, $inventory_name, $type);
            if ($counter > 0) {
                // Jika attempt ke-2 dst, tambahkan suffix random
                $inventory_id = $inventory_id . '-' . rand(100, 999);
            }
            $cek2 = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id='$inventory_id'");
            $counter++;
        } while (mysqli_num_rows($cek2) > 0 && $counter < $max_attempts);
        
        if (mysqli_num_rows($cek2) > 0) {
            mysqli_rollback($conn);
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Gagal Simpan: Tidak dapat menghasilkan ID unik!</div>";
            echo "<script>window.location.href='index.php?page=inventory';</script>";
            exit;
        }
    }
    
    // Kode INSERT (diletakkan di sini, setelah validasi, BUKAN di dalam ELSE)
    $conversion_rate = floatval($_POST['conversion_rate'] ?? 1);
    $base_uom = mysqli_real_escape_string($conn, trim($_POST['base_uom'] ?? 'KG'));
    $pack_uom = mysqli_real_escape_string($conn, trim($_POST['pack_uom'] ?? 'PCS'));
    
    $sql_insert = "INSERT INTO m_inventory (
        inventory_id, inventory_name, uom, type, category, remarks, 
        cap, colour, quality, volume_default, uom_pack, conversion_rate, base_uom, pack_uom,
        tolerance, upper_tolerance, lower_tolerance, merk, p, l, t, p2, tebal, ukuran, 
        density, strength, shelf_life_days, ket_las, re_order_point, is_sub, is_job_order, 
        dont_show_at_w48, stokan, internal_name, catalog, part_no, calculation, printing_type, 
        status, origin, supp_code, description, nama_customer, type_rm, minimum_stock, maximum_stock, 
        create_user, date_created, user_modified, date_modified
    ) VALUES (
        '$inventory_id', '$inventory_name', '$uom', '$type', '$category', '$remarks',
        '$cap', '$colour', '$quality', '$volume_default', '$uom_pack', '$conversion_rate', '$base_uom', '$pack_uom',
        '$tolerance', '$upper_tolerance', '$lower_tolerance', '$merk', '$p', '$l', '$t', '$p2', '$tebal', '$ukuran', 
        '$density', '$strength', '$shelf_life_days', '$ket_las', '$re_order_point', '$is_sub', '$is_job_order', 
        '$dont_show_at_w48', '$stokan', '$internal_name', '$catalog', '$part_no', '$calculation', '$printing_type', 
        '$status', '$origin', '$supp_code', '$description', '$nama_customer', '$type_rm', '$minimum_stock', '$maximum_stock',
        '$user_now', '$datetime_now', '$user_now', '$datetime_now'
    )";
    
    if (mysqli_query($conn, $sql_insert)) {
        mysqli_commit($conn);
        $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Inventory Berhasil Disimpan!</div>";
    } else {
        mysqli_rollback($conn);
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
    }
    
    // Redirect setelah POST (PRG Pattern)
    echo "<script>window.location.href='index.php?page=inventory';</script>";
    exit;
    
} elseif ($action_form == 'update') {
    // Kode UPDATE tetap sama seperti sebelumnya
    $sql_update = "UPDATE m_inventory SET 
        inventory_name='$inventory_name', uom='$uom', type='$type', category='$category', remarks='$remarks',
        cap='$cap', colour='$colour', quality='$quality', volume_default='$volume_default', uom_pack='$uom_pack',
        tolerance='$tolerance', upper_tolerance='$upper_tolerance', lower_tolerance='$lower_tolerance',
        merk='$merk', p='$p', l='$l', t='$t', p2='$p2', tebal='$tebal', ukuran='$ukuran', 
        density='$density', strength='$strength', shelf_life_days='$shelf_life_days',
        ket_las='$ket_las', re_order_point='$re_order_point',
        is_sub='$is_sub', is_job_order='$is_job_order', dont_show_at_w48='$dont_show_at_w48', stokan='$stokan',
        internal_name='$internal_name', catalog='$catalog', part_no='$part_no', calculation='$calculation', 
        printing_type='$printing_type', status='$status', origin='$origin', supp_code='$supp_code',
        description='$description', nama_customer='$nama_customer', type_rm='$type_rm',
        minimum_stock='$minimum_stock', maximum_stock='$maximum_stock',
        user_modified='$user_now', date_modified='$datetime_now' 
    WHERE inventory_id='$inventory_id'";
    
    if (mysqli_query($conn, $sql_update)) {
        $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Perubahan Data Inventory Berhasil Disimpan!</div>";
    } else {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
    }
    
    echo "<script>window.location.href='index.php?page=inventory';</script>";
    exit;
}
}

// Filter Pencarian
$search_keyword = "";
$where_clause = "";
if (isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE inventory_id LIKE '%$search_keyword%' OR inventory_name LIKE '%$search_keyword%' OR category LIKE '%$search_keyword%' OR nama_customer LIKE '%$search_keyword%'";
    }
}
?>
<?php
// Fungsi untuk auto generate Inventory ID berdasarkan type dan nama inventory
function generateInventoryId($conn, $inventory_name, $type) {
    $inventory_name = strtoupper($inventory_name);
    $year = date('Y');
    
    // Jika type adalah FINISH GOOD (FG)
    if ($type == 'Finish Good (FG)') {
        // Mapping kata kunci untuk FG
        $rules = [
            'PE POTONG'     => 'CP-FG/PE-2-',
            'PE ROLL'       => 'CP-FG/PE-1-',
            'HD ROLL'       => 'CP-FG/HD-1-',
            'HD POTONG'     => 'CP-FG/HD-2-',
            'HD POTONG WARNA' => 'CP-FG/HD-4-',
            'HD ROLL WARNA' => 'CP-FG/HD-5-',
            'PP ROLL'       => 'CP-FG/PP-1-',
            'PP POTONG'     => 'CP-FG/PP-2-',
        ];
        
        $prefix = 'CP-FG/'; // Default prefix untuk FG
        
        foreach ($rules as $keyword => $pref) {
            if (strpos($inventory_name, $keyword) !== false) {
                $prefix = $pref;
                break;
            }
        }
        
        // Jika prefix masih default FG/, cari kata kunci lainnya
        if ($prefix == 'CP-FG/') {
            if (strpos($inventory_name, 'ROLL') !== false) {
                $prefix = 'CP-FG/ROLL-';
            } elseif (strpos($inventory_name, 'POTONG') !== false) {
                $prefix = 'CP-FG/POTONG-';
            } else {
                $prefix = 'CP-FG/PCS-';
            }
        }
        
        // PERBAIKAN: Cari nomor urut terakhir dengan validasi lebih baik
        $query = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id LIKE '{$prefix}%' ORDER BY CAST(SUBSTRING(inventory_id, LENGTH('{$prefix}') + 1) AS UNSIGNED) DESC LIMIT 1");
        
        // Cek jika query error
        if (!$query) {
            error_log("MySQL Error: " . mysqli_error($conn));
            return $prefix . str_pad(1, 6, '0', STR_PAD_LEFT);
        }
        
        $row = mysqli_fetch_assoc($query);
        
        if ($row && isset($row['inventory_id'])) {
            // Ekstrak nomor urut dengan lebih aman
            $last_num_str = substr($row['inventory_id'], strlen($prefix));
            $last_num = intval($last_num_str);
            $next_num = $last_num + 1;
        } else {
            $next_num = 1;
        }
        
        return $prefix . str_pad($next_num, 6, '0', STR_PAD_LEFT);
    }
    
    // Untuk type selain Finish Good...
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
    
    // PERBAIKAN: Query dengan format yang benar
    $pattern = $prefix . "/" . $year . "-";
    $query = mysqli_query($conn, "SELECT inventory_id FROM m_inventory WHERE inventory_id LIKE '{$pattern}%' ORDER BY CAST(SUBSTRING(inventory_id, LENGTH('{$pattern}') + 1) AS UNSIGNED) DESC LIMIT 1");
    
    if (!$query) {
        error_log("MySQL Error: " . mysqli_error($conn));
        return $pattern . str_pad(1, 6, '0', STR_PAD_LEFT);
    }
    
    $row = mysqli_fetch_assoc($query);
    
    if ($row && isset($row['inventory_id'])) {
        $last_num_str = substr($row['inventory_id'], strlen($pattern));
        $last_num = intval($last_num_str);
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    
    return $pattern . str_pad($next_num, 6, '0', STR_PAD_LEFT);
}
?>

<style>
    /* Style untuk Tab - WAJIB ADA */
    .spec-tabs {
        display: flex;
        border-bottom: 2px solid #dee2e6;
        margin-bottom: 20px;
        background: white;
        border-radius: 5px 5px 0 0;
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
    .spec-content.active {
        display: block;
    }
    .spec-table {
        width: 100%;
        font-size: 12px;
        border-collapse: collapse;
    }
    .spec-table tr {
        border-bottom: 1px solid #eef2f7;
    }
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
    .spec-table td:last-child {
        background: white;
    }
    .spec-table input, .spec-table select, .spec-table textarea {
        border: 1px solid #ced4da;
        border-radius: 4px;
        padding: 6px 10px;
        font-size: 12px;
        width: 100%;
    }
    .spec-table input:focus, .spec-table select:focus, .spec-table textarea:focus {
        border-color: #0d6efd;
        outline: none;
        box-shadow: 0 0 0 2px rgba(13,110,253,0.25);
    }
    .spec-table textarea {
        resize: vertical;
    }
    
    /* Style untuk Checkbox di tab */
    .spec-table input[type="checkbox"] {
        width: auto;
        margin-right: 8px;
        transform: scale(1.1);
    }
    
    /* Modal body agar scroll smooth */
    .modal-body {
        max-height: 70vh;
        overflow-y: auto;
    }
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
    
    .table-inventory {
        font-size: 10px;
        border-collapse: collapse;
        width: 100%;
        white-space: nowrap;
    }
    .table-inventory th {
        background: #e9ecef;
        padding: 5px 3px;
        border: 1px solid #dee2e6;
        text-align: center;
        font-weight: bold;
        position: sticky;
        top: 0;
    }
    .table-inventory td {
        padding: 3px;
        border: 1px solid #dee2e6;
    }
    .btn-micro {
        padding: 2px 5px;
        font-size: 9px;
    }
    .sticky-col-aksi { position: sticky; left: 0; background: white; z-index: 2; }
    .sticky-col-id { position: sticky; left: 45px; background: white; z-index: 2; }
    .sticky-col-name { position: sticky; left: 130px; background: white; z-index: 2; }
</style>


<!-- HEADER -->
<div class="d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
        <h5 class="fw-bold text-dark m-0"><i class="fa fa-boxes text-info"></i> Master Data Inventory</h5>
        <div class="d-flex gap-2">
            <button class="btn-vs btn-excel" onclick="window.location.href='modul/master/export_inventory.php'">
                <i class="fa fa-file-excel-o"></i> Export to Excel
            </button>
            <button class="btn-vs btn-add" onclick="showModalTambah()">
                <i class="fa fa-plus-circle"></i> Tambah Item
            </button>
        </div>
    </div>
   <div class="container-fluid">
    <?= $alert_message; ?>
    <!-- sisa konten -->
</div>
</div>

<!-- FORM PENCARIAN -->
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="POST" action="index.php?page=inventory" class="row g-2 align-items-center">
            <div class="col-md-5">
                <input type="text" name="search_keyword" class="form-control form-control-sm" 
                       placeholder="Cari ID, Nama Barang, Kategori, Customer..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari Data</button>
                <a href="index.php?page=inventory" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- TABEL SEMUA KOLOM (46 KOLOM) -->
<div class="table-responsive" style="max-height: 550px; overflow-y: auto; border: 1px solid #dee2e6;">
    <table class="table-inventory table-hover">
        <thead>
            <tr>
                <th class="sticky-col-aksi" style="min-width: 55px;">Aksi</th>
                <th class="sticky-col-id" style="min-width: 100px;">Inventory ID</th>
                <th class="sticky-col-name" style="min-width: 180px;">Inventory Name</th>
                <th style="min-width: 50px;">UoM</th>
                <th style="min-width: 80px;">Type</th>
                <th style="min-width: 100px;">Category</th>
                <th style="min-width: 100px;">Remarks</th>
                <th style="min-width: 60px;">Cap</th>
                <th style="min-width: 60px;">Colour</th>
                <th style="min-width: 80px;">Quality</th>
                <th style="min-width: 80px;">Volume Default</th>
                <th style="min-width: 60px;">UoM Pack</th>
                <th style="min-width: 60px;">Tolerance</th>
                <th style="min-width: 80px;">Upper Tol</th>
                <th style="min-width: 80px;">Lower Tol</th>
                <th style="min-width: 80px;">Merk</th>
                <th style="min-width: 50px;">P</th>
                <th style="min-width: 50px;">L</th>
                <th style="min-width: 50px;">T</th>
                <th style="min-width: 50px;">P2</th>
                <th style="min-width: 70px;">Density</th>
                <th style="min-width: 100px;">Description</th>
                <th style="min-width: 80px;">Origin</th>
                <th style="min-width: 60px;">Status</th>
                <th style="min-width: 80px;">Supp Code</th>
                <th style="min-width: 80px;">Re Order Pt</th>
                <th style="min-width: 80px;">Min Stock</th>
                <th style="min-width: 80px;">Max Stock</th>
                <th style="min-width: 80px;">Shelf Life</th>
                <th style="min-width: 50px;">Is Sub</th>
                <th style="min-width: 60px;">Is JO</th>
                <th style="min-width: 80px;">Hide W48</th>
                <th style="min-width: 60px;">Stokan</th>
                <th style="min-width: 100px;">Internal Name</th>
                <th style="min-width: 80px;">Catalog</th>
                <th style="min-width: 80px;">Part No</th>
                <th style="min-width: 80px;">Printing Type</th>
                <th style="min-width: 80px;">Calculation</th>
                <th style="min-width: 120px;">Nama Customer</th>
                <th style="min-width: 60px;">Type RM</th>
                <th style="min-width: 70px;">Tebal</th>
                <th style="min-width: 80px;">Ukuran</th>
                <th style="min-width: 70px;">Strength</th>
                <th style="min-width: 100px;">Create User</th>
                <th style="min-width: 120px;">Date Created</th>
                <th style="min-width: 100px;">User Modified</th>
                <th style="min-width: 120px;">Date Modified</th>
                <th style="min-width: 100px;">Ket Las</th>
            </tr>
        </thead>
        <tbody>
           <?php
                // Di bagian query, lakukan JOIN untuk mendapatkan nama category
                $query_list = mysqli_query($conn, "
                    SELECT i.*, c.name as category_name 
                    FROM m_inventory i
                    LEFT JOIN m_category c ON i.category = c.categori_id
                    $where_clause 
                    ORDER BY i.inventory_id ASC
                ");
                if (!$query_list) {
                echo "<tr><td colspan='48' class='text-danger fw-bold p-3'>Error SQL: ".mysqli_error($conn)."</td></tr>";
            } else if (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='48' class='text-center text-muted py-3'>Tidak ada data inventory ditemukan.</td></tr>";
            } else {
                while ($d = mysqli_fetch_assoc($query_list)) {
            ?>

                
            <tr>
                <td class="sticky-col-aksi text-center">
                    <button class="btn btn-micro btn-warning text-dark" onclick='showModalEdit(<?= json_encode($d); ?>)'><i class="fa fa-edit"></i></button>
                    <a href="index.php?page=inventory&action=delete&id=<?= urlencode($d['inventory_id']) ?>" 
                       class="btn btn-micro btn-danger" onclick="return confirm('Hapus item <?= addslashes($d['inventory_name']) ?>?')"><i class="fa fa-trash"></i></a>
                </td>
                <td class="sticky-col-id fw-bold text-secondary"><?= htmlspecialchars($d['inventory_id']) ?></td>
                <td class="sticky-col-name fw-bold text-dark"><?= htmlspecialchars($d['inventory_name']) ?></td>
                <td><?= htmlspecialchars($d['uom']) ?></td>
                <td><?= htmlspecialchars($d['type']) ?></td>
               <td class="text-center"><?= htmlspecialchars($d['category_name'] ?? $d['category']) ?></td>
                <!--<td><?= htmlspecialchars($d['category']) ?></td>-->
                <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($d['remarks']) ?></td>
                <td><?= htmlspecialchars($d['cap']) ?></td>
                <td><?= htmlspecialchars($d['colour']) ?></td>
                <td><?= htmlspecialchars($d['quality']) ?></td>
                <td class="text-end"><?= number_format($d['volume_default'], 4) ?></td>
                <td><?= htmlspecialchars($d['uom_pack']) ?></td>
                <td class="text-end"><?= $d['tolerance'] ?>%</td>
                <td class="text-end"><?= number_format($d['upper_tolerance'], 2) ?></td>
                <td class="text-end"><?= number_format($d['lower_tolerance'], 2) ?></td>
                <td><?= htmlspecialchars($d['merk']) ?></td>
                <td class="text-end"><?= number_format($d['p'], 2) ?></td>
                <td class="text-end"><?= number_format($d['l'], 2) ?></td>
                <td class="text-end"><?= number_format($d['t'], 2) ?></td>
                <td class="text-end"><?= number_format($d['p2'], 2) ?></td>
                <td class="text-end"><?= number_format($d['density'], 4) ?></td>
                <td style="max-width: 120px; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($d['description']) ?></td>
                <td><?= htmlspecialchars($d['origin']) ?></td>
                <td class="text-center">
                    <span class="badge bg-<?= $d['status']=='Active' ? 'success' : 'danger' ?>" style="font-size:8px;">
                        <?= htmlspecialchars($d['status']) ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($d['supp_code']) ?></td>
                <td class="text-end"><?= number_format($d['re_order_point'], 2) ?></td>
                <td class="text-end"><?= number_format($d['minimum_stock'], 2) ?></td>
                <td class="text-end"><?= number_format($d['maximum_stock'], 2) ?></td>
                <td class="text-end"><?= $d['shelf_life_days'] ?></td>
                <td class="text-center"><?= $d['is_sub'] == 'Checked' ? '✓' : '' ?></td>
                <td class="text-center"><?= $d['is_job_order'] == 'Checked' ? '✓' : '' ?></td>
                <td class="text-center"><?= $d['dont_show_at_w48'] == 'Checked' ? '✓' : '' ?></td>
                <td class="text-center"><?= $d['stokan'] == 'Checked' ? '✓' : '' ?></td>
                <td><?= htmlspecialchars($d['internal_name']) ?></td>
                <td><?= htmlspecialchars($d['catalog']) ?></td>
                <td><?= htmlspecialchars($d['part_no']) ?></td>
                <td><?= htmlspecialchars($d['printing_type']) ?></td>
                <td><?= htmlspecialchars($d['calculation']) ?></td>
                <td><?= htmlspecialchars($d['nama_customer']) ?></td>
                <td><?= htmlspecialchars($d['type_rm']) ?></td>
                <td class="text-end"><?= number_format($d['tebal'], 4) ?></td>
                <td><?= htmlspecialchars($d['ukuran']) ?></td>
                <td><?= htmlspecialchars($d['strength']) ?></td>
                <td><?= htmlspecialchars($d['create_user']) ?></td>
                <td class="text-center small"><?= date('d-m-Y H:i', strtotime($d['date_created'])) ?></td>
                <td><?= htmlspecialchars($d['user_modified']) ?></td>
                <td class="text-center small"><?= $d['date_modified'] ? date('d-m-Y H:i', strtotime($d['date_modified'])) : '-' ?></td>
                <td style="max-width: 100px; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($d['ket_las']) ?></td>
            </tr>
            <?php } } ?>
        </tbody>
    </table>
</div>

<!-- MODAL FORM (sama seperti sebelumnya, tidak diubah) -->
<!-- MODAL FORM DENGAN TABS YANG RAPI -->
<div class="modal fade d-print-none" id="modalInventory" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header bg-dark text-white py-2" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);">
                <h6 class="modal-title fw-bold" id="modalTitle">
                    <i class="fa fa-boxes"></i> Form Master Inventory
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formInventory" method="POST" action="index.php?page=inventory">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <div class="modal-body p-4" style="background: #f8fafc;">
                    
                    <!-- PANEL DATA UTAMA - Card Style -->
                    <div class="card shadow-sm mb-4 border-0">
                        <div class="card-header bg-white py-2 px-3" style="border-left: 4px solid #0d6efd;">
                            <b><i class="fa fa-database me-2"></i>A. DATA UTAMA</b>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Inventory ID <span class="text-danger">*</span></label>
                                    <input type="text" name="inventory_id" id="form_inventory_id" class="form-control form-control-sm" 
                                        placeholder="Auto" style="background:#e9ecef;">
                                    <small class="text-muted">Akan terisi otomatis</small>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label fw-bold small">Inventory Name <span class="text-danger">*</span></label>
                                    <input type="text" name="inventory_name" id="form_inventory_name" class="form-control form-control-sm" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold small">UOM</label>
                                    <select name="uom" id="form_uom" class="form-select form-select-sm">
                                        <option value="">-- Pilih UOM --</option>
                                        <?php 
                                        $uom_list = mysqli_query($conn, "SELECT unit FROM m_uom WHERE is_active='Checked' ORDER BY unit ASC");
                                        while($u = mysqli_fetch_assoc($uom_list)): 
                                        ?>
                                            <option value="<?= htmlspecialchars($u['unit']) ?>"><?= htmlspecialchars($u['unit']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold small">Type</label>
                                    <select name="type" id="form_type" class="form-select form-select-sm" required>
                                        <option value="">-- Pilih Type --</option>
                                        <option value="AKTIVA MESIN (AKT2)">AKTIVA MESIN (AKT2)</option>
                                        <option value="AKTIVA INVENTARIS (AKT)">AKTIVA INVENTARIS (AKT)</option>
                                        <option value="ALAT PABRIK (ALTP)">ALAT PABRIK (ALTP)</option>
                                        <option value="BIAYA (AC)">BIAYA (AC)</option>
                                        <option value="Biaya Makloon (BM)">Biaya Makloon (BM)</option>
                                        <option value="Finish Good (FG)">Finish Good (FG)</option>
                                        <option value="Jasa (JS)">Jasa (JS)</option>
                                        <option value="Raw Material (RAW)">Raw Material (RAW)</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Category</label>
                                    <select name="category" id="form_category" class="form-select form-select-sm">
                                        <option value="">-- Pilih Category --</option>
                                        <?php 
                                        $category_list = mysqli_query($conn, "SELECT categori_id, name FROM m_category ORDER BY name ASC");
                                        while($cat = mysqli_fetch_assoc($category_list)): 
                                        ?>
                                            <option value="<?= htmlspecialchars($cat['categori_id']) ?>">
                                                <?= htmlspecialchars($cat['name']) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small">Remarks</label>
                                    <input type="text" name="remarks" id="form_remarks" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Minimum Stock</label>
                                    <input type="number" step="0.01" name="minimum_stock" id="form_minimum_stock" class="form-control form-control-sm" value="0.00">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Maximum Stock</label>
                                    <input type="number" step="0.01" name="maximum_stock" id="form_maximum_stock" class="form-control form-control-sm" value="0.00">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold small">Status</label>
                                    <select name="status" id="form_status" class="form-select form-select-sm">
                                        <option value="Active">Active</option>
                                        <option value="Inactive">Inactive</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-flex align-items-end">
                                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-4 me-2"><i class="fa fa-save"></i> Simpan</button>
                                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal"><i class="fa fa-times"></i> Batal</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- TABS NAVIGATION -->
                    <div class="spec-tabs">
                        <div class="spec-tab active" data-tab="specification">
                            <i class="fa fa-tag me-1"></i> Specification
                        </div>
                        <div class="spec-tab" data-tab="detail">
                            <i class="fa fa-ruler me-1"></i> Detail Specification
                        </div>
                        <div class="spec-tab" data-tab="calculation">
                            <i class="fa fa-calculator me-1"></i> Calculation
                        </div>
                        <div class="spec-tab" data-tab="others">
                            <i class="fa fa-cog me-1"></i> Others
                        </div>
                    </div>
                    
                    <!-- TAB 1: Specification -->
                    <div id="specification" class="spec-content active">
                        <table class="spec-table">
                            <tr><td style="width: 180px;"><b>Cap</b></td><td><input type="text" name="cap" id="form_cap" placeholder="Contoh: 25kg"></td></tr>
                            <tr><td><b>Colour</b></td><td><input type="text" name="colour" id="form_colour" placeholder="Warna"></td></tr>
                            <tr><td><b>Quality</b></td>
                                <td>
                                    <select name="quality" id="form_quality" class="form-select">
                                        <option value="">-- Pilih Quality --</option>
                                        <option value="BIJI PLASTIK">BIJI PLASTIK</option>
                                        <option value="BRONGSONG PISANG">BRONGSONG PISANG</option>
                                        <option value="BUNGKUS">BUNGKUS</option>
                                        <option value="GEOMEMBRANE">GEOMEMBRANE</option>
                                        <option value="HD KRESEK">HD KRESEK</option>
                                        <option value="HD POTONG">HD POTONG</option>
                                        <option value="HD ROLL">HD ROLL</option>
                                        <option value="KAOS">KAOS</option>
                                        <option value="KOLAM">KOLAM</option>
                                        <option value="LEMPER">LEMPER</option>
                                        <option value="MULSA">MULSA</option>
                                        <option value="MULSA TAMBAK">MULSA TAMBAK</option>
                                        <option value="OBAT WARNA">OBAT WARNA</option>
                                        <option value="PAGER SAWAH">PAGER SAWAH</option>
                                        <option value="PE KRESEK">PE KRESEK</option>
                                        <option value="PE POTONG">PE POTONG</option>
                                        <option value="PE ROLL">PE ROLL</option>
                                        <option value="PLASTIK LOUNDRY">PLASTIK LOUNDRY</option>
                                        <option value="PLASTIK SAMPAH">PLASTIK SAMPAH</option>
                                        <option value="PLASTIK SAYUR">PLASTIK SAYUR</option>
                                        <option value="PLASTIK SEMANGKA">PLASTIK SEMANGKA</option>
                                        <option value="POLYBAG">POLYBAG</option>
                                        <option value="PORPORATED">PORPORATED</option>
                                        <option value="PP ROLL">PP ROLL</option>
                                        <option value="PP POTONG">PP POTONG</option>
                                        <option value="PP ROLL BOLA">PP ROLL BOLA</option>
                                        <option value="SABLON HD POTONG">SABLON HD POTONG</option>
                                        <option value="SABLON HD ROLL">SABLON HD ROLL</option>
                                        <option value="SABLON PE POTONG">SABLON PE POTONG</option>
                                        <option value="SABLON PE POTONG WARNA">SABLON PE POTONG WARNA</option>
                                        <option value="SABLON PE ROLL">SABLON PE ROLL</option>
                                        <option value="SABLON PE ROLL WARNA">SABLON PE ROLL WARNA</option>
                                        <option value="SABLON PP POTONG">SABLON PP POTONG</option>
                                        <option value="SABLON PP ROLL">SABLON PP ROLL</option>
                                        <option value="SEDOTAN">SEDOTAN</option>
                                        <option value="SELANG">SELANG</option>
                                        <option value="SLONTONG">SLONTONG</option>
                                        <option value="TALI GAWAR">TALI GAWAR</option>
                                        <option value="TALI LOS">TALI LOS</option>
                                        <option value="TERPAL">TERPAL</option>
                                        <option value="UV1">UV1</option>
                                        <option value="UV2">UV2</option>
                                    </select>
                                </td>
                            </tr>
                            <tr><td><b>Volume Default</b></td><td><input type="number" step="0.0001" name="volume_default" id="form_volume_default" value="1.0000"></td></tr>
                            <tr><td><b>UOM Pack</b></td>
                                <td>
                                    <select name="uom_pack" id="form_uom_pack" class="form-select">
                                    <option value="">-- Pilih UOM Pack--</option>
                                    <?php 
                                    mysqli_data_seek($uom_list, 0);
                                    while($u = mysqli_fetch_assoc($uom_list)): 
                                    ?>
                                        <option value="<?= htmlspecialchars($u['unit']) ?>"><?= htmlspecialchars($u['unit']) ?></option>
                                    <?php endwhile; ?>
                                </select>
                                </td>
                            </tr>
                            <tr>
    <td><b>Conversion Rate</b></td>
    <td>
        <input type="number" step="0.0001" name="conversion_rate" id="form_conversion_rate" value="1.0000">
        <small class="text-muted">Contoh: 1 LBR = 0.09 KG → isi 0.09</small>
    </td>
                            </tr>
                            <tr>
                                <td><b>Base UOM</b></td>
                                <td>
                                    <select name="base_uom" id="form_base_uom" class="form-select">
                                        <option value="">-- Pilih Base UOM --</option>
                                        <?php 
                                        mysqli_data_seek($uom_list, 0);
                                        while($u = mysqli_fetch_assoc($uom_list)): 
                                        ?>
                                            <option value="<?= htmlspecialchars($u['unit']) ?>"><?= htmlspecialchars($u['unit']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                    <small class="text-muted">Satuan dasar (biasanya KG)</small>
                                </td>
                            </tr>
                            <tr>
                                <td><b>Pack UOM</b></td>
                                <td>
                                    <select name="pack_uom" id="form_pack_uom" class="form-select">
                                        <option value="">-- Pilih Pack UOM --</option>
                                        <?php 
                                        mysqli_data_seek($uom_list, 0);
                                        while($u = mysqli_fetch_assoc($uom_list)): 
                                        ?>
                                            <option value="<?= htmlspecialchars($u['unit']) ?>"><?= htmlspecialchars($u['unit']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                    <small class="text-muted">Satuan kemasan (LBR, BAL, PCS)</small>
                                </td>
                            </tr>
                            <tr><td><b>Tolerance (%)</b></td><td><input type="number" step="0.01" name="tolerance" id="form_tolerance" value="0"></td></tr>
                            <tr><td><b>Upper Tolerance</b></td><td><input type="number" step="0.01" name="upper_tolerance" id="form_upper_tolerance" value="0.00"></td></tr>
                            <tr><td><b>Lower Tolerance</b></td><td><input type="number" step="0.01" name="lower_tolerance" id="form_lower_tolerance" value="0.00"></td></tr>
                        </table>
                    </div>
                    
                    <!-- TAB 2: Detail Specification -->
                    <div id="detail" class="spec-content">
                        <table class="spec-table">
                            <tr><td><b>Merk</b></td><td><input type="text" name="merk" id="form_merk" placeholder="Merek produk"></td></tr>
                            <tr><td><b>P (Panjang)</b></td><td><input type="number" step="0.01" name="p" id="form_p" value="0.00"></td></tr>
                            <tr><td><b>L (Lebar)</b></td><td><input type="number" step="0.01" name="l" id="form_l" value="0.00"></td></tr>
                            <tr><td><b>T (Tebal)</b></td><td><input type="number" step="0.01" name="t" id="form_t" value="0.00"></td></tr>
                            <tr><td><b>P2</b></td><td><input type="number" step="0.01" name="p2" id="form_p2" value="0.00"></td></tr>
                            <tr><td><b>Tebal</b></td><td><input type="number" step="0.0001" name="tebal" id="form_tebal" value="0.0000"></td></tr>
                            <tr><td><b>Ukuran</b></td><td><input type="text" name="ukuran" id="form_ukuran" placeholder="Ukuran produk"></td></tr>
                            <tr><td><b>Density</b></td><td><input type="number" step="0.0001" name="density" id="form_density" value="0.0000"></td></tr>
                            <tr><td><b>Strength</b></td><td><input type="text" name="strength" id="form_strength" placeholder="Kekuatan"></td></tr>
                            <tr><td><b>Shelf Life (Days)</b></td><td><input type="number" name="shelf_life_days" id="form_shelf_life_days" value="0"></td></tr>
                        </table>
                    </div>
                    
                    <!-- TAB 3: Calculation -->
                    <div id="calculation" class="spec-content">
                        <table class="spec-table">
                            <tr><td><b>Ket Las</b></td><td><textarea name="ket_las" id="form_ket_las" rows="2" placeholder="Keterangan las..."></textarea></td></tr>
                            <tr><td><b>Re Order Point</b></td><td><input type="number" step="0.01" name="re_order_point" id="form_re_order_point" value="0.00"></td></tr>
                            <tr><td><b>Is Sub</b></td><td><input type="checkbox" name="is_sub" id="form_is_sub" value="Checked"> True / No</td></tr>
                            <tr><td><b>Is Job Order</b></td><td><input type="checkbox" name="is_job_order" id="form_is_job_order" value="Checked"> True / No</td></tr>
                            <tr><td><b>Dont Show W48</b></td><td><input type="checkbox" name="dont_show_at_w48" id="form_dont_show_at_w48" value="Checked"> True / No</td></tr>
                            <tr><td><b>Stokan</b></td><td><input type="checkbox" name="stokan" id="form_stokan" value="Checked"> True / No</td></tr>
                        </table>
                    </div>
                    
                    <!-- TAB 4: Others -->
                    <div id="others" class="spec-content">
                        <table class="spec-table">
                            <tr><td><b>Internal Name</b></td><td><input type="text" name="internal_name" id="form_internal_name"></td></tr>
                            <tr><td><b>Catalog</b></td><td><input type="text" name="catalog" id="form_catalog"></td></tr>
                            <tr><td><b>Part No</b></td><td><input type="text" name="part_no" id="form_part_no"></td></tr>
                            <tr><td><b>Calculation</b></td><td><input type="text" name="calculation" id="form_calculation"></td></tr>
                            <tr><td><b>Printing Type</b></td><td><input type="text" name="printing_type" id="form_printing_type"></td></tr>
                            <tr><td><b>Origin</b></td>
                                <td>
                                    <select name="origin" id="form_origin" class="form-select">
                                        <option value="">-- Pilih Origin --</option>
                                        <option value="Gudang Bahan Baku">Gudang Bahan Baku</option>
                                        <option value="Gudang Bahan Jadi">Gudang Bahan Jadi</option>
                                    </select>
                                </td>
                            </tr>
                            <tr><td><b>Supp Code</b></td><td><input type="text" name="supp_code" id="form_supp_code"></td></tr>
                            <tr><td><b>Description</b></td><td><textarea name="description" id="form_description" rows="2"></textarea></td></tr>
                            <tr><td><b>Nama Customer</b></td><td><input type="text" name="nama_customer" id="form_nama_customer"></td></tr>
                            <tr><td><b>Type RM</b></td><td><input type="text" name="type_rm" id="form_type_rm"></td></tr>
                        </table>
                    </div>
                    
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var bootstrapModalInventory;

document.addEventListener("DOMContentLoaded", function() {
    const modalElement = document.getElementById('modalInventory');
    if (modalElement) bootstrapModalInventory = new bootstrap.Modal(modalElement);
    
    document.querySelectorAll('.spec-tab').forEach(tab => {
        tab.addEventListener('click', function() {
            const tabId = this.getAttribute('data-tab');
            document.querySelectorAll('.spec-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.spec-content').forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            document.getElementById(tabId).classList.add('active');
        });
    });
});

function showModalTambah() {
    document.getElementById('formInventory').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Tambah Item Inventory Baru';
    
    // Set field inventory_id editable untuk mode insert
    var invIdField = document.getElementById('form_inventory_id');
    invIdField.removeAttribute('readonly');
    invIdField.value = '';
    invIdField.style.background = '#ffffff';
    
    document.getElementById('form_status').value = 'Active';
    document.getElementById('form_volume_default').value = '1.0000';
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
    // Reset flag untuk auto-generate inventory ID
    inventoryIdManuallyEdited = false;
    bootstrapModalInventory.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formInventory').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Inventory: ' + (dataObj.inventory_name || '');
    
    const fields = ['inventory_id', 'inventory_name', 'uom', 'type', 'category', 'remarks', 'cap', 'colour', 
        'quality', 'volume_default', 'uom_pack', 'conversion_rate', 'base_uom', 'pack_uom',
        'tolerance', 'upper_tolerance', 'lower_tolerance', 'merk', 'p', 'l', 't', 'p2', 'tebal', 
        'ukuran', 'density', 'strength', 'shelf_life_days', 'ket_las', 're_order_point', 
        'internal_name', 'catalog', 'part_no', 'calculation', 'printing_type', 'status', 'origin', 
        'supp_code', 'description', 'nama_customer', 'type_rm', 'minimum_stock', 'maximum_stock'];
    
    fields.forEach(field => {
        const el = document.getElementById('form_' + field);
        if (el && dataObj[field] !== undefined && dataObj[field] !== null) {
            el.value = dataObj[field];
        }
    });
    
    // Untuk category select, set value berdasarkan categori_id
    if (dataObj.category) {
        document.getElementById('form_category').value = dataObj.category;
    }
    
    // Checkbox flags
    document.getElementById('form_is_sub').checked = (dataObj.is_sub === 'Checked');
    document.getElementById('form_is_job_order').checked = (dataObj.is_job_order === 'Checked');
    document.getElementById('form_dont_show_at_w48').checked = (dataObj.dont_show_at_w48 === 'Checked');
    document.getElementById('form_stokan').checked = (dataObj.stokan === 'Checked');
    
    // Set field inventory_id readonly untuk mode edit
    var invIdField = document.getElementById('form_inventory_id');
    invIdField.setAttribute('readonly', 'readonly');
    invIdField.style.background = '#e9ecef';
    
    bootstrapModalInventory.show();
}
// Flag untuk tracking apakah user sudah manual edit inventory ID
var inventoryIdManuallyEdited = false;

// Fungsi untuk auto generate ID berdasarkan type dan nama
function autoGenerateInventoryId() {
    var inventoryName = document.getElementById('form_inventory_name').value;
    var type = document.getElementById('form_type').value;
    var inventoryIdField = document.getElementById('form_inventory_id');
    
    // Skip auto-generate jika user sudah manual edit inventory ID
    if (inventoryIdManuallyEdited) {
        return;
    }
    
    if (inventoryName && type) {
        // Kirim request AJAX ke server untuk generate ID
        $.ajax({
            url: 'modul/master/generate_inventory_id.php',
            type: 'POST',
            data: {
                inventory_name: inventoryName,
                type: type
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    inventoryIdField.value = response.inventory_id;
                }
            },
            error: function() {
                console.log('Error generating inventory ID');
            }
        });
    }
}

// Event listener untuk perubahan nama inventory atau type
$(document).ready(function() {
    $('#form_inventory_name, #form_type').on('change keyup', function() {
        autoGenerateInventoryId();
    });
    
    // Event listener untuk detect manual edit pada inventory ID
    $('#form_inventory_id').on('change keyup', function() {
        var value = $(this).val().trim();
        // Mark sebagai manually edited jika user ketik sesuatu
        if (value) {
            inventoryIdManuallyEdited = true;
        }
    });
});
</script>