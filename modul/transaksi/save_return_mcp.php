<?php
// modul/transaksi/save_return_mcp.php
// Sales Return CP-MCP - versi relasi internal lengkap.
//
// Alur:
// 1. Finance input Sales Order MCP + Shipping No MCP + Customer + detail return.
// 2. Sistem membuat referensi INTERNAL:
//      CP-MCP/SO/YYYY/00001
//      CP-MCP/SJ/YYYY/00001
//      CP-MCP/INV/YYYY/00001
//      MCP-INV/YYYY-000001 (per detail; hidden di form, diverifikasi saat save)
// 3. Sistem membuat:
//      head_sales_order (internal)
//      hed_shipping (internal)
//      det_shipping (internal, per item)
//      head_invoice (internal)
//      det_invoice (internal)
//      head_retur_invoice
//      detail_retur_invoice
// 4. Sales Order MCP asli dan Shipping No MCP asli disimpan di remarks_return.
//
// Tujuan desain ini:
// - Tidak memakai FK kosong / angka 0.
// - shipping_detail_id selalu menunjuk det_shipping.id yang valid.
// - invoice_no/shipping_no/order_no pada return menunjuk record internal yang valid.
// - Calculated Detail Return menjadi subtotal + grand_total internal invoice.

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
        $value = trim($value);
        $value = str_replace(' ', '', $value);

        /*
         * Dipakai untuk quantity/desimal biasa.
         * Contoh:
         * 10.125  -> 10.125
         * 10,125  -> 10.125
         */
        if (strpos($value, ',') !== false && strpos($value, '.') === false) {
            $value = str_replace(',', '.', $value);
        }
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

/**
 * Parser nilai uang / Rupiah dari form.
 *
 * Contoh input:
 * 100.000       => 100000
 * 1.000.000     => 1000000
 * Rp 1.000.000  => 1000000
 * 1000000       => 1000000
 *
 * Bila ada format Indonesia dengan desimal:
 * 1.000.000,50  => 1000000.50
 */
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

    // Buang Rp, spasi, dan karakter selain angka, titik, koma, minus.
    $value = preg_replace('/[^0-9,.\-]/', '', $value);

    if ($value === '' || $value === '-') {
        return 0.0;
    }

    $hasDot = strpos($value, '.') !== false;
    $hasComma = strpos($value, ',') !== false;

    if ($hasComma) {
        /*
         * Format Indonesia:
         * 1.000.000,50
         * 100.000,00
         */
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif ($hasDot) {
        /*
         * Untuk field Rupiah, titik dianggap separator ribuan.
         * 100.000   => 100000
         * 1.000.000 => 1000000
         *
         * JavaScript add_return_mcp juga membersihkan separator
         * sebelum submit, tetapi server tetap dibuat aman.
         */
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

    // Nama tabel berasal dari kode, bukan input user.
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

/**
 * Insert hanya kolom yang benar-benar tersedia pada tabel.
 * Ini membantu kompatibilitas bila versi tabel produksi/local berbeda sedikit.
 */
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

$customerId = trim((string)($_POST['customer_ref'] ?? ''));
$customerNameFromForm = trim((string)($_POST['customer_name'] ?? ''));

$currency = trim((string)($_POST['currency'] ?? 'IDR'));
$reasonReturn = trim((string)($_POST['reason_return'] ?? ''));

$downPayment = mcpMoney($_POST['down_payment'] ?? 0);
$titipApplied = mcpMoney($_POST['titip_applied'] ?? 0);
$paymentBalance = mcpMoney($_POST['payment_balance'] ?? 0);
$returnAmount = mcpMoney($_POST['return_amount'] ?? 0);
$remainingBalance = mcpMoney($_POST['remaining_invoice_balance'] ?? 0);

$items = $_POST['items'] ?? [];
$user = (string)($_SESSION['username'] ?? 'SYSTEM');

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
    | Satu lock pendek per tahun cukup untuk SO/SJ/INV/Inventory/Return MCP.
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
    | CUSTOMER
    |--------------------------------------------------------------------------
    */
    $customerName = $customerNameFromForm;
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
        $customerName = trim((string)($customerRow['customer_name'] ?? ''));
        $customerAddress = (string)($customerRow['customer_address'] ?? '');
        $customerCity = (string)($customerRow['customer_city'] ?? '');
    }

    if ($customerName === '') {
        throw new RuntimeException('Customer Name tidak ditemukan.');
    }

    /*
    |--------------------------------------------------------------------------
    | RETURN ID
    |--------------------------------------------------------------------------
    | Gunakan nomor dari form hanya bila belum dipakai. Jika bentrok/blank,
    | generate ulang berdasarkan Return Date.
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

        /*
         * Jangan percaya inventory_id hidden mentah dari browser.
         * Generate ulang di server agar unik.
         */
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

    /*
     * Bila Finance belum mengubah Return Amount dari default 0,
     * gunakan Calculated Detail Return agar return tidak tersimpan 0.
     */
    if ($returnAmount <= 0 && $grandTotal > 0) {
        $returnAmount = $grandTotal;
    }

    /*
    |--------------------------------------------------------------------------
    | REMARKS RETURN
    |--------------------------------------------------------------------------
    | Remarks input user di-hidden; server menjadi sumber kebenaran.
    |--------------------------------------------------------------------------
    */
    $remarksReturn =
        'Sales Order MCP: ' . $externalOrderNo .
        ' | Shipping No. MCP: ' . $externalShippingNo;

    /*
    |--------------------------------------------------------------------------
    | 1. INTERNAL SALES ORDER
    |--------------------------------------------------------------------------
    | Membuat referensi internal supaya bila shipping/return memiliki FK order_no,
    | nilainya tetap valid.
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
    | shipping_detail_id untuk detail return diambil dari det_shipping.id
    | yang benar-benar baru dibuat.
    |--------------------------------------------------------------------------
    */
    foreach ($validDetails as $k => $detail) {
        /*
         * Jika m_inventory ada dan inventory_id belum ada, buat master internal.
         * Ini mencegah FK det_shipping.inventory_id -> m_inventory.inventory_id
         * bila constraint tersebut aktif.
         */
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
            /*
             * Jika id bukan AUTO_INCREMENT, cari ID record yang baru dibuat.
             */
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

                // Original tidak dipakai pada MCP.
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

    mcpRedirect(
        'success',
        'Sales Return CP-MCP ' .
        $returnId .
        ' berhasil disimpan. Internal SO: ' .
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