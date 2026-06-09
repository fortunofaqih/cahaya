<?php
// modul/transaksi/get_customer_items.php

if (!isset($_SESSION['username'])) {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}

include __DIR__ . '/../../koneksi.php';

$customer_id = mysqli_real_escape_string($conn, $_GET['customer_id'] ?? '');
$data = [];

if ($customer_id) {
    /*
     * Ambil item DISTINCT dari order sebelumnya milik customer ini.
     * Dikelompokkan berdasarkan inventory_id + inventory_name supaya
     * tidak duplikat item yang sama dari banyak order.
     * Jika inventory_id kosong, fallback ke inventory_name saja.
     * Limit 50 item terbaru.
     */
    $query = mysqli_query($conn, "
        SELECT
            d.inventory_id,
            d.inventory_name,
            d.uom,
            d.quantity_pack,
            d.uom_pack,
            d.uom_detail,
            d.remarks
        FROM detail_sales_order d
        INNER JOIN head_sales_order h ON d.order_no = h.order_no
        WHERE h.customer_id = '$customer_id'
          AND h.status != 'Cancelled'
        GROUP BY
            COALESCE(NULLIF(d.inventory_id,''), d.inventory_name),
            d.inventory_name
        ORDER BY MAX(d.id) DESC
        LIMIT 50
    ");

    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $data[] = [
                'inventory_id'   => $row['inventory_id']   ?? '',
                'inventory_name' => $row['inventory_name'] ?? '',
                'uom'            => $row['uom']            ?? '',
                'quantity'       => 0,      // qty selalu 0 (harus diisi ulang)
                'quantity_pack'  => 0,
                'uom_pack'       => $row['uom_pack']    ?? '',
                'uom_detail'     => $row['uom_detail']  ?? '',
                'price_unit'     => 0,      // harga selalu 0 (harus diisi ulang)
                'price'          => 0,
                'subtotal'       => 0,
                'remarks'        => $row['remarks']     ?? '',
            ];
        }
    }
}

header('Content-Type: application/json');
echo json_encode($data);