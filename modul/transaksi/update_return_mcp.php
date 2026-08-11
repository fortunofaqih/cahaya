<?php
// modul/transaksi/update_return_mcp.php
// Update Sales Return CP-MCP.
// Header diperbarui dan detail MCP diganti dengan detail terbaru
// dalam satu database transaction.

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

function mcpUpdateDecimal($value): float
{
    if (is_string($value)) {
        $value = str_replace([',', ' '], ['', ''], $value);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function mcpUpdateParseDate($value): ?string
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

function mcpUpdateRedirect(
    string $type,
    string $message,
    string $page,
    string $returnId = ''
): void {
    $_SESSION['alert'] =
        '<div class="alert alert-' .
        htmlspecialchars($type, ENT_QUOTES, 'UTF-8') .
        '">' .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '</div>';

    $url = 'index.php?page=' . rawurlencode($page);

    if ($returnId !== '') {
        $url .= '&return_id=' . rawurlencode($returnId);
    }

    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    echo '<script>
        window.location.href = ' .
        json_encode(
            $url,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ) .
        ';
    </script>';

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcpUpdateRedirect(
        'danger',
        'Metode request tidak valid.',
        'return_invoice'
    );
}

$returnId = trim(
    (string)($_POST['return_id'] ?? '')
);

$returnDate = mcpUpdateParseDate(
    $_POST['return_date'] ?? ''
);

$orderNo = trim(
    (string)($_POST['order_no'] ?? '')
);

$orderDate = mcpUpdateParseDate(
    $_POST['order_date'] ?? ''
);

$shippingNo = trim(
    (string)($_POST['shipping_no'] ?? '')
);

$shippingDate = mcpUpdateParseDate(
    $_POST['shipping_date'] ?? ''
);

$invoiceNo = trim(
    (string)($_POST['invoice_no'] ?? '')
);

$invoiceDate = mcpUpdateParseDate(
    $_POST['invoice_date'] ?? ''
);

$customerId = trim(
    (string)($_POST['customer_ref'] ?? '')
);

$customerName = trim(
    (string)($_POST['customer_name'] ?? '')
);

$currency = trim(
    (string)($_POST['currency'] ?? 'IDR')
);

$reasonReturn = trim(
    (string)($_POST['reason_return'] ?? '')
);

$remarksReturn = trim(
    (string)($_POST['remarks_return'] ?? '')
);

$subtotal = mcpUpdateDecimal(
    $_POST['subtotal'] ?? 0
);

$grandTotal = mcpUpdateDecimal(
    $_POST['grand_total'] ?? 0
);

$downPayment = mcpUpdateDecimal(
    $_POST['down_payment'] ?? 0
);

$titipApplied = mcpUpdateDecimal(
    $_POST['titip_applied'] ?? 0
);

$paymentBalance = mcpUpdateDecimal(
    $_POST['payment_balance'] ?? 0
);

$returnAmount = mcpUpdateDecimal(
    $_POST['return_amount'] ?? 0
);

$remainingBalance = mcpUpdateDecimal(
    $_POST['remaining_invoice_balance'] ?? 0
);

$items = $_POST['items'] ?? [];

$user = (string)(
    $_SESSION['username'] ?? 'SYSTEM'
);

if (
    $returnId === '' ||
    !$returnDate ||
    $orderNo === '' ||
    $shippingNo === '' ||
    $invoiceNo === '' ||
    !$invoiceDate ||
    $customerId === ''
) {
    mcpUpdateRedirect(
        'danger',
        'Header Sales Return CP-MCP belum lengkap.',
        'edit_return_mcp',
        $returnId
    );
}

if ($reasonReturn === '') {
    mcpUpdateRedirect(
        'danger',
        'Reason Return wajib diisi.',
        'edit_return_mcp',
        $returnId
    );
}

if (!is_array($items) || empty($items)) {
    mcpUpdateRedirect(
        'danger',
        'Detail inventory retur belum diisi.',
        'edit_return_mcp',
        $returnId
    );
}

if ($currency === '') {
    $currency = 'IDR';
}

try {
    mysqli_begin_transaction($conn);

    /*
    |--------------------------------------------------------------------------
    | LOCK HEADER
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            return_id,
            approval_status
         FROM head_retur_invoice
         WHERE return_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $returnId
    );

    mysqli_stmt_execute($stmt);

    $existing = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    if (!$existing) {
        throw new RuntimeException(
            'Sales Return tidak ditemukan.'
        );
    }

    if (
        strtolower(
            trim(
                (string)(
                    $existing['approval_status']
                    ?? 'Pending'
                )
            )
        ) !== 'pending'
    ) {
        throw new RuntimeException(
            'Sales Return yang sudah Approved tidak dapat diubah.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER
    |--------------------------------------------------------------------------
    */
    $customerAddress = '';
    $customerCity = '';

    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            customer_name,
            customer_address,
            customer_city
         FROM head_invoice
         WHERE customer_id = ?
         ORDER BY invoice_date DESC, invoice_no DESC
         LIMIT 1"
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $customerId
    );

    mysqli_stmt_execute($stmt);

    $customerRow = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    if ($customerRow) {
        $customerName = trim(
            (string)$customerRow['customer_name']
        );

        $customerAddress =
            (string)(
                $customerRow['customer_address']
                ?? ''
            );

        $customerCity =
            (string)(
                $customerRow['customer_city']
                ?? ''
            );
    }

    if ($customerName === '') {
        throw new RuntimeException(
            'Customer Name tidak ditemukan.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDATE DETAIL
    |--------------------------------------------------------------------------
    */
    $validDetails = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $inventoryId = trim(
            (string)(
                $item['inventory_id'] ?? ''
            )
        );

        $inventoryName = trim(
            (string)(
                $item['inventory_name'] ?? ''
            )
        );

        $returnQty = mcpUpdateDecimal(
            $item['return_quantity'] ?? 0
        );

        $uom = trim(
            (string)($item['uom'] ?? '')
        );

        $returnPack = mcpUpdateDecimal(
            $item['return_quantity_pack'] ?? 0
        );

        $uomPack = trim(
            (string)($item['uom_pack'] ?? '')
        );

        $returnDetail = mcpUpdateDecimal(
            $item['return_quantity_detail'] ?? 0
        );

        $uomDetail = trim(
            (string)($item['uom_detail'] ?? '')
        );

        $priceUnit = mcpUpdateDecimal(
            $item['price_unit'] ?? 0
        );

        $price = mcpUpdateDecimal(
            $item['price'] ?? 0
        );

        $submittedReturnSubtotal =
            mcpUpdateDecimal(
                $item['return_subtotal'] ?? 0
            );

        $remarksDetail = trim(
            (string)(
                $item['remarks_detail'] ?? ''
            )
        );

        if (
            $inventoryId === '' &&
            $inventoryName === '' &&
            $returnQty <= 0 &&
            $returnPack <= 0
        ) {
            continue;
        }

        if (
            $inventoryId === '' ||
            $inventoryName === ''
        ) {
            throw new RuntimeException(
                'Inventory ID dan Inventory Name pada baris ' .
                ($index + 1) .
                ' wajib diisi.'
            );
        }

        if (
            $returnQty <= 0 &&
            $returnPack <= 0
        ) {
            throw new RuntimeException(
                'Qty Return atau Qty Pack Return pada inventory ' .
                $inventoryId .
                ' wajib lebih dari 0.'
            );
        }

        if ($uom === '') {
            throw new RuntimeException(
                'UoM Return pada inventory ' .
                $inventoryId .
                ' wajib diisi.'
            );
        }

        if ($price < 0) {
            throw new RuntimeException(
                'Price Return pada inventory ' .
                $inventoryId .
                ' tidak boleh negatif.'
            );
        }

        /*
         * Sama dengan add/save MCP.
         * Jika Qty Pack > 0:
         * Return Subtotal = Price x Qty Pack Return.
         * Jika Qty Pack = 0, subtotal manual boleh dipakai.
         */
        if ($returnPack > 0) {
            $returnSubtotal = round(
                $price * $returnPack,
                2
            );
        } else {
            $returnSubtotal = round(
                $submittedReturnSubtotal,
                2
            );
        }

        $validDetails[] = [
            'inventory_id' => $inventoryId,
            'inventory_name' => $inventoryName,
            'return_quantity' => $returnQty,
            'uom' => $uom,
            'return_quantity_pack' => $returnPack,
            'uom_pack' => $uomPack,
            'return_quantity_detail' => $returnDetail,
            'uom_detail' => $uomDetail,
            'price_unit' => $priceUnit,
            'price' => $price,
            'return_subtotal' => $returnSubtotal,
            'remarks_detail' => $remarksDetail
        ];
    }

    if (!$validDetails) {
        throw new RuntimeException(
            'Minimal harus ada satu detail inventory retur.'
        );
    }

    if ($returnAmount < 0) {
        throw new RuntimeException(
            'Return Amount tidak boleh negatif.'
        );
    }

    if ($remainingBalance < 0) {
        throw new RuntimeException(
            'Balance After Return tidak boleh negatif.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | UPDATE HEADER
    |--------------------------------------------------------------------------
    */
    $updateHead = "
        UPDATE head_retur_invoice
        SET
            return_date = ?,
            invoice_no = ?,
            invoice_date = ?,
            shipping_no = ?,
            shipping_date = ?,
            order_no = ?,
            order_date = ?,
            customer_id = ?,
            customer_name = ?,
            customer_address = ?,
            customer_city = ?,
            currency = ?,
            reason_return = ?,
            remarks_return = ?,
            subtotal = ?,
            grand_total = ?,
            down_payment = ?,
            titip_applied = ?,
            payment_balance = ?,
            return_amount = ?,
            remaining_invoice_balance = ?,
            user_modified = ?,
            date_modified = NOW()
        WHERE return_id = ?
    ";

    $stmt = mysqli_prepare(
        $conn,
        $updateHead
    );

    mysqli_stmt_bind_param(
        $stmt,
        'ssssssssssssssdddddddss',
        $returnDate,
        $invoiceNo,
        $invoiceDate,
        $shippingNo,
        $shippingDate,
        $orderNo,
        $orderDate,
        $customerId,
        $customerName,
        $customerAddress,
        $customerCity,
        $currency,
        $reasonReturn,
        $remarksReturn,
        $subtotal,
        $grandTotal,
        $downPayment,
        $titipApplied,
        $paymentBalance,
        $returnAmount,
        $remainingBalance,
        $user,
        $returnId
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | REPLACE DETAIL
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM detail_retur_invoice
         WHERE return_id = ?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $returnId
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
            ?, ?, ?, ?, 0,
            ?, ?,
            0, '',
            0, '',
            0, '',
            ?, ?,
            ?, ?,
            ?, ?,
            0,
            ?, ?,
            0,
            ?, ?,
            ?,
            NOW()
        )
    ";

    $detailStmt = mysqli_prepare(
        $conn,
        $insertDetail
    );

    foreach ($validDetails as $detail) {
        mysqli_stmt_bind_param(
            $detailStmt,
            'ssssssdsdsdsdddsss',
            $returnId,
            $invoiceNo,
            $shippingNo,
            $orderNo,
            $detail['inventory_id'],
            $detail['inventory_name'],
            $detail['return_quantity'],
            $detail['uom'],
            $detail['return_quantity_pack'],
            $detail['uom_pack'],
            $detail['return_quantity_detail'],
            $detail['uom_detail'],
            $detail['price_unit'],
            $detail['price'],
            $detail['return_subtotal'],
            $detail['remarks_detail'],
            $user
        );

        mysqli_stmt_execute($detailStmt);
    }

    mysqli_stmt_close($detailStmt);

    mysqli_commit($conn);

    mcpUpdateRedirect(
        'success',
        "Sales Return CP-MCP {$returnId} berhasil diupdate.",
        'return_invoice'
    );

} catch (Throwable $e) {
    mysqli_rollback($conn);

    mcpUpdateRedirect(
        'danger',
        'Error: ' . $e->getMessage(),
        'edit_return_mcp',
        $returnId
    );
}
