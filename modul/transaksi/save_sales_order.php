<?php
// modul/transaksi/save_sales_order.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

    // Hilangkan karakter selain angka, titik, koma, minus
    $value = preg_replace('/[^0-9.,\-]/', '', $value);

    if ($value === '' || $value === '-' || $value === ',' || $value === '.') {
        return 0;
    }

    $hasDot   = strpos($value, '.') !== false;
    $hasComma = strpos($value, ',') !== false;

    if ($hasDot && $hasComma) {
        // Format Indonesia: 1.000.000,50
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif ($hasComma && !$hasDot) {
        // Format: 1000000,50
        $value = str_replace(',', '.', $value);
    } elseif ($hasDot && !$hasComma) {
        $dotCount = substr_count($value, '.');

        if ($dotCount > 1) {
            // Format ribuan: 1.000.000
            $value = str_replace('.', '', $value);
        } else {
            // Bisa jadi 10.000 atau 1000000.00
            $parts = explode('.', $value);
            $decimalLength = strlen($parts[1] ?? '');

            if ($decimalLength === 3) {
                // Format ribuan: 10.000
                $value = str_replace('.', '', $value);
            }
            // Kalau decimalLength 1 atau 2, biarkan sebagai desimal: 1000000.00
        }
    }

    return floatval($value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?page=sales_order');
    exit;
}

$user_now     = mysqli_real_escape_string($conn, $_SESSION['username']);
$datetime_now = date('Y-m-d H:i:s');
$year_now     = date('Y');

// ── Sanitize Header Fields ─────────────────────────────────────────
$order_no           = mysqli_real_escape_string($conn, trim($_POST['order_no'] ?? ''));
$order_date         = mysqli_real_escape_string($conn, $_POST['order_date'] ?? date('Y-m-d'));
$po_input           = mysqli_real_escape_string($conn, trim($_POST['po'] ?? ''));

$marketing_id       = mysqli_real_escape_string($conn, $_POST['marketing_id'] ?? '');
$sales_id           = mysqli_real_escape_string($conn, $_POST['sales_id'] ?? '');
$customer_id        = mysqli_real_escape_string($conn, $_POST['customer_id'] ?? '');
$customer_name      = mysqli_real_escape_string($conn, trim($_POST['customer_name'] ?? ''));
$customer_address   = mysqli_real_escape_string($conn, trim($_POST['customer_address'] ?? ''));
$customer_city      = mysqli_real_escape_string($conn, trim($_POST['customer_city'] ?? ''));
$station            = mysqli_real_escape_string($conn, trim($_POST['station'] ?? 'FACTORY'));
$shipment_due_date  = mysqli_real_escape_string($conn, $_POST['shipment_due_date'] ?? '');
$shipment_location  = mysqli_real_escape_string($conn, trim($_POST['shipment_location'] ?? ''));
$tolerance          = floatval($_POST['tolerance'] ?? 10);
$backward_calc      = isset($_POST['backward_calculation']) ? 'Checked' : 'Unchecked';
$payment_term       = mysqli_real_escape_string($conn, $_POST['payment_term'] ?? 'Franco');
$payment_type       = mysqli_real_escape_string($conn, $_POST['payment_type'] ?? 'Cash');
$days               = intval($_POST['days'] ?? 30);
$currency           = mysqli_real_escape_string($conn, $_POST['currency'] ?? 'IDR');
$allow_auto_correct = isset($_POST['allow_auto_correct']) ? 'Checked' : 'Unchecked';
$remarks            = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
$down_payment       = parseRupiah($_POST['down_payment'] ?? 0);

// ── Validasi Header ───────────────────────────────────────────────
if (empty($order_no) || empty($customer_id)) {
    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Order No dan Customer wajib diisi!</div>";
    echo "<script>window.history.back();</script>";
    exit;
}

// Cek duplikat Order No
$chk = mysqli_query($conn, "SELECT order_no FROM head_sales_order WHERE order_no='$order_no' LIMIT 1");
if ($chk && mysqli_num_rows($chk) > 0) {
    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Order No <strong>$order_no</strong> sudah terpakai!</div>";
    echo "<script>window.history.back();</script>";
    exit;
}

// Auto resolve nama customer
if (empty($customer_name) && !empty($customer_id)) {
    $q_cust = mysqli_query($conn, "SELECT customer FROM m_customer WHERE customer_id='$customer_id' LIMIT 1");
    if ($q_cust && $r_cust = mysqli_fetch_assoc($q_cust)) {
        $customer_name = mysqli_real_escape_string($conn, $r_cust['customer']);
    }
}

$shipment_due_date_sql = $shipment_due_date ? "'$shipment_due_date'" : 'NULL';

// ════════════════════════════════════════════════════════════
// FUNGSI GENERATE PO NUMBER
// Format: 004/PO/2026
// ════════════════════════════════════════════════════════════
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

$po_number = !empty($po_input) ? $po_input : generatePONumber($conn, $year_now);

$cek_po = mysqli_query($conn, "SELECT no_po FROM hed_po WHERE no_po='$po_number' LIMIT 1");
if ($cek_po && mysqli_num_rows($cek_po) > 0) {
    $po_number = generatePONumber($conn, $year_now);
}

// ════════════════════════════════════════════════════════════
// HITUNG ULANG DETAIL DI SERVER
// ════════════════════════════════════════════════════════════
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

$calculated_grand_total = 0;
$details_for_db = [];

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
    // Subtotal = Quantity Pack x Price Unit
    // Jika Price Unit = 0, maka pakai Price
    $harga_dipakai = $price_unit > 0 ? $price_unit : $price;
    $subtotal      = $qty_pack * $harga_dipakai;

    $calculated_grand_total += $subtotal;

    $details_for_db[] = [
        'inventory_id'   => $inv_id,
        'inventory_name' => $inv_name,
        'quantity'       => $qty,
        'uom'            => $uoms[$i] ?? '',
        'quantity_pack'  => $qty_pack,
        'uom_pack'       => $uom_packs[$i] ?? '',
        'uom_detail'     => $uom_details[$i] ?? '',
        'price_unit'     => $price_unit,
        'price'          => $price,
        'subtotal'       => $subtotal,
        'remarks'        => $remarks_details[$i] ?? ''
    ];
}

if (count($details_for_db) === 0) {
    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Detail item pesanan tidak boleh kosong!</div>";
    echo "<script>window.history.back();</script>";
    exit;
}

// Sisa bayar = grand total - down payment
$balance = $calculated_grand_total - $down_payment;

// ════════════════════════════════════════════════════════════
// DATABASE TRANSACTION START
// ════════════════════════════════════════════════════════════
mysqli_begin_transaction($conn);

try {
    // ── INSERT HEAD SALES ORDER ───────────────────────────────────
    $sql_head = "INSERT INTO head_sales_order (
        order_no, order_date, po, marketing_id, sales_id,
        customer_id, customer_name, customer_address, customer_city, station,
        shipment_due_date, shipment_location, tolerance, backward_calculation,
        payment_term, payment_type, days, currency, allow_auto_correct, remarks,
        grand_total, down_payment, status, approval_status, create_user, date_created
    ) VALUES (
        '$order_no', '$order_date', '$po_number', '$marketing_id', '$sales_id',
        '$customer_id', '$customer_name', '$customer_address', '$customer_city', '$station',
        $shipment_due_date_sql, '$shipment_location', '$tolerance', '$backward_calc',
        '$payment_term', '$payment_type', '$days', '$currency', '$allow_auto_correct', '$remarks',
        '$calculated_grand_total', '$down_payment', 'Open', 'Pending', '$user_now', '$datetime_now'
    )";

    if (!mysqli_query($conn, $sql_head)) {
        throw new Exception('Gagal Simpan Head SO: ' . mysqli_error($conn));
    }

    // ── INSERT HEAD PO ─────────────────────────────────────────────
    $sql_po = "INSERT INTO hed_po (
        no_po, tgl_order, customer, customer_id, created_by, created_at
    ) VALUES (
        '$po_number', '$order_date', '$customer_name', '$customer_id', '$user_now', '$datetime_now'
    )";

    if (!mysqli_query($conn, $sql_po)) {
        throw new Exception('Gagal Simpan Head PO: ' . mysqli_error($conn));
    }

    // ── INSERT DETAILS ─────────────────────────────────────────────
    $detail_count = 0;

    foreach ($details_for_db as $det) {
        $inventory_id_esc   = mysqli_real_escape_string($conn, $det['inventory_id']);
        $inventory_name_esc = mysqli_real_escape_string($conn, $det['inventory_name']);
        $uom_esc            = mysqli_real_escape_string($conn, $det['uom']);
        $uom_pack_esc       = mysqli_real_escape_string($conn, $det['uom_pack']);
        $uom_detail_esc     = mysqli_real_escape_string($conn, $det['uom_detail']);
        $remarks_det_esc    = mysqli_real_escape_string($conn, $det['remarks']);

        // Insert detail_sales_order
        $sql_det_so = "INSERT INTO detail_sales_order (
            order_no, inventory_id, inventory_name, quantity, uom,
            quantity_pack, uom_pack, uom_detail,
            price_unit, price, subtotal, remarks
        ) VALUES (
            '$order_no',
            '$inventory_id_esc',
            '$inventory_name_esc',
            '{$det['quantity']}',
            '$uom_esc',
            '{$det['quantity_pack']}',
            '$uom_pack_esc',
            '$uom_detail_esc',
            '{$det['price_unit']}',
            '{$det['price']}',
            '{$det['subtotal']}',
            '$remarks_det_esc'
        )";

        if (!mysqli_query($conn, $sql_det_so)) {
            throw new Exception('Gagal simpan detail SO: ' . mysqli_error($conn));
        }

        // Untuk det_po, harga pakai price_unit.
        // Jika price_unit = 0, maka pakai price.
        $harga_po = $det['price_unit'] > 0 ? $det['price_unit'] : $det['price'];

        $sql_det_po = "INSERT INTO det_po (
            no_po, ukuran, jml_order, harga, harga_kg
        ) VALUES (
            '$po_number',
            '$inventory_name_esc',
            '{$det['quantity']}',
            '$harga_po',
            '$harga_po'
        )";

        if (!mysqli_query($conn, $sql_det_po)) {
            throw new Exception('Gagal simpan detail PO: ' . mysqli_error($conn));
        }

        $detail_count++;
    }

    mysqli_commit($conn);

    $_SESSION['alert'] = "
        <div class='alert alert-success p-2 small'>
            <strong>✅ Sales Order Sukses Disimpan!</strong><br>
            • No Order: <code>$order_no</code><br>
            • No PO: <strong>$po_number</strong><br>
            • Jumlah Item: $detail_count produk<br>
            • Grand Total: <strong>Rp " . number_format($calculated_grand_total, 0, ',', '.') . "</strong><br>
            • Down Payment: Rp " . number_format($down_payment, 0, ',', '.') . "<br>
            • Sisa Bayar: Rp " . number_format($balance, 0, ',', '.') . "
        </div>";

    echo "<script>window.location.href='index.php?page=sales_order';</script>";

} catch (Exception $e) {
    mysqli_rollback($conn);

    $_SESSION['alert'] = "
        <div class='alert alert-danger p-2 small'>
            <strong>❌ Transaksi Gagal:</strong> " . htmlspecialchars($e->getMessage()) . "
        </div>";

    echo "<script>window.history.back();</script>";
}

exit;
?>