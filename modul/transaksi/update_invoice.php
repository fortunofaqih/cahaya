<?php
// modul/transaksi/update_invoice.php
// Update invoice terpisah dan konsisten dengan add_invoice.php.
// Rule utama:
// 1. Semua inventory termasuk yang mengandung "ONGKOS" => subtotal = price × Qty Pack,
//    atau price × Qty jika Qty Pack tidak tersedia.
// 3. Shipping yang sedang dimiliki invoice ini tetap boleh dipilih.
// 4. Shipping yang sudah dipakai invoice lain tidak boleh digunakan.

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

function cleanInput($data)
{
    if (is_array($data)) {
        return array_map('cleanInput', $data);
    }

    return trim((string)$data);
}

function postValue(string $key, $default = '')
{
    return isset($_POST[$key]) ? cleanInput($_POST[$key]) : $default;
}

function postArray(string $key): array
{
    return isset($_POST[$key]) && is_array($_POST[$key])
        ? array_values($_POST[$key])
        : [];
}

function parseMoney($value): float
{
    $value = trim((string)$value);

    if ($value === '') {
        return 0.0;
    }

    $value = str_replace(' ', '', $value);

    /*
     * Mendukung:
     * 1.234.567,89
     * 1,234,567.89
     * 1234567.89
     */
    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        if (strrpos($value, ',') > strrpos($value, '.')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif (strpos($value, ',') !== false) {
        $value = str_replace(',', '.', $value);
    }

    $value = preg_replace('/[^0-9.\-]/', '', $value);

    return is_numeric($value) ? (float)$value : 0.0;
}

/**
 * Semua nominal invoice disimpan sebagai Rupiah penuh.
 * Pembulatan normal / HALF UP:
 * 84.911.059,49 => 84.911.059
 * 84.911.059,50 => 84.911.060
 */
function roundInvoiceMoney($value): float
{
    return (float) round((float)$value, 0, PHP_ROUND_HALF_UP);
}

function toDbDate($value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    $months = [
        'jan' => '01',
        'feb' => '02',
        'mar' => '03',
        'apr' => '04',
        'mei' => '05',
        'may' => '05',
        'jun' => '06',
        'jul' => '07',
        'agu' => '08',
        'aug' => '08',
        'ags' => '08',
        'sep' => '09',
        'okt' => '10',
        'oct' => '10',
        'nov' => '11',
        'des' => '12',
        'dec' => '12',
    ];

    $parts = explode('-', $value);

    if (count($parts) === 3) {
        $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
        $monthKey = strtolower($parts[1]);
        $year = $parts[2];

        if (
            isset($months[$monthKey]) &&
            preg_match('/^\d{4}$/', $year)
        ) {
            return $year . '-' . $months[$monthKey] . '-' . $day;
        }
    }

    $timestamp = strtotime($value);

    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function redirectWithAlert(string $type, string $message, string $url): void
{
    $_SESSION['alert'] =
        '<div class="alert alert-' .
        htmlspecialchars($type, ENT_QUOTES, 'UTF-8') .
        '">' .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '</div>';

    echo "<script>";
    echo "alert('" . addslashes($message) . "');";
    echo "window.location.href='" . addslashes($url) . "';";
    echo "</script>";
    exit;
}

function loadInvoiceForUpdate(mysqli $conn, string $invoiceNo): ?array
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM head_invoice
         WHERE invoice_no = ?
         LIMIT 1
         FOR UPDATE"
    );

    mysqli_stmt_bind_param($stmt, 's', $invoiceNo);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result) ?: null;

    mysqli_stmt_close($stmt);

    return $row;
}

function shippingUsedByOtherInvoice(
    mysqli $conn,
    string $shippingNo,
    string $currentInvoiceNo
): bool {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM det_invoice
         WHERE shipping_no = ?
           AND invoice_no <> ?
         LIMIT 1"
    );

    mysqli_stmt_bind_param(
        $stmt,
        'ss',
        $shippingNo,
        $currentInvoiceNo
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);

    $exists = mysqli_stmt_num_rows($stmt) > 0;

    mysqli_stmt_close($stmt);

    return $exists;
}


/**
 * Jika Customer ID invoice berubah, jangan pindahkan invoice yang sudah
 * mempunyai pembayaran/retur/deposit karena akan menyebabkan ledger customer
 * lama dan baru menjadi tidak konsisten.
 */
function assertCustomerChangeSafe(
    mysqli $conn,
    string $invoiceNo,
    string $oldCustomerId,
    string $newCustomerId
): void {
    if ($oldCustomerId === $newCustomerId) {
        return;
    }

    $checks = [
        [
            'sql' => "SELECT 1 FROM detail_bayar WHERE invoice_no = ? LIMIT 1",
            'message' => 'Invoice sudah memiliki transaksi pembayaran.'
        ],
        [
            'sql' => "SELECT 1 FROM head_retur_invoice WHERE invoice_no = ? LIMIT 1",
            'message' => 'Invoice sudah memiliki transaksi retur.'
        ],
        [
            'sql' => "SELECT 1 FROM invoice_deposit_application WHERE invoice_no = ? LIMIT 1",
            'message' => 'Invoice sudah memiliki aplikasi deposit/titip.'
        ],
    ];

    foreach ($checks as $check) {
        $stmt = mysqli_prepare($conn, $check['sql']);
        mysqli_stmt_bind_param($stmt, 's', $invoiceNo);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $exists = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);

        if ($exists) {
            throw new RuntimeException(
                "Customer invoice tidak dapat dipindahkan dari $oldCustomerId ke $newCustomerId. " .
                $check['message'] .
                ' Koreksi transaksi terkait terlebih dahulu agar Kartu Piutang/Aging tidak berpindah customer secara sepihak.'
            );
        }
    }
}

/**
 * Memastikan customer sumber masih ada di master aktif.
 */
function assertActiveCustomer(mysqli $conn, string $customerId): void
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM m_customer
         WHERE customer_id = ?
           AND COALESCE(is_active, 'Checked') = 'Checked'
         LIMIT 1"
    );

    mysqli_stmt_bind_param($stmt, 's', $customerId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);

    if (!$exists) {
        throw new RuntimeException(
            "Customer sumber $customerId tidak ditemukan atau tidak aktif di master customer."
        );
    }
}

/**
 * Mengambil subtotal satu shipping dengan rule yang sama seperti add_invoice:
 *
 * Semua inventory termasuk ONGKOS:
 * subtotal = price × (qty_pack jika > 0, selain itu qty)
 */
function calculateShippingInvoice(
    mysqli $conn,
    string $shippingNo,
    string $expectedOrderNo
): ?array {
    $stmtHeader = mysqli_prepare(
        $conn,
        "SELECT
            hs.shipping_no,
            hs.shipping_date,
            hs.order_no,
            hs.customer_id,
            hs.customer_name,
            hs.customer_address,
            hs.customer_city,
            COALESCE(hs.remarks_shipping, '') AS remarks_shipping
         FROM hed_shipping hs
         WHERE hs.shipping_no = ?
           AND hs.order_no = ?
         LIMIT 1"
    );

    mysqli_stmt_bind_param(
        $stmtHeader,
        'ss',
        $shippingNo,
        $expectedOrderNo
    );

    mysqli_stmt_execute($stmtHeader);
    $headerResult = mysqli_stmt_get_result($stmtHeader);
    $shipping = mysqli_fetch_assoc($headerResult) ?: null;
    mysqli_stmt_close($stmtHeader);

    if (!$shipping) {
        return null;
    }

    /*
     * Harga SO diringkas per inventory untuk menghindari penggandaan baris
     * ketika join langsung dilakukan terhadap detail_sales_order.
     */
    $stmtItems = mysqli_prepare(
        $conn,
        "SELECT
            ds.inventory_id,
            ds.inventory_name,
            COALESCE(ds.qty_shipping, 0) AS qty_shipping,
            COALESCE(ds.qty_pack_shipping, 0) AS qty_pack_shipping,
            COALESCE(so_price.price, 0) AS invoice_price
         FROM det_shipping ds
         LEFT JOIN (
             SELECT
                 order_no,
                 inventory_id,
                 MAX(
                     CASE
                         WHEN COALESCE(price, 0) > 0 THEN price
                         WHEN COALESCE(quantity_pack, 0) > 0
                             THEN subtotal / NULLIF(quantity_pack, 0)
                         WHEN COALESCE(quantity, 0) > 0
                             THEN subtotal / NULLIF(quantity, 0)
                         ELSE 0
                     END
                 ) AS price
             FROM detail_sales_order
             GROUP BY order_no, inventory_id
         ) so_price
           ON so_price.order_no = ?
          AND so_price.inventory_id = ds.inventory_id
         WHERE ds.shipping_no = ?
         ORDER BY ds.id ASC"
    );

    mysqli_stmt_bind_param(
        $stmtItems,
        'ss',
        $expectedOrderNo,
        $shippingNo
    );

    mysqli_stmt_execute($stmtItems);
    $itemResult = mysqli_stmt_get_result($stmtItems);

    $shippingSubtotal = 0.0;
    $itemCount = 0;

    while ($item = mysqli_fetch_assoc($itemResult)) {
        $inventoryName = strtoupper(
            trim((string)($item['inventory_name'] ?? ''))
        );

        $price = (float)($item['invoice_price'] ?? 0);
        $qty = (float)($item['qty_shipping'] ?? 0);
        $qtyPack = (float)($item['qty_pack_shipping'] ?? 0);

        /*
         * Semua inventory, termasuk "ONGKOS", mengikuti nilai transaksi:
         * subtotal = price × effectiveQty.
         *
         * Kasus contoh:
         * price Rp23.000 × qty 124,5 = Rp2.863.500.
         */
        $effectiveQty = $qtyPack > 0 ? $qtyPack : $qty;

        if ($effectiveQty <= 0) {
            mysqli_stmt_close($stmtItems);
            throw new RuntimeException(
                "Qty Shipping/Qty Pack Shipping untuk inventory " .
                (string)($item['inventory_id'] ?? '') .
                " pada Shipping $shippingNo bernilai 0."
            );
        }

        $itemSubtotal = $price * $effectiveQty;
        $shippingSubtotal += $itemSubtotal;
        $itemCount++;
    }

    mysqli_stmt_close($stmtItems);

    if ($itemCount === 0) {
        throw new RuntimeException(
            "Detail item Shipping $shippingNo tidak ditemukan."
        );
    }

    // Simpan subtotal per Shipping dalam Rupiah penuh agar det_invoice
    // dan head_invoice selalu konsisten tanpa pecahan desimal.
    $shippingSubtotal = roundInvoiceMoney($shippingSubtotal);

    return [
        'shipping_no' => (string)$shipping['shipping_no'],
        'shipping_date' => (string)$shipping['shipping_date'],
        'order_no' => (string)$shipping['order_no'],
        'customer_id' => trim((string)($shipping['customer_id'] ?? '')),
        'customer_name' => trim((string)($shipping['customer_name'] ?? '')),
        'customer_address' => trim((string)($shipping['customer_address'] ?? '')),
        'customer_city' => trim((string)($shipping['customer_city'] ?? '')),
        'subtotal' => $shippingSubtotal,
        'total' => $shippingSubtotal,
        'remarks_shipping' =>
            (string)($shipping['remarks_shipping'] ?? ''),
    ];
}


/**
 * Verifikasi keras sebelum COMMIT.
 *
 * Tujuan:
 * - head_invoice.subtotal/grand_total harus sama dengan hasil hitung server.
 * - SUM(det_invoice.subtotal/total) harus sama dengan header.
 * - setiap Shipping yang dipilih harus tersimpan dengan subtotal/total yang benar.
 *
 * Jika satu saja tidak cocok, transaksi di-ROLLBACK sehingga sistem tidak pernah
 * menampilkan pesan sukses untuk invoice yang sebenarnya belum sinkron.
 */
function verifyInvoicePersistence(
    mysqli $conn,
    string $invoiceNo,
    float $expectedSubtotal,
    array $expectedShippingRows
): void {
    $expectedSubtotal = roundInvoiceMoney($expectedSubtotal);

    $stmtHead = mysqli_prepare(
        $conn,
        "SELECT subtotal, grand_total
         FROM head_invoice
         WHERE invoice_no = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmtHead, 's', $invoiceNo);
    mysqli_stmt_execute($stmtHead);
    $headResult = mysqli_stmt_get_result($stmtHead);
    $head = mysqli_fetch_assoc($headResult) ?: null;
    mysqli_stmt_close($stmtHead);

    if (!$head) {
        throw new RuntimeException(
            "Verifikasi gagal: header Invoice $invoiceNo tidak ditemukan setelah UPDATE."
        );
    }

    $dbHeadSubtotal = roundInvoiceMoney((float)($head['subtotal'] ?? 0));
    $dbHeadGrandTotal = roundInvoiceMoney((float)($head['grand_total'] ?? 0));

    if (
        abs($dbHeadSubtotal - $expectedSubtotal) > 0.01 ||
        abs($dbHeadGrandTotal - $expectedSubtotal) > 0.01
    ) {
        throw new RuntimeException(
            "Verifikasi gagal: nilai header Invoice $invoiceNo tidak tersimpan sesuai hasil hitung. " .
            "Expected Rp " . number_format($expectedSubtotal, 0, ',', '.') .
            ", DB subtotal Rp " . number_format($dbHeadSubtotal, 0, ',', '.') .
            ", DB grand total Rp " . number_format($dbHeadGrandTotal, 0, ',', '.') . "."
        );
    }

    $stmtDetailTotal = mysqli_prepare(
        $conn,
        "SELECT
            COUNT(*) AS row_count,
            COALESCE(SUM(subtotal), 0) AS subtotal_sum,
            COALESCE(SUM(total), 0) AS total_sum
         FROM det_invoice
         WHERE invoice_no = ?"
    );
    mysqli_stmt_bind_param($stmtDetailTotal, 's', $invoiceNo);
    mysqli_stmt_execute($stmtDetailTotal);
    $detailResult = mysqli_stmt_get_result($stmtDetailTotal);
    $detailSummary = mysqli_fetch_assoc($detailResult) ?: [];
    mysqli_stmt_close($stmtDetailTotal);

    $rowCount = (int)($detailSummary['row_count'] ?? 0);
    $dbDetailSubtotal = roundInvoiceMoney((float)($detailSummary['subtotal_sum'] ?? 0));
    $dbDetailTotal = roundInvoiceMoney((float)($detailSummary['total_sum'] ?? 0));

    if ($rowCount !== count($expectedShippingRows)) {
        throw new RuntimeException(
            "Verifikasi gagal: jumlah detail Invoice $invoiceNo tidak sesuai. " .
            "Expected " . count($expectedShippingRows) . " Shipping, DB $rowCount Shipping."
        );
    }

    if (
        abs($dbDetailSubtotal - $expectedSubtotal) > 0.01 ||
        abs($dbDetailTotal - $expectedSubtotal) > 0.01
    ) {
        throw new RuntimeException(
            "Verifikasi gagal: total det_invoice tidak sama dengan header Invoice $invoiceNo. " .
            "Expected Rp " . number_format($expectedSubtotal, 0, ',', '.') .
            ", SUM subtotal detail Rp " . number_format($dbDetailSubtotal, 0, ',', '.') .
            ", SUM total detail Rp " . number_format($dbDetailTotal, 0, ',', '.') . "."
        );
    }

    $stmtShipping = mysqli_prepare(
        $conn,
        "SELECT subtotal, total
         FROM det_invoice
         WHERE invoice_no = ?
           AND shipping_no = ?
         LIMIT 1"
    );

    foreach ($expectedShippingRows as $row) {
        $shippingNo = (string)($row['shipping_no'] ?? '');
        $expectedRowSubtotal = roundInvoiceMoney((float)($row['subtotal'] ?? 0));

        mysqli_stmt_bind_param(
            $stmtShipping,
            'ss',
            $invoiceNo,
            $shippingNo
        );
        mysqli_stmt_execute($stmtShipping);

        $shippingResult = mysqli_stmt_get_result($stmtShipping);
        $dbRow = mysqli_fetch_assoc($shippingResult) ?: null;

        if (!$dbRow) {
            mysqli_stmt_close($stmtShipping);
            throw new RuntimeException(
                "Verifikasi gagal: Shipping $shippingNo tidak tersimpan pada Invoice $invoiceNo."
            );
        }

        $dbRowSubtotal = roundInvoiceMoney((float)($dbRow['subtotal'] ?? 0));
        $dbRowTotal = roundInvoiceMoney((float)($dbRow['total'] ?? 0));

        if (
            abs($dbRowSubtotal - $expectedRowSubtotal) > 0.01 ||
            abs($dbRowTotal - $expectedRowSubtotal) > 0.01
        ) {
            mysqli_stmt_close($stmtShipping);
            throw new RuntimeException(
                "Verifikasi gagal: Shipping $shippingNo tersimpan dengan nominal berbeda. " .
                "Expected Rp " . number_format($expectedRowSubtotal, 0, ',', '.') .
                ", DB subtotal Rp " . number_format($dbRowSubtotal, 0, ',', '.') .
                ", DB total Rp " . number_format($dbRowTotal, 0, ',', '.') . "."
            );
        }
    }

    mysqli_stmt_close($stmtShipping);
}


$invoiceNo = strtoupper(postValue('invoice_no'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithAlert(
        'danger',
        'Metode request tidak valid.',
        '../../index.php?page=invoice'
    );
}

if ($invoiceNo === '') {
    redirectWithAlert(
        'danger',
        'Invoice No tidak boleh kosong.',
        '../../index.php?page=invoice'
    );
}

$editUrl =
    '../../modul/transaksi/edit_invoice.php?invoice_no=' .
    urlencode($invoiceNo);

$invoiceDate = toDbDate(postValue('invoice_date'));
$station = postValue('station', 'FACTORY');
$paymentType = postValue('payment_type');
$paymentTerm = postValue('payment_term');
$days = (int)postValue('days', 30);
$currency = strtoupper(postValue('currency', 'IDR'));
$remarksInvoice = postValue('remarks_invoice');
$downPaymentInput = parseMoney(postValue('down_payment', 0));
$titipAppliedInput = parseMoney(postValue('titip_applied', 0));
$shippingNos = postArray('shipping_no');
$username = (string)$_SESSION['username'];

if (!$invoiceDate) {
    redirectWithAlert(
        'danger',
        'Invoice Date tidak valid atau kosong.',
        $editUrl
    );
}

if ($station === '') {
    $station = 'FACTORY';
}

if ($days < 0) {
    $days = 0;
}

if ($currency === '') {
    $currency = 'IDR';
}

$shippingNos = array_values(
    array_unique(
        array_filter(
            array_map(
                static fn($value) => strtoupper(trim((string)$value)),
                $shippingNos
            )
        )
    )
);

if (count($shippingNos) === 0) {
    redirectWithAlert(
        'danger',
        'Minimal pilih 1 Shipping / Surat Jalan.',
        $editUrl
    );
}

mysqli_begin_transaction($conn);

try {
    $oldInvoice = loadInvoiceForUpdate($conn, $invoiceNo);

    if (!$oldInvoice) {
        throw new RuntimeException(
            "Invoice $invoiceNo tidak ditemukan."
        );
    }

    $orderNo = trim((string)($oldInvoice['order_no'] ?? ''));

    if ($orderNo === '') {
        throw new RuntimeException(
            "Sales Order pada Invoice $invoiceNo tidak ditemukan."
        );
    }

    $validShippingRows = [];
    $calculatedSubtotal = 0.0;

    /*
     * Customer invoice selalu mengikuti Shipping/Sales Order yang dipilih.
     * Semua shipping dalam satu invoice wajib mempunyai Customer ID yang sama.
     */
    $sourceCustomer = null;

    foreach ($shippingNos as $shippingNo) {
        if (
            shippingUsedByOtherInvoice(
                $conn,
                $shippingNo,
                $invoiceNo
            )
        ) {
            throw new RuntimeException(
                "Shipping $shippingNo sudah digunakan oleh invoice lain."
            );
        }

        $shippingRow = calculateShippingInvoice(
            $conn,
            $shippingNo,
            $orderNo
        );

        if (!$shippingRow) {
            throw new RuntimeException(
                "Shipping $shippingNo tidak valid atau bukan milik Sales Order $orderNo."
            );
        }

        if ((float)$shippingRow['subtotal'] < 0) {
            throw new RuntimeException(
                "Subtotal Shipping $shippingNo tidak valid."
            );
        }

        $shipCustomerId = trim((string)($shippingRow['customer_id'] ?? ''));
        $shipCustomerName = trim((string)($shippingRow['customer_name'] ?? ''));

        if ($shipCustomerId === '' || $shipCustomerName === '') {
            throw new RuntimeException(
                "Customer pada Shipping $shippingNo kosong. Perbaiki Shipping terlebih dahulu."
            );
        }

        if ($sourceCustomer === null) {
            $sourceCustomer = [
                'customer_id' => $shipCustomerId,
                'customer_name' => $shipCustomerName,
                'customer_address' => trim((string)($shippingRow['customer_address'] ?? '')),
                'customer_city' => trim((string)($shippingRow['customer_city'] ?? '')),
            ];
        } elseif ($sourceCustomer['customer_id'] !== $shipCustomerId) {
            throw new RuntimeException(
                "Shipping yang dipilih tidak konsisten. " .
                "Customer {$sourceCustomer['customer_id']} dan $shipCustomerId ditemukan dalam satu invoice."
            );
        }

        $validShippingRows[] = $shippingRow;
        $calculatedSubtotal += (float)$shippingRow['subtotal'];
    }

    if (count($validShippingRows) === 0) {
        throw new RuntimeException(
            'Tidak ada Shipping valid untuk disimpan.'
        );
    }

    if ($sourceCustomer === null) {
        throw new RuntimeException(
            'Customer sumber dari Shipping tidak ditemukan.'
        );
    }

    $sourceCustomerId = (string)$sourceCustomer['customer_id'];
    $sourceCustomerName = (string)$sourceCustomer['customer_name'];
    $sourceCustomerAddress = (string)$sourceCustomer['customer_address'];
    $sourceCustomerCity = (string)$sourceCustomer['customer_city'];

    assertActiveCustomer($conn, $sourceCustomerId);

    $oldCustomerId = trim((string)($oldInvoice['customer_id'] ?? ''));

    /*
     * Jika ID customer benar-benar berpindah, lakukan safety check.
     * Jika hanya nama/alamat/city yang berbeda tetapi ID sama, snapshot header
     * boleh disinkronkan tanpa mengganggu ledger.
     */
    assertCustomerChangeSafe(
        $conn,
        $invoiceNo,
        $oldCustomerId,
        $sourceCustomerId
    );

    // Header invoice juga wajib Rupiah penuh. Karena subtotal setiap Shipping
    // sudah dibulatkan, jumlah det_invoice akan sama persis dengan head_invoice.
    $subtotal = roundInvoiceMoney($calculatedSubtotal);
    $grandTotal = $subtotal;

    $downPayment = roundInvoiceMoney(max($downPaymentInput, 0));
    $titipApplied = roundInvoiceMoney(max($titipAppliedInput, 0));

    /*
     * Total DP + titip tidak boleh melebihi Grand Total.
     * Prioritas pertama mempertahankan Down Payment,
     * sisanya baru untuk Titip Applied.
     */
    $downPayment = min($downPayment, $grandTotal);

    $remainingAfterDp = max(
        $grandTotal - $downPayment,
        0
    );

    $titipApplied = min(
        $titipApplied,
        $remainingAfterDp
    );

    $paymentBalance = roundInvoiceMoney(max(
        $grandTotal - $downPayment - $titipApplied,
        0
    ));

    $piutang = $paymentBalance;

    $stmtHead = mysqli_prepare(
        $conn,
        "UPDATE head_invoice
         SET
            invoice_date = ?,
            station = ?,
            payment_type = ?,
            payment_term = ?,
            days = ?,
            currency = ?,
            remarks_invoice = ?,
            customer_id = ?,
            customer_name = ?,
            customer_address = ?,
            customer_city = ?,
            subtotal = ?,
            grand_total = ?,
            down_payment = ?,
            titip_applied = ?,
            payment_balance = ?,
            piutang = ?,
            user_modified = ?,
            date_modified = NOW()
         WHERE invoice_no = ?"
    );

    /*
     * Customer header tidak berasal dari POST.
     * Selalu menggunakan snapshot customer dari Shipping yang dipilih.
     */
    mysqli_stmt_bind_param(
        $stmtHead,
        'ssssissssssddddddss',
        $invoiceDate,
        $station,
        $paymentType,
        $paymentTerm,
        $days,
        $currency,
        $remarksInvoice,
        $sourceCustomerId,
        $sourceCustomerName,
        $sourceCustomerAddress,
        $sourceCustomerCity,
        $subtotal,
        $grandTotal,
        $downPayment,
        $titipApplied,
        $paymentBalance,
        $piutang,
        $username,
        $invoiceNo
    );

    mysqli_stmt_execute($stmtHead);

    if (mysqli_stmt_affected_rows($stmtHead) < 0) {
        mysqli_stmt_close($stmtHead);
        throw new RuntimeException(
            "UPDATE head_invoice untuk $invoiceNo gagal dijalankan."
        );
    }

    mysqli_stmt_close($stmtHead);

    $stmtDelete = mysqli_prepare(
        $conn,
        "DELETE FROM det_invoice
         WHERE invoice_no = ?"
    );

    mysqli_stmt_bind_param(
        $stmtDelete,
        's',
        $invoiceNo
    );

    mysqli_stmt_execute($stmtDelete);
    mysqli_stmt_close($stmtDelete);

    $stmtDetail = mysqli_prepare(
        $conn,
        "INSERT INTO det_invoice (
            invoice_no,
            shipping_no,
            shipping_date,
            order_no,
            subtotal,
            total,
            remarks_shipping,
            create_user,
            date_created
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    foreach ($validShippingRows as $shippingRow) {
        $shippingNo = $shippingRow['shipping_no'];
        $shippingDate = $shippingRow['shipping_date'];
        $shippingOrderNo = $shippingRow['order_no'];
        $rowSubtotal = roundInvoiceMoney($shippingRow['subtotal']);
        $rowTotal = roundInvoiceMoney($shippingRow['total']);
        $remarksShipping = $shippingRow['remarks_shipping'];

        mysqli_stmt_bind_param(
            $stmtDetail,
            'ssssddss',
            $invoiceNo,
            $shippingNo,
            $shippingDate,
            $shippingOrderNo,
            $rowSubtotal,
            $rowTotal,
            $remarksShipping,
            $username
        );

        mysqli_stmt_execute($stmtDetail);
    }

    mysqli_stmt_close($stmtDetail);

    /*
     * PENTING:
     * Jangan COMMIT sebelum data header + detail benar-benar terbukti sinkron.
     * Ini mencegah kondisi invoice terlihat berhasil diedit di layar tetapi
     * head_invoice/det_invoice masih menyimpan nominal lama.
     */
    verifyInvoicePersistence(
        $conn,
        $invoiceNo,
        $subtotal,
        $validShippingRows
    );

    mysqli_commit($conn);

    redirectWithAlert(
        'success',
        "Invoice $invoiceNo berhasil diperbarui dan diverifikasi. Grand Total tersimpan: Rp " .
        number_format($grandTotal, 0, ',', '.') .
        ". Customer mengikuti Shipping: $sourceCustomerId - $sourceCustomerName.",
        '../../index.php?page=invoice'
    );
} catch (Throwable $e) {
    try {
        mysqli_rollback($conn);
    } catch (Throwable $rollbackError) {
        // Abaikan jika transaksi belum aktif.
    }

    error_log(
        'UPDATE INVOICE ERROR [' .
        $invoiceNo .
        ']: ' .
        $e->getMessage()
    );

    redirectWithAlert(
        'danger',
        'Error: ' . $e->getMessage(),
        $editUrl
    );
}
?>
