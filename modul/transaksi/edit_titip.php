<?php
// modul/transaksi/edit_titip.php

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

$titip_no = trim((string)($_GET['titip_no'] ?? ''));

if ($titip_no === '') {
    echo "<script>alert('No. Titip tidak ditemukan.'); window.location.href='index.php?page=titip_uang';</script>";
    exit;
}

$sql = "
    SELECT *
    FROM head_titip
    WHERE titip_no = ?
    LIMIT 1
";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $titip_no);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$data = mysqli_fetch_assoc($res);
mysqli_stmt_close($stmt);

if (!$data) {
    echo "<script>alert('Data titip uang tidak ditemukan.'); window.location.href='index.php?page=titip_uang';</script>";
    exit;
}

if ((float)$data['used_amount'] > 0) {
    $_SESSION['alert'] = "
        <div style='padding:10px;margin-bottom:10px;border-radius:4px;background:#fff3cd;color:#664d03;border:1px solid #ffecb5;'>
            Data titip ini sudah pernah digunakan. Jumlah titip tidak disarankan diubah.
        </div>
    ";
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

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
    min-height: 30px;
}
.btn-success { background: #198754; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
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
        <h5 style="margin:0;">Edit Titip Uang</h5>

        <a class="btn-vs btn-secondary" href="index.php?page=titip_uang">Kembali</a>
    </div>

    <form method="POST" action="modul/transaksi/update_titip.php" id="formTitip">
        <div class="form-card">
            <div class="form-grid">
                <div class="ff">
                    <label>No. Titip</label>
                    <input type="text" name="titip_no" value="<?= h($data['titip_no']) ?>" readonly>
                </div>

                <div class="ff">
                    <label>Tanggal Titip</label>
                    <input type="text" name="titip_date" class="js-date-picker" value="<?= h(formatDateDisplay($data['titip_date'])) ?>" required>
                </div>

                <div class="ff">
                    <label>Customer ID</label>
                    <input type="text" name="customer_id" value="<?= h($data['customer_id']) ?>" readonly>
                </div>

                <div class="ff">
                    <label>Nama Customer</label>
                    <input type="text" name="customer_name" value="<?= h($data['customer_name']) ?>" readonly>
                </div>

                <div class="ff" style="grid-column:1 / -1;">
                    <label>Customer Address</label>
                    <textarea name="customer_address" rows="2" readonly><?= h($data['customer_address']) ?></textarea>
                </div>

                <div class="ff">
                    <label>Customer City</label>
                    <input type="text" name="customer_city" value="<?= h($data['customer_city']) ?>" readonly>
                </div>

                <div class="ff">
                    <label>Jumlah Titip</label>
                    <input type="text" name="total_titip" id="total_titip" value="<?= h(formatMoney($data['total_titip'])) ?>" required>
                    <input type="hidden" id="used_amount" value="<?= h($data['used_amount']) ?>">
                </div>

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
                    <input type="text" name="bank_name" id="bank_name" value="<?= h($data['bank_name']) ?>">
                </div>

                <div class="ff">
                    <label>Terpakai</label>
                    <input type="text" value="Rp <?= h(formatMoney($data['used_amount'])) ?>" readonly>
                </div>

                <div class="ff">
                    <label>Sisa Titip</label>
                    <input type="text" value="Rp <?= h(formatMoney($data['balance_amount'])) ?>" readonly>
                </div>

                <div class="ff" style="grid-column:1 / -1;">
                    <label>Remarks</label>
                    <textarea name="remarks" rows="3"><?= h($data['remarks']) ?></textarea>
                </div>
            </div>

            <div style="margin-top:12px;display:flex;gap:6px;justify-content:flex-end;">
                <a href="index.php?page=titip_uang" class="btn-vs btn-secondary">Batal</a>
                <button type="submit" class="btn-vs btn-success">Update</button>
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

$(document).ready(function () {
    if (typeof flatpickr !== 'undefined') {
        flatpickr('.js-date-picker', {
            dateFormat: 'd-M-Y',
            allowInput: true,
            disableMobile: true
        });
    }

    $('#keterangan').on('change', function () {
        if ($(this).val() === 'Cash') {
            $('#bank_name').val('');
        }
    });

    $('#formTitip').on('submit', function (e) {
        const total = parseNumber($('#total_titip').val());
        const used = parseFloat($('#used_amount').val()) || 0;

        if (total <= 0) {
            alert('Jumlah titip harus lebih dari 0.');
            e.preventDefault();
            return false;
        }

        if (total < used) {
            alert('Jumlah titip tidak boleh lebih kecil dari nominal yang sudah terpakai.');
            e.preventDefault();
            return false;
        }

        $('#total_titip').val(total);
    });
});
</script>