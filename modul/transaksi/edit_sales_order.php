<?php
// modul/transaksi/edit_sales_order.php

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

$order_no = mysqli_real_escape_string($conn, $_GET['id'] ?? '');
if (empty($order_no)) {
    echo "<script>window.location.href='index.php?page=sales_order';</script>";
    exit;
}

// Ambil data header
$q_head = mysqli_query($conn, "SELECT h.*, m.marketing_name, s.sales_name
    FROM head_sales_order h
    LEFT JOIN m_marketing m ON h.marketing_id = m.marketing_id
    LEFT JOIN m_sales s ON h.sales_id = s.sales_id
    WHERE h.order_no = '$order_no' LIMIT 1");

if (!$q_head || mysqli_num_rows($q_head) == 0) {
    echo "<script>alert('Sales Order tidak ditemukan!'); window.location.href='index.php?page=sales_order';</script>";
    exit;
}
$head = mysqli_fetch_assoc($q_head);

// Ambil data detail
$q_det = mysqli_query($conn, "SELECT * FROM detail_sales_order WHERE order_no = '$order_no' ORDER BY id ASC");
$details = [];
while ($d = mysqli_fetch_assoc($q_det)) $details[] = $d;

// Dropdowns
$marketing_rs = mysqli_query($conn, "SELECT marketing_id, marketing_name FROM m_marketing WHERE is_active='Checked' ORDER BY marketing_name ASC");
$sales_rs     = mysqli_query($conn, "SELECT sales_id, sales_name FROM m_sales WHERE is_active='Checked' ORDER BY sales_name ASC");
$customer_rs  = mysqli_query($conn, "SELECT customer_id, customer, address, city FROM m_customer WHERE is_active='Checked' ORDER BY customer ASC");
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<link  href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/css/select2.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.1.0-rc.0/js/select2.min.js"></script>

<style>
:root {
    --bg-base     : #f0f2f5;
    --bg-panel    : #ffffff;
    --bg-panel2   : #f8f9fa;
    --bg-input    : #ffffff;
    --bg-input-ro : #e9ecef;
    --border      : #dee2e6;
    --border-focus: #0d6efd;
    --text-primary: #212529;
    --text-label  : #0d6efd;
    --text-muted  : #6c757d;
    --text-value  : #212529;
    --accent-blue : #0d6efd;
    --accent-green: #198754;
    --accent-red  : #dc3545;
    --tab-active  : #ffffff;
    --tab-inactive: #e9ecef;
    --scrollbar   : #ced4da;
}
.so-wrap * { box-sizing: border-box; font-family: 'Consolas','Cascadia Code','Courier New',monospace; }
.so-wrap { background: var(--bg-base); min-height: 100vh; padding: 0; color: var(--text-primary); }

.so-titlebar { background: #dee2e6; border-bottom: 1px solid #ccc; padding: 6px 14px; display: flex; align-items: center; gap: 10px; font-size: 11px; color: #495057; user-select: none; }
.so-titlebar .dot { width: 12px; height: 12px; border-radius: 50%; }
.so-titlebar .dot.r { background: #ff5f56; }
.so-titlebar .dot.y { background: #ffbd2e; }
.so-titlebar .dot.g { background: #27c93f; }
.so-titlebar .file-tab { background: var(--tab-active); border-top: 2px solid #ffc107; padding: 4px 14px; font-size: 11px; border-right: 1px solid #ccc; color: #333; }
.so-titlebar .file-tab-inactive { background: var(--tab-inactive); padding: 4px 14px; font-size: 11px; border-right: 1px solid #ccc; color: #6c757d; }

.so-body { padding: 12px; }
.panel-row { display: flex; gap: 8px; margin-bottom: 8px; }
.so-panel { flex: 1; background: var(--bg-panel); border: 1px solid var(--border); border-radius: 3px; overflow: hidden; min-width: 0; }
.so-panel-header { background: #fff3cd; border-bottom: 1px solid #ffc107; padding: 5px 10px; font-size: 10px; font-weight: bold; color: #664d03; display: flex; align-items: center; gap: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
.so-panel-header i { color: #ffc107; font-size: 11px; }
.so-panel-body { padding: 10px; }

.ff { margin-bottom: 8px; }
.ff label { display: block; font-size: 9px; font-weight: 600; color: var(--text-label); margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.3px; }
.ff label .comment { color: var(--text-muted); font-weight: normal; }
.ff input[type=text], .ff input[type=date], .ff input[type=number], .ff select, .ff textarea {
    width: 100%; background: var(--bg-input); border: 1px solid var(--border); border-radius: 2px;
    color: var(--text-value); font-size: 11px; padding: 5px 8px; outline: none;
    transition: border-color 0.15s; font-family: 'Consolas','Cascadia Code','Courier New',monospace;
}
.ff input:focus, .ff select:focus, .ff textarea:focus { border-color: var(--border-focus); box-shadow: 0 0 0 1px rgba(13,110,253,0.2); }
.ff input[readonly], .ff textarea[readonly] { background: var(--bg-input-ro); color: #888; cursor: default; }
.ff select option { background: #ffffff; color: #212529; }
.ff .checkbox-row { display: flex; align-items: center; gap: 8px; padding: 5px 0; }
.ff .checkbox-row input[type=checkbox] { width: 14px; height: 14px; accent-color: var(--accent-blue); }
.ff .checkbox-row span { font-size: 11px; color: var(--text-primary); }

.so-panel-full { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 3px; overflow: hidden; margin-bottom: 8px; }
.detail-toolbar { background: #f8f9fa; border-bottom: 1px solid var(--border); padding: 6px 10px; display: flex; align-items: center; gap: 6px; }

.btn-vs { padding: 5px 12px; font-size: 10px; font-weight: 600; border: none; border-radius: 2px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.15s; font-family: 'Consolas','Cascadia Code','Courier New',monospace; }
.btn-vs:hover { filter: brightness(1.1); transform: translateY(-1px); }
.btn-primary   { background: var(--accent-blue); color: #fff; }
.btn-warning   { background: #ffc107; color: #212529; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-danger    { background: transparent; color: var(--accent-red); border: 1px solid var(--accent-red); padding: 3px 7px; font-size: 10px; }
.btn-danger:hover { background: var(--accent-red); color: #fff; }

.detail-table-wrap { max-height: 360px; overflow-y: auto; overflow-x: auto; }
.detail-table-wrap::-webkit-scrollbar { width: 7px; height: 7px; }
.detail-table-wrap::-webkit-scrollbar-thumb { background: var(--scrollbar); border-radius: 2px; }
.detail-table { width: 100%; border-collapse: collapse; font-size: 10px; min-width: 1200px; }
.detail-table th { background: #fff3cd; color: #664d03; padding: 7px 5px; border: 1px solid #ffc107; text-align: center; white-space: nowrap; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; position: sticky; top: 0; z-index: 2; }
.detail-table td { padding: 2px 3px; border: 1px solid var(--border); background: var(--bg-panel2); vertical-align: middle; }
.detail-table tr:hover td { background: #fffbea; }
.detail-table input[type=text], .detail-table input[type=number] { width: 100%; background: transparent; border: none; color: var(--text-value); font-size: 10px; padding: 3px 4px; outline: none; font-family: 'Consolas','Cascadia Code','Courier New',monospace; }
.detail-table input:focus { background: rgba(255,193,7,0.1); border-radius: 2px; }
.detail-table input[readonly] { color: var(--accent-green); cursor: default; }
.detail-table tfoot td { background: #fff3cd; color: #664d03; padding: 5px 8px; font-size: 10px; font-weight: bold; border: 1px solid #ffc107; }

/* Select2 inventory di tabel */
.inv-select2-wrap { min-width: 220px; }
.inv-select2-wrap .select2-container { width: 100% !important; }
.inv-select2-wrap .select2-container--default .select2-selection--single { background: transparent; border: none; border-radius: 0; height: 24px; }
.inv-select2-wrap .select2-container--default .select2-selection--single .select2-selection__rendered { color: #212529; line-height: 22px; padding-left: 4px; font-size: 10px; font-family: 'Consolas','Cascadia Code','Courier New',monospace; }
.inv-select2-wrap .select2-container--default .select2-selection--single .select2-selection__arrow { height: 22px; }
.inv-select2-wrap .select2-container--default.select2-container--focus .select2-selection--single { background: rgba(255,193,7,0.1); border: none; }

.inv-dropdown .select2-results__option { font-size: 10px; padding: 5px 8px; }
.inv-dropdown .select2-results__option .inv-id   { font-weight: bold; color: #0d6efd; margin-right: 6px; }
.inv-dropdown .select2-results__option .inv-name { color: #212529; }
.inv-dropdown .select2-results__option .inv-meta { color: #6c757d; font-size: 9px; margin-top: 1px; }
.inv-dropdown .select2-results__option--highlighted { background: #0d6efd !important; }
.inv-dropdown .select2-results__option--highlighted .inv-id,
.inv-dropdown .select2-results__option--highlighted .inv-name,
.inv-dropdown .select2-results__option--highlighted .inv-meta { color: #fff !important; }
.inv-dropdown .select2-search__field { font-size: 10px; padding: 4px 8px; border: 1px solid #dee2e6; }
.inv-dropdown { border: 1px solid #dee2e6; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

/* Select2 panel */
.select2-container--default .select2-selection--single { background: #fff; border: 1px solid #dee2e6; border-radius: 2px; height: 30px; }
.select2-container--default .select2-selection--single .select2-selection__rendered { color: #212529; line-height: 28px; padding-left: 8px; font-size: 11px; font-family: 'Consolas','Cascadia Code','Courier New',monospace; }
.select2-container--default .select2-selection--single .select2-selection__arrow { height: 28px; }
.select2-container--default .select2-results__option { font-size: 11px; color: #212529; padding: 5px 10px; }
.select2-container--default .select2-results__option--highlighted[aria-selected] { background: #0d6efd; color: #fff; }
.select2-search--dropdown .select2-search__field { border: 1px solid #dee2e6; font-size: 11px; padding: 4px 8px; }
.select2-dropdown { border: 1px solid #dee2e6; border-radius: 2px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.select2-container { width: 100% !important; }

.so-footer-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; background: #f8f9fa; border-top: 1px solid var(--border); gap: 12px; }
.dp-block { display: flex; align-items: center; gap: 8px; font-size: 10px; color: var(--text-label); }
.dp-block input { background: var(--bg-input); border: 1px solid var(--border); color: var(--text-value); padding: 4px 8px; font-size: 11px; width: 130px; border-radius: 2px; font-family: 'Consolas',monospace; }
.dp-block input:focus { border-color: var(--border-focus); outline: none; }
.totals-block { display: flex; gap: 20px; align-items: center; }
.total-item { text-align: right; }
.total-item .lbl { font-size: 9px; color: #888; text-transform: uppercase; }
.total-item .val { font-size: 14px; font-weight: bold; color: var(--accent-green); }
.total-item .val.grand { font-size: 16px; color: #0d6efd; }
.so-actionbar { display: flex; justify-content: flex-end; gap: 8px; padding: 8px 0 0; }
.so-statusbar { background: #ffc107; color: #212529; font-size: 10px; padding: 3px 12px; display: flex; gap: 20px; margin-top: 8px; border-radius: 0 0 3px 3px; }
.so-statusbar span { opacity: 0.85; }
.so-statusbar .sep { opacity: 0.4; }
</style>

<div class="so-wrap">
    <!-- Title Bar -->
    <div class="so-titlebar">
        <div class="dot r"></div><div class="dot y"></div><div class="dot g"></div>
        <div style="flex:1; display:flex; gap:0; margin-left:8px;">
            <div class="file-tab"><i class="fa fa-edit" style="margin-right:5px;"></i>edit_sales_order.php</div>
            <div class="file-tab-inactive"><i class="fa fa-database" style="margin-right:5px;"></i><?= htmlspecialchars($order_no) ?></div>
        </div>
        <span style="color:#888; margin-left:auto;">UTF-8 &nbsp;|&nbsp; EDIT MODE &nbsp;|&nbsp; <?= date('d/m/Y H:i') ?></span>
    </div>

    <div class="so-body">
    <form method="POST" action="index.php?page=update_sales_order" id="formEditSO">
        <input type="hidden" name="order_no" value="<?= htmlspecialchars($order_no) ?>">

        <!-- 3 PANEL ROW -->
        <div class="panel-row">

            <!-- PANEL 1: Order Information -->
            <div class="so-panel">
                <div class="so-panel-header"><i class="fa fa-info-circle"></i> Order Information <span style="color:#aaa;font-size:9px;margin-left:auto;">// panel 1</span></div>
                <div class="so-panel-body">
                    <div class="ff">
                        <label>Order No <span class="comment">// readonly</span></label>
                        <input type="text" value="<?= htmlspecialchars($head['order_no']) ?>" readonly>
                    </div>
                    <div class="ff">
                        <label>Order Date</label>
                        <input type="date" name="order_date" value="<?= htmlspecialchars($head['order_date']) ?>">
                    </div>
                    <!--<div class="ff">
                        <label>PO Number <span class="comment">// readonly</span></label>
                        <input type="text" value="<?= htmlspecialchars($head['po']) ?>" readonly>
                        <input type="hidden" name="po" value="<?= htmlspecialchars($head['po']) ?>">
                    </div>
                    <div class="ff">
                        <label>SOP <span class="comment">// readonly</span></label>
                        <input type="text" value="<?= htmlspecialchars($head['sop']) ?>" readonly>
                        <input type="hidden" name="sop" value="<?= htmlspecialchars($head['sop']) ?>">
                    </div>
                    <div class="ff">
                        <label>SOP Date</label>
                        <input type="date" name="sop_date" value="<?= htmlspecialchars($head['sop_date']) ?>">
                    </div>-->
                    <div class="ff">
                        <label>Marketing</label>
                        <select name="marketing_id" id="marketing_id">
                            <option value="">-- Pilih --</option>
                            <?php while ($m = mysqli_fetch_assoc($marketing_rs)): ?>
                            <option value="<?= htmlspecialchars($m['marketing_id']) ?>"
                                <?= $m['marketing_id'] == $head['marketing_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['marketing_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Sales</label>
                        <select name="sales_id" id="sales_id">
                            <option value="">-- Pilih --</option>
                            <?php while ($s = mysqli_fetch_assoc($sales_rs)): ?>
                            <option value="<?= htmlspecialchars($s['sales_id']) ?>"
                                <?= $s['sales_id'] == $head['sales_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['sales_name']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- PANEL 2: Customer Information -->
            <div class="so-panel">
                <div class="so-panel-header"><i class="fa fa-user"></i> Customer Information <span style="color:#aaa;font-size:9px;margin-left:auto;">// panel 2</span></div>
                <div class="so-panel-body">
                    <div class="ff">
                        <label>Customer Name</label>
                        <select name="customer_id" id="customer_id">
                            <option value="">-- Pilih --</option>
                            <?php while ($c = mysqli_fetch_assoc($customer_rs)): ?>
                            <option value="<?= htmlspecialchars($c['customer_id']) ?>"
                                    data-address="<?= htmlspecialchars($c['address'] ?? '') ?>"
                                    data-city="<?= htmlspecialchars($c['city'] ?? '') ?>"
                                    <?= $c['customer_id'] == $head['customer_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['customer']) ?>
                            </option>
                            <?php endwhile; ?>
                        </select>
                        <input type="hidden" name="customer_name" id="customer_name_hidden" value="<?= htmlspecialchars($head['customer_name']) ?>">
                    </div>
                    <div class="ff">
                        <label>Customer Address</label>
                        <textarea name="customer_address" id="customer_address" rows="2"><?= htmlspecialchars($head['customer_address'] ?? '') ?></textarea>
                    </div>
                    <div class="ff">
                        <label>Customer City</label>
                        <input type="text" name="customer_city" id="customer_city" value="<?= htmlspecialchars($head['customer_city'] ?? '') ?>">
                    </div>
                    <div class="ff">
                        <label>Station</label>
                        <input type="text" name="station" value="<?= htmlspecialchars($head['station'] ?? 'FACTORY') ?>">
                    </div>
                    <div class="ff">
                        <label>Shipment Due Date</label>
                        <input type="date" name="shipment_due_date" value="<?= htmlspecialchars($head['shipment_due_date'] ?? '') ?>">
                    </div>
                    <div class="ff">
                        <label>Shipment Location</label>
                        <textarea name="shipment_location" id="shipment_location" rows="1"><?= htmlspecialchars($head['shipment_location'] ?? '') ?></textarea>
                    </div>
                    <div class="ff">
                        <label>Tolerance (%)</label>
                        <input type="number" step="0.01" name="tolerance" value="<?= htmlspecialchars($head['tolerance'] ?? '10') ?>">
                    </div>
                    <!--<div class="ff">
                        <label>Backward Calculation</label>
                        <div class="checkbox-row">
                            <input type="checkbox" name="backward_calculation" value="Checked"
                                <?= ($head['backward_calculation'] ?? '') == 'Checked' ? 'checked' : '' ?>>
                            <span>Enable backward calculation</span>
                        </div>
                    </div>-->
                    <div class="ff">
                        <label>Payment Term</label>
                        <select name="payment_term">
                            <option value="Franco" <?= $head['payment_term'] == 'Franco' ? 'selected' : '' ?>>Franco</option>
                            <option value="Loco"   <?= $head['payment_term'] == 'Loco'   ? 'selected' : '' ?>>Loco</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- PANEL 3: Payment & Currency -->
            <div class="so-panel">
                <div class="so-panel-header"><i class="fa fa-credit-card"></i> Payment &amp; Currency <span style="color:#aaa;font-size:9px;margin-left:auto;">// panel 3</span></div>
                <div class="so-panel-body">
                    <div class="ff">
                        <label>Payment Type</label>
                        <select name="payment_type">
                            <option value="Cash"   <?= $head['payment_type'] == 'Cash'   ? 'selected' : '' ?>>Cash</option>
                            <option value="Credit" <?= $head['payment_type'] == 'Credit' ? 'selected' : '' ?>>Credit</option>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Days</label>
                        <input type="number" name="days" value="<?= htmlspecialchars($head['days'] ?? 30) ?>" min="0">
                    </div>
                    <div class="ff">
                        <label>Currency</label>
                        <select name="currency">
                            <option value="IDR" <?= $head['currency'] == 'IDR' ? 'selected' : '' ?>>IDR — Indonesian Rupiah</option>
                            <option value="USD" <?= $head['currency'] == 'USD' ? 'selected' : '' ?>>USD — US Dollar</option>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Kurs</label>
                        <input type="text" name="kurs" value="<?= htmlspecialchars($head['kurs'] ?? '1.00') ?>" readonly>
                    </div>
                    <!--<div class="ff">
                        <label>Allow Auto Correct</label>
                        <div class="checkbox-row">
                            <input type="checkbox" name="allow_auto_correct" value="Checked"
                                <?= ($head['allow_auto_correct'] ?? '') == 'Checked' ? 'checked' : '' ?>>
                            <span>Enable auto correct</span>
                        </div>
                    </div>-->
                    <div class="ff">
                        <label>Status</label>
                        <select name="status">
                            <option value="Open"  <?= $head['status'] == 'Open'  ? 'selected' : '' ?>>Open</option>
                            <option value="Close" <?= $head['status'] == 'Close' ? 'selected' : '' ?>>Close</option>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Approval Status</label>
                        <select name="approval_status">
                            <option value="Pending" <?= $head['approval_status'] == 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Approve" <?= $head['approval_status'] == 'Approve' ? 'selected' : '' ?>>Approve</option>
                            <option value="Reject"  <?= $head['approval_status'] == 'Reject'  ? 'selected' : '' ?>>Reject</option>
                        </select>
                    </div>
                    <div class="ff">
                        <label>Remarks</label>
                        <input type="text" name="remarks" value="<?= htmlspecialchars($head['remarks'] ?? '') ?>" placeholder="optional notes...">
                    </div>
                </div>
            </div>
        </div><!-- /panel-row -->


        <!-- PANEL DETAIL TABLE -->
        <div class="so-panel-full">
            <div class="so-panel-header">
                <i class="fa fa-table"></i> Order Details
                <span id="row_count_label" style="color:#aaa;font-size:9px;margin-left:8px;">// 0 rows</span>
                <span style="color:#aaa;font-size:9px;margin-left:auto;">// detail_sales_order</span>
            </div>
            <div class="detail-toolbar">
                <button type="button" class="btn-vs btn-primary" onclick="addRow()"><i class="fa fa-plus"></i> Add Row</button>
                <button type="button" class="btn-vs btn-secondary" onclick="deleteSelected()"><i class="fa fa-trash"></i> Delete Selected</button>
                <span style="font-size:9px;color:#6c757d;margin-left:8px;">
                    <i class="fa fa-info-circle"></i> Ketik di kolom Inventory ID untuk mencari produk
                </span>
            </div>
            <div class="detail-table-wrap">
                <table class="detail-table" id="detailTable">
                    <thead>
                        <tr>
                            <th style="width:28px;"><input type="checkbox" id="selectAll"></th>
                            <th style="width:22px;">#</th>
                            <th style="min-width:240px;">Inventory ID / Name</th>
                            <th style="min-width:75px;">Qty</th>
                            <th style="min-width:50px;">UoM</th>
                            <th style="min-width:75px;">Qty Pack</th>
                            <th style="min-width:55px;">UoM Pack</th>
                            <th style="min-width:60px;">UoM Detail</th>
                            <th style="min-width:90px;">Price Unit</th>
                            <th style="min-width:90px;">Price</th>
                            <th style="min-width:95px;">SubTotal</th>
                            <th style="min-width:120px;">Remarks</th>
                            <th style="width:38px;"></th>
                        </tr>
                    </thead>
                    <tbody id="detailBody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="10" style="text-align:right;color:#664d03;">SubTotal :</td>
                            <td><input type="text" id="subtotal_display" readonly style="color:var(--accent-green);font-weight:bold;text-align:right;width:100%;background:transparent;border:none;"></td>
                            <td colspan="2"></td>
                        </tr>
                        <tr>
                            <td colspan="10" style="text-align:right;color:#664d03;">Grand Total :</td>
                            <td><input type="text" id="grand_total_display" readonly style="color:#0d6efd;font-weight:bold;font-size:12px;text-align:right;width:100%;background:transparent;border:none;"></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="so-footer-row">
                <div class="dp-block">
                    <span>Down Payment :</span>
                    <input 
                        type="text" 
                        name="down_payment" 
                        id="down_payment" 
                        value="<?= number_format((float)($head['down_payment'] ?? 0), 0, ',', '.') ?>"
                        inputmode="numeric"
                    >
                    <input type="hidden" name="grand_total" id="grand_total_hidden">
                </div>
                <div class="totals-block">
                    <div class="total-item"><div class="lbl">SubTotal</div><div class="val" id="st_summary">0,00</div></div>
                    <div style="color:#ccc;font-size:18px;">|</div>
                    <div class="total-item"><div class="lbl">Grand Total</div><div class="val grand" id="gt_summary">0,00</div></div>
                    <div style="color:#ccc;font-size:18px;">|</div>
                    <div class="total-item"><div class="lbl">Balance</div><div class="val" id="balance_summary" style="color:#fd7e14;">0,00</div></div>
                </div>
            </div>
        </div>

        <!-- ACTION BAR -->
        <div class="so-actionbar">
            <button type="button" class="btn-vs btn-secondary" onclick="window.location.href='index.php?page=sales_order'">
                <i class="fa fa-times"></i> Cancel
            </button>
            <button type="submit" class="btn-vs btn-warning" id="btnUpdate">
                <i class="fa fa-save"></i> Update Sales Order
            </button>
        </div>

        <div class="so-statusbar">
            <span><i class="fa fa-edit" style="margin-right:4px;"></i>EDIT MODE</span>
            <span class="sep">|</span>
            <span><?= htmlspecialchars($_SESSION['username']) ?></span>
            <span class="sep">|</span>
            <span id="sb_rows">Rows: 0</span>
            <span class="sep">|</span>
            <span id="sb_total">Total: 0,00</span>
            <span class="sep">|</span>
            <span><?= htmlspecialchars($order_no) ?></span>
            <span class="sep">|</span>
            <span><?= date('d/m/Y H:i') ?></span>
        </div>

    </form>
    </div>
</div>

<!-- Data detail untuk JS -->
<script>
var existingDetails = <?= json_encode($details) ?>;
</script>

<script>
function escHtml(s) {
    if (!s) return '';
    return String(s).replace(/[&<>"']/g, function(m) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}
function parseRupiah(value) {
    if (value === null || value === undefined || value === '') {
        return 0;
    }

    value = String(value).trim();

    // Hilangkan karakter selain angka, titik, koma, minus
    value = value.replace(/[^0-9.,\-]/g, '');

    if (value === '' || value === '-' || value === '.' || value === ',') {
        return 0;
    }

    var hasDot   = value.indexOf('.') !== -1;
    var hasComma = value.indexOf(',') !== -1;

    if (hasDot && hasComma) {
        // Format Indonesia: 1.000.000,50
        value = value.replace(/\./g, '');
        value = value.replace(',', '.');
    } else if (hasComma && !hasDot) {
        // Format: 20000,50
        value = value.replace(',', '.');
    } else if (hasDot && !hasComma) {
        var dotCount = (value.match(/\./g) || []).length;

        if (dotCount > 1) {
            // Format ribuan: 1.000.000
            value = value.replace(/\./g, '');
        } else {
            var parts = value.split('.');
            var decimalLength = parts[1] ? parts[1].length : 0;

            if (decimalLength === 3) {
                // Format input rupiah: 20.000
                value = value.replace(/\./g, '');
            }
            // Kalau decimalLength 1 atau 2, anggap format database/desimal: 20000.00
        }
    }

    return parseFloat(value) || 0;
}

function formatRupiahInput(value) {
    value = parseRupiah(value);

    return value.toLocaleString('id-ID', {
        maximumFractionDigits: 0
    });
}
function formatRupiahTyping(value) {
    value = String(value || '').replace(/\D/g, '');

    if (value === '') {
        return '0';
    }

    value = parseInt(value, 10);

    return value.toLocaleString('id-ID', {
        maximumFractionDigits: 0
    });
}
function fmtNum(value) {
    value = parseRupiah(value);

    return value.toLocaleString('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function updateRowNumbers() {
    var rows = document.querySelectorAll('#detailBody .detail-row');
    rows.forEach(function(r, i) {
        var c = r.querySelector('.ln-cell');
        if (c) c.textContent = i + 1;
    });
    var n = rows.length;
    document.getElementById('row_count_label').textContent = '// ' + n + ' row' + (n!==1?'s':'');
    document.getElementById('sb_rows').textContent = 'Rows: ' + n;
}

var rowIndex = 0;
function buildRow(d) {
    d = d || {};
    var idx = rowIndex++;
    var selOpt = d.inventory_id
        ? `<option value="${escHtml(d.inventory_id)}" selected>${escHtml(d.inventory_id)} — ${escHtml(d.inventory_name||'')}</option>`
        : '';
    return `<tr class="detail-row" data-idx="${idx}">
        <td style="text-align:center;"><input type="checkbox" class="rowCheckbox"></td>
        <td class="ln-cell" style="text-align:right;color:#aaa;font-size:9px;user-select:none;padding-right:5px;"></td>
        <td class="inv-select2-wrap">
            <select name="inventory_id[]" class="inv-select" data-row="${idx}" style="width:100%;">${selOpt}</select>
            <input type="hidden" name="inventory_name[]" class="inv-name-hidden" value="${escHtml(d.inventory_name||'')}">
        </td>
        <td><input type="number" step="0.01" name="quantity[]"      class="qty"          value="${d.quantity||0}"></td>
        <td><input type="text"               name="uom[]"           class="inv-uom"       value="${escHtml(d.uom||'')}"       readonly></td>
        <td><input type="number" step="0.01" name="quantity_pack[]" class="qty-pack"      value="${d.quantity_pack||0}"></td>
        <td><input type="text"               name="uom_pack[]"      class="inv-uom-pack"  value="${escHtml(d.uom_pack||'')}"  readonly></td>
        <td><input type="text"               name="uom_detail[]"    class="inv-uom-detail"value="${escHtml(d.uom_detail||'')}"></td>
        <td>
            <input 
                type="text" 
                name="price_unit[]" 
                class="price-unit rupiah-input" 
                value="${formatRupiahInput(d.price_unit || 0)}"
                inputmode="numeric"
            >
        </td>
        <td>
            <input 
                type="text" 
                name="price[]" 
                class="price rupiah-input" 
                value="${formatRupiahInput(d.price || 0)}"
                inputmode="numeric"
            >
        </td>
        <td>
            <input 
                type="text" 
                name="subtotal[]" 
                class="subtotal rupiah-input" 
                value="${formatRupiahInput(d.subtotal || 0)}"
                readonly
            >
        </td>
        <td><input type="text"               name="remarks_detail[]"class="inv-remarks"   value="${escHtml(d.remarks||'')}" placeholder="catatan..."></td>
        <td style="text-align:center;">
            <button type="button" class="btn-vs btn-danger" onclick="removeRow(this)"><i class="fa fa-times"></i></button>
        </td>
    </tr>`;
}

function initInvSelect2(row) {
    var $sel = $(row).find('.inv-select');
    $sel.select2({
        placeholder        : '🔍 Cari inventory ID / nama...',
        allowClear         : true,
        minimumInputLength : 1,
        dropdownCssClass   : 'inv-dropdown',
        width              : '100%',
        ajax: {
            url          : 'modul/transaksi/search_inventory.php',
            dataType     : 'json',
            delay        : 250,
            data         : function(p) { return { q: p.term, page: p.page||1 }; },
            processResults: function(d) { return { results: d.results, pagination: d.pagination }; },
            cache        : true
        },
        templateResult   : formatInvResult,
        templateSelection: formatInvSelection
    });

    $sel.on('select2:select', function(e) {
        var item = e.params.data;
        var tr   = $(this).closest('tr');
        tr.find('.inv-name-hidden').val(item.inventory_name || '');
        tr.find('.inv-uom').val(item.uom || '');
        tr.find('.inv-uom-pack').val(item.uom_pack || '');
        tr.find('.inv-remarks').val(item.remarks || '');
        calculateRow(tr[0]);
    });
    $sel.on('select2:clear', function() {
        var tr = $(this).closest('tr');
        tr.find('.inv-name-hidden, .inv-uom, .inv-uom-pack, .inv-remarks').val('');
        calculateRow(tr[0]);
    });
}

function formatInvResult(item) {
    if (item.loading) return $('<span style="font-size:10px;">🔍 Mencari...</span>');
    if (!item.inventory_id) return $('<span>' + escHtml(item.text) + '</span>');
    return $(`<div>
        <span class="inv-id">${escHtml(item.inventory_id)}</span>
        <span class="inv-name">${escHtml(item.inventory_name||'')}</span>
        <div class="inv-meta">UoM: ${escHtml(item.uom||'-')} &nbsp;|&nbsp; Density: ${item.density||0} &nbsp;|&nbsp; ${item.remarks ? escHtml(item.remarks).substring(0,40)+(item.remarks.length>40?'...':'') : ''}</div>
    </div>`);
}
function formatInvSelection(item) {
    if (!item.inventory_id) return item.text;
    return item.inventory_id + ' — ' + (item.inventory_name || '');
}

function addRow(data) {
    var html   = buildRow(data);
    var $tbody = $('#detailBody');
    $tbody.append(html);
    var $newRow = $tbody.find('tr.detail-row:last');
    initInvSelect2($newRow[0]);
    updateRowNumbers();
    calculateGrandTotal();
}

function removeRow(btn) {
    $(btn).closest('tr').remove();
    updateRowNumbers();
    calculateGrandTotal();
}

function deleteSelected() {
    var $checked = $('.rowCheckbox:checked');
    if (!$checked.length) { alert('Pilih baris yang ingin dihapus!'); return; }
    if (confirm('Hapus ' + $checked.length + ' baris terpilih?')) {
        $checked.each(function() { $(this).closest('tr').remove(); });
        updateRowNumbers();
        calculateGrandTotal();
    }
}

function calculateRow(row) {
    var $row = $(row);

    var qtyPack   = parseFloat($row.find('.qty-pack').val()) || 0;
    var priceUnit = parseRupiah($row.find('.price-unit').val());
    var price     = parseRupiah($row.find('.price').val());

    // Prioritas pakai price_unit.
    // Kalau price_unit = 0, baru pakai price.
    var hargaDipakai = priceUnit > 0 ? priceUnit : price;

    var subtotal = qtyPack * hargaDipakai;

    $row.find('.subtotal').val(formatRupiahInput(subtotal));

    calculateGrandTotal();
}

function calculateGrandTotal() {
    var subtotalTotal = 0;

    document.querySelectorAll('.subtotal').forEach(function(el) {
        subtotalTotal += parseRupiah(el.value);
    });

    var grandTotal  = subtotalTotal;
    var downPayment = parseRupiah(document.getElementById('down_payment').value);
    var balance     = grandTotal - downPayment;

    document.getElementById('subtotal_display').value    = fmtNum(subtotalTotal);
    document.getElementById('grand_total_display').value = fmtNum(grandTotal);
    document.getElementById('grand_total_hidden').value  = grandTotal.toFixed(2);

    document.getElementById('st_summary').textContent      = fmtNum(subtotalTotal);
    document.getElementById('gt_summary').textContent      = fmtNum(grandTotal);
    document.getElementById('balance_summary').textContent = fmtNum(balance);
    document.getElementById('sb_total').textContent        = 'Total: ' + fmtNum(grandTotal);
}

document.addEventListener('DOMContentLoaded', function() {

    // Select2 panel
    $('#marketing_id').select2({ placeholder: '🔍 Search marketing...', allowClear: true, minimumResultsForSearch: 0 });
    $('#sales_id').select2({ placeholder: '🔍 Search sales...', allowClear: true, minimumResultsForSearch: 0 });
    $('#customer_id').select2({ placeholder: '🔍 Search customer...', allowClear: true, minimumResultsForSearch: 0 });

    // Customer change
    $('#customer_id').on('change', function() {
        var opt  = this.options[this.selectedIndex];
        var cid  = this.value;
        var name = opt.text || '';
        document.getElementById('customer_address').value     = opt.getAttribute('data-address') || '';
        document.getElementById('customer_city').value        = opt.getAttribute('data-city') || '';
        document.getElementById('shipment_location').value    = opt.getAttribute('data-address') || '';
        document.getElementById('customer_name_hidden').value = cid ? name : '';
    });

    // Load existing detail rows
    if (existingDetails && existingDetails.length > 0) {
        existingDetails.forEach(function(d) { addRow(d); });
    }
    // Add 3 empty rows at the end
    for (var i = 0; i < 3; i++) addRow();

    calculateGrandTotal();

    // Input delegation
 $(document).on('input', '.qty-pack, .price-unit, .price', function() {
    if ($(this).hasClass('rupiah-input')) {
        this.value = formatRupiahTyping(this.value);

        // Taruh cursor di akhir supaya tidak mental saat titik ribuan berubah
        this.selectionStart = this.selectionEnd = this.value.length;
    }

    calculateRow($(this).closest('tr')[0]);
});

 $(document).on('input', '#down_payment', function() {
    this.value = formatRupiahTyping(this.value);

    // Taruh cursor di akhir
    this.selectionStart = this.selectionEnd = this.value.length;

    calculateGrandTotal();
});

        // Select All
        document.getElementById('selectAll').addEventListener('change', function() {
            document.querySelectorAll('.rowCheckbox').forEach(function(cb){ cb.checked = this.checked; }, this);
        });

        document.getElementById('formEditSO').addEventListener('submit', function() {
    $('.price-unit, .price, .subtotal, #down_payment').each(function() {
        $(this).val(parseRupiah($(this).val()).toFixed(2));
    });

        var grandTotal = parseRupiah($('#grand_total_hidden').val());
        $('#grand_total_hidden').val(grandTotal.toFixed(2));
        });
    });
</script>