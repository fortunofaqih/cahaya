<?php
// modul/transaksi/add_saldo_awal.php

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

function appIcon($name) {
    $icons = [
        'money' => '<svg viewBox="0 0 24 24"><path d="M3 6h18v12H3V6Zm2 2v8h14V8H5Zm7 1a3 3 0 1 1 0 6 3 3 0 0 1 0-6Zm-5 1h2v2H7v-2Zm8 2h2v2h-2v-2Z"/></svg>',
        'save' => '<svg viewBox="0 0 24 24"><path d="M5 3h12l2 2v16H5V3Zm2 2v14h10V6.83L16.17 5H7Zm2 0h5v4H9V5Zm0 8h6v4H9v-4Z"/></svg>',
        'back' => '<svg viewBox="0 0 24 24"><path d="M11 5 4 12l7 7 1.41-1.41L7.83 13H20v-2H7.83l4.58-4.59L11 5Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'customer' => '<svg viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4 22a8 8 0 0 1 16 0h-2a6 6 0 0 0-12 0H4Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}

/*
 * Customer aktif yang BELUM mempunyai saldo awal aktif.
 * Karena customer_id dibuat UNIQUE pada customer_opening_balance,
 * customer yang sudah pernah diinput diarahkan untuk Edit.
 */
$customers = [];

$sqlCustomer = "
    SELECT
        mc.customer_id,
        mc.customer,
        mc.city
    FROM m_customer mc
    WHERE COALESCE(mc.is_active, 'Checked') = 'Checked'
      AND NOT EXISTS (
            SELECT 1
            FROM customer_opening_balance cob
            WHERE cob.customer_id = mc.customer_id
      )
    ORDER BY mc.customer ASC
";

$resCustomer = mysqli_query($conn, $sqlCustomer);

if (!$resCustomer) {
    die('Gagal mengambil customer: ' . h(mysqli_error($conn)));
}

while ($row = mysqli_fetch_assoc($resCustomer)) {
    $customers[] = $row;
}

$defaultDate = date('Y-m-d');
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
.saldo-awal-form-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}

.saldo-awal-form-wrap {
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
    margin-bottom: 10px;
}

.form-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 14px;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

.form-group {
    margin-bottom: 10px;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: #0d6efd;
    margin-bottom: 4px;
    text-transform: uppercase;
}

.form-control {
    width: 100%;
    border: 1px solid #ced4da;
    border-radius: 3px;
    padding: 7px 8px;
    font-size: 11px;
    background: #fff;
}

textarea.form-control {
    min-height: 80px;
    resize: vertical;
}

.money-input {
    text-align: right;
    font-weight: 700;
    font-size: 13px;
}

.btn-vs {
    padding: 7px 14px;
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
    min-height: 32px;
}

.btn-success {
    background: #198754;
    color: #fff;
}

.btn-secondary {
    background: #6c757d;
    color: #fff;
}

.button-row {
    display: flex;
    justify-content: flex-end;
    gap: 7px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #e5e5e5;
}

.info-box {
    background: #eef6ff;
    border: 1px solid #b6d4fe;
    color: #084298;
    padding: 9px 10px;
    border-radius: 4px;
    margin-bottom: 12px;
    line-height: 1.5;
}

.customer-preview {
    padding: 8px 10px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 3px;
    min-height: 34px;
}

.select2-container {
    width: 100% !important;
    font-size: 11px;
}

.select2-container--default .select2-selection--single {
    height: 32px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    display: flex;
    align-items: center;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 30px;
    color: #212529;
    padding-left: 8px;
    font-size: 11px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 30px;
}

@media (max-width: 800px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .form-group.full {
        grid-column: auto;
    }
}
</style>

<div class="saldo-awal-form-wrap">

    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="crystal-header">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('money') ?></span>
            Add Saldo Awal Customer
        </h5>
    </div>

    <div class="form-card">

        <div class="info-box">
            Satu customer hanya dapat memiliki satu saldo awal. Jika sudah pernah diinput, gunakan menu Edit.
        </div>

        <form
            method="POST"
            action="modul/transaksi/save_saldo_awal.php"
            id="formSaldoAwal"
            autocomplete="off"
        >

            <div class="form-grid">

                <div class="form-group full">
                    <label>
                        <span class="app-icon"><?= appIcon('customer') ?></span>
                        Customer *
                    </label>

                    <select
                        name="customer_id"
                        id="customer_id"
                        class="select2-customer"
                        required
                    >
                        <option value="">-- Pilih Customer --</option>

                        <?php foreach ($customers as $cust): ?>
                            <option
                                value="<?= h($cust['customer_id']) ?>"
                                data-name="<?= h($cust['customer']) ?>"
                                data-city="<?= h($cust['city']) ?>"
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

                <div class="form-group">
                    <label>
                        <span class="app-icon"><?= appIcon('calendar') ?></span>
                        Tanggal Saldo *
                    </label>

                    <input
                        type="text"
                        name="opening_date"
                        id="opening_date"
                        class="form-control js-date-picker"
                        value="<?= h(date('d-M-Y', strtotime($defaultDate))) ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Saldo Awal *</label>

                    <input
                        type="text"
                        name="opening_balance_display"
                        id="opening_balance_display"
                        class="form-control money-input"
                        placeholder="0"
                        inputmode="decimal"
                        required
                    >

                    <input
                        type="hidden"
                        name="opening_balance"
                        id="opening_balance"
                        value="0"
                    >
                </div>

                <div class="form-group">
                    <label>Nama Customer</label>

                    <div
                        class="customer-preview"
                        id="customer_name_preview"
                    >
                        -
                    </div>
                </div>

                <div class="form-group">
                    <label>City</label>

                    <div
                        class="customer-preview"
                        id="customer_city_preview"
                    >
                        -
                    </div>
                </div>

                <div class="form-group full">
                    <label>Keterangan</label>

                    <textarea
                        name="remarks"
                        class="form-control"
                        placeholder="Contoh: Saldo akhir piutang per 31-Jul-2026 sebelum implementasi aplikasi."
                    ></textarea>
                </div>

            </div>

            <div class="button-row">

                <a
                    href="index.php?page=saldo_awal"
                    class="btn-vs btn-secondary"
                >
                    <span class="app-icon"><?= appIcon('back') ?></span>
                    Kembali
                </a>

                <button
                    type="submit"
                    class="btn-vs btn-success"
                    id="btnSave"
                >
                    <span class="app-icon"><?= appIcon('save') ?></span>
                    Save Saldo Awal
                </button>

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

$(document).ready(function () {

    $('.select2-customer').select2({
        placeholder: '-- Pilih Customer --',
        allowClear: true,
        width: '100%'
    });

    function updateCustomerPreview() {
        const option = $('#customer_id option:selected');

        const name = option.data('name') || '-';
        const city = option.data('city') || '-';

        $('#customer_name_preview').text(name);
        $('#customer_city_preview').text(city);
    }

    $('#customer_id').on('change', updateCustomerPreview);

    function parseMoneyInput(value) {
        value = String(value || '').trim();

        if (value === '') {
            return 0;
        }

        // Format Indonesia:
        // 1.234.567,89 -> 1234567.89
        value = value.replace(/\s/g, '');
        value = value.replace(/\./g, '');
        value = value.replace(',', '.');

        const numeric = parseFloat(value);

        return Number.isFinite(numeric) ? numeric : 0;
    }

    function formatMoneyInput(value) {
        const numeric = parseMoneyInput(value);

        if (!Number.isFinite(numeric)) {
            return '';
        }

        return numeric.toLocaleString('id-ID', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        });
    }

    $('#opening_balance_display').on('input', function () {
        const raw = $(this).val();

        // Izinkan angka, titik dan koma saat mengetik.
        const cleaned = raw.replace(/[^0-9.,-]/g, '');

        if (raw !== cleaned) {
            $(this).val(cleaned);
        }
    });

    $('#opening_balance_display').on('blur', function () {
        const numeric = parseMoneyInput($(this).val());

        $('#opening_balance').val(numeric.toFixed(2));

        if ($(this).val().trim() !== '') {
            $(this).val(formatMoneyInput($(this).val()));
        }
    });

    $('#formSaldoAwal').on('submit', function (e) {

        const customerId = $('#customer_id').val();
        const openingDate = $('#opening_date').val().trim();
        const saldo = parseMoneyInput(
            $('#opening_balance_display').val()
        );

        if (!customerId) {
            alert('Customer wajib dipilih.');
            e.preventDefault();
            return;
        }

        if (!openingDate) {
            alert('Tanggal saldo wajib diisi.');
            e.preventDefault();
            return;
        }

        if (!Number.isFinite(saldo)) {
            alert('Saldo awal tidak valid.');
            e.preventDefault();
            return;
        }

        $('#opening_balance').val(saldo.toFixed(2));

        $('#btnSave')
            .prop('disabled', true)
            .text('Menyimpan...');
    });

});
</script>
