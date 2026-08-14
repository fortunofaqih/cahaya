<?php
// modul/transaksi/save_return_mcp.php
// Sales Return CP-MCP - Hybrid Customer

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

// [ALL EXISTING FUNCTIONS - mcpDecimal, mcpMoney, mcpParseDate, etc.]
// Saya tulis ulang dengan modifikasi pada bagian CUSTOMER

function mcpDecimal($value): float
{
    if (is_string($value)) {
        $value = trim($value);
        $value = str_replace(' ', '', $value);

        if (strpos($value, ',') !== false && strpos($value, '.') === false) {
            $value = str_replace(',', '.', $value);
        }
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function mcpMoney($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }

    if (is_int($value) || is_float($value)) {
        return (float)$value;
    }

    $value = trim((string)$value);

    if ($value === '') {
        return 0.0;
    }

    $value = preg_replace('/[^0-9,.\-]/', '', $value);

    if ($value === '' || $value === '-') {
        return 0.0;
    }

    $hasDot = strpos($value, '.') !== false;
    $hasComma = strpos($value, ',') !== false;

    if ($hasComma) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif ($hasDot) {
        $value = str_replace('.', '', $value);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function mcpParseDate($value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    foreach (['Y-m-d', 'd-M-Y', 'd-m-Y', 'd/m/Y'] as $format) {
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

    echo '<script>window.location.href=' .
        json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
        ';</script>';

    exit;
}

function mcpTableColumns(mysqli $conn, string $table): array
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

function mcpColumnExists(mysqli $conn, string $table, string $column): bool
{
    $columns = mcpTableColumns($conn, $table);
    return isset($columns[$column]);
}

function mcpInsertAvailable(
    mysqli $conn,
    string $table,
    array $values,
    array $rawExpressions = []
): int {
    $columns = mcpTableColumns($conn, $table);

    $fields = [];
    $placeholders = [];
    $params = [];
    $types = '';

    foreach ($values as $field => $value) {
        if (!isset($columns[$field])) {
            continue;
        }

        $fields[] = "`{$field}`";

        if (array_key_exists($field, $rawExpressions)) {
            $placeholders[] = $rawExpressions[$field];
            continue;
        }

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
        throw new RuntimeException("Tidak ada kolom yang cocok untuk insert ke {$table}.");
    }

    $sql = "INSERT INTO `{$table}` (" .
        implode(', ', $fields) .
        ") VALUES (" .
        implode(', ', $placeholders) .
        ")";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException(
            "Gagal prepare {$table}: " . mysqli_error($conn)
        );
    }

    if ($params) {
        mysqli_stmt_bind_param($stmt, $types, ...$params);
    }

    mysqli_stmt_execute($stmt);
    $insertId = (int)mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    return $insertId;
}

function mcpExists(
    mysqli $conn,
    string $table,
    string $column,
    string $value
): bool {
    $sql = "SELECT 1 FROM `{$table}` WHERE `{$column}` = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException(
            "Gagal cek data {$table}: " . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, 's', $value);
    mysqli_stmt_execute($stmt);

    $exists = (bool)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return $exists;
}

function mcpNextInternalNumber(
    mysqli $conn,
    string $table,
    string $column,
    string $prefix,
    int $pad
): string {
    $pattern = $prefix . '%';

    $sql = "
        SELECT COALESCE(
            MAX(
                CAST(
                    SUBSTRING(`{$column}`, LENGTH(?) + 1)
                    AS UNSIGNED
                )
            ),
            0
        ) AS max_no
        FROM `{$table}`
        WHERE `{$column}` LIKE ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException(
            "Gagal generate nomor {$table}.{$column}: " . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, 'ss', $prefix, $pattern);
    mysqli_stmt_execute($stmt);

    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $next = ((int)($row['max_no'] ?? 0)) + 1;

    return $prefix . str_pad(
        (string)$next,
        $pad,
        '0',
        STR_PAD_LEFT
    );
}

function mcpRomanMonth(int $month): string
{
    $roman = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV',
        5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII',
        9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'
    ];

    return $roman[$month] ?? (string)$month;
}

function mcpGenerateReturnId(
    mysqli $conn,
    string $returnDate
): string {
    $ts = strtotime($returnDate);
    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    $romanMonth = mcpRomanMonth($month);
    $suffix = '/CP-MCP/' . $romanMonth . '/' . $year;
    $pattern = '%' . $suffix;

    $sql = "
        SELECT COALESCE(
            MAX(
                CAST(
                    SUBSTRING_INDEX(return_id, '/', 1)
                    AS UNSIGNED
                )
            ),
            0
        ) AS max_no
        FROM head_retur_invoice
        WHERE return_id LIKE ?
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal generate Sales Return ID: ' . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, 's', $pattern);
    mysqli_stmt_execute($stmt);

    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $next = ((int)($row['max_no'] ?? 0)) + 1;

    return $next . $suffix;
}

function mcpGenerateInventoryId(
    mysqli $conn,
    int $year,
    array &$reservedIds
): string {
    $prefix = 'MCP-INV/' . $year . '-';
    $maxNo = 0;

    foreach (['detail_retur_invoice', 'm_inventory'] as $table) {
        if (!mcpColumnExists($conn, $table, 'inventory_id')) {
            continue;
        }

        $pattern = $prefix . '%';

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
        $candidate = $prefix . str_pad((string)$maxNo, 6, '0', STR_PAD_LEFT);
    } while (isset($reservedIds[$candidate]));

    $reservedIds[$candidate] = true;
    return $candidate;
}

function mcpReleaseLock(mysqli $conn, ?string $lockName): void
{
    if (!$lockName) {
        return;
    }

    try {
        $stmt = mysqli_prepare($conn, "SELECT RELEASE_LOCK(?)");

        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $lockName);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } catch (Throwable $ignore) {
        // Jangan menimpa error utama hanya karena release lock gagal.
    }
}

// =========================================================================
// ========================== MAIN PROCESSING ==============================
// =========================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcpRedirect('danger', 'Metode request tidak valid.', 'add_return_mcp');
}

/*
|--------------------------------------------------------------------------
| INPUT FORM
|--------------------------------------------------------------------------
*/
$submittedReturnId = trim((string)($_POST['return_id'] ?? ''));
$returnDate = mcpParseDate($_POST['return_date'] ?? '');

$externalOrderNo = trim((string)($_POST['order_no'] ?? ''));
$externalShippingNo = trim((string)($_POST['shipping_no'] ?? ''));

// CUSTOMER HYBRID PROCESSING
$customerRef = trim((string)($_POST['customer_ref'] ?? ''));
$isNewCustomer = isset($_POST['is_new_customer']) && $_POST['is_new_customer'] == '1';
$customerNameFromForm = trim((string)($_POST['customer_name'] ?? ''));

// Manual customer data
$manualCustomerName = trim((string)($_POST['manual_customer_name'] ?? ''));
$manualCity = trim((string)($_POST['manual_city'] ?? ''));
$manualAddress = trim((string)($_POST['manual_address'] ?? ''));
$manualNpwp = trim((string)($_POST['manual_npwp'] ?? ''));
$manualPhone = trim((string)($_POST['manual_phone'] ?? ''));

$currency = trim((string)($_POST['currency'] ?? 'IDR'));
$reasonReturn = trim((string)($_POST['reason_return'] ?? ''));

$downPayment = mcpMoney($_POST['down_payment'] ?? 0);
$titipApplied = mcpMoney($_POST['titip_applied'] ?? 0);
$paymentBalance = mcpMoney($_POST['payment_balance'] ?? 0);
$returnAmount = mcpMoney($_POST['return_amount'] ?? 0);
$remainingBalance = mcpMoney($_POST['remaining_invoice_balance'] ?? 0);

$items = $_POST['items'] ?? [];
$user = (string)($_SESSION['username'] ?? 'SYSTEM');

// VALIDASI CUSTOMER
$customerId = '';
$customerName = '';
$customerAddress = $manualAddress;
$customerCity = $manualCity;

if ($isNewCustomer) {
    // ===== MODE: CUSTOMER BARU =====
    if (empty($manualCustomerName)) {
        mcpRedirect(
            'danger',
            'Nama Customer wajib diisi untuk customer baru.',
            'add_return_mcp'
        );
    }
    
    $customerName = $manualCustomerName;
    
    // Cek apakah customer sudah ada di database
    $checkStmt = mysqli_prepare(
        $conn,
        "SELECT customer_id, customer FROM m_customer WHERE customer = ?"
    );
    mysqli_stmt_bind_param($checkStmt, 's', $customerName);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);
    $existing = mysqli_fetch_assoc($checkResult);
    mysqli_stmt_close($checkStmt);
    
    if ($existing) {
        // Customer sudah ada, gunakan yang existing
        $customerId = $existing['customer_id'];
        $customerName = $existing['customer'];
        
        // Update data jika ada perubahan
        $updateStmt = mysqli_prepare(
            $conn,
            "UPDATE m_customer 
             SET city = COALESCE(?, city),
                 address = COALESCE(?, address),
                 npwp = COALESCE(?, npwp),
                 phone = COALESCE(?, phone),
                 date_modified = NOW(),
                 user_modified = ?
             WHERE customer_id = ?"
        );
        mysqli_stmt_bind_param(
            $updateStmt,
            'ssssss',
            $manualCity,
            $manualAddress,
            $manualNpwp,
            $manualPhone,
            $user,
            $customerId
        );
        mysqli_stmt_execute($updateStmt);
        mysqli_stmt_close($updateStmt);
        
    } else {
        // ===== GENERATE CUSTOMER ID =====
        // Format: CUST-YYYYMMDD-XXX
        $prefix = 'CUST-' . date('Ymd') . '-';
        $maxSql = "SELECT MAX(CAST(SUBSTRING(customer_id, LENGTH(?) + 1) AS UNSIGNED)) AS max_no 
                   FROM m_customer 
                   WHERE customer_id LIKE ?";
        $maxStmt = mysqli_prepare($conn, $maxSql);
        $pattern = $prefix . '%';
        mysqli_stmt_bind_param($maxStmt, 'ss', $prefix, $pattern);
        mysqli_stmt_execute($maxStmt);
        $maxRow = mysqli_fetch_assoc(mysqli_stmt_get_result($maxStmt));
        mysqli_stmt_close($maxStmt);
        
        $nextNo = ((int)($maxRow['max_no'] ?? 0)) + 1;
        $customerId = $prefix . str_pad((string)$nextNo, 3, '0', STR_PAD_LEFT);
        
        // ===== INSERT CUSTOMER BARU KE M_CUSTOMER =====
        $insertCustomer = "
            INSERT INTO m_customer (
                customer_id,
                customer,
                city,
                address,
                npwp,
                phone,
                is_active,
                user_created,
                date_created
            ) VALUES (?, ?, ?, ?, ?, ?, 'Checked', ?, NOW())
        ";
        
        $insertStmt = mysqli_prepare($conn, $insertCustomer);
        mysqli_stmt_bind_param(
            $insertStmt,
            'sssssss',
            $customerId,
            $customerName,
            $manualCity,
            $manualAddress,
            $manualNpwp,
            $manualPhone,
            $user
        );
        mysqli_stmt_execute($insertStmt);
        mysqli_stmt_close($insertStmt);
    }
    
} else {
    // ===== MODE: CUSTOMER EXISTING =====
    if (empty($customerRef)) {
        mcpRedirect(
            'danger',
            'Silakan pilih Customer atau tambahkan customer baru.',
            'add_return_mcp'
        );
    }
    
    // Ambil data customer dari m_customer
    $custStmt = mysqli_prepare(
        $conn,
        "SELECT customer_id, customer, city, address 
         FROM m_customer 
         WHERE customer_id = ? AND is_active = 'Checked'"
    );
    mysqli_stmt_bind_param($custStmt, 's', $customerRef);
    mysqli_stmt_execute($custStmt);
    $custResult = mysqli_stmt_get_result($custStmt);
    $custData = mysqli_fetch_assoc($custResult);
    mysqli_stmt_close($custStmt);
    
    if (!$custData) {
        // Fallback: coba ambil dari head_invoice
        $fallbackStmt = mysqli_prepare(
            $conn,
            "SELECT customer_id, customer_name, customer_address, customer_city 
             FROM head_invoice 
             WHERE customer_id = ? 
             ORDER BY invoice_date DESC, invoice_no DESC 
             LIMIT 1"
        );
        mysqli_stmt_bind_param($fallbackStmt, 's', $customerRef);
        mysqli_stmt_execute($fallbackStmt);
        $fallbackResult = mysqli_stmt_get_result($fallbackStmt);
        $fallbackData = mysqli_fetch_assoc($fallbackResult);
        mysqli_stmt_close($fallbackStmt);
        
        if ($fallbackData) {
            $customerId = $fallbackData['customer_id'];
            $customerName = $fallbackData['customer_name'] ?? '';
            $customerAddress = $fallbackData['customer_address'] ?? '';
            $customerCity = $fallbackData['customer_city'] ?? '';
        } else {
            throw new RuntimeException('Customer tidak ditemukan. ID: ' . $customerRef);
        }
    } else {
        $customerId = $custData['customer_id'];
        $customerName = $custData['customer'] ?? $customerNameFromForm;
        $customerAddress = $custData['address'] ?? '';
        $customerCity = $custData['city'] ?? '';
    }
}

// Validasi tambahan
if (empty($customerId) || empty($customerName)) {
    mcpRedirect(
        'danger',
        'Data Customer tidak valid. Customer ID: ' . $customerId,
        'add_return_mcp'
    );
}

// [REST OF VALIDATIONS - SAME AS ORIGINAL]
if (
    !$returnDate ||
    $externalOrderNo === '' ||
    $externalShippingNo === '' ||
    $customerId === ''
) {
    mcpRedirect(
        'danger',
        'Return Date, Sales Order MCP, Shipping No. MCP, dan Customer wajib diisi.',
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

if (!is_array($items) || !$items) {
    mcpRedirect(
        'danger',
        'Detail inventory retur belum diisi.',
        'add_return_mcp'
    );
}

if ($currency === '') {
    $currency = 'IDR';
}

$globalLockName = null;

try {
    mysqli_begin_transaction($conn);

    /*
    |--------------------------------------------------------------------------
    | LOCK GENERATOR
    |--------------------------------------------------------------------------
    */
    $year = (int)date('Y', strtotime($returnDate));
    $globalLockName = 'CP_MCP_' . $year;

    $lockStmt = mysqli_prepare(
        $conn,
        "SELECT GET_LOCK(?, 10) AS locked"
    );

    if (!$lockStmt) {
        throw new RuntimeException(
            'Gagal prepare lock CP-MCP: ' . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($lockStmt, 's', $globalLockName);
    mysqli_stmt_execute($lockStmt);

    $lockRow = mysqli_fetch_assoc(mysqli_stmt_get_result($lockStmt));
    mysqli_stmt_close($lockStmt);

    if ((int)($lockRow['locked'] ?? 0) !== 1) {
        throw new RuntimeException(
            'Nomor CP-MCP sedang digunakan user lain. Silakan save kembali.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RETURN ID
    |--------------------------------------------------------------------------
    */
    $returnId = $submittedReturnId;

    if (
        $returnId === '' ||
        mcpExists($conn, 'head_retur_invoice', 'return_id', $returnId)
    ) {
        $returnId = mcpGenerateReturnId($conn, $returnDate);
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNAL ID
    |--------------------------------------------------------------------------
    */
    $internalOrderNo = mcpNextInternalNumber(
        $conn,
        'head_sales_order',
        'order_no',
        'CP-MCP/SO/' . $year . '/',
        5
    );

    $internalShippingNo = mcpNextInternalNumber(
        $conn,
        'hed_shipping',
        'shipping_no',
        'CP-MCP/SJ/' . $year . '/',
        5
    );

    $internalInvoiceNo = mcpNextInternalNumber(
        $conn,
        'head_invoice',
        'invoice_no',
        'CP-MCP/INV/' . $year . '/',
        5
    );

    /*
    |--------------------------------------------------------------------------
    | DETAIL VALIDATION
    |--------------------------------------------------------------------------
    */
    $validDetails = [];
    $detailCalculatedTotal = 0.0;
    $reservedInventoryIds = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $inventoryName = trim((string)($item['inventory_name'] ?? ''));

        $returnQty = mcpDecimal($item['return_quantity'] ?? 0);
        $uom = trim((string)($item['uom'] ?? ''));

        $returnPack = mcpDecimal($item['return_quantity_pack'] ?? 0);
        $uomPack = trim((string)($item['uom_pack'] ?? ''));

        $returnDetail = mcpDecimal($item['return_quantity_detail'] ?? 0);
        $uomDetail = trim((string)($item['uom_detail'] ?? ''));

        $priceUnit = mcpMoney($item['price_unit'] ?? 0);
        $price = mcpMoney($item['price'] ?? 0);
        $submittedSubtotal = mcpMoney($item['return_subtotal'] ?? 0);
        $remarksDetail = trim((string)($item['remarks_detail'] ?? ''));

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
                'Inventory Name pada baris ' . ($index + 1) . ' wajib diisi.'
            );
        }

        if (
            $returnQty <= 0 &&
            $returnPack <= 0 &&
            $returnDetail <= 0
        ) {
            throw new RuntimeException(
                'Minimal salah satu quantity pada baris ' .
                ($index + 1) .
                ' harus lebih dari 0.'
            );
        }

        if ($uom === '') {
            throw new RuntimeException(
                'UoM pada inventory ' . $inventoryName . ' wajib diisi.'
            );
        }

        if ($priceUnit < 0 || $price < 0) {
            throw new RuntimeException(
                'Harga pada inventory ' . $inventoryName . ' tidak boleh negatif.'
            );
        }

        if ($returnPack > 0) {
            $returnSubtotal = round($price * $returnPack, 2);
        } else {
            $returnSubtotal = round($submittedSubtotal, 2);
        }

        if ($returnSubtotal < 0) {
            throw new RuntimeException(
                'Return Subtotal pada inventory ' .
                $inventoryName .
                ' tidak boleh negatif.'
            );
        }

        $inventoryId = mcpGenerateInventoryId(
            $conn,
            $year,
            $reservedInventoryIds
        );

        $detailCalculatedTotal += $returnSubtotal;

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

    /*
    |--------------------------------------------------------------------------
    | CALCULATED DETAIL RETURN
    |--------------------------------------------------------------------------
    */
    $subtotal = round($detailCalculatedTotal, 2);
    $grandTotal = $subtotal;

    if ($returnAmount < 0) {
        throw new RuntimeException('Return Amount tidak boleh negatif.');
    }

    if ($remainingBalance < 0) {
        throw new RuntimeException('Balance After Return tidak boleh negatif.');
    }

    if ($returnAmount <= 0 && $grandTotal > 0) {
        $returnAmount = $grandTotal;
    }

    /*
    |--------------------------------------------------------------------------
    | REMARKS RETURN
    |--------------------------------------------------------------------------
    */
    $remarksReturn =
        'Sales Order MCP: ' . $externalOrderNo .
        ' | Shipping No. MCP: ' . $externalShippingNo;

    /*
    |--------------------------------------------------------------------------
    | 1. INTERNAL SALES ORDER
    |--------------------------------------------------------------------------
    */
    mcpInsertAvailable(
        $conn,
        'head_sales_order',
        [
            'order_no' => $internalOrderNo,
            'order_date' => $returnDate,
            'po' => $externalOrderNo,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_city' => $customerCity,
            'station' => 'Factory',
            'currency' => $currency,
            'remarks' => 'Internal Sales Order CP-MCP. Sales Order asli MCP: ' . $externalOrderNo,
            'status' => 'Open',
            'approval' => 'Pending',
            'grand_total' => $grandTotal,
            'down_payment' => $downPayment,
            'create_user' => $user,
            'date_created' => date('Y-m-d H:i:s')
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | 2. INTERNAL SHIPPING HEADER
    |--------------------------------------------------------------------------
    */
    $shippingRemarks =
        'Internal Shipping CP-MCP. Shipping asli MCP: ' .
        $externalShippingNo .
        ' | Sales Order MCP: ' .
        $externalOrderNo;

    mcpInsertAvailable(
        $conn,
        'hed_shipping',
        [
            'shipping_no' => $internalShippingNo,
            'shipping_date' => $returnDate,
            'order_no' => $internalOrderNo,
            'order_date' => $returnDate,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_city' => $customerCity,
            'gudang_id' => 'FC02',
            'remarks_shipping' => $shippingRemarks,
            'status' => 'Open',
            'create_user' => $user,
            'date_created' => date('Y-m-d H:i:s')
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | 3. INTERNAL SHIPPING DETAIL
    |--------------------------------------------------------------------------
    */
    foreach ($validDetails as $k => $detail) {
        if (
            mcpColumnExists($conn, 'm_inventory', 'inventory_id') &&
            !mcpExists(
                $conn,
                'm_inventory',
                'inventory_id',
                $detail['inventory_id']
            )
        ) {
            mcpInsertAvailable(
                $conn,
                'm_inventory',
                [
                    'inventory_id' => $detail['inventory_id'],
                    'inventory_name' => $detail['inventory_name'],
                    'uom' => $detail['uom'],
                    'type' => 'MCP',
                    'category' => 'LAIN LAIN',
                    'remarks' => 'Internal inventory untuk Sales Return CP-MCP ' . $returnId,
                    'is_active' => 'Checked',
                    'create_user' => $user,
                    'date_created' => date('Y-m-d H:i:s')
                ]
            );
        }

        $shippingDetailId = mcpInsertAvailable(
            $conn,
            'det_shipping',
            [
                'shipping_no' => $internalShippingNo,
                'inventory_id' => $detail['inventory_id'],
                'inventory_name' => $detail['inventory_name'],
                'qty_shipping' => $detail['return_quantity'],
                'uom_shipping' => $detail['uom'],
                'qty_pack_shipping' => $detail['return_quantity_pack'],
                'uom_pack_shipping' => $detail['uom_pack'],
                'qty_detail_shipping' => $detail['return_quantity_detail'],
                'uom_detail_shipping' => $detail['uom_detail'],
                'price_unit' => $detail['price_unit'],
                'subtotal' => $detail['return_subtotal'],
                'remarks_inventory_shipping' =>
                    'Internal detail CP-MCP untuk return ' . $returnId,
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

            $idRow = mysqli_fetch_assoc(mysqli_stmt_get_result($idStmt));
            mysqli_stmt_close($idStmt);

            $shippingDetailId = (int)($idRow['id'] ?? 0);
        }

        if ($shippingDetailId <= 0) {
            throw new RuntimeException(
                'Gagal mendapatkan shipping_detail_id untuk inventory ' .
                $detail['inventory_name'] .
                '.'
            );
        }

        $validDetails[$k]['shipping_detail_id'] = $shippingDetailId;
    }

    /*
    |--------------------------------------------------------------------------
    | 4. INTERNAL INVOICE HEADER
    |--------------------------------------------------------------------------
    */
    mcpInsertAvailable(
        $conn,
        'head_invoice',
        [
            'invoice_no' => $internalInvoiceNo,
            'invoice_date' => $returnDate,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_address' => $customerAddress,
            'customer_city' => $customerCity,
            'order_no' => $internalOrderNo,
            'order_date' => $returnDate,
            'station' => 'Factory',
            'payment_type' => '',
            'payment_term' => '',
            'days' => 0,
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
            'status' => 'Open',
            'approval_status' => 'Pending',
            'create_user' => $user,
            'date_created' => date('Y-m-d H:i:s')
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | 5. INTERNAL INVOICE DETAIL
    |--------------------------------------------------------------------------
    */
    mcpInsertAvailable(
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
    | 6. RETURN HEADER
    |--------------------------------------------------------------------------
    */
    mcpInsertAvailable(
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
            'status' => 'Open',
            'approval_status' => 'Pending',
            'create_user' => $user,
            'date_created' => date('Y-m-d H:i:s')
        ]
    );

    /*
    |--------------------------------------------------------------------------
    | 7. RETURN DETAIL
    |--------------------------------------------------------------------------
    */
    foreach ($validDetails as $detail) {
        mcpInsertAvailable(
            $conn,
            'detail_retur_invoice',
            [
                'return_id' => $returnId,
                'invoice_no' => $internalInvoiceNo,
                'shipping_no' => $internalShippingNo,
                'order_no' => $internalOrderNo,
                'shipping_detail_id' => (int)$detail['shipping_detail_id'],
                'inventory_id' => $detail['inventory_id'],
                'inventory_name' => $detail['inventory_name'],
                'original_quantity' => 0.0,
                'original_uom' => '',
                'original_quantity_pack' => 0.0,
                'original_uom_pack' => '',
                'original_quantity_detail' => 0.0,
                'original_uom_detail' => '',
                'return_quantity' => $detail['return_quantity'],
                'uom' => $detail['uom'],
                'return_quantity_pack' => $detail['return_quantity_pack'],
                'uom_pack' => $detail['uom_pack'],
                'return_quantity_detail' => $detail['return_quantity_detail'],
                'uom_detail' => $detail['uom_detail'],
                'pack_conversion_value' => 0.0,
                'price_unit' => $detail['price_unit'],
                'price' => $detail['price'],
                'original_subtotal' => 0.0,
                'return_subtotal' => $detail['return_subtotal'],
                'remarks_detail' => $detail['remarks_detail'],
                'create_user' => $user,
                'date_created' => date('Y-m-d H:i:s')
            ]
        );
    }

    mysqli_commit($conn);
    mcpReleaseLock($conn, $globalLockName);
    $globalLockName = null;

    // Cek apakah customer baru
    $customerNote = $isNewCustomer ? ' (Customer baru: ' . $customerId . ')' : '';

    mcpRedirect(
        'success',
        'Sales Return CP-MCP ' .
        $returnId .
        ' berhasil disimpan.' . $customerNote .
        ' Internal SO: ' .
        $internalOrderNo .
        ', Shipping: ' .
        $internalShippingNo .
        ', Invoice: ' .
        $internalInvoiceNo .
        '.',
        'return_invoice'
    );

} catch (Throwable $e) {
    try {
        mysqli_rollback($conn);
    } catch (Throwable $ignore) {
        // Abaikan bila transaksi belum aktif.
    }

    mcpReleaseLock($conn, $globalLockName);

    mcpRedirect(
        'danger',
        'Error: ' . $e->getMessage(),
        'add_return_mcp'
    );
}