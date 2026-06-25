<?php
// modul/transaksi/update_shipping.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// Fungsi untuk membersihkan input
function cleanInput($data) {
    if (is_array($data)) {
        return array_map('cleanInput', $data);
    }
    return trim($data);
}

// Ambil data dari POST
$shipping_no = isset($_POST['shipping_no']) ? mysqli_real_escape_string($conn, cleanInput($_POST['shipping_no'])) : '';
$old_order_no = isset($_POST['old_order_no']) ? mysqli_real_escape_string($conn, cleanInput($_POST['old_order_no'])) : '';
$shipping_date = isset($_POST['shipping_date']) ? mysqli_real_escape_string($conn, cleanInput($_POST['shipping_date'])) : '';
$nota_date = isset($_POST['nota_date']) ? mysqli_real_escape_string($conn, cleanInput($_POST['nota_date'])) : '';
$order_no = isset($_POST['order_no']) ? mysqli_real_escape_string($conn, cleanInput($_POST['order_no'])) : '';
$order_date = isset($_POST['order_date']) ? mysqli_real_escape_string($conn, cleanInput($_POST['order_date'])) : '';
$customer_id = isset($_POST['customer_id']) ? mysqli_real_escape_string($conn, cleanInput($_POST['customer_id'])) : '';
$customer_name = isset($_POST['customer_name']) ? mysqli_real_escape_string($conn, cleanInput($_POST['customer_name'])) : '';
$customer_address = isset($_POST['customer_address']) ? mysqli_real_escape_string($conn, cleanInput($_POST['customer_address'])) : '';
$customer_city = isset($_POST['customer_city']) ? mysqli_real_escape_string($conn, cleanInput($_POST['customer_city'])) : '';
$shipment_location = isset($_POST['shipment_location']) ? mysqli_real_escape_string($conn, cleanInput($_POST['shipment_location'])) : '';
$transporter = isset($_POST['transporter']) ? mysqli_real_escape_string($conn, cleanInput($_POST['transporter'])) : '';
$driver_name = isset($_POST['driver_name']) ? mysqli_real_escape_string($conn, cleanInput($_POST['driver_name'])) : '';
$truck_no = isset($_POST['truck_no']) ? mysqli_real_escape_string($conn, cleanInput($_POST['truck_no'])) : '';
$gudang_id = isset($_POST['gudang_id']) ? mysqli_real_escape_string($conn, cleanInput($_POST['gudang_id'])) : '';
$remarks_shipping = isset($_POST['remarks_shipping']) ? mysqli_real_escape_string($conn, cleanInput($_POST['remarks_shipping'])) : '';

// Data arrays dari detail
$detail_ids = isset($_POST['detail_id']) ? $_POST['detail_id'] : [];
$inventory_ids = isset($_POST['inventory_id']) ? $_POST['inventory_id'] : [];
$inventory_names = isset($_POST['inventory_name']) ? $_POST['inventory_name'] : [];
$uom_shippings = isset($_POST['uom_shipping']) ? $_POST['uom_shipping'] : [];
$qty_shippings = isset($_POST['qty_shipping']) ? $_POST['qty_shipping'] : [];
$qty_pack_shippings = isset($_POST['qty_pack_shipping']) ? $_POST['qty_pack_shipping'] : [];
$uom_pack_shippings = isset($_POST['uom_pack_shipping']) ? $_POST['uom_pack_shipping'] : [];
$uom_detail_shippings = isset($_POST['uom_detail_shipping']) ? $_POST['uom_detail_shipping'] : [];
$adjustment_shippings = isset($_POST['adjustment_shipping']) ? $_POST['adjustment_shipping'] : [];
$remarks_inventory_shippings = isset($_POST['remarks_inventory_shipping']) ? $_POST['remarks_inventory_shipping'] : [];

// Validasi mandatory fields
if (empty($shipping_no)) {
    echo "<script>
        alert('Shipping No tidak ditemukan!');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;
}

if (empty($shipping_date)) {
    echo "<script>
        alert('Shipping Date tidak boleh kosong!');
        window.location.href='index.php?page=edit_shipping&id=$shipping_no';
    </script>";
    exit;
}

if (empty($order_no)) {
    echo "<script>
        alert('Order No tidak boleh kosong!');
        window.location.href='index.php?page=edit_shipping&id=$shipping_no';
    </script>";
    exit;
}

// Validasi minimal 1 item
$valid_items = 0;
foreach ($inventory_ids as $inv_id) {
    if (!empty(trim($inv_id))) {
        $valid_items++;
    }
}

if ($valid_items == 0) {
    echo "<script>
        alert('Minimal harus ada 1 item inventory yang valid!');
        window.location.href='index.php?page=edit_shipping&id=$shipping_no';
    </script>";
    exit;
}

// Mulai transaksi
mysqli_begin_transaction($conn);

try {
    // 1. UPDATE hed_shipping
    $query_header = "UPDATE hed_shipping SET
        shipping_date = '$shipping_date',
        order_no = '$order_no',
        order_date = " . ($order_date ? "'$order_date'" : "NULL") . ",
        customer_id = " . ($customer_id ? "'$customer_id'" : "NULL") . ",
        customer_name = " . ($customer_name ? "'$customer_name'" : "NULL") . ",
        customer_address = " . ($customer_address ? "'$customer_address'" : "NULL") . ",
        customer_city = " . ($customer_city ? "'$customer_city'" : "NULL") . ",
        shipment_location = " . ($shipment_location ? "'$shipment_location'" : "NULL") . ",
        transporter = " . ($transporter ? "'$transporter'" : "NULL") . ",
        driver_name = " . ($driver_name ? "'$driver_name'" : "NULL") . ",
        truck_no = " . ($truck_no ? "'$truck_no'" : "NULL") . ",
        gudang_id = " . ($gudang_id ? "'$gudang_id'" : "NULL") . ",
        remarks_shipping = " . ($remarks_shipping ? "'$remarks_shipping'" : "NULL") . ",
        nota_date = " . ($nota_date ? "'$nota_date'" : "NULL") . ",
        user_modified = '" . $_SESSION['username'] . "',
        date_modified = NOW()
    WHERE shipping_no = '$shipping_no'";

    if (!mysqli_query($conn, $query_header)) {
        throw new Exception("Gagal update header shipping: " . mysqli_error($conn));
    }

    // 2. Hapus semua detail yang ada
    $query_delete = "DELETE FROM det_shipping WHERE shipping_no = '$shipping_no'";
    if (!mysqli_query($conn, $query_delete)) {
        throw new Exception("Gagal menghapus detail shipping lama: " . mysqli_error($conn));
    }

    // 3. Insert ulang semua detail
    $total_items = count($inventory_ids);
    $inserted_count = 0;
    
    for ($i = 0; $i < $total_items; $i++) {
        $inventory_id = isset($inventory_ids[$i]) ? mysqli_real_escape_string($conn, cleanInput($inventory_ids[$i])) : '';
        $inventory_name = isset($inventory_names[$i]) ? mysqli_real_escape_string($conn, cleanInput($inventory_names[$i])) : '';
        $uom_shipping = isset($uom_shippings[$i]) ? mysqli_real_escape_string($conn, cleanInput($uom_shippings[$i])) : 'KG';
        $qty_shipping = isset($qty_shippings[$i]) ? floatval($qty_shippings[$i]) : 0;
        $qty_pack_shipping = isset($qty_pack_shippings[$i]) ? floatval($qty_pack_shippings[$i]) : 0;
        $uom_pack_shipping = isset($uom_pack_shippings[$i]) ? mysqli_real_escape_string($conn, cleanInput($uom_pack_shippings[$i])) : 'KG';
        $uom_detail_shipping = isset($uom_detail_shippings[$i]) ? mysqli_real_escape_string($conn, cleanInput($uom_detail_shippings[$i])) : 'KG';
        $adjustment_shipping = isset($adjustment_shippings[$i]) ? floatval($adjustment_shippings[$i]) : 0;
        $remarks_inventory_shipping = isset($remarks_inventory_shippings[$i]) ? mysqli_real_escape_string($conn, cleanInput($remarks_inventory_shippings[$i])) : '';

        // Skip jika inventory_id kosong
        if (empty($inventory_id)) {
            continue;
        }

        $query_detail = "INSERT INTO det_shipping (
            shipping_no,
            inventory_id,
            inventory_name,
            uom_shipping,
            qty_shipping,
            qty_pack_shipping,
            uom_pack_shipping,
            uom_detail_shipping,
            adjustment_shipping,
            remarks_inventory_shipping,
            create_user,
            date_created
        ) VALUES (
            '$shipping_no',
            '$inventory_id',
            " . ($inventory_name ? "'$inventory_name'" : "NULL") . ",
            '$uom_shipping',
            $qty_shipping,
            $qty_pack_shipping,
            '$uom_pack_shipping',
            '$uom_detail_shipping',
            $adjustment_shipping,
            " . ($remarks_inventory_shipping ? "'$remarks_inventory_shipping'" : "NULL") . ",
            '" . $_SESSION['username'] . "',
            NOW()
        )";

        if (!mysqli_query($conn, $query_detail)) {
            throw new Exception("Gagal insert detail shipping item ke-" . ($i+1) . ": " . mysqli_error($conn));
        }
        $inserted_count++;
    }

    if ($inserted_count == 0) {
        throw new Exception("Tidak ada item inventory yang valid untuk disimpan!");
    }

    // Commit transaksi
    mysqli_commit($conn);

    $_SESSION['alert'] = '<div class="alert alert-success">Shipping ' . $shipping_no . ' berhasil diupdate! (' . $inserted_count . ' item)</div>';
    
    echo "<script>
        alert('Shipping " . addslashes($shipping_no) . " berhasil diupdate! (" . $inserted_count . " item)');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;

} catch (Exception $e) {
    // Rollback jika ada error
    mysqli_rollback($conn);
    
    $error_message = $e->getMessage();
    
    $_SESSION['alert'] = '<div class="alert alert-danger">Error: ' . $error_message . '</div>';
    
    echo "<script>
        alert('Error: " . addslashes($error_message) . "');
        window.location.href='index.php?page=edit_shipping&id=" . addslashes($shipping_no) . "';
    </script>";
    exit;
}
?>