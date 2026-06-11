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

    // Hilangkan titik ribuan
    $value = str_replace('.', '', $value);

    // Kalau ada koma desimal, ubah ke titik
    $value = str_replace(',', '.', $value);

    // Sisakan angka, minus, dan titik
    $value = preg_replace('/[^0-9.\-]/', '', $value);

    return floatval($value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?page=sales_order');
    exit;
}

$user_now     = $_SESSION['username'];
$datetime_now = date('Y-m-d H:i:s');
$year_now     = date('Y');
$month_now    = date('m');

// ── Sanitize Fields ─────────────────────────────────────────
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
$down_payment_input = parseRupiah($_POST['down_payment'] ?? 0);

// ── Validasi ───────────────────────────────────────────────
if (empty($order_no) || empty($customer_id)) {
    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Order No dan Customer wajib diisi!</div>";
    echo "<script>window.history.back();</script>";
    exit;
}

// Cek duplikat Order No
$chk = mysqli_query($conn, "SELECT order_no FROM head_sales_order WHERE order_no='$order_no' LIMIT 1");
if (mysqli_num_rows($chk) > 0) {
    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Order No <strong>$order_no</strong> sudah terpakai!</div>";
    echo "<script>window.history.back();</script>";
    exit;
}

// Auto Resolve Nama Customer
if (empty($customer_name) && !empty($customer_id)) {
    $q_cust = mysqli_query($conn, "SELECT customer FROM m_customer WHERE customer_id='$customer_id' LIMIT 1");
    if ($q_cust && $r_cust = mysqli_fetch_assoc($q_cust)) {
        $customer_name = mysqli_real_escape_string($conn, $r_cust['customer']);
    }
}

$shipment_due_date_sql = $shipment_due_date ? "'$shipment_due_date'" : 'NULL';

// ════════════════════════════════════════════════════════════
// FUNGSI GENERATE PO NUMBER (Format: 004/PO/2026)
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
if (mysqli_num_rows($cek_po) > 0) {
    $po_number = generatePONumber($conn, $year_now);
}

// ════════════════════════════════════════════════════════════


// ════════════════════════════════════════════════════════════
// HITUNG ULANG SEMUA PERHITUNGAN DI SERVER (VALIDASI)
// ════════════════════════════════════════════════════════════
$inventory_ids = $_POST['inventory_id'] ?? [];
$quantities    = $_POST['quantity'] ?? [];
$price_units   = $_POST['price_unit'] ?? [];
$prices        = $_POST['price'] ?? [];

$calculated_grand_total = 0;
$details_for_db = [];

for ($i = 0; $i < count($inventory_ids); $i++) {
    $inv_id = trim($inventory_ids[$i] ?? '');
    if (empty($inv_id)) continue;

    $qty        = floatval($quantities[$i] ?? 0);
    $price_unit = parseRupiah($price_units[$i] ?? 0);
    $price      = parseRupiah($prices[$i] ?? 0);

    // Subtotal mengikuti kolom price
    $subtotal = $price;

    $calculated_grand_total += $subtotal;

    $details_for_db[] = [
        'inventory_id'   => $inv_id,
        'inventory_name' => $_POST['inventory_name'][$i] ?? '',
        'quantity'       => $qty,
        'uom'            => $_POST['uom'][$i] ?? '',
        'quantity_pack'  => floatval($_POST['quantity_pack'][$i] ?? 0),
        'uom_pack'       => $_POST['uom_pack'][$i] ?? '',
        'uom_detail'     => $_POST['uom_detail'][$i] ?? '',
        'price_unit'     => $price_unit,
        'price'          => $price,
        'subtotal'       => $subtotal,
        'remarks'        => $_POST['remarks_detail'][$i] ?? ''
    ];
}

// Validasi down payment tidak boleh lebih dari grand total
$down_payment = min($down_payment_input, $calculated_grand_total);

// ════════════════════════════════════════════════════════════
// DATABASE TRANSACTION START
// ════════════════════════════════════════════════════════════
mysqli_begin_transaction($conn);

try {
    // ── INSERT HEADER TABLES ───────────────────────────────────
    
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
    if (!mysqli_query($conn, $sql_head)) throw new Exception('Gagal Simpan Head SO: ' . mysqli_error($conn));



    // Insert hed_po
    $sql_po = "INSERT INTO hed_po (
        no_po, tgl_order, customer, customer_id, created_by, created_at
    ) VALUES (
        '$po_number', '$order_date', '$customer_name', '$customer_id', '$user_now', '$datetime_now'
    )";
    if (!mysqli_query($conn, $sql_po)) throw new Exception('Gagal Simpan Head PO: ' . mysqli_error($conn));

    // ── INSERT DETAILS (menggunakan hasil perhitungan ulang) ───
    $detail_count = 0;
    foreach ($details_for_db as $det) {
        // Insert detail_sales_order
        $sql_det_so = "INSERT INTO detail_sales_order (
            order_no, inventory_id, inventory_name, quantity, uom, 
            quantity_pack, uom_pack, uom_detail, price_unit, price, subtotal, remarks
        ) VALUES (
            '$order_no', 
            '" . mysqli_real_escape_string($conn, $det['inventory_id']) . "',
            '" . mysqli_real_escape_string($conn, $det['inventory_name']) . "',
            '{$det['quantity']}',
            '" . mysqli_real_escape_string($conn, $det['uom']) . "',
            '{$det['quantity_pack']}',
            '" . mysqli_real_escape_string($conn, $det['uom_pack']) . "',
            '" . mysqli_real_escape_string($conn, $det['uom_detail']) . "',
            '{$det['price_unit']}',
            '{$det['price']}',
            '{$det['subtotal']}',
            '" . mysqli_real_escape_string($conn, $det['remarks']) . "'
        )";
        if (!mysqli_query($conn, $sql_det_so)) {
            throw new Exception("Gagal simpan detail SO: " . mysqli_error($conn));
        }



        // Insert det_po
        $sql_det_po = "INSERT INTO det_po (
            no_po, ukuran, jml_order, harga, harga_kg
        ) VALUES (
            '$po_number',
            '" . mysqli_real_escape_string($conn, $det['inventory_name']) . "',
            '{$det['quantity']}',
            '{$det['price_unit']}',
            '{$det['price_unit']}'
        )";
        if (!mysqli_query($conn, $sql_det_po)) {
            throw new Exception("Gagal simpan detail PO: " . mysqli_error($conn));
        }

        $detail_count++;
    }

    if ($detail_count === 0) {
        throw new Exception('Detail item pesanan tidak boleh kosong!');
    }

    mysqli_commit($conn);

    // Hitung balance untuk ditampilkan di alert
    $balance = $calculated_grand_total - $down_payment;

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
    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'><strong>❌ Transaksi Gagal:</strong> " . htmlspecialchars($e->getMessage()) . "</div>";
    echo "<script>window.history.back();</script>";
}
exit;
?>