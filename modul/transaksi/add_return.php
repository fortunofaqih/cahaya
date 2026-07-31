<?php
// modul/transaksi/add_return.php
// Tampilan disamakan dengan add_invoice.php. Flow: Customer -> Order -> Shipping -> Invoice.

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

$returnDateDisplay = formatDateDisplay(date('Y-m-d'));
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>

<style>
.return-wrap * { box-sizing:border-box; font-family:'Segoe UI','Consolas','Cascadia Code',monospace; }
.return-wrap { background:#f0f2f5; padding:12px; color:#212529; font-size:11px; }
.panel-row { display:flex; gap:10px; margin-bottom:10px; align-items:stretch; }
.return-panel { flex:1; background:#fff; border:1px solid #dee2e6; border-radius:4px; overflow:hidden; min-width:0; }
.return-panel-header { background:#e9ecef; border-bottom:1px solid #dee2e6; padding:6px 12px; font-size:11px; font-weight:bold; color:#495057; display:flex; align-items:center; gap:6px; }
.return-panel-body { padding:12px; }
.ff { margin-bottom:8px; }
.ff:last-child { margin-bottom:0; }
.ff label { display:block; font-size:10px; font-weight:700; color:#0d6efd; margin-bottom:3px; text-transform:uppercase; }
.ff input,.ff select,.ff textarea { width:100%; background:#fff; border:1px solid #ced4da; border-radius:3px; font-size:11px; padding:5px 8px; outline:none; }
.ff input:focus,.ff select:focus,.ff textarea:focus { border-color:#86b7fe; box-shadow:0 0 0 2px rgba(13,110,253,.12); }
.ff input[readonly],.ff textarea[readonly] { background:#e9ecef; color:#555; }
.return-panel-full { background:#fff; border:1px solid #dee2e6; border-radius:4px; overflow:hidden; margin-bottom:10px; }
.detail-toolbar { background:#f8f9fa; border-bottom:1px solid #dee2e6; padding:8px 12px; display:flex; gap:8px; align-items:center; }
.btn-vs { padding:6px 12px; font-size:11px; font-weight:bold; border:none; border-radius:3px; cursor:pointer; display:inline-flex; align-items:center; gap:5px; text-decoration:none; line-height:1.25; }
.btn-vs:hover { filter:brightness(.95); text-decoration:none; }
.btn-primary { background:#0d6efd; color:#fff; }
.btn-success { background:#198754; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.required { color:red; font-weight:bold; }
.detail-table-wrap { max-height:390px; overflow:auto; }
.detail-table { width:100%; min-width:1540px; border-collapse:collapse; font-size:11px; }
.detail-table th { background:#e9ecef; padding:8px 6px; border:1px solid #dee2e6; position:sticky; top:0; z-index:2; font-size:10px; text-transform:uppercase; text-align:center; white-space:nowrap; }
.detail-table td { padding:5px 6px; border:1px solid #dee2e6; background:#fff; vertical-align:middle; white-space:nowrap; }
.detail-table tbody tr:hover td { background:#f3f8ff; }
.detail-table input[type="number"],.detail-table input[type="text"] { width:100%; border:1px solid #ced4da; background:#fff; font-size:11px; padding:4px 5px; outline:none; border-radius:3px; }
.detail-table input[type="checkbox"] { width:15px; height:15px; cursor:pointer; }
.detail-table .inventory-name { white-space:normal; word-break:break-word; min-width:260px; }
.detail-table .text-right { text-align:right; }
.detail-table .text-center { text-align:center; }
.summary-panel { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; padding:12px; background:#fff; border:1px solid #dee2e6; border-radius:4px; margin-bottom:10px; }
.summary-box label { display:block; font-size:10px; font-weight:bold; color:#0d6efd; text-transform:uppercase; margin-bottom:3px; }
.summary-box input { width:100%; border:1px solid #ced4da; border-radius:3px; padding:6px 8px; font-size:12px; font-weight:bold; text-align:right; background:#f8f9fa; }
.summary-box.highlight input { background:#e7f5ff; color:#0d6efd; }
.actionbar { display:flex; justify-content:flex-end; gap:10px; padding:10px 0; }
.select2-container--default .select2-selection--single { height:28px!important; padding:2px 0!important; font-size:11px!important; border:1px solid #ced4da!important; border-radius:3px!important; }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height:22px!important; font-size:11px!important; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height:26px!important; }
.select2-container--default.select2-container--disabled .select2-selection--single { background:#e9ecef!important; }
.return-id-input { font-weight:bold; color:#0d6efd; }
.small-note { font-size:10px; color:#777; }
@media(max-width:1000px){ .panel-row{flex-direction:column;} .summary-panel{grid-template-columns:1fr 1fr;} }
@media(max-width:600px){ .summary-panel{grid-template-columns:1fr;} }
</style>

<div class="return-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <form method="POST" action="index.php?page=save_return" id="returnForm">
        <div class="panel-row">
            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-rotate-left"></i> Return Information
                </div>
                <div class="return-panel-body">
                    <div class="ff">
                        <label>Sales Return ID <span class="required">*</span></label>
                        <input type="text" name="return_id" class="return-id-input" required maxlength="50" placeholder="Input manual oleh user">
                    </div>
                    <div class="ff">
                        <label>Return Date <span class="required">*</span></label>
                        <input type="text" name="return_date" class="datepicker" value="<?= h($returnDateDisplay) ?>" required autocomplete="off">
                    </div>
                    <div class="ff">
                        <label>Reason Return <span class="required">*</span></label>
                        <input type="text" name="reason_return" required maxlength="255" placeholder="Ketik alasan retur secara manual">
                    </div>
                    <div class="ff">
                        <label>Remarks Return</label>
                        <textarea name="remarks_return" rows="3" placeholder="Keterangan tambahan retur..."></textarea>
                    </div>
                </div>
            </div>

            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-file-lines"></i> Sales Order & Shipping
                </div>
                <div class="return-panel-body">
                    <div class="ff">
                        <label>Sales Order <span class="required">*</span></label>
                        <select name="order_no" id="order_no" required disabled>
                            <option value="">-- Pilih Customer terlebih dahulu --</option>
                        </select>
                        <div id="order_inventory_info" class="small-note" style="margin-top:5px;color:#495057;"></div>
                    </div>
                    <div class="ff">
                        <label>Shipping No. <span class="required">*</span></label>
                        <select name="shipping_ref" id="shipping_ref" required disabled>
                            <option value="">-- Pilih Shipping --</option>
                        </select>
                        <input type="hidden" name="shipping_no" id="shipping_no">
                        <input type="hidden" name="invoice_no" id="invoice_no">
                    </div>
                    <div class="ff">
                        <label>Invoice No.</label>
                        <input type="text" id="invoice_no_display" readonly>
                    </div>
                    <div class="ff">
                        <label>Invoice Date</label>
                        <input type="text" id="invoice_date_display" readonly>
                    </div>
                </div>
            </div>

            <div class="return-panel">
                <div class="return-panel-header">
                    <i class="fa fa-building-user"></i> Customer & Invoice Information
                </div>
                <div class="return-panel-body">
                    <div class="ff">
                        <label>Customer Name <span class="required">*</span></label>
                        <select name="customer_ref" id="customer_ref" required>
                            <option value="">-- Pilih Customer --</option>
                            <?php while ($customer = mysqli_fetch_assoc($customerRs)): ?>
                                <option value="<?= h($customer['customer_id']) ?>"
                                        data-customer-name="<?= h($customer['customer_name']) ?>">
                                    <?= h($customer['customer_name']) ?> | <?= h($customer['customer_id']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <input type="hidden" id="customer_name" value="">
                    <div class="ff">
                        <label>Currency</label>
                        <input type="text" id="currency" value="IDR" readonly>
                    </div>
                    <div class="ff">
                        <label>Invoice Subtotal</label>
                        <input type="text" id="invoice_subtotal_display" readonly style="text-align:right;">
                    </div>
                    <div class="ff">
                        <label>Grand Total</label>
                        <input type="text" id="grand_total_display" readonly style="text-align:right;font-weight:bold;">
                    </div>
                </div>
            </div>
        </div>

        <div class="return-panel-full">
            <div class="return-panel-header">
                <i class="fa fa-boxes-stacked"></i> Detail Inventory Return
            </div>
            <div class="detail-toolbar">
                <span class="small-note">
                    <i class="fa fa-info-circle"></i>
                    Pilih inventory yang diretur. Qty Return dan Qty Pack Return akan saling menyesuaikan berdasarkan rasio Shipping.
                </span>
            </div>
            <div class="detail-table-wrap">
                <table class="detail-table" id="itemTable">
                    <thead>
                        <tr>
                            <th style="width:55px;">Return</th>
                            <th style="width:125px;">Inventory ID</th>
                            <th>Inventory Name</th>
                            <th style="width:100px;">Remaining Qty</th>
                            <th style="width:70px;">UoM</th>
                            <th style="width:120px;">Remaining Qty Pack</th>
                            <th style="width:85px;">UoM Pack</th>
                            <th style="width:105px;">Qty Return</th>
                            <th style="width:120px;">Qty Pack Return</th>
                            <th style="width:90px;">UoM Detail</th>
                            <th style="width:120px;">Price</th>
                            <th style="width:140px;">Return Subtotal</th>
                            <th style="width:200px;">Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr id="emptyRow">
                            <td colspan="13" style="text-align:center;color:#777;padding:15px;">
                                Pilih Customer, Sales Order, dan Shipping terlebih dahulu.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="summary-panel">
            <div class="summary-box">
                <label>Down Payment</label>
                <input type="text" id="down_payment_display" value="0.00" readonly>
            </div>
            <div class="summary-box">
                <label>Titip</label>
                <input type="text" id="titip_display" value="0.00" readonly>
            </div>
            <div class="summary-box">
                <label>Payment Balance</label>
                <input type="text" id="payment_balance_display" value="0.00" readonly>
            </div>
            <div class="summary-box highlight">
                <label>Return Amount</label>
                <input type="text" id="return_amount_display" value="0.00" readonly>
            </div>
            <div class="summary-box highlight">
                <label>Balance After Return</label>
                <input type="text" id="balance_after_display" value="0.00" readonly>
            </div>
        </div>

        <div class="actionbar">
            <button type="button" class="btn-vs btn-secondary" onclick="window.location.href='index.php?page=return_invoice'">
                <i class="fa fa-times"></i> Batal / Kembali
            </button>
            <button type="submit" class="btn-vs btn-success" id="saveButton" disabled>
                <i class="fa fa-save"></i> Simpan Sales Return
            </button>
        </div>
    </form>
</div>

<script>
function escHtml(value){
    if(value === null || value === undefined) return '';
    return String(value).replace(/[&<>"']/g, function(m){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}
function trimDecimal(value){
    return Number(value || 0).toFixed(4).replace(/\.?0+$/, '');
}
function formatQty(value){
    return Number(value || 0).toLocaleString('en-US', {minimumFractionDigits:0, maximumFractionDigits:4});
}
function fmtMoney(value){
    return Number(value || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}
function fmtDate(dateStr){
    if(!dateStr) return '';
    var d = new Date(dateStr + 'T00:00:00');
    if(isNaN(d)) return dateStr;
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return String(d.getDate()).padStart(2,'0') + '-' + months[d.getMonth()] + '-' + d.getFullYear();
}

var currentHeader = null;

function resetOrderShippingAndItems(){
    $('#order_no')
        .empty()
        .append(new Option('-- Pilih Customer terlebih dahulu --', ''))
        .prop('disabled', true)
        .trigger('change.select2');
    $('#order_inventory_info').html('');
    resetShippingAndItems();
}

function resetShippingAndItems(){
    $('#shipping_ref')
        .empty()
        .append(new Option('-- Pilih Shipping --', ''))
        .prop('disabled', true)
        .trigger('change.select2');

    $('#shipping_no,#invoice_no').val('');
    clearItems();
}

function clearItems(){
    currentHeader = null;
    $('#itemTable tbody').html(
        '<tr id="emptyRow"><td colspan="13" style="text-align:center;color:#777;padding:15px;">' +
        'Pilih Customer, Sales Order, dan Shipping terlebih dahulu.</td></tr>'
    );

    $('#invoice_no_display,#invoice_date_display,#invoice_subtotal_display,#grand_total_display').val('');
    $('#currency').val('IDR');
    $('#down_payment_display,#titip_display,#payment_balance_display,#return_amount_display,#balance_after_display').val('0.00');
    $('#saveButton').prop('disabled', true);
}

function fillHeader(header){
    $('#invoice_no_display').val(header.invoice_no || '');
    $('#invoice_date_display').val(fmtDate(header.invoice_date || ''));
    $('#customer_name').val(header.customer_name || '');
    $('#currency').val(header.currency || 'IDR');
    $('#invoice_subtotal_display').val(fmtMoney(header.subtotal));
    $('#grand_total_display').val(fmtMoney(header.grand_total));
    $('#down_payment_display').val(fmtMoney(header.down_payment));
    $('#titip_display').val(fmtMoney(header.titip_applied));
    $('#payment_balance_display').val(fmtMoney(header.payment_balance));
}

function renderItems(details){
    var tbody = document.querySelector('#itemTable tbody');
    tbody.innerHTML = '';

    var available = (details || []).filter(function(item){
        return Number(item.remaining_quantity || 0) > 0 || Number(item.remaining_quantity_pack || 0) > 0;
    });

    if(!available.length){
        tbody.innerHTML = '<tr><td colspan="13" style="text-align:center;color:#dc3545;padding:15px;">Semua quantity pada shipping ini sudah diretur.</td></tr>';
        $('#saveButton').prop('disabled', true);
        return;
    }

    available.forEach(function(item, index){
        var tr = document.createElement('tr');
        tr.className = 'item-row';
        tr.dataset.originalQty = item.original_quantity || 0;
        tr.dataset.originalPack = item.original_quantity_pack || 0;
        tr.dataset.remainingQty = item.remaining_quantity || 0;
        tr.dataset.remainingPack = item.remaining_quantity_pack || 0;
        tr.dataset.originalSubtotal = item.original_subtotal || 0;
        tr.dataset.price = item.price || 0;
        tr.dataset.priceUnit = item.price_unit || 0;
        tr.dataset.uomPack = String(item.original_uom_pack || '').toUpperCase();
        tr.dataset.returnSubtotal = '0.00';

        tr.innerHTML =
            '<td class="text-center">' +
                '<input type="checkbox" name="items['+index+'][selected]" value="1" class="item-selected">' +
                '<input type="hidden" name="items['+index+'][shipping_detail_id]" value="'+escHtml(item.shipping_detail_id)+'">' +
            '</td>' +
            '<td><code>'+escHtml(item.inventory_id)+'</code></td>' +
            '<td class="inventory-name">'+escHtml(item.inventory_name)+'</td>' +
            '<td class="text-right">'+formatQty(item.remaining_quantity)+'</td>' +
            '<td class="text-center">'+escHtml(item.original_uom)+'</td>' +
            '<td class="text-right">'+formatQty(item.remaining_quantity_pack)+'</td>' +
            '<td class="text-center">'+escHtml(item.original_uom_pack)+'</td>' +
            '<td><input type="number" step="0.0001" min="0" max="'+Number(item.remaining_quantity || 0)+'" name="items['+index+'][return_quantity]" class="return-qty" value="0"></td>' +
            '<td><input type="number" step="0.0001" min="0" max="'+Number(item.remaining_quantity_pack || 0)+'" name="items['+index+'][return_quantity_pack]" class="return-pack" value="0"></td>' +
            '<td class="text-center">'+escHtml(item.original_uom_detail)+'</td>' +
            '<td class="text-right">Rp '+fmtMoney(item.price || 0)+'</td>' +
            '<td class="text-right"><b>Rp <span class="return-subtotal-display">0.00</span></b></td>' +
            '<td><input type="text" name="items['+index+'][remarks_detail]" maxlength="255" placeholder="Remarks..."></td>';

        tbody.appendChild(tr);
    });

    bindItemEvents();
    $('#saveButton').prop('disabled', false);
}

function bindItemEvents(){
    document.querySelectorAll('.item-row').forEach(function(row){
        var checkbox = row.querySelector('.item-selected');
        var qty = row.querySelector('.return-qty');
        var pack = row.querySelector('.return-pack');

        checkbox.addEventListener('change', function(){
            if(!checkbox.checked){
                qty.value = '0';
                pack.value = '0';
            }
            calculateRow(row);
        });

        qty.addEventListener('input', function(){
            checkbox.checked = Number(qty.value || 0) > 0;
            syncFromQty(row);
        });

        pack.addEventListener('input', function(){
            checkbox.checked = Number(pack.value || 0) > 0;
            syncFromPack(row);
        });
    });
}

function syncFromQty(row){
    var qtyInput = row.querySelector('.return-qty');
    var packInput = row.querySelector('.return-pack');
    var remainingQty = Number(row.dataset.remainingQty || 0);
    var remainingPack = Number(row.dataset.remainingPack || 0);
    var originalQty = Number(row.dataset.originalQty || 0);
    var originalPack = Number(row.dataset.originalPack || 0);
    var uomPack = row.dataset.uomPack;

    var qty = Math.max(0, Math.min(Number(qtyInput.value || 0), remainingQty));
    var pack = 0;

    if(uomPack === 'KG'){
        pack = qty;
    }else if(originalQty > 0 && originalPack > 0){
        pack = qty / (originalQty / originalPack);
    }

    if(remainingPack > 0) pack = Math.min(pack, remainingPack);
    qtyInput.value = trimDecimal(qty);
    packInput.value = trimDecimal(pack);
    calculateRow(row);
}

function syncFromPack(row){
    var qtyInput = row.querySelector('.return-qty');
    var packInput = row.querySelector('.return-pack');
    var remainingQty = Number(row.dataset.remainingQty || 0);
    var remainingPack = Number(row.dataset.remainingPack || 0);
    var originalQty = Number(row.dataset.originalQty || 0);
    var originalPack = Number(row.dataset.originalPack || 0);
    var uomPack = row.dataset.uomPack;

    var pack = Math.max(0, Math.min(Number(packInput.value || 0), remainingPack));
    var qty = 0;

    if(uomPack === 'KG'){
        qty = pack;
    }else if(originalQty > 0 && originalPack > 0){
        qty = pack * (originalQty / originalPack);
    }

    qty = Math.min(qty, remainingQty);
    qtyInput.value = trimDecimal(qty);
    packInput.value = trimDecimal(pack);
    calculateRow(row);
}

function calculateRow(row){
    var checked = row.querySelector('.item-selected').checked;
    var qtyPackReturn = checked
        ? Number(row.querySelector('.return-pack').value || 0)
        : 0;
    var price = Number(row.dataset.price || 0);

    /*
     * Mengikuti add_sales_order:
     * Return Subtotal = Price x Qty Pack Return
     */
    var subtotal = price * qtyPackReturn;

    row.dataset.returnSubtotal = subtotal.toFixed(2);
    row.querySelector('.return-subtotal-display').textContent = fmtMoney(subtotal);
    calculateTotals();
}

function calculateTotals(){
    var returnAmount = 0;
    document.querySelectorAll('.item-row').forEach(function(row){
        returnAmount += Number(row.dataset.returnSubtotal || 0);
    });

    var paymentBalance = Number(currentHeader && currentHeader.payment_balance || 0);
    var after = Math.max(0, paymentBalance - returnAmount);
    $('#return_amount_display').val(fmtMoney(returnAmount));
    $('#balance_after_display').val(fmtMoney(after));
}

$(document).ready(function(){
    if(typeof flatpickr !== 'undefined'){
        flatpickr('.datepicker', {dateFormat:'d-M-Y', allowInput:true, disableMobile:true});
    }

    $('#customer_ref').select2({
        width:'100%',
        placeholder:'-- Pilih Customer --',
        allowClear:true
    });

    $('#order_no').select2({
        width:'100%',
        placeholder:'-- Pilih Sales Order --',
        allowClear:true
    });

    $('#shipping_ref').select2({
        width:'100%',
        placeholder:'-- Pilih Shipping --',
        allowClear:true
    });

    $('#customer_ref').on('change', function(){
        var customerId = this.value;
        var selectedOption = this.options[this.selectedIndex];
        $('#customer_name').val(selectedOption ? (selectedOption.getAttribute('data-customer-name') || '') : '');
        resetOrderShippingAndItems();

        if(!customerId) return;

        $('#order_no')
            .empty()
            .append(new Option('Loading Sales Order...', ''))
            .prop('disabled', true)
            .trigger('change.select2');

        fetch('modul/transaksi/ajax_return_orders.php?customer_id=' + encodeURIComponent(customerId), {headers:{'Accept':'application/json'}})
            .then(function(response){
                return response.json().then(function(data){
                    if(!response.ok) throw new Error(data.message || ('HTTP ' + response.status));
                    return data;
                });
            })
            .then(function(result){
                var select = $('#order_no');
                select.empty().append(new Option('-- Pilih Sales Order --', ''));

                (result.data || []).forEach(function(row){
                    var inventorySummary = row.inventory_summary || '-';
                    var label = (row.order_no || '') + ' | ' + fmtDate(row.order_date || '') + ' | ' + inventorySummary;
                    var option = new Option(label, row.order_no || '');
                    option.setAttribute('data-inventory-summary', inventorySummary);
                    option.setAttribute('data-shipping-count', row.shipping_count || 0);
                    select.append(option);
                });

                select.prop('disabled', false).trigger('change.select2');

                if(!(result.data || []).length){
                    $('#order_inventory_info').html('<span style="color:#dc3545;">Tidak ada Sales Order berinvoice yang masih memiliki quantity untuk diretur.</span>');
                }
            })
            .catch(function(error){
                alert('Gagal mengambil daftar Sales Order: ' + error.message);
                resetOrderShippingAndItems();
            });
    });

    $('#order_no').on('change', function(){
        var orderNo = this.value;
        var selectedOption = this.options[this.selectedIndex];
        var inventorySummary = selectedOption ? (selectedOption.getAttribute('data-inventory-summary') || '') : '';
        var shippingCount = selectedOption ? (selectedOption.getAttribute('data-shipping-count') || '0') : '0';
        $('#order_inventory_info').html(orderNo ? '<b>Inventory:</b> ' + escHtml(inventorySummary) + ' &nbsp; | &nbsp; <b>Total Shipping:</b> ' + escHtml(shippingCount) : '');
        resetShippingAndItems();
        if(!orderNo) return;

        $('#shipping_ref').empty().append(new Option('Loading shipping...', '')).prop('disabled', true).trigger('change.select2');

        fetch('modul/transaksi/ajax_return_shippings.php?order_no=' + encodeURIComponent(orderNo), {headers:{'Accept':'application/json'}})
            .then(function(response){
                if(!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(function(result){
                var select = $('#shipping_ref');
                select.empty().append(new Option('-- Pilih Shipping --', ''));

                (result.data || []).forEach(function(row){
                    var value = JSON.stringify({shipping_no:row.shipping_no, invoice_no:row.invoice_no});
                    var label = (row.shipping_no || '') + ' | ' + fmtDate(row.shipping_date || '') + ' | ' + (row.invoice_no || '');
                    select.append(new Option(label, value));
                });

                select.prop('disabled', false).trigger('change.select2');
            })
            .catch(function(error){
                alert('Gagal mengambil daftar Shipping: ' + error.message);
                resetShippingAndItems();
            });
    });

    $('#shipping_ref').on('change', function(){
        if(!this.value){
            clearItems();
            return;
        }

        var ref;
        try {
            ref = JSON.parse(this.value);
        } catch(e) {
            alert('Referensi Shipping tidak valid.');
            clearItems();
            return;
        }

        $('#shipping_no').val(ref.shipping_no || '');
        $('#invoice_no').val(ref.invoice_no || '');
        $('#itemTable tbody').html('<tr><td colspan="13" style="text-align:center;color:#777;padding:15px;"><i class="fa fa-spinner fa-spin"></i> Loading detail inventory...</td></tr>');

        var url = 'modul/transaksi/ajax_return_shipping_detail.php?shipping_no=' + encodeURIComponent(ref.shipping_no) + '&invoice_no=' + encodeURIComponent(ref.invoice_no);

        fetch(url, {headers:{'Accept':'application/json'}})
            .then(function(response){
                return response.json().then(function(data){
                    if(!response.ok) throw new Error(data.message || ('HTTP ' + response.status));
                    return data;
                });
            })
            .then(function(result){
                if(!result.success) throw new Error(result.message || 'Gagal mengambil detail.');
                currentHeader = result.header;
                fillHeader(result.header);
                renderItems(result.details);
                calculateTotals();
            })
            .catch(function(error){
                alert('Gagal mengambil detail Return: ' + error.message);
                clearItems();
            });
    });

    $('#returnForm').on('submit', function(event){
        var selected = Array.from(document.querySelectorAll('.item-selected:checked')).some(function(cb){
            return Number(cb.closest('tr').querySelector('.return-qty').value || 0) > 0;
        });

        if(!$('#customer_ref').val()){
            event.preventDefault();
            alert('Customer Name wajib dipilih.');
            return false;
        }
        if(!$('#order_no').val()){
            event.preventDefault();
            alert('Sales Order wajib dipilih.');
            return false;
        }
        if(!$('#shipping_no').val() || !$('#invoice_no').val()){
            event.preventDefault();
            alert('Shipping No. wajib dipilih.');
            return false;
        }
        if(!selected){
            event.preventDefault();
            alert('Minimal pilih satu barang dan isi Qty Return lebih dari 0.');
            return false;
        }

        $('#saveButton').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        return true;
    });
});
</script>
