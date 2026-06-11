<?php
// modul/master/export_mesin.php
session_start();

if (!isset($_SESSION['username'])) {
    die("Akses ditolak");
}

include __DIR__ . '/../../koneksi.php';

while (ob_get_level()) ob_end_clean();

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Master_Mesin_" . date('Ymd_His') . ".xls");
header("Cache-Control: no-cache, must-revalidate");

$query = mysqli_query($conn, "SELECT * FROM m_mesin ORDER BY mesin_id ASC");
?>

<table border="1">
    <thead>
        <tr>
            <th>ID Mesin</th><th>Nama Mesin</th><th>Spesifikasi</th><th>Tgl Manufacture</th>
            <th>Dibuat Oleh</th><th>Supplier</th><th>Harga Beli</th><th>Tgl Beli</th>
            <th>ACC Reff</th><th>Keterangan</th><th>Status</th><th>Kapasitas</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = mysqli_fetch_assoc($query)): ?>
        <tr>
            <td><?= htmlspecialchars($row['mesin_id']) ?></td>
            <td><?= htmlspecialchars($row['name']) ?></td>
            <td><?= htmlspecialchars($row['spec']) ?></td>
            <td><?= htmlspecialchars($row['manufactured_date']) ?></td>
            <td><?= htmlspecialchars($row['manufactured_by']) ?></td>
            <td><?= htmlspecialchars($row['supplier']) ?></td>
            <td><?= number_format($row['purchase_price'], 2) ?></td>
            <td><?= htmlspecialchars($row['purchase_date']) ?></td>
            <td><?= htmlspecialchars($row['acc_reff']) ?></td>
            <td><?= htmlspecialchars($row['remarks']) ?></td>
            <td><?= $row['active'] == 'Checked' ? 'Active' : 'Inactive' ?></td>
            <td><?= htmlspecialchars($row['capacity']) ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
<?php exit; ?>