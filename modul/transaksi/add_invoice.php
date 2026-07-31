<?php
// modul/transaksi/add_invoice.php
// REVISI: Pilih Sales Order terlebih dahulu, lalu pilih Shipping yang akan dibuat invoice.
// UPDATE: Jika inventory_name mengandung "ONGKOS", subtotal = price (tidak dikalikan qty)

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

function generateInvoiceNo($conn) {
    $year = date('Y');
    $prefix = "INV/$year/";
    $stmt = mysqli_prepare($conn, "
        SELECT invoice_no
        FROM head_invoice
        WHERE invoice_no LIKE CONCAT(?, '%')
        ORDER BY invoice_no DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 's', $prefix);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if ($row && !empty($row['invoice_no'])) {
        $last = (int)substr($row['invoice_no'], -6);
        $next = $last + 1;
    } else {
        $next = 1;
    }

    return $prefix . str_pad((string)$next, 6, '0', STR_PAD_LEFT);
}

$invoice_no = generateInvoiceNo($conn);
$invoice_date = date('Y-m-d');
$invoice_date_display = formatDateDisplay($invoice_date);

$order_rs = mysqli_query($conn, "
    SELECT DISTINCT
        so.order_no,
        so.order_date,
        so.customer_id,
        COALESCE(NULLIF(so.customer_name, ''), c.customer, '') AS customer_name,
        COALESCE(NULLIF(so.customer_address, ''), c.address, '') AS customer_address,
        COALESCE(NULLIF(so.customer_city, ''), c.city, '') AS customer_city,
        so.payment_type,
        so.payment_term,
        so.days,
        so.currency,
        so.station,
        so.grand_total,
        so.down_payment,
        so.remarks
    FROM head_sales_order so
    INNER JOIN hed_shipping hs
        ON hs.order_no = so.order_no
    LEFT JOIN m_customer c
        ON c.customer_id = so.customer_id
    WHERE NOT EXISTS (
        SELECT 1
        FROM det_invoice di
        WHERE di.shipping_no = hs.shipping_no
    )
    ORDER BY
        so.order_date DESC,
        so.order_no DESC
");

if (!$order_rs) {
    die('Gagal mengambil daftar Sales Order: ' . mysqli_error($conn));
}

$default_gudang_id = 'FC-02';
$gudang_options = [];
$gudang_rs = mysqli_query($conn, "
    SELECT gudang_id, name, station
    FROM m_gudang
    ORDER BY 
        CASE WHEN gudang_id = '$default_gudang_id' THEN 0 ELSE 1 END,
        gudang_id ASC
");
if ($gudang_rs) {
    while ($g = mysqli_fetch_assoc($gudang_rs)) {
        $gudang_options[] = $g;
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
.invoice-wrap * { box-sizing:border-box; font-family:'Segoe UI','Consolas','Cascadia Code',monospace; }
.invoice-wrap { background:#f0f2f5; padding:12px; color:#212529; font-size:11px; }
.panel-row { display:flex; gap:10px; margin-bottom:10px; }
.inv-panel { flex:1; background:#fff; border:1px solid #dee2e6; border-radius:4px; overflow:hidden; }
.inv-panel-header { background:#e9ecef; border-bottom:1px solid #dee2e6; padding:6px 12px; font-size:11px; font-weight:bold; color:#495057; display:flex; align-items:center; gap:6px; }
.inv-panel-body { padding:12px; }
.ff { margin-bottom:8px; }
.ff label { display:block; font-size:10px; font-weight:700; color:#0d6efd; margin-bottom:3px; text-transform:uppercase; }
.ff input,.ff select,.ff textarea { width:100%; background:#fff; border:1px solid #ced4da; border-radius:3px; font-size:11px; padding:5px 8px; outline:none; }
.ff input[readonly],.ff textarea[readonly] { background:#e9ecef; color:#555; }
.inv-panel-full { background:#fff; border:1px solid #dee2e6; border-radius:4px; overflow:hidden; margin-bottom:10px; }
.detail-toolbar { background:#f8f9fa; border-bottom:1px solid #dee2e6; padding:8px 12px; display:flex; gap:8px; align-items:center; }
.btn-vs { padding:6px 12px; font-size:11px; font-weight:bold; border:none; border-radius:3px; cursor:pointer; display:inline-flex; align-items:center; gap:5px; text-decoration:none; }
.btn-primary { background:#0d6efd; color:#fff; }
.btn-success { background:#198754; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-danger { background:#dc3545; color:#fff; padding:4px 8px; font-size:10px; border-radius:2px; }
.btn-warning { background:#ffc107; color:#000; }
.required { color:red; font-weight:bold; }
.detail-table-wrap { max-height:360px; overflow:auto; }
.detail-table { width:100%; min-width:1150px; border-collapse:collapse; font-size:11px; }
.detail-table th { background:#e9ecef; padding:8px 6px; border:1px solid #dee2e6; position:sticky; top:0; z-index:2; font-size:10px; text-transform:uppercase; text-align:center; }
.detail-table td { padding:5px 6px; border:1px solid #dee2e6; background:#fff; }
.detail-table input { width:100%; border:none; background:transparent; font-size:11px; padding:2px; outline:none; }
.detail-table select { width:100%; border:1px solid #ced4da; background:#fff; font-size:11px; padding:3px 5px; outline:none; border-radius:3px; }
.expand-btn { width:22px; height:22px; border:1px solid #0d6efd; background:#fff; color:#0d6efd; border-radius:3px; font-weight:bold; line-height:18px; cursor:pointer; }
.expand-btn:hover { background:#0d6efd; color:#fff; }
.shipping-detail-row td { background:#f8f9fa!important; padding:0!important; }
.shipping-detail-box { padding:8px 10px; border-left:3px solid #0d6efd; }
.shipping-detail-table { width:100%; border-collapse:collapse; font-size:10.5px; background:#fff; }
.shipping-detail-table th { background:#dde8f7; border:1px solid #cfd8e3; padding:5px; text-align:center; text-transform:uppercase; }
.shipping-detail-table td { border:1px solid #e0e5ea; padding:5px 6px; background:#fff; }
.shipping-detail-table .text-right { text-align:right; }
.shipping-detail-table .text-center { text-align:center; }
.shipping-detail-table .inventory-name { white-space:normal; word-break:break-word; min-width:240px; }
.shipping-detail-table .ongkos-row { background-color: #fff3cd !important; }
.shipping-detail-table .ongkos-row td { background-color: #fff3cd !important; }
.summary-panel { display:grid; grid-template-columns: repeat(4, 1fr); gap:10px; padding:12px; background:#fff; border:1px solid #dee2e6; border-radius:4px; margin-bottom:10px; }
.summary-box label { display:block; font-size:10px; font-weight:bold; color:#0d6efd; text-transform:uppercase; margin-bottom:3px; }
.summary-box input { width:100%; border:1px solid #ced4da; border-radius:3px; padding:6px 8px; font-size:12px; font-weight:bold; text-align:right; background:#f8f9fa; }
.actionbar { display:flex; justify-content:flex-end; gap:10px; padding:10px 0; }
.select2-container--default .select2-selection--single { height:28px!important; padding:2px 0!important; font-size:11px!important; }
.select2-container--default .select2-selection--single .select2-selection__rendered { line-height:24px!important; font-size:11px!important; }
@media(max-width:900px){ .panel-row{flex-direction:column;} .summary-panel{grid-template-columns:1fr;} }
</style>

<div class="invoice-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <form method="POST" action="index.php?page=save_invoice" id="formInvoice">
        <div class="panel-row">
            <div class="inv-panel">
                <div class="inv-panel-header"><i class="fa fa-file-invoice"></i> Invoice Information</div>
                <div class="inv-panel-body">
                    <div class="ff">
                        <label>Invoice No.</label>
                        <input type="text" name="invoice_no" value="<?= h($invoice_no) ?>" readonly style="font-weight:bold;color:#0d6efd;">
                    </div>
                    <div class="ff">
                        <label>Invoice Date <span class="required">*</span></label>
                        <input type="text" name="invoice_date" class="datepicker" value="<?= h($invoice_date_display) ?>" required>
                    </div>
                    <div class="ff">
                        <label>Remarks Invoice</label>
                        <textarea name="remarks_invoice" rows="3" placeholder="Catatan invoice..."></textarea>
                    </div>
                </div>
            </div>

            <div class="inv-panel">
                <div class="inv-panel-header"><i class="fa fa-file-lines"></i> Sales Order</div>
                <div class="inv-panel-body">
                    <div class="ff">
                        <label>Sales Order <span class="required">*</span></label>
                        <select name="order_no" id="order_no" required>
                            <option value="">-- Pilih Sales Order --</option>
                            <?php while ($o = mysqli_fetch_assoc($order_rs)): ?>
                                <option
                                    value="<?= h($o['order_no']) ?>"
                                    data-order-date="<?= h($o['order_date']) ?>"
                                    data-customer-id="<?= h($o['customer_id']) ?>"
                                    data-customer-name="<?= h($o['customer_name']) ?>"
                                    data-address="<?= h($o['customer_address']) ?>"
                                    data-city="<?= h($o['customer_city']) ?>"
                                    data-payment-type="<?= h($o['payment_type']) ?>"
                                    data-payment-term="<?= h($o['payment_term']) ?>"
                                    data-days="<?= h($o['days']) ?>"
                                    data-currency="<?= h($o['currency']) ?>"
                                    data-station="<?= h($o['station']) ?>"
                                    data-grand-total="<?= h($o['grand_total']) ?>"
                                    data-down-payment="<?= h($o['down_payment']) ?>"
                                    data-remarks="<?= h($o['remarks']) ?>"
                                >
                                    <?= h($o['order_no']) ?> | <?= h(formatDateDisplay($o['order_date'])) ?> | <?= h($o['customer_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <input type="hidden" name="order_date" id="order_date">
                    <input type="hidden" name="currency" id="currency" value="IDR">
                    <input type="hidden" name="payment_type" id="payment_type">
                    <input type="hidden" name="payment_term" id="payment_term" value="30">

                    <div class="ff">
                        <label>Order Date</label>
                        <input type="text" id="order_date_display" readonly>
                    </div>
                    <div class="ff">
                        <label>Station</label>
                        <input type="text" name="station" id="station" value="FACTORY" placeholder="FACTORY">
                    </div>
                    <div class="ff">
                        <label>Payment Type</label>
                        <input type="text" id="payment_type_display" readonly>
                    </div>
                    <div class="ff">
                        <label>Payment Term / Days</label>
                        <input type="number" name="days" id="days" value="30" min="0" step="1">
                    </div>
                    <div class="ff">
                        <label>Remarks Sales Order</label>
                        <textarea id="remarks_so" rows="2" readonly></textarea>
                    </div>
                </div>
            </div>

            <div class="inv-panel">
                <div class="inv-panel-header"><i class="fa fa-building-user"></i> Customer Information</div>
                <div class="inv-panel-body">
                    <div class="ff">
                        <label>Customer Name</label>
                        <input type="text" id="customer_name_display" readonly>
                        <input type="hidden" name="customer_id" id="customer_id_hidden">
                        <input type="hidden" name="customer_name" id="customer_name">
                    </div>
                    <div class="ff">
                        <label>Customer Address</label>
                        <textarea name="customer_address" id="customer_address" rows="2" readonly></textarea>
                    </div>
                    <div class="ff">
                        <label>Customer City</label>
                        <input type="text" name="customer_city" id="customer_city" readonly>
                    </div>
                </div>
            </div>
            </div>
        </div>

        <div class="inv-panel-full">
            <div class="inv-panel-header"><i class="fa fa-truck"></i> Daftar Shipping / Surat Jalan</div>
            <div class="detail-toolbar">
                <span style="font-size:10px;color:#777;"><i class="fa fa-info-circle"></i> Setelah Sales Order dipilih, centang satu atau beberapa Shipping yang akan dibuatkan invoice. Shipping yang sudah pernah masuk invoice otomatis tidak muncul lagi.</span>
            </div>
            <div class="detail-table-wrap">
                <table class="detail-table" id="shippingTable">
                    <thead>
                        <tr>
                            <th style="width:35px;">Pilih</th>
                            <th style="width:35px;">+</th>
                            <th style="width:150px;">Shipping No</th>
                            <th style="width:110px;">Shipping Date</th>
                            <th style="width:150px;">Order No</th>
                            <th style="width:180px;">Gudang / Warehouse</th>
                            <th style="width:140px;">Subtotal</th>
                            <th style="width:140px;">Total</th>
                            <th>Remarks Shipping</th>
                        </tr>
                    </thead>
                    <tbody id="shippingBody">
                        <tr><td colspan="9" style="text-align:center;color:#777;padding:15px;">Pilih Sales Order terlebih dahulu.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="summary-panel">
            <div class="summary-box">
                <label>Grand Total</label>
                <input type="text" id="grand_total_display" value="0,00" readonly>
                <input type="hidden" name="grand_total" id="grand_total" value="0.00">
                <input type="hidden" name="subtotal" id="subtotal" value="0.00">
            </div>
            <div class="summary-box">
                <label>Down Payment / Titip</label>
                <input type="text" id="down_payment_display" value="0,00" readonly>
                <input type="hidden" name="down_payment" id="down_payment" value="0.00">
                <input type="hidden" name="titip_available" id="titip_available" value="0.00">
                <input type="hidden" name="titip_applied" id="titip_applied" value="0.00">
            </div>
            <div class="summary-box">
                <label>Payment Balance</label>
                <input type="text" id="payment_balance_display" value="0,00" readonly>
                <input type="hidden" name="payment_balance" id="payment_balance" value="0.00">
            </div>
            <div class="summary-box">
                <label>Piutang</label>
                <input type="text" id="piutang_display" value="0,00" readonly>
                <input type="hidden" name="piutang" id="piutang" value="0.00">
            </div>
        </div>

        <div class="actionbar">
            <button type="button" class="btn-vs btn-secondary" onclick="window.location.href='index.php?page=invoice'"><i class="fa fa-times"></i> Batal / Kembali</button>
            <button type="submit" class="btn-vs btn-success" id="btnSave"><i class="fa fa-save"></i> Simpan Invoice</button>
        </div>
    </form>
</div>

<script>
function escHtml(s){ if(s===null||s===undefined)return ''; return String(s).replace(/[&<>"']/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];}); }
function parseNum(v){ return parseFloat(String(v||'0').replace(/\./g,'').replace(',','.').replace(/[^\d.-]/g,'')) || 0; }
function fmtMoney(n){ return (parseFloat(n||0)).toLocaleString('id-ID',{minimumFractionDigits:2, maximumFractionDigits:2}); }
function fmtDate(dateStr){ if(!dateStr) return ''; var d = new Date(dateStr + 'T00:00:00'); if(isNaN(d)) return dateStr; var months=['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des']; return String(d.getDate()).padStart(2,'0')+'-'+months[d.getMonth()]+'-'+d.getFullYear(); }
function indoToSql(v){ if(!v) return ''; var p=v.split('-'); if(p.length!==3) return v; var m={'Jan':'01','Feb':'02','Mar':'03','Apr':'04','Mei':'05','May':'05','Jun':'06','Jul':'07','Agu':'08','Aug':'08','Sep':'09','Okt':'10','Oct':'10','Nov':'11','Des':'12','Dec':'12'}; return m[p[1]] ? p[2]+'-'+m[p[1]]+'-'+p[0] : v; }

var selectedOrder = null;
var depositBalance = 0;
var gudangOptions = <?= json_encode($gudang_options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
var defaultGudangId = <?= json_encode($default_gudang_id); ?>;

function getShippingItems(s){
    return s.items || s.details || s.detail || s.lines || [];
}

// Fungsi untuk mengecek apakah inventory_name mengandung "ONGKOS" (case insensitive)
function isOngkosItem(inventoryName){
    if(!inventoryName) return false;
    return inventoryName.toUpperCase().indexOf('ONGKOS') !== -1;
}

// Fungsi untuk menghitung subtotal item (dengan pengecekan ONGKOS)
function calculateItemSubtotal(item){
    var qty = parseNum(item.qty || item.quantity || 0);
    var qtyPack = parseNum(item.qty_pack || item.quantity_pack || 0);
    var price = parseNum(item.price || item.unit_price || 0);
    var inventoryName = item.inventory_name || '';
    
    // Jika inventory_name mengandung "ONGKOS", subtotal = price (tidak dikalikan qty)
    if(isOngkosItem(inventoryName)){
        return price;
    }
    
    // Jika tidak, subtotal = price * qty (atau qty_pack jika ada)
    var effectiveQty = qtyPack > 0 ? qtyPack : qty;
    return price * effectiveQty;
}

function getShippingSubtotal(s){
    var subtotal = parseNum(s.subtotal || s.shipping_subtotal || s.sub_total || s.dpp || 0);
    if(subtotal > 0) return subtotal;

    var items = getShippingItems(s);
    if(items && items.length){
        var sum = 0;
        items.forEach(function(it){
            // Gunakan fungsi calculateItemSubtotal untuk menghitung subtotal per item
            sum += calculateItemSubtotal(it);
        });
        if(sum > 0) return sum;
    }

    return parseNum(s.total || s.grand_total || 0);
}

function getShippingTotal(s){
    var total = parseNum(s.total || s.grand_total || s.amount_total || 0);
    if(total > 0) return total;
    return getShippingSubtotal(s);
}

function renderWarehouseSelect(shippingNo, selectedId){
    selectedId = selectedId || defaultGudangId;
    var html = '<select class="warehouse-select" name="warehouse_id[' + escHtml(shippingNo) + ']">';
    gudangOptions.forEach(function(g){
        var gudangId = g.gudang_id || '';
        var label = (g.name || gudangId);
        var selected = gudangId === selectedId ? ' selected' : '';
        html += '<option value="' + escHtml(gudangId) + '"' + selected + '>' + escHtml(label) + '</option>';
    });
    html += '</select>';
    return html;
}

function renderShippingDetailTable(items){
    if(!items || !items.length){
        return '<div class="shipping-detail-box" style="color:#777;">Detail item belum tersedia dari response ajax. Pastikan <b>ajax_invoice.php</b> mengirim array <b>items/details</b> atau menyediakan endpoint <b>get_shipping_details</b>.</div>';
    }

    var html = '<div class="shipping-detail-box">'+
        '<table class="shipping-detail-table">'+
        '<thead><tr>'+
        '<th style="width:120px;">Inventory ID</th>'+
        '<th>Inventory Name</th>'+
        '<th style="width:90px;">Qty</th>'+
        '<th style="width:80px;">UoM</th>'+
        '<th style="width:90px;">Qty Pack</th>'+
        '<th style="width:90px;">UoM Pack</th>'+
        '<th style="width:120px;">Price</th>'+
        '<th style="width:130px;">Subtotal</th>'+
        '<th style="width:100px;">Status</th>'+
        '</tr></thead><tbody>';

    items.forEach(function(it){
        var qty = parseNum(it.qty || it.quantity || 0);
        var qtyPack = parseNum(it.qty_pack || it.quantity_pack || 0);
        var price = parseNum(it.price || it.unit_price || 0);
        var inventoryName = it.inventory_name || '';
        
        // Gunakan fungsi calculateItemSubtotal untuk menghitung subtotal
        var sub = calculateItemSubtotal(it);
        
        // Tentukan apakah item adalah ONGKOS
        var isOngkos = isOngkosItem(inventoryName);
        
        // Tentukan class row (highlight untuk ONGKOS)
        var rowClass = isOngkos ? 'ongkos-row' : '';
        
        // Tentukan status display
        var statusDisplay = isOngkos ? 
            '<span style="color:#856404;font-weight:bold;">⚠️ ONGKOS/JASA</span>' : 
            '<span style="color:#28a745;">✓ Produk</span>';
        
        // Tentukan display subtotal dengan info jika ONGKOS
        var subtotalDisplay = isOngkos ? 
            'Rp '+fmtMoney(sub)+' <span style="font-size:9px;color:#856404;">(price)</span>' : 
            'Rp '+fmtMoney(sub);

        html += '<tr class="' + rowClass + '">'+
            '<td><code>'+escHtml(it.inventory_id || '')+'</code></td>'+
            '<td class="inventory-name">'+escHtml(inventoryName)+'</td>'+
            '<td class="text-right">'+fmtMoney(qty)+'</td>'+
            '<td class="text-center">'+escHtml(it.uom || '')+'</td>'+
            '<td class="text-right">'+fmtMoney(qtyPack)+'</td>'+
            '<td class="text-center">'+escHtml(it.uom_pack || it.uom_detail || '')+'</td>'+
            '<td class="text-right">Rp '+fmtMoney(price)+'</td>'+
            '<td class="text-right"><b>'+subtotalDisplay+'</b></td>'+
            '<td class="text-center">'+statusDisplay+'</td>'+
        '</tr>';
    });

    html += '</tbody></table></div>';
    return html;
}

function loadCustomerDeposit(customerId){
    depositBalance = 0;
    recalcTotals();

    if(!customerId){
        return;
    }

    fetch(
        'modul/transaksi/ajax_invoice.php?ajax=get_customer_deposit&customer_id=' +
        encodeURIComponent(customerId),
        {headers:{'Accept':'application/json'}}
    )
        .then(function(r){
            if(!r.ok){
                throw new Error('HTTP ' + r.status);
            }
            return r.json();
        })
        .then(function(res){
            depositBalance = res.status === 'success'
                ? parseFloat(res.balance || 0)
                : 0;
            recalcTotals();
        })
        .catch(function(){
            depositBalance = 0;
            recalcTotals();
        });
}

function resetOrderFields(){
    selectedOrder = null;
    depositBalance = 0;

    $('#order_date,#payment_type').val('');
    $('#currency').val('IDR');
    $('#station').val('FACTORY');
    $('#days').val('30');
    $('#payment_term').val('30');

    $('#order_date_display,#payment_type_display,#remarks_so').val('');
    $('#customer_id_hidden,#customer_name,#customer_name_display,#customer_address,#customer_city').val('');

    $('#shippingBody').html(
        '<tr><td colspan="9" style="text-align:center;color:#777;padding:15px;">' +
        'Pilih Sales Order terlebih dahulu.</td></tr>'
    );

    recalcTotals();
}

function loadOrderShippings(orderNo){
    if(!orderNo){ resetOrderFields(); return; }
    $('#shippingBody').html('<tr><td colspan="9" style="text-align:center;color:#777;padding:15px;">Loading shipping...</td></tr>');

    fetch('modul/transaksi/ajax_invoice.php?ajax=get_order_shippings&order_no=' + encodeURIComponent(orderNo), {headers:{'Accept':'application/json'}})
        .then(r => r.json())
        .then(res => {
            if(res.status !== 'success') throw new Error(res.message || 'Gagal load shipping');
            if(!res.data.length){
                $('#shippingBody').html('<tr><td colspan="9" style="text-align:center;color:#777;padding:15px;">Tidak ada antrian shipping untuk order ini.</td></tr>');
                recalcTotals();
                return;
            }

            var html = '';
            var shippingRows = Array.isArray(res.data) ? res.data : [];

            if(!shippingRows.length){
                $('#shippingBody').html(
                    '<tr><td colspan="9" style="text-align:center;color:#777;padding:15px;">' +
                    'Tidak ada shipping yang belum masuk invoice untuk Sales Order ini.</td></tr>'
                );
                recalcTotals();
                return;
            }

            shippingRows.forEach(function(s){
                var subtotal = getShippingSubtotal(s);
                var total = getShippingTotal(s);
                var shippingNo = s.shipping_no || '';
                var selectedWarehouse = s.warehouse_id || s.gudang_id || defaultGudangId;
                var items = getShippingItems(s);

                html += '<tr class="shipping-main-row" data-shipping-no="'+escHtml(shippingNo)+'">'+
                    '<td style="text-align:center;"><input type="checkbox" class="ship-check" value="'+escHtml(shippingNo)+'" data-subtotal="'+escHtml(subtotal)+'" data-total="'+escHtml(total)+'" form="formInvoice"></td>'+
                    '<td style="text-align:center;"><button type="button" class="expand-btn" data-shipping-no="'+escHtml(shippingNo)+'" data-loaded="'+(items.length ? '1' : '0')+'">+</button></td>'+
                    '<td style="font-weight:bold;color:#0d6efd;">'+escHtml(shippingNo)+'</td>'+
                    '<td>'+escHtml(fmtDate(s.shipping_date))+'<input type="hidden" name="shipping_date_'+escHtml(shippingNo)+'" value="'+escHtml(s.shipping_date)+'"></td>'+
                    '<td>'+escHtml(s.order_no)+'</td>'+
                    '<td>'+renderWarehouseSelect(shippingNo, selectedWarehouse)+'</td>'+
                    '<td style="text-align:right;">Rp '+fmtMoney(subtotal)+'</td>'+
                    '<td style="text-align:right;font-weight:bold;">Rp '+fmtMoney(total)+'</td>'+
                    '<td>'+escHtml(s.remarks_shipping || '')+'</td>'+
                '</tr>';

                html += '<tr class="shipping-detail-row" id="ship-detail-'+escHtml(shippingNo).replace(/[^a-zA-Z0-9_-]/g, '_')+'" style="display:none;">'+
                    '<td colspan="9">'+renderShippingDetailTable(items)+'</td>'+
                '</tr>';
            });
            $('#shippingBody').html(html);
            recalcTotals();
        })
        .catch(err => { alert('Gagal load shipping: '+err.message); $('#shippingBody').html('<tr><td colspan="9" style="text-align:center;color:#dc3545;padding:15px;">Gagal load shipping.</td></tr>'); });
}

function recalcTotals(){
    var subtotal = 0;
    var grand = 0;
    $('.ship-check:checked').each(function(){
        subtotal += parseFloat($(this).data('subtotal')) || 0;
        grand += parseFloat($(this).data('total')) || 0;
    });

    var soDp = selectedOrder ? parseFloat(selectedOrder.down_payment || 0) : 0;
    var availableTitip = depositBalance || 0;
    var appliedTitip = Math.min(availableTitip, Math.max(grand - soDp, 0));
    var totalDpTitip = Math.min(soDp + appliedTitip, grand);
    var paymentBalance = Math.max(grand - totalDpTitip, 0);
    var piutang = paymentBalance;

    $('#subtotal').val(subtotal.toFixed(2));
    $('#grand_total').val(grand.toFixed(2));
    $('#grand_total_display').val(fmtMoney(grand));
    $('#down_payment').val(totalDpTitip.toFixed(2));
    $('#titip_available').val(availableTitip.toFixed(2));
    $('#titip_applied').val(appliedTitip.toFixed(2));
    $('#down_payment_display').val(fmtMoney(totalDpTitip));
    $('#payment_balance').val(paymentBalance.toFixed(2));
    $('#payment_balance_display').val(fmtMoney(paymentBalance));
    $('#piutang').val(piutang.toFixed(2));
    $('#piutang_display').val(fmtMoney(piutang));
}


$(document).on('click', '.expand-btn', function(){
    var btn = $(this);
    var shippingNo = btn.data('shipping-no');
    var rowId = '#ship-detail-' + String(shippingNo || '').replace(/[^a-zA-Z0-9_-]/g, '_');
    var detailRow = $(rowId);

    if(detailRow.is(':visible')){
        detailRow.hide();
        btn.text('+');
        return;
    }

    detailRow.show();
    btn.text('-');

    if(btn.attr('data-loaded') === '1'){
        return;
    }

    detailRow.find('td').html('<div class="shipping-detail-box" style="color:#777;"><i class="fa fa-spinner fa-spin"></i> Loading detail item...</div>');

    fetch('modul/transaksi/ajax_invoice.php?ajax=get_shipping_details&shipping_no=' + encodeURIComponent(shippingNo), {headers:{'Accept':'application/json'}})
        .then(r => r.json())
        .then(res => {
            if(res.status !== 'success') throw new Error(res.message || 'Gagal load detail shipping');
            var items = res.data || res.items || res.details || [];
            detailRow.find('td').html(renderShippingDetailTable(items));
            btn.attr('data-loaded', '1');
        })
        .catch(err => {
            detailRow.find('td').html('<div class="shipping-detail-box" style="color:#dc3545;">Gagal load detail item: '+escHtml(err.message)+'</div>');
        });
});

$(document).ready(function(){
    if(typeof flatpickr !== 'undefined'){
        flatpickr('.datepicker', { dateFormat:'d-M-Y', allowInput:true, disableMobile:true });
    }
    $('#order_no').select2({
        width:'100%',
        placeholder:'-- Pilih Sales Order --',
        allowClear:true
    });

    $('#order_no').on('change', function(){
        var opt = this.options[this.selectedIndex];

        if(!opt || !this.value){
            resetOrderFields();
            return;
        }

        selectedOrder = {
            order_no: this.value,
            order_date: opt.getAttribute('data-order-date') || '',
            customer_id: opt.getAttribute('data-customer-id') || '',
            customer_name: opt.getAttribute('data-customer-name') || '',
            customer_address: opt.getAttribute('data-address') || '',
            customer_city: opt.getAttribute('data-city') || '',
            payment_type: opt.getAttribute('data-payment-type') || '',
            payment_term: opt.getAttribute('data-payment-term') || '',
            days: opt.getAttribute('data-days') || '30',
            currency: opt.getAttribute('data-currency') || 'IDR',
            station: opt.getAttribute('data-station') || 'FACTORY',
            grand_total: parseFloat(opt.getAttribute('data-grand-total') || 0),
            down_payment: parseFloat(opt.getAttribute('data-down-payment') || 0),
            remarks: opt.getAttribute('data-remarks') || ''
        };

        $('#customer_id_hidden').val(selectedOrder.customer_id);
        $('#customer_name').val(selectedOrder.customer_name);
        $('#customer_name_display').val(selectedOrder.customer_name);
        $('#customer_address').val(selectedOrder.customer_address);
        $('#customer_city').val(selectedOrder.customer_city);

        $('#order_date').val(selectedOrder.order_date);
        $('#order_date_display').val(fmtDate(selectedOrder.order_date));

        $('#payment_type').val(selectedOrder.payment_type);
        $('#payment_type_display').val(selectedOrder.payment_type);

        var selectedDays =
            selectedOrder.days ||
            selectedOrder.payment_term ||
            '30';

        $('#days').val(selectedDays);
        $('#payment_term').val(selectedOrder.payment_term || '');
        $('#currency').val(selectedOrder.currency || 'IDR');
        $('#station').val(
            (selectedOrder.station || 'FACTORY').toUpperCase()
        );
        $('#remarks_so').val(selectedOrder.remarks);

        loadOrderShippings(selectedOrder.order_no);
        loadCustomerDeposit(selectedOrder.customer_id);
        recalcTotals();
    });

    $(document).on('change', '.ship-check', recalcTotals);

    $('#days').on('input change', function(){
        var d = parseInt($(this).val(), 10);
        if (isNaN(d) || d < 0) d = 30;
        $('#payment_term').val(String(d));
    });

    $('#formInvoice').on('submit', function(e){
        var $form = $(this);
        var $checkedShipping = $('.ship-check:checked');

        if(!$('#customer_id_hidden').val()){
            e.preventDefault();
            alert('Data customer dari Sales Order tidak ditemukan.');
            return false;
        }

        if(!$('#order_no').val()){
            e.preventDefault();
            alert('Sales Order wajib dipilih.');
            return false;
        }

        if($checkedShipping.length === 0){
            e.preventDefault();
            alert('Pilih minimal 1 shipping/surat jalan.');
            return false;
        }

        /*
         * Checkbox Shipping dibuat secara dinamis di tabel.
         * Agar shipping_no[] pasti masuk POST, salin semua checkbox
         * terpilih menjadi hidden input langsung di dalam form.
         */
        $form.find('input.shipping-post-value').remove();

        $checkedShipping.each(function(){
            $('<input>', {
                type: 'hidden',
                name: 'shipping_no[]',
                value: String($(this).val() || ''),
                class: 'shipping-post-value'
            }).appendTo($form);
        });

        var d = parseInt($('#days').val(), 10);
        if (isNaN(d) || d < 0) {
            d = 30;
        }

        $('#days').val(d);

        /*
         * Jangan timpa payment_term dengan days.
         * payment_term tetap mengikuti data Sales Order.
         */
        if (!$('#payment_term').val()) {
            $('#payment_term').val(
                selectedOrder && selectedOrder.payment_term
                    ? selectedOrder.payment_term
                    : ''
            );
        }

        if (!$.trim($('#station').val())) {
            $('#station').val('FACTORY');
        }

        var invDate = $('input[name="invoice_date"]');
        invDate.val(indoToSql(invDate.val()));

        /*
         * Pemeriksaan terakhir sebelum submit.
         */
        if ($form.find('input[name="shipping_no[]"]').length === 0) {
            e.preventDefault();
            alert('Shipping terpilih gagal dimasukkan ke form. Silakan pilih ulang.');
            return false;
        }

        $('#btnSave')
            .prop('disabled', true)
            .html('<i class="fa fa-spinner fa-spin"></i> Saving...');

        return true;
    });
});
</script>