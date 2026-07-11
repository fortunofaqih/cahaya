<?php
// modul/transaksi/add_titip.php

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

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function generateTitipNo($conn) {
    $prefix = 'T-';
    $sql = "
        SELECT titip_no 
        FROM head_titip 
        WHERE titip_no LIKE 'T-%'
        ORDER BY CAST(SUBSTRING(titip_no, 3) AS UNSIGNED) DESC
        LIMIT 1
    ";
    $res = mysqli_query($conn, $sql);
    $lastNumber = 0;

    if ($res && $row = mysqli_fetch_assoc($res)) {
        $lastNumber = (int)substr($row['titip_no'], 2);
    }

    return $prefix . str_pad($lastNumber + 1, 9, '0', STR_PAD_LEFT);
}

function appIcon($name) {
    $icons = [
        'money' => '<svg viewBox="0 0 24 24"><path d="M3 6h18v12H3V6Zm2 2v8h14V8H5Zm7 1a3 3 0 1 1 0 6 3 3 0 0 1 0-6Zm-5 1h2v2H7v-2Zm8 2h2v2h-2v-2Z"/></svg>',
        'save' => '<svg viewBox="0 0 24 24"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4ZM5 5h10.2L19 8.8V19H5V5Zm2 8h10v5H7v-5Zm1-6h7v4H8V7Z"/></svg>',
        'back' => '<svg viewBox="0 0 24 24"><path d="M20 11v2H7.83l5.59 5.59L12 20 4 12l8-8 1.42 1.41L7.83 11H20Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}

$titip_no = generateTitipNo($conn);

$customers = [];
$sqlCustomer = "
    SELECT 
        customer_id,
        customer,
        address AS customer_address,
        city AS customer_city
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
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
.titip-form-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.titip-form-wrap {
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
    vertical-align: -2px;
}
.app-icon svg {
    width: 14px;
    height: 14px;
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
.form-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 12px;
    margin-top: 10px;
}
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
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
.ff select,
.ff textarea {
    width: 100%;
    border: 1px solid #ced4da;
    border-radius: 3px;
    padding: 6px 8px;
    font-size: 11px;
    background: #fff;
}
.ff input[readonly],
.ff textarea[readonly] {
    background: #f8f9fa;
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
.btn-success { background: #198754; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.warning-box {
    display: none;
    margin-top: 6px;
    padding: 7px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: bold;
}
.warning-info {
    display: block;
    background: #fff3cd;
    color: #664d03;
    border: 1px solid #ffecb5;
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
    .form-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="titip-form-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('money') ?></span>
            Add Titip Uang
        </h5>

        <a class="btn-vs btn-secondary" href="index.php?page=titip_uang">
            <span class="app-icon"><?= appIcon('back') ?></span>
            Kembali
        </a>
    </div>

    <form method="POST" action="modul/transaksi/save_titip.php" id="formTitip">
        <div class="form-card">
            <div class="form-grid">
                <div class="ff">
                    <label>No. Titip</label>
                    <input type="text" name="titip_no" value="<?= h($titip_no) ?>" readonly>
                </div>

                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Tanggal Titip</label>
                    <input type="text" name="titip_date" class="js-date-picker" value="<?= h(date('d-M-Y')) ?>" autocomplete="off" required>
                </div>

                <div class="ff" style="grid-column:1 / -1;">
                    <label>Nama Customer</label>
                    <select name="customer_select" id="customer_select" class="select2-customer" required>
                        <option value="">-- Pilih Customer --</option>
                        <?php foreach ($customers as $cust): ?>
                            <option
                                value="<?= h($cust['customer_id']) ?>"
                                data-customer-id="<?= h($cust['customer_id']) ?>"
                                data-customer-name="<?= h($cust['customer']) ?>"
                                data-customer-address="<?= h($cust['customer_address']) ?>"
                                data-customer-city="<?= h($cust['customer_city']) ?>">
                                <?= h($cust['customer_id'] . ' - ' . $cust['customer']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ff">
                    <label>Customer ID</label>
                    <input type="text" name="customer_id" id="customer_id" readonly required>
                </div>

                <div class="ff">
                    <label>Nama Customer</label>
                    <input type="text" name="customer_name" id="customer_name" readonly required>
                </div>

                <div class="ff" style="grid-column:1 / -1;">
                    <label>Customer Address</label>
                    <textarea name="customer_address" id="customer_address" rows="2" readonly></textarea>
                </div>

                <div class="ff">
                    <label>Customer City</label>
                    <input type="text" name="customer_city" id="customer_city" readonly>
                </div>

                <div class="ff">
                    <label>Jumlah Titip</label>
                    <input type="text" name="total_titip" id="total_titip" autocomplete="off" required>
                    <div id="warningNominal" class="warning-box"></div>
                </div>

                <div class="ff">
                    <label>Keterangan</label>
                    <select name="keterangan" id="keterangan" required>
                        <option value="">-- Pilih --</option>
                        <option value="Cash">Cash</option>
                        <option value="Transfer">Transfer</option>
                    </select>
                </div>

                <div class="ff">
                    <label>Nama Bank</label>
                    <input type="text" name="bank_name" id="bank_name" placeholder="Contoh: BCA / Mandiri / BRI">
                </div>

                <div class="ff" style="grid-column:1 / -1;">
                    <label>Remarks</label>
                    <textarea name="remarks" rows="3"></textarea>
                </div>
            </div>

            <div style="margin-top:12px;display:flex;gap:6px;justify-content:flex-end;">
                <a href="index.php?page=titip_uang" class="btn-vs btn-secondary">
                    <span class="app-icon"><?= appIcon('back') ?></span>
                    Batal
                </a>
                <button type="submit" class="btn-vs btn-success">
                    <span class="app-icon"><?= appIcon('save') ?></span>
                    Save
                </button>
            </div>
        </div>
    </form>
</div>

<script>
function parseNumber(value) {
    value = String(value || '').replace(/[^0-9,-]/g, '');
    value = value.replace(/\./g, '').replace(',', '.');
    return parseFloat(value) || 0;
}

function formatRupiah(value) {
    value = parseFloat(value) || 0;
    return value.toLocaleString('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

$(document).ready(function () {
    $('.select2-customer').select2({
        placeholder: '-- Pilih Customer --',
        allowClear: true,
        width: '100%'
    });

    if (typeof flatpickr !== 'undefined') {
        flatpickr('.js-date-picker', {
            dateFormat: 'd-M-Y',
            allowInput: true,
            disableMobile: true
        });
    }

    $('#customer_select').on('change', function () {
        const opt = $(this).find(':selected');

        $('#customer_id').val(opt.data('customer-id') || '');
        $('#customer_name').val(opt.data('customer-name') || '');
        $('#customer_address').val(opt.data('customer-address') || '');
        $('#customer_city').val(opt.data('customer-city') || '');
    });

    $('#total_titip').on('blur', function () {
        const nominal = parseNumber($(this).val());
        if (nominal > 0) {
            $(this).val(formatRupiah(nominal));
            $('#warningNominal')
                .addClass('warning-info')
                .html('Jumlah titip: Rp ' + formatRupiah(nominal))
                .show();
        }
    });

    $('#keterangan').on('change', function () {
        if ($(this).val() === 'Cash') {
            $('#bank_name').val('');
        }
    });

    $('#formTitip').on('submit', function (e) {
        const nominal = parseNumber($('#total_titip').val());

        if (!$('#customer_id').val()) {
            alert('Customer wajib dipilih.');
            e.preventDefault();
            return false;
        }

        if (nominal <= 0) {
            alert('Jumlah titip harus lebih dari 0.');
            e.preventDefault();
            return false;
        }

        $('#total_titip').val(nominal);
    });
});
</script>