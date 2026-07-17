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

function cleanInput($data) {
    if (is_array($data)) {
        return array_map('cleanInput', $data);
    }
    return trim((string)$data);
}

function esc($conn, $value) {
    return mysqli_real_escape_string($conn, cleanInput($value));
}

function toDecimal($value) {
    if ($value === null || $value === '') {
        return 0;
    }

    $value = trim((string)$value);

    // Aman untuk format Indonesia sederhana: 1.234,56 atau 1234.56
    if (strpos($value, ',') !== false) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } else {
        $value = str_replace(',', '', $value);
    }

    return (float)$value;
}

function sqlNullableString($conn, $value) {
    $value = cleanInput($value);
    if ($value === '') {
        return 'NULL';
    }
    return "'" . mysqli_real_escape_string($conn, $value) . "'";
}


function normalizeSqlDate($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $formats = ['!Y-m-d', '!d-M-Y', '!d-m-Y'];

    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    $bulanIndonesia = [
        'Jan' => 'Jan', 'Feb' => 'Feb', 'Mar' => 'Mar', 'Apr' => 'Apr',
        'Mei' => 'May', 'Jun' => 'Jun', 'Jul' => 'Jul', 'Agu' => 'Aug',
        'Sep' => 'Sep', 'Okt' => 'Oct', 'Nov' => 'Nov', 'Des' => 'Dec'
    ];

    $parts = explode('-', $value);
    if (count($parts) === 3) {
        $day = trim($parts[0]);
        $month = trim($parts[1]);
        $year = trim($parts[2]);

        if (isset($bulanIndonesia[$month])) {
            $englishDate = $day . '-' . $bulanIndonesia[$month] . '-' . $year;
            $dt = DateTime::createFromFormat('!d-M-Y', $englishDate);

            if ($dt instanceof DateTime) {
                return $dt->format('Y-m-d');
            }
        }
    }

    throw new Exception('Format tanggal tidak valid: ' . $value);
}

function getOrderTolerance($conn, $order_no) {
    $order_no_esc = mysqli_real_escape_string($conn, $order_no);
    $sql = "SELECT COALESCE(tolerance, 10.00) AS tolerance FROM head_sales_order WHERE order_no = '$order_no_esc' LIMIT 1";
    $res = mysqli_query($conn, $sql);
    if ($res && $row = mysqli_fetch_assoc($res)) {
        return (float)$row['tolerance'];
    }
    return 10.00;
}

function getSalesOrderLimits($conn, $order_no) {
    $order_no_esc = mysqli_real_escape_string($conn, $order_no);
    $limits = [];

    $sql = "
        SELECT
            inventory_id,
            inventory_name,
            COALESCE(SUM(quantity), 0) AS order_qty,
            COALESCE(SUM(quantity_pack), 0) AS order_qty_pack,
            MAX(uom) AS order_uom,
            MAX(uom_pack) AS order_uom_pack
        FROM detail_sales_order
        WHERE order_no = '$order_no_esc'
        GROUP BY inventory_id, inventory_name
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        throw new Exception('Gagal membaca detail sales order: ' . mysqli_error($conn));
    }

    while ($row = mysqli_fetch_assoc($res)) {
        $limits[$row['inventory_id']] = [
            'inventory_name' => $row['inventory_name'],
            'order_qty' => (float)$row['order_qty'],
            'order_qty_pack' => (float)$row['order_qty_pack'],
            'order_uom' => $row['order_uom'],
            'order_uom_pack' => $row['order_uom_pack']
        ];
    }

    return $limits;
}

function getPreviouslyShippedExceptCurrent($conn, $order_no, $shipping_no) {
    $order_no_esc = mysqli_real_escape_string($conn, $order_no);
    $shipping_no_esc = mysqli_real_escape_string($conn, $shipping_no);
    $prev = [];

    $sql = "
        SELECT
            d.inventory_id,
            COALESCE(SUM(d.qty_shipping), 0) AS shipped_qty,
            COALESCE(SUM(d.qty_pack_shipping), 0) AS shipped_qty_pack
        FROM det_shipping d
        INNER JOIN hed_shipping h ON h.shipping_no = d.shipping_no
        WHERE h.order_no = '$order_no_esc'
          AND d.shipping_no <> '$shipping_no_esc'
        GROUP BY d.inventory_id
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        throw new Exception('Gagal membaca riwayat shipping sebelumnya: ' . mysqli_error($conn));
    }

    while ($row = mysqli_fetch_assoc($res)) {
        $prev[$row['inventory_id']] = [
            'shipped_qty' => (float)$row['shipped_qty'],
            'shipped_qty_pack' => (float)$row['shipped_qty_pack']
        ];
    }

    return $prev;
}

// Ambil data header dari POST
$shipping_no = isset($_POST['shipping_no']) ? esc($conn, $_POST['shipping_no']) : '';
$old_shipping_no = isset($_POST['old_shipping_no']) ? esc($conn, $_POST['old_shipping_no']) : $shipping_no;
$old_order_no = isset($_POST['old_order_no']) ? esc($conn, $_POST['old_order_no']) : '';
$shipping_date = isset($_POST['shipping_date']) ? normalizeSqlDate($_POST['shipping_date']) : '';
$nota_date = isset($_POST['nota_date']) ? normalizeSqlDate($_POST['nota_date']) : '';
$order_no = isset($_POST['order_no']) ? esc($conn, $_POST['order_no']) : '';
$order_date = isset($_POST['order_date']) ? normalizeSqlDate($_POST['order_date']) : '';
$customer_id = isset($_POST['customer_id']) ? esc($conn, $_POST['customer_id']) : '';
$customer_name = isset($_POST['customer_name']) ? esc($conn, $_POST['customer_name']) : '';
$customer_address = isset($_POST['customer_address']) ? esc($conn, $_POST['customer_address']) : '';
$customer_city = isset($_POST['customer_city']) ? esc($conn, $_POST['customer_city']) : '';
$shipment_location = isset($_POST['shipment_location']) ? esc($conn, $_POST['shipment_location']) : '';
$transporter = isset($_POST['transporter']) ? esc($conn, $_POST['transporter']) : '';
$driver_name = isset($_POST['driver_name']) ? esc($conn, $_POST['driver_name']) : '';
$truck_no = isset($_POST['truck_no']) ? esc($conn, $_POST['truck_no']) : '';
$gudang_id = isset($_POST['gudang_id']) ? esc($conn, $_POST['gudang_id']) : '';
$remarks_shipping = isset($_POST['remarks_shipping']) ? esc($conn, $_POST['remarks_shipping']) : '';

// Data arrays dari detail
$detail_ids = isset($_POST['detail_id']) && is_array($_POST['detail_id']) ? $_POST['detail_id'] : [];
$inventory_ids = isset($_POST['inventory_id']) && is_array($_POST['inventory_id']) ? $_POST['inventory_id'] : [];
$inventory_names = isset($_POST['inventory_name']) && is_array($_POST['inventory_name']) ? $_POST['inventory_name'] : [];
$uom_shippings = isset($_POST['uom_shipping']) && is_array($_POST['uom_shipping']) ? $_POST['uom_shipping'] : [];
$qty_shippings = isset($_POST['qty_shipping']) && is_array($_POST['qty_shipping']) ? $_POST['qty_shipping'] : [];
$qty_pack_shippings = isset($_POST['qty_pack_shipping']) && is_array($_POST['qty_pack_shipping']) ? $_POST['qty_pack_shipping'] : [];
$uom_pack_shippings = isset($_POST['uom_pack_shipping']) && is_array($_POST['uom_pack_shipping']) ? $_POST['uom_pack_shipping'] : [];
$uom_detail_shippings = isset($_POST['uom_detail_shipping']) && is_array($_POST['uom_detail_shipping']) ? $_POST['uom_detail_shipping'] : [];
$qty_detail_shippings = isset($_POST['qty_detail_shipping']) && is_array($_POST['qty_detail_shipping']) ? $_POST['qty_detail_shipping'] : [];
$uom_detail_jsons = isset($_POST['uom_detail_shipping_json']) && is_array($_POST['uom_detail_shipping_json']) ? $_POST['uom_detail_shipping_json'] : [];
$adjustment_shippings = isset($_POST['adjustment_shipping']) && is_array($_POST['adjustment_shipping']) ? $_POST['adjustment_shipping'] : [];
$remarks_inventory_shippings = isset($_POST['remarks_inventory_shipping']) && is_array($_POST['remarks_inventory_shipping']) ? $_POST['remarks_inventory_shipping'] : [];

// Validasi mandatory fields
if ($shipping_no === '') {
    echo "<script>
        alert('Shipping No tidak ditemukan!');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;
}

if ($shipping_date === '') {
    echo "<script>
        alert('Shipping Date tidak boleh kosong!');
        window.location.href='index.php?page=edit_shipping&id=" . addslashes($old_shipping_no) . "';
    </script>";
    exit;
}

if ($order_no === '') {
    echo "<script>
        alert('Order No tidak boleh kosong!');
        window.location.href='index.php?page=edit_shipping&id=" . addslashes($old_shipping_no) . "';
    </script>";
    exit;
}

// Susun item valid dari POST
$items = [];
$total_items = count($inventory_ids);

for ($i = 0; $i < $total_items; $i++) {
    $inventory_id = isset($inventory_ids[$i]) ? cleanInput($inventory_ids[$i]) : '';

    if ($inventory_id === '') {
        continue;
    }

    $inventory_name = isset($inventory_names[$i]) ? cleanInput($inventory_names[$i]) : '';
    $uom_shipping = isset($uom_shippings[$i]) ? cleanInput($uom_shippings[$i]) : '';
    $qty_shipping = isset($qty_shippings[$i]) ? toDecimal($qty_shippings[$i]) : 0;
    $qty_pack_shipping = isset($qty_pack_shippings[$i]) ? toDecimal($qty_pack_shippings[$i]) : 0;
    $uom_pack_shipping = isset($uom_pack_shippings[$i]) ? cleanInput($uom_pack_shippings[$i]) : '';
    $uom_detail_shipping = isset($uom_detail_shippings[$i]) ? cleanInput($uom_detail_shippings[$i]) : '';
    $qty_detail_shipping = isset($qty_detail_shippings[$i]) ? toDecimal($qty_detail_shippings[$i]) : 0;
    $uom_detail_json = isset($uom_detail_jsons[$i]) ? cleanInput($uom_detail_jsons[$i]) : '[]';
    $adjustment_shipping = isset($adjustment_shippings[$i]) ? toDecimal($adjustment_shippings[$i]) : 0;
    $remarks_inventory_shipping = isset($remarks_inventory_shippings[$i]) ? cleanInput($remarks_inventory_shippings[$i]) : '';

    // Jika UOM belum dipilih dari edit page, simpan NULL, bukan memaksa KG.
    if ($uom_shipping === '-- Pilih UoM --') {
        $uom_shipping = '';
    }
    if ($uom_pack_shipping === '-- Pilih UoM --') {
        $uom_pack_shipping = '';
    }
    if ($uom_detail_shipping === '-- Pilih UoM Detail --') {
        $uom_detail_shipping = '';
    }

    // Normalisasi JSON detail UOM.
    $decoded_uom_details = json_decode($uom_detail_json, true);
    if (!is_array($decoded_uom_details)) {
        $decoded_uom_details = [];
    }

    $clean_uom_details = [];
    $first_uom_detail = '';
    $total_qty_detail = 0;

    foreach ($decoded_uom_details as $detail) {
        $detail_uom = isset($detail['uom']) ? cleanInput($detail['uom']) : '';
        $detail_qty = isset($detail['qty']) ? toDecimal($detail['qty']) : 0;

        if ($detail_uom === '' || $detail_qty <= 0) {
            continue;
        }

        if ($first_uom_detail === '') {
            $first_uom_detail = $detail_uom;
        }

        $total_qty_detail += $detail_qty;
        $clean_uom_details[] = [
            'uom' => $detail_uom,
            'qty' => $detail_qty
        ];
    }

    // Fallback jika edit lama belum mengirim JSON.
    if (empty($clean_uom_details) && $uom_detail_shipping !== '' && $qty_detail_shipping > 0) {
        $first_uom_detail = $uom_detail_shipping;
        $total_qty_detail = $qty_detail_shipping;
        $clean_uom_details[] = [
            'uom' => $uom_detail_shipping,
            'qty' => $qty_detail_shipping
        ];
    }

    // Jika JSON ada, kolom lama di det_shipping mengikuti ringkasan dari JSON.
    if (!empty($clean_uom_details)) {
        $uom_detail_shipping = $first_uom_detail;
        $qty_detail_shipping = $total_qty_detail;
    }

    $items[] = [
        'inventory_id' => $inventory_id,
        'inventory_name' => $inventory_name,
        'uom_shipping' => $uom_shipping,
        'qty_shipping' => $qty_shipping,
        'qty_pack_shipping' => $qty_pack_shipping,
        'uom_pack_shipping' => $uom_pack_shipping,
        'uom_detail_shipping' => $uom_detail_shipping,
        'qty_detail_shipping' => $qty_detail_shipping,
        'uom_details' => $clean_uom_details,
        'adjustment_shipping' => $adjustment_shipping,
        'remarks_inventory_shipping' => $remarks_inventory_shipping
    ];
}

if (count($items) === 0) {
    echo "<script>
        alert('Minimal harus ada 1 item inventory yang valid!');
        window.location.href='index.php?page=edit_shipping&id=" . addslashes($old_shipping_no) . "';
    </script>";
    exit;
}

mysqli_begin_transaction($conn);

try {
    $username = isset($_SESSION['username']) ? mysqli_real_escape_string($conn, $_SESSION['username']) : '';

    $old_shipping_no_esc = mysqli_real_escape_string($conn, $old_shipping_no);
    $shipping_no_esc = mysqli_real_escape_string($conn, $shipping_no);

    $check_current = mysqli_query($conn, "SELECT shipping_no FROM hed_shipping WHERE shipping_no = '$old_shipping_no_esc' LIMIT 1 FOR UPDATE");
    if (!$check_current) {
        throw new Exception('Gagal memeriksa Shipping No lama: ' . mysqli_error($conn));
    }
    if (mysqli_num_rows($check_current) === 0) {
        throw new Exception("Shipping No lama $old_shipping_no tidak ditemukan.");
    }

    if (strcasecmp($shipping_no, $old_shipping_no) !== 0) {
        $check_duplicate = mysqli_query($conn, "SELECT shipping_no FROM hed_shipping WHERE shipping_no = '$shipping_no_esc' LIMIT 1");
        if (!$check_duplicate) {
            throw new Exception('Gagal memeriksa duplikat Shipping No: ' . mysqli_error($conn));
        }
        if (mysqli_num_rows($check_duplicate) > 0) {
            throw new Exception("Shipping No $shipping_no sudah digunakan.");
        }

        $check_invoice = mysqli_query($conn, "SELECT invoice_no FROM det_invoice WHERE shipping_no = '$old_shipping_no_esc' LIMIT 1");
        if (!$check_invoice) {
            throw new Exception('Gagal memeriksa invoice terkait: ' . mysqli_error($conn));
        }
        if (mysqli_num_rows($check_invoice) > 0) {
            $invoice_row = mysqli_fetch_assoc($check_invoice);
            $invoice_ref = $invoice_row['invoice_no'] ?? '';
            throw new Exception("Shipping No tidak dapat diubah karena sudah digunakan pada Invoice $invoice_ref.");
        }
    }

    // Validasi tolerance server-side.
    // Validasi hanya terhadap total input pada dokumen shipping yang sedang diedit.
    // Tidak mengakumulasi shipping lain; kekurangan/riwayat kirim akan ditangani di report terpisah.
    $tolerance = getOrderTolerance($conn, $order_no);
    $so_limits = getSalesOrderLimits($conn, $order_no);

    $current_by_inventory = [];
    foreach ($items as $item) {
        $inv = $item['inventory_id'];
        if (!isset($current_by_inventory[$inv])) {
            $current_by_inventory[$inv] = [
                'inventory_name' => $item['inventory_name'],
                'qty_shipping' => 0,
                'qty_pack_shipping' => 0
            ];
        }
        $current_by_inventory[$inv]['qty_shipping'] += $item['qty_shipping'];
        $current_by_inventory[$inv]['qty_pack_shipping'] += $item['qty_pack_shipping'];
    }

    foreach ($current_by_inventory as $inv => $cur) {
        if (!isset($so_limits[$inv])) {
            throw new Exception("Inventory $inv tidak ditemukan pada Sales Order $order_no.");
        }

        $order_qty = (float)$so_limits[$inv]['order_qty'];
        $order_qty_pack = (float)$so_limits[$inv]['order_qty_pack'];

        $max_qty = $order_qty + ($order_qty * $tolerance / 100);
        $max_qty_pack = $order_qty_pack + ($order_qty_pack * $tolerance / 100);

        $total_qty_on_this_shipping = (float)$cur['qty_shipping'];
        $total_qty_pack_on_this_shipping = (float)$cur['qty_pack_shipping'];

        if ($order_qty > 0 && $total_qty_on_this_shipping > ($max_qty + 0.0001)) {
            throw new Exception(
                "Qty shipping inventory $inv melebihi tolerance order. " .
                "Order: " . number_format($order_qty, 2) . ", Tolerance: " . number_format($tolerance, 2) . "%, " .
                "Maksimal per dokumen shipping: " . number_format($max_qty, 2) . ", " .
                "Total input edit ini: " . number_format($total_qty_on_this_shipping, 2) . "."
            );
        }

        if ($order_qty_pack > 0 && $total_qty_pack_on_this_shipping > ($max_qty_pack + 0.0001)) {
            throw new Exception(
                "Qty Pack shipping inventory $inv melebihi tolerance order. " .
                "Order Pack: " . number_format($order_qty_pack, 2) . ", Tolerance: " . number_format($tolerance, 2) . "%, " .
                "Maksimal per dokumen shipping: " . number_format($max_qty_pack, 2) . ", " .
                "Total input edit ini: " . number_format($total_qty_pack_on_this_shipping, 2) . "."
            );
        }
    }

    // 1. UPDATE hed_shipping
    $query_header = "UPDATE hed_shipping SET
        shipping_no = " . sqlNullableString($conn, $shipping_no) . ",
        shipping_date = " . sqlNullableString($conn, $shipping_date) . ",
        order_no = " . sqlNullableString($conn, $order_no) . ",
        order_date = " . sqlNullableString($conn, $order_date) . ",
        customer_id = " . sqlNullableString($conn, $customer_id) . ",
        customer_name = " . sqlNullableString($conn, $customer_name) . ",
        customer_address = " . sqlNullableString($conn, $customer_address) . ",
        customer_city = " . sqlNullableString($conn, $customer_city) . ",
        shipment_location = " . sqlNullableString($conn, $shipment_location) . ",
        transporter = " . sqlNullableString($conn, $transporter) . ",
        driver_name = " . sqlNullableString($conn, $driver_name) . ",
        truck_no = " . sqlNullableString($conn, $truck_no) . ",
        gudang_id = " . sqlNullableString($conn, $gudang_id) . ",
        remarks_shipping = " . sqlNullableString($conn, $remarks_shipping) . ",
        nota_date = " . sqlNullableString($conn, $nota_date) . ",
        user_modified = " . sqlNullableString($conn, $username) . ",
        date_modified = NOW()
    WHERE shipping_no = '" . mysqli_real_escape_string($conn, $old_shipping_no) . "'";

    if (!mysqli_query($conn, $query_header)) {
        throw new Exception('Gagal update header shipping: ' . mysqli_error($conn));
    }

    // 2. Hapus detail UOM lama dulu, baru hapus detail utama.
    $old_shipping_no_esc = mysqli_real_escape_string($conn, $old_shipping_no);
    $shipping_no_esc = mysqli_real_escape_string($conn, $shipping_no);

    $query_delete_uom = "DELETE FROM det_shipping_uom_detail WHERE shipping_no = '$old_shipping_no_esc'";
    if (!mysqli_query($conn, $query_delete_uom)) {
        throw new Exception('Gagal menghapus detail UOM shipping lama: ' . mysqli_error($conn));
    }

    $query_delete = "DELETE FROM det_shipping WHERE shipping_no = '$old_shipping_no_esc'";
    if (!mysqli_query($conn, $query_delete)) {
        throw new Exception('Gagal menghapus detail shipping lama: ' . mysqli_error($conn));
    }

    // 3. Insert ulang semua detail.
    $inserted_count = 0;

    foreach ($items as $idx => $item) {
        $inventory_id = mysqli_real_escape_string($conn, $item['inventory_id']);
        $inventory_name = mysqli_real_escape_string($conn, $item['inventory_name']);
        $uom_shipping = mysqli_real_escape_string($conn, $item['uom_shipping']);
        $qty_shipping = (float)$item['qty_shipping'];
        $qty_pack_shipping = (float)$item['qty_pack_shipping'];
        $uom_pack_shipping = mysqli_real_escape_string($conn, $item['uom_pack_shipping']);
        $uom_detail_shipping = mysqli_real_escape_string($conn, $item['uom_detail_shipping']);
        $qty_detail_shipping = (float)$item['qty_detail_shipping'];
        $adjustment_shipping = (float)$item['adjustment_shipping'];
        $remarks_inventory_shipping = mysqli_real_escape_string($conn, $item['remarks_inventory_shipping']);

        $query_detail = "INSERT INTO det_shipping (
            shipping_no,
            inventory_id,
            inventory_name,
            adjustment_shipping,
            qty_shipping,
            uom_shipping,
            qty_pack_shipping,
            uom_pack_shipping,
            uom_detail_shipping,
            qty_detail_shipping,
            remarks_inventory_shipping,
            create_user,
            date_created,
            user_modified,
            date_modified
        ) VALUES (
            '$shipping_no_esc',
            '$inventory_id',
            " . sqlNullableString($conn, $inventory_name) . ",
            $adjustment_shipping,
            $qty_shipping,
            " . sqlNullableString($conn, $uom_shipping) . ",
            $qty_pack_shipping,
            " . sqlNullableString($conn, $uom_pack_shipping) . ",
            " . sqlNullableString($conn, $uom_detail_shipping) . ",
            $qty_detail_shipping,
            " . sqlNullableString($conn, $remarks_inventory_shipping) . ",
            " . sqlNullableString($conn, $username) . ",
            NOW(),
            " . sqlNullableString($conn, $username) . ",
            NOW()
        )";

        if (!mysqli_query($conn, $query_detail)) {
            throw new Exception('Gagal insert detail shipping item ke-' . ($idx + 1) . ': ' . mysqli_error($conn));
        }

        $det_shipping_id = mysqli_insert_id($conn);

        // 4. Insert detail multi UOM ke tabel baru.
        foreach ($item['uom_details'] as $detail) {
            $detail_uom = mysqli_real_escape_string($conn, $detail['uom']);
            $detail_qty = (float)$detail['qty'];

            if ($detail_uom === '' || $detail_qty <= 0) {
                continue;
            }

            $query_uom_detail = "INSERT INTO det_shipping_uom_detail (
                shipping_no,
                det_shipping_id,
                inventory_id,
                uom_detail,
                qty_detail,
                create_user,
                date_created
            ) VALUES (
                '$shipping_no_esc',
                $det_shipping_id,
                '$inventory_id',
                '$detail_uom',
                $detail_qty,
                " . sqlNullableString($conn, $username) . ",
                NOW()
            )";

            if (!mysqli_query($conn, $query_uom_detail)) {
                throw new Exception('Gagal insert UOM Detail shipping item ke-' . ($idx + 1) . ': ' . mysqli_error($conn));
            }
        }

        $inserted_count++;
    }

    if ($inserted_count === 0) {
        throw new Exception('Tidak ada item inventory yang valid untuk disimpan!');
    }

    mysqli_commit($conn);

    $_SESSION['alert'] = '<div class="alert alert-success">Shipping ' . htmlspecialchars($shipping_no) . ' berhasil diupdate! (' . $inserted_count . ' item)</div>';

    echo "<script>
        alert('Shipping " . addslashes($shipping_no) . " berhasil diupdate! (" . $inserted_count . " item)');
        window.location.href='index.php?page=shipping';
    </script>";
    exit;

} catch (Exception $e) {
    mysqli_rollback($conn);

    $error_message = $e->getMessage();
    $_SESSION['alert'] = '<div class="alert alert-danger">Error: ' . htmlspecialchars($error_message) . '</div>';

    echo "<script>
        alert('Error: " . addslashes($error_message) . "');
        window.location.href='index.php?page=edit_shipping&id=" . addslashes($old_shipping_no) . "';
    </script>";
    exit;
}
?>
