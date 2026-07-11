<?php
// modul/transaksi/invoice.php

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

function parseInvoiceDate($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y'];
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
        'invoice' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a2 2 0 0 1 2 2v18l-3-1.5L13 22l-3-1.5L7 22l-3-1.5V4a3 3 0 0 1 3-2Zm0 2a1 1 0 0 0-1 1v14.3l1 .5 3-1.5 3 1.5 3-1.5 1 .5V4H7Zm2 4h6v2H9V8Zm0 4h6v2H9v-2Zm0 4h4v2H9v-2Z"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 4a6.5 6.5 0 0 1 5.18 10.43l4.45 4.44-1.42 1.42-4.44-4.45A6.5 6.5 0 1 1 10.5 4Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z"/></svg>',
        'reset' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.33 10H7.9A5 5 0 1 0 12 7H8.83l2.58 2.59L10 11 5 6l5-5 1.41 1.41L8.83 5H12Z"/></svg>',
        'print' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10v5H7V3Zm2 2v1h6V5H9ZM6 9h12a3 3 0 0 1 3 3v5h-4v4H7v-4H3v-5a3 3 0 0 1 3-3Zm3 7v3h6v-3H9Zm8-3h2v-1a1 1 0 0 0-1-1H6a1 1 0 0 0-1 1v3h2v-1h10v1h2v-2h-2Z"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.86 3.59a2 2 0 0 1 2.83 0l.72.72a2 2 0 0 1 0 2.83L9.5 18.05 5 19l.95-4.5L16.86 3.59Zm1.41 1.41L7.78 15.5l-.3 1.02 1.02-.3L19 5.73 18.27 5ZM4 21h16v-2H4v2Z"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v9h-2V9Zm4 0h2v9h-2V9ZM7 9h2v10h6V9h2v10a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V9Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'status' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 3 6v6c0 5 3.8 9.7 9 10 5.2-.3 9-5 9-10V6l-9-4Zm0 2.2 7 3.1V12c0 4-2.9 7.5-7 8-4.1-.5-7-4-7-8V7.3l7-3.1Zm-1 11.2 5.3-5.3 1.4 1.4-6.7 6.7-3.7-3.7 1.4-1.4 2.3 2.3Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}

$today = date('Y-m-d');
$start_date = parseInvoiceDate($_GET['start_date'] ?? '', $today);
$end_date = parseInvoiceDate($_GET['end_date'] ?? '', $today);

if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$start_date_display = formatDateDisplay($start_date);
$end_date_display = formatDateDisplay($end_date);
$status = trim((string)($_GET['status'] ?? 'All'));
$invoice_no_search = trim((string)($_GET['invoice_no'] ?? ''));

$where = ["hi.invoice_date BETWEEN ? AND ?"];
$types = 'ss';
$params = [$start_date, $end_date];

if ($status !== '' && strtolower($status) !== 'all') {
    $where[] = "hi.status = ?";
    $types .= 's';
    $params[] = $status;
}

if ($invoice_no_search !== '') {
    $where[] = "hi.invoice_no LIKE ?";
    $types .= 's';
    $params[] = '%' . $invoice_no_search . '%';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);
$sql = "
    SELECT
        hi.invoice_no,
        hi.invoice_date,
        hi.customer_name,
        hi.customer_address,
        hi.customer_city,
        hi.order_no,
        hi.station,
        hi.payment_type,
        hi.payment_term,
        hi.days,
        hi.currency,
        hi.down_payment,
        hi.subtotal,
        hi.grand_total,
        hi.remarks_invoice,
        hi.status,
        hi.approval_status,
        hi.create_user,
        hi.date_created,
        hi.user_modified,
        hi.date_modified,
        COALESCE(dc.total_shipping, 0) AS total_shipping
    FROM head_invoice hi
    LEFT JOIN (
        SELECT invoice_no, COUNT(*) AS total_shipping
        FROM det_invoice
        GROUP BY invoice_no
    ) dc ON dc.invoice_no = hi.invoice_no
    $where_sql
    ORDER BY hi.invoice_date DESC, hi.invoice_no DESC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die('<div class="alert alert-danger">SQL Error: ' . h(mysqli_error($conn)) . '</div>');
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>
.invoice-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.invoice-wrap {
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
    grid-template-columns: repeat(5, minmax(130px, 1fr));
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
.btn-primary { background: #0d6efd; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-success { background: #198754; color: #fff; }
.btn-warning { background: #ffc107; color: #000; }
.btn-danger { background: #dc3545; color: #fff; }
.btn-dark { background: #212529; color: #fff; }
.btn-action {
    width: 30px;
    height: 28px;
    padding: 0;
    margin: 0 1px;
}
.table-wrap {
    max-height: 560px;
    overflow: auto;
    border: 1px solid #c0cddb;
    background: #fff;
}
.invoice-table {
    width: 100%;
    min-width: 2100px;
    border-collapse: collapse;
    font-size: 10.5px;
}
.invoice-table th {
    position: sticky;
    top: 0;
    background: #e9ecef;
    color: #2b4c7e;
    border: 1px solid #c0cddb;
    padding: 7px 6px;
    text-align: center;
    white-space: nowrap;
    z-index: 2;
}
.invoice-table td {
    border: 1px solid #d3d3d3;
    padding: 5px 6px;
    vertical-align: middle;
    white-space: nowrap;
}
.invoice-table tbody tr:hover td {
    background: #e8f2fe;
}
.sticky-aksi {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 3;
    min-width: 120px;
}
.invoice-table th.sticky-aksi {
    background: #e9ecef;
    z-index: 4;
}
.badge-open,
.badge-close {
    padding: 3px 8px;
    border-radius: 10px;
    font-weight: bold;
    display: inline-block;
    min-width: 48px;
    text-align: center;
}
.badge-open { background: #d1e7dd; color: #0f5132; }
.badge-close { background: #f8d7da; color: #842029; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.text-bold { font-weight: bold; }
.text-blue { color: #0d6efd; }
@media (max-width: 900px) {
    .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
}
@media print {
    .d-print-none {
        display: none !important;
    }
    .invoice-wrap {
        background: #fff;
        padding: 0;
    }
    .table-wrap {
        max-height: none;
        overflow: visible;
    }
}
</style>

<div class="invoice-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="crystal-header d-print-none" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('invoice') ?></span>
            Invoice
        </h5>
        <a href="index.php?page=add_invoice" class="btn-vs btn-success">
            <span class="app-icon"><?= appIcon('plus') ?></span>
            Create New Invoice
        </a>
    </div>

    <div class="filter-card d-print-none">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="invoice">
            <div class="filter-grid">
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Start Date</label>
                    <input type="text" name="start_date" class="js-date-picker" value="<?= h($start_date_display) ?>" autocomplete="off">
                </div>
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> End Date</label>
                    <input type="text" name="end_date" class="js-date-picker" value="<?= h($end_date_display) ?>" autocomplete="off">
                </div>
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('status') ?></span> Status</label>
                    <select name="status">
                        <?php foreach (['All' => 'All', 'Open' => 'Open', 'Close' => 'Close'] as $val => $label): ?>
                            <option value="<?= h($val) ?>" <?= $status === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ff">
                    <label>Invoice No</label>
                    <input type="text" name="invoice_no" value="<?= h($invoice_no_search) ?>" placeholder="Cari Invoice No...">
                </div>
                <div style="display:flex;gap:6px;">
                    <button type="submit" class="btn-vs btn-dark">
                        <span class="app-icon"><?= appIcon('search') ?></span>
                        Cari
                    </button>
                    <a href="index.php?page=invoice" class="btn-vs btn-secondary">
                        <span class="app-icon"><?= appIcon('reset') ?></span>
                        Reset
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
                    <th>Invoice No.</th>
                    <th>Invoice Date</th>
                    <th>Customer Name</th>
                    <th>Customer Address</th>
                    <th>Customer City</th>
                    <th>Order No</th>
                    <th>Station</th>
                    <th>Payment Type</th>
                    <th>Payment Term</th>
                    <th>Currency</th>
                    <th>Downpayment</th>
                    <th>Subtotal</th>
                    <th>Grand Total</th>
                    <th>Remarks Invoice</th>
                   <!-- <th>Status</th>-->
                    <th>User Created</th>
                    <th>Date Created</th>
                    <th>User Modified</th>
                    <th>Date Modified</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$result || mysqli_num_rows($result) === 0): ?>
                    <tr>
                        <td colspan="20" style="text-align:center;color:#777;padding:15px;">
                            Tidak ada data invoice.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <?php
                            $invoiceNo = (string)($row['invoice_no'] ?? '');
                            $statusText = (string)($row['status'] ?? 'Open');
                            $statusClass = strtolower($statusText) === 'close' ? 'badge-close' : 'badge-open';
                            $paymentTerm = $row['payment_term'] ?: $row['days'];
                        ?>
                        <tr>
                          <td class="sticky-aksi text-center">
                                <a class="btn-vs btn-primary btn-action"
                                href="modul/transaksi/cetak_invoice.php?invoice_no=<?= urlencode($row['invoice_no']) ?>"
                                target="_blank"
                                title="Print">
                                    <span class="app-icon"><?= appIcon('print') ?></span>
                                </a>

                                <!--<a class="btn-vs btn-warning btn-action"
                                href="modul/transaksi/edit_invoice.php?invoice_no=<?= urlencode($row['invoice_no']) ?>"
                                title="Edit">
                                    <span class="app-icon"><?= appIcon('edit') ?></span>
                                </a>-->

                                <a class="btn-vs btn-danger btn-action"
                                href="modul/transaksi/delete_invoice.php?invoice_no=<?= urlencode($row['invoice_no']) ?>"
                                onclick="return confirm('Hapus invoice <?= htmlspecialchars($row['invoice_no'], ENT_QUOTES, 'UTF-8') ?>?')"
                                title="Delete">
                                    <span class="app-icon"><?= appIcon('delete') ?></span>
                                </a>
                            </td>
                            <td class="text-bold text-blue"><?= h($invoiceNo) ?></td>
                            <td><?= h(formatDateDisplay($row['invoice_date'])) ?></td>
                            <td><?= h($row['customer_name']) ?></td>
                            <td><?= h($row['customer_address']) ?></td>
                            <td><?= h($row['customer_city']) ?></td>
                            <td><?= h($row['order_no']) ?></td>
                            <td><?= h($row['station']) ?></td>
                            <td><?= h($row['payment_type']) ?></td>
                            <td><?= h($paymentTerm) ?></td>
                            <td><?= h($row['currency']) ?></td>
                            <td class="text-right">Rp <?= h(formatMoney($row['down_payment'])) ?></td>
                            <td class="text-right">Rp <?= h(formatMoney($row['subtotal'])) ?></td>
                            <td class="text-right text-bold">Rp <?= h(formatMoney($row['grand_total'])) ?></td>
                            <td><?= h($row['remarks_invoice']) ?></td>
                            <!--<td class="text-center"><span class="<?= h($statusClass) ?>"><?= h($statusText) ?></span></td>-->
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
</script>

<?php mysqli_stmt_close($stmt); ?>
