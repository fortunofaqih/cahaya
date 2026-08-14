<?php
// modul/transaksi/edit_return_mcp.php
// Edit Sales Return CP-MCP.
// Referensi transaksi dan detail inventory tetap manual.
// Customer dipilih dari daftar customer.
// UoM berasal dari master m_uom.unit.

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

function extractMcpReference(string $remarks, string $label): string
{
    $remarks = trim($remarks);
    if ($remarks === '') return '';

    $pattern = '/(?:^|\\|\\s*)' . preg_quote($label, '/') . '\\s*:\\s*([^|]+)/i';

    if (preg_match($pattern, $remarks, $m)) {
        return trim((string)($m[1] ?? ''));
    }

    return '';
}

$returnId = trim((string)($_GET['return_id'] ?? $_GET['id'] ?? ''));

if ($returnId === '') {
    die('Sales Return ID kosong.');
}

/*
|--------------------------------------------------------------------------
| HEADER RETURN
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM head_retur_invoice
     WHERE return_id = ?
     LIMIT 1"
);

if (!$stmt) {
    die('Gagal prepare Sales Return: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, 's', $returnId);
mysqli_stmt_execute($stmt);

$head = mysqli_fetch_assoc(
    mysqli_stmt_get_result($stmt)
);

mysqli_stmt_close($stmt);

if (!$head) {
    die('Sales Return tidak ditemukan.');
}

if (
    strtolower(trim((string)($head['approval_status'] ?? 'Pending')))
    !== 'pending'
) {
    die('Sales Return yang sudah Approved tidak dapat diedit.');
}

$isMcp =
    stripos((string)$head['return_id'], '/CP-MCP/') !== false ||
    strpos((string)($head['invoice_no'] ?? ''), 'CP-MCP/INV/') === 0;

if (!$isMcp) {
    die('Data ini bukan Sales Return CP-MCP.');
}

$remarksReturn = (string)($head['remarks_return'] ?? '');

$externalOrderNo = extractMcpReference(
    $remarksReturn,
    'Sales Order MCP'
);

$externalShippingNo = extractMcpReference(
    $remarksReturn,
    'Shipping No. MCP'
);

if ($externalOrderNo === '') {
    $externalOrderNo = (string)($head['order_no'] ?? '');
}

if ($externalShippingNo === '') {
    $externalShippingNo = (string)($head['shipping_no'] ?? '');
}

/*
|--------------------------------------------------------------------------
| DETAIL RETURN
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM detail_retur_invoice
     WHERE return_id = ?
     ORDER BY id ASC"
);

if (!$stmt) {
    die('Gagal prepare Detail Return: ' . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, 's', $returnId);
mysqli_stmt_execute($stmt);

$detailRs = mysqli_stmt_get_result($stmt);
$details = [];

while ($detailRs && $row = mysqli_fetch_assoc($detailRs)) {
    $details[] = $row;
}

mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| CUSTOMER
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
    background:#f0f2f5;
    padding:12px;
    color:#212529;
    font-size:11px;
}
.mcp-banner {
    background:linear-gradient(135deg,#ffc107,#ffca2c);
    border:1px solid #e0a800;
    color:#332701;
    padding:8px 12px;
    margin-bottom:10px;
    border-radius:4px;
    display:flex;
    align-items:center;
    gap:7px;
    font-weight:700;
}
.panel-row {
    display:flex;
    gap:10px;
    margin-bottom:10px;
    align-items:stretch;
}
.return-panel {
    flex:1;
    background:#fff;
    border:1px solid #dee2e6;
    border-radius:4px;
    overflow:hidden;
    min-width:0;
}
.return-panel-header {
    background:#e9ecef;
    border-bottom:1px solid #dee2e6;
    padding:6px 12px;
    font-size:11px;
    font-weight:bold;
    color:#495057;
    display:flex;
    align-items:center;
    gap:6px;
}
.return-panel-body { padding:12px; }
.ff { margin-bottom:8px; }
.ff:last-child { margin-bottom:0; }
.ff label {
    display:block;
    font-size:10px;
    font-weight:700;
    color:#0d6efd;
    margin-bottom:3px;
    text-transform:uppercase;
}
.ff input,.ff select,.ff textarea {
    width:100%;
    background:#fff;
    border:1px solid #ced4da;
    border-radius:3px;
    font-size:11px;
    padding:5px 8px;
    outline:none;
}
.ff input:focus,.ff select:focus,.ff textarea:focus {
    border-color:#86b7fe;
    box-shadow:0 0 0 2px rgba(13,110,253,.12);
}
.ff input[readonly] {
    background:#e9ecef;
    color:#555;
}
.required { color:red; font-weight:bold; }
.return-id-input { font-weight:bold; color:#0d6efd; }
.return-panel-full {
    background:#fff;
    border:1px solid #dee2e6;
    border-radius:4px;
    overflow:hidden;
    margin-bottom:10px;
}
.detail-toolbar {
    background:#f8f9fa;
    border-bottom:1px solid #dee2e6;
    padding:8px 12px;
    display:flex;
    gap:8px;
    align-items:center;
    justify-content:space-between;
    flex-wrap:wrap;
}
.btn-vs {
    padding:6px 12px;
    font-size:11px;
    font-weight:bold;
    border:none;
    border-radius:3px;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    text-decoration:none;
    line-height:1.25;
}
.btn-vs:hover { filter:brightness(.95); text-decoration:none; }
.btn-primary { background:#0d6efd; color:#fff; }
.btn-success { background:#198754; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-danger { background:#dc3545; color:#fff; }
.btn-row-delete {
    width:30px;
    height:28px;
    padding:0;
}
.small-note { font-size:10px; color:#777; }
.detail-table-wrap {
    max-height:430px;
    overflow:auto;
}
.detail-table {
    width:100%;
    min-width:1550px;
    border-collapse:collapse;
    font-size:11px;
}
.detail-table th {
    background:#e9ecef;
    padding:8px 6px;
    border:1px solid #dee2e6;
    position:sticky;
    top:0;
    z-index:2;
    font-size:10px;
    text-transform:uppercase;
    text-align:center;
    white-space:nowrap;
}
.detail-table td {
    padding:5px 6px;
    border:1px solid #dee2e6;
    background:#fff;
    vertical-align:middle;
    white-space:nowrap;
}
.detail-table tbody tr:hover td { background:#f3f8ff; }
.detail-table input,.detail-table select {
    width:100%;
    border:1px solid #ced4da;
    background:#fff;
    font-size:11px;
    padding:4px 5px;
    outline:none;
    border-radius:3px;
}
.inventory-name-input { min-width:240px; }
.remarks-input { min-width:180px; }
.text-right { text-align:right; }
.text-center { text-align:center; }
.summary-panel {
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:10px;
    padding:12px;
    background:#fff;
    border:1px solid #dee2e6;
    border-radius:4px;
    margin-bottom:10px;
}
.summary-box label {
    display:block;
    font-size:10px;
    font-weight:bold;
    color:#0d6efd;
    text-transform:uppercase;
    margin-bottom:3px;
}
.summary-box input {
    width:100%;
    border:1px solid #ced4da;
    border-radius:3px;
    padding:6px 8px;
    font-size:12px;
    font-weight:bold;
    text-align:right;
    background:#fff;
}
.summary-box.readonly input { background:#e9ecef; }
.summary-box.highlight input {
    background:#e7f5ff;
    color:#0d6efd;
}
.actionbar {
    display:flex;
    justify-content:flex-end;
    gap:10px;
    padding:10px 0;
}
.select2-container--default .select2-selection--single {
    height:28px!important;
    padding:2px 0!important;
    font-size:11px!important;
    border:1px solid #ced4da!important;
    border-radius:3px!important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height:22px!important;
    font-size:11px!important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height:26px!important;
}
@media(max-width:1000px) {
    .panel-row { flex-direction:column; }
    .summary-panel { grid-template-columns:1fr 1fr; }
}
@media(max-width:600px) {
    .summary-panel { grid-template-columns:1fr; }
    .actionbar { flex-direction:column-reverse; }
    .actionbar .btn-vs { width:100%; }
}
</style>

<div class="return-wrap">

    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="mcp-banner">
        <i class="fa fa-pen-to-square"></i>
        Edit Sales Return CP-MCP — Sales Return ID dapat diubah, sedangkan nomor internal SO/SJ/INV tetap dipertahankan.
    </div>

    <form method="POST" action="index.php?page=update_return_mcp" id="returnMcpForm">

        <input type="hidden" name="original_return_id" value="<?= h($head['return_id']) ?>">

        <input type="hidden" name="internal_order_no" value="<?= h($head['order_no']) ?>">
        <input type="hidden" name="internal_shipping_no" value="<?= h($head['shipping_no']) ?>">
        <input type="hidden" name="internal_invoice_no" value="<?= h($head['invoice_no']) ?>">
        <input type="hidden" name="remarks_return" id="remarks_return" value="<?= h($head['remarks_return']) ?>">

        <div class="panel-row">

            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-rotate-left"></i>
                    Return Information
                </div>

                <div class="return-panel-body">
                    <div class="ff">
                        <label>Sales Return ID</label>
                        <input
                            type="text"
                            name="return_id"
                            id="return_id"
                            class="return-id-input"
                            value="<?= h($head['return_id']) ?>"
                            maxlength="50"
                            required
                            autocomplete="off"
                        >
                        <div class="small-note" style="margin-top:4px;">
                            Sales Return ID dapat diubah. Sistem akan mengecek agar ID baru tidak duplikat.
                        </div>
                    </div>

                    <div class="ff">
                        <label>Return Date <span class="required">*</span></label>
                        <input
                            type="text"
                            name="return_date"
                            class="datepicker"
                            value="<?= h(formatDateDisplay($head['return_date'])) ?>"
                            required
                            autocomplete="off"
                        >
                    </div>

                    <div class="ff">
                        <label>Reason Return <span class="required">*</span></label>
                        <input
                            type="text"
                            name="reason_return"
                            value="<?= h($head['reason_return']) ?>"
                            required
                            maxlength="255"
                        >
                    </div>

                </div>
            </div>

            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-file-lines"></i>
                    Sales Order & Shipping MCP
                </div>

                <div class="return-panel-body">
                    <div class="ff">
                        <label>Sales Order MCP <span class="required">*</span></label>
                        <input
                            type="text"
                            name="order_no"
                            value="<?= h($externalOrderNo) ?>"
                            required
                            maxlength="50"
                            placeholder="Input Sales Order asli MCP"
                        >
                    </div>

                    <div class="ff">
                        <label>Shipping No. MCP <span class="required">*</span></label>
                        <input
                            type="text"
                            name="shipping_no"
                            value="<?= h($externalShippingNo) ?>"
                            required
                            maxlength="50"
                            placeholder="Input Shipping No. asli MCP"
                        >
                    </div>
                </div>
            </div>

            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-building-user"></i>
                    Customer Information
                </div>

                <div class="return-panel-body">
                    <div class="ff">
                        <label>Customer Name <span class="required">*</span></label>

                        <select name="customer_ref" id="customer_ref" required>
                            <option value="">-- Pilih Customer --</option>

                            <?php while ($customer = mysqli_fetch_assoc($customerRs)): ?>
                                <option
                                    value="<?= h($customer['customer_id']) ?>"
                                    data-customer-name="<?= h($customer['customer_name']) ?>"
                                    <?= (string)$customer['customer_id'] === (string)$head['customer_id'] ? 'selected' : '' ?>
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
                            value="<?= h($head['customer_name']) ?>"
                        >
                    </div>

                    <div class="ff">
                        <label>Currency</label>
                        <input
                            type="text"
                            name="currency"
                            value="<?= h($head['currency'] ?: 'IDR') ?>"
                            maxlength="10"
                        >
                    </div>


                </div>
            </div>

        </div>

        <div class="return-panel-full">
            <div class="return-panel-header">
                <i class="fa fa-boxes-stacked"></i>
                Detail Inventory Return
            </div>

            <div class="detail-toolbar">
                <span class="small-note">
                    <i class="fa fa-info-circle"></i>
                    Inventory ID disimpan otomatis/hidden. Return Subtotal otomatis = Price × Qty Pack Return.
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

        <div class="summary-panel">

            <div class="summary-box">
                <label>Down Payment</label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="down_payment"
                    value="<?= h((float)$head['down_payment']) ?>"
                >
            </div>

            <div class="summary-box">
                <label>Titip</label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="titip_applied"
                    value="<?= h((float)$head['titip_applied']) ?>"
                >
            </div>

            <div class="summary-box">
                <label>Payment Balance</label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="payment_balance"
                    value="<?= h((float)$head['payment_balance']) ?>"
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
                <label>Return Amount <span class="required">*</span></label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="return_amount"
                    id="return_amount"
                    value="<?= h((float)$head['return_amount']) ?>"
                    required
                >
            </div>

            <div class="summary-box highlight">
                <label>Balance After Return <span class="required">*</span></label>
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    name="remaining_invoice_balance"
                    id="remaining_invoice_balance"
                    value="<?= h((float)$head['remaining_invoice_balance']) ?>"
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
                Update Sales Return CP-MCP
            </button>
        </div>

    </form>
</div>

<script>
function escapeHtml(value) {
    if (value === null || value === undefined) return '';

    return String(value).replace(/[&<>"']/g, function(m) {
        return {
            '&':'&amp;',
            '<':'&lt;',
            '>':'&gt;',
            '"':'&quot;',
            "'":'&#39;'
        }[m];
    });
}

function money(value) {
    return Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits:2,
        maximumFractionDigits:2
    });
}

var masterUom = <?= json_encode(
    $uomOptions,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

var existingDetails = <?= json_encode(
    $details,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

function uomOptionsHtml(placeholder, selectedValue) {
    var html =
        '<option value="">' +
        escapeHtml(placeholder || '-- Pilih UoM --') +
        '</option>';

    var selectedExists = false;

    masterUom.forEach(function(unit) {
        var selected =
            String(unit) === String(selectedValue || '')
                ? ' selected'
                : '';

        if (selected) {
            selectedExists = true;
        }

        html +=
            '<option value="' +
            escapeHtml(unit) +
            '"' +
            selected +
            '>' +
            escapeHtml(unit) +
            '</option>';
    });

    /*
     * Jika data lama memakai UoM yang saat ini tidak aktif/tidak ada di master,
     * tetap tampilkan agar data existing tidak hilang saat edit.
     */
    if (selectedValue && !selectedExists) {
        html +=
            '<option value="' +
            escapeHtml(selectedValue) +
            '" selected>' +
            escapeHtml(selectedValue) +
            '</option>';
    }

    return html;
}

var itemIndex = 0;

function itemRow(index, data) {
    data = data || {};

    return `
        <tr class="item-row">
            <td class="text-center row-number"></td>

            <td>
                <input type="hidden"
                       name="items[${index}][inventory_id]"
                       value="${escapeHtml(data.inventory_id || '')}">
                <input type="hidden"
                       name="items[${index}][shipping_detail_id]"
                       value="${escapeHtml(data.shipping_detail_id || '')}">

                <input type="text"
                       class="inventory-name-input"
                       name="items[${index}][inventory_name]"
                       maxlength="255"
                       value="${escapeHtml(data.inventory_name || '')}"
                       required>
            </td>

            <td>
                <input type="number"
                       step="0.0001"
                       min="0"
                       class="return-qty"
                       name="items[${index}][return_quantity]"
                       value="${Number(data.return_quantity || 0)}"
                       required>
            </td>

            <td>
                <select name="items[${index}][uom]" required>
                    ${uomOptionsHtml('-- Pilih --', data.uom || '')}
                </select>
            </td>

            <td>
                <input type="number"
                       step="0.0001"
                       min="0"
                       class="return-pack"
                       name="items[${index}][return_quantity_pack]"
                       value="${Number(data.return_quantity_pack || 0)}">
            </td>

            <td>
                <select name="items[${index}][uom_pack]">
                    ${uomOptionsHtml('-- Pilih --', data.uom_pack || '')}
                </select>
            </td>

            <td>
                <input type="number"
                       step="0.0001"
                       min="0"
                       name="items[${index}][return_quantity_detail]"
                       value="${Number(data.return_quantity_detail || 0)}">
            </td>

            <td>
                <select name="items[${index}][uom_detail]">
                    ${uomOptionsHtml('-- Pilih --', data.uom_detail || '')}
                </select>
            </td>

            <td>
                <input type="number"
                       step="0.0001"
                       min="0"
                       name="items[${index}][price_unit]"
                       value="${Number(data.price_unit || 0)}">
            </td>

            <td>
                <input type="number"
                       step="0.01"
                       min="0"
                       class="return-price"
                       name="items[${index}][price]"
                       value="${Number(data.price || 0)}"
                       required>
            </td>

            <td>
                <input type="number"
                       step="0.01"
                       min="0"
                       class="return-subtotal"
                       name="items[${index}][return_subtotal]"
                       value="${Number(data.return_subtotal || 0)}"
                       required>
            </td>

            <td>
                <input type="text"
                       class="remarks-input"
                       name="items[${index}][remarks_detail]"
                       maxlength="255"
                       value="${escapeHtml(data.remarks_detail || '')}">
            </td>

            <td class="text-center">
                <button
                    type="button"
                    class="btn-vs btn-danger btn-row-delete"
                    title="Hapus baris"
                >
                    <i class="fa fa-trash"></i>
                </button>
            </td>
        </tr>
    `;
}

function renumberRows() {
    document
        .querySelectorAll('#itemTable tbody .item-row')
        .forEach(function(row, idx) {
            row.querySelector('.row-number').textContent = idx + 1;
        });
}

function addItemRow(data) {
    var tbody = document.querySelector('#itemTable tbody');
    tbody.insertAdjacentHTML('beforeend', itemRow(itemIndex, data));
    itemIndex++;
    renumberRows();
}

function calculateRow(row) {
    var pack =
        Number(row.querySelector('.return-pack').value || 0);

    var price =
        Number(row.querySelector('.return-price').value || 0);

    /*
     * Sama dengan add_return_mcp:
     * Return Subtotal = Price x Qty Pack Return.
     * Jika Qty Pack 0, subtotal manual tidak dipaksa berubah.
     */
    if (pack > 0) {
        var subtotal = Math.max(0, pack * price);

        row.querySelector('.return-subtotal').value =
            subtotal.toFixed(2);
    }

    calculateDetailTotal();
}

function calculateDetailTotal() {
    var total = 0;

    document
        .querySelectorAll('.return-subtotal')
        .forEach(function(input) {
            total += Number(input.value || 0);
        });

    document.getElementById(
        'calculated_return_display'
    ).value = money(total);
}

function syncRemarksReturn() {
    var orderInput = document.querySelector('input[name="order_no"]');
    var shippingInput = document.querySelector('input[name="shipping_no"]');
    var remarksInput = document.getElementById('remarks_return');

    if (!remarksInput) return;

    remarksInput.value =
        'Sales Order MCP: ' +
        String(orderInput ? orderInput.value : '').trim() +
        ' | Shipping No. MCP: ' +
        String(shippingInput ? shippingInput.value : '').trim();
}

document.addEventListener('input', function(event) {
    if (
        event.target.classList.contains('return-pack') ||
        event.target.classList.contains('return-price')
    ) {
        var row = event.target.closest('.item-row');

        if (row) {
            calculateRow(row);
        }
    }

    if (
        event.target.classList.contains('return-subtotal')
    ) {
        calculateDetailTotal();
    }

    if (
        event.target.name === 'order_no' ||
        event.target.name === 'shipping_no'
    ) {
        syncRemarksReturn();
    }
});

document.addEventListener('click', function(event) {
    var deleteButton =
        event.target.closest('.btn-row-delete');

    if (!deleteButton) return;

    var rows =
        document.querySelectorAll(
            '#itemTable tbody .item-row'
        );

    if (rows.length <= 1) {
        alert('Minimal harus ada 1 baris inventory.');
        return;
    }

    deleteButton.closest('.item-row').remove();

    renumberRows();
    calculateDetailTotal();
});

document
    .getElementById('addItemButton')
    .addEventListener('click', function() {
        addItemRow({});
    });

$(document).ready(function() {
    if (typeof flatpickr !== 'undefined') {
        flatpickr('.datepicker', {
            dateFormat:'d-M-Y',
            allowInput:true,
            disableMobile:true
        });
    }

    $('#customer_ref').select2({
        width:'100%',
        placeholder:'-- Pilih Customer --',
        allowClear:true
    });

    $('#customer_ref').on('change', function() {
        var option = this.options[this.selectedIndex];

        $('#customer_name').val(
            option
                ? (
                    option.getAttribute(
                        'data-customer-name'
                    ) || ''
                )
                : ''
        );
    });

    if (existingDetails.length) {
        existingDetails.forEach(function(detail) {
            addItemRow(detail);
        });
    } else {
        addItemRow({});
    }

    calculateDetailTotal();
    syncRemarksReturn();

    $('#returnMcpForm').on('submit', function(event) {
        syncRemarksReturn();

        if (!String($('#return_id').val() || '').trim()) {
            event.preventDefault();
            alert('Sales Return ID wajib diisi.');
            return false;
        }

        if (!$('#customer_ref').val()) {
            event.preventDefault();
            alert('Customer Name wajib dipilih.');
            return false;
        }

        var rows =
            document.querySelectorAll('.item-row');

        var hasValidReturn =
            Array.from(rows).some(function(row) {
                var qty =
                    Number(
                        row.querySelector('.return-qty')
                            .value || 0
                    );

                var pack =
                    Number(
                        row.querySelector('.return-pack')
                            .value || 0
                    );

                return qty > 0 || pack > 0;
            });

        if (!hasValidReturn) {
            event.preventDefault();

            alert(
                'Minimal satu inventory harus memiliki Qty Return atau Qty Pack Return lebih dari 0.'
            );

            return false;
        }

        $('#saveButton')
            .prop('disabled', true)
            .html(
                '<i class="fa fa-spinner fa-spin"></i> Updating...'
            );

        return true;
    });
});
</script>