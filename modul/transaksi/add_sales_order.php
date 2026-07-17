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
$inventory_rs = mysqli_query($conn, "
    SELECT 
        mi.inventory_id, 
        mi.inventory_name,
        COALESCE(miu.unit, mi.uom) AS uom,
        COALESCE(miu.unit, mi.uom_pack) AS uom_pack,
        mi.p,
        mi.l,
        mi.t
    FROM m_inventory mi
    LEFT JOIN m_inventory_uom miu 
        ON miu.inventory_id = mi.inventory_id 
       AND miu.`Default` = 1
    WHERE mi.status = 'Active'
    ORDER BY mi.inventory_name ASC
");
$uom_map = [];

$uom_q = mysqli_query($conn, "
    SELECT 
        inventory_id,
        unit,
        `Default`,
        `Value`
    FROM m_inventory_uom
    ORDER BY inventory_id ASC, `Default` DESC, unit ASC
");

if ($uom_q) {
    while ($u = mysqli_fetch_assoc($uom_q)) {
        $inventory_id = $u['inventory_id'];

        if (!isset($uom_map[$inventory_id])) {
            $uom_map[$inventory_id] = [];
        }

        $uom_map[$inventory_id][] = [
            'unit' => $u['unit'],
            'default' => (int)$u['Default'],
            'value' => (float)$u['Value']
        ];
    }
}
// Simpan data inventory ke array JavaScript
$inventory_options = [];
while ($inv = mysqli_fetch_assoc($inventory_rs)) {
  $inventory_options[] = [
    'id' => $inv['inventory_id'],
    'name' => $inv['inventory_name'],
    'uom' => 'KG',
    'uom_pack' => $inv['uom_pack'],
    'p' => (float)$inv['p'],
    'l' => (float)$inv['l'],
    't' => (float)$inv['t']
];
}

// Ambil semua data inventory_uom untuk dropdown options
$inventory_uom_rs = mysqli_query($conn, "
    SELECT 
        inventory_id, 
        unit, 
        `Default`, 
        `Value`
    FROM m_inventory_uom 
    ORDER BY inventory_id, `Default` DESC, unit ASC
");
$inventory_uom_options = [];
while ($uom = mysqli_fetch_assoc($inventory_uom_rs)) {
    if (!isset($inventory_uom_options[$uom['inventory_id']])) {
        $inventory_uom_options[$uom['inventory_id']] = [];
    }

    $inventory_uom_options[$uom['inventory_id']][] = [
        'unit' => $uom['unit'],
        'default' => (int)$uom['Default'],
        'value' => (float)$uom['Value']
    ];
}

// Fungsi format tanggal ke dd-MMM-yyyy
function formatDateIndonesian($date) {
    if (empty($date) || $date == '0000-00-00') {
        return '';
    }
    
    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];
    
    $timestamp = strtotime($date);
    $tanggal = date('d', $timestamp);
    $bulan_num = (int)date('m', $timestamp);
    $tahun = date('Y', $timestamp);
    
    return $tanggal . '-' . $bulan[$bulan_num] . '-' . $tahun;
}

// Order Date default hari ini
$order_date = date('Y-m-d');
$order_date_display = formatDateIndonesian($order_date);

$order_no     = generateOrderNo($conn);
$tahun        = date('Y');
$no_po    = generatePONumber($conn, $tahun);
?>

<!-- CSS & JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet">

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
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
.required {
    color: red;
    font-weight: bold;
    margin-left: 2px;
}
.uom-detail-input {
    cursor: pointer;
    background: #fff !important;
}

.uom-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 9998;
}

.uom-modal {
    display: none;
    position: fixed;
    top: 50%;
    left: 50%;
    width: 520px;
    max-width: calc(100vw - 30px);
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 6px;
    border: 1px solid #ccc;
    z-index: 9999;
    box-shadow: 0 10px 30px rgba(0,0,0,.25);
    overflow: hidden;
}

.uom-modal-header {
    padding: 10px 14px;
    background: #e9ecef;
    border-bottom: 1px solid #dee2e6;
    font-size: 12px;
    font-weight: bold;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.uom-modal-body {
    padding: 12px;
    max-height: 360px;
    overflow: auto;
}

.uom-modal-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}

.uom-modal-table th,
.uom-modal-table td {
    border: 1px solid #dee2e6;
    padding: 6px;
}

.uom-modal-table th {
    background: #f8f9fa;
    text-align: center;
}

.uom-modal-table input {
    width: 100%;
    border: 1px solid #ced4da;
    padding: 4px 6px;
    font-size: 11px;
    text-align: right;
}

.uom-modal-close {
    border: none;
    background: transparent;
    font-size: 16px;
    cursor: pointer;
}

.btn-uom-pilih {
    padding: 4px 8px;
    border: none;
    background: #0d6efd;
    color: #fff;
    border-radius: 3px;
    font-size: 10px;
    cursor: pointer;
}

.auto-correct-toggle {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: bold;
    color: var(--accent-blue);
    margin-left: auto;
    background: #eaf4ff;
    border: 1px solid #b6dcff;
    border-radius: 4px;
    padding: 5px 10px;
}
.auto-correct-toggle input {
    width: auto !important;
    margin: 0;
}
.qty-auto-calculated {
    background: #eaf4ff !important;
    font-weight: bold;
}
</style>

<div class="so-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <form method="POST" action="index.php?page=save_sales_order" id="formSO">
        <input type="hidden" name="po" value="<?= htmlspecialchars($no_po) ?>">
         <input type="hidden" name="no_po" value="<?= htmlspecialchars($no_po) ?>">
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
                        <input type="text" name="order_date" class="form-control form-control-sm datepicker" value="<?= $order_date_display ?>">
                    </div>
                     <div class="ff">
                            <label>No. PO <span class="required">*</span></label>
                            <input type="text" name="no_po" id="no_po" value="<?= htmlspecialchars($no_po) ?>" style="font-weight:bold; color:var(--accent-blue); background:#e9ecef;">
                            <small style="display:block; color:#6c757d; font-size:9px; margin-top:2px;">Nomor PO akan otomatis digenerate</small>
                        </div>
                    <div class="ff">
                        <label>Marketing <span class="required">*</span></label>
                        <select name="marketing_id" id="marketing_id" required>
                            <option value="">-- Pilih Marketing --</option>
                            <?php while ($m = mysqli_fetch_assoc($marketing_rs)): ?>
                                <option value="<?= htmlspecialchars($m['marketing_id']) ?>"><?= htmlspecialchars($m['marketing_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Sales <span class="required">*</span></label>
                        <select name="sales_id" id="sales_id" required>
                            <option value="">-- Pilih Sales --</option>
                            <?php while ($s = mysqli_fetch_assoc($sales_rs)): ?>
                                <option value="<?= htmlspecialchars($s['sales_id']) ?>"><?= htmlspecialchars($s['sales_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <input type="hidden" name="status" value="Open">
                    <input type="hidden" name="approval_status" value="Reject">
                </div>
            </div>

            <!-- PANEL 2: Customer Information -->
            <div class="so-panel">
                <div class="so-panel-header"><i class="fa-solid fa-building-user"></i> Customer Information</div>
                <div class="so-panel-body">
                    <div class="ff">
                        <label>Customer Name <span class="required">*</span></label>
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
                        <input type="text" name="shipment_due_date" class="form-control form-control-sm datepicker">
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
                <label class="auto-correct-toggle" title="Jika aktif, Qty & Qty Pack akan dihitung otomatis dari UoM Detail x konversi m_inventory_uom">
                    <input type="checkbox" id="chkAutoCorrect" name="allow_auto_correct" value="Checked">
                    <i class="fa fa-wand-magic-sparkles"></i> Allow Auto Correct
                </label>
            </div>

            <div class="detail-table-wrap">
                <table class="detail-table" id="detailTable">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="selectAll"></th>
                            <th style="width:35px;">#</th>
                            <th style="width:250px;">Pilih Produk Item</th>
                            <th style="width:80px;">Qty</th>
                            <th style="width:60px;">UoM</th>
                            <th style="width:80px;">Qty Pack</th>
                            <th style="width:100px;">UoM Pack</th>
                            <th style="width:150px;">UoM Detail</th>
                            <th style="width:100px;">Price Unit</th>
                            <th style="width:100px;">Price</th>
                            <th style="width:100px;">SubTotal</th>
                            <th>Keterangan</th>
                            <th style="width:45px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="detailBody"></tbody>
                   <tfoot>
                        <tr>
                            <td colspan="10" style="text-align:right; font-weight:bold; color:var(--accent-blue); padding:8px;">
                                Total Gross:
                            </td>
                            <td>
                                <input type="text" id="subtotal_display" readonly style="text-align:right; font-weight:bold; color:var(--accent-green); background:transparent; width:100%;">
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="so-footer-row">
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="font-size:11px; font-weight:bold; color:var(--text-label)">DOWN PAYMENT (DP) :</span>
                    <input type="text" name="down_payment" id="down_payment" value="0" inputmode="numeric"
                        style="width:160px; padding:5px; border:1px solid #ccc; border-radius:3px; font-weight:bold; text-align:right;">
                    <input type="hidden" name="grand_total" id="grand_total_hidden">
                </div>
                <div style="display:flex; gap:30px; font-size:12px; font-weight:bold;">
                    <div>NET TOTAL: <span id="st_summary" style="color:var(--accent-green);">0,00</span></div>
                    <div>SISA BAYAR: <span id="balance_summary" style="color:#fd7e14;">0,00</span></div>
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
<div class="uom-modal-backdrop" id="uomDetailBackdrop"></div>

<div class="uom-modal" id="uomDetailModal">
    <div class="uom-modal-header">
        <span id="uomDetailModalTitle">Pilih UoM Detail</span>
        <button type="button" class="uom-modal-close" onclick="closeUomDetailModal()">×</button>
    </div>

    <div class="uom-modal-body">
        <table class="uom-modal-table">
            <thead>
                <tr>
                    <th style="width:90px;">UoM</th>
                    <th style="width:90px;">Value</th>
                    <th style="width:90px;">Quantity</th>
                    <th style="width:70px;">Aksi</th>
                </tr>
            </thead>
            <tbody id="uomDetailModalBody"></tbody>
        </table>
    </div>
</div>

<script>
// Data dari PHP
var inventoryData = <?php echo json_encode($inventory_options); ?>;
var inventoryUomData = <?php echo json_encode($inventory_uom_options); ?>;

var rowCounter = 0;
var currentUomDetailRow = null;

function escHtml(s) {
    if (s === null || s === undefined) return '';

    return String(s).replace(/[&<>"']/g, function(m) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[m];
    });
}

function formatNumber(num) {
    return parseFloat(num || 0).toLocaleString('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
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

function formatDecimalInput(num) {
    num = parseFloat(num || 0);

    if (!num) return '0';

    return num.toFixed(4).replace(/\.?0+$/, '');
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

    var selectedInventoryId = data.inventory_id || '';

    var optionsHtml = '<option value="">-- Pilih Inventory --</option>';

    for (var i = 0; i < inventoryData.length; i++) {
        var inv = inventoryData[i];
        var selectedAttr = selectedInventoryId === inv.id ? 'selected' : '';

        optionsHtml += '<option value="' + escHtml(inv.id) + '" ' +
            selectedAttr + ' ' +
            'data-inv-name="' + escHtml(inv.name) + '" ' +
            'data-uom="KG" ' +
            'data-uom-pack="' + escHtml(inv.uom_pack) + '" ' +
            'data-p="' + escHtml(inv.p || 0) + '" ' +
            'data-l="' + escHtml(inv.l || 0) + '" ' +
            'data-t="' + escHtml(inv.t || 0) + '">' +
            escHtml(inv.id) + ' — ' + escHtml(inv.name) +
            '</option>';
    }

    return `<tr class="detail-row" data-idx="${idx}">
        <td style="text-align:center;">
            <input type="checkbox" class="rowCheckbox">
        </td>

        <td class="ln-cell" style="text-align:center; font-weight:bold; color:#888;"></td>

        <td>
            <select name="inventory_id[]" class="inv-select" style="width:100%;">
                ${optionsHtml}
            </select>
            <input type="hidden" name="inventory_name[]" class="inv-name-hidden" value="${escHtml(data.inventory_name || '')}">
            <input type="hidden" class="inv-p" value="${data.p || 0}">
            <input type="hidden" class="inv-l" value="${data.l || 0}">
            <input type="hidden" class="inv-t" value="${data.t || 0}">
        </td>

        <td>
            <input type="number" step="0.0001" name="quantity[]" class="qty" value="${data.quantity || 0}" style="text-align:center;">
        </td>

        <td>
            <input type="text" name="uom[]" class="inv-uom" value="${escHtml(data.uom || 'KG')}" readonly style="background:#f1f3f5; text-align:center;">
        </td>

        <td>
            <input type="number" step="0.0001" name="quantity_pack[]" class="qty-pack" value="${data.quantity_pack || 0}" style="text-align:center;">
        </td>

        <td>
            <select name="uom_pack[]" class="inv-uom-pack-select" style="width:100%;">
                <option value="">-- Select --</option>
            </select>
        </td>

        <td>
            <div style="display:flex; gap:3px;">
                <input type="text"
                       name="uom_detail[]"
                       class="inv-uom-detail uom-detail-input"
                       value="${escHtml(data.uom_detail || '')}"
                       placeholder="Klik pilih"
                       readonly
                       style="width:70px; text-align:center;">

                <input type="number"
                       step="0.0001"
                       name="uom_detail_value[]"
                       class="inv-uom-detail-value"
                       value="${data.uom_detail_value || 0}"
                       placeholder="Value"
                       style="width:75px; text-align:right;">

                <input type="hidden"
                       class="inv-uom-detail-factor"
                       value="${data.uom_detail_factor || 0}">
            </div>
        </td>

        <td>
            <input type="text" 
                   name="price_unit[]" 
                   class="price-unit rupiah-input" 
                   value="${formatRupiahInput(data.price_unit || 0)}" 
                   inputmode="numeric" 
                   style="text-align:right;">
        </td>

        <td>
            <input type="text" 
                   name="price[]" 
                   class="price rupiah-input" 
                   value="${formatRupiahInput(data.price || 0)}" 
                   inputmode="numeric" 
                   style="text-align:right;">
        </td>

        <td>
            <input type="text" 
                   name="subtotal[]" 
                   class="subtotal rupiah-input" 
                   value="${formatRupiahInput(data.subtotal || 0)}" 
                   readonly 
                   style="text-align:right; background:#f1f3f5;">
        </td>

        <td>
            <input type="text" name="remarks_detail[]" class="inv-remarks" value="${escHtml(data.remarks || '')}" placeholder="Notes...">
        </td>

        <td style="text-align:center;">
            <button type="button" class="btn-vs btn-danger" onclick="removeRow(this)">
                <i class="fa fa-trash"></i>
            </button>
        </td>
    </tr>`;
}

// ============================================================
// PERBAIKAN UTAMA: Fungsi getPriceFormulaFactor
// ============================================================
function getPriceFormulaFactor(row) {
    var $row = $(row);

    var p = parseFloat($row.find('.inv-p').val()) || 0;
    var l = parseFloat($row.find('.inv-l').val()) || 0;
    var t = parseFloat($row.find('.inv-t').val()) || 0;
    var inventoryName = $row.find('.inv-name-hidden').val() || '';
    var qty = parseFloat($row.find('.qty').val()) || 0;

    // Untuk PP ROLL BOLA dan PE ROLL STOKAN SSB
    if (inventoryName.includes("PE ROLL STOKAN SSB") || inventoryName.includes("PP ROLL BOLA")) {
        // Rumus: (P × L × T) / 1000 
        // Contoh: PP ROLL BOLA 0.0200X3X100
        // P = 0.0200, L = 3, T = 100
        // (0.0200 × 3 × 100) / 1000 = 6 / 1000 = 0.006
        var factor = (p * l * t) / 1000;
        
        // Jika hasil factor = 0 (terlalu kecil), gunakan alternatif
        if (factor === 0) {
            // Alternatif: (P × L × T) / 10
            factor = (p * l * t) / 10;
            
            // Jika masih 0, gunakan pendekatan lain
            if (factor === 0) {
                // Coba dengan angka yang lebih realistis
                factor = (t / 100) * (l / 10);
                if (factor === 0) factor = 0.6; // Default untuk kasus spesifik
            }
        }
        
        return factor;
    }

    // Untuk inventory lain (default)
    var divisor = 1;
    if (p === 50) {
        divisor = 2;
    } else if (p === 25) {
        divisor = 4;
    }

    var factor = (t * 10 * l) / divisor;
    return factor;
}

// ============================================================
// PERBAIKAN: Fungsi isSpecialInventory
// ============================================================
function isSpecialInventory(row) {
    var $row = $(row);
    var inventoryName = $row.find('.inv-name-hidden').val() || '';
    
    return inventoryName.includes("PE ROLL STOKAN SSB") || inventoryName.includes("PP ROLL BOLA");
}

// ============================================================
// PERBAIKAN: Fungsi calculatePriceFromPriceUnit
// ============================================================
function calculatePriceFromPriceUnit(row) {
    var $row = $(row);

    var priceUnit = parseRupiah($row.find('.price-unit').val());
    var factor = getPriceFormulaFactor(row);
    var qtyPack = parseFloat($row.find('.qty-pack').val()) || 0;
    var qty = parseFloat($row.find('.qty').val()) || 0;

    if (priceUnit > 0 && factor > 0) {
        // Harga per roll = priceUnit × factor
        var price = priceUnit * factor;
        
        // Untuk inventory khusus, price adalah harga per roll × Qty Pack
        var inventoryName = $row.find('.inv-name-hidden').val() || '';
        if (inventoryName.includes("PE ROLL STOKAN SSB") || inventoryName.includes("PP ROLL BOLA")) {
            // Price adalah harga per roll × Qty Pack
            price = price * qtyPack;
        }
        
        $row.find('.price').val(formatRupiahInput(price));
    }

    calculateRow(row);
}

// ============================================================
// PERBAIKAN: Fungsi calculatePriceUnitFromPrice
// ============================================================
function calculatePriceUnitFromPrice(row) {
    var $row = $(row);

    var price = parseRupiah($row.find('.price').val());
    var factor = getPriceFormulaFactor(row);
    var qtyPack = parseFloat($row.find('.qty-pack').val()) || 0;

    if (price > 0 && factor > 0 && qtyPack > 0) {
        // Price Unit = Price / (factor × Qty Pack)
        var priceUnit = price / (factor * qtyPack);
        $row.find('.price-unit').val(formatRupiahInput(priceUnit));
    } else if (price > 0 && factor > 0) {
        var priceUnit = price / factor;
        $row.find('.price-unit').val(formatRupiahInput(priceUnit));
    }

    calculateRow(row);
}

// ============================================================
// PERBAIKAN: Fungsi calculateRow
// ============================================================
function calculateRow(row) {
    var $row = $(row);

    var qtyPack = parseFloat($row.find('.qty-pack').val()) || 0;
    var price = parseRupiah($row.find('.price').val());

    // Subtotal = Price × Qty Pack
    var subtotal = price * qtyPack;

    $row.find('.subtotal').val(formatRupiahInput(subtotal));

    calculateGrandTotal();
}

// ============================================================
// PERBAIKAN: Fungsi updatePriceUnitReadonly
// ============================================================
function updatePriceUnitReadonly(row) {
    var $row = $(row);
    var isSpecial = isSpecialInventory(row);
    var priceUnitInput = $row.find('.price-unit');
    var priceInput = $row.find('.price');
    
    if (isSpecial) {
        // Untuk inventory khusus: PP ROLL BOLA / PE ROLL STOKAN SSB
        // Price Unit bisa diinput manual
        priceUnitInput.prop('readonly', false);
        priceUnitInput.css('background', '#ffffff');
        if (parseRupiah(priceUnitInput.val()) === 0) {
            priceUnitInput.val('0');
        }
        
        // Price otomatis dihitung, readonly
        priceInput.prop('readonly', true);
        priceInput.css('background', '#f1f3f5');
    } else {
        // Untuk inventory lain: Price Unit = 0 dan readonly
        priceUnitInput.prop('readonly', true);
        priceUnitInput.css('background', '#f1f3f5');
        priceUnitInput.val('0'); // Set ke 0
        
        // Price bisa diinput manual
        priceInput.prop('readonly', false);
        priceInput.css('background', '#ffffff');
    }
}

function calculateGrandTotal() {
    var total = 0;

    $('.subtotal').each(function() {
        total += parseRupiah($(this).val()) || 0;
    });

    var dp = parseRupiah($('#down_payment').val()) || 0;

    $('#subtotal_display').val(formatNumber(total));
    $('#grand_total_hidden').val(total.toFixed(2));
    $('#st_summary').text(formatNumber(total));
    $('#balance_summary').text(formatNumber(total - dp));
}

function getDefaultUomItem(inventoryId) {
    if (!inventoryId || !inventoryUomData[inventoryId]) return null;

    var list = inventoryUomData[inventoryId];

    for (var i = 0; i < list.length; i++) {
        if (parseInt(list[i].default) === 1) {
            return list[i];
        }
    }

    return list.length ? list[0] : null;
}

function getUomFactor(inventoryId, unit) {
    if (!inventoryId || !unit || !inventoryUomData[inventoryId]) return 0;

    var list = inventoryUomData[inventoryId];

    for (var i = 0; i < list.length; i++) {
        if ((list[i].unit || '').trim() === unit.trim()) {
            return parseFloat(list[i].value) || 0;
        }
    }

    return 0;
}

function showNotification(message, type) {
    var notification = $('<div class="notification">' + escHtml(message) + '</div>');
    var bgColor = type === 'success' ? '#28a745' : '#dc3545';

    notification.css({
        position: 'fixed',
        top: '20px',
        right: '20px',
        padding: '12px 20px',
        background: bgColor,
        color: '#fff',
        borderRadius: '5px',
        zIndex: 9999,
        fontSize: '12px',
        boxShadow: '0 2px 5px rgba(0,0,0,0.2)'
    });

    $('body').append(notification);

    setTimeout(function() {
        notification.fadeOut(500, function() { $(this).remove(); });
    }, 3000);
}

function applyAutoCorrectToRow(row) {
    var $row = $(row);

    var inventoryId = $row.find('.inv-select').val();
    var uomDetailUnit = ($row.find('.inv-uom-detail').val() || '').trim();
    var uomDetailValue = parseFloat($row.find('.inv-uom-detail-value').val()) || 0;
    var uomDetailFactor = parseFloat($row.find('.inv-uom-detail-factor').val()) || 0;
    var uomPack = ($row.find('.inv-uom-pack-select').val() || '').trim();

    if (!inventoryId || !uomDetailUnit || uomDetailValue <= 0) return;

    if (uomDetailFactor <= 0) {
        uomDetailFactor = getUomFactor(inventoryId, uomDetailUnit);
        $row.find('.inv-uom-detail-factor').val(formatDecimalInput(uomDetailFactor));
    }

    if (uomDetailFactor <= 0) {
        showNotification('Perhatian: nilai konversi UoM ' + uomDetailUnit + ' tidak ditemukan di m_inventory_uom.', 'error');
        return;
    }

    var qtyDefault = uomDetailValue * uomDetailFactor;
    $row.find('.qty').val(formatDecimalInput(qtyDefault)).addClass('qty-auto-calculated');

    var packFactor = getUomFactor(inventoryId, uomPack);

    if (uomPack && packFactor > 0) {
        $row.find('.qty-pack').val(formatDecimalInput(qtyDefault / packFactor)).addClass('qty-auto-calculated');
    } else if (uomPack) {
        $row.find('.qty-pack').val(formatDecimalInput(qtyDefault)).addClass('qty-auto-calculated');
        showNotification('Perhatian: nilai konversi UoM Pack ' + uomPack + ' tidak ditemukan, Qty Pack dianggap 1:1.', 'error');
    }

    if (isSpecialInventory(row)) {
        var priceUnit = parseRupiah($row.find('.price-unit').val());
        if (priceUnit > 0) {
            calculatePriceFromPriceUnit(row);
        }
    }

    calculateRow(row);
}

function openUomDetailModal(row) {
    currentUomDetailRow = $(row);

    var inventoryId = currentUomDetailRow.find('.inv-select').val();

    if (!inventoryId) {
        alert('Pilih inventory terlebih dahulu.');
        currentUomDetailRow = null;
        return;
    }

    var list = inventoryUomData[inventoryId] || [];
    var html = '';

    $('#uomDetailModalTitle').text('Pilih UoM Detail - ' + inventoryId);

    if (!list.length) {
        html = '<tr><td colspan="4" style="text-align:center; color:#888;">Data UoM belum tersedia.</td></tr>';
    } else {
        for (var i = 0; i < list.length; i++) {
            var item = list[i];

            var unit = item.unit || '';
            var factor = parseFloat(item.value) || 0;
            var isDefault = parseInt(item.default) === 1;

            var defaultLabel = isDefault
                ? ' <span style="color:#198754; font-weight:bold;">(Default)</span>'
                : '';

            var existingUnit = currentUomDetailRow.find('.inv-uom-detail').val();
            var existingManualValue = currentUomDetailRow.find('.inv-uom-detail-value').val();

            var manualValueDisplay = existingUnit === unit ? existingManualValue : '';

            html += `
                <tr>
                    <td style="text-align:center; font-weight:bold;">
                        ${escHtml(unit)}${defaultLabel}
                    </td>

                    <td style="text-align:right;">
                        ${formatDecimalInput(factor)}
                    </td>

                    <td>
                        <input type="number"
                               step="0.0001"
                               min="0"
                               class="uom-modal-manual-value"
                               data-unit="${escHtml(unit)}"
                               data-factor="${escHtml(factor)}"
                               value="${escHtml(manualValueDisplay)}"
                               placeholder="Qty">
                    </td>

                    <td style="text-align:center;">
                        <button type="button"
                                class="btn-uom-pilih"
                                onclick="chooseUomDetailFromModal(this)">
                            Pilih
                        </button>
                    </td>
                </tr>
            `;
        }
    }

    $('#uomDetailModalBody').html(html);
    $('#uomDetailBackdrop, #uomDetailModal').show();
}

function closeUomDetailModal() {
    $('#uomDetailBackdrop, #uomDetailModal').hide();
    $('#uomDetailModalBody').html('');
    currentUomDetailRow = null;
}

function chooseUomDetailFromModal(btn) {
    if (!currentUomDetailRow) return;

    var input = $(btn).closest('tr').find('.uom-modal-manual-value');

    var selectedUnit = input.data('unit') || '';
    var masterFactor = parseFloat(input.data('factor')) || 0;
    var manualValue = parseFloat(input.val()) || 0;

    if (!selectedUnit) {
        alert('Unit tidak valid.');
        return;
    }

    if (manualValue <= 0) {
        alert('Isi value manual terlebih dahulu.');
        input.focus();
        return;
    }

    if (masterFactor <= 0) {
        alert('Master value UoM belum valid.');
        return;
    }

    var hasilKonversi = manualValue * masterFactor;

    currentUomDetailRow.find('.inv-uom-detail').val(selectedUnit);
    currentUomDetailRow.find('.inv-uom-detail-value').val(formatDecimalInput(manualValue));
    currentUomDetailRow.find('.inv-uom-detail-factor').val(formatDecimalInput(masterFactor));

    if ($('#chkAutoCorrect').is(':checked')) {
        // Allow Auto Correct hanya berjalan jika user mencentang checkbox.
        applyAutoCorrectToRow(currentUomDetailRow[0]);
    } else {
        // Default tetap mengikuti ketentuan add_sales_order sebelumnya.
        currentUomDetailRow.find('.qty, .qty-pack').removeClass('qty-auto-calculated');
        currentUomDetailRow.find('.qty').val(formatDecimalInput(hasilKonversi));
        updateQtyPackBySelectedUomPack(currentUomDetailRow[0]);
        calculateRow(currentUomDetailRow[0]);
    }

    closeUomDetailModal();
}

function recalculateFromUomDetail(row) {
    var $row = $(row);

    if ($('#chkAutoCorrect').is(':checked')) {
        applyAutoCorrectToRow(row);
        return;
    }

    // Default tetap mengikuti ketentuan add_sales_order sebelumnya.
    $row.find('.qty, .qty-pack').removeClass('qty-auto-calculated');

    var manualValue = parseFloat($row.find('.inv-uom-detail-value').val()) || 0;
    var factor = parseFloat($row.find('.inv-uom-detail-factor').val()) || 0;

    if (manualValue > 0 && factor > 0) {
        var hasilKonversi = manualValue * factor;
        $row.find('.qty').val(formatDecimalInput(hasilKonversi));
        updateQtyPackBySelectedUomPack(row);
    }

    calculateRow(row);
}

function updateQtyPackBySelectedUomPack(row) {
    var $row = $(row);

    var inventoryId = $row.find('.inv-select').val();

    var uomDefault = ($row.find('.inv-uom').val() || '').trim();
    var uomPack = ($row.find('.inv-uom-pack-select').val() || '').trim();

    var qtyDefault = parseFloat($row.find('.qty').val()) || 0;

    var uomDetailUnit = ($row.find('.inv-uom-detail').val() || '').trim();
    var uomDetailManualValue = parseFloat($row.find('.inv-uom-detail-value').val()) || 0;

    if (!uomPack) {
        $row.find('.qty-pack').val(0);
        calculateRow(row);
        return;
    }

    // Jika UoM Detail berbeda dengan UoM Pack
    if (uomDetailUnit && uomDetailUnit !== uomPack && uomDetailManualValue > 0) {
        // Jika user mengisi UoM Detail manual, gunakan nilai itu
        // Tapi Qty Pack tetap berdasarkan UoM Pack
        var selectedPackFactor = getUomFactor(inventoryId, uomPack);
        if (selectedPackFactor > 0) {
            var convertedQtyPack = qtyDefault / selectedPackFactor;
            $row.find('.qty-pack').val(formatDecimalInput(convertedQtyPack));
        } else {
            $row.find('.qty-pack').val(formatDecimalInput(qtyDefault));
        }
        calculateRow(row);
        return;
    }

    // Jika UoM Pack sama dengan UoM Default (KG), Qty Pack = Qty
    if (uomPack === uomDefault) {
        $row.find('.qty-pack').val(formatDecimalInput(qtyDefault));
        updateUomDetailFromQtyPack(row);
        calculateRow(row);
        return;
    }

    // Jika UoM Pack sama dengan UoM Detail dan UoM Detail bukan KG
    if (uomPack === uomDetailUnit && uomDetailManualValue > 0) {
        $row.find('.qty-pack').val(formatDecimalInput(uomDetailManualValue));
        updateUomDetailFromQtyPack(row);
        calculateRow(row);
        return;
    }

    // Konversi berdasarkan faktor UoM Pack
    var selectedPackFactor = getUomFactor(inventoryId, uomPack);

    if (selectedPackFactor > 0) {
        var convertedQtyPack = qtyDefault / selectedPackFactor;
        $row.find('.qty-pack').val(formatDecimalInput(convertedQtyPack));
        updateUomDetailFromQtyPack(row);
    } else {
        $row.find('.qty-pack').val(formatDecimalInput(qtyDefault));
        updateUomDetailFromQtyPack(row);
    }

    calculateRow(row);
}

function updateUomDetailFromQtyPack(row) {
    var $row = $(row);
    
    var uomPack = ($row.find('.inv-uom-pack-select').val() || '').trim();
    var uomDefault = ($row.find('.inv-uom').val() || '').trim();
    var qtyPack = parseFloat($row.find('.qty-pack').val()) || 0;
    var inventoryId = $row.find('.inv-select').val();
    
    // Cek apakah UoM Detail sudah diisi manual oleh user
    var currentUomDetail = ($row.find('.inv-uom-detail').val() || '').trim();
    var currentUomDetailValue = parseFloat($row.find('.inv-uom-detail-value').val()) || 0;
    
    // Jika UoM Detail sudah diisi dan nilainya > 0, jangan ubah otomatis
    if (currentUomDetail && currentUomDetailValue > 0) {
        // Tetap pertahankan nilai yang sudah diisi user
        return;
    }
    
    // Jika UoM Pack = KG, maka UoM Detail Value = Qty Pack
    if (uomPack === uomDefault) {
        $row.find('.inv-uom-detail-value').val(formatDecimalInput(qtyPack));
        $row.find('.inv-uom-detail').val(uomPack);
        $row.find('.inv-uom-detail-factor').val(1);
        return;
    }
    
    // Jika UoM Pack selain KG, cari faktor konversi
    var factor = getUomFactor(inventoryId, uomPack);
    if (factor > 0) {
        // UoM Detail Value = Qty Pack (karena UoM Detail sama dengan UoM Pack)
        $row.find('.inv-uom-detail-value').val(formatDecimalInput(qtyPack));
        $row.find('.inv-uom-detail').val(uomPack);
        $row.find('.inv-uom-detail-factor').val(formatDecimalInput(factor));
    }
}

function addRow(data) {
    var $newRow = $(buildRow(data));

    $('#detailBody').append($newRow);

    initSelect2OnRow($newRow);

    if (data && data.inventory_id) {
        $newRow.find('.inv-select').trigger('change');

        if (data.price_unit) {
            $newRow.find('.price-unit').val(formatRupiahInput(data.price_unit));
        }

        if (data.price) {
            $newRow.find('.price').val(formatRupiahInput(data.price));
        }

        calculateRow($newRow[0]);
    }

    updateRowNumbers();
    calculateGrandTotal();
}

function initSelect2OnRow($row) {
    $row.find('.inv-select')
        .select2({
            placeholder: '🔍 Pilih Produk...',
            allowClear: true,
            width: '100%'
        })
        .on('change', function() {
            var tr = $(this).closest('tr');

            var inventoryId = $(this).val();
            var selectedOption = $(this).find('option:selected');

            var inventoryName = selectedOption.data('inv-name') || '';
            var uom = 'KG';
            var uomPack = selectedOption.data('uom-pack') || uom;

            var p = parseFloat(selectedOption.data('p')) || 0;
            var l = parseFloat(selectedOption.data('l')) || 0;
            var t = parseFloat(selectedOption.data('t')) || 0;

            tr.find('.inv-name-hidden').val(inventoryName);
            tr.find('.inv-uom').val(uom);

            tr.find('.inv-p').val(p);
            tr.find('.inv-l').val(l);
            tr.find('.inv-t').val(t);

            var uomPackSelect = tr.find('.inv-uom-pack-select');
            uomPackSelect.empty();

            if (inventoryId && inventoryUomData[inventoryId]) {
                var uomOptions = inventoryUomData[inventoryId];

                for (var i = 0; i < uomOptions.length; i++) {
                    var uomItem = uomOptions[i];
                    var selectedAttr = parseInt(uomItem.default) === 1 ? 'selected' : '';

                    uomPackSelect.append(
                        '<option value="' + escHtml(uomItem.unit) + '" ' + selectedAttr + '>' +
                            escHtml(uomItem.unit) +
                        '</option>'
                    );
                }
            } else {
                if (uomPack) {
                    uomPackSelect.append(
                        '<option value="' + escHtml(uomPack) + '" selected>' +
                            escHtml(uomPack) +
                        '</option>'
                    );
                } else {
                    uomPackSelect.append('<option value="">-- Pilih UoM Pack --</option>');
                }
            }

            // Reset semua nilai
            tr.find('.inv-uom-detail').val('');
            tr.find('.inv-uom-detail-value').val(0);
            tr.find('.inv-uom-detail-factor').val(0);
            tr.find('.qty').val(0);
            tr.find('.qty-pack').val(0);
            
            // Set price_unit readonly atau tidak berdasarkan inventory
            updatePriceUnitReadonly(tr[0]);

            // Reset Price Unit dan Price ke 0
            tr.find('.price-unit').val('0');
            tr.find('.price').val('0');
            tr.find('.subtotal').val('0');

            // Hitung ulang
            calculateRow(tr[0]);
        });

    $row.find('.inv-uom-detail').on('click', function() {
        openUomDetailModal($(this).closest('tr')[0]);
    });

    $row.find('.inv-uom-detail-value').on('input', function() {
        recalculateFromUomDetail($(this).closest('tr')[0]);
    });

    $row.find('.inv-uom-pack-select').on('change', function() {
        var tr = $(this).closest('tr');

        if ($('#chkAutoCorrect').is(':checked') && (tr.find('.inv-uom-detail').val() || '').trim() && (parseFloat(tr.find('.inv-uom-detail-value').val()) || 0) > 0) {
            applyAutoCorrectToRow(tr[0]);
            return;
        }

        updateQtyPackBySelectedUomPack(tr[0]);
        updateUomDetailFromQtyPack(tr[0]);
        
        // Recalculate price jika inventory khusus
        if (isSpecialInventory(tr[0])) {
            var priceUnit = parseRupiah(tr.find('.price-unit').val());
            if (priceUnit > 0) {
                calculatePriceFromPriceUnit(tr[0]);
            }
        }
    });

    // Event untuk Qty - jika Qty diisi manual
    $row.find('.qty').on('input', function() {
        var tr = $(this).closest('tr');
        
        // Update Qty Pack berdasarkan UoM Pack
        updateQtyPackBySelectedUomPack(tr[0]);
        // Update UoM Detail berdasarkan Qty Pack
        updateUomDetailFromQtyPack(tr[0]);
        
        // Recalculate price jika inventory khusus
        if (isSpecialInventory(tr[0])) {
            var priceUnit = parseRupiah(tr.find('.price-unit').val());
            if (priceUnit > 0) {
                calculatePriceFromPriceUnit(tr[0]);
            }
        }
        
        calculateRow(tr[0]);
    });

    // Event untuk Qty Pack - jika diisi manual
    $row.find('.qty-pack').on('input', function() {
        var tr = $(this).closest('tr');
        var qtyPack = parseFloat($(this).val()) || 0;
        var uomPack = tr.find('.inv-uom-pack-select').val() || '';
        var uomDefault = tr.find('.inv-uom').val() || '';
        var uomDetail = tr.find('.inv-uom-detail').val() || '';
        var inventoryId = tr.find('.inv-select').val();
        
        // Update UoM Detail Value otomatis
        tr.find('.inv-uom-detail-value').val(formatDecimalInput(qtyPack));
        tr.find('.inv-uom-detail').val(uomPack);
        
        // Cari faktor konversi untuk UoM Pack
        var factor = getUomFactor(inventoryId, uomPack);
        tr.find('.inv-uom-detail-factor').val(formatDecimalInput(factor));
        
        // Jika UoM Pack sama dengan UoM Default (KG), maka Qty ikut Qty Pack
        if (uomPack === uomDefault) {
            tr.find('.qty').val(formatDecimalInput(qtyPack));
        } 
        // Jika UoM Pack sama dengan UoM Detail dan UoM Detail bukan KG
        else if (uomPack === uomDetail && uomDetail !== uomDefault) {
            // Qty dihitung dari Qty Pack * faktor konversi
            var factorConv = getUomFactor(inventoryId, uomPack);
            if (factorConv > 0) {
                tr.find('.qty').val(formatDecimalInput(qtyPack * factorConv));
            }
        }
        // Selain itu, konversi berdasarkan value dari m_inventory_uom
        else {
            var factorConv = getUomFactor(inventoryId, uomPack);
            if (factorConv > 0) {
                tr.find('.qty').val(formatDecimalInput(qtyPack * factorConv));
            }
        }
        
        // Recalculate price jika inventory khusus
        if (isSpecialInventory(tr[0])) {
            var priceUnit = parseRupiah(tr.find('.price-unit').val());
            if (priceUnit > 0) {
                calculatePriceFromPriceUnit(tr[0]);
            }
        }
        
        calculateRow(tr[0]);
    });

    // ============================================================
    // PERBAIKAN: Event untuk Price Unit (hanya untuk inventory khusus)
    // ============================================================
    $row.find('.price-unit').on('input', function() {
        var tr = $(this).closest('tr');
        
        // Hanya proses jika inventory khusus
        if (isSpecialInventory(tr[0])) {
            var cursorPosition = this.selectionStart;
            var beforeLength = this.value.length;

            this.value = formatRupiahInput(this.value);

            var afterLength = this.value.length;
            this.selectionStart = this.selectionEnd = cursorPosition + (afterLength - beforeLength);

            calculatePriceFromPriceUnit(tr[0]);
        }
    });

    // ============================================================
    // PERBAIKAN: Event untuk Price (hanya untuk inventory non-khusus)
    // ============================================================
    $row.find('.price').on('input', function() {
        var tr = $(this).closest('tr');
        
        // Hanya proses jika BUKAN inventory khusus
        if (!isSpecialInventory(tr[0])) {
            var cursorPosition = this.selectionStart;
            var beforeLength = this.value.length;

            this.value = formatRupiahInput(this.value);

            var afterLength = this.value.length;
            this.selectionStart = this.selectionEnd = cursorPosition + (afterLength - beforeLength);

            calculateRow(tr[0]);
        }
    });
}

function removeRow(btn) {
    $(btn).closest('tr').remove();

    updateRowNumbers();
    calculateGrandTotal();
}

function deleteSelected() {
    var $checked = $('.rowCheckbox:checked');

    if (!$checked.length) {
        alert('Silakan centang baris item!');
        return;
    }

    if (confirm('Hapus baris terpilih?')) {
        $checked.each(function() {
            $(this).closest('tr').remove();
        });

        updateRowNumbers();
        calculateGrandTotal();
    }
}

$(document).on('input', '#down_payment', function() {
    var cursorPosition = this.selectionStart;
    var beforeLength = this.value.length;

    this.value = formatRupiahInput(this.value);

    var afterLength = this.value.length;
    this.selectionStart = this.selectionEnd = cursorPosition + (afterLength - beforeLength);

    calculateGrandTotal();
});


$(document).on('change', '#chkAutoCorrect', function() {
    if (this.checked) {
        $('#detailBody .detail-row').each(function() {
            var $row = $(this);
            var hasUomDetail = ($row.find('.inv-uom-detail').val() || '').trim();
            var hasValue = (parseFloat($row.find('.inv-uom-detail-value').val()) || 0) > 0;

            if (hasUomDetail && hasValue) {
                applyAutoCorrectToRow(this);
            }
        });
        showNotification('Auto Correct diaktifkan. Qty & Qty Pack dihitung ulang dari UoM Detail.', 'success');
    } else {
        $('.qty, .qty-pack').removeClass('qty-auto-calculated');
    }
});

$(document).ready(function() {
    flatpickr(".datepicker", {
        dateFormat: "d-M-Y",
        altFormat: "d-M-Y",
        allowInput: true,
        disableMobile: true
    });

    $('#marketing_id, #sales_id, #customer_id').select2({
        width: '100%',
        placeholder: '-- Pilih --',
        allowClear: true
    });

    $('#customer_id').on('change', function() {
        var opt = this.options[this.selectedIndex];

        if (!opt || this.value === '') {
            $('#customer_address, #customer_city, #shipment_location, #customer_name_hidden').val('');
            return;
        }

        $('#customer_address').val(opt.getAttribute('data-address') || '');
        $('#customer_city').val(opt.getAttribute('data-city') || '');
        $('#shipment_location').val(opt.getAttribute('data-address') || '');
        $('#customer_name_hidden').val(opt.text ? opt.text.trim() : '');
    });

    $('#selectAll').on('change', function() {
        $('.rowCheckbox').prop('checked', this.checked);
    });

    $('#uomDetailBackdrop').on('click', function() {
        closeUomDetailModal();
    });

    $('#formSO').on('submit', function(e) {
        if ($('#detailBody .detail-row').length === 0) {
            e.preventDefault();
            alert('Gagal Simpan: Detail item pesanan tidak boleh kosong!');
            return false;
        }

        var isValid = true;

        $('#detailBody .detail-row').each(function() {
            if (!$(this).find('.inv-select').val()) {
                isValid = false;
                return false;
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Gagal Simpan: Ada baris item yang belum dipilih produknya!');
            return false;
        }

        $('input[name="order_date"], input[name="shipment_due_date"]').each(function() {
            var dateValue = $(this).val();

            if (dateValue) {
                var dateParts = dateValue.split('-');

                if (dateParts.length === 3) {
                    var months = {
                        'Jan': '01',
                        'Feb': '02',
                        'Mar': '03',
                        'Apr': '04',
                        'Mei': '05',
                        'Jun': '06',
                        'Jul': '07',
                        'Agu': '08',
                        'Sep': '09',
                        'Okt': '10',
                        'Nov': '11',
                        'Des': '12'
                    };

                    var monthNum = months[dateParts[1]];

                    if (monthNum) {
                        $(this).val(dateParts[2] + '-' + monthNum + '-' + dateParts[0]);
                    }
                }
            }
        });

        $('.price-unit, .price, .subtotal, #down_payment').each(function() {
            $(this).val(parseRupiah($(this).val()).toFixed(2));
        });

        $('#btnSave').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

        return true;
    });

    addRow();
});
</script>