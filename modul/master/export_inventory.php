<?php
// file: modul/master/export_inventory.php
// Export Excel Native PHP

// Mulai buffer dan bersihkan semua output sebelumnya
if (ob_get_level()) ob_end_clean();
ob_start();

// Koneksi database
include __DIR__ . '/../../koneksi.php';

// Set header untuk download Excel
header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Master_Inventory_" . date('Ymd_His') . ".xls");
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");

// Query data
$query = mysqli_query($conn, "SELECT * FROM m_inventory ORDER BY inventory_id ASC");

// Output Excel
?>
<table border="1">
    <thead>
        <tr style="background-color: #f2f2f2; font-weight: bold;">
            <th>Inventory ID</th>
            <th>Inventory Name</th>
            <th>UOM</th>
            <th>Type</th>
            <th>Category</th>
            <th>Remarks</th>
            <th>Cap</th>
            <th>Colour</th>
            <th>Quality</th>
            <th>Volume Default</th>
            <th>UOM Pack</th>
            <th>Conversion Rate</th>
            <th>Base UOM</th>
            <th>Pack UOM</th>
            <th>Tolerance</th>
            <th>Upper Tolerance</th>
            <th>Lower Tolerance</th>
            <th>Merk</th>
            <th>P</th>
            <th>L</th>
            <th>T</th>
            <th>P2</th>
            <th>Density</th>
            <th>Description</th>
            <th>Origin</th>
            <th>Status</th>
            <th>Supp Code</th>
            <th>Re Order Point</th>
            <th>Minimum Stock</th>
            <th>Maximum Stock</th>
            <th>Shelf Life Days</th>
            <th>Is Sub</th>
            <th>Is Job Order</th>
            <th>Dont Show W48</th>
            <th>Stokan</th>
            <th>Internal Name</th>
            <th>Catalog</th>
            <th>Part No</th>
            <th>Printing Type</th>
            <th>Calculation</th>
            <th>Nama Customer</th>
            <th>Type RM</th>
            <th>Tebal</th>
            <th>Ukuran</th>
            <th>Strength</th>
            <th>Create User</th>
            <th>Date Created</th>
            <th>User Modified</th>
            <th>Date Modified</th>
            <th>Ket Las</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = mysqli_fetch_assoc($query)): ?>
        <tr>
            <td><?= htmlspecialchars($row['inventory_id'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['inventory_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['uom'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['type'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['category'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['remarks'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['cap'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['colour'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['quality'] ?? '') ?></td>
            <td><?= number_format($row['volume_default'] ?? 0, 4) ?></td>
            <td><?= htmlspecialchars($row['uom_pack'] ?? '') ?></td>
            <td><?= number_format($row['conversion_rate'] ?? 1, 4) ?></td>
            <td><?= htmlspecialchars($row['base_uom'] ?? 'KG') ?></td>
            <td><?= htmlspecialchars($row['pack_uom'] ?? 'PCS') ?></td>
            <td><?= $row['tolerance'] ?? 0 ?></td>
            <td><?= number_format($row['upper_tolerance'] ?? 0, 2) ?></td>
            <td><?= number_format($row['lower_tolerance'] ?? 0, 2) ?></td>
            <td><?= htmlspecialchars($row['merk'] ?? '') ?></td>
            <td><?= number_format($row['p'] ?? 0, 2) ?></td>
            <td><?= number_format($row['l'] ?? 0, 2) ?></td>
            <td><?= number_format($row['t'] ?? 0, 2) ?></td>
            <td><?= number_format($row['p2'] ?? 0, 2) ?></td>
            <td><?= number_format($row['density'] ?? 0, 4) ?></td>
            <td><?= htmlspecialchars($row['description'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['origin'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['status'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['supp_code'] ?? '') ?></td>
            <td><?= number_format($row['re_order_point'] ?? 0, 2) ?></td>
            <td><?= number_format($row['minimum_stock'] ?? 0, 2) ?></td>
            <td><?= number_format($row['maximum_stock'] ?? 0, 2) ?></td>
            <td><?= $row['shelf_life_days'] ?? 0 ?></td>
            <td><?= ($row['is_sub'] ?? '') == 'Checked' ? 'Yes' : 'No' ?></td>
            <td><?= ($row['is_job_order'] ?? '') == 'Checked' ? 'Yes' : 'No' ?></td>
            <td><?= ($row['dont_show_at_w48'] ?? '') == 'Checked' ? 'Yes' : 'No' ?></td>
            <td><?= ($row['stokan'] ?? '') == 'Checked' ? 'Yes' : 'No' ?></td>
            <td><?= htmlspecialchars($row['internal_name'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['catalog'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['part_no'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['printing_type'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['calculation'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['nama_customer'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['type_rm'] ?? '') ?></td>
            <td><?= number_format($row['tebal'] ?? 0, 4) ?></td>
            <td><?= htmlspecialchars($row['ukuran'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['strength'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['create_user'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['date_created'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['user_modified'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['date_modified'] ?? '') ?></td>
            <td><?= htmlspecialchars($row['ket_las'] ?? '') ?></td>
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