<?php
// modul/transaksi/ajax_invoice.php
// Revisi flow invoice:
// - Shipping/SJ mengambil qty dari det_shipping
// - Harga mengambil dari detail_sales_order berdasarkan order_no + inventory_id
// - Subtotal invoice per item = qty_pack_shipping * harga SO

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/ajax_invoice_error.log');

function sendJson($payload, $httpCode = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['username'])) {
    sendJson(['status' => 'error', 'message' => 'Session expired'], 401);
}

include __DIR__ . '/../../koneksi.php';

if (!isset($conn) || !$conn) {
    sendJson(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

$ajax = $_GET['ajax'] ?? '';

function tableExists($conn, $tableName) {
    $stmt = mysqli_prepare($conn, "SHOW TABLES LIKE ?");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 's', $tableName);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $exists = $res && mysqli_num_rows($res) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

function getDefaultGudang() {
    return [
        'warehouse_id' => 'FC-02',
        'warehouse_name' => 'GUDANG BARANG JADI 1',
        'gudang_id' => 'FC-02',
        'gudang_name' => 'GUDANG BARANG JADI 1'
    ];
}

function getShippingItemsForInvoice($conn, $shippingNo) {
    $sql = "
        SELECT
            hs.shipping_no,
            hs.order_no,
            ds.inventory_id,
            ds.inventory_name,
            ds.qty_shipping,
            ds.uom_shipping,
            ds.qty_pack_shipping,
            ds.uom_pack_shipping,
            ds.qty_detail_shipping,
            ds.uom_detail_shipping,
            ds.remarks_inventory_shipping,
            ds.note,

            dso.quantity AS so_quantity,
            dso.uom AS so_uom,
            dso.quantity_pack AS so_quantity_pack,
            dso.uom_pack AS so_uom_pack,
            dso.quantity_detail AS so_quantity_detail,
            dso.uom_detail AS so_uom_detail,
            dso.price_unit AS so_price_unit,
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
        LEFT JOIN detail_sales_order dso
               ON dso.order_no = hs.order_no
              AND dso.inventory_id = ds.inventory_id
        WHERE ds.shipping_no = ?
        ORDER BY ds.id ASC
    ";

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new Exception('Gagal prepare detail shipping: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt, 's', $shippingNo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $items = [];
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) {
            $qty = (float)($r['qty_shipping'] ?? 0);
            $qtyPack = (float)($r['qty_pack_shipping'] ?? 0);
            $price = (float)($r['invoice_price'] ?? 0);
            $subtotal = (float)($r['invoice_subtotal'] ?? 0);

            $items[] = [
                'shipping_no' => $r['shipping_no'],
                'order_no' => $r['order_no'],
                'inventory_id' => $r['inventory_id'] ?? '',
                'inventory_name' => $r['inventory_name'] ?? '',

                // Nama standar untuk add_invoice expand
                'qty' => $qty,
                'quantity' => $qty,
                'uom' => $r['uom_shipping'] ?: ($r['so_uom'] ?? ''),
                'qty_pack' => $qtyPack,
                'quantity_pack' => $qtyPack,
                'uom_pack' => $r['uom_pack_shipping'] ?: ($r['so_uom_pack'] ?? ''),
                'uom_detail' => $r['uom_detail_shipping'] ?: ($r['so_uom_detail'] ?? ''),
                'qty_detail' => (float)($r['qty_detail_shipping'] ?? 0),

                // Nama spesifik shipping supaya jelas
                'qty_shipping' => $qty,
                'uom_shipping' => $r['uom_shipping'] ?? '',
                'qty_pack_shipping' => $qtyPack,
                'uom_pack_shipping' => $r['uom_pack_shipping'] ?? '',
                'qty_detail_shipping' => (float)($r['qty_detail_shipping'] ?? 0),
                'uom_detail_shipping' => $r['uom_detail_shipping'] ?? '',

                // Harga dari SO
                'price' => $price,
                'unit_price' => $price,
                'price_unit' => (float)($r['so_price_unit'] ?? 0),
                'so_price' => (float)($r['so_price'] ?? 0),
                'subtotal' => $subtotal,
                'sub_total' => $subtotal,
                'amount' => $subtotal,

                'so_quantity' => (float)($r['so_quantity'] ?? 0),
                'so_quantity_pack' => (float)($r['so_quantity_pack'] ?? 0),
                'so_subtotal' => (float)($r['so_subtotal'] ?? 0),
                'remarks_inventory_shipping' => $r['remarks_inventory_shipping'] ?? '',
                'note' => $r['note'] ?? ''
            ];
        }
    }

    mysqli_stmt_close($stmt);
    return $items;
}

function sumItemsSubtotal($items) {
    $total = 0;
    foreach ($items as $it) {
        $total += (float)($it['subtotal'] ?? 0);
    }
    return $total;
}

if ($ajax === 'get_customer_orders') {
    $customerId = trim($_GET['customer_id'] ?? '');
    if ($customerId === '') {
        sendJson(['status' => 'error', 'message' => 'Customer ID kosong'], 400);
    }

    $sql = "
        SELECT DISTINCT
            hso.order_no,
            hso.order_date,
            hso.payment_type,
            hso.payment_term,
            hso.days,
            hso.currency,
            hso.station,
            hso.grand_total,
            hso.down_payment,
            hso.remarks
        FROM head_sales_order hso
        INNER JOIN hed_shipping hs ON hs.order_no = hso.order_no
        LEFT JOIN det_invoice di ON di.shipping_no = hs.shipping_no
        WHERE hso.customer_id = ?
          AND di.shipping_no IS NULL
        ORDER BY hso.order_date DESC, hso.order_no DESC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) sendJson(['status'=>'error','message'=>'Gagal prepare order: '.mysqli_error($conn)], 500);
    mysqli_stmt_bind_param($stmt, 's', $customerId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $data = [];
    while ($r = mysqli_fetch_assoc($res)) {
        $data[] = [
            'order_no' => $r['order_no'],
            'order_date' => $r['order_date'],
            'payment_type' => $r['payment_type'],
            'payment_term' => $r['payment_term'],
            'days' => (int)($r['days'] ?? 30),
            'currency' => $r['currency'] ?: 'IDR',
            'station' => $r['station'] ?: 'Factory',
            'grand_total' => (float)($r['grand_total'] ?? 0),
            'down_payment' => (float)($r['down_payment'] ?? 0),
            'remarks' => $r['remarks'] ?? ''
        ];
    }
    mysqli_stmt_close($stmt);
    sendJson(['status'=>'success','data'=>$data]);
}

if ($ajax === 'get_order_shippings') {
    $orderNo = trim($_GET['order_no'] ?? '');
    if ($orderNo === '') {
        sendJson(['status'=>'error','message'=>'Order No kosong'], 400);
    }

    $sql = "
        SELECT
            hs.shipping_no,
            hs.shipping_date,
            hs.order_no,
            hs.gudang_id,
            COALESCE(mg.name, 'GUDANG BARANG JADI 1') AS warehouse_name,
            hs.remarks_shipping
        FROM hed_shipping hs
        LEFT JOIN m_gudang mg ON mg.gudang_id = hs.gudang_id
        LEFT JOIN det_invoice di ON di.shipping_no = hs.shipping_no
        WHERE hs.order_no = ?
          AND di.shipping_no IS NULL
        ORDER BY hs.shipping_date ASC, hs.shipping_no ASC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) sendJson(['status'=>'error','message'=>'Gagal prepare shipping: '.mysqli_error($conn)], 500);
    mysqli_stmt_bind_param($stmt, 's', $orderNo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $data = [];
    while ($r = mysqli_fetch_assoc($res)) {
        try {
            $items = getShippingItemsForInvoice($conn, $r['shipping_no']);
        } catch (Exception $e) {
            mysqli_stmt_close($stmt);
            sendJson(['status'=>'error','message'=>$e->getMessage()], 500);
        }

        $subtotal = sumItemsSubtotal($items);
        $gudangId = $r['gudang_id'] ?: 'FC-02';
        $warehouseName = $r['warehouse_name'] ?: 'GUDANG BARANG JADI 1';

        $data[] = [
            'shipping_no' => $r['shipping_no'],
            'shipping_date' => $r['shipping_date'],
            'order_no' => $r['order_no'],
            'warehouse_id' => $gudangId,
            'warehouse_name' => $warehouseName,
            'gudang_id' => $gudangId,
            'gudang_name' => $warehouseName,
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'remarks_shipping' => $r['remarks_shipping'] ?? '',
            'items' => $items,
            'details' => $items
        ];
    }
    mysqli_stmt_close($stmt);
    sendJson(['status'=>'success','data'=>$data]);
}

if ($ajax === 'get_shipping_details') {
    $shippingNo = trim($_GET['shipping_no'] ?? '');
    if ($shippingNo === '') {
        sendJson(['status'=>'error','message'=>'Shipping No kosong'], 400);
    }

    try {
        $items = getShippingItemsForInvoice($conn, $shippingNo);
    } catch (Exception $e) {
        sendJson(['status'=>'error','message'=>$e->getMessage()], 500);
    }

    sendJson([
        'status' => 'success',
        'shipping_no' => $shippingNo,
        'subtotal' => sumItemsSubtotal($items),
        'total' => sumItemsSubtotal($items),
        'data' => $items,
        'items' => $items,
        'details' => $items
    ]);
}

if ($ajax === 'get_customer_deposit') {
    $customerId = trim($_GET['customer_id'] ?? '');
    if ($customerId === '') {
        sendJson(['status'=>'success','balance'=>0,'data'=>[]]);
    }

    if (!tableExists($conn, 'customer_deposit')) {
        sendJson(['status'=>'success','balance'=>0,'data'=>[]]);
    }

    $sql = "
        SELECT deposit_id, deposit_no, deposit_date, balance_amount, payment_method
        FROM customer_deposit
        WHERE customer_id = ?
          AND status = 'Open'
          AND balance_amount > 0
        ORDER BY deposit_date ASC, deposit_id ASC
    ";
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) sendJson(['status'=>'error','message'=>'Gagal prepare titip: '.mysqli_error($conn)], 500);
    mysqli_stmt_bind_param($stmt, 's', $customerId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $data = [];
    $balance = 0;
    while ($r = mysqli_fetch_assoc($res)) {
        $amount = (float)$r['balance_amount'];
        $balance += $amount;
        $data[] = [
            'deposit_id' => (int)$r['deposit_id'],
            'deposit_no' => $r['deposit_no'],
            'deposit_date' => $r['deposit_date'],
            'balance_amount' => $amount,
            'payment_method' => $r['payment_method']
        ];
    }
    mysqli_stmt_close($stmt);
    sendJson(['status'=>'success','balance'=>$balance,'data'=>$data]);
}

sendJson(['status'=>'error','message'=>'Invalid AJAX request'], 400);
