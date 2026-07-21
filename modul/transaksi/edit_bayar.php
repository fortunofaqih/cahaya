<?php
// modul/transaksi/edit_bayar.php

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

function appIcon($name) {
    $icons = [
        'payment' => '<svg viewBox="0 0 24 24"><path d="M3 5h18v14H3V5Zm2 4h14V7H5v2Zm0 3v5h14v-5H5Zm2 2h5v2H7v-2Z"/></svg>',
        'save' => '<svg viewBox="0 0 24 24"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4ZM5 5h10.2L19 8.8V19H5V5Zm2 8h10v5H7v-5Zm1-6h7v4H8V7Z"/></svg>',
        'back' => '<svg viewBox="0 0 24 24"><path d="M20 11v2H7.83l5.59 5.59L12 20 4 12l8-8 1.42 1.41L7.83 11H20Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}

$bayar_no = trim((string)($_GET['bayar_no'] ?? ''));

if ($bayar_no === '') {
    echo "<script>alert('No. Bayar tidak ditemukan.'); window.location.href='index.php?page=pembayaran';</script>";
    exit;
}

$sql = "
    SELECT
        hb.bayar_no,
        hb.bayar_date,
        hb.customer_id,
        hb.customer_name,
        hb.customer_address,
        hb.customer_city,
        hb.total_bayar,
        hb.keterangan,
        hb.bank_name,
        hb.remarks,
        db.invoice_no,
        db.invoice_date,
        db.invoice_amount,
        db.cash_amount,
        db.titip_amount,
        db.bayar_amount
    FROM head_bayar hb
    INNER JOIN detail_bayar db ON db.bayar_no = hb.bayar_no
    WHERE hb.bayar_no = ?
    LIMIT 1
";
$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die('Error preparing query: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, 's', $bayar_no);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$data) {
    echo "<script>alert('Data pembayaran tidak ditemukan.'); window.location.href='index.php?page=pembayaran';</script>";
    exit;
}

$invoice_no = $data['invoice_no'];

// ============================================================
// QUERY HITUNG SISA INVOICE - DIPERBAIKI
// ============================================================
$sqlSisa = "
    SELECT
        hi.invoice_no,
        CASE
            WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
            WHEN COALESCE(hi.grand_total, 0) > 0 THEN COALESCE(hi.grand_total, 0)
            ELSE COALESCE(hi.subtotal, 0)
        END AS invoice_amount,
        COALESCE(SUM(CASE WHEN db.bayar_no <> ? THEN db.bayar_amount ELSE 0 END), 0) AS paid_except_current
    FROM head_invoice hi
    LEFT JOIN detail_bayar db ON db.invoice_no = hi.invoice_no
    WHERE hi.invoice_no = ?
    GROUP BY hi.invoice_no, hi.piutang, hi.grand_total, hi.subtotal
    LIMIT 1
";
$stmtSisa = mysqli_prepare($conn, $sqlSisa);
if (!$stmtSisa) {
    die('Error preparing query: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmtSisa, 'ss', $bayar_no, $invoice_no);
mysqli_stmt_execute($stmtSisa);
$resSisa = mysqli_stmt_get_result($stmtSisa);
$sisaData = mysqli_fetch_assoc($resSisa);
mysqli_stmt_close($stmtSisa);

// ============================================================
// QUERY CEK SALDO TITIP
// ============================================================
$sqlSaldoTitip = "
    SELECT COALESCE(SUM(balance_amount), 0) AS saldo_titip
    FROM head_titip
    WHERE customer_id = ?
      AND balance_amount > 0
";
$stmtSaldoTitip = mysqli_prepare($conn, $sqlSaldoTitip);
if (!$stmtSaldoTitip) {
    die('Error preparing query: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmtSaldoTitip, 's', $data['customer_id']);
mysqli_stmt_execute($stmtSaldoTitip);
$resSaldoTitip = mysqli_stmt_get_result($stmtSaldoTitip);
$rowSaldoTitip = mysqli_fetch_assoc($resSaldoTitip);
mysqli_stmt_close($stmtSaldoTitip);

$current_titip_amount = (float)($data['titip_amount'] ?? 0);
$saldo_titip_available = (float)($rowSaldoTitip['saldo_titip'] ?? 0) + $current_titip_amount;

$invoice_amount = (float)($sisaData['invoice_amount'] ?? $data['invoice_amount']);
$paid_except_current = (float)($sisaData['paid_except_current'] ?? 0);
$sisa_before_current = $invoice_amount - $paid_except_current;
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<style>
.pay-form-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.pay-form-wrap {
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
.ff input[readonly] {
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
.warning-danger {
    display: block;
    background: #f8d7da;
    color: #842029;
    border: 1px solid #f5c2c7;
}
.warning-info {
    display: block;
    background: #fff3cd;
    color: #664d03;
    border: 1px solid #ffecb5;
}
.warning-ok {
    display: block;
    background: #d1e7dd;
    color: #0f5132;
    border: 1px solid #badbcc;
}
@media (max-width: 900px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
}
.form-section {
    border: 1px solid #d8e2ef;
    border-radius: 6px;
    background: #ffffff;
    margin-bottom: 12px;
    overflow: hidden;
}

.form-section-title {
    background: linear-gradient(135deg, #eef5ff 0%, #f8fbff 100%);
    color: #1e3c72;
    font-size: 11px;
    font-weight: 800;
    padding: 8px 10px;
    border-bottom: 1px solid #d8e2ef;
    text-transform: uppercase;
    letter-spacing: .2px;
}

.form-section-body {
    padding: 10px;
}

.form-grid-3 {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr;
    gap: 10px;
}

.form-grid-2 {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.form-grid-4 {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

.field-full {
    grid-column: 1 / -1;
}

.readonly-highlight {
    background: #f8f9fa !important;
    font-weight: 700;
    color: #1e3c72;
}

.payment-summary-input {
    background: #eaf7ef !important;
    font-weight: 800;
    color: #0f5132;
}

.checkbox-line {
    display: flex;
    align-items: center;
    gap: 8px;
    min-height: 30px;
    padding: 6px 8px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    background: #fff;
}

.checkbox-line input {
    width: auto !important;
    margin: 0;
}

@media (max-width: 1100px) {
    .form-grid-4 {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 900px) {
    .form-grid-3,
    .form-grid-2,
    .form-grid-4 {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="pay-form-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('payment') ?></span>
            Edit Pembayaran
        </h5>

        <a class="btn-vs btn-secondary" href="index.php?page=pembayaran">
            <span class="app-icon"><?= appIcon('back') ?></span>
            Kembali
        </a>
    </div>

    <form method="POST" action="modul/transaksi/update_bayar.php" id="formBayar">
      <div class="form-card">

    <!-- GROUP 1: Invoice & Payment Header -->
    <div class="form-section">
        <div class="form-section-title">Data Invoice & Pembayaran</div>
        <div class="form-section-body">
            <div class="form-grid-3">
                <div class="ff">
                    <label>No. Invoice</label>
                    <input type="text" name="invoice_no" id="invoice_no" value="<?= h($data['invoice_no']) ?>" class="readonly-highlight" readonly>
                </div>

                <div class="ff">
                    <label>No. Bayar</label>
                    <input type="text" name="bayar_no" value="<?= h($data['bayar_no']) ?>" class="readonly-highlight" readonly>
                </div>

                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Tanggal Bayar</label>
                    <input type="text" name="bayar_date" class="js-date-picker" value="<?= h(formatDateDisplay($data['bayar_date'])) ?>" autocomplete="off" required>
                </div>
            </div>
        </div>
    </div>

    <!-- GROUP 2: Customer -->
    <div class="form-section">
        <div class="form-section-title">Data Customer</div>
        <div class="form-section-body">
            <div class="form-grid-2">
                <div class="ff">
                    <label>Customer ID</label>
                    <input type="text" name="customer_id" value="<?= h($data['customer_id']) ?>" class="readonly-highlight" readonly required>
                </div>

                <div class="ff">
                    <label>Nama Customer</label>
                    <input type="text" name="customer_name" value="<?= h($data['customer_name']) ?>" class="readonly-highlight" readonly required>
                </div>

                <div class="ff field-full">
                    <label>Customer Address</label>
                    <textarea name="customer_address" rows="2" readonly><?= h($data['customer_address']) ?></textarea>
                </div>

                <div class="ff">
                    <label>Customer City</label>
                    <input type="text" name="customer_city" value="<?= h($data['customer_city']) ?>" readonly>
                </div>
            </div>
        </div>
    </div>

    <!-- GROUP 3: Payment Calculation -->
    <div class="form-section">
        <div class="form-section-title">Perhitungan Pembayaran</div>
        <div class="form-section-body">
            <div class="form-grid-4">
                <div class="ff">
                    <label>Jumlah Titip Uang</label>
                    <input type="text" id="saldo_titip_display" value="Rp <?= h(formatMoney($saldo_titip_available)) ?>" class="readonly-highlight" readonly>
                    <input type="hidden" name="saldo_titip" id="saldo_titip" value="<?= h($saldo_titip_available) ?>">
                </div>

                <div class="ff">
                    <label>Pakai Titip Uang</label>
                    <div class="checkbox-line">
                        <input type="checkbox" name="pakai_titip" id="pakai_titip" value="1" <?= $current_titip_amount > 0 ? 'checked' : '' ?>>
                        <span>Gunakan titip uang customer</span>
                    </div>
                </div>

                <div class="ff">
                    <label>Nominal Titip yang Dipakai</label>
                    <input type="text" name="nominal_titip" id="nominal_titip" value="<?= h(formatMoney($current_titip_amount)) ?>" autocomplete="off" <?= $current_titip_amount > 0 ? '' : 'disabled' ?>>
                </div>

                <div class="ff">
                    <label>Nominal Bayar Cash / Transfer</label>
                    <input type="text" name="nominal_bayar" id="nominal_bayar" value="<?= h(formatMoney($data['cash_amount'])) ?>" autocomplete="off" required>
                </div>

                <div class="ff">
                    <label>Sisa Invoice Sebelum Pembayaran Ini</label>
                    <input type="text" id="sisa_invoice_display" value="Rp <?= h(formatMoney($sisa_before_current)) ?>" class="readonly-highlight" readonly>
                    <input type="hidden" name="sisa_invoice" id="sisa_invoice" value="<?= h($sisa_before_current) ?>">
                    <input type="hidden" name="invoice_amount" id="invoice_amount" value="<?= h($invoice_amount) ?>">
                </div>

                <div class="ff">
                    <label>Total Bayar ke Invoice</label>
                    <input type="text" id="total_bayar_invoice_display" class="payment-summary-input" readonly>
                </div>

                <div class="ff field-full">
                    <div id="warningNominal" class="warning-box"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- GROUP 4: Payment Method & Remarks -->
    <div class="form-section">
        <div class="form-section-title">Metode Pembayaran & Keterangan</div>
        <div class="form-section-body">
            <div class="form-grid-2">
                <div class="ff">
                    <label>Keterangan</label>
                    <select name="keterangan" id="keterangan" required>
                        <option value="">-- Pilih --</option>
                        <option value="Cash" <?= $data['keterangan'] === 'Cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="Transfer" <?= $data['keterangan'] === 'Transfer' ? 'selected' : '' ?>>Transfer</option>
                    </select>
                </div>

                <div class="ff">
                    <label>Nama Bank</label>
                    <input type="text" name="bank_name" id="bank_name" value="<?= h($data['bank_name']) ?>" placeholder="Contoh: BCA / Mandiri / BRI">
                </div>

                <div class="ff field-full">
                    <label>Remarks</label>
                    <textarea name="remarks" rows="3"><?= h($data['remarks']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top:12px;display:flex;gap:6px;justify-content:flex-end;">
        <a href="index.php?page=pembayaran" class="btn-vs btn-secondary">
            <span class="app-icon"><?= appIcon('back') ?></span>
            Batal
        </a>
        <button type="submit" class="btn-vs btn-success">
            <span class="app-icon"><?= appIcon('save') ?></span>
            Update
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

function checkNominal() {
    const sisa = parseFloat($('#sisa_invoice').val()) || 0;
    const saldoTitip = parseFloat($('#saldo_titip').val()) || 0;

    const nominalCash = parseNumber($('#nominal_bayar').val());
    const nominalTitip = $('#pakai_titip').is(':checked') ? parseNumber($('#nominal_titip').val()) : 0;

    const totalBayarInvoice = nominalCash + nominalTitip;
    const warning = $('#warningNominal');

    $('#total_bayar_invoice_display').val('Rp ' + formatRupiah(totalBayarInvoice));

    warning.removeClass('warning-danger warning-info warning-ok').hide();

    if (nominalTitip > saldoTitip) {
        warning
            .addClass('warning-danger')
            .html('Nominal titip yang dipakai melebihi saldo titip uang. Saldo titip: Rp ' + formatRupiah(saldoTitip))
            .show();
        return;
    }

    if (totalBayarInvoice <= 0 || sisa <= 0) {
        return;
    }

    if (totalBayarInvoice > sisa) {
        warning
            .addClass('warning-danger')
            .html('Total bayar melebihi sisa invoice. Maksimal: Rp ' + formatRupiah(sisa))
            .show();
    } else if (totalBayarInvoice < sisa) {
        warning
            .addClass('warning-info')
            .html('Pembayaran kurang dari sisa invoice. Sisa setelah bayar: Rp ' + formatRupiah(sisa - totalBayarInvoice))
            .show();
    } else {
        warning
            .addClass('warning-ok')
            .html('Total bayar sama dengan sisa invoice.')
            .show();
    }
}

$(document).ready(function () {
    if (typeof flatpickr !== 'undefined') {
        flatpickr('.js-date-picker', {
            dateFormat: 'd-M-Y',
            allowInput: true,
            disableMobile: true
        });
    }

    checkNominal();

    $('#nominal_bayar').on('input keyup blur', function () {
        checkNominal();
    });

    $('#nominal_bayar').on('blur', function () {
        const nominal = parseNumber($(this).val());
        if (nominal > 0) {
            $(this).val(formatRupiah(nominal));
        }
    });

    $('#keterangan').on('change', function () {
        if ($(this).val() === 'Cash') {
            $('#bank_name').val('');
        }
    });

    $('#formBayar').on('submit', function (e) {
        const sisa = parseFloat($('#sisa_invoice').val()) || 0;
        const saldoTitip = parseFloat($('#saldo_titip').val()) || 0;
        const nominalCash = parseNumber($('#nominal_bayar').val());
        const nominalTitip = $('#pakai_titip').is(':checked') ? parseNumber($('#nominal_titip').val()) : 0;
        const totalBayarInvoice = nominalCash + nominalTitip;

        if (totalBayarInvoice <= 0) {
            alert('Total bayar harus lebih dari 0.');
            e.preventDefault();
            return false;
        }

        if (nominalTitip > saldoTitip) {
            alert('Nominal titip yang dipakai tidak boleh lebih dari saldo titip uang.');
            e.preventDefault();
            return false;
        }

        if (totalBayarInvoice > sisa) {
            alert('Total bayar tidak boleh lebih dari sisa invoice.');
            e.preventDefault();
            return false;
        }

        $('#nominal_bayar').val(nominalCash);
        $('#nominal_titip').prop('disabled', false).val(nominalTitip);
    });

    $('#pakai_titip').on('change', function () {
        if ($(this).is(':checked')) {
            $('#nominal_titip').prop('disabled', false);
        } else {
            $('#nominal_titip').prop('disabled', true).val('0,00');
        }
        checkNominal();
    });

    $('#nominal_titip').on('input keyup blur', function () {
        checkNominal();
    });

    $('#nominal_titip').on('blur', function () {
        const nominal = parseNumber($(this).val());
        $(this).val(formatRupiah(nominal));
    });
});
</script>