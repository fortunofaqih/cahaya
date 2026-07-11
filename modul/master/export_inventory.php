<?php
// file: modul/master/export_inventory.php
// Export Excel Native PHP dengan filter

// Mulai buffer dan bersihkan semua output sebelumnya
if (ob_get_level()) ob_end_clean();
ob_start();

session_start();

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

// Koneksi database
include __DIR__ . '/../../koneksi.php';

// ==========================================
// HELPER DATE - SAMA DENGAN INVENTORY.PHP
// ==========================================
function parseExportFilterDate($value, $fallback) {
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

// ==========================================
// FILTER - SAMA DENGAN INVENTORY.PHP
// Default: awal tahun sampai hari ini
// ==========================================
$today = date('Y-m-d');
$firstDayOfYear = date('Y') . '-01-01';
$search_keyword = isset($_GET['search']) ? trim($_GET['search']) : '';

// Ambil filter dari GET atau default ke awal tahun - hari ini
$start_date_raw = isset($_GET['start_date']) && trim($_GET['start_date']) !== ''
    ? trim($_GET['start_date'])
    : date('d-M-Y', strtotime($firstDayOfYear));

$end_date_raw = isset($_GET['end_date']) && trim($_GET['end_date']) !== ''
    ? trim($_GET['end_date'])
    : date('d-M-Y', strtotime($today));

$start_date = parseExportFilterDate($start_date_raw, $firstDayOfYear);
$end_date = parseExportFilterDate($end_date_raw, $today);

// Validasi silang tanggal
if (strtotime($start_date) > strtotime($end_date)) {
    $tmp_date = $start_date;
    $start_date = $end_date;
    $end_date = $tmp_date;
}

$start_date_sql = mysqli_real_escape_string($conn, $start_date);
$end_date_sql = mysqli_real_escape_string($conn, $end_date);
$search_keyword_sql = mysqli_real_escape_string($conn, $search_keyword);

// ==========================================
// BUILD WHERE CLAUSE - SAMA DENGAN INVENTORY.PHP
// ==========================================
$filter_conditions = [];
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

// ==========================================
// QUERY DATA DENGAN FILTER
// ==========================================
$sql = "SELECT 
            i.*,
            c.name as category_name 
        FROM m_inventory i 
        LEFT JOIN m_category c ON i.category = c.categori_id
        $where_clause
        ORDER BY i.date_created DESC, i.inventory_id DESC";

$query = mysqli_query($conn, $sql);

if (!$query) {
    die('Query Export Error: ' . mysqli_error($conn));
}

// Set header untuk download Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Master_Inventory_" . date('Ymd_His') . ".xls");
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");

// ==========================================
// OUTPUT EXCEL
// ==========================================
?>
<table border="1">
    <thead>
        <tr style="background-color: #4472C4; color: #ffffff; font-weight: bold;">
            <th>No</th>
            <th>Inventory ID</th>
            <th>Inventory Name</th>
            <th>Type</th>
            <th>Category</th>
            <th>UOM</th>
            <th>UOM Pack</th>
            <th>Status</th>
            <th>Date Created</th>
            <th>Create User</th>
            <th>Date Modified</th>
            <th>User Modified</th>
            <th>Remarks</th>
            <th>Cap</th>
            <th>Colour</th>
            <th>Quality</th>
            <th>Volume Default</th>
            <th>Tolerance</th>
            <th>Upper Tolerance</th>
            <th>Lower Tolerance</th>
            <th>Merk</th>
            <th>P</th>
            <th>L</th>
            <th>T</th>
            <th>P2</th>
            <th>Tebal</th>
            <th>Ukuran</th>
            <th>Density</th>
            <th>Strength</th>
            <th>Ket Las</th>
            <th>Re Order Point</th>
            <th>Minimum Stock</th>
            <th>Maximum Stock</th>
            <th>Dont Show W48</th>
            <th>Stokan</th>
            <th>Internal Name</th>
            <th>Catalog</th>
            <th>Part No</th>
            <th>Printing Type</th>
            <th>Calculation</th>
            <th>Origin</th>
            <th>Supp Code</th>
            <th>Description</th>
            <th>Nama Customer</th>
            <th>Type RM</th>
        </tr>
    </thead>
    <tbody>
        <?php 
        $no = 1;
        while ($row = mysqli_fetch_assoc($query)): 
        ?>
        <tr>
            <td><?= $no++ ?></td>
            <td><?= htmlspecialchars($row['inventory_id'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['inventory_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['type'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['category_name'] ?? $row['category'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['uom'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['uom_pack'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['status'] ?? '') ?></td>
            <td><?= !empty($row['date_created']) ? date('d-m-Y H:i', strtotime($row['date_created'])) : '' ?></td>
            <td><?= htmlspecialchars($row['create_user'] ?? '') ?></td>
            <td><?= !empty($row['date_modified']) ? date('d-m-Y H:i', strtotime($row['date_modified'])) : '' ?></td>
            <td><?= htmlspecialchars($row['user_modified'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['remarks'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['cap'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['colour'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['quality'] ?? '') ?></td>
            <td><?= number_format($row['volume_default'] ?? 0, 4, ',', '') ?></td>
            <td><?= number_format($row['tolerance'] ?? 0, 2, ',', '') ?></td>
            <td><?= number_format($row['upper_tolerance'] ?? 0, 2, ',', '') ?></td>
            <td><?= number_format($row['lower_tolerance'] ?? 0, 2, ',', '') ?></td>
            <td><?= htmlspecialchars($row['merk'] ?? '') ?></td>
            <td><?= number_format($row['p'] ?? 0, 3, ',', '') ?></td>
            <td><?= number_format($row['l'] ?? 0, 3, ',', '') ?></td>
            <td><?= number_format($row['t'] ?? 0, 3, ',', '') ?></td>
            <td><?= number_format($row['p2'] ?? 0, 3, ',', '') ?></td>
            <td><?= number_format($row['tebal'] ?? 0, 4, ',', '') ?></td>
            <td><?= htmlspecialchars($row['ukuran'] ?? '') ?></td>
            <td><?= number_format($row['density'] ?? 0, 4, ',', '') ?></td>
            <td><?= htmlspecialchars($row['strength'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['ket_las'] ?? '') ?></td>
            <td><?= number_format($row['re_order_point'] ?? 0, 2, ',', '') ?></td>
            <td><?= number_format($row['minimum_stock'] ?? 0, 2, ',', '') ?></td>
            <td><?= number_format($row['maximum_stock'] ?? 0, 2, ',', '') ?></td>
            <td><?= ($row['dont_show_at_w48'] ?? '') == 'Checked' ? 'Yes' : 'No' ?></td>
            <td><?= ($row['stokan'] ?? '') == 'Checked' ? 'Yes' : 'No' ?></td>
            <td><?= htmlspecialchars($row['internal_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['catalog'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['part_no'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['printing_type'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['calculation'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['origin'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['supp_code'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['description'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['nama_customer'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['type_rm'] ?? '') ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
<?php
// Kirim output
$output = ob_get_clean();
echo $output;
exit;
?>