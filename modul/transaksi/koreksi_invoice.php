<?php
// modul/transaksi/koreksi_invoice.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function parseCorrectionDate($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '') return $fallback;

    foreach (['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }
    return $fallback;
}

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    $value = (float)$value;
    $value = $value >= 0 ? floor($value) : ceil($value);
    return number_format($value, 2, ',', '.');
}

function appIcon($name) {
    $icons = [
        'invoice' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a2 2 0 0 1 2 2v18l-3-1.5L13 22l-3-1.5L7 22l-3-1.5V4a3 3 0 0 1 3-2Zm0 2a1 1 0 0 0-1 1v14.3l1 .5 3-1.5 3 1.5 3-1.5 1 .5V4H7Zm2 4h6v2H9V8Zm0 4h6v2H9v-2Zm0 4h4v2H9v-2Z"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 4a6.5 6.5 0 0 1 5.18 10.43l4.45 4.44-1.42 1.42-4.44-4.45A6.5 6.5 0 1 1 10.5 4Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z"/></svg>',
        'reset' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.33 10H7.9A5 5 0 1 0 12 7H8.83l2.58 2.59L10 11 5 6l5-5 1.41 1.41L8.83 5H12Z"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.86 3.59a2 2 0 0 1 2.83 0l.72.72a2 2 0 0 1 0 2.83L9.5 18.05 5 19l.95-4.5L16.86 3.59Zm1.41 1.41L7.78 15.5l-.3 1.02 1.02-.3L19 5.73 18.27 5ZM4 21h16v-2H4v2Z"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v9h-2V9Zm4 0h2v9h-2V9ZM7 9h2v10h6V9h2v10a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V9Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'save' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h12l2 2v16H5V3Zm2 2v14h10V6.2L15.8 5H15v5H8V5H7Zm3 0v3h3V5h-3Zm-1 8h6v4H9v-4Z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$correctionCategories = [
    'PP',
    'KERTAS',
    'PE',
    'PE WARNA',
    'HD',
    'HD WARNA',
    'HD KRESEK',
    'HD SABLON',
    'PP SABLON',
    'TALI KG',
    'TALI LOS',
    'BAHAN',
    'TERPAL',
    'BOX',
    'SEDOTAN',
    'ONGKOS'
];

$today = date('Y-m-d');
$start_date = parseCorrectionDate($_GET['start_date'] ?? '', date('Y-m-01'));
$end_date   = parseCorrectionDate($_GET['end_date'] ?? '', $today);
if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$customer_search = trim((string)($_GET['customer_id'] ?? ''));

// Customer aktif untuk input / filter.
$customerList = [];
$sqlCustomer = "
    SELECT customer_id, customer, city
    FROM m_customer
    WHERE COALESCE(is_active, 'Checked') = 'Checked'
    ORDER BY customer ASC
";
$resCustomer = mysqli_query($conn, $sqlCustomer);
while ($c = mysqli_fetch_assoc($resCustomer)) {
    $customerList[] = $c;
}


$where = ["ic.correction_date BETWEEN ? AND ?"];
$types = 'ss';
$params = [$start_date, $end_date];

if ($customer_search !== '') {
    $where[] = "ic.customer_id = ?";
    $types .= 's';
    $params[] = $customer_search;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);
$sql = "
    SELECT
        ic.correction_id,
        ic.correction_no,
        ic.correction_date,
        ic.customer_id,
        COALESCE(mc.customer, ic.customer_id) AS customer_name,
        COALESCE(mc.city, '') AS customer_city,
        ic.category,
        ic.invoice_no,
        ic.shipping_no,
        ic.correction_amount,
        ic.status,
        ic.create_user,
        ic.date_created,
        ic.user_modified,
        ic.date_modified
    FROM invoice_correction ic
    LEFT JOIN m_customer mc
        ON mc.customer_id COLLATE utf8mb4_general_ci
         = ic.customer_id COLLATE utf8mb4_general_ci
    $whereSql
    ORDER BY ic.correction_date DESC, ic.correction_id DESC
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
.invoice-wrap * { box-sizing:border-box; font-family:'Segoe UI',Tahoma,Arial,sans-serif; }
.invoice-wrap { background:#f0f2f5; padding:12px; color:#212529; font-size:11px; }
.app-icon { width:14px; height:14px; display:inline-flex; align-items:center; justify-content:center; flex:0 0 auto; vertical-align:-2px; }
.app-icon svg { width:14px; height:14px; display:block; fill:currentColor; }
.title-icon svg { width:18px; height:18px; }
.crystal-header { background:linear-gradient(135deg,#1e3c72 0%,#2a5298 100%); color:#fff; padding:10px 15px; border-radius:5px; }
.filter-card, .form-card { background:#fff; border:1px solid #dee2e6; border-radius:5px; padding:10px; margin-bottom:10px; }
.filter-grid { display:grid; grid-template-columns:repeat(4,minmax(150px,1fr)); gap:8px; align-items:end; }
.form-grid { display:grid; grid-template-columns:160px minmax(250px,1.2fr) 160px 170px 170px 190px auto; gap:8px; align-items:end; }
.ff label { display:block; font-size:10px; font-weight:700; color:#0d6efd; margin-bottom:3px; text-transform:uppercase; }
.ff input, .ff select { width:100%; border:1px solid #ced4da; border-radius:3px; padding:6px 8px; font-size:11px; background:#fff; min-height:30px; }
.ff input:focus, .ff select:focus { outline:none; border-color:#86b7fe; box-shadow:0 0 0 2px rgba(13,110,253,.08); }
.amount-note { margin-top:3px; color:#842029; font-size:10px; }
.select2-container {
    width: 100% !important;
}

.select2-container .select2-selection--single {
    height: 30px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    font-size: 11px;
}

.select2-container .select2-selection--single .select2-selection__rendered {
    line-height: 28px;
    padding-left: 8px;
}

.select2-container .select2-selection--single .select2-selection__arrow {
    height: 28px;
}

.select2-dropdown {
    font-size: 11px;
}
.btn-vs { padding:6px 12px; font-size:11px; font-weight:bold; border:none; border-radius:3px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:5px; line-height:1; min-height:30px; }
.btn-vs:hover { filter:brightness(.95); text-decoration:none; }
.btn-primary { background:#0d6efd; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-success { background:#198754; color:#fff; }
.btn-warning { background:#ffc107; color:#000; }
.btn-danger { background:#dc3545; color:#fff; }
.btn-dark { background:#212529; color:#fff; }
.btn-action { width:30px; height:28px; padding:0; margin:0 1px; }
.table-wrap { max-height:560px; overflow:auto; border:1px solid #c0cddb; background:#fff; }
.invoice-table { width:100%; min-width:1250px; border-collapse:collapse; font-size:10.5px; }
.invoice-table th { position:sticky; top:0; background:#e9ecef; color:#2b4c7e; border:1px solid #c0cddb; padding:7px 6px; text-align:center; white-space:nowrap; z-index:2; }
.invoice-table td { border:1px solid #d3d3d3; padding:5px 6px; vertical-align:middle; white-space:nowrap; }
.invoice-table tbody tr:hover td { background:#e8f2fe; }
.sticky-aksi { position:sticky; left:0; background:#fff; z-index:3; min-width:82px; }
.invoice-table th.sticky-aksi { background:#e9ecef; z-index:4; }
.text-right { text-align:right; }
.text-center { text-align:center; }
.text-bold { font-weight:bold; }
.text-blue { color:#0d6efd; }
.text-danger { color:#dc3545; }
.badge-active, .badge-cancelled { padding:3px 8px; border-radius:10px; font-weight:bold; display:inline-block; min-width:56px; text-align:center; }
.badge-active { background:#d1e7dd; color:#0f5132; }
.badge-cancelled { background:#f8d7da; color:#842029; }
@media (max-width:900px) {
    .filter-grid, .form-grid { grid-template-columns:1fr 1fr; }
}
</style>

<div class="invoice-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('invoice') ?></span>
            Koreksi Invoice
        </h5>
    </div>

    <div class="form-card">
        <form method="POST" action="modul/transaksi/save_koreksi_invoice.php" autocomplete="off">
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Tanggal Koreksi</label>
                    <input type="text" name="correction_date" class="js-date-picker"
                           value="<?= h(formatDateDisplay($today)) ?>"
                           autocomplete="off" required>
                </div>

                <div class="ff">
                    <label>Customer</label>
                    <select name="customer_id"
                        id="customer_id"
                        class="js-customer-select"
                        required>
                    <option value="">-- Pilih Customer --</option>

                    <?php foreach ($customerList as $customer): ?>
                        <option value="<?= h($customer['customer_id']) ?>">
                            <?= h($customer['customer_id'] . ' - ' . $customer['customer']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                </div>

                <div class="ff">
                    <label>Kategori</label>
                    <select name="category" required>
                        <option value="">-- Pilih Kategori --</option>
                        <?php foreach ($correctionCategories as $category): ?>
                            <option value="<?= h($category) ?>"><?= h($category) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ff">
                    <label>Invoice No. <span style="font-weight:400;text-transform:none;">(opsional)</span></label>
                    <input type="text" name="invoice_no"
                           placeholder="Contoh: INV/2026/000001">
                </div>

                <div class="ff">
                    <label>Shipping No. <span style="font-weight:400;text-transform:none;">(opsional)</span></label>
                    <input type="text" name="shipping_no"
                           placeholder="Contoh: 001234">
                </div>

                <div class="ff">
                    <label>Nominal Koreksi</label>
                    <input type="text" name="correction_amount" class="js-money"
                           value=""
                           placeholder="Contoh: 2.000.000" required>
                    <div class="amount-note">Input positif. Sistem otomatis menyimpan sebagai nilai minus.</div>
                </div>

                <div style="display:flex;gap:6px;align-items:end;">
                    <button type="submit" class="btn-vs btn-success">
                        <span class="app-icon"><?= appIcon('save') ?></span>
                        Simpan
                    </button>
                </div>
            </div>
        </form>
    </div>

    <div class="filter-card">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="koreksi_invoice">
            <div class="filter-grid">
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Start Date</label>
                    <input type="text" name="start_date" class="js-date-picker" value="<?= h(formatDateDisplay($start_date)) ?>" autocomplete="off">
                </div>
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> End Date</label>
                    <input type="text" name="end_date" class="js-date-picker" value="<?= h(formatDateDisplay($end_date)) ?>" autocomplete="off">
                </div>
                <div class="ff">
                    <label>Customer</label>
                    <select name="customer_id" class="js-customer-filter">
                        <option value="">Semua Customer</option>
                        <?php foreach ($customerList as $c): ?>
                            <option value="<?= h($c['customer_id']) ?>" <?= $customer_search === $c['customer_id'] ? 'selected' : '' ?>>
                                <?= h($c['customer_id'] . ' - ' . $c['customer']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex;gap:6px;">
                    <button type="submit" class="btn-vs btn-dark">
                        <span class="app-icon"><?= appIcon('search') ?></span> Cari
                    </button>
                    <a href="index.php?page=koreksi_invoice" class="btn-vs btn-secondary">
                        <span class="app-icon"><?= appIcon('reset') ?></span> Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <div class="table-wrap">
        <table class="invoice-table">
            <thead>
                <tr>
                    <th class="sticky-aksi">Aksi</th>
                    <th>No. Koreksi</th>
                    <th>Tanggal Koreksi</th>
                    <th>Customer ID</th>
                    <th>Customer Name</th>
                    <th>Kota</th>
                    <th>Kategori</th>
                    <th>Invoice No.</th>
                    <th>Shipping No.</th>
                    <th>Nominal Koreksi</th>
                    <th>Status</th>
                    <th>User Created</th>
                    <th>Date Created</th>
                    <th>User Modified</th>
                    <th>Date Modified</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$result || mysqli_num_rows($result) === 0): ?>
                    <tr>
                        <td colspan="15" style="text-align:center;color:#777;padding:15px;">Tidak ada data koreksi invoice.</td>
                    </tr>
                <?php else: ?>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <?php $isActive = strtolower((string)$row['status']) === 'active'; ?>
                        <tr>
                            <td class="sticky-aksi text-center">
                                <?php if ($isActive): ?>
                                    <a class="btn-vs btn-warning btn-action"
                                       href="modul/transaksi/edit_koreksi_invoice.php?correction_id=<?= (int)$row['correction_id'] ?>"
                                       title="Edit Koreksi">
                                        <span class="app-icon"><?= appIcon('edit') ?></span>
                                    </a>
                                    <a class="btn-vs btn-danger btn-action"
                                       href="modul/transaksi/delete_koreksi_invoice.php?correction_id=<?= (int)$row['correction_id'] ?>"
                                       onclick="return confirm('Batalkan koreksi <?= h($row['correction_no']) ?>? Data tidak akan dihapus fisik, hanya status menjadi Cancelled.');"
                                       title="Delete / Cancel">
                                        <span class="app-icon"><?= appIcon('delete') ?></span>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td class="text-bold text-blue"><?= h($row['correction_no']) ?></td>
                            <td><?= h(formatDateDisplay($row['correction_date'])) ?></td>
                            <td><?= h($row['customer_id']) ?></td>
                            <td><?= h($row['customer_name']) ?></td>
                            <td><?= h($row['customer_city']) ?></td>
                            <td><?= h($row['category']) ?></td>
                            <td><?= h($row['invoice_no']) ?></td>
                            <td><?= h($row['shipping_no']) ?></td>
                            <td class="text-right text-bold text-danger">Rp <?= h(formatMoney($row['correction_amount'])) ?></td>
                            <td class="text-center">
                                <span class="<?= $isActive ? 'badge-active' : 'badge-cancelled' ?>"><?= h($row['status']) ?></span>
                            </td>
                            <td><?= h($row['create_user']) ?></td>
                            <td><?= h($row['date_created']) ?></td>
                            <td><?= h($row['user_modified']) ?></td>
                            <td><?= h($row['date_modified']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
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

document.querySelectorAll('.js-money').forEach(function(input) {
    input.addEventListener('input', function() {
        var digits = this.value.replace(/[^0-9]/g, '');
        this.value = digits ? new Intl.NumberFormat('id-ID').format(parseInt(digits, 10)) : '';
    });
});
if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.select2) {
    $(document).ready(function () {
        $('.js-customer-select').select2({
            width: '100%',
            placeholder: 'Ketik nama / ID customer...',
            allowClear: true
        });

        $('.js-customer-filter').select2({
            width: '100%',
            placeholder: 'Semua Customer',
            allowClear: true
        });
    });
}
</script>

<?php mysqli_stmt_close($stmt); ?>
