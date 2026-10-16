<?php
// modul/transaksi/save_invoice.php
// VERSI SINKRONISASI SOURCE OF TRUTH INVOICE:
// 1. Data customer/order diambil ulang dari head_sales_order, bukan dipercaya dari browser.
// 2. Setiap shipping wajib milik Sales Order yang dipilih.
// 3. Shipping yang sudah masuk invoice ditolak.
// 4. Total invoice dihitung ulang di server.
// 5. Harga Shipping diambil dari detail_sales_order.
// 6. Semua inventory termasuk yang mengandung "ONGKOS": subtotal = effectiveQty × price.
// 7. DP Sales Order dihitung berdasarkan sisa DP yang belum pernah dipakai.
// 8. Nilai titip dipisahkan dari DP.
// 9. Seluruh proses memakai transaction dan row locking.
// 10. Pembulatan invoice = Rupiah penuh (PHP_ROUND_HALF_UP), sama dengan update_invoice.php.
// 11. Qty normal: qty_pack_shipping; jika 0, fallback qty_shipping.
// 12. Sebelum COMMIT, head_invoice dan det_invoice diverifikasi wajib sinkron.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function cleanInput($value) {
    if (is_array($value)) {
        return array_map('cleanInput', $value);
    }

    return trim((string)$value);
}

function roundInvoiceMoney($value): float {
    return (float) round((float)$value, 0, PHP_ROUND_HALF_UP);
}

function moneyToFloat($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return 0.0;
    }

    // Mendukung:
    // 1.000.000,50
    // 1000000.50
    // 1000000
    $value = preg_replace('/[^0-9,.\-]/', '', $value);

    $hasDot = strpos($value, '.') !== false;
    $hasComma = strpos($value, ',') !== false;

    if ($hasDot && $hasComma) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif ($hasComma && !$hasDot) {
        $value = str_replace(',', '.', $value);
    } elseif ($hasDot && !$hasComma) {
        $dotCount = substr_count($value, '.');

        if ($dotCount > 1) {
            $value = str_replace('.', '', $value);
        }
    }

    return (float)$value;
}

function redirectWithError($message) {
    $_SESSION['alert'] =
        '<div class="alert alert-danger">Error: ' .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '</div>';

    echo "<script>
        alert('Error: " . addslashes($message) . "');
        window.location.href='index.php?page=add_invoice';
    </script>";
    exit;
}

function redirectWithSuccess($invoiceNo, $grandTotal = null) {
    $extra = '';
    if ($grandTotal !== null) {
        $extra = ' Grand Total tersimpan: Rp ' .
            number_format((float)$grandTotal, 0, ',', '.') . '.';
    }

    $_SESSION['alert'] =
        '<div class="alert alert-success">Invoice ' .
        htmlspecialchars($invoiceNo, ENT_QUOTES, 'UTF-8') .
        ' berhasil disimpan dan diverifikasi.' .
        htmlspecialchars($extra, ENT_QUOTES, 'UTF-8') .
        '</div>';

    echo "<script>
        alert('Invoice " . addslashes($invoiceNo) .
        " berhasil disimpan dan diverifikasi." .
        addslashes($extra) . "');
        window.location.href='index.php?page=invoice';
    </script>";
    exit;
}

/**
 * Ambil dan lock Sales Order.
 * Data ini menjadi sumber utama untuk header invoice.
 */
function getSalesOrderForUpdate(mysqli $conn, string $orderNo): ?array {
    $stmt = mysqli_prepare($conn, "
        SELECT
            so.order_no,
            so.order_date,
            so.customer_id,
            so.customer_name,
            so.customer_address,
            so.customer_city,
            so.station,
            so.payment_type,
            so.payment_term,
            so.days,
            so.currency,
            so.down_payment,
            so.remarks
        FROM head_sales_order so
        WHERE so.order_no = ?
        LIMIT 1
        FOR UPDATE
    ");

    mysqli_stmt_bind_param($stmt, 's', $orderNo);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

/**
 * Ambil total DP yang sudah pernah dipakai invoice sebelumnya.
 *
 * head_invoice.down_payment dianggap sebagai DP murni,
 * bukan DP + titip.
 */
function getUsedSalesOrderDp(mysqli $conn, string $orderNo): float {
    $stmt = mysqli_prepare($conn, "
        SELECT COALESCE(SUM(hi.down_payment), 0) AS used_dp
        FROM head_invoice hi
        WHERE hi.order_no = ?
    ");

    mysqli_stmt_bind_param($stmt, 's', $orderNo);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return (float)($row['used_dp'] ?? 0);
}

/**
 * Pastikan Shipping:
 * - ada,
 * - milik Sales Order,
 * - customer sama.
 */
function getShippingHeaderForUpdate(
    mysqli $conn,
    string $shippingNo,
    string $orderNo,
    string $customerId
): ?array {
    $stmt = mysqli_prepare($conn, "
        SELECT
            hs.shipping_no,
            hs.shipping_date,
            hs.order_no,
            hs.customer_id,
            hs.remarks_shipping
        FROM hed_shipping hs
        WHERE hs.shipping_no = ?
          AND hs.order_no = ?
          AND hs.customer_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    mysqli_stmt_bind_param(
        $stmt,
        'sss',
        $shippingNo,
        $orderNo,
        $customerId
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;

    mysqli_stmt_close($stmt);

    return $row ?: null;
}

/**
 * Cek apakah shipping sudah pernah digunakan.
 */
function shippingAlreadyInvoiced(mysqli $conn, string $shippingNo): bool {
    $stmt = mysqli_prepare($conn, "
        SELECT invoice_no
        FROM det_invoice
        WHERE shipping_no = ?
        LIMIT 1
        FOR UPDATE
    ");

    mysqli_stmt_bind_param($stmt, 's', $shippingNo);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;

    mysqli_stmt_close($stmt);

    return $exists;
}

/**
 * Cek item ONGKOS/JASA.
 */
function isOngkosItem(string $inventoryName): bool {
    if ($inventoryName === '') {
        return false;
    }

    return stripos($inventoryName, 'ONGKOS') !== false;
}

/**
 * Harga efektif Sales Order untuk satu inventory.
 *
 * Prioritas:
 * 1. detail_sales_order.price > 0
 * 2. subtotal / quantity_pack
 * 3. subtotal / quantity
 *
 * Jika ditemukan lebih dari satu harga efektif berbeda untuk inventory
 * yang sama pada SO yang sama, proses dihentikan agar nilai invoice tidak ambigu.
 */
function getEffectiveSalesOrderPrice(
    mysqli $conn,
    string $orderNo,
    string $inventoryId
): float {
    $stmt = mysqli_prepare($conn, "
        SELECT DISTINCT
            ROUND(
                CASE
                    WHEN COALESCE(dso.price, 0) > 0
                        THEN COALESCE(dso.price, 0)

                    WHEN COALESCE(dso.quantity_pack, 0) > 0
                         AND COALESCE(dso.subtotal, 0) > 0
                        THEN COALESCE(dso.subtotal, 0)
                             / NULLIF(dso.quantity_pack, 0)

                    WHEN COALESCE(dso.quantity, 0) > 0
                         AND COALESCE(dso.subtotal, 0) > 0
                        THEN COALESCE(dso.subtotal, 0)
                             / NULLIF(dso.quantity, 0)

                    ELSE 0
                END,
                6
            ) AS effective_price
        FROM detail_sales_order dso
        WHERE dso.order_no = ?
          AND dso.inventory_id = ?
    ");

    mysqli_stmt_bind_param(
        $stmt,
        'ss',
        $orderNo,
        $inventoryId
    );

    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $prices = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $price = (float)($row['effective_price'] ?? 0);

        if ($price > 0) {
            $prices[] = $price;
        }
    }

    mysqli_stmt_close($stmt);

    $prices = array_values(array_unique(array_map(
        static fn($price) => number_format((float)$price, 6, '.', ''),
        $prices
    )));

    if (count($prices) === 0) {
        throw new Exception(
            "Harga Sales Order untuk inventory {$inventoryId} tidak ditemukan atau bernilai 0."
        );
    }

    if (count($prices) > 1) {
        throw new Exception(
            "Inventory {$inventoryId} memiliki lebih dari satu harga pada Sales Order {$orderNo}. " .
            "Sistem menghentikan proses agar subtotal invoice tidak salah. " .
            "Pisahkan detail inventory atau gunakan referensi detail Sales Order yang unik."
        );
    }

    return (float)$prices[0];
}

/**
 * Hitung subtotal satu Shipping.
 *
 * Rumus disamakan dengan update_invoice.php:
 * - Semua inventory termasuk ONGKOS: subtotal item = effectiveQty × price
 * - effectiveQty = qty_pack_shipping jika > 0, selain itu qty_shipping
 * - subtotal satu Shipping dibulatkan normal ke Rupiah penuh
 */
function calculateShippingSubtotal(
    mysqli $conn,
    string $shippingNo,
    string $orderNo
): float {
    $stmt = mysqli_prepare($conn, "
        SELECT
            ds.inventory_id,
            COALESCE(ds.qty_shipping, 0) AS qty_shipping,
            COALESCE(ds.qty_pack_shipping, 0) AS qty_pack_shipping,
            COALESCE(mi.inventory_name, ds.inventory_name, '') AS inventory_name
        FROM det_shipping ds
        LEFT JOIN m_inventory mi
            ON mi.inventory_id = ds.inventory_id
        WHERE ds.shipping_no = ?
        ORDER BY ds.id ASC
    ");

    mysqli_stmt_bind_param($stmt, 's', $shippingNo);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);

    $subtotal = 0.0;
    $detailCount = 0;

    while ($row = mysqli_fetch_assoc($result)) {
        $detailCount++;

        $inventoryId = cleanInput($row['inventory_id'] ?? '');
        $qtyShipping = (float)($row['qty_shipping'] ?? 0);
        $qtyPack = (float)($row['qty_pack_shipping'] ?? 0);
        $effectiveQty = $qtyPack > 0 ? $qtyPack : $qtyShipping;
        $inventoryName = (string)($row['inventory_name'] ?? '');

        if ($inventoryId === '') {
            throw new Exception(
                "Shipping {$shippingNo} memiliki detail tanpa Inventory ID."
            );
        }

        $price = getEffectiveSalesOrderPrice(
            $conn,
            $orderNo,
            $inventoryId
        );

        /*
         * Semua item, termasuk inventory yang namanya mengandung "ONGKOS",
         * menggunakan nilai transaksi sebenarnya:
         *
         * subtotal = effectiveQty × price
         *
         * effectiveQty = qty_pack_shipping bila > 0,
         * selain itu fallback ke qty_shipping.
         *
         * Jangan khususkan ONGKOS menjadi subtotal = price, karena itu
         * menyebabkan contoh price Rp23.000 × qty 124,5 tersimpan hanya
         * Rp23.000, padahal subtotal seharusnya Rp2.863.500.
         */
        if ($effectiveQty <= 0) {
            throw new Exception(
                "Qty Shipping/Qty Pack Shipping untuk inventory {$inventoryId} " .
                "pada Shipping {$shippingNo} bernilai 0."
            );
        }

        $subtotal += ($effectiveQty * $price);
    }

    mysqli_stmt_close($stmt);

    if ($detailCount === 0) {
        throw new Exception(
            "Shipping {$shippingNo} tidak mempunyai detail barang."
        );
    }

    return roundInvoiceMoney($subtotal);
}

/**
 * Verifikasi keras sebelum COMMIT.
 *
 * Invoice hanya dianggap berhasil jika:
 * - head_invoice.subtotal == hasil hitung server
 * - head_invoice.grand_total == hasil hitung server
 * - jumlah det_invoice == jumlah shipping terpilih
 * - SUM(det_invoice.subtotal) == header
 * - SUM(det_invoice.total) == header
 * - setiap Shipping memiliki subtotal/total yang sesuai
 */
function verifyInvoicePersistence(
    mysqli $conn,
    string $invoiceNo,
    float $expectedSubtotal,
    array $expectedShippingRows
): void {
    $expectedSubtotal = roundInvoiceMoney($expectedSubtotal);

    $stmtHead = mysqli_prepare($conn, "
        SELECT subtotal, grand_total
        FROM head_invoice
        WHERE invoice_no = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmtHead, 's', $invoiceNo);
    mysqli_stmt_execute($stmtHead);
    $head = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtHead)) ?: null;
    mysqli_stmt_close($stmtHead);

    if (!$head) {
        throw new RuntimeException(
            "Verifikasi gagal: header Invoice {$invoiceNo} tidak ditemukan setelah INSERT."
        );
    }

    $dbHeadSubtotal = roundInvoiceMoney((float)($head['subtotal'] ?? 0));
    $dbHeadGrandTotal = roundInvoiceMoney((float)($head['grand_total'] ?? 0));

    if (
        abs($dbHeadSubtotal - $expectedSubtotal) > 0.01 ||
        abs($dbHeadGrandTotal - $expectedSubtotal) > 0.01
    ) {
        throw new RuntimeException(
            "Verifikasi gagal: nilai header Invoice {$invoiceNo} tidak sesuai hasil hitung server. " .
            "Expected Rp " . number_format($expectedSubtotal, 0, ',', '.') .
            ", DB subtotal Rp " . number_format($dbHeadSubtotal, 0, ',', '.') .
            ", DB grand total Rp " . number_format($dbHeadGrandTotal, 0, ',', '.') . "."
        );
    }

    $stmtSummary = mysqli_prepare($conn, "
        SELECT
            COUNT(*) AS row_count,
            COALESCE(SUM(subtotal), 0) AS subtotal_sum,
            COALESCE(SUM(total), 0) AS total_sum
        FROM det_invoice
        WHERE invoice_no = ?
    ");
    mysqli_stmt_bind_param($stmtSummary, 's', $invoiceNo);
    mysqli_stmt_execute($stmtSummary);
    $summary = mysqli_fetch_assoc(mysqli_stmt_get_result($stmtSummary)) ?: [];
    mysqli_stmt_close($stmtSummary);

    $rowCount = (int)($summary['row_count'] ?? 0);
    $dbDetailSubtotal = roundInvoiceMoney((float)($summary['subtotal_sum'] ?? 0));
    $dbDetailTotal = roundInvoiceMoney((float)($summary['total_sum'] ?? 0));

    if ($rowCount !== count($expectedShippingRows)) {
        throw new RuntimeException(
            "Verifikasi gagal: jumlah detail Invoice {$invoiceNo} tidak sesuai. " .
            "Expected " . count($expectedShippingRows) .
            " Shipping, DB {$rowCount} Shipping."
        );
    }

    if (
        abs($dbDetailSubtotal - $expectedSubtotal) > 0.01 ||
        abs($dbDetailTotal - $expectedSubtotal) > 0.01
    ) {
        throw new RuntimeException(
            "Verifikasi gagal: total det_invoice tidak sama dengan header Invoice {$invoiceNo}. " .
            "Expected Rp " . number_format($expectedSubtotal, 0, ',', '.') .
            ", SUM subtotal detail Rp " . number_format($dbDetailSubtotal, 0, ',', '.') .
            ", SUM total detail Rp " . number_format($dbDetailTotal, 0, ',', '.') . "."
        );
    }

    $stmtShipping = mysqli_prepare($conn, "
        SELECT subtotal, total
        FROM det_invoice
        WHERE invoice_no = ?
          AND shipping_no = ?
        LIMIT 1
    ");

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

        $dbRow = mysqli_fetch_assoc(
            mysqli_stmt_get_result($stmtShipping)
        ) ?: null;

        if (!$dbRow) {
            mysqli_stmt_close($stmtShipping);
            throw new RuntimeException(
                "Verifikasi gagal: Shipping {$shippingNo} tidak tersimpan pada Invoice {$invoiceNo}."
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
                "Verifikasi gagal: Shipping {$shippingNo} tersimpan dengan nominal berbeda. " .
                "Expected Rp " . number_format($expectedRowSubtotal, 0, ',', '.') .
                ", DB subtotal Rp " . number_format($dbRowSubtotal, 0, ',', '.') .
                ", DB total Rp " . number_format($dbRowTotal, 0, ',', '.') . "."
            );
        }
    }

    mysqli_stmt_close($stmtShipping);
}

// ============================================================
// INPUT
// ============================================================

$invoiceNo = cleanInput($_POST['invoice_no'] ?? '');
$invoiceDate = cleanInput($_POST['invoice_date'] ?? '');
$orderNo = cleanInput($_POST['order_no'] ?? '');
$remarksInvoice = cleanInput($_POST['remarks_invoice'] ?? '');
$username = cleanInput($_SESSION['username'] ?? '');

$shippingNos = $_POST['shipping_no'] ?? [];

// Pada add_invoice existing:
// down_payment = DP + titip
// titip_applied = titip
//
// Agar tersimpan terpisah:
// requestedDp = posted down_payment - posted titip_applied
$postedDpAndTitip = moneyToFloat($_POST['down_payment'] ?? 0);
$requestedTitip = max(
    moneyToFloat($_POST['titip_applied'] ?? 0),
    0
);
$requestedDp = max(
    $postedDpAndTitip - $requestedTitip,
    0
);

if (
    $invoiceNo === '' ||
    $invoiceDate === '' ||
    $orderNo === ''
) {
    redirectWithError(
        'Invoice No, Invoice Date, dan Sales Order wajib diisi.'
    );
}

if (!is_array($shippingNos) || count($shippingNos) === 0) {
    redirectWithError(
        'Minimal pilih 1 Shipping / Surat Jalan.'
    );
}

// Hilangkan shipping kosong dan duplikat dari request.
$shippingNos = array_values(array_unique(array_filter(
    array_map('cleanInput', $shippingNos),
    static fn($value) => $value !== ''
)));

if (count($shippingNos) === 0) {
    redirectWithError(
        'Minimal pilih 1 Shipping / Surat Jalan yang valid.'
    );
}

mysqli_begin_transaction($conn);

try {
    // ========================================================
    // CEK INVOICE NO
    // ========================================================
    $stmtCheckInvoice = mysqli_prepare($conn, "
        SELECT invoice_no
        FROM head_invoice
        WHERE invoice_no = ?
        LIMIT 1
        FOR UPDATE
    ");

    mysqli_stmt_bind_param(
        $stmtCheckInvoice,
        's',
        $invoiceNo
    );

    mysqli_stmt_execute($stmtCheckInvoice);

    $resultCheckInvoice = mysqli_stmt_get_result($stmtCheckInvoice);

    if (
        $resultCheckInvoice &&
        mysqli_num_rows($resultCheckInvoice) > 0
    ) {
        throw new Exception(
            'Invoice No sudah terdaftar. Refresh halaman dan coba kembali.'
        );
    }

    mysqli_stmt_close($stmtCheckInvoice);

    // ========================================================
    // AMBIL SALES ORDER
    // ========================================================
    $salesOrder = getSalesOrderForUpdate(
        $conn,
        $orderNo
    );

    if (!$salesOrder) {
        throw new Exception(
            "Sales Order {$orderNo} tidak ditemukan."
        );
    }

    $customerId = cleanInput($salesOrder['customer_id'] ?? '');
    $customerName = cleanInput($salesOrder['customer_name'] ?? '');
    $customerAddress = cleanInput($salesOrder['customer_address'] ?? '');
    $customerCity = cleanInput($salesOrder['customer_city'] ?? '');
    $orderDate = cleanInput($salesOrder['order_date'] ?? '');
    $station = cleanInput($salesOrder['station'] ?? 'FACTORY');
    $paymentType = cleanInput($salesOrder['payment_type'] ?? '');
    $paymentTerm = cleanInput($salesOrder['payment_term'] ?? '');
    $days = (int)($salesOrder['days'] ?? 30);
    $currency = cleanInput($salesOrder['currency'] ?? 'IDR');

    if ($customerId === '') {
        throw new Exception(
            "Customer pada Sales Order {$orderNo} tidak ditemukan."
        );
    }

    if ($station === '') {
        $station = 'FACTORY';
    }

    if ($currency === '') {
        $currency = 'IDR';
    }

    if ($days < 0) {
        $days = 30;
    }

    // ========================================================
    // VALIDASI SHIPPING + HITUNG TOTAL SERVER
    // ========================================================
    $validShippingRows = [];
    $subtotal = 0.0;

    foreach ($shippingNos as $shippingNo) {
        $shipping = getShippingHeaderForUpdate(
            $conn,
            $shippingNo,
            $orderNo,
            $customerId
        );

        if (!$shipping) {
            throw new Exception(
                "Shipping {$shippingNo} tidak ditemukan, bukan milik Sales Order {$orderNo}, " .
                "atau customer-nya berbeda."
            );
        }

        if (shippingAlreadyInvoiced($conn, $shippingNo)) {
            throw new Exception(
                "Shipping {$shippingNo} sudah pernah dibuatkan invoice."
            );
        }

        $shippingSubtotal = calculateShippingSubtotal(
            $conn,
            $shippingNo,
            $orderNo
        );

        if ($shippingSubtotal <= 0) {
            throw new Exception(
                "Subtotal Shipping {$shippingNo} bernilai 0."
            );
        }

        $validShippingRows[] = [
            'shipping_no' => $shippingNo,
            'shipping_date' => cleanInput($shipping['shipping_date'] ?? ''),
            'order_no' => cleanInput($shipping['order_no'] ?? ''),
            'subtotal' => $shippingSubtotal,
            'total' => $shippingSubtotal,
            'remarks_shipping' => cleanInput(
                $shipping['remarks_shipping'] ?? ''
            )
        ];

        $subtotal += $shippingSubtotal;
    }

    if (count($validShippingRows) === 0) {
        throw new Exception(
            'Tidak ada Shipping valid untuk disimpan.'
        );
    }

    // Satu source of truth: total header = jumlah subtotal Shipping yang
    // masing-masing sudah dibulatkan ke Rupiah penuh.
    $subtotal = roundInvoiceMoney($subtotal);
    $grandTotal = $subtotal;

    // ========================================================
    // HITUNG SISA DP SALES ORDER
    // ========================================================
    $salesOrderDp = max(
        (float)($salesOrder['down_payment'] ?? 0),
        0
    );

    $usedDp = max(
        getUsedSalesOrderDp($conn, $orderNo),
        0
    );

    $remainingDp = max(
        $salesOrderDp - $usedDp,
        0
    );

    $downPayment = min(
        $requestedDp,
        $remainingDp,
        $grandTotal
    );

    // Titip dipisahkan dari DP dan tidak boleh melebihi sisa invoice.
    // Logika existing dipertahankan.
    $titipApplied = min(
        $requestedTitip,
        max($grandTotal - $downPayment, 0)
    );

    $paymentBalance = max(
        $grandTotal - $downPayment - $titipApplied,
        0
    );

    $piutang = $paymentBalance;

    // ========================================================
    // INSERT HEADER
    // ========================================================
    $stmtHead = mysqli_prepare($conn, "
        INSERT INTO head_invoice (
            invoice_no,
            invoice_date,
            customer_id,
            customer_name,
            customer_address,
            customer_city,
            order_no,
            order_date,
            station,
            payment_type,
            payment_term,
            days,
            currency,
            remarks_invoice,
            subtotal,
            grand_total,
            down_payment,
            titip_applied,
            payment_balance,
            piutang,
            status,
            approval_status,
            create_user,
            date_created
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            'Open',
            'Pending',
            ?,
            NOW()
        )
    ");

    mysqli_stmt_bind_param(
        $stmtHead,
        'sssssssssssisddddddds',
        $invoiceNo,
        $invoiceDate,
        $customerId,
        $customerName,
        $customerAddress,
        $customerCity,
        $orderNo,
        $orderDate,
        $station,
        $paymentType,
        $paymentTerm,
        $days,
        $currency,
        $remarksInvoice,
        $subtotal,
        $grandTotal,
        $downPayment,
        $titipApplied,
        $paymentBalance,
        $piutang,
        $username
    );

    mysqli_stmt_execute($stmtHead);

    if (mysqli_stmt_affected_rows($stmtHead) !== 1) {
        mysqli_stmt_close($stmtHead);
        throw new RuntimeException(
            "INSERT head_invoice untuk {$invoiceNo} tidak menghasilkan tepat 1 row."
        );
    }

    mysqli_stmt_close($stmtHead);

    // ========================================================
    // INSERT DETAIL
    // ========================================================
    $stmtDetail = mysqli_prepare($conn, "
        INSERT INTO det_invoice (
            invoice_no,
            shipping_no,
            shipping_date,
            order_no,
            subtotal,
            total,
            remarks_shipping,
            create_user,
            date_created
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, NOW()
        )
    ");

    foreach ($validShippingRows as $row) {
        $shippingNo = $row['shipping_no'];
        $shippingDate = $row['shipping_date'];
        $shippingOrderNo = $row['order_no'];
        $shippingSubtotal = roundInvoiceMoney((float)$row['subtotal']);
        $shippingTotal = $shippingSubtotal;
        $shippingRemarks = $row['remarks_shipping'];

        mysqli_stmt_bind_param(
            $stmtDetail,
            'ssssddss',
            $invoiceNo,
            $shippingNo,
            $shippingDate,
            $shippingOrderNo,
            $shippingSubtotal,
            $shippingTotal,
            $shippingRemarks,
            $username
        );

        mysqli_stmt_execute($stmtDetail);

        if (mysqli_stmt_affected_rows($stmtDetail) !== 1) {
            mysqli_stmt_close($stmtDetail);
            throw new RuntimeException(
                "INSERT det_invoice untuk Shipping {$shippingNo} gagal."
            );
        }
    }

    mysqli_stmt_close($stmtDetail);

    /*
     * PENTING:
     * Jangan COMMIT sebelum header + detail benar-benar terbukti sinkron.
     */
    verifyInvoicePersistence(
        $conn,
        $invoiceNo,
        $subtotal,
        $validShippingRows
    );

    mysqli_commit($conn);

    redirectWithSuccess(
        $invoiceNo,
        $grandTotal
    );

} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirectWithError($e->getMessage());
}
