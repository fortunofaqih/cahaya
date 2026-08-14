<?php
// modul/transaksi/return_invoice.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function returnH($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function parseReturnDate($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    return $fallback;
}

function formatReturnDate($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatReturnMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function returnIcon($name) {
    $icons = [
        'return' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.4 5H18a3 3 0 0 1 3 3v8a3 3 0 0 1-3 3H8v-2h10a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1H7.4l3.3 3.3-1.4 1.4L3.6 6l5.7-5.7 1.4 1.4L7.4 5Z"/></svg>',
        'plus' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 4a6.5 6.5 0 0 1 5.18 10.43l4.45 4.44-1.42 1.42-4.44-4.45A6.5 6.5 0 1 1 10.5 4Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z"/></svg>',
        'reset' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.33 10H7.9A5 5 0 1 0 12 7H8.83l2.58 2.59L10 11 5 6l5-5 1.41 1.41L8.83 5H12Z"/></svg>',
        'print' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10v5H7V3Zm2 2v1h6V5H9ZM6 9h12a3 3 0 0 1 3 3v5h-4v4H7v-4H3v-5a3 3 0 0 1 3-3Zm3 7v3h6v-3H9Zm8-3h2v-1a1 1 0 0 0-1-1H6a1 1 0 0 0-1 1v3h2v-1h10v1h2v-2h-2Z"/></svg>',
        'edit' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16.86 3.59a2 2 0 0 1 2.83 0l.72.72a2 2 0 0 1 0 2.83L9.5 18.05 5 19l.95-4.5L16.86 3.59Zm1.41 1.41L7.78 15.5l-.3 1.02 1.02-.3L19 5.73 18.27 5ZM4 21h16v-2H4v2Z"/></svg>',
        'delete' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6l1 2h4v2H4V5h4l1-2Zm1 6h2v9h-2V9Zm4 0h2v9h-2V9ZM7 9h2v10h6V9h2v10a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2V9Z"/></svg>',
        'expand' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 10l5 5 5-5H7Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'status' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 3 6v6c0 5 3.8 9.7 9 10 5.2-.3 9-5 9-10V6l-9-4Zm0 2.2 7 3.1V12c0 4-2.9 7.5-7 8-4.1-.5-7-4-7-8V7.3l7-3.1Zm-1 11.2 5.3-5.3 1.4 1.4-6.7 6.7-3.7-3.7 1.4-1.4 2.3 2.3Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}

$today = date('Y-m-d');
$start_date = parseReturnDate($_GET['start_date'] ?? '', $today);
$end_date = parseReturnDate($_GET['end_date'] ?? '', $today);

if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

$start_date_display = formatReturnDate($start_date);
$end_date_display = formatReturnDate($end_date);
$status = trim((string)($_GET['status'] ?? 'All'));
$return_id_search = trim((string)($_GET['return_id'] ?? ''));
$shipping_no_search = trim((string)($_GET['shipping_no'] ?? ''));

$where = ["h.return_date BETWEEN ? AND ?"];
$types = 'ss';
$params = [$start_date, $end_date];

if ($status !== '' && strtolower($status) !== 'all') {
    $where[] = "h.status = ?";
    $types .= 's';
    $params[] = $status;
}

if ($return_id_search !== '') {
    $where[] = "h.return_id LIKE ?";
    $types .= 's';
    $params[] = '%' . $return_id_search . '%';
}

if ($shipping_no_search !== '') {
    $where[] = "h.shipping_no LIKE ?";
    $types .= 's';
    $params[] = '%' . $shipping_no_search . '%';
}

$where_sql = 'WHERE ' . implode(' AND ', $where);

$sql = "
    SELECT
        h.return_id,
        h.return_date,
        h.invoice_no,
        h.invoice_date,
        h.shipping_no,
        h.shipping_date,
        h.order_no,
        h.order_date,
        h.customer_id,
        h.customer_name,
        h.customer_address,
        h.customer_city,
        h.currency,
        h.reason_return,
        h.remarks_return,
        h.subtotal,
        h.grand_total,
        h.down_payment,
        h.titip_applied,
        h.payment_balance,
        h.return_amount,
        h.remaining_invoice_balance,
        h.status,
        h.approval_status,
        h.create_user,
        h.date_created,
        h.user_modified,
        h.date_modified,
       CASE
            WHEN h.return_id LIKE '%/CP-MCP/%'
            OR h.invoice_no LIKE 'CP-MCP/INV/%'
            THEN 1
            ELSE 0
        END AS is_mcp
    FROM head_retur_invoice h
    $where_sql
    ORDER BY h.return_date DESC, h.return_id DESC
";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
    die('<div class="alert alert-danger">SQL Error: ' . returnH(mysqli_error($conn)) . '</div>');
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$returnDetails = [];
$detailSql = "
    SELECT
        d.return_id,
        d.shipping_no,
        d.invoice_no,
        d.order_no,
        d.shipping_detail_id,
        d.inventory_id,
        d.inventory_name,
        d.original_quantity,
        d.original_uom,
        d.original_quantity_pack,
        d.original_uom_pack,
        d.original_quantity_detail,
        d.original_uom_detail,
        d.return_quantity,
        d.uom,
        d.return_quantity_pack,
        d.uom_pack,
        d.return_quantity_detail,
        d.uom_detail,
        d.price,
        d.return_subtotal,
        d.remarks_detail
    FROM detail_retur_invoice d
    INNER JOIN head_retur_invoice h ON h.return_id = d.return_id
    $where_sql
    ORDER BY d.return_id, d.id
";

$detailStmt = mysqli_prepare($conn, $detailSql);
if ($detailStmt) {
    mysqli_stmt_bind_param($detailStmt, $types, ...$params);
    mysqli_stmt_execute($detailStmt);
    $detailResult = mysqli_stmt_get_result($detailStmt);

    while ($detailRow = mysqli_fetch_assoc($detailResult)) {
        $key = (string)($detailRow['return_id'] ?? '');
        $returnDetails[$key][] = $detailRow;
    }

    mysqli_stmt_close($detailStmt);
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<style>
.return-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.return-wrap {
    background: #f0f2f5;
    padding: 12px;
    color: #212529;
    font-size: 11px;
}
.return-app-icon {
    width: 14px;
    height: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    vertical-align: -2px;
}
.return-app-icon svg {
    width: 14px;
    height: 14px;
    display: block;
    fill: currentColor;
}
.return-title-icon svg {
    width: 18px;
    height: 18px;
}
.return-crystal-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: #fff;
    padding: 10px 15px;
    border-radius: 5px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.return-header-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    flex-wrap: wrap;
}

.return-header-actions .return-btn-vs {
    white-space: nowrap;
}
.return-filter-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin-bottom: 10px;
}
.return-filter-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(130px, 1fr));
    gap: 8px;
    align-items: end;
}
.return-ff label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: #0d6efd;
    margin-bottom: 3px;
    text-transform: uppercase;
}
.return-ff input,
.return-ff select {
    width: 100%;
    border: 1px solid #ced4da;
    border-radius: 3px;
    padding: 6px 8px;
    font-size: 11px;
    background: #fff;
}
.return-btn-vs {
    padding: 6px 12px;
    font-size: 11px;
    font-weight: bold;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    line-height: 1;
    min-height: 30px;
}
.return-btn-vs:hover {
    filter: brightness(.95);
    text-decoration: none;
}
.return-btn-primary { background: #0d6efd; color: #fff; }
.return-btn-secondary { background: #6c757d; color: #fff; }
.return-btn-success { background: #198754; color: #fff; }
.return-btn-warning { background: #ffc107; color: #000; }
.return-btn-danger { background: #dc3545; color: #fff; }
.return-btn-dark { background: #212529; color: #fff; }
.return-btn-disabled {
    opacity: .45;
    pointer-events: none;
    cursor: not-allowed;
}
.return-btn-action {
    width: 30px;
    height: 28px;
    padding: 0;
    margin: 0 1px;
}
.return-table-wrap {
    max-height: 560px;
    overflow: auto;
    border: 1px solid #c0cddb;
    background: #fff;
}
.return-table {
    width: 100%;
    min-width: 1180px;
    border-collapse: collapse;
    font-size: 10.5px;
}
.return-table th {
    position: sticky;
    top: 0;
    background: #e9ecef;
    color: #2b4c7e;
    border: 1px solid #c0cddb;
    padding: 7px 6px;
    text-align: center;
    white-space: nowrap;
    z-index: 2;
}
.return-table td {
    border: 1px solid #d3d3d3;
    padding: 5px 6px;
    vertical-align: middle;
    white-space: nowrap;
}
.return-table tbody tr:hover td {
    background: #e8f2fe;
}
.return-sticky-action {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 3;
    min-width: 142px;
}
.return-table th.return-sticky-action {
    background: #e9ecef;
    z-index: 4;
}
.return-badge-open,
.return-badge-close,
.return-badge-pending,
.return-badge-approved {
    padding: 3px 8px;
    border-radius: 10px;
    font-weight: bold;
    display: inline-block;
    min-width: 58px;
    text-align: center;
}
.return-badge-open { background: #d1e7dd; color: #0f5132; }
.return-badge-close { background: #f8d7da; color: #842029; }
.return-badge-pending { background: #fff3cd; color: #664d03; }
.return-badge-approved { background: #cff4fc; color: #055160; }
.return-text-right { text-align: right; }
.return-text-center { text-align: center; }
.return-text-bold { font-weight: bold; }
.return-text-blue { color: #0d6efd; }
.return-btn-expand { background: #6f42c1; color: #fff; }
.return-btn-expand-mcp { background: #fd7e14; color: #fff; }
.return-btn-edit-mcp { background: #ffca2c; color: #000; }
.return-btn-delete-mcp { background: #b02a37; color: #fff; }

.return-type-badge {
    display: inline-block;
    margin-left: 5px;
    padding: 2px 6px;
    border-radius: 9px;
    font-size: 9px;
    font-weight: 700;
    vertical-align: middle;
}
.return-type-cp {
    background: #d1e7dd;
    color: #0f5132;
}
.return-type-mcp {
    background: #fff3cd;
    color: #664d03;
}
.return-btn-expand .return-app-icon { transition: transform .2s ease; }
.return-btn-expand.expanded .return-app-icon { transform: rotate(180deg); }
.return-detail-row { display: none; }
.return-detail-row.show { display: table-row; }
.return-detail-cell { padding: 0 !important; background: #f8fbff !important; }
.return-detail-box { padding: 10px 12px 12px 45px; min-width: 1450px; }
.return-detail-title { font-weight: 700; color: #2b4c7e; margin-bottom: 7px; }
.return-detail-table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
.return-detail-table th,
.return-detail-table td {
    border: 1px solid #ccd7e3;
    padding: 6px;
    background: #fff;
    white-space: nowrap;
}
.return-detail-table th {
    background: #dfeaf5;
    color: #2b4c7e;
    position: static;
}
@media (max-width: 900px) {
    .return-filter-grid {
        grid-template-columns: 1fr 1fr;
    }

    .return-crystal-header {
        align-items: flex-start;
    }

    .return-header-actions {
        width: 100%;
        justify-content: flex-start;
    }
}

@media (max-width: 520px) {
    .return-header-actions {
        display: grid;
        grid-template-columns: 1fr;
        gap: 6px;
    }

    .return-header-actions .return-btn-vs {
        width: 100%;
    }
}
@media print {
    .return-d-print-none {
        display: none !important;
    }
    .return-wrap {
        background: #fff;
        padding: 0;
    }
    .return-table-wrap {
        max-height: none;
        overflow: visible;
    }
}
</style>

<div class="return-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="return-crystal-header return-d-print-none" style="margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="return-app-icon return-title-icon"><?= returnIcon('return') ?></span>
            Sales Return / Retur Invoice
        </h5>

        <div class="return-header-actions">
            <a href="index.php?page=add_return" class="return-btn-vs return-btn-success">
                <span class="return-app-icon"><?= returnIcon('plus') ?></span>
                Create Return CP
            </a>

            <a href="index.php?page=add_return_mcp" class="return-btn-vs return-btn-warning">
                <span class="return-app-icon"><?= returnIcon('plus') ?></span>
                Create Return CP-MCP
            </a>
        </div>
    </div>

    <div class="return-filter-card return-d-print-none">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="return_invoice">
            <div class="return-filter-grid">
                <div class="return-ff">
                    <label><span class="return-app-icon"><?= returnIcon('calendar') ?></span> Start Date</label>
                    <input type="text" name="start_date" class="js-return-date-picker"
                           value="<?= returnH($start_date_display) ?>" autocomplete="off">
                </div>
                <div class="return-ff">
                    <label><span class="return-app-icon"><?= returnIcon('calendar') ?></span> End Date</label>
                    <input type="text" name="end_date" class="js-return-date-picker"
                           value="<?= returnH($end_date_display) ?>" autocomplete="off">
                </div>
                <div class="return-ff">
                    <label><span class="return-app-icon"><?= returnIcon('status') ?></span> Status</label>
                    <select name="status">
                        <?php foreach (['All' => 'All', 'Open' => 'Open', 'Close' => 'Close', 'Cancelled' => 'Cancelled'] as $val => $label): ?>
                            <option value="<?= returnH($val) ?>" <?= $status === $val ? 'selected' : '' ?>>
                                <?= returnH($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="return-ff">
                    <label>Return ID</label>
                    <input type="text" name="return_id" value="<?= returnH($return_id_search) ?>"
                           placeholder="Cari Return ID...">
                </div>
                <!--<div class="return-ff">
                    <label>Shipping No.</label>
                    <input type="text" name="shipping_no" value="<?= returnH($shipping_no_search) ?>"
                           placeholder="Cari Shipping No...">
                </div>-->
                <div style="display:flex;gap:6px;">
                    <button type="submit" class="return-btn-vs return-btn-dark">
                        <span class="return-app-icon"><?= returnIcon('search') ?></span>
                        Cari
                    </button>
                    <a href="index.php?page=return_invoice" class="return-btn-vs return-btn-secondary">
                        <span class="return-app-icon"><?= returnIcon('reset') ?></span>
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <div class="return-table-wrap">
        <table class="return-table">
            <thead>
                <tr>
                    <th class="return-sticky-action">Aksi</th>
                    <th>Return ID</th>
                    <th>Return Date</th>
                    <th>Customer ID</th>
                    <th>Order No.</th>
                    <th>Reason Return</th>
                    <th>Invoice No.</th>
                    <th>Remarks Return</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$result || mysqli_num_rows($result) === 0): ?>
                    <tr>
                        <td colspan="8" style="text-align:center;color:#777;padding:15px;">
                            Tidak ada data Sales Return.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                        <?php
                            $returnId = (string)($row['return_id'] ?? '');
                            $statusText = (string)($row['status'] ?? 'Open');
                            $statusClass = strtolower($statusText) === 'open'
                                ? 'return-badge-open'
                                : 'return-badge-close';
                            $approvalText = (string)($row['approval_status'] ?? 'Pending');
                            $approvalClass = strtolower($approvalText) === 'approved'
                                ? 'return-badge-approved'
                                : 'return-badge-pending';
                            $canModify = strtolower($approvalText) === 'pending';

                            // CP-MCP disimpan dengan shipping_detail_id = 0.
                            $isMcp = (int)($row['is_mcp'] ?? 0) === 1;

                            $detailRows = $returnDetails[$returnId] ?? [];
                            $detailTargetId = 'return-detail-' . md5($returnId);

                            $editPage = $isMcp ? 'edit_return_mcp' : 'edit_return';
                            $deleteAction = $isMcp
                                ? 'modul/transaksi/delete_return_mcp.php'
                                : 'modul/transaksi/delete_return.php';

                            $typeLabel = $isMcp ? 'CP-MCP' : 'CP';
                            $typeClass = $isMcp ? 'return-type-mcp' : 'return-type-cp';
                        ?>
                        <tr>
                            <td class="return-sticky-action return-text-center">
                                <button type="button"
                                        class="return-btn-vs <?= $isMcp ? 'return-btn-expand-mcp' : 'return-btn-expand' ?> return-btn-action js-expand-return"
                                        data-target="<?= returnH($detailTargetId) ?>"
                                        title="Lihat detail <?= returnH($typeLabel) ?>">
                                    <span class="return-app-icon"><?= returnIcon('expand') ?></span>
                                </button>

                                <a class="return-btn-vs return-btn-primary return-btn-action"
                                   href="modul/transaksi/print_return.php?return_id=<?= urlencode($returnId) ?>"
                                   target="_blank"
                                   title="Print Sales Return <?= returnH($typeLabel) ?>">
                                    <span class="return-app-icon"><?= returnIcon('print') ?></span>
                                </a>

                                <a class="return-btn-vs <?= $isMcp ? 'return-btn-edit-mcp' : 'return-btn-warning' ?> return-btn-action <?= !$canModify ? 'return-btn-disabled' : '' ?>"
                                   href="index.php?page=<?= returnH($editPage) ?>&return_id=<?= urlencode($returnId) ?>"
                                   title="Edit <?= returnH($typeLabel) ?>">
                                    <span class="return-app-icon"><?= returnIcon('edit') ?></span>
                                </a>

                                <button type="button"
                                        class="return-btn-vs <?= $isMcp ? 'return-btn-delete-mcp' : 'return-btn-danger' ?> return-btn-action js-delete-return <?= !$canModify ? 'return-btn-disabled' : '' ?>"
                                        data-return-id="<?= returnH($returnId) ?>"
                                        data-delete-action="<?= returnH($deleteAction) ?>"
                                        data-return-type="<?= returnH($typeLabel) ?>"
                                        <?= !$canModify ? 'disabled' : '' ?>
                                        title="Delete <?= returnH($typeLabel) ?>">
                                    <span class="return-app-icon"><?= returnIcon('delete') ?></span>
                                </button>
                            </td>
                            <td class="return-text-bold return-text-blue">
                                <?= returnH($returnId) ?>
                                <span class="return-type-badge <?= returnH($typeClass) ?>">
                                    <?= returnH($typeLabel) ?>
                                </span>
                            </td>
                            <td><?= returnH(formatReturnDate($row['return_date'])) ?></td>
                            <td><?= returnH($row['customer_id']) ?></td>
                            <td><?= returnH($row['order_no']) ?></td>
                            <td><?= returnH($row['reason_return']) ?></td>
                            <td><?= returnH($row['invoice_no']) ?></td>
                            <td><?= returnH($row['remarks_return']) ?></td>
                        </tr>

                        <tr id="<?= returnH($detailTargetId) ?>" class="return-detail-row">
                            <td colspan="8" class="return-detail-cell">
                                <div class="return-detail-box">
                                    <div class="return-detail-title">
                                        Detail Sales Return <?= returnH($typeLabel) ?>: <?= returnH($returnId) ?>
                                    </div>
                                    <table class="return-detail-table">
                                        <thead>
                                            <tr>
                                                <th>Inventory ID</th>
                                                <th>Inventory Name</th>
                                                <th>Qty Return</th>
                                                <th>UoM</th>
                                                <th>Qty Pack Return</th>
                                                <th>UoM Pack</th>
                                                <th>Qty Detail Return</th>
                                                <th>UoM Detail</th>
                                                <th>Price</th>
                                                <th>Subtotal</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php if (empty($detailRows)): ?>
                                            <tr>
                                                <td colspan="11" class="return-text-center">
                                                    Detail inventory tidak ditemukan.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($detailRows as $detailIndex => $detailRow): ?>
                                                <tr>
                                                    <td><?= returnH($detailRow['inventory_id']) ?></td>
                                                    <td><?= returnH($detailRow['inventory_name']) ?></td>
                                                    <td class="return-text-right return-text-bold"><?= returnH(formatReturnMoney($detailRow['return_quantity'])) ?></td>
                                                    <td><?= returnH($detailRow['uom']) ?></td>
                                                    <td class="return-text-right return-text-bold"><?= returnH(formatReturnMoney($detailRow['return_quantity_pack'])) ?></td>
                                                    <td><?= returnH($detailRow['uom_pack']) ?></td>
                                                    <td class="return-text-right"><?= returnH(formatReturnMoney($detailRow['return_quantity_detail'])) ?></td>
                                                    <td><?= returnH($detailRow['uom_detail']) ?></td>
                                                    <td class="return-text-right">Rp <?= returnH(formatReturnMoney($detailRow['price'])) ?></td>
                                                    <td class="return-text-right return-text-bold">Rp <?= returnH(formatReturnMoney($detailRow['return_subtotal'])) ?></td>
                                                    <td><?= returnH($detailRow['remarks_detail']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<form id="returnDeleteForm" method="POST" action="" style="display:none;">
    <input type="hidden" name="return_id" id="returnDeleteId">
</form>

<script>
document.addEventListener('click', function(event) {
    var deleteButton = event.target.closest('.js-delete-return');
    if (deleteButton) {
        var returnId = deleteButton.getAttribute('data-return-id') || '';
        var deleteAction = deleteButton.getAttribute('data-delete-action') || '';
        var returnType = deleteButton.getAttribute('data-return-type') || 'CP';

        if (
            returnId &&
            deleteAction &&
            confirm('Hapus Sales Return ' + returnType + ' ' + returnId + '?')
        ) {
            var deleteForm = document.getElementById('returnDeleteForm');

            document.getElementById('returnDeleteId').value = returnId;
            deleteForm.action = deleteAction;
            deleteForm.submit();
        }

        return;
    }

    var button = event.target.closest('.js-expand-return');
    if (!button) return;

    var target = document.getElementById(button.getAttribute('data-target'));
    if (!target) return;

    target.classList.toggle('show');
    button.classList.toggle('expanded');
});

if (typeof flatpickr !== 'undefined') {
    flatpickr('.js-return-date-picker', {
        dateFormat: 'd-M-Y',
        allowInput: true,
        disableMobile: true
    });
}
</script>

<?php mysqli_stmt_close($stmt); ?>