<?php
// modul/transaksi/edit_return.php
// Tampilan disamakan dengan add_return.php.
// Referensi Customer, Order, Shipping, dan Invoice dikunci saat edit.

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
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    return number_format((float)$value, 2, '.', ',');
}

$returnId = trim((string)($_GET['return_id'] ?? ''));

if ($returnId === '') {
    $_SESSION['alert'] = '<div class="alert alert-danger">Return ID tidak valid.</div>';
    echo "<script>window.location.href='index.php?page=return_invoice';</script>";
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM head_retur_invoice
     WHERE return_id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 's', $returnId);
mysqli_stmt_execute($stmt);
$header = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$header) {
    $_SESSION['alert'] = '<div class="alert alert-danger">Data retur tidak ditemukan.</div>';
    echo "<script>window.location.href='index.php?page=return_invoice';</script>";
    exit;
}

if (($header['approval_status'] ?? 'Pending') !== 'Pending') {
    $_SESSION['alert'] = '<div class="alert alert-danger">Data yang sudah diproses tidak dapat diedit.</div>';
    echo "<script>window.location.href='index.php?page=return_invoice';</script>";
    exit;
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM detail_retur_invoice
     WHERE return_id = ?
     ORDER BY id"
);
mysqli_stmt_bind_param($stmt, 's', $returnId);
mysqli_stmt_execute($stmt);
$details = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$returnDateDisplay = formatDateDisplay($header['return_date'] ?? '');
$invoiceDateDisplay = formatDateDisplay($header['invoice_date'] ?? '');
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>
.return-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', 'Consolas', 'Cascadia Code', monospace;
}
.return-wrap {
    background: #f0f2f5;
    padding: 12px;
    color: #212529;
    font-size: 11px;
}
.panel-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: stretch;
}
.return-panel {
    flex: 1;
    min-width: 0;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    overflow: hidden;
}
.return-panel-header {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    background: #e9ecef;
    border-bottom: 1px solid #dee2e6;
    color: #495057;
    font-size: 11px;
    font-weight: bold;
}
.return-panel-body {
    padding: 12px;
}
.ff {
    margin-bottom: 8px;
}
.ff:last-child {
    margin-bottom: 0;
}
.ff label {
    display: block;
    margin-bottom: 3px;
    color: #0d6efd;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}
.ff input,
.ff select,
.ff textarea {
    width: 100%;
    padding: 5px 8px;
    background: #fff;
    border: 1px solid #ced4da;
    border-radius: 3px;
    outline: none;
    font-size: 11px;
}
.ff input:focus,
.ff select:focus,
.ff textarea:focus {
    border-color: #86b7fe;
    box-shadow: 0 0 0 2px rgba(13,110,253,.12);
}
.ff input[readonly],
.ff textarea[readonly] {
    background: #e9ecef;
    color: #555;
}
.return-id-input {
    background: #e9ecef !important;
    color: #0d6efd !important;
    font-weight: bold;
}
.required {
    color: red;
    font-weight: bold;
}
.return-panel-full {
    margin-bottom: 10px;
    overflow: hidden;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}
.detail-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}
.small-note {
    color: #777;
    font-size: 10px;
}
.detail-table-wrap {
    max-height: 390px;
    overflow: auto;
}
.detail-table {
    width: 100%;
    min-width: 1510px;
    border-collapse: collapse;
    font-size: 11px;
}
.detail-table th {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 8px 6px;
    background: #e9ecef;
    border: 1px solid #dee2e6;
    font-size: 10px;
    text-align: center;
    text-transform: uppercase;
    white-space: nowrap;
}
.detail-table td {
    padding: 5px 6px;
    background: #fff;
    border: 1px solid #dee2e6;
    vertical-align: middle;
    white-space: nowrap;
}
.detail-table tbody tr:hover td {
    background: #f3f8ff;
}
.detail-table input[type="number"],
.detail-table input[type="text"] {
    width: 100%;
    padding: 4px 5px;
    background: #fff;
    border: 1px solid #ced4da;
    border-radius: 3px;
    outline: none;
    font-size: 11px;
}
.detail-table .inventory-name {
    min-width: 260px;
    white-space: normal;
    word-break: break-word;
}
.text-right {
    text-align: right;
}
.text-center {
    text-align: center;
}
.summary-panel {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 10px;
    padding: 12px;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}
.summary-box label {
    display: block;
    margin-bottom: 3px;
    color: #0d6efd;
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
}
.summary-box input {
    width: 100%;
    padding: 6px 8px;
    background: #f8f9fa;
    border: 1px solid #ced4da;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
    text-align: right;
}
.summary-box.highlight input {
    background: #e7f5ff;
    color: #0d6efd;
}
.actionbar {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 10px 0;
}
.btn-vs {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 12px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    font-size: 11px;
    font-weight: bold;
    line-height: 1.25;
    text-decoration: none;
}
.btn-vs:hover {
    filter: brightness(.95);
    text-decoration: none;
}
.btn-success {
    background: #198754;
    color: #fff;
}
.btn-secondary {
    background: #6c757d;
    color: #fff;
}
@media (max-width: 1000px) {
    .panel-row {
        flex-direction: column;
    }
    .summary-panel {
        grid-template-columns: 1fr 1fr;
    }
}
@media (max-width: 600px) {
    .summary-panel {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="return-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <form method="POST" action="index.php?page=update_return" id="editReturnForm">
        <input type="hidden" name="return_id" value="<?= h($header['return_id']) ?>">

        <div class="panel-row">
            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-rotate-left"></i>
                    Edit Return Information
                </div>

                <div class="return-panel-body">
                    <div class="ff">
                        <label>Sales Return ID</label>
                        <input type="text"
                               class="return-id-input"
                               value="<?= h($header['return_id']) ?>"
                               readonly>
                    </div>

                    <div class="ff">
                        <label>Return Date <span class="required">*</span></label>
                        <input type="text"
                               name="return_date"
                               class="datepicker"
                               value="<?= h($returnDateDisplay) ?>"
                               required
                               autocomplete="off">
                    </div>

                    <div class="ff">
                        <label>Reason Return <span class="required">*</span></label>
                        <input type="text"
                               name="reason_return"
                               value="<?= h($header['reason_return']) ?>"
                               maxlength="255"
                               required>
                    </div>

                    <div class="ff">
                        <label>Remarks Return</label>
                        <textarea name="remarks_return"
                                  rows="3"
                                  placeholder="Keterangan tambahan retur..."><?= h($header['remarks_return']) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-file-lines"></i>
                    Edit Sales Order & Shipping
                </div>

                <div class="return-panel-body">
                    <div class="ff">
                        <label>Sales Order</label>
                        <input type="text"
                               value="<?= h($header['order_no']) ?>"
                               readonly>
                    </div>

                    <div class="ff">
                        <label>Shipping No.</label>
                        <input type="text"
                               value="<?= h($header['shipping_no']) ?>"
                               readonly>
                    </div>

                    <div class="ff">
                        <label>Invoice No.</label>
                        <input type="text"
                               value="<?= h($header['invoice_no']) ?>"
                               readonly>
                    </div>

                    <div class="ff">
                        <label>Invoice Date</label>
                        <input type="text"
                               value="<?= h($invoiceDateDisplay) ?>"
                               readonly>
                    </div>
                </div>
            </div>

            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-building-user"></i>
                    Edit Customer & Invoice Information
                </div>

                <div class="return-panel-body">
                    <div class="ff">
                        <label>Customer Name</label>
                        <input type="text"
                               value="<?= h($header['customer_name']) ?>"
                               readonly>
                    </div>

                    <div class="ff">
                        <label>Currency</label>
                        <input type="text"
                               value="<?= h($header['currency'] ?: 'IDR') ?>"
                               readonly>
                    </div>

                    <div class="ff">
                        <label>Invoice Subtotal</label>
                        <input type="text"
                               value="<?= h(formatMoney($header['subtotal'])) ?>"
                               style="text-align:right;"
                               readonly>
                    </div>

                    <div class="ff">
                        <label>Grand Total</label>
                        <input type="text"
                               value="<?= h(formatMoney($header['grand_total'])) ?>"
                               style="text-align:right;font-weight:bold;"
                               readonly>
                    </div>
                </div>
            </div>
        </div>

        <div class="return-panel-full">
            <div class="return-panel-header">
                <i class="fa fa-boxes-stacked"></i>
                Edit Detail Inventory Return
            </div>

            <div class="detail-toolbar">
                <span class="small-note">
                    <i class="fa fa-info-circle"></i>
                    Qty Return dan Qty Pack Return saling menyesuaikan berdasarkan rasio Shipping.
                    Return Subtotal dihitung dari Price × Qty Pack Return.
                </span>
            </div>

            <div class="detail-table-wrap">
                <table class="detail-table" id="itemTable">
                    <thead>
                        <tr>
                            <th style="width:125px;">Inventory ID</th>
                            <th>Inventory Name</th>
                            <th style="width:105px;">Original Qty</th>
                            <th style="width:105px;">Qty Return</th>
                            <th style="width:70px;">UoM</th>
                            <th style="width:120px;">Original Qty Pack</th>
                            <th style="width:120px;">Qty Pack Return</th>
                            <th style="width:85px;">UoM Pack</th>
                            <th style="width:90px;">UoM Detail</th>
                            <th style="width:120px;">Price</th>
                            <th style="width:140px;">Return Subtotal</th>
                            <th style="width:210px;">Remarks</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php if (!$details): ?>
                        <tr>
                            <td colspan="12"
                                style="text-align:center;color:#777;padding:15px;">
                                Detail inventory retur tidak ditemukan.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($details as $index => $detail): ?>
                            <tr class="item-row"
                                data-original-qty="<?= h($detail['original_quantity']) ?>"
                                data-original-pack="<?= h($detail['original_quantity_pack']) ?>"
                                data-price="<?= h($detail['price']) ?>"
                                data-uom-pack="<?= h(strtoupper((string)$detail['uom_pack'])) ?>"
                                data-return-subtotal="<?= h($detail['return_subtotal']) ?>">

                                <td>
                                    <code><?= h($detail['inventory_id']) ?></code>
                                    <input type="hidden"
                                           name="items[<?= $index ?>][id]"
                                           value="<?= (int)$detail['id'] ?>">
                                </td>

                                <td class="inventory-name">
                                    <?= h($detail['inventory_name']) ?>
                                </td>

                                <td class="text-right">
                                    <?= h(number_format((float)$detail['original_quantity'], 4, '.', ',')) ?>
                                </td>

                                <td>
                                    <input type="number"
                                           name="items[<?= $index ?>][return_quantity]"
                                           class="return-qty"
                                           step="0.0001"
                                           min="0"
                                           max="<?= h($detail['original_quantity']) ?>"
                                           value="<?= h($detail['return_quantity']) ?>">
                                </td>

                                <td class="text-center">
                                    <?= h($detail['uom']) ?>
                                </td>

                                <td class="text-right">
                                    <?= h(number_format((float)$detail['original_quantity_pack'], 4, '.', ',')) ?>
                                </td>

                                <td>
                                    <input type="number"
                                           name="items[<?= $index ?>][return_quantity_pack]"
                                           class="return-pack"
                                           step="0.0001"
                                           min="0"
                                           max="<?= h($detail['original_quantity_pack']) ?>"
                                           value="<?= h($detail['return_quantity_pack']) ?>">
                                </td>

                                <td class="text-center">
                                    <?= h($detail['uom_pack']) ?>
                                </td>

                                <td class="text-center">
                                    <?= h($detail['uom_detail']) ?>
                                </td>

                                <td class="text-right">
                                    Rp <?= h(formatMoney($detail['price'])) ?>
                                </td>

                                <td class="text-right">
                                    <b>
                                        Rp
                                        <span class="return-subtotal-display">
                                            <?= h(formatMoney($detail['return_subtotal'])) ?>
                                        </span>
                                    </b>
                                </td>

                                <td>
                                    <input type="text"
                                           name="items[<?= $index ?>][remarks_detail]"
                                           maxlength="255"
                                           value="<?= h($detail['remarks_detail']) ?>"
                                           placeholder="Remarks...">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="summary-panel">
            <div class="summary-box">
                <label>Down Payment</label>
                <input type="text"
                       value="<?= h(formatMoney($header['down_payment'])) ?>"
                       readonly>
            </div>

            <div class="summary-box">
                <label>Titip</label>
                <input type="text"
                       value="<?= h(formatMoney($header['titip_applied'])) ?>"
                       readonly>
            </div>

            <div class="summary-box">
                <label>Payment Balance</label>
                <input type="text"
                       id="payment_balance_display"
                       value="<?= h(formatMoney($header['payment_balance'])) ?>"
                       readonly>
            </div>

            <div class="summary-box highlight">
                <label>Return Amount</label>
                <input type="text"
                       id="return_amount_display"
                       value="<?= h(formatMoney($header['return_amount'])) ?>"
                       readonly>
            </div>

            <div class="summary-box highlight">
                <label>Balance After Return</label>
                <input type="text"
                       id="balance_after_display"
                       value="<?= h(formatMoney($header['remaining_invoice_balance'])) ?>"
                       readonly>
            </div>
        </div>

        <div class="actionbar">
            <button type="button"
                    class="btn-vs btn-secondary"
                    onclick="window.location.href='index.php?page=return_invoice'">
                <i class="fa fa-times"></i>
                Batal / Kembali
            </button>

            <button type="submit"
                    class="btn-vs btn-success"
                    id="btnUpdate">
                <i class="fa fa-save"></i>
                Update Sales Return
            </button>
        </div>
    </form>
</div>

<script>
function trimDecimal(value) {
    return Number(value || 0)
        .toFixed(4)
        .replace(/\.?0+$/, '');
}

function formatMoney(value) {
    return Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

var paymentBalance = <?= json_encode((float)$header['payment_balance']) ?>;

document.querySelectorAll('.item-row').forEach(function(row) {
    var qtyInput = row.querySelector('.return-qty');
    var packInput = row.querySelector('.return-pack');

    qtyInput.addEventListener('input', function() {
        syncFromQty(row);
    });

    packInput.addEventListener('input', function() {
        syncFromPack(row);
    });
});

function syncFromQty(row) {
    var qtyInput = row.querySelector('.return-qty');
    var packInput = row.querySelector('.return-pack');

    var originalQty = Number(row.dataset.originalQty || 0);
    var originalPack = Number(row.dataset.originalPack || 0);
    var uomPack = String(row.dataset.uomPack || '').toUpperCase();

    var qty = Math.max(
        0,
        Math.min(Number(qtyInput.value || 0), originalQty)
    );

    var pack = 0;

    if (uomPack === 'KG') {
        pack = qty;
    } else if (originalQty > 0 && originalPack > 0) {
        pack = qty / (originalQty / originalPack);
    }

    if (originalPack > 0) {
        pack = Math.min(pack, originalPack);
    }

    qtyInput.value = trimDecimal(qty);
    packInput.value = trimDecimal(pack);

    calculateRow(row);
}

function syncFromPack(row) {
    var qtyInput = row.querySelector('.return-qty');
    var packInput = row.querySelector('.return-pack');

    var originalQty = Number(row.dataset.originalQty || 0);
    var originalPack = Number(row.dataset.originalPack || 0);
    var uomPack = String(row.dataset.uomPack || '').toUpperCase();

    var pack = Math.max(
        0,
        Math.min(Number(packInput.value || 0), originalPack)
    );

    var qty = 0;

    if (uomPack === 'KG') {
        qty = pack;
    } else if (originalQty > 0 && originalPack > 0) {
        qty = pack * (originalQty / originalPack);
    }

    qty = Math.min(qty, originalQty);

    qtyInput.value = trimDecimal(qty);
    packInput.value = trimDecimal(pack);

    calculateRow(row);
}

function calculateRow(row) {
    var price = Number(row.dataset.price || 0);
    var qtyPackReturn = Number(
        row.querySelector('.return-pack').value || 0
    );

    var subtotal = price * qtyPackReturn;

    row.dataset.returnSubtotal = subtotal.toFixed(2);

    row.querySelector('.return-subtotal-display').textContent =
        formatMoney(subtotal);

    calculateTotal();
}

function calculateTotal() {
    var total = 0;

    document.querySelectorAll('.item-row').forEach(function(row) {
        total += Number(row.dataset.returnSubtotal || 0);
    });

    document.getElementById('return_amount_display').value =
        formatMoney(total);

    document.getElementById('balance_after_display').value =
        formatMoney(Math.max(0, paymentBalance - total));
}

document.getElementById('editReturnForm').addEventListener('submit', function(event) {
    var hasQuantity = Array.from(
        document.querySelectorAll('.return-qty')
    ).some(function(input) {
        return Number(input.value || 0) > 0;
    });

    if (!hasQuantity) {
        event.preventDefault();
        alert('Minimal satu inventory harus memiliki Qty Return lebih dari 0.');
        return false;
    }

    var returnDate = document.querySelector(
        'input[name="return_date"]'
    );

    if (returnDate && returnDate.value) {
        var parsedDate = flatpickr.parseDate(
            returnDate.value,
            'd-M-Y'
        );

        if (parsedDate) {
            returnDate.value = flatpickr.formatDate(
                parsedDate,
                'Y-m-d'
            );
        }
    }

    document.getElementById('btnUpdate')
        .disabled = true;

    document.getElementById('btnUpdate').innerHTML =
        '<i class="fa fa-spinner fa-spin"></i> Updating...';

    return true;
});

if (typeof flatpickr !== 'undefined') {
    flatpickr('.datepicker', {
        dateFormat: 'd-M-Y',
        allowInput: true,
        disableMobile: true
    });
}

calculateTotal();
</script>
