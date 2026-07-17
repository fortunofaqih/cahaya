<?php
// modul/transaksi/add_shipping.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// AJAX: cek Shipping No manual apakah sudah ada di database
if (isset($_GET['ajax_check_shipping_no'])) {
    header('Content-Type: application/json; charset=utf-8');

    $shipping_no = isset($_GET['shipping_no']) ? trim($_GET['shipping_no']) : '';
    if ($shipping_no === '') {
        echo json_encode(['exists' => false, 'message' => 'Shipping No kosong']);
        exit;
    }

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM hed_shipping WHERE shipping_no = ?");
    mysqli_stmt_bind_param($stmt, 's', $shipping_no);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);

    echo json_encode([
        'exists' => ((int)($row['total'] ?? 0) > 0),
        'total'  => (int)($row['total'] ?? 0)
    ]);
    exit;
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
        h.tolerance,
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

// Default values
$shipping_date_display = formatDateIndonesian(date('Y-m-d'));
$nota_date_display = formatDateIndonesian(date('Y-m-d'));
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
.warning-text {
    font-size: 10px;
    color: #dc3545;
    margin-top: 4px;
    display: none;
    font-weight: bold;
}
.success-text {
    font-size: 10px;
    color: #198754;
    margin-top: 4px;
    display: none;
    font-weight: bold;
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
    align-items: center;
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
.btn-vs:disabled { opacity: .6; cursor: not-allowed; }
.detail-table-wrap {
    max-height: 450px;
    overflow: auto;
}
.detail-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    min-width: 1350px;
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

/* Modal */
.shipping-modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 9998;
    align-items: center;
    justify-content: center;
    padding: 20px;
}
.shipping-modal {
    width: min(1150px, 96vw);
    max-height: 88vh;
    background: #fff;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 12px 35px rgba(0,0,0,.25);
    display: flex;
    flex-direction: column;
}
.shipping-modal-header {
    background: #e9ecef;
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    font-weight: bold;
}
.shipping-modal-body {
    padding: 12px;
    overflow: auto;
}
.shipping-modal-footer {
    padding: 10px 12px;
    background: #f8f9fa;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
.modal-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
}
.modal-table th, .modal-table td {
    border: 1px solid var(--border);
    padding: 6px;
}
.modal-table th {
    background: #f1f3f5;
    text-align: center;
    text-transform: uppercase;
    font-size: 10px;
}
.modal-table td input[type="number"] {
    width: 100%;
    font-size: 11px;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: 3px;
    text-align: right;
}
.modal-table td select {
    width: 100%;
    font-size: 11px;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: 3px;
}
.modal-muted {
    color: #777;
    font-size: 10px;
}

.uom-detail-btn {
    width: 100%;
    min-height: 26px;
    border: 1px solid var(--border);
    background: #fff;
    border-radius: 3px;
    font-size: 11px;
    text-align: left;
    padding: 4px 6px;
    cursor: pointer;
}
.uom-detail-btn.empty {
    color: #777;
}
.tolerance-warning-row {
    background: #fff3cd !important;
}

</style>

<div class="shipping-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <form method="POST" action="index.php?page=save_shipping" id="formShipping">
        <div class="panel-row">
            <!-- PANEL 1: Shipping Information -->
            <div class="shipping-panel">
                <div class="shipping-panel-header"><i class="fa-solid fa-truck"></i> Shipping Information</div>
                <div class="shipping-panel-body">
                    <div class="ff">
                        <label>Shipping No <span class="required">*</span></label>
                        <input type="text" name="shipping_no" id="shipping_no" value="" required autocomplete="off" style="font-weight:bold; color:var(--accent-blue); text-transform:uppercase;">
                        <div id="shipping_no_warning" class="warning-text"><i class="fa fa-triangle-exclamation"></i> Shipping No sudah ada di database.</div>
                        <div id="shipping_no_ok" class="success-text"><i class="fa fa-check-circle"></i> Shipping No belum digunakan.</div>
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
                            <?php while ($o = mysqli_fetch_assoc($order_rs)): ?>
                                <option value="<?= htmlspecialchars($o['order_no']) ?>"
                                        data-order-date="<?= htmlspecialchars($o['order_date']) ?>"
                                        data-order-date-display="<?= htmlspecialchars(formatDateIndonesian($o['order_date'])) ?>"
                                        data-customer-id="<?= htmlspecialchars($o['customer_id']) ?>"
                                        data-customer-name="<?= htmlspecialchars($o['customer_name']) ?>"
                                        data-customer-address="<?= htmlspecialchars($o['customer_address']) ?>"
                                        data-customer-city="<?= htmlspecialchars($o['customer_city']) ?>"
                                        data-tolerance="<?= htmlspecialchars($o['tolerance'] ?? '10.00') ?>">
                                        <?= htmlspecialchars($o['order_no']) ?> - <?= htmlspecialchars(formatDateIndonesian($o['order_date'])) ?> - <?= htmlspecialchars($o['customer_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Order Date</label>
                        <input type="text" id="order_date_display" readonly placeholder="Akan tampil setelah Order No dipilih">
                        <input type="hidden" name="order_date" id="order_date">
                    </div>
                    <div class="ff">
                        <label>Gudang <span class="required">*</span></label>
                        <select name="gudang_id" id="gudang_id" required>
                            <option value="">-- Pilih Gudang --</option>
                            <?php while ($g = mysqli_fetch_assoc($gudang_rs)): 
                                $selected = ($g['gudang_id'] == $default_gudang_id) ? 'selected' : '';
                            ?>
                                <option value="<?= htmlspecialchars($g['gudang_id']) ?>" <?= $selected ?>>
                                    <?= htmlspecialchars($g['name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Remarks Shipping</label>
                        <textarea name="remarks_shipping" rows="1" placeholder="Catatan pengiriman..."></textarea>
                    </div>
                </div>
            </div>

            <!-- PANEL 3: Customer Information -->
            <div class="shipping-panel">
                <div class="shipping-panel-header"><i class="fa-solid fa-building-user"></i> Customer Information</div>
                <div class="shipping-panel-body">
                    <div class="ff">
                        <label>Customer ID</label>
                        <input type="text" name="customer_id" id="customer_id" readonly>
                    </div>
                    <div class="ff">
                        <label>Customer Name</label>
                        <input type="text" name="customer_name" id="customer_name" readonly>
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

        <!-- PANEL 4: Inventory Details -->
        <div class="shipping-panel-full">
            <div class="shipping-panel-header">
                <i class="fa-solid fa-boxes-stacked"></i> Inventory Items to Ship
                <span id="row_count_label" style="color:#777; font-size:10px; margin-left:10px; font-weight:normal;">// 0 rows</span>
            </div>

            <div class="detail-toolbar">
                <button type="button" class="btn-vs btn-primary" onclick="addRow()"><i class="fa fa-plus"></i> Tambah Item Manual</button>
                <button type="button" class="btn-vs btn-secondary" onclick="deleteSelected()"><i class="fa fa-trash"></i> Hapus Terpilih</button>
                <button type="button" class="btn-vs btn-warning" onclick="openLoadInventoryModal()"><i class="fa fa-list-check"></i> Load dari Order</button>
                <span style="font-size:10px; color:#888;">
                    <i class="fa fa-info-circle"></i> Load dari Order akan membuka modal pilihan inventory terlebih dahulu.
                </span>
            </div>

            <div class="detail-table-wrap">
                <table class="detail-table" id="detailTable">
                    <thead>
                        <tr>
                            <th style="width:30px;"><input type="checkbox" id="selectAll"></th>
                            <th style="width:35px;">#</th>
                            <th style="width:130px;">Inventory ID</th>
                            <th style="width:220px;">Inventory Name</th>
                            <th style="width:90px;">Qty</th>
                            <th style="width:80px;">UoM</th>
                            <th style="width:90px;">Qty Pack</th>
                            <th style="width:95px;">UoM Pack</th>
                            <th style="width:190px;">UoM Detail</th>
                            <th style="width:150px;">Remarks</th>
                            <th style="width:45px;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody id="detailBody"></tbody>
                </table>
            </div>

            <div class="shipping-footer-row">
                <div style="display:flex; align-items:center; gap:20px;">
                    <span style="font-size:11px; font-weight:bold; color:var(--text-label);">TOTAL ITEMS :</span>
                    <span id="total_items_label" style="font-weight:bold; color:var(--accent-blue); font-size:14px;">0</span>
                </div>
            </div>
        </div>

        <div class="shipping-actionbar">
            <button type="button" class="btn-vs btn-secondary" onclick="window.location.href='index.php?page=shipping'">
                <i class="fa fa-times"></i> Batal / Kembali
            </button>
            <button type="submit" class="btn-vs btn-success" id="btnSave">
                <i class="fa fa-save"></i> Simpan Shipping
            </button>
        </div>
    </form>
</div>

<!-- MODAL: Pilih inventory dari order -->
<div class="shipping-modal-backdrop" id="orderInventoryModal">
    <div class="shipping-modal">
        <div class="shipping-modal-header">
            <span><i class="fa fa-boxes-stacked"></i> Pilih Inventory dari Order <span id="modal_order_no"></span></span>
            <button type="button" class="btn-vs btn-secondary" onclick="closeLoadInventoryModal()"><i class="fa fa-times"></i></button>
        </div>
        <div class="shipping-modal-body">
            <div class="modal-muted" style="margin-bottom:8px;">
Centang inventory yang ingin dimasukkan ke daftar shipping. Setelah ditambahkan, Qty diketik manual di tabel shipping.
            </div>
            <table class="modal-table">
                <thead>
                    <tr>
                        <th style="width:35px;"><input type="checkbox" id="modalSelectAll"></th>
                        <th style="width:125px;">Inventory ID</th>
                        <th>Inventory Name</th>
                        <th style="width:70px;">UoM SO</th>
                        <th style="width:90px;">Qty SO</th>
                        <th style="width:100px;">Qty Pack SO</th>
                        <th style="width:95px;">UoM Pack SO</th>
                        <th style="width:120px;">Tolerance</th>
                    </tr>
                </thead>
                <tbody id="modalInventoryBody">
                    <tr><td colspan="8" style="text-align:center; color:#777;">Belum ada data.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="shipping-modal-footer">
            <button type="button" class="btn-vs btn-secondary" onclick="closeLoadInventoryModal()"><i class="fa fa-times"></i> Tutup</button>
            <button type="button" class="btn-vs btn-success" onclick="addSelectedInventoryFromModal()"><i class="fa fa-plus"></i> Tambahkan Terpilih</button>
        </div>
    </div>
</div>


<!-- MODAL: Pilih multi UoM detail per inventory -->
<div class="shipping-modal-backdrop" id="uomDetailModal">
    <div class="shipping-modal" style="max-width:520px;">
        <div class="shipping-modal-header">
            <span><i class="fa fa-list-check"></i> UoM Detail <span id="uom_detail_modal_inventory"></span></span>
            <button type="button" class="btn-vs btn-secondary" onclick="closeUomDetailModal()"><i class="fa fa-times"></i></button>
        </div>
        <div class="shipping-modal-body">
            <div class="modal-muted" style="margin-bottom:8px;">
                Centang satu atau lebih UoM, lalu isi Qty untuk masing-masing UoM.
            </div>
            <table class="modal-table">
                <thead>
                    <tr>
                        <th style="width:45px;">Pilih</th>
                        <th>UoM</th>
                        <th style="width:140px;">Qty</th>
                    </tr>
                </thead>
                <tbody id="uomDetailModalBody">
                    <tr><td colspan="3" style="text-align:center; color:#777;">Belum ada data.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="shipping-modal-footer">
            <button type="button" class="btn-vs btn-secondary" onclick="closeUomDetailModal()"><i class="fa fa-times"></i> Tutup</button>
            <button type="button" class="btn-vs btn-success" onclick="saveUomDetailModal()"><i class="fa fa-check"></i> Simpan UoM Detail</button>
        </div>
    </div>
</div>

<script>
var inventoryUomData = {};
var rowCounter = 0;
var shippingNoExists = false;
var checkShippingTimer = null;

// Ambil data UoM dari database via AJAX
$.ajax({
    url: 'modul/transaksi/get_inventory_uom.php',
    type: 'GET',
    dataType: 'json',
    async: false,
    success: function(response) {
        if (response.success) {
            inventoryUomData = response.data;
        }
    }
});

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

function escAttr(s) {
    return escHtml(s).replace(/`/g, '&#96;');
}

function formatDecimal(num) {
    var n = parseFloat(num || 0);
    if (isNaN(n)) n = 0;
    return n.toFixed(4);
}

// Ambil nilai konversi (kolom `Value` di m_inventory_uom) untuk 1 unit inventory tertentu.
// Toleran terhadap variasi nama key hasil json_encode PHP (value / Value / conversion_value).
function getUomConversionValue(inventoryId, unit) {
    if (!inventoryId || !unit || !inventoryUomData[inventoryId]) return null;

    // Backend (get_inventory_uom.php) menyimpan unit dalam UPPERCASE (strtoupper),
    // sedangkan uom_pack dari Sales Order bisa datang dengan casing berbeda.
    // Bandingkan dalam bentuk uppercase+trim supaya tidak salah fallback ke 1:1.
    var target = String(unit).trim().toUpperCase();

    var list = inventoryUomData[inventoryId];
    for (var i = 0; i < list.length; i++) {
        var candidate = String(list[i].unit || '').trim().toUpperCase();
        if (candidate === target) {
            var raw = list[i].value;
            if (raw === undefined) raw = list[i].Value;
            if (raw === undefined) raw = list[i].conversion_value;
            var v = parseFloat(raw);
            return isNaN(v) ? null : v;
        }
    }
    return null;
}

function getUomOptions(inventoryId, selectedValue, selectDefault, includePlaceholder) {
    selectedValue = selectedValue || '';
    selectDefault = (selectDefault === true);
    includePlaceholder = (includePlaceholder !== false);

    var html = includePlaceholder ? '<option value="">-- Pilih UoM --</option>' : '';
    var foundSelected = false;
    var hasOption = false;

    if (inventoryId && inventoryUomData[inventoryId]) {
        var uomList = inventoryUomData[inventoryId];

        for (var i = 0; i < uomList.length; i++) {
            var unit = uomList[i].unit || '';
            if (unit === '') continue;

            var isDefault = parseInt(uomList[i].default) === 1;
            var selected = '';

            if (selectedValue !== '') {
                selected = (unit === selectedValue) ? 'selected' : '';
            } else if (selectDefault && isDefault) {
                selected = 'selected';
            }

            if (selected === 'selected') {
                foundSelected = true;
            }

            hasOption = true;
            html += '<option value="' + escAttr(unit) + '" ' + selected + '>' + escHtml(unit) + '</option>';
        }
    }

    // Jika UoM dari SO tidak ada di master m_inventory_uom, tetap tampilkan nilai dari SO.
    if (selectedValue !== '' && !foundSelected) {
        html += '<option value="' + escAttr(selectedValue) + '" selected>' + escHtml(selectedValue) + '</option>';
        foundSelected = true;
        hasOption = true;
    }

    // Fallback agar kolom tidak kosong / tidak menampilkan "-- Pilih UoM --".
    if (!hasOption && !includePlaceholder) {
        var fallbackUnit = selectedValue || 'KG';
        html += '<option value="' + escAttr(fallbackUnit) + '" selected>' + escHtml(fallbackUnit) + '</option>';
    }

    return html;
}

function parseUomDetailJson(value) {
    if (!value) return [];
    try {
        var parsed = JSON.parse(value);
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
        return [];
    }
}

function getUomDetailSummary(value) {
    var arr = parseUomDetailJson(value);
    if (!arr.length) return '-- Pilih UoM Detail --';

    return arr.map(function(item) {
        return (formatDecimal(item.qty || 0)) + ' ' + (item.uom || '');
    }).join(', ');
}


function syncUomDetailLegacyFields($row, jsonValue) {
    var arr = parseUomDetailJson(jsonValue);
    var firstUom = '';
    var totalQty = 0;

    if (arr.length > 0) {
        firstUom = arr[0].uom || '';
        arr.forEach(function(item) {
            totalQty += parseFloat(item.qty) || 0;
        });
    }

    $row.find('.uom-detail-legacy-uom').val(firstUom);
    $row.find('.qty-detail-legacy-qty').val(formatDecimal(totalQty));
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

    var inventoryId = data.inventory_id || '';

    // Kolom UoM utama untuk Shipping selalu KG.
    var uomOptions = '<option value="KG" selected>KG</option>';

    // Kolom UoM Pack mengikuti UoM Pack dari Sales Order, bukan placeholder.
    var soUomPack = data.uom_pack || data.so_uom_pack || 'KG';
    var uomPackOptions = getUomOptions(inventoryId, soUomPack, true, false);

    var uomDetailJson = data.uom_detail_json || data.uom_detail || '';
    var uomDetailSummary = getUomDetailSummary(uomDetailJson);
    var isUomDetailEmpty = parseUomDetailJson(uomDetailJson).length === 0;

    return '<tr class="detail-row" data-idx="' + idx + '" data-order-qty="' + escAttr(data.order_qty || 0) + '" data-order-qty-pack="' + escAttr(data.order_qty_pack || 0) + '">' +
        '<td style="text-align:center;"><input type="checkbox" class="rowCheckbox"></td>' +
        '<td class="ln-cell" style="text-align:center; font-weight:bold; color:#888;"></td>' +
        '<td>' +
            '<input type="text" name="inventory_id[]" class="inv-id" value="' + escAttr(data.inventory_id || '') + '" readonly style="background:#f1f3f5; font-weight:bold; text-align:center;">' +
            '<input type="hidden" name="inventory_name[]" class="inv-name-hidden" value="' + escAttr(data.inventory_name || '') + '">' +
        '</td>' +
        '<td><input type="text" class="inv-name-display" value="' + escAttr(data.inventory_name || '') + '" readonly style="background:#f1f3f5;"></td>' +
        '<td><input type="number" step="0.0001" name="qty_shipping[]" class="qty-shipping" value="' + formatDecimal(data.qty || 0) + '" style="text-align:right;"></td>' +
        '<td><select name="uom_shipping[]" class="inv-uom-select" style="width:100%;">' + uomOptions + '</select></td>' +
        '<td><input type="number" step="0.0001" name="qty_pack_shipping[]" class="qty-pack-shipping" value="' + formatDecimal(data.qty_pack || 0) + '" style="text-align:right;"></td>' +
        '<td><select name="uom_pack_shipping[]" class="inv-uom-pack-select" style="width:100%;">' + uomPackOptions + '</select></td>' +
        '<td>' +
            '<button type="button" class="uom-detail-btn ' + (isUomDetailEmpty ? 'empty' : '') + '" onclick="openUomDetailModal(this)">' + escHtml(uomDetailSummary) + '</button>' +
            '<input type="hidden" name="uom_detail_shipping[]" class="uom-detail-legacy-uom" value="">' +
            '<input type="hidden" name="qty_detail_shipping[]" class="qty-detail-legacy-qty" value="0">' +
            '<input type="hidden" name="uom_detail_shipping_json[]" class="uom-detail-json" value="' + escAttr(uomDetailJson) + '">' +
        '</td>' +
        '<td><input type="text" name="remarks_inventory_shipping[]" class="inv-remarks" value="' + escAttr(data.remarks || '') + '" placeholder="Catatan..."></td>' +
        '<td style="text-align:center;"><button type="button" class="btn-vs btn-danger" onclick="removeRow(this)"><i class="fa fa-trash"></i></button></td>' +
    '</tr>';
}

function addRow(data) {
    var $newRow = $(buildRow(data || {}));
    $('#detailBody').append($newRow);
    syncUomDetailLegacyFields($newRow, $newRow.find('.uom-detail-json').val());
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
        $('#selectAll').prop('checked', false);
        updateRowNumbers();
    }
}

function getSelectedOrderTolerance() {
    var opt = document.getElementById('order_no').selectedOptions[0];
    var tolerance = opt ? parseFloat(opt.getAttribute('data-tolerance')) : 10;
    if (isNaN(tolerance)) tolerance = 10;
    return tolerance;
}

function openLoadInventoryModal() {
    var orderNo = $('#order_no').val();
    if (!orderNo) {
        alert('Pilih Order No terlebih dahulu!');
        return;
    }

    $('#modal_order_no').text(' - ' + orderNo);
    $('#modalInventoryBody').html('<tr><td colspan="8" style="text-align:center; color:#777;">Loading data inventory...</td></tr>');
    $('#modalSelectAll').prop('checked', false);
    $('#orderInventoryModal').css('display', 'flex');

    $.ajax({
        url: 'modul/transaksi/get_order_inventory.php',
        type: 'POST',
        data: { order_no: orderNo },
        dataType: 'json',
        success: function(response) {
            if (response.success && response.data.length > 0) {
                renderModalInventoryRows(response.data);
            } else {
                $('#modalInventoryBody').html('<tr><td colspan="8" style="text-align:center; color:#777;">Tidak ada inventory ditemukan untuk order ini.</td></tr>');
            }
        },
        error: function() {
            $('#modalInventoryBody').html('<tr><td colspan="8" style="text-align:center; color:#dc3545;">Gagal mengambil data inventory. Silakan coba lagi.</td></tr>');
        }
    });
}

function closeLoadInventoryModal() {
    $('#orderInventoryModal').hide();
}

function renderModalInventoryRows(items) {
    var html = '';
    var tolerance = getSelectedOrderTolerance();

    items.forEach(function(item, i) {
        var inventoryId = item.inventory_id || '';
        var qty = parseFloat(item.quantity) || 0;
        var qtyPack = parseFloat(item.quantity_pack) || 0;
        var uomPack = item.uom_pack || '';

        html += '<tr class="modal-inventory-row" ' +
            'data-inventory-id="' + escAttr(inventoryId) + '" ' +
            'data-inventory-name="' + escAttr(item.inventory_name || '') + '" ' +
            'data-order-qty="' + escAttr(qty) + '" ' +
            'data-order-qty-pack="' + escAttr(qtyPack) + '" ' +
            'data-uom="' + escAttr(item.uom || 'KG') + '" ' +
            'data-uom-pack="' + escAttr(uomPack || 'KG') + '" ' +
            'data-remarks="' + escAttr(item.remarks || '') + '">' +
            '<td style="text-align:center;"><input type="checkbox" class="modalRowCheckbox"></td>' +
            '<td style="font-weight:bold; text-align:center;">' + escHtml(inventoryId) + '</td>' +
            '<td>' + escHtml(item.inventory_name || '') + '</td>' +
            '<td style="text-align:center;">' + escHtml(item.uom || '') + '</td>' +
            '<td style="text-align:right; background:#f8f9fa;">' + formatDecimal(qty) + '</td>' +
            '<td style="text-align:right; background:#f8f9fa;">' + formatDecimal(qtyPack) + '</td>' +
            '<td style="text-align:center; background:#f8f9fa;">' + escHtml(uomPack) + '</td>' +
            '<td style="text-align:center; background:#fff3cd;">' + formatDecimal(tolerance) + '%</td>' +
        '</tr>';
    });

    $('#modalInventoryBody').html(html);
}

function addSelectedInventoryFromModal() {
    var $checked = $('#modalInventoryBody .modalRowCheckbox:checked');

    if (!$checked.length) {
        alert('Pilih minimal 1 inventory terlebih dahulu!');
        return;
    }

    $checked.each(function() {
        var $r = $(this).closest('.modal-inventory-row');
        addRow({
            inventory_id: $r.data('inventory-id') || '',
            inventory_name: $r.data('inventory-name') || '',
            qty: 0,
            uom: 'KG',
            qty_pack: 0,
            uom_pack: $r.attr('data-uom-pack') || 'KG',
            uom_detail_json: '',
            order_qty: parseFloat($r.attr('data-order-qty')) || 0,
            order_qty_pack: parseFloat($r.attr('data-order-qty-pack')) || 0,
            remarks: $r.data('remarks') || ''
        });
    });

    showNotification('Berhasil menambahkan ' + $checked.length + ' item. Qty silakan diisi manual.', 'success');
    closeLoadInventoryModal();
}

var activeUomDetailRow = null;

function openUomDetailModal(btn) {
    activeUomDetailRow = $(btn).closest('.detail-row');
    var inventoryId = activeUomDetailRow.find('.inv-id').val();
    var current = parseUomDetailJson(activeUomDetailRow.find('.uom-detail-json').val());
    var currentMap = {};

    current.forEach(function(item) {
        if (item.uom) currentMap[item.uom] = parseFloat(item.qty) || 0;
    });

    $('#uom_detail_modal_inventory').text(' - ' + inventoryId);

    if (!inventoryId || !inventoryUomData[inventoryId] || inventoryUomData[inventoryId].length === 0) {
        $('#uomDetailModalBody').html('<tr><td colspan="3" style="text-align:center; color:#777;">UoM inventory tidak ditemukan.</td></tr>');
    } else {
        var html = '';
        inventoryUomData[inventoryId].forEach(function(item) {
            var unit = item.unit || '';
            var checked = Object.prototype.hasOwnProperty.call(currentMap, unit);
            var qty = checked ? currentMap[unit] : 0;

            html += '<tr>' +
                '<td style="text-align:center;"><input type="checkbox" class="uom-detail-check" value="' + escAttr(unit) + '" ' + (checked ? 'checked' : '') + '></td>' +
                '<td style="font-weight:bold;">' + escHtml(unit) + '</td>' +
                '<td><input type="number" step="0.0001" class="uom-detail-qty" value="' + formatDecimal(qty) + '" style="text-align:right;"></td>' +
            '</tr>';
        });
        $('#uomDetailModalBody').html(html);
    }

    $('#uomDetailModal').css('display', 'flex');
}

function closeUomDetailModal() {
    $('#uomDetailModal').hide();
    activeUomDetailRow = null;
}

function saveUomDetailModal() {
    if (!activeUomDetailRow) {
        closeUomDetailModal();
        return;
    }

    var details = [];
    var invalid = false;

    $('#uomDetailModalBody tr').each(function() {
        var $check = $(this).find('.uom-detail-check');
        if (!$check.length || !$check.is(':checked')) return;

        var uom = $check.val();
        var qty = parseFloat($(this).find('.uom-detail-qty').val()) || 0;

        if (qty <= 0) {
            alert('Qty UoM Detail untuk ' + uom + ' harus lebih dari 0.');
            invalid = true;
            return false;
        }

        details.push({ uom: uom, qty: qty });
    });

    if (invalid) return;

    var json = JSON.stringify(details);
    activeUomDetailRow.find('.uom-detail-json').val(json);
    syncUomDetailLegacyFields(activeUomDetailRow, json);

    var summary = getUomDetailSummary(json);
    activeUomDetailRow.find('.uom-detail-btn')
        .text(summary)
        .toggleClass('empty', details.length === 0);

    closeUomDetailModal();
}

function validateToleranceBeforeSubmit() {
    var tolerance = getSelectedOrderTolerance();
    var factor = 1 + (tolerance / 100);
    var totals = {};
    var names = {};
    var valid = true;
    var message = '';

    $('#detailBody .detail-row').removeClass('tolerance-warning-row');

    $('#detailBody .detail-row').each(function() {
        var $row = $(this);
        var invId = $.trim($row.find('.inv-id').val());
        if (!invId) return;

        if (!totals[invId]) {
            totals[invId] = {
                qty: 0,
                qtyPack: 0,
                orderQty: parseFloat($row.attr('data-order-qty')) || 0,
                orderQtyPack: parseFloat($row.attr('data-order-qty-pack')) || 0,
                row: $row
            };
            names[invId] = $row.find('.inv-name-display').val() || '';
        }

        totals[invId].qty += parseFloat($row.find('.qty-shipping').val()) || 0;
        totals[invId].qtyPack += parseFloat($row.find('.qty-pack-shipping').val()) || 0;
    });

    Object.keys(totals).forEach(function(invId) {
        var t = totals[invId];
        var maxQty = t.orderQty * factor;
        var maxQtyPack = t.orderQtyPack * factor;

        if (t.orderQty > 0 && t.qty > maxQty + 0.00001) {
            valid = false;
            t.row.addClass('tolerance-warning-row');
            message += invId + ' - ' + names[invId] + ': Qty kirim ' + formatDecimal(t.qty) + ' melebihi batas ' + formatDecimal(maxQty) + ' (Order ' + formatDecimal(t.orderQty) + ' + tolerance ' + formatDecimal(tolerance) + '%).\n';
        }

        if (t.orderQtyPack > 0 && t.qtyPack > maxQtyPack + 0.00001) {
            valid = false;
            t.row.addClass('tolerance-warning-row');
            message += invId + ' - ' + names[invId] + ': Qty Pack kirim ' + formatDecimal(t.qtyPack) + ' melebihi batas ' + formatDecimal(maxQtyPack) + ' (Order ' + formatDecimal(t.orderQtyPack) + ' + tolerance ' + formatDecimal(tolerance) + '%).\n';
        }
    });

    if (!valid) {
        alert('Gagal Simpan: jumlah kirim melebihi tolerance order.\n\n' + message);
    }

    return valid;
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

function checkShippingNo() {
    var shippingNo = $.trim($('#shipping_no').val());

    $('#shipping_no_warning, #shipping_no_ok').hide();
    shippingNoExists = false;

    if (shippingNo === '') {
        return;
    }

    $.ajax({
        url: 'index.php?page=add_shipping&ajax_check_shipping_no=1',
        type: 'GET',
        data: { shipping_no: shippingNo },
        dataType: 'json',
        success: function(response) {
            shippingNoExists = !!response.exists;
            if (shippingNoExists) {
                $('#shipping_no_warning').show();
                $('#shipping_no_ok').hide();
            } else {
                $('#shipping_no_warning').hide();
                $('#shipping_no_ok').show();
            }
        },
        error: function() {
            shippingNoExists = false;
        }
    });
}

function convertIndoDateToSql($el) {
    var dateValue = $el.val();
    if (!dateValue) return;

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
            $el.val(dateParts[2] + '-' + monthNum + '-' + dateParts[0]);
        }
    }
}

$(document).on('change', '#order_no', function() {
    var opt = this.options[this.selectedIndex];
    if (!opt || this.value === '') {
        $('#order_date, #order_date_display, #customer_id, #customer_name, #customer_address, #customer_city').val('');
        $('#detailBody').empty();
        rowCounter = 0;
        updateRowNumbers();
        return;
    }

    $('#order_date').val(opt.getAttribute('data-order-date') || '');
    $('#order_date_display').val(
        opt.getAttribute('data-order-date-display') ||
        opt.getAttribute('data-order-date') ||
        ''
    );
    $('#customer_id').val(opt.getAttribute('data-customer-id') || '');
    $('#customer_name').val(opt.getAttribute('data-customer-name') || '');
    $('#customer_address').val(opt.getAttribute('data-customer-address') || '');
    $('#customer_city').val(opt.getAttribute('data-customer-city') || '');

    $('#detailBody').empty();
    rowCounter = 0;
    updateRowNumbers();
});

$(document).ready(function() {
    flatpickr('.datepicker', {
        dateFormat: 'd-M-Y',
        altFormat: 'd-M-Y',
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

    $('#modalSelectAll').on('change', function() {
        $('#modalInventoryBody .modalRowCheckbox').prop('checked', this.checked);
    });

    $('#shipping_no').on('input blur', function() {
        var upper = $(this).val().toUpperCase();
        $(this).val(upper);

        clearTimeout(checkShippingTimer);
        checkShippingTimer = setTimeout(checkShippingNo, 350);
    });

    $('#orderInventoryModal').on('click', function(e) {
        if (e.target === this) {
            closeLoadInventoryModal();
        }
    });

    $('#uomDetailModal').on('click', function(e) {
        if (e.target === this) {
            closeUomDetailModal();
        }
    });

    $('#formShipping').on('submit', function(e) {
        var shippingNo = $.trim($('#shipping_no').val());

        if (shippingNo === '') {
            e.preventDefault();
            alert('Shipping No wajib diisi!');
            $('#shipping_no').focus();
            return false;
        }

        if (shippingNoExists) {
            e.preventDefault();
            alert('Gagal Simpan: Shipping No sudah ada di database. Silakan gunakan nomor lain.');
            $('#shipping_no').focus();
            return false;
        }

        if ($('#detailBody .detail-row').length === 0) {
            e.preventDefault();
            alert('Gagal Simpan: Item inventory tidak boleh kosong!');
            return false;
        }

        var validRows = true;
        $('#detailBody .detail-row').each(function(i) {
            var invId = $.trim($(this).find('.inv-id').val());
            var qty = parseFloat($(this).find('.qty-shipping').val()) || 0;
            var qtyPack = parseFloat($(this).find('.qty-pack-shipping').val()) || 0;
            var uom = $(this).find('.inv-uom-select').val();
            var uomPack = $(this).find('.inv-uom-pack-select').val();
            var uomDetailJson = $(this).find('.uom-detail-json').val();
            var uomDetailArr = parseUomDetailJson(uomDetailJson);

            if (invId === '') {
                alert('Baris ' + (i + 1) + ': Inventory ID kosong. Gunakan Load dari Order atau lengkapi data manual.');
                validRows = false;
                return false;
            }
            if (qty <= 0 && qtyPack <= 0) {
                alert('Baris ' + (i + 1) + ': Qty atau Qty Pack harus lebih dari 0.');
                validRows = false;
                return false;
            }
            if (uom === '') {
                alert('Baris ' + (i + 1) + ': UoM wajib dipilih.');
                validRows = false;
                return false;
            }
            if (uomPack === '') {
                alert('Baris ' + (i + 1) + ': UoM Pack wajib dipilih.');
                validRows = false;
                return false;
            }
            if (uomDetailArr.length === 0) {
                alert('Baris ' + (i + 1) + ': UoM Detail wajib dipilih minimal 1.');
                validRows = false;
                return false;
            }
        });

        if (!validRows) {
            e.preventDefault();
            return false;
        }

        if (!validateToleranceBeforeSubmit()) {
            e.preventDefault();
            return false;
        }

        $('input[name="shipping_date"], input[name="nota_date"]').each(function() {
            convertIndoDateToSql($(this));
        });

        $('#btnSave').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
        return true;
    });
});
</script>