<?php
// modul/transaksi/edit_saldo_awal.php

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
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
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

$opening_id = isset($_GET['opening_id']) ? (int)$_GET['opening_id'] : 0;

if ($opening_id <= 0) {
    die('Opening ID tidak valid.');
}

$stmt = mysqli_prepare(
    $conn,
    "
    SELECT
        cob.*,
        COALESCE(mc.customer, cob.customer_name, '') AS master_customer_name,
        COALESCE(mc.city, cob.customer_city, '') AS master_customer_city
    FROM customer_opening_balance cob
    LEFT JOIN m_customer mc
        ON mc.customer_id = cob.customer_id
    WHERE cob.opening_id = ?
    LIMIT 1
    "
);

if (!$stmt) {
    die('SQL Error: ' . h(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmt, 'i', $opening_id);
mysqli_stmt_execute($stmt);

$res = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($res);

mysqli_stmt_close($stmt);

if (!$data) {
    die('Data saldo awal tidak ditemukan.');
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

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
.readonly-box {
    padding: 8px 10px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 3px;
    min-height: 34px;
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
.btn-success { background: #198754; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.button-row {
    display: flex;
    justify-content: flex-end;
    gap: 7px;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #e5e5e5;
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
            Edit Saldo Awal Customer
        </h5>
    </div>

    <div class="form-card">

        <form
            method="POST"
            action="modul/transaksi/update_saldo_awal.php"
            id="formSaldoAwal"
            autocomplete="off"
        >
            <input
                type="hidden"
                name="opening_id"
                value="<?= (int)$data['opening_id'] ?>"
            >

            <div class="form-grid">

                <div class="form-group">
                    <label>
                        <span class="app-icon"><?= appIcon('customer') ?></span>
                        Customer ID
                    </label>

                    <div class="readonly-box">
                        <?= h($data['customer_id']) ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Nama Customer</label>

                    <div class="readonly-box">
                        <?= h($data['master_customer_name']) ?>
                    </div>
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
                        value="<?= h(formatDateDisplay($data['opening_date'])) ?>"
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
                        value="<?= h(number_format((float)$data['opening_balance'], 2, ',', '.')) ?>"
                        inputmode="decimal"
                        required
                    >

                    <input
                        type="hidden"
                        name="opening_balance"
                        id="opening_balance"
                        value="<?= h((float)$data['opening_balance']) ?>"
                    >
                </div>

                <div class="form-group">
                    <label>City</label>

                    <div class="readonly-box">
                        <?= h($data['master_customer_city']) ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Status</label>

                    <select
                        name="status"
                        class="form-control"
                        required
                    >
                        <option
                            value="Active"
                            <?= strtolower((string)$data['status']) === 'active' ? 'selected' : '' ?>
                        >
                            Active
                        </option>

                        <option
                            value="Cancelled"
                            <?= strtolower((string)$data['status']) === 'cancelled' ? 'selected' : '' ?>
                        >
                            Cancelled
                        </option>
                    </select>
                </div>

                <div class="form-group full">
                    <label>Keterangan</label>

                    <textarea
                        name="remarks"
                        class="form-control"
                    ><?= h($data['remarks']) ?></textarea>
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
                    Update Saldo Awal
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

function parseMoneyInput(value) {
    value = String(value || '').trim();

    if (value === '') {
        return 0;
    }

    value = value.replace(/\s/g, '');
    value = value.replace(/\./g, '');
    value = value.replace(',', '.');

    const numeric = parseFloat(value);

    return Number.isFinite(numeric) ? numeric : 0;
}

function formatMoneyInput(value) {
    const numeric = parseMoneyInput(value);

    return numeric.toLocaleString('id-ID', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    });
}

document.getElementById('opening_balance_display')
    .addEventListener('input', function () {
        const cleaned = this.value.replace(/[^0-9.,-]/g, '');

        if (this.value !== cleaned) {
            this.value = cleaned;
        }
    });

document.getElementById('opening_balance_display')
    .addEventListener('blur', function () {
        const numeric = parseMoneyInput(this.value);

        document.getElementById('opening_balance').value =
            numeric.toFixed(2);

        this.value = formatMoneyInput(this.value);
    });

document.getElementById('formSaldoAwal')
    .addEventListener('submit', function (e) {

        const saldo = parseMoneyInput(
            document.getElementById('opening_balance_display').value
        );

        if (!Number.isFinite(saldo) || saldo < 0) {
            alert('Saldo awal tidak valid.');
            e.preventDefault();
            return;
        }

        document.getElementById('opening_balance').value =
            saldo.toFixed(2);

        const btn = document.getElementById('btnSave');
        btn.disabled = true;
        btn.textContent = 'Menyimpan...';
    });
</script>
