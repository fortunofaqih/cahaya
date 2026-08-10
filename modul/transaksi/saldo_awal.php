<?php
// modul/transaksi/saldo_awal.php
// Master / daftar Saldo Awal Customer
// Saldo awal digunakan sebagai saldo piutang customer pada saat cut-off awal penggunaan aplikasi.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function parseReportDate($value, $fallback) {
    $value = trim((string)$value);

    if ($value === '') {
        return $fallback;
    }

    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);

        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    return $fallback;
}

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);

    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function appIcon($name) {
    $icons = [
        'money' => '<svg viewBox="0 0 24 24"><path d="M3 6h18v12H3V6Zm2 2v8h14V8H5Zm7 1a3 3 0 1 1 0 6 3 3 0 0 1 0-6Zm-5 1h2v2H7v-2Zm8 2h2v2h-2v-2Z"/></svg>',
        'search' => '<svg viewBox="0 0 24 24"><path d="M10.5 4a6.5 6.5 0 0 1 5.18 10.43l4.45 4.44-1.42 1.42-4.44-4.45A6.5 6.5 0 1 1 10.5 4Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z"/></svg>',
        'reset' => '<svg viewBox="0 0 24 24"><path d="M12 5a7 7 0 1 1-6.33 10H7.9A5 5 0 1 0 12 7H8.83l2.58 2.59L10 11 5 6l5-5 1.41 1.41L8.83 5H12Z"/></svg>',
        'add' => '<svg viewBox="0 0 24 24"><path d="M11 11V5h2v6h6v2h-6v6h-2v-6H5v-2h6Z"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24"><path d="M5 19h1.4L16.7 8.7l-1.4-1.4L5 17.6V19Zm-2 2v-4.25L16.7 3.05a1 1 0 0 1 1.4 0l2.85 2.85a1 1 0 0 1 0 1.4L7.25 21H3Z"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24"><path d="M7 21a2 2 0 0 1-2-2V7H4V5h5V3h6v2h5v2h-1v12a2 2 0 0 1-2 2H7Zm10-14H7v12h10V7ZM9 9h2v8H9V9Zm4 0h2v8h-2V9Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'customer' => '<svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4 22a8 8 0 0 1 16 0h-2a6 6 0 0 0-12 0H4Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}

$defaultStart = date('Y-01-01');
$defaultEnd = date('Y-m-d');

$start_date = parseReportDate($_GET['start_date'] ?? '', $defaultStart);
$end_date = parseReportDate($_GET['end_date'] ?? '', $defaultEnd);

if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$customer_id = trim((string)($_GET['customer_id'] ?? ''));

/*
 * Daftar customer aktif.
 */
$customers = [];

$sqlCustomer = "
    SELECT
        customer_id,
        customer
    FROM m_customer
    WHERE COALESCE(is_active, 'Checked') = 'Checked'
    ORDER BY customer ASC
";

$resCustomer = mysqli_query($conn, $sqlCustomer);

if ($resCustomer) {
    while ($row = mysqli_fetch_assoc($resCustomer)) {
        $customers[] = $row;
    }
}

/*
 * Daftar saldo awal.
 *
 * Tabel yang digunakan:
 * customer_opening_balance
 *
 * opening_id      : primary key
 * opening_date    : tanggal cut-off saldo awal
 * customer_id     : customer
 * opening_balance : nominal saldo awal piutang
 * remarks         : keterangan
 * status          : Active / Cancelled
 */
$where = "
    WHERE cob.opening_date BETWEEN ? AND ?
";

$params = [
    $start_date,
    $end_date
];

$types = "ss";

if ($customer_id !== '') {
    $where .= " AND cob.customer_id = ? ";
    $params[] = $customer_id;
    $types .= "s";
}

$sql = "
    SELECT
        cob.opening_id,
        cob.opening_date,
        cob.customer_id,
        COALESCE(mc.customer, cob.customer_name, '') AS customer_name,
        COALESCE(mc.city, cob.customer_city, '') AS customer_city,
        cob.opening_balance,
        cob.remarks,
        cob.status,
        cob.create_user,
        cob.date_created
    FROM customer_opening_balance cob

    LEFT JOIN m_customer mc
        ON mc.customer_id = cob.customer_id

    $where

    ORDER BY
        cob.opening_date DESC,
        cob.opening_id DESC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die('SQL Error Saldo Awal: ' . h(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);

$res = mysqli_stmt_get_result($stmt);

$rows = [];
$total_opening_balance = 0;

while ($row = mysqli_fetch_assoc($res)) {
    $rows[] = $row;

    if (strtolower(trim((string)($row['status'] ?? 'Active'))) !== 'cancelled') {
        $total_opening_balance += (float)($row['opening_balance'] ?? 0);
    }
}

mysqli_stmt_close($stmt);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
.saldo-awal-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}

.saldo-awal-wrap {
    background: #f0f2f5;
    padding: 12px;
    color: #212529;
    font-size: 11px;
}

.app-icon {
    width: 14px;
    height: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    vertical-align: -2px;
}

.app-icon svg {
    width: 14px;
    height: 14px;
    display: block;
    fill: currentColor;
}

.title-icon svg {
    width: 18px;
    height: 18px;
}

.crystal-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: #fff;
    padding: 10px 15px;
    border-radius: 5px;
}

.filter-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin-bottom: 10px;
}

.filter-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 2fr auto;
    gap: 8px;
    align-items: end;
}

.ff label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: #0d6efd;
    margin-bottom: 3px;
    text-transform: uppercase;
}

.ff input,
.ff select {
    width: 100%;
    border: 1px solid #ced4da;
    border-radius: 3px;
    padding: 6px 8px;
    font-size: 11px;
    background: #fff;
}

.btn-vs {
    padding: 6px 12px;
    font-size: 11px;
    font-weight: bold;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    line-height: 1;
    min-height: 30px;
}

.btn-vs:hover {
    filter: brightness(.95);
    text-decoration: none;
}

.btn-success { background: #198754; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-warning { background: #ffc107; color: #000; }
.btn-danger { background: #dc3545; color: #fff; }
.btn-dark { background: #212529; color: #fff; }

.table-wrap {
    max-height: 560px;
    overflow: auto;
    border: 1px solid #c0cddb;
    background: #fff;
}

.saldo-awal-table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
    font-size: 9.5px;
}

.saldo-awal-table th {
    position: sticky;
    top: 0;
    background: #e9ecef;
    color: #2b4c7e;
    border: 1px solid #c0cddb;
    padding: 5px 4px;
    text-align: center;
    white-space: nowrap;
    z-index: 2;
}

.saldo-awal-table td {
    border: 1px solid #d3d3d3;
    padding: 4px;
    vertical-align: middle;
    white-space: nowrap;
}

.saldo-awal-table td.remarks-cell {
    white-space: normal;
    min-width: 180px;
}

.saldo-awal-table tbody tr:hover td {
    background: #e8f2fe;
}

.saldo-awal-table tfoot td {
    background: #f8f9fa;
    font-weight: bold;
}

.text-center { text-align: center; }
.text-right { text-align: right; }
.text-bold { font-weight: bold; }

.money-cell {
    text-align: right;
    font-family: Arial, Helvetica, sans-serif;
    font-variant-numeric: tabular-nums;
}

.status-active {
    color: #146c43;
    font-weight: bold;
}

.status-cancelled {
    color: #b02a37;
    font-weight: bold;
}

.select2-container {
    width: 100% !important;
    font-size: 11px;
}

.select2-container--default .select2-selection--single {
    height: 30px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    display: flex;
    align-items: center;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 28px;
    color: #212529;
    padding-left: 8px;
    font-size: 11px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 28px;
}

.select2-dropdown {
    font-size: 11px;
}

@media (max-width: 900px) {
    .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
}
</style>

<div class="saldo-awal-wrap">

    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div
        class="crystal-header"
        style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;"
    >
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('money') ?></span>
            Saldo Awal Customer
        </h5>

        <a
            class="btn-vs btn-success"
            href="index.php?page=add_saldo_awal"
        >
            <span class="app-icon"><?= appIcon('add') ?></span>
            Add Saldo Awal
        </a>
    </div>

    <div class="filter-card">
        <form method="GET" action="index.php">

            <input
                type="hidden"
                name="page"
                value="saldo_awal"
            >

            <div class="filter-grid">

                <div class="ff">
                    <label>
                        <span class="app-icon"><?= appIcon('calendar') ?></span>
                        Start Date
                    </label>

                    <input
                        type="text"
                        name="start_date"
                        class="js-date-picker"
                        value="<?= h(formatDateDisplay($start_date)) ?>"
                        autocomplete="off"
                    >
                </div>

                <div class="ff">
                    <label>
                        <span class="app-icon"><?= appIcon('calendar') ?></span>
                        End Date
                    </label>

                    <input
                        type="text"
                        name="end_date"
                        class="js-date-picker"
                        value="<?= h(formatDateDisplay($end_date)) ?>"
                        autocomplete="off"
                    >
                </div>

                <div class="ff">
                    <label>
                        <span class="app-icon"><?= appIcon('customer') ?></span>
                        Nama Customer
                    </label>

                    <select
                        name="customer_id"
                        class="select2-customer"
                    >
                        <option value="">
                            -- Semua Customer --
                        </option>

                        <?php foreach ($customers as $cust): ?>
                            <option
                                value="<?= h($cust['customer_id']) ?>"
                                <?= $customer_id === $cust['customer_id'] ? 'selected' : '' ?>
                            >
                                <?= h(
                                    $cust['customer_id']
                                    . ' - '
                                    . $cust['customer']
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display:flex;gap:6px;">

                    <button
                        type="submit"
                        class="btn-vs btn-dark"
                    >
                        <span class="app-icon"><?= appIcon('search') ?></span>
                        Cari
                    </button>

                    <a
                        href="index.php?page=saldo_awal"
                        class="btn-vs btn-secondary"
                    >
                        <span class="app-icon"><?= appIcon('reset') ?></span>
                        Reset
                    </a>

                </div>
            </div>
        </form>
    </div>

    <div class="table-wrap">

        <table class="saldo-awal-table">

            <thead>
                <tr>
                    <th style="width:40px;">No</th>
                    <th>Tanggal Saldo</th>
                    <th>Customer ID</th>
                    <th>Nama Customer</th>
                    <th>City</th>
                    <th>Saldo Awal</th>
                    <th>Keterangan</th>
                    <th>Status</th>
                    <th>Input By</th>
                    <th style="width:150px;">Aksi</th>
                </tr>
            </thead>

            <tbody>

                <?php if (empty($rows)): ?>

                    <tr>
                        <td
                            colspan="10"
                            class="text-center"
                            style="padding:15px;color:#777;"
                        >
                            Tidak ada data saldo awal customer.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($rows as $i => $row): ?>

                        <?php
                        $status = trim((string)($row['status'] ?? 'Active'));
                        $isCancelled = strtolower($status) === 'cancelled';
                        ?>

                        <tr>

                            <td class="text-center">
                                <?= $i + 1 ?>
                            </td>

                            <td class="text-center">
                                <?= h(
                                    formatDateDisplay(
                                        $row['opening_date']
                                    )
                                ) ?>
                            </td>

                            <td class="text-bold">
                                <?= h($row['customer_id']) ?>
                            </td>

                            <td>
                                <?= h($row['customer_name']) ?>
                            </td>

                            <td>
                                <?= h($row['customer_city']) ?>
                            </td>

                            <td class="money-cell text-bold">
                                Rp <?= h(
                                    formatMoney(
                                        $row['opening_balance']
                                    )
                                ) ?>
                            </td>

                            <td class="remarks-cell">
                                <?= h($row['remarks']) ?>
                            </td>

                            <td
                                class="text-center <?= $isCancelled ? 'status-cancelled' : 'status-active' ?>"
                            >
                                <?= h($status) ?>
                            </td>

                            <td class="text-center">
                                <?= h($row['create_user']) ?>
                            </td>

                            <td class="text-center">

                                <a
                                    class="btn-vs btn-warning"
                                    href="index.php?page=edit_saldo_awal&opening_id=<?= urlencode($row['opening_id']) ?>"
                                >
                                    <span class="app-icon"><?= appIcon('edit') ?></span>
                                    Edit
                                </a>

                                <a
                                    class="btn-vs btn-danger"
                                    href="modul/transaksi/delete_saldo_awal.php?opening_id=<?= urlencode($row['opening_id']) ?>"
                                    onclick="return confirm('Yakin ingin menghapus saldo awal customer <?= h($row['customer_name']) ?> ?')"
                                >
                                    <span class="app-icon"><?= appIcon('delete') ?></span>
                                    Delete
                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

            <tfoot>
                <tr>

                    <td
                        colspan="5"
                        class="text-right"
                    >
                        TOTAL SALDO AWAL
                    </td>

                    <td class="money-cell">
                        Rp <?= h(
                            formatMoney(
                                $total_opening_balance
                            )
                        ) ?>
                    </td>

                    <td colspan="4"></td>

                </tr>
            </tfoot>

        </table>

    </div>

</div>

<script>
if (typeof flatpickr !== 'undefined') {
    flatpickr('.js-date-picker', {
        dateFormat: 'd-M-Y',
        allowInput: true,
        disableMobile: true
    });
}

$(document).ready(function () {
    $('.select2-customer').select2({
        placeholder: '-- Pilih Customer --',
        allowClear: true,
        width: '100%'
    });
});
</script>
