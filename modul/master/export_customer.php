<?php
// modul/master/export_customer.php
session_start();

if (!isset($_SESSION['username'])) {
    die("Akses ditolak. Silakan login terlebih dahulu.");
}

include __DIR__ . '/../../koneksi.php';

// Bersihkan output buffer agar file Excel tidak rusak oleh spasi / warning sebelumnya
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Jangan tampilkan warning ke file export
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

$filename = "Master_Customer_Lengkap_" . date('Ymd_His') . ".xls";

header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header("Cache-Control: no-cache, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Helper aman untuk export HTML Excel
function e($value) {
    if ($value === null || $value === '') {
        return '';
    }

    $value = (string)$value;

    // Cegah formula injection di Excel jika data diawali =, +, -, @
    if (preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }

    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eUpper($value) {
    if ($value === null || $value === '') {
        return '';
    }
    return e(strtoupper((string)$value));
}

function eText($value) {
    if ($value === null || $value === '') {
        return '';
    }

    $value = (string)$value;

    // Prefix tab menjaga leading zero dan format panjang tetap sebagai text di Excel
    if (preg_match('/^[=+\-@]/', $value)) {
        $value = "'" . $value;
    }

    return "&#9;" . htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function eMoney($value) {
    if ($value === null || $value === '') {
        $value = 0;
    }
    return number_format((float)$value, 2, '.', ',');
}

function eStatus($value) {
    return ((string)$value === 'Checked') ? 'Active' : 'Inactive';
}

$sql = "SELECT * FROM m_customer ORDER BY customer_id ASC";
$query = mysqli_query($conn, $sql);

if (!$query) {
    die("Gagal export customer: " . htmlspecialchars(mysqli_error($conn), ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
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
        text-align: center;
    }
    td {
        white-space: normal;
        word-break: break-word;
        max-width: 250px;
    }
    td.nowrap {
        white-space: nowrap;
    }
    td.right {
        text-align: right;
    }
    td.center {
        text-align: center;
    }
    td.text-format {
        mso-number-format: "\\@";
    }
    td.number-format {
        mso-number-format: "#,##0.00";
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
            <td class="nowrap center text-format"><?= eText($row['customer_id'] ?? '') ?></td>
            <td><?= eUpper($row['customer'] ?? '') ?></td>
            <td><?= eUpper($row['city'] ?? '') ?></td>
            <td><?= e($row['address'] ?? '') ?></td>
            <td><?= e($row['npwp_address'] ?? '') ?></td>
            <td><?= e($row['contact_person'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['contact_person_phone'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['npwp'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['id_number'] ?? '') ?></td>
            <td><?= e($row['id_name'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['phone'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['fax'] ?? '') ?></td>
            <td class="right nowrap number-format"><?= eMoney($row['credit_limit'] ?? 0) ?></td>
            <td><?= e($row['email'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['old_code'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['area_code'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['effective_date_area'] ?? '') ?></td>
            <td><?= e($row['remark_area'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['sales_id'] ?? '') ?></td>
            <td><?= e($row['sales_name'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['effective_date_sales'] ?? '') ?></td>
            <td><?= e($row['remark_sales'] ?? '') ?></td>
            <td><?= e($row['remarks'] ?? '') ?></td>
            <td class="center"><?= e($row['type'] ?? '') ?></td>
            <td class="center"><?= e($row['tax_type'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['parent_id'] ?? '') ?></td>
            <td><?= e($row['parent_customer'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['id_tku'] ?? '') ?></td>
            <td><?= e($row['bagian'] ?? '') ?></td>
            <td><?= e($row['transaction_tax'] ?? '') ?></td>
            <td><?= e($row['transaction_tax_child'] ?? '') ?></td>
            <td class="center"><?= eStatus($row['is_active'] ?? '') ?></td>
            <td class="nowrap"><?= e($row['user_created'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['date_created'] ?? '') ?></td>
            <td class="nowrap"><?= e($row['user_modified'] ?? '') ?></td>
            <td class="nowrap text-format"><?= eText($row['date_modified'] ?? '') ?></td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</body>
</html>
<?php
mysqli_free_result($query);
exit;
?>
