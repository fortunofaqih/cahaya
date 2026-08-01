<?php
// modul/transaksi/save_return.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
mysqli_set_charset($conn, 'utf8mb4');

function returnDecimalValue($value): float
{
    if (is_string($value)) {
        $value = str_replace([',', ' '], ['', ''], $value);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function returnParseDate($value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    foreach (['Y-m-d', 'd-M-Y', 'd-m-Y'] as $format) {
        $date = DateTime::createFromFormat($format, $value);

        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function returnRedirect(string $type, string $message, string $page): void
{
    $_SESSION['alert'] =
        '<div class="alert alert-' .
        htmlspecialchars($type, ENT_QUOTES, 'UTF-8') .
        '">' .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '</div>';

    $url = 'index.php?page=' . rawurlencode($page);

    /*
     * Jika belum ada output, gunakan redirect PHP.
     * Jika header.php sudah mencetak HTML, gunakan JavaScript.
     */
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    echo '<script>
        window.location.href = ' .
        json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
        ';
    </script>';

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    returnRedirect('danger', 'Metode request tidak valid.', 'add_return');
}

$returnId      = trim((string)($_POST['return_id'] ?? ''));
$returnDate    = returnParseDate($_POST['return_date'] ?? '');
$orderNo       = trim((string)($_POST['order_no'] ?? ''));
$shippingNo    = trim((string)($_POST['shipping_no'] ?? ''));
$invoiceNo     = trim((string)($_POST['invoice_no'] ?? ''));
$reasonReturn  = trim((string)($_POST['reason_return'] ?? ''));
$remarksReturn = trim((string)($_POST['remarks_return'] ?? ''));
$items         = $_POST['items'] ?? [];
$user          = (string)($_SESSION['username'] ?? 'SYSTEM');

if (
    $returnId === '' ||
    !$returnDate ||
    $orderNo === '' ||
    $shippingNo === '' ||
    $invoiceNo === ''
) {
    returnRedirect('danger', 'Header Sales Return belum lengkap.', 'add_return');
}

if ($reasonReturn === '') {
    returnRedirect('danger', 'Reason Return wajib diisi.', 'add_return');
}

if (!is_array($items)) {
    returnRedirect('danger', 'Detail retur tidak valid.', 'add_return');
}

try {
    mysqli_begin_transaction($conn);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT return_id
         FROM head_retur_invoice
         WHERE return_id = ?
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($stmt, 's', $returnId);
    mysqli_stmt_execute($stmt);

    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        throw new RuntimeException('Sales Return ID sudah digunakan.');
    }
    mysqli_stmt_close($stmt);

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
            hs.shipping_no,
            hs.shipping_date
        FROM head_invoice hi
        INNER JOIN det_invoice di
            ON di.invoice_no = hi.invoice_no
        INNER JOIN hed_shipping hs
            ON hs.shipping_no = di.shipping_no
        WHERE hi.invoice_no = ?
          AND hs.shipping_no = ?
          AND hs.order_no = ?
          AND COALESCE(hi.status, 'Open') <> 'Cancelled'
        LIMIT 1
        FOR UPDATE
    ";

    $stmt = mysqli_prepare($conn, $headSql);
    mysqli_stmt_bind_param(
        $stmt,
        'sss',
        $invoiceNo,
        $shippingNo,
        $orderNo
    );
    mysqli_stmt_execute($stmt);
    $header = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$header) {
        throw new RuntimeException(
            'Relasi Order, Shipping, dan Invoice tidak valid.'
        );
    }

    $validDetails = [];
    $returnAmount = 0.0;

    foreach ($items as $item) {
        if (!isset($item['selected'])) {
            continue;
        }

        $detailId      = (int)($item['shipping_detail_id'] ?? 0);
        $returnQty     = returnDecimalValue($item['return_quantity'] ?? 0);
        $returnPack    = returnDecimalValue($item['return_quantity_pack'] ?? 0);
        $inputPrice    = returnDecimalValue($item['price'] ?? 0);
        $remarksDetail = trim((string)($item['remarks_detail'] ?? ''));

        if ($detailId <= 0 || $returnQty <= 0) {
            continue;
        }

        /*
         * Price dikirim dari add_return.php agar dapat diubah oleh user.
         * Data Sales Order tetap diambil sebagai referensi dan sumber
         * informasi inventory, UoM, price_unit, serta original_subtotal.
         */
        $detailSql = "
            SELECT
                ds.id,
                ds.inventory_id,
                ds.inventory_name,
                COALESCE(ds.qty_shipping, 0) AS qty_shipping,
                ds.uom_shipping,
                COALESCE(ds.qty_pack_shipping, 0) AS qty_pack_shipping,
                ds.uom_pack_shipping,
                COALESCE(ds.qty_detail_shipping, 0) AS qty_detail_shipping,
                ds.uom_detail_shipping,

                COALESCE(dso.price_unit, 0) AS price_unit,
                COALESCE(dso.price, 0) AS price,
                COALESCE(dso.subtotal, 0) AS original_subtotal

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

            WHERE ds.id = ?
              AND ds.shipping_no = ?

            LIMIT 1
            FOR UPDATE
        ";

        $stmt = mysqli_prepare($conn, $detailSql);
        mysqli_stmt_bind_param(
            $stmt,
            'is',
            $detailId,
            $shippingNo
        );
        mysqli_stmt_execute($stmt);
        $source = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if (!$source) {
            throw new RuntimeException(
                "Detail shipping ID {$detailId} tidak ditemukan."
            );
        }

        if ($inputPrice <= 0) {
            throw new RuntimeException(
                "Price Return untuk inventory {$source['inventory_id']} wajib lebih dari 0."
            );
        }

        $usedSql = "
            SELECT
                COALESCE(SUM(d.return_quantity), 0) AS used_qty,
                COALESCE(SUM(d.return_quantity_pack), 0) AS used_pack
            FROM detail_retur_invoice d
            INNER JOIN head_retur_invoice h
                ON h.return_id = d.return_id
            WHERE d.shipping_detail_id = ?
              AND h.invoice_no = ?
              AND h.shipping_no = ?
              AND COALESCE(h.status, 'Open') <> 'Cancelled'
        ";

        $stmt = mysqli_prepare($conn, $usedSql);
        mysqli_stmt_bind_param(
            $stmt,
            'iss',
            $detailId,
            $invoiceNo,
            $shippingNo
        );
        mysqli_stmt_execute($stmt);
        $used = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        $originalQty  = (float)$source['qty_shipping'];
        $originalPack = (float)$source['qty_pack_shipping'];

        $remainingQty = max(
            0,
            $originalQty - (float)$used['used_qty']
        );

        $remainingPack = max(
            0,
            $originalPack - (float)$used['used_pack']
        );

        if ($returnQty > $remainingQty + 0.0001) {
            throw new RuntimeException(
                "Qty Return {$source['inventory_id']} melebihi sisa Qty yang dapat diretur."
            );
        }

        /*
         * Sinkronisasi Qty Return dan Qty Pack Return memakai rasio
         * quantity asli pada Shipping.
         */
        $uomPack = strtoupper(
            trim((string)$source['uom_pack_shipping'])
        );

        if ($uomPack === 'KG') {
            $returnPack = $returnQty;
        } elseif ($originalQty > 0 && $originalPack > 0) {
            $returnPack =
                $returnQty / ($originalQty / $originalPack);
        }

        if (
            $originalPack > 0 &&
            $returnPack > $remainingPack + 0.0001
        ) {
            throw new RuntimeException(
                "Qty Pack Return {$source['inventory_id']} melebihi sisa Qty Pack."
            );
        }

        /*
         * Mengikuti add_sales_order:
         * Return Subtotal = Price x Qty Pack Return
         */
        /*
         * Price menggunakan nilai yang diinput atau diubah user
         * pada add_return.php.
         */
        $price          = round($inputPrice, 4);
        $priceUnit      = (float)$source['price_unit'];
        $returnSubtotal = round($price * $returnPack, 2);

        $returnAmount += $returnSubtotal;

        $validDetails[] = [
            'source'          => $source,
            'return_qty'      => $returnQty,
            'return_pack'     => $returnPack,
            'return_detail'   => 0.0,
            'price_unit'      => $priceUnit,
            'price'           => $price,
            'return_subtotal' => $returnSubtotal,
            'remarks_detail'  => $remarksDetail
        ];
    }

    if (!$validDetails) {
        throw new RuntimeException(
            'Minimal pilih satu barang dan isi Qty Return lebih dari 0.'
        );
    }

    $remainingBalance = max(
        0,
        (float)$header['payment_balance'] - $returnAmount
    );

    $insertHead = "
        INSERT INTO head_retur_invoice (
            return_id,
            return_date,
            invoice_no,
            invoice_date,
            shipping_no,
            shipping_date,
            order_no,
            order_date,
            customer_id,
            customer_name,
            customer_address,
            customer_city,
            currency,
            reason_return,
            remarks_return,
            subtotal,
            grand_total,
            down_payment,
            titip_applied,
            payment_balance,
            return_amount,
            remaining_invoice_balance,
            status,
            approval_status,
            create_user,
            date_created
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            'Open', 'Pending', ?, NOW()
        )
    ";

    $stmt = mysqli_prepare($conn, $insertHead);
    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssssssssddddddds',
        $returnId,
        $returnDate,
        $header['invoice_no'],
        $header['invoice_date'],
        $header['shipping_no'],
        $header['shipping_date'],
        $header['order_no'],
        $header['order_date'],
        $header['customer_id'],
        $header['customer_name'],
        $header['customer_address'],
        $header['customer_city'],
        $header['currency'],
        $reasonReturn,
        $remarksReturn,
        $header['subtotal'],
        $header['grand_total'],
        $header['down_payment'],
        $header['titip_applied'],
        $header['payment_balance'],
        $returnAmount,
        $remainingBalance,
        $user
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $insertDetail = "
        INSERT INTO detail_retur_invoice (
            return_id,
            invoice_no,
            shipping_no,
            order_no,
            shipping_detail_id,
            inventory_id,
            inventory_name,
            original_quantity,
            original_uom,
            original_quantity_pack,
            original_uom_pack,
            original_quantity_detail,
            original_uom_detail,
            return_quantity,
            uom,
            return_quantity_pack,
            uom_pack,
            return_quantity_detail,
            uom_detail,
            pack_conversion_value,
            price_unit,
            price,
            original_subtotal,
            return_subtotal,
            remarks_detail,
            create_user,
            date_created
        ) VALUES (
            ?, ?, ?, ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?, ?,
            ?,
            ?, ?,
            ?, ?,
            ?, ?,
            NOW()
        )
    ";

    $detailStmt = mysqli_prepare($conn, $insertDetail);

    foreach ($validDetails as $detail) {
        $source = $detail['source'];

        $conversion =
            (float)$source['qty_pack_shipping'] > 0
                ? (float)$source['qty_shipping'] /
                  (float)$source['qty_pack_shipping']
                : 0.0;

        mysqli_stmt_bind_param(
            $detailStmt,
            'ssssissdsdsdsdsdsdsddddsss',
            $returnId,
            $header['invoice_no'],
            $header['shipping_no'],
            $header['order_no'],
            $source['id'],
            $source['inventory_id'],
            $source['inventory_name'],
            $source['qty_shipping'],
            $source['uom_shipping'],
            $source['qty_pack_shipping'],
            $source['uom_pack_shipping'],
            $source['qty_detail_shipping'],
            $source['uom_detail_shipping'],
            $detail['return_qty'],
            $source['uom_shipping'],
            $detail['return_pack'],
            $source['uom_pack_shipping'],
            $detail['return_detail'],
            $source['uom_detail_shipping'],
            $conversion,
            $detail['price_unit'],
            $detail['price'],
            $source['original_subtotal'],
            $detail['return_subtotal'],
            $detail['remarks_detail'],
            $user
        );

        mysqli_stmt_execute($detailStmt);
    }

    mysqli_stmt_close($detailStmt);

    mysqli_commit($conn);

    returnRedirect(
        'success',
        "Sales Return {$returnId} berhasil disimpan.",
        'return_invoice'
    );
} catch (Throwable $e) {
    mysqli_rollback($conn);

    returnRedirect(
        'danger',
        'Error: ' . $e->getMessage(),
        'add_return'
    );
}
