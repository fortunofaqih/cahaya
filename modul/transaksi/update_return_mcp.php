<?php
// modul/transaksi/update_return_mcp.php
// Update Sales Return CP-MCP - sinkron dengan relasi internal terbaru.
//
// Sales Return ID dapat diubah dengan sinkronisasi referensi.
// Internal SO/SJ/INV dipertahankan nomornya.
// Sales Order MCP asli + Shipping No MCP asli disimpan di remarks_return.
// Calculated Detail Return menjadi subtotal/grand_total internal invoice + return.
// detail_retur_invoice dan det_shipping disinkronkan ulang.
// shipping_detail_id selalu menunjuk det_shipping.id yang valid.

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
        $value = str_replace(' ', '', trim($value));
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function mcpUpdateParseDate($value): ?string
{
    $value = trim((string)$value);

    if ($value === '') return null;

    foreach (['Y-m-d', 'd-M-Y', 'd-m-Y', 'd/m/Y'] as $format) {
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

    echo '<script>window.location.href=' .
        json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
        ';</script>';

    exit;
}

function mcpUpdateTableColumns(mysqli $conn, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $res = mysqli_query($conn, "SHOW COLUMNS FROM `{$table}`");
    $columns = [];

    while ($row = mysqli_fetch_assoc($res)) {
        $columns[$row['Field']] = $row;
    }

    $cache[$table] = $columns;
    return $columns;
}

function mcpUpdateColumnExists(
    mysqli $conn,
    string $table,
    string $column
): bool {
    $columns = mcpUpdateTableColumns($conn, $table);
    return isset($columns[$column]);
}

function mcpUpdateAvailable(
    mysqli $conn,
    string $table,
    array $values,
    string $whereColumn,
    $whereValue
): void {
    $columns = mcpUpdateTableColumns($conn, $table);

    if (!isset($columns[$whereColumn])) {
        throw new RuntimeException(
            "Kolom {$whereColumn} tidak ditemukan pada {$table}."
        );
    }

    $set = [];
    $params = [];
    $types = '';

    foreach ($values as $field => $value) {
        if (!isset($columns[$field])) {
            continue;
        }

        $set[] = "`{$field}` = ?";

        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }

        $params[] = $value;
    }

    if (!$set) return;

    if (is_int($whereValue)) {
        $types .= 'i';
    } else {
        $types .= 's';
    }

    $params[] = $whereValue;

    $sql = "UPDATE `{$table}` SET " .
        implode(', ', $set) .
        " WHERE `{$whereColumn}` = ?";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException(
            "Gagal prepare update {$table}: " . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function mcpUpdateInsertAvailable(
    mysqli $conn,
    string $table,
    array $values
): int {
    $columns = mcpUpdateTableColumns($conn, $table);

    $fields = [];
    $placeholders = [];
    $params = [];
    $types = '';

    foreach ($values as $field => $value) {
        if (!isset($columns[$field])) {
            continue;
        }

        $fields[] = "`{$field}`";
        $placeholders[] = '?';

        if (is_int($value)) {
            $types .= 'i';
        } elseif (is_float($value)) {
            $types .= 'd';
        } else {
            $types .= 's';
        }

        $params[] = $value;
    }

    if (!$fields) {
        throw new RuntimeException(
            "Tidak ada kolom yang cocok untuk insert ke {$table}."
        );
    }

    $sql = "INSERT INTO `{$table}` (" .
        implode(', ', $fields) .
        ") VALUES (" .
        implode(', ', $placeholders) .
        ")";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException(
            "Gagal prepare insert {$table}: " . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);

    $insertId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    return $insertId;
}

function mcpUpdateExists(
    mysqli $conn,
    string $table,
    string $column,
    string $value
): bool {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1"
    );

    if (!$stmt) {
        throw new RuntimeException(
            "Gagal cek {$table}: " . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, 's', $value);
    mysqli_stmt_execute($stmt);

    $exists = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $exists;
}

function mcpUpdateGenerateInventoryId(
    mysqli $conn,
    int $year,
    array &$reserved
): string {
    $prefix = 'MCP-INV/' . $year . '-';
    $pattern = $prefix . '%';
    $maxNo = 0;

    foreach (['detail_retur_invoice', 'm_inventory'] as $table) {
        if (!mcpUpdateColumnExists($conn, $table, 'inventory_id')) {
            continue;
        }

        $sql = "
            SELECT COALESCE(
                MAX(
                    CAST(
                        SUBSTRING(inventory_id, LENGTH(?) + 1)
                        AS UNSIGNED
                    )
                ),
                0
            ) AS max_no
            FROM `{$table}`
            WHERE inventory_id LIKE ?
        ";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'ss', $prefix, $pattern);
        mysqli_stmt_execute($stmt);

        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        $maxNo = max($maxNo, (int)($row['max_no'] ?? 0));
    }

    do {
        $maxNo++;
        $candidate =
            $prefix .
            str_pad((string)$maxNo, 6, '0', STR_PAD_LEFT);
    } while (isset($reserved[$candidate]));

    $reserved[$candidate] = true;
    return $candidate;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcpUpdateRedirect(
        'danger',
        'Metode request tidak valid.',
        'return_invoice'
    );
}

$originalReturnId = trim((string)($_POST['original_return_id'] ?? ''));
$returnId = trim((string)($_POST['return_id'] ?? ''));
$returnDate = mcpUpdateParseDate($_POST['return_date'] ?? '');

$externalOrderNo = trim((string)($_POST['order_no'] ?? ''));
$externalShippingNo = trim((string)($_POST['shipping_no'] ?? ''));

$customerId = trim((string)($_POST['customer_ref'] ?? ''));
$customerName = trim((string)($_POST['customer_name'] ?? ''));

$currency = trim((string)($_POST['currency'] ?? 'IDR'));
$reasonReturn = trim((string)($_POST['reason_return'] ?? ''));

$downPayment = mcpUpdateDecimal($_POST['down_payment'] ?? 0);
$titipApplied = mcpUpdateDecimal($_POST['titip_applied'] ?? 0);
$paymentBalance = mcpUpdateDecimal($_POST['payment_balance'] ?? 0);
$returnAmount = mcpUpdateDecimal($_POST['return_amount'] ?? 0);
$remainingBalance = mcpUpdateDecimal(
    $_POST['remaining_invoice_balance'] ?? 0
);

$items = $_POST['items'] ?? [];
$user = (string)($_SESSION['username'] ?? 'SYSTEM');

if (
    $originalReturnId === '' ||
    $returnId === '' ||
    !$returnDate ||
    $externalOrderNo === '' ||
    $externalShippingNo === '' ||
    $customerId === ''
) {
    mcpUpdateRedirect(
        'danger',
        'Header Sales Return CP-MCP belum lengkap.',
        'edit_return_mcp',
        $originalReturnId
    );
}

if ($reasonReturn === '') {
    mcpUpdateRedirect(
        'danger',
        'Reason Return wajib diisi.',
        'edit_return_mcp',
        $originalReturnId
    );
}

if (!is_array($items) || !$items) {
    mcpUpdateRedirect(
        'danger',
        'Detail inventory retur belum diisi.',
        'edit_return_mcp',
        $originalReturnId
    );
}

if ($currency === '') {
    $currency = 'IDR';
}

try {
    mysqli_begin_transaction($conn);

    /*
    |--------------------------------------------------------------------------
    | LOCK RETURN + AMBIL INTERNAL REFERENCE
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            return_id,
            invoice_no,
            shipping_no,
            order_no,
            approval_status
         FROM head_retur_invoice
         WHERE return_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    mysqli_stmt_bind_param($stmt, 's', $originalReturnId);
    mysqli_stmt_execute($stmt);

    $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$existing) {
        throw new RuntimeException('Sales Return tidak ditemukan.');
    }

    if (
        strtolower(trim((string)($existing['approval_status'] ?? 'Pending')))
        !== 'pending'
    ) {
        throw new RuntimeException(
            'Sales Return yang sudah Approved tidak dapat diubah.'
        );
    }

    $internalInvoiceNo =
        trim((string)($existing['invoice_no'] ?? ''));

    $internalShippingNo =
        trim((string)($existing['shipping_no'] ?? ''));

    $internalOrderNo =
        trim((string)($existing['order_no'] ?? ''));

    $isMcp =
        stripos($originalReturnId, '/CP-MCP/') !== false ||
        strpos($internalInvoiceNo, 'CP-MCP/INV/') === 0;

    if (!$isMcp) {
        throw new RuntimeException(
            'Data ini bukan Sales Return CP-MCP.'
        );
    }

    if (
        $internalInvoiceNo === '' ||
        $internalShippingNo === '' ||
        $internalOrderNo === ''
    ) {
        throw new RuntimeException(
            'Internal reference CP-MCP tidak lengkap.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI SALES RETURN ID BARU
    |--------------------------------------------------------------------------
    */
    if ($returnId !== $originalReturnId) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT 1
             FROM head_retur_invoice
             WHERE return_id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Gagal mengecek Sales Return ID baru: ' . mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param($stmt, 's', $returnId);
        mysqli_stmt_execute($stmt);

        $duplicateReturnId = (bool)mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmt)
        );

        mysqli_stmt_close($stmt);

        if ($duplicateReturnId) {
            throw new RuntimeException(
                'Sales Return ID ' . $returnId . ' sudah digunakan. Gunakan ID lain.'
            );
        }
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

    mysqli_stmt_bind_param($stmt, 's', $customerId);
    mysqli_stmt_execute($stmt);

    $customerRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if ($customerRow) {
        $customerName =
            trim((string)($customerRow['customer_name'] ?? ''));

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
    | VALIDASI DETAIL
    |--------------------------------------------------------------------------
    */
    $validDetails = [];
    $calculatedTotal = 0.0;
    $year = (int)date('Y', strtotime($returnDate));
    $reservedInventoryIds = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) continue;

        $inventoryId =
            trim((string)($item['inventory_id'] ?? ''));

        $inventoryName =
            trim((string)($item['inventory_name'] ?? ''));

        $returnQty =
            mcpUpdateDecimal($item['return_quantity'] ?? 0);

        $uom = trim((string)($item['uom'] ?? ''));

        $returnPack =
            mcpUpdateDecimal(
                $item['return_quantity_pack'] ?? 0
            );

        $uomPack =
            trim((string)($item['uom_pack'] ?? ''));

        $returnDetail =
            mcpUpdateDecimal(
                $item['return_quantity_detail'] ?? 0
            );

        $uomDetail =
            trim((string)($item['uom_detail'] ?? ''));

        $priceUnit =
            mcpUpdateDecimal($item['price_unit'] ?? 0);

        $price =
            mcpUpdateDecimal($item['price'] ?? 0);

        $submittedSubtotal =
            mcpUpdateDecimal(
                $item['return_subtotal'] ?? 0
            );

        $remarksDetail =
            trim((string)($item['remarks_detail'] ?? ''));

        if (
            $inventoryName === '' &&
            $returnQty <= 0 &&
            $returnPack <= 0 &&
            $returnDetail <= 0
        ) {
            continue;
        }

        if ($inventoryName === '') {
            throw new RuntimeException(
                'Inventory Name baris ' .
                ($index + 1) .
                ' wajib diisi.'
            );
        }

        if (
            $returnQty <= 0 &&
            $returnPack <= 0 &&
            $returnDetail <= 0
        ) {
            throw new RuntimeException(
                'Minimal salah satu quantity pada inventory ' .
                $inventoryName .
                ' harus lebih dari 0.'
            );
        }

        if ($uom === '') {
            throw new RuntimeException(
                'UoM Return pada inventory ' .
                $inventoryName .
                ' wajib diisi.'
            );
        }

        if ($priceUnit < 0 || $price < 0) {
            throw new RuntimeException(
                'Harga pada inventory ' .
                $inventoryName .
                ' tidak boleh negatif.'
            );
        }

        if ($returnPack > 0) {
            $returnSubtotal =
                round($price * $returnPack, 2);
        } else {
            $returnSubtotal =
                round($submittedSubtotal, 2);
        }

        if ($inventoryId === '') {
            $inventoryId =
                mcpUpdateGenerateInventoryId(
                    $conn,
                    $year,
                    $reservedInventoryIds
                );
        } else {
            $reservedInventoryIds[$inventoryId] = true;
        }

        $calculatedTotal += $returnSubtotal;

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

    $subtotal = round($calculatedTotal, 2);
    $grandTotal = $subtotal;

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

    if ($returnAmount <= 0 && $grandTotal > 0) {
        $returnAmount = $grandTotal;
    }

    $remarksReturn =
        'Sales Order MCP: ' .
        $externalOrderNo .
        ' | Shipping No. MCP: ' .
        $externalShippingNo;

    $shippingRemarks =
        'Internal Shipping CP-MCP. Shipping asli MCP: ' .
        $externalShippingNo .
        ' | Sales Order MCP: ' .
        $externalOrderNo;

    /*
    |--------------------------------------------------------------------------
    | UPDATE INTERNAL HEADER
    |--------------------------------------------------------------------------
    */
    mcpUpdateAvailable(
        $conn,
        'head_sales_order',
        [
            'order_date' => $returnDate,
            'po' => $externalOrderNo,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_city' => $customerCity,
            'currency' => $currency,
            'remarks' =>
                'Internal Sales Order CP-MCP. Sales Order asli MCP: ' .
                $externalOrderNo,
            'grand_total' => $grandTotal,
            'down_payment' => $downPayment,
            'user_modified' => $user,
            'date_modified' => date('Y-m-d H:i:s')
        ],
        'order_no',
        $internalOrderNo
    );

    mcpUpdateAvailable(
        $conn,
        'hed_shipping',
        [
            'shipping_date' => $returnDate,
            'order_no' => $internalOrderNo,
            'order_date' => $returnDate,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_city' => $customerCity,
            'remarks_shipping' => $shippingRemarks,
            'user_modified' => $user,
            'date_modified' => date('Y-m-d H:i:s')
        ],
        'shipping_no',
        $internalShippingNo
    );

    mcpUpdateAvailable(
        $conn,
        'head_invoice',
        [
            'invoice_date' => $returnDate,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_city' => $customerCity,
            'order_no' => $internalOrderNo,
            'order_date' => $returnDate,
            'currency' => $currency,
            'remarks_invoice' =>
                'Internal Invoice CP-MCP untuk Sales Return ' .
                $returnId .
                '. Invoice mewakili Calculated Detail Return.',
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            'down_payment' => $downPayment,
            'titip_applied' => $titipApplied,
            'payment_balance' => $paymentBalance,
            'piutang' => $grandTotal,
            'user_modified' => $user,
            'date_modified' => date('Y-m-d H:i:s')
        ],
        'invoice_no',
        $internalInvoiceNo
    );

    /*
    |--------------------------------------------------------------------------
    | HAPUS DETAIL RETURN DULU
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM detail_retur_invoice
         WHERE return_id = ?"
    );

    mysqli_stmt_bind_param($stmt, 's', $originalReturnId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | DELETE + RECREATE DET SHIPPING
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM det_shipping
         WHERE shipping_no = ?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $internalShippingNo
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    foreach ($validDetails as $k => $detail) {
        if (
            mcpUpdateColumnExists(
                $conn,
                'm_inventory',
                'inventory_id'
            ) &&
            !mcpUpdateExists(
                $conn,
                'm_inventory',
                'inventory_id',
                $detail['inventory_id']
            )
        ) {
            mcpUpdateInsertAvailable(
                $conn,
                'm_inventory',
                [
                    'inventory_id' => $detail['inventory_id'],
                    'inventory_name' => $detail['inventory_name'],
                    'uom' => $detail['uom'],
                    'type' => 'MCP',
                    'category' => 'LAIN LAIN',
                    'remarks' =>
                        'Internal inventory untuk Sales Return CP-MCP ' .
                        $returnId,
                    'is_active' => 'Checked',
                    'create_user' => $user,
                    'date_created' => date('Y-m-d H:i:s')
                ]
            );
        } elseif (
            mcpUpdateColumnExists(
                $conn,
                'm_inventory',
                'inventory_id'
            )
        ) {
            mcpUpdateAvailable(
                $conn,
                'm_inventory',
                [
                    'inventory_name' =>
                        $detail['inventory_name'],
                    'uom' => $detail['uom'],
                    'user_modified' => $user,
                    'date_modified' => date('Y-m-d H:i:s')
                ],
                'inventory_id',
                $detail['inventory_id']
            );
        }

        $shippingDetailId =
            mcpUpdateInsertAvailable(
                $conn,
                'det_shipping',
                [
                    'shipping_no' => $internalShippingNo,
                    'inventory_id' => $detail['inventory_id'],
                    'inventory_name' => $detail['inventory_name'],
                    'qty_shipping' => $detail['return_quantity'],
                    'uom_shipping' => $detail['uom'],
                    'qty_pack_shipping' =>
                        $detail['return_quantity_pack'],
                    'uom_pack_shipping' => $detail['uom_pack'],
                    'qty_detail_shipping' =>
                        $detail['return_quantity_detail'],
                    'uom_detail_shipping' => $detail['uom_detail'],
                    'price_unit' => $detail['price_unit'],
                    'subtotal' => $detail['return_subtotal'],
                    'remarks_inventory_shipping' =>
                        'Internal detail CP-MCP untuk return ' .
                        $returnId,
                    'note' => $detail['remarks_detail']
                ]
            );

        if ($shippingDetailId <= 0) {
            $idStmt = mysqli_prepare(
                $conn,
                "SELECT id
                 FROM det_shipping
                 WHERE shipping_no = ?
                   AND inventory_id = ?
                 ORDER BY id DESC
                 LIMIT 1"
            );

            mysqli_stmt_bind_param(
                $idStmt,
                'ss',
                $internalShippingNo,
                $detail['inventory_id']
            );

            mysqli_stmt_execute($idStmt);

            $idRow = mysqli_fetch_assoc(
                mysqli_stmt_get_result($idStmt)
            );

            mysqli_stmt_close($idStmt);

            $shippingDetailId =
                (int)($idRow['id'] ?? 0);
        }

        if ($shippingDetailId <= 0) {
            throw new RuntimeException(
                'Gagal mendapatkan shipping_detail_id untuk ' .
                $detail['inventory_name'] .
                '.'
            );
        }

        $validDetails[$k]['shipping_detail_id'] =
            $shippingDetailId;
    }

    /*
    |--------------------------------------------------------------------------
    | RECREATE DET INVOICE
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM det_invoice
         WHERE invoice_no = ?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $internalInvoiceNo
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    mcpUpdateInsertAvailable(
        $conn,
        'det_invoice',
        [
            'invoice_no' => $internalInvoiceNo,
            'shipping_no' => $internalShippingNo,
            'shipping_date' => $returnDate,
            'order_no' => $internalOrderNo,
            'subtotal' => $subtotal,
            'total' => $grandTotal,
            'remarks_shipping' => $shippingRemarks
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | UPDATE RETURN HEADER
    |--------------------------------------------------------------------------
    */
    mcpUpdateAvailable(
        $conn,
        'head_retur_invoice',
        [
            'return_id' => $returnId,
            'return_date' => $returnDate,
            'invoice_no' => $internalInvoiceNo,
            'invoice_date' => $returnDate,
            'shipping_no' => $internalShippingNo,
            'shipping_date' => $returnDate,
            'order_no' => $internalOrderNo,
            'order_date' => $returnDate,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_city' => $customerCity,
            'currency' => $currency,
            'reason_return' => $reasonReturn,
            'remarks_return' => $remarksReturn,
            'subtotal' => $subtotal,
            'grand_total' => $grandTotal,
            'down_payment' => $downPayment,
            'titip_applied' => $titipApplied,
            'payment_balance' => $paymentBalance,
            'return_amount' => $returnAmount,
            'remaining_invoice_balance' => $remainingBalance,
            'user_modified' => $user,
            'date_modified' => date('Y-m-d H:i:s')
        ],
        'return_id',
        $originalReturnId
    );

    /*
    |--------------------------------------------------------------------------
    | SINKRONKAN REFERENSI RETURN ID PADA PEMBAYARAN
    |--------------------------------------------------------------------------
    *
    * Jika Sales Return ID diganti, link audit pada detail_bayar juga harus
    * mengikuti agar histori pembayaran/retur tidak putus.
    */
    if (
        $returnId !== $originalReturnId &&
        mcpUpdateColumnExists($conn, 'detail_bayar', 'return_id')
    ) {
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE detail_bayar
             SET return_id = ?
             WHERE return_id = ?"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Gagal prepare sinkronisasi return_id pada detail_bayar: ' .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'ss',
            $returnId,
            $originalReturnId
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT RETURN DETAIL BARU
    |--------------------------------------------------------------------------
    */
    foreach ($validDetails as $detail) {
        mcpUpdateInsertAvailable(
            $conn,
            'detail_retur_invoice',
            [
                'return_id' => $returnId,
                'invoice_no' => $internalInvoiceNo,
                'shipping_no' => $internalShippingNo,
                'order_no' => $internalOrderNo,
                'shipping_detail_id' =>
                    (int)$detail['shipping_detail_id'],
                'inventory_id' => $detail['inventory_id'],
                'inventory_name' => $detail['inventory_name'],

                'original_quantity' => 0.0,
                'original_uom' => '',
                'original_quantity_pack' => 0.0,
                'original_uom_pack' => '',
                'original_quantity_detail' => 0.0,
                'original_uom_detail' => '',

                'return_quantity' =>
                    $detail['return_quantity'],
                'uom' => $detail['uom'],
                'return_quantity_pack' =>
                    $detail['return_quantity_pack'],
                'uom_pack' => $detail['uom_pack'],
                'return_quantity_detail' =>
                    $detail['return_quantity_detail'],
                'uom_detail' => $detail['uom_detail'],
                'pack_conversion_value' => 0.0,
                'price_unit' => $detail['price_unit'],
                'price' => $detail['price'],
                'original_subtotal' => 0.0,
                'return_subtotal' =>
                    $detail['return_subtotal'],
                'remarks_detail' => $detail['remarks_detail'],
                'create_user' => $user,
                'date_created' => date('Y-m-d H:i:s'),
                'user_modified' => $user,
                'date_modified' => date('Y-m-d H:i:s')
            ]
        );
    }

    mysqli_commit($conn);

    mcpUpdateRedirect(
        'success',
        "Sales Return CP-MCP {$returnId} berhasil diupdate.",
        'return_invoice'
    );

} catch (Throwable $e) {
    try {
        mysqli_rollback($conn);
    } catch (Throwable $ignore) {
    }

    mcpUpdateRedirect(
        'danger',
        'Error: ' . $e->getMessage(),
        'edit_return_mcp',
        $originalReturnId
    );
}