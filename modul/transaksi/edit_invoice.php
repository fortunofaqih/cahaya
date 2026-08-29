<?php
// modul/transaksi/edit_invoice.php
// Edit invoice: header dapat diedit, shipping dapat dipertahankan/ditambah/dihapus.
// Perhitungan server mengikuti flow: qty_pack_shipping * price SO.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function cleanInput($data) {
    if (is_array($data)) return array_map('cleanInput', $data);
    return trim((string)$data);
}

function esc($conn, $value) {
    return mysqli_real_escape_string($conn, cleanInput($value));
}

function moneyToFloat($value) {
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);
    return (float)$value;
}

function fmtMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function fmtQty($value) {
    $num = (float)$value;
    if (abs($num - round($num)) < 0.000001) return number_format($num, 0, ',', '.');
    return number_format($num, 2, ',', '.');
}

function getShippingInvoiceSubtotal($conn, $shippingNo, $orderNo, $currentInvoiceNo = '') {
    $sql = "
        SELECT
            hs.shipping_no,
            hs.shipping_date,
            hs.order_no,
            hs.remarks_shipping,
            COALESCE(SUM(
                COALESCE(ds.qty_pack_shipping, 0) *
                CASE
                    WHEN COALESCE(dso.price, 0) > 0 THEN COALESCE(dso.price, 0)
                    WHEN COALESCE(dso.quantity_pack, 0) > 0 THEN COALESCE(dso.subtotal, 0) / NULLIF(dso.quantity_pack, 0)
                    ELSE 0
                END
            ), 0) AS invoice_subtotal
        FROM hed_shipping hs
        INNER JOIN det_shipping ds ON ds.shipping_no = hs.shipping_no
        LEFT JOIN detail_sales_order dso
               ON dso.order_no = hs.order_no
              AND dso.inventory_id = ds.inventory_id
        LEFT JOIN det_invoice di
               ON di.shipping_no = hs.shipping_no
              AND (? = '' OR di.invoice_no <> ?)
        WHERE hs.shipping_no = ?
          AND hs.order_no = ?
          AND di.shipping_no IS NULL
        GROUP BY hs.shipping_no, hs.shipping_date, hs.order_no, hs.remarks_shipping
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) throw new Exception('Gagal prepare subtotal shipping: ' . mysqli_error($conn));
    mysqli_stmt_bind_param($stmt, 'ssss', $currentInvoiceNo, $currentInvoiceNo, $shippingNo, $orderNo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row) return null;

    return [
        'shipping_no' => $row['shipping_no'],
        'shipping_date' => $row['shipping_date'],
        'order_no' => $row['order_no'],
        'subtotal' => (float)($row['invoice_subtotal'] ?? 0),
        'total' => (float)($row['invoice_subtotal'] ?? 0),
        'remarks_shipping' => $row['remarks_shipping'] ?? ''
    ];
}

function getInvoiceItemsByShipping($conn, $shippingNo) {
    $sql = "
        SELECT
            hs.shipping_no,
            hs.order_no,
            COALESCE(mg.name, 'GUDANG BARANG JADI 1') AS warehouse_name,
            ds.inventory_id,
            ds.inventory_name,
            ds.qty_shipping,
            ds.uom_shipping,
            ds.qty_pack_shipping,
            ds.uom_pack_shipping,
            ds.qty_detail_shipping,
            ds.uom_detail_shipping,
            dso.price AS so_price,
            dso.subtotal AS so_subtotal,
            CASE
                WHEN COALESCE(dso.price, 0) > 0 THEN COALESCE(dso.price, 0)
                WHEN COALESCE(dso.quantity_pack, 0) > 0 THEN COALESCE(dso.subtotal, 0) / NULLIF(dso.quantity_pack, 0)
                ELSE 0
            END AS invoice_price,
            (
                COALESCE(ds.qty_pack_shipping, 0) *
                CASE
                    WHEN COALESCE(dso.price, 0) > 0 THEN COALESCE(dso.price, 0)
                    WHEN COALESCE(dso.quantity_pack, 0) > 0 THEN COALESCE(dso.subtotal, 0) / NULLIF(dso.quantity_pack, 0)
                    ELSE 0
                END
            ) AS invoice_subtotal
        FROM det_shipping ds
        INNER JOIN hed_shipping hs ON hs.shipping_no = ds.shipping_no
        LEFT JOIN m_gudang mg ON mg.gudang_id = hs.gudang_id
        LEFT JOIN detail_sales_order dso
               ON dso.order_no = hs.order_no
              AND dso.inventory_id = ds.inventory_id
        WHERE ds.shipping_no = ?
        ORDER BY ds.id ASC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) return [];
    mysqli_stmt_bind_param($stmt, 's', $shippingNo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $items = [];
    while ($res && $row = mysqli_fetch_assoc($res)) $items[] = $row;
    mysqli_stmt_close($stmt);
    return $items;
}

$invoiceNo = trim($_GET['invoice_no'] ?? $_POST['invoice_no'] ?? $_GET['id'] ?? '');
if ($invoiceNo === '') {
    $_SESSION['alert'] = '<div class="alert alert-danger">Invoice No kosong.</div>';
    echo "<script>window.location.href='index.php?page=invoice';</script>";
    exit;
}

$stmtHead = mysqli_prepare($conn, "SELECT * FROM head_invoice WHERE invoice_no = ? LIMIT 1");
if (!$stmtHead) die('Gagal prepare invoice: ' . mysqli_error($conn));
mysqli_stmt_bind_param($stmtHead, 's', $invoiceNo);
mysqli_stmt_execute($stmtHead);
$resHead = mysqli_stmt_get_result($stmtHead);
$head = $resHead ? mysqli_fetch_assoc($resHead) : null;
mysqli_stmt_close($stmtHead);

if (!$head) die('Invoice tidak ditemukan.');

$stmtShip = mysqli_prepare($conn, "
    SELECT
        hs.shipping_no,
        hs.shipping_date,
        hs.order_no,
        hs.customer_id,
        hs.customer_name,
        hs.customer_address,
        hs.customer_city,
        hs.gudang_id,
        COALESCE(mg.name, 'GUDANG BARANG JADI 1') AS warehouse_name,
        hs.remarks_shipping,
        CASE WHEN di_current.shipping_no IS NULL THEN 0 ELSE 1 END AS is_selected
    FROM hed_shipping hs
    LEFT JOIN m_gudang mg ON mg.gudang_id = hs.gudang_id
    LEFT JOIN det_invoice di_current ON di_current.shipping_no = hs.shipping_no AND di_current.invoice_no = ?
    LEFT JOIN det_invoice di_other ON di_other.shipping_no = hs.shipping_no AND di_other.invoice_no <> ?
    WHERE hs.order_no = ?
      AND di_other.shipping_no IS NULL
    ORDER BY hs.shipping_date ASC, hs.shipping_no ASC
");
if (!$stmtShip) die('Gagal prepare shipping: ' . mysqli_error($conn));
mysqli_stmt_bind_param($stmtShip, 'sss', $invoiceNo, $invoiceNo, $head['order_no']);
mysqli_stmt_execute($stmtShip);
$resShip = mysqli_stmt_get_result($stmtShip);
$shippings = [];
while ($resShip && $row = mysqli_fetch_assoc($resShip)) {
    $calc = getShippingInvoiceSubtotal($conn, $row['shipping_no'], $head['order_no'], $invoiceNo);
    $row['subtotal_calc'] = $calc ? (float)$calc['subtotal'] : 0;
    $shippings[] = $row;
}
mysqli_stmt_close($stmtShip);

/*
 * CUSTOMER SUMBER:
 * - Invoice tidak boleh memilih customer bebas.
 * - Customer mengikuti Shipping/Sales Order yang dipilih.
 * - Untuk tampilan edit, prioritas customer dari shipping yang saat ini
 *   sudah terhubung ke invoice. Jika belum ada, pakai shipping pertama.
 */
$sourceCustomer = null;
$sourceCustomerConflict = false;
$sourceCustomerIds = [];

foreach ($shippings as $ship) {
    $shipCustomerId = trim((string)($ship['customer_id'] ?? ''));

    if ($shipCustomerId !== '') {
        $sourceCustomerIds[$shipCustomerId] = true;
    }

    if (
        $sourceCustomer === null &&
        (int)($ship['is_selected'] ?? 0) === 1
    ) {
        $sourceCustomer = [
            'customer_id' => $shipCustomerId,
            'customer_name' => trim((string)($ship['customer_name'] ?? '')),
            'customer_address' => trim((string)($ship['customer_address'] ?? '')),
            'customer_city' => trim((string)($ship['customer_city'] ?? '')),
        ];
    }
}

if ($sourceCustomer === null && !empty($shippings)) {
    $firstShip = $shippings[0];
    $sourceCustomer = [
        'customer_id' => trim((string)($firstShip['customer_id'] ?? '')),
        'customer_name' => trim((string)($firstShip['customer_name'] ?? '')),
        'customer_address' => trim((string)($firstShip['customer_address'] ?? '')),
        'customer_city' => trim((string)($firstShip['customer_city'] ?? '')),
    ];
}

$sourceCustomerConflict = count($sourceCustomerIds) > 1;

$invoiceCustomerId = trim((string)($head['customer_id'] ?? ''));
$invoiceCustomerName = trim((string)($head['customer_name'] ?? ''));

$sourceCustomerId = trim((string)($sourceCustomer['customer_id'] ?? ''));
$sourceCustomerName = trim((string)($sourceCustomer['customer_name'] ?? ''));

$customerMismatch =
    $sourceCustomer !== null &&
    (
        $invoiceCustomerId !== $sourceCustomerId ||
        strcasecmp($invoiceCustomerName, $sourceCustomerName) !== 0
    );
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Edit Invoice <?= h($invoiceNo) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
    <style>
        body { background:#f4f6f8; font-size:12px; }
        .wrap { max-width:1200px; margin:15px auto; }
        .card-header { font-weight:bold; }
        .table-sm th, .table-sm td { font-size:11px; vertical-align:middle; }
        .item-table th { background:#eef4ff; }
        .item-name { white-space:normal; word-break:break-word; min-width:230px; }
        .text-end input { text-align:right; }
        .customer-box { border:1px solid #d9e2ec; border-radius:6px; padding:10px; background:#f8fafc; height:100%; }
        .customer-box.source { border-color:#86b7fe; background:#eef6ff; }
        .customer-box.current-mismatch { border-color:#f1aeb5; background:#fff5f5; }
        .customer-meta { font-size:11px; color:#5f6b76; line-height:1.55; }
		.customer-compare-wrap {
			display: grid;
			grid-template-columns: minmax(0, 1fr) 32px minmax(0, 1fr);
			gap: 8px;
			align-items: center;
			margin-top: 8px;
		}

		.customer-mini-card {
			border: 1px solid #d9dee5;
			border-radius: 6px;
			padding: 8px 10px;
			background: #fff;
			min-height: auto;
		}

		.customer-mini-card.current {
			background: #fff8f8;
			border-color: #f1c7c7;
		}

		.customer-mini-card.source {
			background: #f4fbf6;
			border-color: #b8dfc3;
		}

		.customer-mini-title {
			font-size: 9px;
			font-weight: 700;
			text-transform: uppercase;
			color: #6c757d;
			margin-bottom: 3px;
			letter-spacing: .2px;
		}

		.customer-mini-main {
			font-size: 12px;
			font-weight: 700;
			color: #212529;
			line-height: 1.25;
		}

		.customer-mini-sub {
			margin-top: 2px;
			font-size: 10px;
			color: #6c757d;
		}

		.customer-arrow {
			text-align: center;
			font-size: 18px;
			font-weight: 700;
			color: #6c757d;
		}

		@media (max-width: 768px) {
			.customer-compare-wrap {
				grid-template-columns: 1fr;
			}

			.customer-arrow {
				transform: rotate(90deg);
			}
		}
    </style>
</head>
<body>
<div class="wrap">
    <?php if (isset($_SESSION['alert'])): ?><?= $_SESSION['alert']; unset($_SESSION['alert']); ?><?php endif; ?>

    <form method="POST" action="update_invoice.php" id="formEditInvoice">
        <input type="hidden" name="update_invoice" value="1">
        <input type="hidden" name="invoice_no" value="<?= h($invoiceNo) ?>">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-primary text-white">Edit Invoice</div>
            <div class="card-body">
                <?php if ($sourceCustomerConflict): ?>
                    <div class="alert alert-danger py-2">
                        <strong>Peringatan:</strong> ditemukan lebih dari satu Customer ID pada shipping untuk Sales Order ini.
                        Update akan ditolak sampai data Shipping konsisten.
                    </div>
                <?php elseif ($customerMismatch): ?>
                    <div class="alert alert-warning py-2">
                        <strong>Customer invoice berbeda dengan SO / Shipping.</strong>
                        Saat Update Invoice, customer header invoice akan otomatis dikoreksi mengikuti customer dari shipping yang dipilih.
                    </div>
                <?php elseif ($sourceCustomer): ?>
                    <div class="alert alert-success py-2">
                        Customer invoice sudah sesuai dengan SO / Shipping.
                    </div>
                <?php endif; ?>

                <div class="row g-2">
                    <div class="col-md-3"><label class="form-label fw-bold">Invoice No</label><input class="form-control form-control-sm" value="<?= h($head['invoice_no']) ?>" readonly></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Invoice Date</label><input type="date" name="invoice_date" class="form-control form-control-sm" value="<?= h($head['invoice_date']) ?>" required></div>
                    <div class="col-md-3"><label class="form-label fw-bold">Sales Order</label><input class="form-control form-control-sm" value="<?= h($head['order_no']) ?>" readonly></div>
					<div class="customer-compare-wrap">
						<div class="customer-mini-card current">
							<div class="customer-mini-title">Customer Invoice Saat Ini</div>
							<div class="customer-mini-main">
								<?= h(($head['customer_id'] ?? '') . ' - ' . ($head['customer_name'] ?? '')) ?>
							</div>
							<div class="customer-mini-sub">
								<?= h($head['customer_city'] ?? '') ?>
							</div>
						</div>

						<div class="customer-arrow">
							→
						</div>

						<div class="customer-mini-card source">
							<div class="customer-mini-title">Customer Sumber (SO / Shipping)</div>
							<div class="customer-mini-main">
								<?= h(($sourceCustomer['customer_id'] ?? '') . ' - ' . ($sourceCustomer['customer_name'] ?? '')) ?>
							</div>
							<div class="customer-mini-sub">
								<?= h($sourceCustomer['customer_city'] ?? '') ?>
							</div>
						</div>
					</div>
                    <div class="col-md-2"><label class="form-label fw-bold">Station</label><input name="station" class="form-control form-control-sm" value="<?= h($head['station']) ?>"></div>
                    <div class="col-md-2"><label class="form-label fw-bold">Payment Type</label><input name="payment_type" class="form-control form-control-sm" value="<?= h($head['payment_type']) ?>"></div>
                    <div class="col-md-2"><label class="form-label fw-bold">Payment Term</label><input name="payment_term" class="form-control form-control-sm" value="<?= h($head['payment_term']) ?>"></div>
                    <div class="col-md-2"><label class="form-label fw-bold">Days</label><input type="number" name="days" class="form-control form-control-sm" value="<?= (int)$head['days'] ?>"></div>
                    <div class="col-md-2"><label class="form-label fw-bold">Currency</label><input name="currency" class="form-control form-control-sm" value="<?= h($head['currency'] ?: 'IDR') ?>"></div>
                    <div class="col-md-2"><label class="form-label fw-bold">Down Payment</label><input name="down_payment" class="form-control form-control-sm text-end" value="<?= fmtMoney($head['down_payment']) ?>"></div>
                    <div class="col-md-2"><label class="form-label fw-bold">Titip Applied</label><input name="titip_applied" class="form-control form-control-sm text-end" value="<?= fmtMoney($head['titip_applied']) ?>"></div>
                    <div class="col-md-10"><label class="form-label fw-bold">Remarks Invoice</label><textarea name="remarks_invoice" rows="2" class="form-control form-control-sm"><?= h($head['remarks_invoice']) ?></textarea></div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header bg-secondary text-white">Shipping / Surat Jalan</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px;">Pilih</th>
                                <th>Shipping No</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Warehouse</th>
                                <th>Subtotal Invoice</th>
                                <th>Item Detail</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$shippings): ?>
                            <tr><td colspan="7" class="text-center text-danger py-3">Tidak ada shipping tersedia untuk order ini.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($shippings as $ship): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" name="shipping_no[]" value="<?= h($ship['shipping_no']) ?>" <?= (int)$ship['is_selected'] === 1 ? 'checked' : '' ?>></td>
                                <td><strong><?= h($ship['shipping_no']) ?></strong></td>
                                <td><?= h($ship['shipping_date']) ?></td>
                                <td>
                                    <strong><?= h(($ship['customer_id'] ?? '') . ' - ' . ($ship['customer_name'] ?? '')) ?></strong>
                                </td>
                                <td><?= h($ship['warehouse_name']) ?></td>
                                <td class="text-end fw-bold"><?= fmtMoney($ship['subtotal_calc']) ?></td>
                                <td>
                                    <?php $items = getInvoiceItemsByShipping($conn, $ship['shipping_no']); ?>
                                    <table class="table table-sm table-bordered item-table mb-0">
                                        <thead><tr><th>Inventory ID</th><th>Inventory Name</th><th>Qty</th><th>UoM</th><th>Qty Pack</th><th>UoM Pack</th><th>Price</th><th>Subtotal</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($items as $it): ?>
                                            <tr>
                                                <td><?= h($it['inventory_id']) ?></td>
                                                <td class="item-name"><?= h($it['inventory_name']) ?></td>
                                                <td class="text-end"><?= fmtQty($it['qty_shipping']) ?></td>
                                                <td class="text-center"><?= h($it['uom_shipping']) ?></td>
                                                <td class="text-end"><?= fmtQty($it['qty_pack_shipping']) ?></td>
                                                <td class="text-center"><?= h($it['uom_pack_shipping']) ?></td>
                                                <td class="text-end"><?= fmtMoney($it['invoice_price']) ?></td>
                                                <td class="text-end fw-bold"><?= fmtMoney($it['invoice_subtotal']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-body d-flex justify-content-end gap-2">
               <button type="button" class="btn-vs btn-secondary" onclick="window.location.href='../../index.php?page=invoice'">
                <i class="fa fa-times"></i> Cancel
            </button>
                <button type="submit" class="btn btn-success">Update Invoice</button>
            </div>
        </div>
    </form>
</div>
</body>
</html>