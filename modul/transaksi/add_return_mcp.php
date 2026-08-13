<?php
// modul/transaksi/add_return_mcp.php
// Sales Return CP-MCP
// Semua data transaksi diinput manual oleh Finance,
// kecuali Customer Name yang dipilih dari daftar customer seperti add_return.php.

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

/*
|--------------------------------------------------------------------------
| Customer
|--------------------------------------------------------------------------
| Disamakan dengan add_return.php:
| customer dipilih dari customer yang pernah memiliki invoice.
|--------------------------------------------------------------------------
*/
$customerSql = "
    SELECT DISTINCT
        hi.customer_id,
        hi.customer_name
    FROM head_invoice hi
    INNER JOIN det_invoice di
        ON di.invoice_no = hi.invoice_no
    INNER JOIN hed_shipping hs
        ON hs.shipping_no = di.shipping_no
    WHERE COALESCE(hi.status, 'Open') <> 'Cancelled'
      AND COALESCE(hi.customer_id, '') <> ''
    ORDER BY hi.customer_name ASC, hi.customer_id ASC
";

$customerRs = mysqli_query($conn, $customerSql);

if (!$customerRs) {
    die('Gagal mengambil daftar Customer: ' . mysqli_error($conn));
}

/*
|--------------------------------------------------------------------------
| MASTER UOM
|--------------------------------------------------------------------------
| Dipakai untuk UoM, UoM Pack, dan UoM Detail.
| Hanya mengambil unit yang aktif.
|--------------------------------------------------------------------------
*/
$uomSql = "
    SELECT DISTINCT unit
    FROM m_uom
    WHERE COALESCE(is_active, 'Checked') = 'Checked'
      AND COALESCE(unit, '') <> ''
    ORDER BY unit ASC
";

$uomRs = mysqli_query($conn, $uomSql);

if (!$uomRs) {
    die('Gagal mengambil master UoM: ' . mysqli_error($conn));
}

$uomOptions = [];

while ($uomRow = mysqli_fetch_assoc($uomRs)) {
    $unit = trim((string)($uomRow['unit'] ?? ''));

    if ($unit !== '') {
        $uomOptions[] = $unit;
    }
}

/*
|--------------------------------------------------------------------------
| AUTO NUMBERING CP-MCP
|--------------------------------------------------------------------------
| Sales Return ID    : nomor urut per bulan/tahun, contoh 16/CP-MCP/VIII/2026
| Internal Invoice ID: nomor internal otomatis, contoh CP-MCP/INV/2026/00001
| Inventory ID       : nomor unik otomatis, contoh MCP-INV/2026-000001
|--------------------------------------------------------------------------
*/
function monthRoman($month) {
    $romans = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV',
        5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII',
        9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'
    ];

    return $romans[(int)$month] ?? '';
}

$currentYear = date('Y');
$currentMonthRoman = monthRoman((int)date('n'));

$returnNoSql = "
    SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(return_id, '/', 1) AS UNSIGNED)), 0) AS max_no
    FROM head_retur_invoice
    WHERE return_id LIKE ?
";
$returnPattern = '%/CP-MCP/' . $currentMonthRoman . '/' . $currentYear;
$returnNoStmt = mysqli_prepare($conn, $returnNoSql);
if (!$returnNoStmt) {
    die('Gagal generate Sales Return ID: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($returnNoStmt, 's', $returnPattern);
mysqli_stmt_execute($returnNoStmt);
$returnNoRow = mysqli_fetch_assoc(mysqli_stmt_get_result($returnNoStmt));
mysqli_stmt_close($returnNoStmt);

$nextReturnNo = ((int)($returnNoRow['max_no'] ?? 0)) + 1;
$autoReturnId = $nextReturnNo . '/CP-MCP/' . $currentMonthRoman . '/' . $currentYear;


/*
|--------------------------------------------------------------------------
| INTERNAL INVOICE MCP
|--------------------------------------------------------------------------
| Nomor ini tidak ditampilkan ke user. Dipakai agar return MCP memiliki
| invoice internal yang valid di head_invoice dan memenuhi foreign key.
|--------------------------------------------------------------------------
*/
$invoicePrefix = 'CP-MCP/INV/' . $currentYear . '/';
$invoicePattern = $invoicePrefix . '%';

$invoiceNoSql = "
    SELECT COALESCE(
        MAX(
            CAST(
                SUBSTRING(invoice_no, LENGTH(?) + 1)
                AS UNSIGNED
            )
        ),
        0
    ) AS max_no
    FROM head_invoice
    WHERE invoice_no LIKE ?
";

$invoiceNoStmt = mysqli_prepare($conn, $invoiceNoSql);

if (!$invoiceNoStmt) {
    die('Gagal generate Internal Invoice No: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param(
    $invoiceNoStmt,
    'ss',
    $invoicePrefix,
    $invoicePattern
);

mysqli_stmt_execute($invoiceNoStmt);

$invoiceNoRow = mysqli_fetch_assoc(
    mysqli_stmt_get_result($invoiceNoStmt)
);

mysqli_stmt_close($invoiceNoStmt);

$nextInvoiceNo = ((int)($invoiceNoRow['max_no'] ?? 0)) + 1;
$autoInvoiceNo = $invoicePrefix . str_pad(
    (string)$nextInvoiceNo,
    5,
    '0',
    STR_PAD_LEFT
);

$inventoryPrefix = 'MCP-INV/' . $currentYear . '-';
$inventoryPattern = $inventoryPrefix . '%';
$inventoryNoSql = "
    SELECT COALESCE(MAX(CAST(SUBSTRING(inventory_id, LENGTH(?) + 1) AS UNSIGNED)), 0) AS max_no
    FROM detail_retur_invoice
    WHERE inventory_id LIKE ?
      AND return_id LIKE '%/CP-MCP/%'
";
$inventoryNoStmt = mysqli_prepare($conn, $inventoryNoSql);
if (!$inventoryNoStmt) {
    die('Gagal generate Inventory ID: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($inventoryNoStmt, 'ss', $inventoryPrefix, $inventoryPattern);
mysqli_stmt_execute($inventoryNoStmt);
$inventoryNoRow = mysqli_fetch_assoc(mysqli_stmt_get_result($inventoryNoStmt));
mysqli_stmt_close($inventoryNoStmt);

$nextInventoryNo = ((int)($inventoryNoRow['max_no'] ?? 0)) + 1;

$todayDisplay = formatDateDisplay(date('Y-m-d'));
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet">

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>

<style>
.return-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI','Consolas','Cascadia Code',monospace;
}

.return-wrap {
    background: #f0f2f5;
    padding: 12px;
    color: #212529;
    font-size: 11px;
}

.mcp-banner {
    background: linear-gradient(135deg,#ffc107,#ffca2c);
    border: 1px solid #e0a800;
    color: #332701;
    padding: 8px 12px;
    margin-bottom: 10px;
    border-radius: 4px;
    display: flex;
    align-items: center;
    gap: 7px;
    font-weight: 700;
}

.panel-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
    align-items: stretch;
}

.return-panel {
    flex: 1;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    overflow: hidden;
    min-width: 0;
}

.return-panel-header {
    background: #e9ecef;
    border-bottom: 1px solid #dee2e6;
    padding: 6px 12px;
    font-size: 11px;
    font-weight: bold;
    color: #495057;
    display: flex;
    align-items: center;
    gap: 6px;
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
    background: #fff;
    border: 1px solid #ced4da;
    border-radius: 3px;
    font-size: 11px;
    padding: 5px 8px;
    outline: none;
}

.ff input:focus,
.ff select:focus,
.ff textarea:focus {
    border-color: #86b7fe;
    box-shadow: 0 0 0 2px rgba(13,110,253,.12);
}

.required {
    color: red;
    font-weight: bold;
}

.return-id-input {
    font-weight: bold;
    color: #0d6efd;
    background: #e9ecef !important;
}

.auto-id-input {
    background: #e9ecef !important;
    color: #495057;
    font-weight: 700;
}

.return-panel-full {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 10px;
}

.detail-toolbar {
    background: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    padding: 8px 12px;
    display: flex;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
}

.btn-vs {
    padding: 6px 12px;
    font-size: 11px;
    font-weight: bold;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    text-decoration: none;
    line-height: 1.25;
}

.btn-vs:hover {
    filter: brightness(.95);
    text-decoration: none;
}

.btn-primary { background:#0d6efd; color:#fff; }
.btn-success { background:#198754; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-danger { background:#dc3545; color:#fff; }
.btn-warning { background:#ffc107; color:#000; }

.btn-row-delete {
    width: 30px;
    height: 28px;
    padding: 0;
}

.small-note {
    font-size: 10px;
    color: #777;
}

.detail-table-wrap {
    max-height: 430px;
    overflow: auto;
}

.detail-table {
    width: 100%;
    min-width: 1550px;
    border-collapse: collapse;
    font-size: 11px;
}

.detail-table th {
    background: #e9ecef;
    padding: 8px 6px;
    border: 1px solid #dee2e6;
    position: sticky;
    top: 0;
    z-index: 2;
    font-size: 10px;
    text-transform: uppercase;
    text-align: center;
    white-space: nowrap;
}

.detail-table td {
    padding: 5px 6px;
    border: 1px solid #dee2e6;
    background: #fff;
    vertical-align: middle;
    white-space: nowrap;
}

.detail-table tbody tr:hover td {
    background: #f3f8ff;
}

.detail-table input,
.detail-table select {
    width: 100%;
    border: 1px solid #ced4da;
    background: #fff;
    font-size: 11px;
    padding: 4px 5px;
    outline: none;
    border-radius: 3px;
}

.inventory-name-input {
    min-width: 240px;
}

.remarks-input {
    min-width: 180px;
}

.text-right {
    text-align: right;
}

.text-center {
    text-align: center;
}

.summary-panel {
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: 10px;
    padding: 12px;
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    margin-bottom: 10px;
}

.summary-box label {
    display: block;
    font-size: 10px;
    font-weight: bold;
    color: #0d6efd;
    text-transform: uppercase;
    margin-bottom: 3px;
}

.summary-box input {
    width: 100%;
    border: 1px solid #ced4da;
    border-radius: 3px;
    padding: 6px 8px;
    font-size: 12px;
    font-weight: bold;
    text-align: right;
    background: #fff;
}

.summary-box.readonly input {
    background: #e9ecef;
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

.select2-container--default .select2-selection--single {
    height: 28px!important;
    padding: 2px 0!important;
    font-size: 11px!important;
    border: 1px solid #ced4da!important;
    border-radius: 3px!important;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 22px!important;
    font-size: 11px!important;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 26px!important;
}

@media(max-width:1000px) {
    .panel-row {
        flex-direction: column;
    }

    .summary-panel {
        grid-template-columns: 1fr 1fr;
    }
}

@media(max-width:600px) {
    .summary-panel {
        grid-template-columns: 1fr;
    }

    .actionbar {
        flex-direction: column-reverse;
    }

    .actionbar .btn-vs {
        width: 100%;
    }
}
</style>

<div class="return-wrap">

    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="mcp-banner">
        <i class="fa fa-pen-to-square"></i>
        Sales Return CP-MCP — nomor internal dibuat otomatis. Finance cukup mengisi Sales Order, Shipping No. MCP, Customer, dan detail retur.
    </div>

    <form method="POST" action="index.php?page=save_return_mcp" id="returnMcpForm">

        <!-- Internal Invoice MCP: hidden, tetap dicek/generate ulang saat save -->
        <input
            type="hidden"
            name="invoice_no"
            value="<?= h($autoInvoiceNo) ?>"
        >

        <div class="panel-row">

            <!-- RETURN INFORMATION -->
            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-rotate-left"></i>
                    Return Information
                </div>

                <div class="return-panel-body">
                    <div class="ff">
                        <label>
                            Sales Return ID <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            name="return_id"
                            class="return-id-input"
                            value="<?= h($autoReturnId) ?>"
                            
                            required
                            maxlength="50"
                        >
                        <div class="small-note" style="margin-top:4px;">
                            Nomor dibuat otomatis berdasarkan urutan CP-MCP bulan berjalan.
                        </div>
                    </div>

                    <div class="ff">
                        <label>
                            Return Date <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            name="return_date"
                            class="datepicker"
                            value="<?= h($todayDisplay) ?>"
                            required
                            autocomplete="off"
                        >
                    </div>

                    <div class="ff">
                        <label>
                            Reason Return <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            name="reason_return"
                            required
                            maxlength="255"
                            placeholder="Input alasan retur..."
                        >
                    </div>

                    <input
                        type="hidden"
                        name="remarks_return"
                        id="remarks_return"
                        value=""
                    >
                </div>
            </div>

            <!-- TRANSACTION REFERENCE -->
            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-file-lines"></i>
                    Sales Order & Shipping
                </div>

                <div class="return-panel-body">
                    <div class="ff">
                        <label>
                            Sales Order <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            name="order_no"
                            required
                            maxlength="50"
                            placeholder="Input Sales Order manual"
                        >
                    </div>

                    <div class="ff">
                        <label>
                            Shipping No. MCP <span class="required">*</span>
                        </label>
                        <input
                            type="text"
                            name="shipping_no"
                            required
                            maxlength="50"
                            placeholder="Input Shipping No. asli dari MCP"
                        >
                    </div>
                </div>
            </div>

            <!-- CUSTOMER -->
            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-building-user"></i>
                    Customer Information
                </div>

                <div class="return-panel-body">

                    <div class="ff">
                        <label>
                            Customer Name <span class="required">*</span>
                        </label>

                        <select
                            name="customer_ref"
                            id="customer_ref"
                            required
                        >
                            <option value="">
                                -- Pilih Customer --
                            </option>

                            <?php while ($customer = mysqli_fetch_assoc($customerRs)): ?>
                                <option
                                    value="<?= h($customer['customer_id']) ?>"
                                    data-customer-name="<?= h($customer['customer_name']) ?>"
                                >
                                    <?= h($customer['customer_name']) ?>
                                    |
                                    <?= h($customer['customer_id']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>

                        <input
                            type="hidden"
                            name="customer_name"
                            id="customer_name"
                            value=""
                        >
                    </div>

                    <div class="ff">
                        <label>Currency</label>
                        <input
                            type="text"
                            name="currency"
                            value="IDR"
                            maxlength="10"
                        >
                    </div>
                </div>
            </div>

        </div>

        <!-- DETAIL INVENTORY -->
        <div class="return-panel-full">
            <div class="return-panel-header">
                <i class="fa fa-boxes-stacked"></i>
                Detail Inventory Return
            </div>

            <div class="detail-toolbar">
                <span class="small-note">
                    <i class="fa fa-info-circle"></i>
                    Inventory ID dibuat otomatis oleh sistem dan disimpan secara hidden. Inventory Name, quantity, UoM, dan harga retur diinput oleh Finance. Return Subtotal otomatis = Price × Qty Pack Return.
                </span>

                <button
                    type="button"
                    class="btn-vs btn-primary"
                    id="addItemButton"
                >
                    <i class="fa fa-plus"></i>
                    Tambah Inventory
                </button>
            </div>

            <div class="detail-table-wrap">
                <table class="detail-table" id="itemTable">
                    <thead>
                        <tr>
                            <th style="width:45px;">No</th>
                            <th>Inventory Name</th>
                            <th style="width:95px;">Qty Return</th>
                            <th style="width:70px;">UoM</th>
                            <th style="width:105px;">Qty Pack Return</th>
                            <th style="width:85px;">UoM Pack</th>
                            <th style="width:105px;">Qty Detail Return</th>
                            <th style="width:90px;">UoM Detail</th>
                            <th style="width:100px;">Price Unit</th>
                            <th style="width:110px;">Price</th>
                            <th style="width:130px;">Return Subtotal</th>
                            <th>Remarks</th>
                            <th style="width:48px;">Aksi</th>
                        </tr>
                    </thead>

                    <tbody></tbody>
                </table>
            </div>
        </div>

        <!-- PAYMENT / SUMMARY -->
        <div class="summary-panel">

            <div class="summary-box">
                <label>Down Payment</label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="down_payment"
                    value="0"
                >
            </div>

            <div class="summary-box">
                <label>Titip</label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="titip_applied"
                    value="0"
                >
            </div>

            <div class="summary-box">
                <label>Payment Balance</label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="payment_balance"
                    value="0"
                >
            </div>

            <div class="summary-box readonly">
                <label>Calculated Detail Return</label>
                <input
                    type="text"
                    id="calculated_return_display"
                    value="0.00"
                    readonly
                >
            </div>

            <div class="summary-box highlight">
                <label>
                    Return Amount <span class="required">*</span>
                </label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="return_amount"
                    id="return_amount"
                    value="0"
                    required
                >
            </div>

            <div class="summary-box highlight">
                <label>
                    Balance After Return <span class="required">*</span>
                </label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="remaining_invoice_balance"
                    id="remaining_invoice_balance"
                    value="0"
                    required
                >
            </div>

        </div>

        <div class="actionbar">
            <button
                type="button"
                class="btn-vs btn-secondary"
                onclick="window.location.href='index.php?page=return_invoice'"
            >
                <i class="fa fa-times"></i>
                Batal / Kembali
            </button>

            <button
                type="submit"
                class="btn-vs btn-success"
                id="saveButton"
            >
                <i class="fa fa-save"></i>
                Simpan Sales Return CP-MCP
            </button>
        </div>

    </form>
</div>

<script>

function syncRemarksReturn() {
    var shippingInput = document.querySelector('input[name="shipping_no"]');
    var remarksInput = document.getElementById('remarks_return');

    if (!shippingInput || !remarksInput) return;

    var externalShipping = String(shippingInput.value || '').trim();
    remarksInput.value = externalShipping !== ''
        ? 'Shipping No. MCP: ' + externalShipping
        : '';
}

document.addEventListener('input', function(event) {
    if (event.target && event.target.name === 'shipping_no') {
        syncRemarksReturn();
    }
});

function escapeHtml(value) {
    if (value === null || value === undefined) return '';

    return String(value).replace(/[&<>"']/g, function(m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[m];
    });
}

function money(value) {
    return Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

var masterUom = <?= json_encode(
    $uomOptions,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

function uomOptionsHtml(placeholder) {
    var html = '<option value="">' + escapeHtml(placeholder || '-- Pilih UoM --') + '</option>';

    masterUom.forEach(function(unit) {
        html += '<option value="' + escapeHtml(unit) + '">' + escapeHtml(unit) + '</option>';
    });

    return html;
}

var inventoryPrefix = <?= json_encode($inventoryPrefix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var nextInventoryNo = <?= (int)$nextInventoryNo ?>;

function generateInventoryId() {
    var id = inventoryPrefix + String(nextInventoryNo).padStart(6, '0');
    nextInventoryNo++;
    return id;
}

var itemIndex = 0;

function itemRow(index) {
    var autoInventoryId = generateInventoryId();

    return `
        <tr class="item-row">
            <td class="text-center row-number"></td>

            <td>
                <input type="hidden"
                       name="items[${index}][inventory_id]"
                       value="${escapeHtml(autoInventoryId)}">

                <input type="text"
                       class="inventory-name-input"
                       name="items[${index}][inventory_name]"
                       maxlength="255"
                       required>
            </td>

            <td>
                <input type="number"
                       step="0.0001"
                       min="0"
                       class="return-qty"
                       name="items[${index}][return_quantity]"
                       value="0"
                       required>
            </td>

            <td>
                <select name="items[${index}][uom]" required>
                    ${uomOptionsHtml('-- Pilih --')}
                </select>
            </td>

            <td>
                <input type="number"
                       step="0.0001"
                       min="0"
                       class="return-pack"
                       name="items[${index}][return_quantity_pack]"
                       value="0">
            </td>

            <td>
                <select name="items[${index}][uom_pack]">
                    ${uomOptionsHtml('-- Pilih --')}
                </select>
            </td>

            <td>
                <input type="number"
                       step="0.0001"
                       min="0"
                       name="items[${index}][return_quantity_detail]"
                       value="0">
            </td>

            <td>
                <select name="items[${index}][uom_detail]">
                    ${uomOptionsHtml('-- Pilih --')}
                </select>
            </td>

            <td>
                <input type="text"
                inputmode="numeric"
                class="rupiah-input price-unit"
                name="items[${index}][price_unit]"
                value="0"
                autocomplete="off">
            </td>

            <td>
               <input type="text"
                inputmode="numeric"
                class="return-price rupiah-input"
                name="items[${index}][price]"
                value="0"
                autocomplete="off"
                required>
            </td>

            <td>
                <input type="text"
                inputmode="numeric"
                class="return-subtotal rupiah-input"
                name="items[${index}][return_subtotal]"
                value="0"
                autocomplete="off"
                readonly
                required>
            </td>

            <td>
                <input type="text"
                       class="remarks-input"
                       name="items[${index}][remarks_detail]"
                       maxlength="255">
            </td>

            <td class="text-center">
                <button type="button"
                        class="btn-vs btn-danger btn-row-delete"
                        title="Hapus baris">
                    <i class="fa fa-trash"></i>
                </button>
            </td>
        </tr>
    `;
}

function renumberRows() {
    document.querySelectorAll('#itemTable tbody .item-row').forEach(function(row, idx) {
        row.querySelector('.row-number').textContent = idx + 1;
    });
}

function addItemRow() {
    var tbody = document.querySelector('#itemTable tbody');
    tbody.insertAdjacentHTML('beforeend', itemRow(itemIndex));
    itemIndex++;
    renumberRows();
}
function parseRupiah(value) {
    // 1.000.000 -> 1000000
    return Number(
        String(value || '')
            .replace(/\./g, '')
            .replace(/[^0-9]/g, '')
    ) || 0;
}

function formatRupiah(value) {
    var number = parseRupiah(value);

    return number.toLocaleString('id-ID', {
        maximumFractionDigits: 0
    });
}
function calculateRow(row) {
    var pack = Number(row.querySelector('.return-pack').value || 0);
    var price = parseRupiah(row.querySelector('.return-price').value);
    var subtotal = Math.max(0, pack * price);

    row.querySelector('.return-subtotal').value = formatRupiah(subtotal);

    calculateDetailTotal();
}

function calculateDetailTotal() {
    var total = 0;

    document.querySelectorAll('.return-subtotal').forEach(function(input) {
        total += parseRupiah(input.value);
    });

    document.getElementById('calculated_return_display').value =
        formatRupiah(total);
}

document.addEventListener('input', function(event) {
    if (
        event.target.classList.contains('return-pack') ||
        event.target.classList.contains('return-price')
    ) {
        var row = event.target.closest('.item-row');
        if (row) calculateRow(row);
    }

    if (event.target.classList.contains('return-subtotal')) {
        calculateDetailTotal();
    }
    if (event.target.classList.contains('rupiah-input')) {
        var angka = parseRupiah(event.target.value);

        event.target.value = angka > 0
            ? formatRupiah(angka)
            : '';
    }
});

document.addEventListener('click', function(event) {
    var deleteButton = event.target.closest('.btn-row-delete');

    if (deleteButton) {
        var rows = document.querySelectorAll('#itemTable tbody .item-row');

        if (rows.length <= 1) {
            alert('Minimal harus ada 1 baris inventory.');
            return;
        }

        deleteButton.closest('.item-row').remove();
        renumberRows();
        calculateDetailTotal();
    }
});

document.getElementById('addItemButton').addEventListener('click', addItemRow);

$(document).ready(function() {
    if (typeof flatpickr !== 'undefined') {
        flatpickr('.datepicker', {
            dateFormat: 'd-M-Y',
            allowInput: true,
            disableMobile: true
        });
    }

    $('#customer_ref').select2({
        width: '100%',
        placeholder: '-- Pilih Customer --',
        allowClear: true
    });

    $('#customer_ref').on('change', function() {
        var option = this.options[this.selectedIndex];

        $('#customer_name').val(
            option
                ? (option.getAttribute('data-customer-name') || '')
                : ''
        );
    });

    addItemRow();

    $('#returnMcpForm').on('submit', function(event) {
        syncRemarksReturn();
        if (!$('#customer_ref').val()) {
            event.preventDefault();
            alert('Customer Name wajib dipilih.');
            return false;
        }

        var rows = document.querySelectorAll('.item-row');

        if (!rows.length) {
            event.preventDefault();
            alert('Minimal harus ada satu detail inventory.');
            return false;
        }

        var hasValidReturn = Array.from(rows).some(function(row) {
            var qty = Number(row.querySelector('.return-qty').value || 0);
            var pack = Number(row.querySelector('.return-pack').value || 0);
            return qty > 0 || pack > 0;
        });

        if (!hasValidReturn) {
            event.preventDefault();
            alert('Minimal satu inventory harus memiliki Qty Return atau Qty Pack Return lebih dari 0.');
            return false;
        }

        $('#saveButton')
            .prop('disabled', true)
            .html('<i class="fa fa-spinner fa-spin"></i> Saving...');

        return true;
    });
});
</script>