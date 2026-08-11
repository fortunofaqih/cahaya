<?php
// modul/transaksi/save_return_mcp.php
// Save Sales Return CP-MCP.
// Tidak melakukan validasi relasi Order -> Shipping -> Invoice,
// karena seluruh referensi transaksi diinput manual oleh Finance.
// Data tetap disimpan ke head_retur_invoice dan detail_retur_invoice.

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

function mcpDecimal($value): float
{
    if (is_string($value)) {
        $value = str_replace([',', ' '], ['', ''], $value);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function mcpParseDate($value): ?string
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

function mcpRedirect(string $type, string $message, string $page): void
{
    $_SESSION['alert'] =
        '<div class="alert alert-' .
        htmlspecialchars($type, ENT_QUOTES, 'UTF-8') .
        '">' .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '</div>';

    $url = 'index.php?page=' . rawurlencode($page);

    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    echo '<script>
        window.location.href = ' .
        json_encode(
            $url,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) .
        ';
    </script>';

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcpRedirect(
        'danger',
        'Metode request tidak valid.',
        'add_return_mcp'
    );
}

$returnId     = trim((string)($_POST['return_id'] ?? ''));
$returnDate   = mcpParseDate($_POST['return_date'] ?? '');

$orderNo      = trim((string)($_POST['order_no'] ?? ''));
$orderDate    = mcpParseDate($_POST['order_date'] ?? '');

$shippingNo   = trim((string)($_POST['shipping_no'] ?? ''));
$shippingDate = mcpParseDate($_POST['shipping_date'] ?? '');

$invoiceNo    = trim((string)($_POST['invoice_no'] ?? ''));
$invoiceDate  = mcpParseDate($_POST['invoice_date'] ?? '');

$customerId   = trim((string)($_POST['customer_ref'] ?? ''));
$customerName = trim((string)($_POST['customer_name'] ?? ''));

$currency      = trim((string)($_POST['currency'] ?? 'IDR'));
$reasonReturn  = trim((string)($_POST['reason_return'] ?? ''));
$remarksReturn = trim((string)($_POST['remarks_return'] ?? ''));

$subtotal       = mcpDecimal($_POST['subtotal'] ?? 0);
$grandTotal     = mcpDecimal($_POST['grand_total'] ?? 0);
$downPayment    = mcpDecimal($_POST['down_payment'] ?? 0);
$titipApplied   = mcpDecimal($_POST['titip_applied'] ?? 0);
$paymentBalance = mcpDecimal($_POST['payment_balance'] ?? 0);
$returnAmount   = mcpDecimal($_POST['return_amount'] ?? 0);

$remainingBalance = mcpDecimal(
    $_POST['remaining_invoice_balance'] ?? 0
);

$items = $_POST['items'] ?? [];
$user  = (string)($_SESSION['username'] ?? 'SYSTEM');

if (
    $returnId === '' ||
    !$returnDate ||
    $orderNo === '' ||
    $shippingNo === '' ||
    $invoiceNo === '' ||
    !$invoiceDate ||
    $customerId === ''
) {
    mcpRedirect(
        'danger',
        'Header Sales Return CP-MCP belum lengkap.',
        'add_return_mcp'
    );
}

if ($reasonReturn === '') {
    mcpRedirect(
        'danger',
        'Reason Return wajib diisi.',
        'add_return_mcp'
    );
}

if (!is_array($items) || empty($items)) {
    mcpRedirect(
        'danger',
        'Detail inventory retur belum diisi.',
        'add_return_mcp'
    );
}

if ($currency === '') {
    $currency = 'IDR';
}

try {
    mysqli_begin_transaction($conn);

    /*
    |--------------------------------------------------------------------------
    | Cek Return ID duplicate
    |--------------------------------------------------------------------------
    */
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
        throw new RuntimeException(
            'Sales Return ID sudah digunakan.'
        );
    }

    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | Ambil identitas customer
    |--------------------------------------------------------------------------
    | Customer Name tetap berdasarkan customer yang dipilih.
    | Address dan City diambil dari invoice terakhir customer tersebut,
    | supaya field head_retur_invoice tetap terisi konsisten.
    |--------------------------------------------------------------------------
    */
    $customerAddress = '';
    $customerCity    = '';

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
        $customerName =
            trim((string)$customerRow['customer_name']);

        $customerAddress =
            (string)($customerRow['customer_address'] ?? '');

        $customerCity =
            (string)($customerRow['customer_city'] ?? '');
    }

    if ($customerName === '') {
        throw new RuntimeException(
            'Customer Name tidak ditemukan.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Validasi detail manual
    |--------------------------------------------------------------------------
    */
    $validDetails = [];
    $detailCalculatedTotal = 0.0;

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $inventoryId =
            trim((string)($item['inventory_id'] ?? ''));

        $inventoryName =
            trim((string)($item['inventory_name'] ?? ''));

        $originalQty =
            mcpDecimal($item['original_quantity'] ?? 0);

        $originalUom =
            trim((string)($item['original_uom'] ?? ''));

        $originalPack =
            mcpDecimal($item['original_quantity_pack'] ?? 0);

        $originalUomPack =
            trim((string)($item['original_uom_pack'] ?? ''));

        $originalDetail =
            mcpDecimal($item['original_quantity_detail'] ?? 0);

        $originalUomDetail =
            trim((string)($item['original_uom_detail'] ?? ''));

        $returnQty =
            mcpDecimal($item['return_quantity'] ?? 0);

        $uom =
            trim((string)($item['uom'] ?? ''));

        $returnPack =
            mcpDecimal($item['return_quantity_pack'] ?? 0);

        $uomPack =
            trim((string)($item['uom_pack'] ?? ''));

        $returnDetail =
            mcpDecimal($item['return_quantity_detail'] ?? 0);

        $uomDetail =
            trim((string)($item['uom_detail'] ?? ''));

        $priceUnit =
            mcpDecimal($item['price_unit'] ?? 0);

        $price =
            mcpDecimal($item['price'] ?? 0);

        $originalSubtotal =
            mcpDecimal($item['original_subtotal'] ?? 0);

        $submittedReturnSubtotal =
            mcpDecimal($item['return_subtotal'] ?? 0);

        $remarksDetail =
            trim((string)($item['remarks_detail'] ?? ''));

        /*
         * Baris benar-benar kosong dilewati.
         */
        if (
            $inventoryId === '' &&
            $inventoryName === '' &&
            $returnQty <= 0 &&
            $returnPack <= 0
        ) {
            continue;
        }

        if ($inventoryId === '' || $inventoryName === '') {
            throw new RuntimeException(
                'Inventory ID dan Inventory Name pada baris ' .
                ($index + 1) .
                ' wajib diisi.'
            );
        }

        if ($returnQty <= 0 && $returnPack <= 0) {
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
         * Mengikuti logika retur existing:
         * Return Subtotal = Price x Qty Pack Return.
         *
         * Jika Qty Pack Return = 0 tetapi user mengisi subtotal manual,
         * nilai subtotal manual tetap dipakai untuk mendukung retur MCP.
         */
        if ($returnPack > 0) {
            $returnSubtotal =
                round($price * $returnPack, 2);
        } else {
            $returnSubtotal =
                round($submittedReturnSubtotal, 2);
        }

        $conversion =
            $originalPack > 0
                ? $originalQty / $originalPack
                : 0.0;

        $detailCalculatedTotal += $returnSubtotal;

        $validDetails[] = [
            'inventory_id'             => $inventoryId,
            'inventory_name'           => $inventoryName,
            'original_quantity'        => $originalQty,
            'original_uom'             => $originalUom,
            'original_quantity_pack'   => $originalPack,
            'original_uom_pack'        => $originalUomPack,
            'original_quantity_detail' => $originalDetail,
            'original_uom_detail'      => $originalUomDetail,
            'return_quantity'          => $returnQty,
            'uom'                      => $uom,
            'return_quantity_pack'     => $returnPack,
            'uom_pack'                 => $uomPack,
            'return_quantity_detail'   => $returnDetail,
            'uom_detail'               => $uomDetail,
            'pack_conversion_value'    => $conversion,
            'price_unit'               => $priceUnit,
            'price'                    => $price,
            'original_subtotal'        => $originalSubtotal,
            'return_subtotal'          => $returnSubtotal,
            'remarks_detail'           => $remarksDetail
        ];
    }

    if (!$validDetails) {
        throw new RuntimeException(
            'Minimal harus ada satu detail inventory retur.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | PAYMENT MANUAL
    |--------------------------------------------------------------------------
    | Finance menginput manual Return Amount dan Balance After Return.
    | Tidak dipaksa sama dengan total detail karena kebutuhan MCP memang manual.
    |--------------------------------------------------------------------------
    */
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
    | Insert Header
    |--------------------------------------------------------------------------
    */
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
            'Open',
            'Pending',
            ?,
            NOW()
        )
    ";

    $stmt = mysqli_prepare($conn, $insertHead);

    mysqli_stmt_bind_param(
        $stmt,
        'sssssssssssssssddddddds',
        $returnId,
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
        $user
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | Insert Detail
    |--------------------------------------------------------------------------
    | shipping_detail_id = 0 karena return MCP tidak harus berasal dari
    | det_shipping existing.
    |--------------------------------------------------------------------------
    */
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

    $detailStmt = mysqli_prepare(
        $conn,
        $insertDetail
    );

    foreach ($validDetails as $detail) {
        mysqli_stmt_bind_param(
            $detailStmt,
            'ssssssdsdsdsdsdsdsddddsss',
            $returnId,
            $invoiceNo,
            $shippingNo,
            $orderNo,
            $detail['inventory_id'],
            $detail['inventory_name'],
            $detail['original_quantity'],
            $detail['original_uom'],
            $detail['original_quantity_pack'],
            $detail['original_uom_pack'],
            $detail['original_quantity_detail'],
            $detail['original_uom_detail'],
            $detail['return_quantity'],
            $detail['uom'],
            $detail['return_quantity_pack'],
            $detail['uom_pack'],
            $detail['return_quantity_detail'],
            $detail['uom_detail'],
            $detail['pack_conversion_value'],
            $detail['price_unit'],
            $detail['price'],
            $detail['original_subtotal'],
            $detail['return_subtotal'],
            $detail['remarks_detail'],
            $user
        );

        mysqli_stmt_execute($detailStmt);
    }

    mysqli_stmt_close($detailStmt);

    mysqli_commit($conn);

    mcpRedirect(
        'success',
        "Sales Return CP-MCP {$returnId} berhasil disimpan.",
        'return_invoice'
    );

} catch (Throwable $e) {
    mysqli_rollback($conn);

    mcpRedirect(
        'danger',
        'Error: ' . $e->getMessage(),
        'add_return_mcp'
    );
}
