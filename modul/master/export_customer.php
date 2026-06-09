<?php
// modul/master/export_customer.php (Ultra Compact)
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

$query = mysqli_query($conn, "SELECT * FROM m_customer ORDER BY id ASC");
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
        padding: 0px 3px;
        height: 21px;
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
        max-width: 200px;
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
    /* Setting lebar kolom otomatis (Excel akan sesuaikan) */
    col.col-id { width: 65px; }
    col.col-cust { width: 130px; }
    col.col-city { width: 70px; }
    col.col-addr { width: 160px; }
    col.col-npwpaddr { width: 160px; }
    col.col-cp { width: 85px; }
    col.col-phone { width: 80px; }
    col.col-npwp { width: 105px; }
    col.col-email { width: 120px; }
    col.col-remark { width: 120px; }
    col.col-tax { width: 150px; }
</style>
</head>
<body>
<table>
    <colgroup>
        <col class="col-id"><col class="col-cust"><col class="col-city"><col class="col-addr"><col class="col-addr">
        <col class="col-cp"><col class="col-phone"><col class="col-phone"><col class="col-npwp"><col style="width:90px">
        <col style="width:90px"><col class="col-phone"><col class="col-phone"><col style="width:80px"><col class="col-email">
        <col style="width:65px"><col style="width:65px"><col class="col-remark"><col style="width:50px"><col style="width:65px">
        <col style="width:60px"><col style="width:90px"><col style="width:55px"><col style="width:80px"><col class="col-tax">
        <col style="width:90px"><col style="width:40px"><col style="width:80px"><col style="width:100px"><col style="width:80px"><col style="width:100px">
    </colgroup>
    <thead>
        <tr>
            <th>ID</th><th>Customer</th><th>City</th><th>Address</th><th>NPWP Address</th>
            <th>Contact Person</th><th>CP Phone</th><th>CP Mobile</th><th>NPWP</th><th>ID Number</th>
            <th>ID Name</th><th>Phone</th><th>Fax</th><th>Credit Limit</th><th>Email</th>
            <th>Old Code</th><th>Area Code</th><th>Remarks</th><th>Type</th><th>Tax Type</th>
            <th>Parent ID</th><th>Parent Cust</th><th>TKU</th><th>Bagian</th><th>Transaction Tax</th>
            <th>Trans Child</th><th>Active</th><th>User Created</th><th>Date Created</th><th>User Modified</th><th>Date Modified</th>
        </tr>
    </thead>
    <tbody>
        <?php while ($row = mysqli_fetch_assoc($query)): ?>
        <tr>
            <td class="nowrap center"><?= $row['id'] ?></td>
            <td><?= strtoupper($row['customer']) ?></td>
            <td><?= strtoupper($row['city']) ?></td>
            <td><?= strtoupper($row['address']) ?></td>
            <td><?= strtoupper($row['npwp_address']) ?></td>
            <td><?= strtoupper($row['contact_person']) ?></td>
            <td class="nowrap"><?= $row['contact_person_phone'] ?></td>
            <td class="nowrap"><?= $row['contact_person_mobile'] ?></td>
            <td class="nowrap"><?= $row['npwp'] ?></td>
            <td class="nowrap"><?= $row['id_number'] ?></td>
            <td><?= strtoupper($row['id_name']) ?></td>
            <td class="nowrap"><?= $row['phone'] ?></td>
            <td class="nowrap"><?= $row['fax'] ?></td>
            <td class="right nowrap"><?= number_format($row['credit_limit'], 2) ?></td>
            <td><?= $row['email'] ?></td>
            <td class="nowrap"><?= $row['old_code'] ?></td>
            <td class="nowrap"><?= $row['area_code'] ?></td>
            <td><?= $row['remarks'] ?></td>
            <td class="center"><?= $row['type'] ?></td>
            <td class="center"><?= $row['tax_type'] ?></td>
            <td class="nowrap"><?= $row['parent_id'] ?></td>
            <td><?= $row['parent_customer'] ?></td>
            <td class="nowrap"><?= $row['id_tku'] ?></td>
            <td><?= $row['bagian'] ?></td>
            <td><?= $row['transaction_tax'] ?></td>
            <td><?= $row['transaction_tax_child'] ?></td>
            <td class="center"><?= $row['is_active'] == 'Checked' ? '✓' : '' ?></td>
            <td class="nowrap"><?= $row['user_created'] ?></td>
            <td class="nowrap"><?= $row['date_created'] ?></td>
            <td class="nowrap"><?= $row['user_modified'] ?></td>
            <td class="nowrap"><?= $row['date_modified'] ?></td>
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