<?php
// modul/transaksi/ajax_return_shipping_detail.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

header('Content-Type: application/json; charset=utf-8');

function sendReturnJson(array $data, int $statusCode = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    if (!isset($_SESSION['username'])) {
        sendReturnJson([
            'success' => false,
            'message' => 'Session login telah berakhir. Silakan login kembali.'
        ], 401);
    }

    require_once __DIR__ . '/../../koneksi.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException('Koneksi database $conn tidak ditemukan.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    mysqli_set_charset($conn, 'utf8mb4');

    $shippingNo = trim((string)($_GET['shipping_no'] ?? ''));
    $invoiceNo  = trim((string)($_GET['invoice_no'] ?? ''));

    if ($shippingNo === '' || $invoiceNo === '') {
        sendReturnJson([
            'success' => false,
            'message' => 'Shipping No. dan Invoice No. wajib diisi.'
        ], 422);
    }

    $headSql = "
        SELECT
            hi.invoice_no,
            hi.invoice_date,
            hi.order_no,
            hi.order_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            hi.currency,
            hi.subtotal,
            hi.grand_total,
            hi.down_payment,
            hi.titip_applied,
            hi.payment_balance,
            hi.piutang,
            hs.shipping_no,
            hs.shipping_date
        FROM det_invoice di
        INNER JOIN head_invoice hi
            ON hi.invoice_no = di.invoice_no
        INNER JOIN hed_shipping hs
            ON hs.shipping_no = di.shipping_no
        WHERE di.invoice_no = ?
          AND di.shipping_no = ?
          AND COALESCE(hi.status, 'Open') <> 'Cancelled'
        LIMIT 1
    ";

    $headStmt = mysqli_prepare($conn, $headSql);
    mysqli_stmt_bind_param($headStmt, 'ss', $invoiceNo, $shippingNo);
    mysqli_stmt_execute($headStmt);
    $headResult = mysqli_stmt_get_result($headStmt);
    $header = $headResult ? mysqli_fetch_assoc($headResult) : null;
    mysqli_stmt_close($headStmt);

    if (!$header) {
        sendReturnJson([
            'success' => false,
            'message' => 'Relasi Invoice dan Shipping tidak valid atau invoice sudah dibatalkan.'
        ], 404);
    }

    /*
     * Harga diambil dari detail_sales_order karena det_shipping.price_unit
     * pada data existing dapat bernilai 0.
     *
     * Subquery MIN(id) digunakan agar satu baris det_shipping tidak menjadi
     * ganda jika inventory yang sama muncul lebih dari sekali pada SO.
     */
    $detailSql = "
        SELECT
            ds.id AS shipping_detail_id,
            ds.inventory_id,
            ds.inventory_name,

            COALESCE(ds.qty_shipping, 0) AS original_quantity,
            ds.uom_shipping AS original_uom,

            COALESCE(ds.qty_pack_shipping, 0) AS original_quantity_pack,
            ds.uom_pack_shipping AS original_uom_pack,

            COALESCE(ds.qty_detail_shipping, 0) AS original_quantity_detail,
            ds.uom_detail_shipping AS original_uom_detail,

            COALESCE(dso.price_unit, 0) AS price_unit,
            COALESCE(dso.price, 0) AS price,
            COALESCE(dso.subtotal, 0) AS original_subtotal,

            COALESCE(retur.returned_qty, 0) AS returned_qty,
            COALESCE(retur.returned_pack, 0) AS returned_pack,
            COALESCE(retur.returned_detail, 0) AS returned_detail

        FROM det_shipping ds

        INNER JOIN hed_shipping hs
            ON hs.shipping_no = ds.shipping_no

        LEFT JOIN detail_sales_order dso
            ON dso.id = (
                SELECT MIN(dso_pick.id)
                FROM detail_sales_order dso_pick
                WHERE dso_pick.order_no = hs.order_no
                  AND dso_pick.inventory_id = ds.inventory_id
            )

        LEFT JOIN (
            SELECT
                d.shipping_detail_id,
                SUM(COALESCE(d.return_quantity, 0)) AS returned_qty,
                SUM(COALESCE(d.return_quantity_pack, 0)) AS returned_pack,
                SUM(COALESCE(d.return_quantity_detail, 0)) AS returned_detail
            FROM detail_retur_invoice d
            INNER JOIN head_retur_invoice h
                ON h.return_id = d.return_id
            WHERE h.shipping_no = ?
              AND h.invoice_no = ?
              AND COALESCE(h.status, 'Open') <> 'Cancelled'
            GROUP BY d.shipping_detail_id
        ) retur
            ON retur.shipping_detail_id = ds.id

        WHERE ds.shipping_no = ?
        ORDER BY ds.id
    ";

    $detailStmt = mysqli_prepare($conn, $detailSql);
    mysqli_stmt_bind_param(
        $detailStmt,
        'sss',
        $shippingNo,
        $invoiceNo,
        $shippingNo
    );
    mysqli_stmt_execute($detailStmt);
    $detailResult = mysqli_stmt_get_result($detailStmt);

    $details = [];

    while ($row = mysqli_fetch_assoc($detailResult)) {
        $originalQty    = (float)($row['original_quantity'] ?? 0);
        $originalPack   = (float)($row['original_quantity_pack'] ?? 0);
        $originalDetail = (float)($row['original_quantity_detail'] ?? 0);

        $returnedQty    = (float)($row['returned_qty'] ?? 0);
        $returnedPack   = (float)($row['returned_pack'] ?? 0);
        $returnedDetail = (float)($row['returned_detail'] ?? 0);

        $remainingQty    = max(0, $originalQty - $returnedQty);
        $remainingPack   = max(0, $originalPack - $returnedPack);
        $remainingDetail = max(0, $originalDetail - $returnedDetail);

        $packConversionValue = $originalPack > 0
            ? $originalQty / $originalPack
            : 0;

        $details[] = [
            'shipping_detail_id'        => (int)($row['shipping_detail_id'] ?? 0),
            'inventory_id'              => (string)($row['inventory_id'] ?? ''),
            'inventory_name'            => (string)($row['inventory_name'] ?? ''),

            'original_quantity'         => $originalQty,
            'original_uom'              => (string)($row['original_uom'] ?? ''),

            'original_quantity_pack'    => $originalPack,
            'original_uom_pack'         => (string)($row['original_uom_pack'] ?? ''),

            'original_quantity_detail'  => $originalDetail,
            'original_uom_detail'       => (string)($row['original_uom_detail'] ?? ''),

            'price_unit'                => (float)($row['price_unit'] ?? 0),
            'price'                     => (float)($row['price'] ?? 0),
            'original_subtotal'         => (float)($row['original_subtotal'] ?? 0),

            'returned_qty'              => $returnedQty,
            'returned_pack'             => $returnedPack,
            'returned_detail'           => $returnedDetail,

            'remaining_quantity'        => $remainingQty,
            'remaining_quantity_pack'   => $remainingPack,
            'remaining_quantity_detail' => $remainingDetail,

            'pack_conversion_value'     => $packConversionValue
        ];
    }

    mysqli_stmt_close($detailStmt);

    sendReturnJson([
        'success' => true,
        'header' => [
            'invoice_no'       => (string)($header['invoice_no'] ?? ''),
            'invoice_date'     => (string)($header['invoice_date'] ?? ''),
            'order_no'         => (string)($header['order_no'] ?? ''),
            'order_date'       => (string)($header['order_date'] ?? ''),
            'customer_id'      => (string)($header['customer_id'] ?? ''),
            'customer_name'    => (string)($header['customer_name'] ?? ''),
            'customer_address' => (string)($header['customer_address'] ?? ''),
            'customer_city'    => (string)($header['customer_city'] ?? ''),
            'currency'         => (string)($header['currency'] ?? 'IDR'),
            'subtotal'         => (float)($header['subtotal'] ?? 0),
            'grand_total'      => (float)($header['grand_total'] ?? 0),
            'down_payment'     => (float)($header['down_payment'] ?? 0),
            'titip_applied'    => (float)($header['titip_applied'] ?? 0),
            'payment_balance'  => (float)($header['payment_balance'] ?? 0),
            'piutang'          => (float)($header['piutang'] ?? 0),
            'shipping_no'      => (string)($header['shipping_no'] ?? ''),
            'shipping_date'    => (string)($header['shipping_date'] ?? '')
        ],
        'details' => $details
    ]);
} catch (Throwable $e) {
    sendReturnJson([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], 500);
}
