<?php
// modul/master/export_customer.php
session_start();

if (!isset($_SESSION['username'])) {
    die("Akses ditolak. Silakan login terlebih dahulu.");
}

include __DIR__ . '/../../koneksi.php';

if (ob_get_level()) ob_end_clean();
ob_start();

header("Content-Type: application/vnd.ms-excel");
header("Content-Disposition: attachment; filename=Master_Customer_Lengkap_" . date('Ymd_His') . ".xls");
header("Cache-Control: no-cache, must-revalidate");

// Perbaikan: ORDER BY customer_id (bukan id, karena id mungkin tidak ada)
$query = mysqli_query($conn, "SELECT * FROM m_customer ORDER BY customer_id ASC");
?>
<html>
<head>
<meta charset="UTF-8">
<style>
    /* Style Ultra Compact seperti Excel */
    table {
        border-collapse: collapse;
        font-family: Calibri, Arial, sans-serif;
        font-size: 10pt;
    }
    th, td {
        border: 1px solid #aaa;
        padding: 2px 5px;
        height: 20px;
        vertical-align: top;
    }
    th {
        background: #e9ecef;
        font-weight: bold;
        white-space: nowrap;
    }
    td {
        white-space: normal;
        word-break: break-word;
        max-width: 250px;
    }
    /* Kolom pendek - no wrap */
    td.nowrap {
        white-space: nowrap;
    }
    td.right {
        text-align: right;
    }
    td.center {
        text-align: center;
    }
</style>
</head>
<body>
<table>
    <thead>
        <tr>
            <th>Customer ID</th>
            <th>Customer</th>
            <th>City</th>
            <th>Address</th>
            <th>NPWP Address</th>
            <th>Contact Person</th>
            <th>CP Phone</th>
            <th>NPWP</th>
            <th>ID Number</th>
            <th>ID Name</th>
            <th>Phone</th>
            <th>Fax</th>
            <th>Credit Limit</th>
            <th>Email</th>
            <th>Old Code</th>
            <th>Area Code</th>
            <th>Effective Date Area</th>
            <th>Remark Area</th>
            <th>Sales ID</th>
            <th>Sales Name</th>
            <th>Effective Date Sales</th>
            <th>Remark Sales</th>
            <th>Remarks</th>
            <th>Type</th>
            <th>Tax Type</th>
            <th>Parent ID</th>
            <th>Parent Customer</th>
            <th>ID TKU</th>
            <th>Bagian</th>
            <th>Transaction Tax</th>
            <th>Transaction Tax Child</th>
            <th>Is Active</th>
            <th>User Created</th>
            <th>Date Created</th>
            <th>User Modified</th>
            <th>Date Modified</th>
         </tr>
    </thead>
    <tbody>
        <?php while ($row = mysqli_fetch_assoc($query)): ?>
        <tr>
            <td class="nowrap center"><?= htmlspecialchars($row['customer_id']) ?></td>
            <td><?= htmlspecialchars(strtoupper($row['customer'])) ?></td>
            <td><?= htmlspecialchars(strtoupper($row['city'])) ?></td>
            <td><?= htmlspecialchars($row['address']) ?></td>
            <td><?= htmlspecialchars($row['npwp_address']) ?></td>
            <td><?= htmlspecialchars($row['contact_person']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['contact_person_phone']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['npwp']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['id_number']) ?></td>
            <td><?= htmlspecialchars($row['id_name']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['phone']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['fax']) ?></td>
            <td class="right nowrap"><?= number_format($row['credit_limit'], 2) ?></td>
            <td><?= htmlspecialchars($row['email']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['old_code']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['area_code']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['effective_date_area']) ?></td>
            <td><?= htmlspecialchars($row['remark_area']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['sales_id']) ?></td>
            <td><?= htmlspecialchars($row['sales_name']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['effective_date_sales']) ?></td>
            <td><?= htmlspecialchars($row['remark_sales']) ?></td>
            <td><?= htmlspecialchars($row['remarks']) ?></td>
            <td class="center"><?= htmlspecialchars($row['type']) ?></td>
            <td class="center"><?= htmlspecialchars($row['tax_type']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['parent_id']) ?></td>
            <td><?= htmlspecialchars($row['parent_customer']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['id_tku']) ?></td>
            <td><?= htmlspecialchars($row['bagian']) ?></td>
            <td><?= htmlspecialchars($row['transaction_tax']) ?></td>
            <td><?= htmlspecialchars($row['transaction_tax_child']) ?></td>
            <td class="center"><?= $row['is_active'] == 'Checked' ? 'Active' : 'Inactive' ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['user_created']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['date_created']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['user_modified']) ?></td>
            <td class="nowrap"><?= htmlspecialchars($row['date_modified']) ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</body>
</html>
<?php
$output = ob_get_contents();
ob_end_clean();
echo $output;
exit;
?>