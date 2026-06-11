<?php
// modul/transaksi/add_sales_order.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// Fungsi generate nomor SO otomatis saat halaman dibuka
function generateOrderNo($conn) {
    $year  = date('Y');
    $month = date('m');
    $query = mysqli_query($conn, "SELECT order_no FROM head_sales_order WHERE order_no LIKE 'SO.$year%' ORDER BY order_no DESC LIMIT 1");
    $row   = mysqli_fetch_assoc($query);
    $next_num = $row ? (intval(substr($row['order_no'], -5)) + 1) : 1;
    return "SO.$year" . "FC" . $month . "." . str_pad($next_num, 5, '0', STR_PAD_LEFT);
}

// Fungsi generate nomor PO otomatis
function generatePONumber($conn, $tahun) {
    $query = mysqli_query($conn, "SELECT no_po FROM hed_po WHERE no_po LIKE '%/PO/$tahun' ORDER BY no_po DESC LIMIT 1");
    $row = mysqli_fetch_assoc($query);
    
    if ($row) {
        $last_num = intval(explode('/', $row['no_po'])[0]);
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    
    return str_pad($next_num, 3, '0', STR_PAD_LEFT) . "/PO/" . $tahun;
}

// Ambil data master untuk Dropdown Panel Header
$marketing_rs = mysqli_query($conn, "SELECT marketing_id, marketing_name FROM m_marketing WHERE is_active='Checked' ORDER BY marketing_name ASC");
$sales_rs     = mysqli_query($conn, "SELECT sales_id, sales_name FROM m_sales WHERE is_active='Checked' ORDER BY sales_name ASC");
$customer_rs  = mysqli_query($conn, "SELECT customer_id, customer, address, city FROM m_customer WHERE is_active='Checked' ORDER BY customer ASC");

// Ambil semua data inventory untuk dropdown options
$inventory_rs = mysqli_query($conn, "SELECT inventory_id, inventory_name, uom, uom_pack 
                                     FROM m_inventory 
                                     WHERE status = 'Active'
                                     ORDER BY inventory_name ASC");

// Simpan data inventory ke array JavaScript
$inventory_options = [];
while ($inv = mysqli_fetch_assoc($inventory_rs)) {
    $inventory_options[] = [
        'id' => $inv['inventory_id'],
        'name' => $inv['inventory_name'],
        'uom' => $inv['uom'],
        'uom_pack' => $inv['uom_pack']
    ];
}

$order_no     = generateOrderNo($conn);
$order_date   = date('Y-m-d');
$tahun        = date('Y');
$po_number    = generatePONumber($conn, $tahun);
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>

<style>
:root {
    --bg-base: #f0f2f5; 
    --bg-panel: #ffffff; 
    --border: #dee2e6; 
    --text-primary: #212529;
    --text-label: #0d6efd; 
    --accent-blue: #0d6efd; 
    --accent-green: #198754;
}
.so-wrap * { 
    box-sizing: border-box; 
    font-family: 'Segoe UI', 'Consolas', 'Cascadia Code', monospace; 
}
.so-wrap { 
    background: var(--bg-base); 
    padding: 12px; 
    color: var(--text-primary); 
}
.panel-row { 
    display: flex; 
    gap: 10px; 
    margin-bottom: 10px; 
}
.so-panel { 
    flex: 1; 
    background: var(--bg-panel); 
    border: 1px solid var(--border); 
    border-radius: 4px; 
    overflow: hidden; 
}
.so-panel-header { 
    background: #e9ecef; 
    border-bottom: 1px solid var(--border); 
    padding: 6px 12px; 
    font-size: 11px; 
    font-weight: bold; 
    color: #495057; 
    display: flex; 
    align-items: center; 
    gap: 6px; 
}
.so-panel-body { 
    padding: 12px; 
}
.ff { 
    margin-bottom: 8px; 
}
.ff label { 
    display: block; 
    font-size: 10px; 
    font-weight: 600; 
    color: var(--text-label); 
    margin-bottom: 3px; 
    text-transform: uppercase; 
}
.ff input, .ff select, .ff textarea { 
    width: 100%; 
    background: #ffffff; 
    border: 1px solid var(--border); 
    border-radius: 3px; 
    font-size: 11px; 
    padding: 5px 8px; 
    outline: none; 
}
.ff input[readonly], .ff textarea[readonly] { 
    background: #e9ecef; 
    color: #555; 
}
.so-panel-full { 
    background: var(--bg-panel); 
    border: 1px solid var(--border); 
    border-radius: 4px; 
    overflow: hidden; 
    margin-bottom: 10px; 
}
.detail-toolbar { 
    background: #f8f9fa; 
    border-bottom: 1px solid var(--border); 
    padding: 8px 12px; 
    display: flex; 
    gap: 8px; 
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
    gap: 5px; 
}
.btn-primary { background: var(--accent-blue); color: #fff; }
.btn-success { background: var(--accent-green); color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-danger { background: #dc3545; color: #fff; padding: 4px 8px; font-size: 10px; border-radius: 2px; }

.detail-table-wrap { 
    max-height: 400px; 
    overflow: auto; 
}
.detail-table { 
    width: 100%; 
    border-collapse: collapse; 
    font-size: 11px; 
    min-width: 1300px; 
}
.detail-table th { 
    background: #e9ecef; 
    padding: 8px 6px; 
    border: 1px solid var(--border); 
    position: sticky; 
    top: 0; 
    z-index: 2; 
    font-size: 10px; 
    text-transform: uppercase; 
}
.detail-table td { 
    padding: 4px 6px; 
    border: 1px solid var(--border); 
    background: #fff; 
}
.detail-table input { 
    width: 100%; 
    background: transparent; 
    border: none; 
    font-size: 11px; 
    padding: 2px; 
    outline: none; 
}
.detail-table input[readonly] { 
    color: #444; 
    background: #f1f3f5;
}
.so-footer-row { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    padding: 10px; 
    background: #f8f9fa; 
    border-top: 1px solid var(--border); 
}
.so-actionbar { 
    display: flex; 
    justify-content: flex-end; 
    gap: 10px; 
    padding: 10px 0; 
}
.select2-container--default .select2-selection--single {
    height: 28px !important;
    padding: 2px 0 !important;
    font-size: 11px !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 24px !important;
    font-size: 11px !important;
}
.select2-results__option {
    font-size: 11px !important;
    padding: 6px 10px !important;
}
</style>

<div class="so-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <form method="POST" action="index.php?page=save_sales_order" id="formSO">
        
        <!-- HIDDEN FIELD UNTUK PO NUMBER (Generated Otomatis) -->
        <input type="hidden" name="po" value="<?= htmlspecialchars($po_number) ?>">
        
        <!-- PANEL 1: Order Information -->
        <div class="panel-row">
            <div class="so-panel">
                <div class="so-panel-header"><i class="fa-solid fa-file-invoice"></i> Order Information</div>
                <div class="so-panel-body">
                    <div class="ff">
                        <label>Order No</label>
                        <input type="text" name="order_no" value="<?= htmlspecialchars($order_no) ?>" readonly style="font-weight:bold; color:var(--accent-blue);">
                    </div>
                    <div class="ff">
                        <label>Order Date</label>
                        <input type="date" name="order_date" value="<?= $order_date ?>">
                    </div>

                    <div class="ff">
                        <label>Marketing</label>
                        <select name="marketing_id" id="marketing_id">
                            <option value="">-- Pilih Marketing --</option>
                            <?php while ($m = mysqli_fetch_assoc($marketing_rs)): ?>
                                <option value="<?= htmlspecialchars($m['marketing_id']) ?>"><?= htmlspecialchars($m['marketing_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Sales</label>
                        <select name="sales_id" id="sales_id">
                            <option value="">-- Pilih Sales --</option>
                            <?php while ($s = mysqli_fetch_assoc($sales_rs)): ?>
                                <option value="<?= htmlspecialchars($s['sales_id']) ?>"><?= htmlspecialchars($s['sales_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <!-- Hidden fields untuk status -->
                    <input type="hidden" name="status" value="Open">
                    <input type="hidden" name="approval_status" value="Pending">
                </div>
            </div>

            <!-- PANEL 2: Customer Information -->
            <div class="so-panel">
                <div class="so-panel-header"><i class="fa-solid fa-building-user"></i> Customer Information</div>
                <div class="so-panel-body">
                    <div class="ff">
                        <label>Customer Name</label>
                        <select name="customer_id" id="customer_id" required>
                            <option value="">-- Pilih Customer --</option>
                            <?php 
                            mysqli_data_seek($customer_rs, 0);
                            while ($c = mysqli_fetch_assoc($customer_rs)): 
                            ?>
                                <option value="<?= htmlspecialchars($c['customer_id']) ?>"
                                        data-address="<?= htmlspecialchars($c['address'] ?? '') ?>"
                                        data-city="<?= htmlspecialchars($c['city'] ?? '') ?>">
                                    <?= htmlspecialchars($c['customer']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <input type="hidden" name="customer_name" id="customer_name_hidden">
                    </div>
                    <div class="ff">
                        <label>Address</label>
                        <textarea name="customer_address" id="customer_address" rows="2" readonly></textarea>
                    </div>
                    <div class="ff">
                        <label>City</label>
                        <input type="text" name="customer_city" id="customer_city" readonly>
                    </div>
                    <div class="ff">
                        <label>Station</label>
                        <input type="text" name="station" value="FACTORY">
                    </div>
                    <div class="ff">
                        <label>Shipment Due Date</label>
                        <input type="date" name="shipment_due_date">
                    </div>
                    <div class="ff">
                        <label>Shipment Location</label>
                        <textarea name="shipment_location" id="shipment_location" rows="1"></textarea>
                    </div>
                </div>
            </div>

            <!-- PANEL 3: Payment Term & Config -->
            <div class="so-panel">
                <div class="so-panel-header"><i class="fa-solid fa-gears"></i> Payment Term &amp; Config</div>
                <div class="so-panel-body">
                    <div class="ff">
                        <label>Payment Type</label>
                        <select name="payment_type">
                            <option value="Cash" selected>Cash</option>
                            <option value="Credit">Credit</option>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Term Days</label>
                        <input type="number" name="days" value="30" min="0">
                    </div>
                    <div class="ff">
                        <label>Payment Term</label>
                        <select name="payment_term">
                            <option value="Franco" selected>Franco</option>
                            <option value="Loco">Loco</option>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Currency</label>
                        <select name="currency">
                            <option value="IDR" selected>IDR — Rupiah</option>
                            <option value="USD">USD — US Dollar</option>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Tolerance (%)</label>
                        <input type="number" step="0.01" name="tolerance" value="10.00">
                    </div>
                    <div class="ff" style="padding-top: 6px;">
                        <!--<label style="color:#212529; text-transform:none; cursor:pointer;">
                            <input type="checkbox" name="backward_calculation" value="Checked" style="width:auto; display:inline-block; margin-right:5px;"> 
                            Backward Calculation
                        </label>-->
                    </div>
                    <div class="ff">
                        <label>Remarks / Keterangan</label>
                        <textarea name="remarks" rows="2" placeholder="Tulis STOKAN atau lainnya"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- PANEL 4: Order Details -->
        <div class="so-panel-full">
            <div class="so-panel-header">
                <i class="fa-solid fa-boxes-stacked"></i> Item Pesanan (Multiple Inventory Grid)
                <span id="row_count_label" style="color:#777; font-size:10px; margin-left:10px; font-weight:normal;">// 0 rows</span>
            </div>

            <div class="detail-toolbar">
                <button type="button" class="btn-vs btn-primary" onclick="addRow()"><i class="fa fa-plus"></i> Tambah Baris Item</button>
                <button type="button" class="btn-vs btn-secondary" onclick="deleteSelected()"><i class="fa fa-trash"></i> Hapus Terpilih</button>
            </div>

            <div class="detail-table-wrap">
                <table class="detail-table" id="detailTable">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="selectAll"></th>
                            <th style="width:35px;">#</th>
                            <th style="width:300px;">Pilih Produk Item (Master Inventory)</th>
                            <th style="width:90px;">Qty Order</th>
                            <th style="width:70px;">UoM</th>
                            <th style="width:90px;">Qty Pack</th>
                            <th style="width:80px;">UoM Pack</th>
                            <th style="width:90px;">UoM Detail</th>
                            <th style="width:110px;">Price Unit</th>
                            <th style="width:110px;">Price</th>
                            <th style="width:120px;">SubTotal</th>
                            <th>Keterangan / Notes Detail</th>
                            <th style="width:45px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="detailBody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="10" style="text-align:right; font-weight:bold; color:var(--accent-blue); padding:8px;">Total Gross:</td>
                            <td><input type="text" id="subtotal_display" readonly style="text-align:right; font-weight:bold; color:var(--accent-green); background:transparent;"></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="so-footer-row">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:11px; font-weight:bold; color:var(--text-label)">DOWN PAYMENT (DP) :</span>
                  <input 
                    type="text" 
                    name="down_payment" 
                    id="down_payment" 
                    value="0" 
                    inputmode="numeric"
                    style="width:160px; padding:5px; border:1px solid #ccc; border-radius:3px; font-weight:bold; text-align:right;"
                >
                    <input type="hidden" name="grand_total" id="grand_total_hidden">
                </div>
                <div style="display:flex; gap:30px; font-size:12px; font-weight:bold;">
                    <div>NET TOTAL: <span id="st_summary" style="color:var(--accent-green);">0,00</span></div>
                    <div>SISA BAYAR (BALANCE): <span id="balance_summary" style="color:#fd7e14;">0,00</span></div>
                </div>
            </div>
        </div>

        <div class="so-actionbar">
            <button type="button" class="btn-vs btn-secondary" onclick="window.location.href='index.php?page=sales_order'">
                <i class="fa fa-times"></i> Batal / Kembali
            </button>
            <button type="submit" class="btn-vs btn-success" id="btnSave">
                <i class="fa fa-save"></i> Simpan Transaksi SO
            </button>
        </div>
    </form>
</div>

<script>
// Data inventory dari PHP
var inventoryData = <?php echo json_encode($inventory_options); ?>;

var rowCounter = 0;

function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/[&<>"']/g, function(m) { 
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; 
    });
}

function formatNumber(num) {
    return parseFloat(num || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function parseRupiah(value) {
    if (!value) return 0;

    value = String(value)
        .replace(/\./g, '')
        .replace(/,/g, '.')
        .replace(/[^\d.]/g, '');

    return parseFloat(value) || 0;
}

function formatRupiahInput(value) {
    value = parseRupiah(value);

    return value.toLocaleString('id-ID', {
        maximumFractionDigits: 0
    });
}

function updateRowNumbers() {
    var rows = document.querySelectorAll('#detailBody .detail-row');
    rows.forEach(function(r, i) {
        var c = r.querySelector('.ln-cell');
        if (c) c.textContent = i + 1;
    });
    document.getElementById('row_count_label').textContent = '// ' + rows.length + ' Item Terdaftar';
}

function buildRow(data) {
    var idx = rowCounter++;
    data = data || {};
    
    // Generate option HTML dari inventoryData
    var optionsHtml = '<option value="">-- Pilih Inventory --</option>';
    for (var i = 0; i < inventoryData.length; i++) {
        var inv = inventoryData[i];
        optionsHtml += '<option value="' + escHtml(inv.id) + '" ' +
                       'data-inv-name="' + escHtml(inv.name) + '" ' +
                       'data-uom="' + escHtml(inv.uom) + '" ' +
                       'data-uom-pack="' + escHtml(inv.uom_pack) + '">' +
                       escHtml(inv.id) + ' — ' + escHtml(inv.name) + '</option>';
    }
    
    return `<tr class="detail-row" data-idx="${idx}">
        <td style="text-align:center;"><input type="checkbox" class="rowCheckbox"></td>
        <td class="ln-cell" style="text-align:center; font-weight:bold; color:#888;"></td>
        <td>
            <select name="inventory_id[]" class="inv-select" data-row="${idx}" style="width:100%;">
                ${optionsHtml}
            </select>
            <input type="hidden" name="inventory_name[]" class="inv-name-hidden" value="${escHtml(data.inventory_name || '')}">
        </td>
        <td><input type="number" step="0.01" name="quantity[]" class="qty" value="${data.quantity || 0}" style="text-align:center; border:1px solid #ddd; border-radius:2px;"></td>
        <td><input type="text" name="uom[]" class="inv-uom" value="${escHtml(data.uom || '')}" readonly style="text-align:center; background:#f1f3f5;"></td>
        <td><input type="number" step="0.01" name="quantity_pack[]" class="qty-pack" value="${data.quantity_pack || 0}" style="text-align:center; border:1px solid #ddd; border-radius:2px;"></td>
        <td><input type="text" name="uom_pack[]" class="inv-uom-pack" value="${escHtml(data.uom_pack || '')}" readonly style="text-align:center; background:#f1f3f5;"></td>
        <td><input type="text" name="uom_detail[]" class="inv-uom-detail" value="${escHtml(data.uom_detail || '')}" style="border:1px solid #ddd; border-radius:2px; text-align:center;"></td>
       <td>
            <input 
                type="text" 
                name="price_unit[]" 
                class="price-unit rupiah-input" 
                value="${formatRupiahInput(data.price_unit || 0)}" 
                inputmode="numeric"
                style="text-align:right; border:1px solid #ddd; border-radius:2px;"
            >
        </td>

        <td>
            <input 
                type="text" 
                name="price[]" 
                class="price rupiah-input" 
                value="${formatRupiahInput(data.price || 0)}" 
                inputmode="numeric"
                style="text-align:right; border:1px solid #ddd; border-radius:2px;"
            >
        </td>
        <td>
    <input 
        type="text" 
        name="subtotal[]" 
        class="subtotal rupiah-input" 
        value="${formatRupiahInput(data.subtotal || 0)}" 
        readonly 
        style="text-align:right; font-weight:bold; background:#f1f3f5;"
    >
</td>
        <td><input type="text" name="remarks_detail[]" class="inv-remarks" value="${escHtml(data.remarks || '')}" placeholder="Notes..." style="border:1px solid #ddd; border-radius:2px; padding:2px 4px;"></td>
        <td style="text-align:center;"><button type="button" class="btn-vs btn-danger" onclick="removeRow(this)"><i class="fa fa-trash"></i></button></td>
    </tr>`;
}

// =====================================================
// FUNGSI PERHITUNGAN OTOMATIS
// =====================================================
function calculateRow(row) {
    var $row = $(row);

    var qtyPack   = parseFloat($row.find('.qty-pack').val()) || 0;
    var priceUnit = parseRupiah($row.find('.price-unit').val());
    var price     = parseRupiah($row.find('.price').val());

    // Pilih salah satu: prioritas price_unit, kalau 0 pakai price
    var hargaDipakai = priceUnit > 0 ? priceUnit : price;

    var subtotal = qtyPack * hargaDipakai;

    $row.find('.subtotal').val(formatRupiahInput(subtotal));

    calculateGrandTotal();
}

function calculateGrandTotal() {
    var total = 0;

    $('.subtotal').each(function() {
        var val = parseRupiah($(this).val()) || 0;
        total += val;
    });

    var dp = parseRupiah($('#down_payment').val()) || 0;
    var balance = total - dp;

    $('#subtotal_display').val(formatNumber(total));
    $('#grand_total_hidden').val(total.toFixed(2));
    $('#st_summary').text(formatNumber(total));
    $('#balance_summary').text(formatNumber(balance));
}

// =====================================================
// EVENT LISTENERS
// =====================================================
$(document).on('input', '.qty', function() {
    var $row = $(this).closest('.detail-row');
    if ($row.length) {
        calculateRow($row[0]);
    }
});

$(document).on('input', '.qty-pack', function() {
    var $row = $(this).closest('.detail-row');
    if ($row.length) {
        calculateRow($row[0]);
    }
});

$(document).on('input', '.price-unit, .price', function() {
    var cursorPosition = this.selectionStart;
    var beforeLength = this.value.length;

    this.value = formatRupiahInput(this.value);

    var afterLength = this.value.length;
    this.selectionStart = this.selectionEnd = cursorPosition + (afterLength - beforeLength);

    var $row = $(this).closest('.detail-row');
    if ($row.length) {
        calculateRow($row[0]);
    }
});

$(document).on('input', '#down_payment', function() {
    var cursorPosition = this.selectionStart;
    var beforeLength = this.value.length;

    this.value = formatRupiahInput(this.value);

    var afterLength = this.value.length;
    this.selectionStart = this.selectionEnd = cursorPosition + (afterLength - beforeLength);

    calculateGrandTotal();
});
// =====================================================
// CRUD ROWS
// =====================================================

function addRow(data) {
    var $newRow = $(buildRow(data));
    $('#detailBody').append($newRow);
    initSelect2OnRow($newRow);
    updateRowNumbers();
    calculateGrandTotal();
}

function initSelect2OnRow($row) {
    $row.find('.inv-select').select2({
        placeholder: '🔍 Pilih Produk...',
        allowClear: true,
        width: '100%'
    }).on('change', function() {
        var option = $(this).find('option:selected');
        var tr = $(this).closest('tr');
        
        var inventoryName = option.data('inv-name') || '';
        var uom = option.data('uom') || '';
        var uomPack = option.data('uom-pack') || '';
        
        tr.find('.inv-name-hidden').val(inventoryName);
        tr.find('.inv-uom').val(uom);
        tr.find('.inv-uom-pack').val(uomPack);
        
        calculateRow(tr[0]);
    });
}

function removeRow(btn) {
    $(btn).closest('tr').remove();
    updateRowNumbers();
    calculateGrandTotal();
}

function deleteSelected() {
    var $checked = $('.rowCheckbox:checked');
    if (!$checked.length) return alert('Silakan centang baris item!');
    if (confirm('Hapus baris terpilih?')) {
        $checked.each(function() { $(this).closest('tr').remove(); });
        updateRowNumbers();
        calculateGrandTotal();
    }
}

// =====================================================
// DOCUMENT READY
// =====================================================
$(document).ready(function() {
    // Initialize Select2 untuk dropdown biasa
    $('#marketing_id, #sales_id, #customer_id').select2({ 
        width: '100%',
        placeholder: '-- Pilih --',
        allowClear: true
    });

    // Customer change handler
    $('#customer_id').on('change', function() {
        var opt = this.options[this.selectedIndex];
        if (!opt || this.value === '') {
            $('#customer_address').val('');
            $('#customer_city').val('');
            $('#shipment_location').val('');
            $('#customer_name_hidden').val('');
            return;
        }
        var address = opt.getAttribute('data-address') || '';
        var city = opt.getAttribute('data-city') || '';
        
        $('#customer_address').val(address);
        $('#customer_city').val(city);
        $('#shipment_location').val(address);
        $('#customer_name_hidden').val(opt.text ? opt.text.trim() : '');
    });

    // Select All checkbox
    $('#selectAll').on('change', function() {
        var isChecked = this.checked;
        $('.rowCheckbox').each(function() { this.checked = isChecked; });
    });

    // Form submit validation
    $('#formSO').on('submit', function(e) {
        var rows = $('#detailBody .detail-row');
        if (rows.length === 0) {
            e.preventDefault();
            alert('Gagal Simpan: Detail item pesanan tidak boleh kosong!');
            return false;
        }
        
        // Pastikan semua select sudah terisi
        var isValid = true;
        rows.each(function() {
            var invId = $(this).find('.inv-select').val();
            if (!invId) {
                isValid = false;
                return false;
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            alert('Gagal Simpan: Ada baris item yang belum dipilih produknya!');
            return false;
        }
        
     $('.price-unit, .price, .subtotal, #down_payment').each(function() {
    $(this).val(parseRupiah($(this).val()).toFixed(2));
});

        $('#btnSave').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        return true;
    });

    // Generate 3 baris awal
    for (var i = 0; i < 3; i++) {
        addRow();
    }
});
</script>