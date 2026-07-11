<?php
// modul/transaksi/save_invoice.php
// Revisi flow invoice:
// - Invoice total dihitung server berdasarkan qty_pack_shipping dari det_shipping
// - Harga mengambil dari detail_sales_order.price berdasarkan order_no + inventory_id

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

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

function getShippingInvoiceSubtotal($conn, $shippingNo, $orderNo) {
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
        LEFT JOIN det_invoice di ON di.shipping_no = hs.shipping_no
        WHERE hs.shipping_no = ?
          AND hs.order_no = ?
          AND di.shipping_no IS NULL
        GROUP BY hs.shipping_no, hs.shipping_date, hs.order_no, hs.remarks_shipping
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception('Gagal prepare subtotal shipping: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 'ss', $shippingNo, $orderNo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row) {
        return null;
    }

    return [
        'shipping_no' => $row['shipping_no'],
        'shipping_date' => $row['shipping_date'],
        'order_no' => $row['order_no'],
        'subtotal' => (float)($row['invoice_subtotal'] ?? 0),
        'total' => (float)($row['invoice_subtotal'] ?? 0),
        'remarks_shipping' => $row['remarks_shipping'] ?? ''
    ];
}

$invoice_no = esc($conn, $_POST['invoice_no'] ?? '');
$invoice_date = esc($conn, $_POST['invoice_date'] ?? '');
$customer_id = esc($conn, $_POST['customer_id'] ?? '');
$customer_name = esc($conn, $_POST['customer_name'] ?? '');
$customer_address = esc($conn, $_POST['customer_address'] ?? '');
$customer_city = esc($conn, $_POST['customer_city'] ?? '');
$order_no = esc($conn, $_POST['order_no'] ?? '');
$order_date = esc($conn, $_POST['order_date'] ?? '');
$station = esc($conn, $_POST['station'] ?? 'Factory');
$payment_type = esc($conn, $_POST['payment_type'] ?? '');
$payment_term = esc($conn, $_POST['payment_term'] ?? '');
$days = (int)($_POST['days'] ?? 30);
$currency = esc($conn, $_POST['currency'] ?? 'IDR');
$remarks_invoice = esc($conn, $_POST['remarks_invoice'] ?? '');
$subtotal = moneyToFloat($_POST['subtotal'] ?? 0);
$grand_total = moneyToFloat($_POST['grand_total'] ?? 0);
$down_payment = moneyToFloat($_POST['down_payment'] ?? 0);
$titip_applied = moneyToFloat($_POST['titip_applied'] ?? 0);
$payment_balance = moneyToFloat($_POST['payment_balance'] ?? 0);
$piutang = moneyToFloat($_POST['piutang'] ?? 0);
$shipping_nos = $_POST['shipping_no'] ?? [];
$username = $_SESSION['username'];

if ($invoice_no === '' || $invoice_date === '' || $customer_id === '' || $order_no === '') {
    $_SESSION['alert'] = '<div class="alert alert-danger">Gagal Simpan: Invoice No, Date, Customer, dan Sales Order wajib diisi.</div>';
    echo "<script>window.location.href='index.php?page=add_invoice';</script>";
    exit;
}

if (!is_array($shipping_nos) || count($shipping_nos) === 0) {
    $_SESSION['alert'] = '<div class="alert alert-danger">Gagal Simpan: Minimal pilih 1 shipping/surat jalan.</div>';
    echo "<script>window.location.href='index.php?page=add_invoice';</script>";
    exit;
}

mysqli_begin_transaction($conn);

try {
    $stmtCheckInv = mysqli_prepare($conn, "SELECT invoice_no FROM head_invoice WHERE invoice_no = ? LIMIT 1");
    if (!$stmtCheckInv) {
        throw new Exception('Gagal prepare cek invoice: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmtCheckInv, 's', $invoice_no);
    mysqli_stmt_execute($stmtCheckInv);
    $resCheckInv = mysqli_stmt_get_result($stmtCheckInv);
    if ($resCheckInv && mysqli_num_rows($resCheckInv) > 0) {
        throw new Exception('Invoice No sudah terdaftar. Silakan refresh halaman dan coba lagi.');
    }
    mysqli_stmt_close($stmtCheckInv);

    $validShippingRows = [];
    $calcSubtotal = 0;
    $calcGrandTotal = 0;

    foreach ($shipping_nos as $shipNoRaw) {
        $shipNo = cleanInput($shipNoRaw);
        if ($shipNo === '') continue;

        $rowShip = getShippingInvoiceSubtotal($conn, $shipNo, $order_no);

        if (!$rowShip) {
            throw new Exception('Shipping ' . $shipNo . ' tidak valid, bukan milik order ini, tidak punya detail, atau sudah pernah dibuat invoice.');
        }

        $rowSubtotal = (float)($rowShip['subtotal'] ?? 0);
        if ($rowSubtotal <= 0) {
            throw new Exception('Subtotal invoice untuk shipping ' . $shipNo . ' bernilai 0. Cek detail_sales_order.price dan det_shipping.qty_pack_shipping.');
        }

        $validShippingRows[] = $rowShip;
        $calcSubtotal += $rowSubtotal;
        $calcGrandTotal += $rowSubtotal;
    }

    if (count($validShippingRows) === 0) {
        throw new Exception('Tidak ada shipping valid untuk disimpan.');
    }

    // Pakai hasil kalkulasi server agar tidak bisa dimanipulasi dari browser.
    $subtotal = $calcSubtotal;
    $grand_total = $calcGrandTotal;
    $down_payment = min($down_payment, $grand_total);
    $payment_balance = max($grand_total - $down_payment, 0);
    $piutang = $payment_balance;

    $stmtHead = mysqli_prepare($conn, "
        INSERT INTO head_invoice (
            invoice_no, invoice_date, customer_id, customer_name, customer_address, customer_city,
            order_no, order_date, station, payment_type, payment_term, days, currency,
            remarks_invoice, subtotal, grand_total, down_payment, titip_applied, payment_balance, piutang,
            status, approval_status, create_user, date_created
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', 'Pending', ?, NOW())
    ");

    if (!$stmtHead) {
        throw new Exception('Gagal prepare header invoice: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param(
        $stmtHead,
        'sssssssssssisddddddds',
        $invoice_no,
        $invoice_date,
        $customer_id,
        $customer_name,
        $customer_address,
        $customer_city,
        $order_no,
        $order_date,
        $station,
        $payment_type,
        $payment_term,
        $days,
        $currency,
        $remarks_invoice,
        $subtotal,
        $grand_total,
        $down_payment,
        $titip_applied,
        $payment_balance,
        $piutang,
        $username
    );

    if (!mysqli_stmt_execute($stmtHead)) {
        throw new Exception('Gagal simpan header invoice: ' . mysqli_stmt_error($stmtHead));
    }
    mysqli_stmt_close($stmtHead);

    $stmtDet = mysqli_prepare($conn, "
        INSERT INTO det_invoice (
            invoice_no, shipping_no, shipping_date, order_no, subtotal, total,
            remarks_shipping, create_user, date_created
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$stmtDet) {
        throw new Exception('Gagal prepare detail invoice: ' . mysqli_error($conn));
    }

    foreach ($validShippingRows as $row) {
        $shipNo = $row['shipping_no'];
        $shipDate = $row['shipping_date'];
        $shipOrderNo = $row['order_no'];
        $rowSubtotal = (float)$row['subtotal'];
        $rowTotal = (float)$row['total'];
        $remarksShipping = $row['remarks_shipping'];

        mysqli_stmt_bind_param(
            $stmtDet,
            'ssssddss',
            $invoice_no,
            $shipNo,
            $shipDate,
            $shipOrderNo,
            $rowSubtotal,
            $rowTotal,
            $remarksShipping,
            $username
        );

        if (!mysqli_stmt_execute($stmtDet)) {
            throw new Exception('Gagal simpan detail invoice shipping ' . $shipNo . ': ' . mysqli_stmt_error($stmtDet));
        }
    }
    mysqli_stmt_close($stmtDet);

    mysqli_commit($conn);

    $_SESSION['alert'] = '<div class="alert alert-success">Invoice ' . htmlspecialchars($invoice_no, ENT_QUOTES, 'UTF-8') . ' berhasil disimpan.</div>';
    echo "<script>alert('Invoice " . addslashes($invoice_no) . " berhasil disimpan.'); window.location.href='index.php?page=invoice';</script>";
    exit;
} catch (Exception $e) {
    mysqli_rollback($conn);
    $_SESSION['alert'] = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href='index.php?page=add_invoice';</script>";
    exit;
}
