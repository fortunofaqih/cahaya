<?php
// modul/transaksi/save_shipping.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function cleanInput($data) {
    if (is_array($data)) {
        return array_map('cleanInput', $data);
    }
    return trim((string)$data);
}

function postValue($key, $default = '') {
    return isset($_POST[$key]) ? cleanInput($_POST[$key]) : $default;
}

function postArray($key) {
    return isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : [];
}

function normalizeUom($uom) {
    $uom = strtoupper(trim((string)$uom));
    return $uom === '' ? null : $uom;
}

function parseNumber($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }

    // Support input seperti 1,000.50 atau 1.000,50 atau 1000.50
    $value = str_replace(' ', '', $value);

    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        // Jika koma berada setelah titik, anggap format Indonesia: 1.000,50
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            // Format internasional: 1,000.50
            $value = str_replace(',', '', $value);
        }
    } elseif (strpos($value, ',') !== false) {
        $value = str_replace(',', '.', $value);
    }

    return is_numeric($value) ? (float)$value : 0;
}

function toDbDate($date) {
    $date = trim((string)$date);
    if ($date === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return $date;
    }

    // Format dari flatpickr: 25-Jun-2026 / 25-Mei-2026
    $months = [
        'jan' => '01', 'feb' => '02', 'mar' => '03', 'apr' => '04',
        'mei' => '05', 'may' => '05', 'jun' => '06', 'jul' => '07',
        'agu' => '08', 'aug' => '08', 'sep' => '09', 'okt' => '10',
        'oct' => '10', 'nov' => '11', 'des' => '12', 'dec' => '12'
    ];

    $parts = explode('-', $date);
    if (count($parts) === 3) {
        $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $monthKey = strtolower($parts[1]);
        $year = $parts[2];

        if (isset($months[$monthKey]) && preg_match('/^\d{4}$/', $year)) {
            return $year . '-' . $months[$monthKey] . '-' . $day;
        }
    }

    $timestamp = strtotime($date);
    if ($timestamp) {
        return date('Y-m-d', $timestamp);
    }

    return null;
}

function failAndBack($message) {
    $_SESSION['alert'] = '<div class="alert alert-danger">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    echo "<script>alert('" . addslashes($message) . "'); window.location.href='index.php?page=add_shipping';</script>";
    exit;
}

function successRedirect($message) {
    $_SESSION['alert'] = '<div class="alert alert-success">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
    echo "<script>alert('" . addslashes($message) . "'); window.location.href='index.php?page=shipping';</script>";
    exit;
}

function getSingleValue(mysqli $conn, $sql, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($types !== '' && !empty($params)) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_row($result);
    mysqli_stmt_close($stmt);

    return $row ? $row[0] : null;
}

function loadSalesOrderQtyMap(mysqli $conn, $order_no) {
    $map = [];

    $stmt = mysqli_prepare($conn, "
        SELECT
            inventory_id,
            COALESCE(SUM(quantity), 0) AS order_qty,
            COALESCE(SUM(quantity_pack), 0) AS order_qty_pack
        FROM detail_sales_order
        WHERE order_no = ?
        GROUP BY inventory_id
    ");
    mysqli_stmt_bind_param($stmt, 's', $order_no);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $inventoryId = (string)$row['inventory_id'];
        $map[$inventoryId] = [
            'order_qty' => (float)$row['order_qty'],
            'order_qty_pack' => (float)$row['order_qty_pack'],
        ];
    }

    mysqli_stmt_close($stmt);
    return $map;
}

function loadAlreadyShippedQtyMap(mysqli $conn, $order_no) {
    $map = [];

    $stmt = mysqli_prepare($conn, "
        SELECT
            ds.inventory_id,
            COALESCE(SUM(ds.qty_shipping), 0) AS shipped_qty,
            COALESCE(SUM(ds.qty_pack_shipping), 0) AS shipped_qty_pack
        FROM det_shipping ds
        INNER JOIN hed_shipping hs ON hs.shipping_no = ds.shipping_no
        WHERE hs.order_no = ?
          AND COALESCE(hs.status, '') <> 'Cancelled'
        GROUP BY ds.inventory_id
    ");
    mysqli_stmt_bind_param($stmt, 's', $order_no);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $inventoryId = (string)$row['inventory_id'];
        $map[$inventoryId] = [
            'shipped_qty' => (float)$row['shipped_qty'],
            'shipped_qty_pack' => (float)$row['shipped_qty_pack'],
        ];
    }

    mysqli_stmt_close($stmt);
    return $map;
}

function parseUomDetailJson($json, &$jsonError = '') {
    $jsonError = '';
    $json = trim((string)$json);

    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        $jsonError = 'Format JSON UOM Detail tidak valid.';
        return [];
    }

    // Gabungkan jika UOM yang sama terpilih lebih dari satu kali.
    $merged = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }

        $uom = normalizeUom($item['uom'] ?? '');
        $qty = parseNumber($item['qty'] ?? 0);

        if ($uom === null || $qty <= 0) {
            continue;
        }

        if (!isset($merged[$uom])) {
            $merged[$uom] = 0;
        }
        $merged[$uom] += $qty;
    }

    $details = [];
    foreach ($merged as $uom => $qty) {
        $details[] = [
            'uom' => $uom,
            'qty' => $qty,
        ];
    }

    return $details;
}

try {
    $username = $_SESSION['username'];

    // Header
    $shipping_no = postValue('shipping_no');
    $shipping_date = toDbDate(postValue('shipping_date'));
    $nota_date = toDbDate(postValue('nota_date'));
    $order_no = postValue('order_no');
    $order_date = toDbDate(postValue('order_date'));
    $customer_id = postValue('customer_id');
    $customer_name = postValue('customer_name');
    $customer_address = postValue('customer_address');
    $customer_city = postValue('customer_city');
    $gudang_id = postValue('gudang_id');
    $remarks_shipping = postValue('remarks_shipping');

    // Detail arrays dari add_shipping.php revisi
    $inventory_ids = postArray('inventory_id');
    $inventory_names = postArray('inventory_name');
    $uom_shippings = postArray('uom_shipping');
    $qty_shippings = postArray('qty_shipping');
    $qty_pack_shippings = postArray('qty_pack_shipping');
    $uom_pack_shippings = postArray('uom_pack_shipping');
    $uom_detail_shippings = postArray('uom_detail_shipping');
    $qty_detail_shippings = postArray('qty_detail_shipping');
    $uom_detail_shipping_jsons = postArray('uom_detail_shipping_json');
    $remarks_inventory_shippings = postArray('remarks_inventory_shipping');

    if ($shipping_no === '') {
        failAndBack('Shipping No tidak boleh kosong!');
    }

    if (!$shipping_date) {
        failAndBack('Shipping Date tidak valid atau kosong!');
    }

    if ($order_no === '') {
        failAndBack('Order No tidak boleh kosong!');
    }

    if (count($inventory_ids) === 0) {
        failAndBack('Minimal harus ada 1 item inventory!');
    }

    // Pastikan kolom dan tabel pendukung sudah ada.
    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM det_shipping LIKE 'qty_detail_shipping'");
    if (mysqli_num_rows($colCheck) === 0) {
        failAndBack("Kolom det_shipping.qty_detail_shipping belum ada. Jalankan ALTER TABLE terlebih dahulu.");
    }

    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'det_shipping_uom_detail'");
    if (mysqli_num_rows($tableCheck) === 0) {
        failAndBack("Tabel det_shipping_uom_detail belum ada. Buat tabel detail UOM terlebih dahulu.");
    }

    // Ambil tolerance SO untuk validasi server-side.
    $tolerance = getSingleValue(
        $conn,
        "SELECT COALESCE(tolerance, 10.00) FROM head_sales_order WHERE order_no = ? LIMIT 1",
        's',
        [$order_no]
    );
    $tolerance = $tolerance === null ? 10.00 : (float)$tolerance;
    if ($tolerance < 0) {
        $tolerance = 0;
    }

    $soQtyMap = loadSalesOrderQtyMap($conn, $order_no);
    // Validasi hanya terhadap total input pada shipping yang sedang dibuat.
    // Tidak mengakumulasi shipping sebelumnya, karena kekurangan kirim akan dipantau di report terpisah.
    $currentShippingMap = [];

    mysqli_begin_transaction($conn);

    // Validasi duplicate shipping_no di server side
    $stmtCheck = mysqli_prepare($conn, "SELECT shipping_no FROM hed_shipping WHERE shipping_no = ? LIMIT 1");
    mysqli_stmt_bind_param($stmtCheck, 's', $shipping_no);
    mysqli_stmt_execute($stmtCheck);
    mysqli_stmt_store_result($stmtCheck);

    if (mysqli_stmt_num_rows($stmtCheck) > 0) {
        mysqli_stmt_close($stmtCheck);
        mysqli_rollback($conn);
        failAndBack("Shipping No $shipping_no sudah ada! Gunakan nomor yang berbeda.");
    }
    mysqli_stmt_close($stmtCheck);

    // Insert header. Field transporter, driver, truck, shipment_location tidak diisi karena sudah dihilangkan dari add_shipping.php.
    $stmtHeader = mysqli_prepare($conn, "
        INSERT INTO hed_shipping (
            shipping_no,
            shipping_date,
            order_no,
            order_date,
            customer_id,
            customer_name,
            customer_address,
            customer_city,
            gudang_id,
            remarks_shipping,
            nota_date,
            status,
            approval_status,
            create_user,
            date_created
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Open', 'Pending', ?, NOW())
    ");

    mysqli_stmt_bind_param(
        $stmtHeader,
        'ssssssssssss',
        $shipping_no,
        $shipping_date,
        $order_no,
        $order_date,
        $customer_id,
        $customer_name,
        $customer_address,
        $customer_city,
        $gudang_id,
        $remarks_shipping,
        $nota_date,
        $username
    );
    mysqli_stmt_execute($stmtHeader);
    mysqli_stmt_close($stmtHeader);

    $stmtDetail = mysqli_prepare($conn, "
        INSERT INTO det_shipping (
            shipping_no,
            inventory_id,
            inventory_name,
            uom_shipping,
            qty_shipping,
            qty_pack_shipping,
            uom_pack_shipping,
            uom_detail_shipping,
            qty_detail_shipping,
            adjustment_shipping,
            remarks_inventory_shipping,
            create_user,
            date_created
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())
    ");

    $stmtUomDetail = mysqli_prepare($conn, "
        INSERT INTO det_shipping_uom_detail (
            shipping_no,
            det_shipping_id,
            inventory_id,
            uom_detail,
            qty_detail,
            create_user,
            date_created
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    $inserted_count = 0;
    $total_items = count($inventory_ids);

    for ($i = 0; $i < $total_items; $i++) {
        $inventory_id = isset($inventory_ids[$i]) ? cleanInput($inventory_ids[$i]) : '';
        $inventory_name = isset($inventory_names[$i]) ? cleanInput($inventory_names[$i]) : '';
        $uom_shipping = isset($uom_shippings[$i]) ? normalizeUom($uom_shippings[$i]) : null;
        $qty_shipping = isset($qty_shippings[$i]) ? parseNumber($qty_shippings[$i]) : 0;
        $qty_pack_shipping = isset($qty_pack_shippings[$i]) ? parseNumber($qty_pack_shippings[$i]) : 0;
        $uom_pack_shipping = isset($uom_pack_shippings[$i]) ? normalizeUom($uom_pack_shippings[$i]) : null;
        $uom_detail_shipping = isset($uom_detail_shippings[$i]) ? normalizeUom($uom_detail_shippings[$i]) : null;
        $qty_detail_shipping = isset($qty_detail_shippings[$i]) ? parseNumber($qty_detail_shippings[$i]) : 0;
        $uomDetailJson = isset($uom_detail_shipping_jsons[$i]) ? (string)$uom_detail_shipping_jsons[$i] : '';
        $remarks_inventory_shipping = isset($remarks_inventory_shippings[$i]) ? cleanInput($remarks_inventory_shippings[$i]) : '';

        if ($inventory_id === '') {
            continue;
        }

        $jsonError = '';
        $uomDetailRows = parseUomDetailJson($uomDetailJson, $jsonError);
        if ($jsonError !== '') {
            mysqli_rollback($conn);
            failAndBack('Item ke-' . ($i + 1) . ': ' . $jsonError);
        }

        // Jika ada JSON multi-UOM, jadikan field lama sebagai ringkasan:
        // uom_detail_shipping = UOM pertama, qty_detail_shipping = total Qty Detail.
        if (!empty($uomDetailRows)) {
            $uom_detail_shipping = $uomDetailRows[0]['uom'];
            $qty_detail_shipping = 0;
            foreach ($uomDetailRows as $detailRow) {
                $qty_detail_shipping += (float)$detailRow['qty'];
            }
        }

        if ($qty_shipping <= 0 && $qty_pack_shipping <= 0 && $qty_detail_shipping <= 0) {
            mysqli_rollback($conn);
            failAndBack('Qty item ke-' . ($i + 1) . ' belum diisi. Minimal salah satu Qty harus lebih dari 0.');
        }

        if ($qty_shipping > 0 && $uom_shipping === null) {
            mysqli_rollback($conn);
            failAndBack('UOM item ke-' . ($i + 1) . ' belum dipilih.');
        }

        if ($qty_pack_shipping > 0 && $uom_pack_shipping === null) {
            mysqli_rollback($conn);
            failAndBack('UOM Pack item ke-' . ($i + 1) . ' belum dipilih.');
        }

        if ($qty_detail_shipping > 0 && $uom_detail_shipping === null) {
            mysqli_rollback($conn);
            failAndBack('UOM Detail item ke-' . ($i + 1) . ' belum dipilih.');
        }

        // Validasi tolerance hanya untuk total item pada dokumen shipping yang sedang dibuat.
        // Contoh: order 90 KG tolerance 10% => maksimal input pada dokumen ini 99 KG.
        // Shipping sebelumnya tidak ikut dihitung di sini.
        if (isset($soQtyMap[$inventory_id])) {
            $orderQty = (float)$soQtyMap[$inventory_id]['order_qty'];
            $orderQtyPack = (float)$soQtyMap[$inventory_id]['order_qty_pack'];
            $maxQty = $orderQty + ($orderQty * $tolerance / 100);
            $maxQtyPack = $orderQtyPack + ($orderQtyPack * $tolerance / 100);

            if (!isset($currentShippingMap[$inventory_id])) {
                $currentShippingMap[$inventory_id] = [
                    'qty' => 0,
                    'qty_pack' => 0,
                ];
            }

            $newTotalQty = $currentShippingMap[$inventory_id]['qty'] + $qty_shipping;
            $newTotalQtyPack = $currentShippingMap[$inventory_id]['qty_pack'] + $qty_pack_shipping;

            if ($orderQty > 0 && $newTotalQty > ($maxQty + 0.0001)) {
                mysqli_rollback($conn);
                failAndBack(
                    'Qty shipping inventory ' . $inventory_id . ' melebihi tolerance order. ' .
                    'Order Qty: ' . number_format($orderQty, 2, '.', ',') . ', ' .
                    'Tolerance: ' . number_format($tolerance, 2, '.', ',') . '%, ' .
                    'Maksimal per dokumen shipping: ' . number_format($maxQty, 2, '.', ',') . ', ' .
                    'Total input shipping ini: ' . number_format($newTotalQty, 2, '.', ',') . '.'
                );
            }

            if ($orderQtyPack > 0 && $newTotalQtyPack > ($maxQtyPack + 0.0001)) {
                mysqli_rollback($conn);
                failAndBack(
                    'Qty Pack shipping inventory ' . $inventory_id . ' melebihi tolerance order. ' .
                    'Order Qty Pack: ' . number_format($orderQtyPack, 2, '.', ',') . ', ' .
                    'Tolerance: ' . number_format($tolerance, 2, '.', ',') . '%, ' .
                    'Maksimal per dokumen shipping: ' . number_format($maxQtyPack, 2, '.', ',') . ', ' .
                    'Total input shipping ini: ' . number_format($newTotalQtyPack, 2, '.', ',') . '.'
                );
            }

            $currentShippingMap[$inventory_id]['qty'] = $newTotalQty;
            $currentShippingMap[$inventory_id]['qty_pack'] = $newTotalQtyPack;
        }

        mysqli_stmt_bind_param(
            $stmtDetail,
            'ssssddssdss',
            $shipping_no,
            $inventory_id,
            $inventory_name,
            $uom_shipping,
            $qty_shipping,
            $qty_pack_shipping,
            $uom_pack_shipping,
            $uom_detail_shipping,
            $qty_detail_shipping,
            $remarks_inventory_shipping,
            $username
        );
        mysqli_stmt_execute($stmtDetail);

        $det_shipping_id = mysqli_insert_id($conn);
        if ($det_shipping_id <= 0) {
            // Jika det_shipping.id di database belum AUTO_INCREMENT, detail UOM tetap disimpan
            // dengan det_shipping_id NULL. Relasi utama tetap melalui shipping_no + inventory_id.
            $det_shipping_id = null;
        }

        foreach ($uomDetailRows as $detailRow) {
            $detail_uom = $detailRow['uom'];
            $detail_qty = (float)$detailRow['qty'];

            mysqli_stmt_bind_param(
                $stmtUomDetail,
                'sissds',
                $shipping_no,
                $det_shipping_id,
                $inventory_id,
                $detail_uom,
                $detail_qty,
                $username
            );
            mysqli_stmt_execute($stmtUomDetail);
        }

        $inserted_count++;
    }

    mysqli_stmt_close($stmtDetail);
    mysqli_stmt_close($stmtUomDetail);

    if ($inserted_count === 0) {
        mysqli_rollback($conn);
        failAndBack('Tidak ada item inventory yang valid untuk disimpan!');
    }

    mysqli_commit($conn);
    successRedirect("Shipping $shipping_no berhasil disimpan! ($inserted_count item)");

} catch (Throwable $e) {
    if (isset($conn)) {
        try {
            mysqli_rollback($conn);
        } catch (Throwable $rollbackError) {
            // Abaikan jika transaksi belum aktif.
        }
    }

    error_log('SAVE SHIPPING ERROR: ' . $e->getMessage());
    failAndBack('Error: ' . $e->getMessage());
}
?>
