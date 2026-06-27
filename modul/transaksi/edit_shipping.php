<?php
// modul/transaksi/edit_shipping.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// Ambil shipping_no dari URL
$shipping_no = isset($_GET['id']) ? mysqli_real_escape_string($conn, trim($_GET['id'])) : '';

if (empty($shipping_no)) {
    $_SESSION['alert'] = '<div class="alert alert-danger">Shipping No tidak ditemukan!</div>';
    echo "<script>window.location.href='index.php?page=shipping';</script>";
    exit;
}

// Ambil data header shipping
$query_header = mysqli_query($conn, "
    SELECT 
        hs.*,
        mg.name AS gudang_name
    FROM hed_shipping hs
    LEFT JOIN m_gudang mg ON hs.gudang_id = mg.gudang_id
    WHERE hs.shipping_no = '$shipping_no'
");

if (!$query_header || mysqli_num_rows($query_header) == 0) {
    $_SESSION['alert'] = '<div class="alert alert-danger">Data shipping tidak ditemukan!</div>';
    echo "<script>window.location.href='index.php?page=shipping';</script>";
    exit;
}

$header = mysqli_fetch_assoc($query_header);

// Ambil data detail shipping
$query_detail = mysqli_query($conn, "
    SELECT *
    FROM det_shipping
    WHERE shipping_no = '$shipping_no'
    ORDER BY id ASC
");

$details = [];
while ($row = mysqli_fetch_assoc($query_detail)) {
    $details[] = $row;
}

// Ambil semua data order untuk dropdown
$order_rs = mysqli_query($conn, "
    SELECT 
        h.order_no,
        h.order_date,
        h.customer_id,
        h.customer_name,
        h.customer_address,
        h.customer_city,
        h.shipment_location,
        h.status,
        h.approval_status
    FROM head_sales_order h
    WHERE h.status = 'Open'
    ORDER BY h.order_date DESC, h.order_no DESC
");

// Ambil semua data gudang
$gudang_rs = mysqli_query($conn, "SELECT gudang_id, name FROM m_gudang ORDER BY name ASC");

// Default gudang: GUDANG BARANG JADI 1 (FC-02)
$default_gudang_id = 'FC-02';

// Fungsi format tanggal
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
    if (!$timestamp) return '';
    
    $tanggal = date('d', $timestamp);
    $bulan_num = (int)date('m', $timestamp);
    $tahun = date('Y', $timestamp);
    
    return $tanggal . '-' . $bulan[$bulan_num] . '-' . $tahun;
}

// Format tanggal untuk tampilan
$shipping_date_display = formatDateIndonesian($header['shipping_date']);
$order_date_display = formatDateIndonesian($header['order_date']);
$nota_date_display = formatDateIndonesian($header['nota_date']);
$date_created_display = formatDateIndonesian($header['date_created']);
$date_modified_display = formatDateIndonesian($header['date_modified']);
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
.shipping-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', 'Consolas', 'Cascadia Code', monospace;
}
.shipping-wrap {
    background: var(--bg-base);
    padding: 12px;
    color: var(--text-primary);
}
.panel-row {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
}
.shipping-panel {
    flex: 1;
    background: var(--bg-panel);
    border: 1px solid var(--border);
    border-radius: 4px;
    overflow: hidden;
}
.shipping-panel-header {
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
.shipping-panel-body {
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
.shipping-panel-full {
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
    gap: 5px;
}
.btn-primary { background: var(--accent-blue); color: #fff; }
.btn-success { background: var(--accent-green); color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-danger { background: #dc3545; color: #fff; padding: 4px 8px; font-size: 10px; border-radius: 2px; }
.btn-warning { background: #ffc107; color: #000; }

.detail-table-wrap {
    max-height: 450px;
    overflow: auto;
}
.detail-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    min-width: 1600px;
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
    text-align: center;
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
.detail-table select {
    width: 100%;
    background: transparent;
    border: none;
    font-size: 11px;
    padding: 2px;
    outline: none;
}
.shipping-footer-row {
    display: flex;
    justify-content: flex-end;
    padding: 10px;
    background: #f8f9fa;
    border-top: 1px solid var(--border);
}
.shipping-actionbar {
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
.info-label {
    font-size: 10px;
    color: #6c757d;
    margin-top: 2px;
}
</style>

<div class="shipping-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="crystal-header mb-3" style="background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%); color: #fff; padding: 10px 15px; border-radius: 5px;">
        <h5 class="m-0"><i class="fa fa-edit"></i> Edit Shipping: <?= htmlspecialchars($shipping_no) ?></h5>
    </div>

    <form method="POST" action="index.php?page=update_shipping" id="formShipping">
        <input type="hidden" name="shipping_no" value="<?= htmlspecialchars($shipping_no) ?>">
        <input type="hidden" name="old_order_no" value="<?= htmlspecialchars($header['order_no']) ?>">
        
        <!-- PANEL 1: Shipping Information -->
        <div class="panel-row">
            <div class="shipping-panel">
                <div class="shipping-panel-header"><i class="fa-solid fa-truck"></i> Shipping Information</div>
                <div class="shipping-panel-body">
                    <div class="ff">
                        <label>Shipping No</label>
                        <input type="text" value="<?= htmlspecialchars($shipping_no) ?>" readonly style="font-weight:bold; color:var(--accent-blue);">
                    </div>
                    <div class="ff">
                        <label>Shipping Date <span class="required">*</span></label>
                        <input type="text" name="shipping_date" class="form-control form-control-sm datepicker" value="<?= $shipping_date_display ?>" required>
                    </div>
                    <div class="ff">
                        <label>Nota Date</label>
                        <input type="text" name="nota_date" class="form-control form-control-sm datepicker" value="<?= $nota_date_display ?>">
                    </div>
                </div>
            </div>

            <!-- PANEL 2: Order Information -->
            <div class="shipping-panel">
                <div class="shipping-panel-header"><i class="fa-solid fa-file-invoice"></i> Order Information</div>
                <div class="shipping-panel-body">
                    <div class="ff">
                        <label>Order No <span class="required">*</span></label>
                        <select name="order_no" id="order_no" required>
                            <option value="">-- Pilih Order No --</option>
                            <?php 
                            // Reset pointer
                            mysqli_data_seek($order_rs, 0);
                            while ($o = mysqli_fetch_assoc($order_rs)): 
                                $selected = ($o['order_no'] == $header['order_no']) ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($o['order_no']) ?>" <?= $selected ?>
                                        data-order-date="<?= htmlspecialchars($o['order_date']) ?>"
                                        data-customer-id="<?= htmlspecialchars($o['customer_id']) ?>"
                                        data-customer-name="<?= htmlspecialchars($o['customer_name']) ?>"
                                        data-customer-address="<?= htmlspecialchars($o['customer_address']) ?>"
                                        data-customer-city="<?= htmlspecialchars($o['customer_city']) ?>"
                                        data-shipment-location="<?= htmlspecialchars($o['shipment_location']) ?>">
                                    <?= htmlspecialchars($o['order_no']) ?> - <?= htmlspecialchars($o['customer_name']) ?>
                                </option>
                            <?php endwhile; ?>
                            <!-- Tambahkan option untuk order yang sedang dipilih (mungkin sudah Close) -->
                            <?php if ($header['order_no']): ?>
                            <option value="<?= htmlspecialchars($header['order_no']) ?>" selected
                                    data-order-date="<?= htmlspecialchars($header['order_date']) ?>"
                                    data-customer-id="<?= htmlspecialchars($header['customer_id']) ?>"
                                    data-customer-name="<?= htmlspecialchars($header['customer_name']) ?>"
                                    data-customer-address="<?= htmlspecialchars($header['customer_address']) ?>"
                                    data-customer-city="<?= htmlspecialchars($header['customer_city']) ?>"
                                    data-shipment-location="<?= htmlspecialchars($header['shipment_location']) ?>">
                                <?= htmlspecialchars($header['order_no']) ?> - <?= htmlspecialchars($header['customer_name']) ?> (Current)
                            </option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Order Date</label>
                        <input type="text" name="order_date" id="order_date" value="<?= $order_date_display ?>" readonly>
                    </div>
                </div>
            </div>

            <!-- PANEL 3: Customer Information -->
            <div class="shipping-panel">
                <div class="shipping-panel-header"><i class="fa-solid fa-building-user"></i> Customer Information</div>
                <div class="shipping-panel-body">
                    <div class="ff">
                        <label>Customer ID</label>
                        <input type="text" name="customer_id" id="customer_id" value="<?= htmlspecialchars($header['customer_id']) ?>" readonly>
                    </div>
                    <div class="ff">
                        <label>Customer Name</label>
                        <input type="text" name="customer_name" id="customer_name" value="<?= htmlspecialchars($header['customer_name']) ?>" readonly>
                    </div>
                    <div class="ff">
                        <label>Customer Address</label>
                        <textarea name="customer_address" id="customer_address" rows="2" readonly><?= htmlspecialchars($header['customer_address']) ?></textarea>
                    </div>
                    <div class="ff">
                        <label>Customer City</label>
                        <input type="text" name="customer_city" id="customer_city" value="<?= htmlspecialchars($header['customer_city']) ?>" readonly>
                    </div>
                </div>
            </div>
        </div>

      <!-- PANEL 4: Transporter & Shipping Location -->
<div class="panel-row">
    <div class="shipping-panel">
        <div class="shipping-panel-header"><i class="fa-solid fa-truck-fast"></i> Transporter Information</div>
        <div class="shipping-panel-body">
            <div class="ff">
                <label>Transporter</label>
                <input type="text" name="transporter" value="<?= htmlspecialchars($header['transporter']) ?>" placeholder="Nama Transporter...">
            </div>
            <div class="ff">
                <label>Driver Name</label>
                <input type="text" name="driver_name" value="<?= htmlspecialchars($header['driver_name']) ?>" placeholder="Nama Supir...">
            </div>
            <div class="ff">
                <label>Truck No</label>
                <input type="text" name="truck_no" value="<?= htmlspecialchars($header['truck_no']) ?>" placeholder="Nomor Truk...">
            </div>
        </div>
    </div>

    <div class="shipping-panel">
        <div class="shipping-panel-header"><i class="fa-solid fa-warehouse"></i> Shipping Location</div>
        <div class="shipping-panel-body">
            <div class="ff">
                <label>Shipment Location</label>
                <textarea name="shipment_location" id="shipment_location" rows="2" placeholder="Alamat pengiriman..."><?= htmlspecialchars($header['shipment_location']) ?></textarea>
            </div>
            <div class="ff">
                <label>Gudang <span class="required">*</span></label>
                <select name="gudang_id" id="gudang_id" required>
                    <option value="">-- Pilih Gudang --</option>
                    <?php 
                    mysqli_data_seek($gudang_rs, 0);
                    while ($g = mysqli_fetch_assoc($gudang_rs)): 
                        // Jika ada nilai di database, gunakan nilai tersebut
                        // Jika tidak ada (NULL/kosong), gunakan default FC-02
                        if (!empty($header['gudang_id'])) {
                            $selected = ($g['gudang_id'] == $header['gudang_id']) ? 'selected' : '';
                        } else {
                            $selected = ($g['gudang_id'] == $default_gudang_id) ? 'selected' : '';
                        }
                    ?>
                        <option value="<?= htmlspecialchars($g['gudang_id']) ?>" <?= $selected ?>>
                            <?= htmlspecialchars($g['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="ff">
                <label>Remarks Shipping</label>
                <textarea name="remarks_shipping" rows="1" placeholder="Catatan pengiriman..."><?= htmlspecialchars($header['remarks_shipping']) ?></textarea>
            </div>
        </div>
    </div>
</div>
        <!-- PANEL 5: Inventory Details -->
        <div class="shipping-panel-full">
            <div class="shipping-panel-header">
                <i class="fa-solid fa-boxes-stacked"></i> Inventory Items to Ship
                <span id="row_count_label" style="color:#777; font-size:10px; margin-left:10px; font-weight:normal;">// <?= count($details) ?> rows</span>
            </div>

            <div class="detail-toolbar">
                <button type="button" class="btn-vs btn-primary" onclick="addRow()"><i class="fa fa-plus"></i> Tambah Item</button>
                <button type="button" class="btn-vs btn-secondary" onclick="deleteSelected()"><i class="fa fa-trash"></i> Hapus Terpilih</button>
                <button type="button" class="btn-vs btn-warning" onclick="loadInventoryFromOrder()"><i class="fa fa-sync"></i> Load dari Order</button>
                <span style="font-size:10px; color:#888; margin-left:10px;">
                    <i class="fa fa-info-circle"></i> Klik "Load dari Order" untuk mengambil inventory dari Sales Order
                </span>
            </div>

            <div class="detail-table-wrap">
                <table class="detail-table" id="detailTable">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="selectAll"></th>
                            <th style="width:35px;">#</th>
                            <th style="width:130px;">Inventory ID</th>
                            <th style="width:180px;">Inventory Name</th>
                            <th style="width:60px;">UoM</th>
                            <th style="width:70px;">Qty</th>
                            <th style="width:70px;">Qty Pack</th>
                            <th style="width:90px;">UoM Pack</th>
                            <th style="width:130px;">UoM Detail</th>
                            <th style="width:80px;">Adjustment</th>
                            <th style="width:120px;">Remarks</th>
                            <th style="width:45px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="detailBody">
                        <?php foreach ($details as $index => $detail): ?>
                        <tr class="detail-row" data-idx="<?= $index ?>">
                            <td style="text-align:center;">
                                <input type="checkbox" class="rowCheckbox">
                            </td>
                            <td class="ln-cell" style="text-align:center; font-weight:bold; color:#888;"><?= $index + 1 ?></td>
                            <td>
                                <input type="text" name="inventory_id[]" class="inv-id" value="<?= htmlspecialchars($detail['inventory_id']) ?>" readonly style="background:#f1f3f5; font-weight:bold; text-align:center;">
                                <input type="hidden" name="inventory_name[]" class="inv-name-hidden" value="<?= htmlspecialchars($detail['inventory_name']) ?>">
                                <input type="hidden" name="detail_id[]" value="<?= $detail['id'] ?>">
                            </td>
                            <td>
                                <input type="text" class="inv-name-display" value="<?= htmlspecialchars($detail['inventory_name']) ?>" readonly style="background:#f1f3f5;">
                            </td>
                            <td>
                                <input type="text" name="uom_shipping[]" class="inv-uom" value="<?= htmlspecialchars($detail['uom_shipping']) ?>" readonly style="background:#f1f3f5; text-align:center;">
                            </td>
                            <td>
                                <input type="number" step="0.0001" name="qty_shipping[]" class="qty-shipping" value="<?= $detail['qty_shipping'] ?>" style="text-align:center;">
                            </td>
                            <td>
                                <input type="number" step="0.0001" name="qty_pack_shipping[]" class="qty-pack-shipping" value="<?= $detail['qty_pack_shipping'] ?>" style="text-align:center;">
                            </td>
                            <td>
                                <input type="text" name="uom_pack_shipping[]" class="inv-uom-pack" value="<?= htmlspecialchars($detail['uom_pack_shipping']) ?>" style="text-align:center;">
                            </td>
                            <td>
                                <input type="text" name="uom_detail_shipping[]" class="inv-uom-detail" value="<?= htmlspecialchars($detail['uom_detail_shipping']) ?>" style="text-align:center;">
                            </td>
                            <td>
                                <input type="number" step="0.0001" name="adjustment_shipping[]" class="adjustment-shipping" value="<?= $detail['adjustment_shipping'] ?>" style="text-align:center;">
                            </td>
                            <td>
                                <input type="text" name="remarks_inventory_shipping[]" class="inv-remarks" value="<?= htmlspecialchars($detail['remarks_inventory_shipping']) ?>" placeholder="Catatan...">
                            </td>
                            <td style="text-align:center;">
                                <button type="button" class="btn-vs btn-danger" onclick="removeRow(this)">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="shipping-footer-row">
                <div style="display:flex; align-items:center; gap:20px;">
                    <span style="font-size:11px; font-weight:bold; color:var(--text-label);">TOTAL ITEMS :</span>
                    <span id="total_items_label" style="font-weight:bold; color:var(--accent-blue); font-size:14px;"><?= count($details) ?></span>
                </div>
            </div>
        </div>

        <div class="shipping-actionbar">
            <button type="button" class="btn-vs btn-secondary" onclick="window.location.href='index.php?page=shipping'">
                <i class="fa fa-times"></i> Batal / Kembali
            </button>
            <button type="submit" class="btn-vs btn-success" id="btnSave">
                <i class="fa fa-save"></i> Update Shipping
            </button>
        </div>
    </form>
</div>

<script>
var rowCounter = <?= count($details) ?>;
var currentOrderNo = '<?= $header['order_no'] ?>';

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

function updateRowNumbers() {
    var rows = document.querySelectorAll('#detailBody .detail-row');
    rows.forEach(function(r, i) {
        var c = r.querySelector('.ln-cell');
        if (c) c.textContent = i + 1;
    });
    document.getElementById('row_count_label').textContent = '// ' + rows.length + ' Item Terdaftar';
    document.getElementById('total_items_label').textContent = rows.length;
}

function buildRow(data) {
    var idx = rowCounter++;
    data = data || {};
    
    return `<tr class="detail-row" data-idx="${idx}">
        <td style="text-align:center;">
            <input type="checkbox" class="rowCheckbox">
        </td>
        <td class="ln-cell" style="text-align:center; font-weight:bold; color:#888;"></td>
        <td>
            <input type="text" name="inventory_id[]" class="inv-id" value="${escHtml(data.inventory_id || '')}" readonly style="background:#f1f3f5; font-weight:bold; text-align:center;">
            <input type="hidden" name="inventory_name[]" class="inv-name-hidden" value="${escHtml(data.inventory_name || '')}">
            <input type="hidden" name="detail_id[]" value="new">
        </td>
        <td>
            <input type="text" class="inv-name-display" value="${escHtml(data.inventory_name || '')}" readonly style="background:#f1f3f5;">
        </td>
        <td>
            <input type="text" name="uom_shipping[]" class="inv-uom" value="${escHtml(data.uom || '')}" readonly style="background:#f1f3f5; text-align:center;">
        </td>
        <td>
            <input type="number" step="0.0001" name="qty_shipping[]" class="qty-shipping" value="${data.qty || 0}" style="text-align:center;">
        </td>
        <td>
            <input type="number" step="0.0001" name="qty_pack_shipping[]" class="qty-pack-shipping" value="${data.qty_pack || 0}" style="text-align:center;">
        </td>
        <td>
            <input type="text" name="uom_pack_shipping[]" class="inv-uom-pack" value="${escHtml(data.uom_pack || '')}" style="text-align:center;">
        </td>
        <td>
            <input type="text" name="uom_detail_shipping[]" class="inv-uom-detail" value="${escHtml(data.uom_detail || '')}" style="text-align:center;">
        </td>
        <td>
            <input type="number" step="0.0001" name="adjustment_shipping[]" class="adjustment-shipping" value="${data.adjustment || 0}" style="text-align:center;">
        </td>
        <td>
            <input type="text" name="remarks_inventory_shipping[]" class="inv-remarks" value="${escHtml(data.remarks || '')}" placeholder="Catatan...">
        </td>
        <td style="text-align:center;">
            <button type="button" class="btn-vs btn-danger" onclick="removeRow(this)">
                <i class="fa fa-trash"></i>
            </button>
        </td>
    </tr>`;
}

function addRow(data) {
    var $newRow = $(buildRow(data || {}));
    $('#detailBody').append($newRow);
    updateRowNumbers();
}

function removeRow(btn) {
    if (confirm('Hapus item ini?')) {
        $(btn).closest('tr').remove();
        updateRowNumbers();
    }
}

function deleteSelected() {
    var $checked = $('.rowCheckbox:checked');
    if (!$checked.length) {
        alert('Silakan centang baris item yang akan dihapus!');
        return;
    }
    if (confirm('Hapus baris terpilih?')) {
        $checked.each(function() {
            $(this).closest('tr').remove();
        });
        updateRowNumbers();
    }
}

// Load inventory dari order yang dipilih
function loadInventoryFromOrder() {
    var orderNo = $('#order_no').val();
    if (!orderNo) {
        alert('Pilih Order No terlebih dahulu!');
        return;
    }

    // Konfirmasi: akan mengganti semua item yang ada
    var currentItems = $('#detailBody .detail-row').length;
    if (currentItems > 0) {
        if (!confirm('Load dari order akan mengganti semua item yang ada. Lanjutkan?')) {
            return;
        }
    }

    $.ajax({
        url: 'modul/transaksi/get_order_inventory.php',
        type: 'POST',
        data: { order_no: orderNo },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data.length > 0) {
                $('#detailBody').empty();
                rowCounter = 0;
                
                response.data.forEach(function(item) {
                    addRow({
                        inventory_id: item.inventory_id,
                        inventory_name: item.inventory_name,
                        uom: item.uom,
                        qty: parseFloat(item.quantity) || 0,
                        qty_pack: parseFloat(item.quantity_pack) || 0,
                        adjustment: 0,
                        remarks: item.remarks || '',
                        uom_pack: item.uom_pack || item.uom,
                        uom_detail: item.uom_detail || item.uom
                    });
                });
                
                updateRowNumbers();
                showNotification('Berhasil load ' + response.data.length + ' item dari order', 'success');
            } else {
                alert('Tidak ada inventory ditemukan untuk order ini.');
            }
        },
        error: function() {
            alert('Gagal mengambil data inventory. Silakan coba lagi.');
        }
    });
}

function showNotification(message, type) {
    var notification = $('<div class="notification">' + message + '</div>');
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

// Event handler untuk order_no change
$(document).on('change', '#order_no', function() {
    var opt = this.options[this.selectedIndex];
    if (!opt || this.value === '') {
        $('#order_date, #customer_id, #customer_name, #customer_address, #customer_city, #shipment_location').val('');
        return;
    }

    $('#order_date').val(opt.getAttribute('data-order-date') || '');
    $('#customer_id').val(opt.getAttribute('data-customer-id') || '');
    $('#customer_name').val(opt.getAttribute('data-customer-name') || '');
    $('#customer_address').val(opt.getAttribute('data-customer-address') || '');
    $('#customer_city').val(opt.getAttribute('data-customer-city') || '');
    $('#shipment_location').val(opt.getAttribute('data-shipment-location') || '');
});

// Select2 initialization
$(document).ready(function() {
    flatpickr(".datepicker", {
        dateFormat: "d-M-Y",
        altFormat: "d-M-Y",
        allowInput: true,
        disableMobile: true
    });

    $('#order_no, #gudang_id').select2({
        width: '100%',
        placeholder: '-- Pilih --',
        allowClear: true
    });

    $('#selectAll').on('change', function() {
        $('.rowCheckbox').prop('checked', this.checked);
    });

    // Form submit validation
    $('#formShipping').on('submit', function(e) {
        if ($('#detailBody .detail-row').length === 0) {
            e.preventDefault();
            alert('Gagal Update: Item inventory tidak boleh kosong!');
            return false;
        }

        // Konversi tanggal ke format YYYY-MM-DD
        $('input[name="shipping_date"], input[name="nota_date"]').each(function() {
            var dateValue = $(this).val();
            if (dateValue) {
                var dateParts = dateValue.split('-');
                if (dateParts.length === 3) {
                    var months = {
                        'Jan': '01', 'Feb': '02', 'Mar': '03', 'Apr': '04',
                        'Mei': '05', 'May': '05', 'Jun': '06', 'Jul': '07',
                        'Agu': '08', 'Aug': '08', 'Sep': '09', 'Okt': '10',
                        'Oct': '10', 'Nov': '11', 'Des': '12', 'Dec': '12'
                    };
                    var monthNum = months[dateParts[1]];
                    if (monthNum) {
                        $(this).val(dateParts[2] + '-' + monthNum + '-' + dateParts[0]);
                    }
                }
            }
        });

        $('#btnSave').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');
        return true;
    });
});
</script>