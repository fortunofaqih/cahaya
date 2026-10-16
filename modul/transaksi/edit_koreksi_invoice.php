<?php
// modul/transaksi/edit_koreksi_invoice.php

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

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoneyInput($value) {
    $value = abs((float)$value);
    return number_format($value, 0, ',', '.');
}

function appIcon($name) {
    $icons = [
        'invoice' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a2 2 0 0 1 2 2v18l-3-1.5L13 22l-3-1.5L7 22l-3-1.5V4a3 3 0 0 1 3-2Zm0 2a1 1 0 0 0-1 1v14.3l1 .5 3-1.5 3 1.5 3-1.5 1 .5V4H7Zm2 4h6v2H9V8Zm0 4h6v2H9v-2Zm0 4h4v2H9v-2Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'save' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h12l2 2v16H5V3Zm2 2v14h10V6.2L15.8 5H15v5H8V5H7Zm3 0v3h3V5h-3Zm-1 8h6v4H9v-4Z"/></svg>',
        'reset' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.33 10H7.9A5 5 0 1 0 12 7H8.83l2.58 2.59L10 11 5 6l5-5 1.41 1.41L8.83 5H12Z"/></svg>',
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

$correctionId = (int)($_GET['correction_id'] ?? 0);
if ($correctionId <= 0) {
    $_SESSION['alert'] = '<div style="padding:10px;margin-bottom:10px;border-radius:4px;background:#f8d7da;color:#842029;border:1px solid #f5c2c7;">ID koreksi tidak valid.</div>';
    header('Location: ../../index.php?page=koreksi_invoice');
    exit;
}

$stmt = mysqli_prepare($conn, "
    SELECT correction_id, correction_no, correction_date, customer_id,
           category, invoice_no, shipping_no,
           correction_amount, status
    FROM invoice_correction
    WHERE correction_id = ?
    LIMIT 1
");
mysqli_stmt_bind_param($stmt, 'i', $correctionId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($result);
mysqli_stmt_close($stmt);

if (!$data) {
    $_SESSION['alert'] = '<div style="padding:10px;margin-bottom:10px;border-radius:4px;background:#f8d7da;color:#842029;border:1px solid #f5c2c7;">Data koreksi invoice tidak ditemukan.</div>';
    header('Location: ../../index.php?page=koreksi_invoice');
    exit;
}

if (strtolower((string)$data['status']) !== 'active') {
    $_SESSION['alert'] = '<div style="padding:10px;margin-bottom:10px;border-radius:4px;background:#f8d7da;color:#842029;border:1px solid #f5c2c7;">Koreksi yang sudah Cancelled tidak dapat diedit.</div>';
    header('Location: ../../index.php?page=koreksi_invoice');
    exit;
}

$customerList = [];
$resCustomer = mysqli_query($conn, "
    SELECT customer_id, customer, city
    FROM m_customer
    WHERE COALESCE(is_active, 'Checked') = 'Checked'
       OR customer_id = '" . mysqli_real_escape_string($conn, (string)$data['customer_id']) . "'
    ORDER BY customer ASC
");
while ($c = mysqli_fetch_assoc($resCustomer)) {
    $customerList[] = $c;
}
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
.form-card { background:#fff; border:1px solid #dee2e6; border-radius:5px; padding:12px; margin-bottom:10px; }
.form-grid { display:grid; grid-template-columns:160px minmax(250px,1.2fr) 160px 170px 170px 190px auto; gap:8px; align-items:end; }
.ff label { display:block; font-size:10px; font-weight:700; color:#0d6efd; margin-bottom:3px; text-transform:uppercase; }
.ff input, .ff select { width:100%; border:1px solid #ced4da; border-radius:3px; padding:6px 8px; font-size:11px; background:#fff; min-height:30px; }
.ff input[readonly] { background:#f8f9fa; }
.amount-note { margin-top:3px; color:#842029; font-size:10px; }
.btn-vs { padding:6px 12px; font-size:11px; font-weight:bold; border:none; border-radius:3px; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:5px; line-height:1; min-height:30px; }
.btn-success { background:#198754; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.select2-container { width:100% !important; }
.select2-container .select2-selection--single { height:30px; border:1px solid #ced4da; border-radius:3px; font-size:11px; }
.select2-container .select2-selection--single .select2-selection__rendered { line-height:28px; padding-left:8px; }
.select2-container .select2-selection--single .select2-selection__arrow { height:28px; }
.select2-dropdown { font-size:11px; }
@media (max-width:900px) { .form-grid { grid-template-columns:1fr 1fr; } }
</style>

<div class="invoice-wrap">
    <div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('invoice') ?></span>
            Edit Koreksi Invoice
        </h5>
        <div style="font-weight:700;"><?= h($data['correction_no']) ?></div>
    </div>

    <div class="form-card">
        <form method="POST" action="save_koreksi_invoice.php" autocomplete="off">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="correction_id" value="<?= (int)$data['correction_id'] ?>">

            <div class="form-grid">
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Tanggal Koreksi</label>
                    <input type="text" name="correction_date" class="js-date-picker"
                           value="<?= h(formatDateDisplay($data['correction_date'])) ?>"
                           autocomplete="off" required>
                </div>

                <div class="ff">
                    <label>Customer</label>
                    <select name="customer_id" class="js-customer-select" required>
                        <option value="">-- Pilih Customer --</option>
                        <?php foreach ($customerList as $customer): ?>
                            <option value="<?= h($customer['customer_id']) ?>"
                                <?= ((string)$data['customer_id'] === (string)$customer['customer_id']) ? 'selected' : '' ?>>
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
                            <option value="<?= h($category) ?>"
                                <?= ((string)($data['category'] ?? '') === $category) ? 'selected' : '' ?>>
                                <?= h($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ff">
                    <label>Invoice No. <span style="font-weight:400;text-transform:none;">(opsional)</span></label>
                    <input type="text" name="invoice_no"
                           value="<?= h($data['invoice_no'] ?? '') ?>"
                           placeholder="Contoh: INV/2026/000001">
                </div>

                <div class="ff">
                    <label>Shipping No. <span style="font-weight:400;text-transform:none;">(opsional)</span></label>
                    <input type="text" name="shipping_no"
                           value="<?= h($data['shipping_no'] ?? '') ?>"
                           placeholder="Contoh: 001234">
                </div>

                <div class="ff">
                    <label>Nominal Koreksi</label>
                    <input type="text" name="correction_amount" class="js-money"
                           value="<?= h(formatMoneyInput($data['correction_amount'])) ?>"
                           required>
                    <div class="amount-note">Input positif. Sistem tetap menyimpan sebagai nilai minus.</div>
                </div>

                <div style="display:flex;gap:6px;">
                    <button type="submit" class="btn-vs btn-success">
                        <span class="app-icon"><?= appIcon('save') ?></span>
                        Update
                    </button>
                    <a href="../../index.php?page=koreksi_invoice" class="btn-vs btn-secondary">
                        <span class="app-icon"><?= appIcon('reset') ?></span>
                        Batal
                    </a>
                </div>
            </div>
        </form>
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
    $('.js-customer-select').select2({
        width: '100%',
        placeholder: 'Ketik nama / ID customer...',
        allowClear: true
    });
}
</script>
