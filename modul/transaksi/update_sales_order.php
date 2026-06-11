<?php
// modul/transaksi/update_sales_order.php

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function parseRupiah($value) {
    if ($value === null || $value === '') {
        return 0;
    }

    $value = trim((string)$value);

    // Hilangkan titik ribuan
    $value = str_replace('.', '', $value);

    // Kalau ada koma desimal, ubah ke titik
    $value = str_replace(',', '.', $value);

    // Sisakan angka, minus, dan titik
    $value = preg_replace('/[^0-9.\-]/', '', $value);

    return floatval($value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<script>window.location.href='index.php?page=sales_order';</script>";
    exit;
}

$user_now     = mysqli_real_escape_string($conn, $_SESSION['username']);
$datetime_now = date('Y-m-d H:i:s');

// ── Sanitize ──────────────────────────────────────────────────────────
$order_no          = mysqli_real_escape_string($conn, trim($_POST['order_no'] ?? ''));
$order_date        = mysqli_real_escape_string($conn, $_POST['order_date'] ?? '');
$sop_date          = mysqli_real_escape_string($conn, $_POST['sop_date'] ?? '');
$marketing_id      = mysqli_real_escape_string($conn, $_POST['marketing_id'] ?? '');
$sales_id          = mysqli_real_escape_string($conn, $_POST['sales_id'] ?? '');
$customer_id       = mysqli_real_escape_string($conn, $_POST['customer_id'] ?? '');
$customer_name     = mysqli_real_escape_string($conn, trim($_POST['customer_name'] ?? ''));
$customer_address  = mysqli_real_escape_string($conn, trim($_POST['customer_address'] ?? ''));
$customer_city     = mysqli_real_escape_string($conn, trim($_POST['customer_city'] ?? ''));
$station           = mysqli_real_escape_string($conn, trim($_POST['station'] ?? 'FACTORY'));
$shipment_due_date = mysqli_real_escape_string($conn, $_POST['shipment_due_date'] ?? '');
$shipment_location = mysqli_real_escape_string($conn, trim($_POST['shipment_location'] ?? ''));
$tolerance         = floatval($_POST['tolerance'] ?? 10);
$backward_calc     = isset($_POST['backward_calculation']) ? 'Checked' : 'Unchecked';
$payment_term      = mysqli_real_escape_string($conn, $_POST['payment_term'] ?? 'Franco');
$payment_type      = mysqli_real_escape_string($conn, $_POST['payment_type'] ?? 'Cash');
$days              = intval($_POST['days'] ?? 30);
$currency          = mysqli_real_escape_string($conn, $_POST['currency'] ?? 'IDR');
$kurs              = floatval($_POST['kurs'] ?? 1);
$allow_auto_correct= isset($_POST['allow_auto_correct']) ? 'Checked' : 'Unchecked';
$remarks           = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
$status            = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Open');
$approval_status   = mysqli_real_escape_string($conn, $_POST['approval_status'] ?? 'Pending');
$down_payment      = parseRupiah($_POST['down_payment'] ?? 0);

// Jangan langsung pakai grand_total dari POST.
// Nanti dihitung ulang dari detail.
$grand_total = 0;

// ── Validasi ──────────────────────────────────────────────────────────
if (empty($order_no)) {
    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Order No tidak valid!</div>";
    echo "<script>window.history.back();</script>";
    exit;
}

// ── Resolve customer_name ─────────────────────────────────────────────
if (empty($customer_name) && !empty($customer_id)) {
    $q = mysqli_query($conn, "SELECT customer FROM m_customer WHERE customer_id='$customer_id' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $customer_name = mysqli_real_escape_string($conn, $r['customer']);
    }
}

$order_date            = $order_date ?: date('Y-m-d');
$sop_date              = $sop_date ?: date('Y-m-d');
$shipment_due_date_sql = $shipment_due_date ? "'$shipment_due_date'" : 'NULL';

// ── Transaction: update head + replace detail ─────────────────────────
mysqli_begin_transaction($conn);

try {
    // Hapus detail lama
    if (!mysqli_query($conn, "DELETE FROM detail_sales_order WHERE order_no='$order_no'")) {
        throw new Exception('Hapus detail gagal: ' . mysqli_error($conn));
    }

    // Insert detail baru
    $inventory_ids   = $_POST['inventory_id'] ?? [];
    $inventory_names = $_POST['inventory_name'] ?? [];
    $quantities      = $_POST['quantity'] ?? [];
    $uoms            = $_POST['uom'] ?? [];
    $quantity_packs  = $_POST['quantity_pack'] ?? [];
    $uom_packs       = $_POST['uom_pack'] ?? [];
    $uom_details     = $_POST['uom_detail'] ?? [];
    $price_units     = $_POST['price_unit'] ?? [];
    $prices          = $_POST['price'] ?? [];
    $remarks_details = $_POST['remarks_detail'] ?? [];

    $detail_count = 0;

    for ($i = 0; $i < count($inventory_ids); $i++) {
        $inv_id   = trim($inventory_ids[$i] ?? '');
        $inv_name = trim($inventory_names[$i] ?? '');
        $qty      = floatval($quantities[$i] ?? 0);

        if (empty($inv_id) && empty($inv_name) && $qty <= 0) {
            continue;
        }

        $qty_pack   = floatval($quantity_packs[$i] ?? 0);
        $price_unit = parseRupiah($price_units[$i] ?? 0);
        $price      = parseRupiah($prices[$i] ?? 0);

        // Logic utama:
        // Subtotal = Qty Pack x Price Unit
        // Jika Price Unit 0, pakai Price
        $harga_dipakai = $price_unit > 0 ? $price_unit : $price;
        $subtotal      = $qty_pack * $harga_dipakai;

        $grand_total += $subtotal;

        $sql_det = "INSERT INTO detail_sales_order (
            order_no, inventory_id, inventory_name, quantity, uom,
            quantity_pack, uom_pack, uom_detail,
            price_unit, price, subtotal, remarks
        ) VALUES (
            '$order_no',
            '" . mysqli_real_escape_string($conn, $inv_id) . "',
            '" . mysqli_real_escape_string($conn, $inv_name) . "',
            '$qty',
            '" . mysqli_real_escape_string($conn, $uoms[$i] ?? '') . "',
            '$qty_pack',
            '" . mysqli_real_escape_string($conn, $uom_packs[$i] ?? '') . "',
            '" . mysqli_real_escape_string($conn, $uom_details[$i] ?? '') . "',
            '$price_unit',
            '$price',
            '$subtotal',
            '" . mysqli_real_escape_string($conn, $remarks_details[$i] ?? '') . "'
        )";

        if (!mysqli_query($conn, $sql_det)) {
            throw new Exception('Insert detail gagal: ' . mysqli_error($conn));
        }

        $detail_count++;
    }

    // Update head_sales_order setelah grand_total dihitung ulang
    $sql_head = "UPDATE head_sales_order SET
        order_date        = '$order_date',
        sop_date          = '$sop_date',
        marketing_id      = '$marketing_id',
        sales_id          = '$sales_id',
        customer_id       = '$customer_id',
        customer_name     = '$customer_name',
        customer_address  = '$customer_address',
        customer_city     = '$customer_city',
        station           = '$station',
        shipment_due_date = $shipment_due_date_sql,
        shipment_location = '$shipment_location',
        tolerance         = '$tolerance',
        backward_calculation = '$backward_calc',
        payment_term      = '$payment_term',
        payment_type      = '$payment_type',
        days              = '$days',
        currency          = '$currency',
        kurs              = '$kurs',
        allow_auto_correct= '$allow_auto_correct',
        remarks           = '$remarks',
        status            = '$status',
        approval_status   = '$approval_status',
        grand_total       = '$grand_total',
        down_payment      = '$down_payment',
        user_modified     = '$user_now',
        date_modified     = '$datetime_now'
    WHERE order_no = '$order_no'";

    if (!mysqli_query($conn, $sql_head)) {
        throw new Exception('Update header gagal: ' . mysqli_error($conn));
    }

    mysqli_commit($conn);

    $_SESSION['alert'] = "
        <div class='alert alert-success p-2 small'>
            <strong>✅ Sales Order <code>$order_no</code> berhasil diupdate!</strong>
            &nbsp;|&nbsp; $detail_count item detail tersimpan.
        </div>";

    echo "<script>window.location.href='index.php?page=sales_order';</script>";

} catch (Exception $e) {
    mysqli_rollback($conn);

    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>
        <strong>❌ Gagal update!</strong> " . htmlspecialchars($e->getMessage()) . "
    </div>";

    echo "<script>window.location.href='index.php?page=sales_order';</script>";
}

exit;