<?php
/*
 * RULE NILAI RETUR CP-MCP:
 * - Retur CP-MCP memakai grand_total sebagai nominal kredit customer.
 * - Retur normal tetap memakai return_amount.
 */

// modul/transaksi/save_bayar.php
// RULE CP-MCP:
// - Invoice / Shipping yang mengandung string CP-MCP ditolak sebagai transaksi pembayaran.
// - Retur customer tetap boleh dipakai standalone berdasarkan return_id/customer,
//   tanpa harus terhubung ke invoice/shipping asal retur.
// Pembayaran multi invoice / multi shipping.
//
// Struktur:
// - 1 head_bayar untuk satu transaksi pembayaran customer.
// - N detail_bayar untuk setiap invoice/shipping yang dicentang.
// - Retur-only disimpan sebagai 1 detail_bayar dengan invoice_no/shipping_no kosong.
// - Semua pasangan invoice/shipping divalidasi ulang dari database.
// - Nilai setiap detail = nominal pembayaran yang dialokasikan ke shipping tersebut; pembayaran parsial diperbolehkan.
// - Titip uang dialokasikan terlebih dahulu, sisanya Cash/Transfer.
// - Retur dipilih standalone berdasarkan Customer, hanya boleh dipakai satu kali, dan MENGURANGI total tagihan yang harus dibayar.
// - Setelah semua detail tersimpan, status/payment_balance setiap invoice dihitung ulang.

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

function redirectWithAlert($type, $message, $page = 'pembayaran') {
    $_SESSION['alert'] = "
        <div style='padding:10px;margin-bottom:10px;border-radius:4px;background:" .
        ($type === 'success' ? '#d1e7dd' : '#f8d7da') .
        ";color:" .
        ($type === 'success' ? '#0f5132' : '#842029') .
        ";border:1px solid " .
        ($type === 'success' ? '#badbcc' : '#f5c2c7') .
        ";'>" .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        "</div>
    ";

    header(
        "Location: ../../index.php?page=" .
        urlencode($page)
    );
    exit;
}

function parseDateInput($value) {
    $value = trim((string)$value);

    foreach (
        ['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y']
        as $format
    ) {
        $date = DateTime::createFromFormat(
            $format,
            $value
        );

        $errors = DateTime::getLastErrors();

        if (
            $date instanceof DateTime &&
            (
                $errors === false ||
                (
                    $errors['warning_count'] === 0 &&
                    $errors['error_count'] === 0
                )
            )
        ) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function parseNumber($value) {
    $value = trim((string)$value);

    if ($value === '') {
        return 0.0;
    }

    $value = str_ireplace('Rp', '', $value);
    $value = str_replace([' ', '.'], '', $value);
    $value = str_replace(',', '.', $value);

    return is_numeric($value)
        ? (float)$value
        : 0.0;
}

function generateBayarNo($conn) {
    $prefix = 'B-';

    $sql = "
        SELECT bayar_no
        FROM head_bayar
        WHERE bayar_no LIKE 'B-%'
        ORDER BY
            CAST(
                SUBSTRING(bayar_no, 3)
                AS UNSIGNED
            ) DESC
        LIMIT 1
        FOR UPDATE
    ";

    $result = mysqli_query($conn, $sql);
    $lastNumber = 0;

    if (
        $result &&
        $row = mysqli_fetch_assoc($result)
    ) {
        $lastNumber =
            (int)substr(
                (string)$row['bayar_no'],
                2
            );
    }

    return $prefix .
        str_pad(
            $lastNumber + 1,
            9,
            '0',
            STR_PAD_LEFT
        );
}

function applyTitipUsage(
    $conn,
    $customerId,
    $customerName,
    $bayarNo,
    $bayarDate,
    $amount,
    $username
) {
    if ($amount <= 0.0001) {
        return;
    }

    $remaining = $amount;

    $sqlTitip = "
        SELECT
            titip_no,
            balance_amount
        FROM head_titip
        WHERE customer_id = ?
          AND balance_amount > 0
        ORDER BY
            titip_date ASC,
            titip_no ASC
        FOR UPDATE
    ";

    $stmtTitip =
        mysqli_prepare(
            $conn,
            $sqlTitip
        );

    mysqli_stmt_bind_param(
        $stmtTitip,
        's',
        $customerId
    );

    mysqli_stmt_execute($stmtTitip);

    $resultTitip =
        mysqli_stmt_get_result(
            $stmtTitip
        );

    while (
        $rowTitip =
        mysqli_fetch_assoc($resultTitip)
    ) {
        if ($remaining <= 0.0001) {
            break;
        }

        $titipNo =
            (string)$rowTitip['titip_no'];

        $balanceBefore =
            (float)$rowTitip[
                'balance_amount'
            ];

        $usedNow = min(
            $balanceBefore,
            $remaining
        );

        $balanceAfter =
            $balanceBefore -
            $usedNow;

        $status =
            $balanceAfter <= 0.0001
                ? 'Closed'
                : 'Open';

        $stmtUpdate =
            mysqli_prepare(
                $conn,
                "
                UPDATE head_titip
                SET
                    used_amount =
                        COALESCE(
                            used_amount,
                            0
                        ) + ?,
                    balance_amount =
                        balance_amount - ?,
                    status = ?,
                    user_modified = ?,
                    date_modified = NOW()
                WHERE titip_no = ?
                "
            );

        mysqli_stmt_bind_param(
            $stmtUpdate,
            'ddsss',
            $usedNow,
            $usedNow,
            $status,
            $username,
            $titipNo
        );

        mysqli_stmt_execute(
            $stmtUpdate
        );

        mysqli_stmt_close(
            $stmtUpdate
        );

        $remarksTitip =
            'Dipakai untuk pembayaran ' .
            $bayarNo;

        $stmtDetailTitip =
            mysqli_prepare(
                $conn,
                "
                INSERT INTO detail_titip
                (
                    titip_no,
                    titip_date,
                    customer_id,
                    customer_name,
                    transaction_type,
                    ref_no,
                    amount_in,
                    amount_out,
                    balance_after,
                    keterangan,
                    bank_name,
                    remarks,
                    create_user,
                    date_created
                )
                VALUES (
                    ?, ?, ?, ?,
                    'PAKAI',
                    ?,
                    0,
                    ?,
                    ?,
                    'PEMBAYARAN',
                    '',
                    ?,
                    ?,
                    NOW()
                )
                "
            );

        mysqli_stmt_bind_param(
            $stmtDetailTitip,
            'sssssddss',
            $titipNo,
            $bayarDate,
            $customerId,
            $customerName,
            $bayarNo,
            $usedNow,
            $balanceAfter,
            $remarksTitip,
            $username
        );

        mysqli_stmt_execute(
            $stmtDetailTitip
        );

        mysqli_stmt_close(
            $stmtDetailTitip
        );

        $remaining -= $usedNow;
    }

    mysqli_stmt_close($stmtTitip);

    if ($remaining > 0.0001) {
        throw new Exception(
            'Saldo titip uang tidak mencukupi.'
        );
    }
}

function isCpMcpDocument(string $value): bool {
    return stripos(trim($value), 'CP-MCP') !== false;
}

function getShippingPaymentData(
    mysqli $conn,
    string $invoiceNo,
    string $shippingNo
): array {
    /*
     * CP-MCP adalah dokumen internal Sales Return.
     * Dokumen ini bukan piutang normal dan tidak boleh dijadikan
     * item transaksi pembayaran.
     */
    if (
        isCpMcpDocument($invoiceNo) ||
        isCpMcpDocument($shippingNo)
    ) {
        throw new Exception(
            'Invoice / Shipping CP-MCP tidak boleh dijadikan transaksi pembayaran. ' .
            'Gunakan retur customer melalui panel Retur Customer sebagai kredit standalone.'
        );
    }

    $sql = "
        SELECT
            hi.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            TRIM(di.shipping_no) AS shipping_no,
            MAX(di.shipping_date) AS shipping_date,
            MAX(di.order_no) AS order_no,

            SUM(
                CASE
                    WHEN COALESCE(di.total, 0) > 0
                        THEN COALESCE(di.total, 0)
                    ELSE COALESCE(di.subtotal, 0)
                END
            ) AS shipping_amount,

            (
                SELECT COUNT(DISTINCT TRIM(di_count.shipping_no))
                FROM det_invoice di_count
                WHERE di_count.invoice_no = hi.invoice_no
                  AND COALESCE(TRIM(di_count.shipping_no), '') <> ''
            ) AS shipping_count

        FROM head_invoice hi
        INNER JOIN det_invoice di
            ON di.invoice_no = hi.invoice_no

        WHERE hi.invoice_no = ?
          AND TRIM(di.shipping_no) = ?
          AND UPPER(COALESCE(hi.invoice_no, '')) NOT LIKE '%CP-MCP%'
          AND UPPER(COALESCE(TRIM(di.shipping_no), '')) NOT LIKE '%CP-MCP%'

        GROUP BY
            hi.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            TRIM(di.shipping_no)

        LIMIT 1
        FOR UPDATE
    ";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $invoiceNo, $shippingNo);
    mysqli_stmt_execute($stmt);

    $row = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    if (!$row) {
        throw new Exception(
            'Pasangan Invoice ' . $invoiceNo .
            ' / Shipping ' . $shippingNo .
            ' tidak ditemukan.'
        );
    }

    /*
     * Pembayaran dan retur yang SUDAH dipakai.
     * Untuk single-shipping, hitung di level invoice agar
     * transaksi legacy tanpa shipping_no tetap terbaca.
     */
    if ((int)$row['shipping_count'] === 1) {
        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                COALESCE(SUM(db.bayar_amount), 0) AS paid_amount
            FROM detail_bayar db
            WHERE db.invoice_no = ?
            FOR UPDATE
            "
        );

        mysqli_stmt_bind_param($stmt, 's', $invoiceNo);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                COALESCE(SUM(db.bayar_amount), 0) AS paid_amount
            FROM detail_bayar db
            WHERE db.invoice_no = ?
              AND TRIM(db.shipping_no) = ?
            FOR UPDATE
            "
        );

        mysqli_stmt_bind_param($stmt, 'ss', $invoiceNo, $shippingNo);
    }

    mysqli_stmt_execute($stmt);

    $paidRow = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    $paidAmount = (float)($paidRow['paid_amount'] ?? 0);

    if ((int)$row['shipping_count'] === 1) {
        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                COALESCE(
                SUM(
                    CASE
                            WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                              OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                                THEN COALESCE(hri.grand_total, 0)
                            ELSE COALESCE(hri.return_amount, 0)
                        END
                ),
                0
            ) AS return_used
            FROM head_retur_invoice hri
            INNER JOIN
            (
                SELECT DISTINCT TRIM(db.return_id) AS return_id
                FROM detail_bayar db
                WHERE db.invoice_no = ?
                  AND COALESCE(TRIM(db.return_id), '') <> ''
            ) used
                ON TRIM(hri.return_id) = used.return_id
            WHERE LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
            "
        );

        mysqli_stmt_bind_param($stmt, 's', $invoiceNo);
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT
                COALESCE(
                SUM(
                    CASE
                            WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                              OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                                THEN COALESCE(hri.grand_total, 0)
                            ELSE COALESCE(hri.return_amount, 0)
                        END
                ),
                0
            ) AS return_used
            FROM head_retur_invoice hri
            INNER JOIN
            (
                SELECT DISTINCT TRIM(db.return_id) AS return_id
                FROM detail_bayar db
                WHERE db.invoice_no = ?
                  AND TRIM(db.shipping_no) = ?
                  AND COALESCE(TRIM(db.return_id), '') <> ''
            ) used
                ON TRIM(hri.return_id) = used.return_id
            WHERE LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
            "
        );

        mysqli_stmt_bind_param($stmt, 'ss', $invoiceNo, $shippingNo);
    }

    mysqli_stmt_execute($stmt);

    $returnRow = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    $returnUsed = (float)($returnRow['return_used'] ?? 0);

    $row['paid_amount'] = $paidAmount;
    $row['return_used'] = $returnUsed;

    /*
     * SISA EFEKTIF:
     * shipping_amount - pembayaran - retur terpakai
     */
    $row['sisa_shipping'] = max(
        (float)$row['shipping_amount']
        - $paidAmount
        - $returnUsed,
        0
    );

    return $row;
}

function getCustomerMasterForPayment(
    mysqli $conn,
    string $customerId
): array {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT * FROM m_customer WHERE customer_id = ? LIMIT 1"
    );

    if (!$stmt) {
        throw new Exception(
            'Gagal prepare master customer: ' .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $customerId
    );

    mysqli_stmt_execute($stmt);

    $row = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    if (!$row) {
        throw new Exception(
            'Customer ' . $customerId .
            ' tidak ditemukan pada master customer.'
        );
    }

    return [
        'customer_id' => $customerId,
        'customer_name' => (string)(
            $row['customer']
            ?? $row['customer_name']
            ?? ''
        ),
        'customer_address' => (string)(
            $row['address']
            ?? $row['customer_address']
            ?? ''
        ),
        'customer_city' => (string)(
            $row['city']
            ?? $row['customer_city']
            ?? ''
        ),
    ];
}

function validateSelectedReturnByCustomer(
    mysqli $conn,
    string $returnId,
    string $customerId
): float {
    if ($returnId === '') {
        return 0.0;
    }

    /*
     * Lock master retur terlebih dahulu.
     * Ini penting agar dua user tidak bisa memakai return_id
     * yang sama secara bersamaan.
     */
    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            return_id,
            return_amount,
            grand_total,
            invoice_no,
            shipping_no,
            customer_id,
            CASE
                WHEN UPPER(COALESCE(invoice_no, '')) LIKE '%CP-MCP%'
                  OR UPPER(COALESCE(shipping_no, '')) LIKE '%CP-MCP%'
                    THEN COALESCE(grand_total, 0)
                ELSE COALESCE(return_amount, 0)
            END AS effective_return_amount
        FROM head_retur_invoice
        WHERE
            return_id = ?
            AND customer_id = ?
            AND LOWER(
                COALESCE(
                    status,
                    'Open'
                )
            ) <> 'cancelled'
        LIMIT 1
        FOR UPDATE
        "
    );

    if (!$stmt) {
        throw new Exception(
            'Gagal prepare validasi retur: ' .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        'ss',
        $returnId,
        $customerId
    );

    mysqli_stmt_execute($stmt);

    $row = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    if (!$row) {
        throw new Exception(
            'No. Retur ' .
            $returnId .
            ' tidak ditemukan, sudah cancelled, atau bukan milik customer yang dipilih.'
        );
    }

    /*
     * Proteksi server-side:
     * return_id yang sudah pernah tersimpan di detail_bayar
     * tidak boleh digunakan kembali.
     *
     * Karena row head_retur_invoice di atas sudah FOR UPDATE,
     * transaksi lain yang mencoba return_id sama akan menunggu
     * sampai transaksi ini commit/rollback, lalu pengecekan ini
     * akan membaca hasil terbaru.
     */
    $stmtUsed = mysqli_prepare(
        $conn,
        "
        SELECT
            bayar_no
        FROM detail_bayar
        WHERE
            TRIM(
                COALESCE(
                    return_id,
                    ''
                )
            ) = TRIM(?)
        LIMIT 1
        "
    );

    if (!$stmtUsed) {
        throw new Exception(
            'Gagal prepare pengecekan penggunaan retur: ' .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $stmtUsed,
        's',
        $returnId
    );

    mysqli_stmt_execute($stmtUsed);

    $usedRow = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmtUsed)
    );

    mysqli_stmt_close($stmtUsed);

    if ($usedRow) {
        throw new Exception(
            'No. Retur ' .
            $returnId .
            ' sudah pernah digunakan pada pembayaran ' .
            (string)($usedRow['bayar_no'] ?? '') .
            ' dan tidak dapat digunakan kembali.'
        );
    }

    $returnAmount =
        round(
            (float)(
                $row['effective_return_amount']
                ?? 0
            ),
            2
        );

    if ($returnAmount <= 0.0001) {
        throw new Exception(
            'Nilai No. Retur ' .
            $returnId .
            ' tidak valid atau Rp 0,00.'
        );
    }

    return $returnAmount;
}

function updateInvoicePaymentStatus(
    mysqli $conn,
    string $invoiceNo,
    string $username
): void {
    $sql = "
        SELECT
            COALESCE(
                (
                    SELECT SUM(
                        CASE
                            WHEN COALESCE(di.total, 0) > 0
                                THEN COALESCE(di.total, 0)
                            ELSE COALESCE(di.subtotal, 0)
                        END
                    )
                    FROM det_invoice di
                    WHERE di.invoice_no = ?
                ),
                0
            ) AS invoice_amount,

            COALESCE(
                (
                    SELECT SUM(db.bayar_amount)
                    FROM detail_bayar db
                    WHERE db.invoice_no = ?
                ),
                0
            ) AS paid_amount,

            COALESCE(
                (
                    SELECT SUM(
                        CASE
                            WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                              OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                                THEN COALESCE(hri.grand_total, 0)
                            ELSE COALESCE(hri.return_amount, 0)
                        END
                    )
                    FROM head_retur_invoice hri
                    INNER JOIN
                    (
                        SELECT DISTINCT TRIM(db.return_id) AS return_id
                        FROM detail_bayar db
                        WHERE db.invoice_no = ?
                          AND COALESCE(TRIM(db.return_id), '') <> ''
                    ) used
                        ON TRIM(hri.return_id) = used.return_id
                    WHERE LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
                ),
                0
            ) AS return_amount
    ";

    $stmt = mysqli_prepare($conn, $sql);

    mysqli_stmt_bind_param(
        $stmt,
        'sss',
        $invoiceNo,
        $invoiceNo,
        $invoiceNo
    );

    mysqli_stmt_execute($stmt);

    $row = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    $invoiceAmount = (float)($row['invoice_amount'] ?? 0);
    $invoicePaid = (float)($row['paid_amount'] ?? 0);
    $invoiceRetur = (float)($row['return_amount'] ?? 0);

    $invoiceBalance = max(
        $invoiceAmount - $invoicePaid - $invoiceRetur,
        0
    );

    if ($invoiceBalance <= 0.0001) {
        $newStatus = 'Paid';
    } elseif ($invoicePaid > 0.0001 || $invoiceRetur > 0.0001) {
        $newStatus = 'Partial';
    } else {
        $newStatus = 'Open';
    }

    $stmt = mysqli_prepare(
        $conn,
        "
        UPDATE head_invoice
        SET
            payment_balance = ?,
            status = ?,
            user_modified = ?,
            date_modified = NOW()
        WHERE invoice_no = ?
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        'dsss',
        $invoiceBalance,
        $newStatus,
        $username,
        $invoiceNo
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| REQUEST
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD']
    !== 'POST'
) {
    redirectWithAlert(
        'error',
        'Invalid request.'
    );
}

$bayarDate =
    parseDateInput(
        $_POST['bayar_date']
        ?? ''
    );

$customerIdPosted =
    trim(
        (string)(
            $_POST['customer_id']
            ?? ''
        )
    );

$returnId =
    trim(
        (string)(
            $_POST['return_id']
            ?? ''
        )
    );


$nominalBayar =
    parseNumber(
        $_POST['nominal_bayar']
        ?? 0
    );

$pakaiTitip =
    isset(
        $_POST['pakai_titip']
    ) &&
    (string)$_POST[
        'pakai_titip'
    ] === '1';

$nominalTitip =
    $pakaiTitip
        ? parseNumber(
            $_POST['nominal_titip']
            ?? 0
        )
        : 0.0;

$totalBayar =
    $nominalBayar +
    $nominalTitip;

$keterangan =
    trim(
        (string)(
            $_POST['keterangan']
            ?? ''
        )
    );

$bankName =
    trim(
        (string)(
            $_POST['bank_name']
            ?? ''
        )
    );

$remarks =
    trim(
        (string)(
            $_POST['remarks']
            ?? ''
        )
    );

$postedItems =
    $_POST['items']
    ?? [];

$username =
    (string)(
        $_SESSION[
            'username'
        ]
        ?? 'system'
    );

if (
    !$bayarDate ||
    $customerIdPosted === '' ||
    $keterangan === '' ||
    !is_array($postedItems)
) {
    redirectWithAlert(
        'error',
        'Data pembayaran belum lengkap.',
        'add_bayar'
    );
}

if (
    $nominalBayar < 0 ||
    $nominalTitip < 0
) {
    redirectWithAlert(
        'error',
        'Nominal pembayaran tidak boleh negatif.',
        'add_bayar'
    );
}

/*
 * Ambil hanya item yang dicentang.
 */
$selectedPosted = [];

foreach (
    $postedItems
    as $index => $item
) {
    if (!is_array($item)) {
        continue;
    }

    $selected =
        isset($item['selected']) &&
        (string)$item['selected']
            === '1';

    if (!$selected) {
        continue;
    }

    $invoiceNo =
        trim(
            (string)(
                $item['invoice_no']
                ?? ''
            )
        );

    $shippingNo =
        trim(
            (string)(
                $item['shipping_no']
                ?? ''
            )
        );

    if (
        $invoiceNo === '' ||
        $shippingNo === ''
    ) {
        redirectWithAlert(
            'error',
            'Ada invoice/shipping terpilih yang tidak lengkap.',
            'add_bayar'
        );
    }

    /*
     * Jangan hanya mengandalkan filter pada add_bayar.php.
     * Tolak juga POST manual/manipulasi yang mencoba memasukkan CP-MCP.
     */
    if (
        isCpMcpDocument($invoiceNo) ||
        isCpMcpDocument($shippingNo)
    ) {
        redirectWithAlert(
            'error',
            'Invoice / Shipping CP-MCP tidak boleh dijadikan transaksi pembayaran. ' .
            'Pilih retur customer pada panel Retur Customer bila ingin menggunakan retur sebagai pengurang pembayaran.',
            'add_bayar'
        );
    }

    $key =
        $invoiceNo .
        '|' .
        $shippingNo;

    if (
        isset(
            $selectedPosted[$key]
        )
    ) {
        redirectWithAlert(
            'error',
            'Invoice / Shipping terpilih ganda: ' .
            $invoiceNo .
            ' / ' .
            $shippingNo,
            'add_bayar'
        );
    }

    $selectedPosted[$key] = [
        'invoice_no' =>
            $invoiceNo,
        'shipping_no' =>
            $shippingNo
    ];
}

if (
    !$selectedPosted &&
    $returnId === ''
) {
    redirectWithAlert(
        'error',
        'Minimal pilih 1 Invoice / Shipping atau 1 Retur Customer.',
        'add_bayar'
    );
}

mysqli_begin_transaction($conn);

try {
    /*
    |--------------------------------------------------------------------------
    | GENERATE BAYAR NO
    |--------------------------------------------------------------------------
    */
    $bayarNo =
        generateBayarNo($conn);

    /*
    |--------------------------------------------------------------------------
    | VALIDASI SEMUA ITEM DARI DATABASE
    |--------------------------------------------------------------------------
    */
    $validItems = [];
    $selectedTotal = 0.0;

    /*
     * Customer selalu diambil ulang dari master.
     * Ini diperlukan untuk transaksi retur-only tanpa invoice/shipping.
     */
    $customerMaster =
        getCustomerMasterForPayment(
            $conn,
            $customerIdPosted
        );

    $customerId =
        (string)$customerMaster['customer_id'];

    $customerName =
        (string)$customerMaster['customer_name'];

    $customerAddress =
        (string)$customerMaster['customer_address'];

    $customerCity =
        (string)$customerMaster['customer_city'];

    /*
     * Retur sekarang berdiri sendiri pada level pembayaran/customer.
     * Bukan lagi per Invoice / Shipping.
     */
    $selectedReturnAmount = 0.0;

    foreach (
        $selectedPosted
        as $posted
    ) {
        $shipping =
            getShippingPaymentData(
                $conn,
                $posted[
                    'invoice_no'
                ],
                $posted[
                    'shipping_no'
                ]
            );

        /*
         * Semua invoice/shipping yang dipilih harus sesuai customer form.
         */
        if (
            $customerId !==
            (string)$shipping['customer_id']
        ) {
            throw new Exception(
                'Invoice / Shipping yang dipilih bukan milik Customer form.'
            );
        }

        $sisaShipping =
            (float)$shipping[
                'sisa_shipping'
            ];

        /*
         * Sesuai rule add_bayar terbaru:
         * hanya Invoice / Shipping yang masih memiliki piutang
         * yang boleh diproses.
         *
         * Jangan mengandalkan filter di halaman input saja,
         * karena data POST dapat dimanipulasi.
         */
        if ($sisaShipping <= 0.01) {
            throw new Exception(
                'Shipping ' .
                $posted[
                    'shipping_no'
                ] .
                ' sudah lunas / tidak memiliki sisa piutang dan tidak dapat diproses kembali.'
            );
        }

        $selectedTotal +=
            $sisaShipping;

        $validItems[] = [
            'invoice_no' =>
                (string)$shipping[
                    'invoice_no'
                ],
            'invoice_date' =>
                (string)$shipping[
                    'invoice_date'
                ],
            'shipping_no' =>
                (string)$shipping[
                    'shipping_no'
                ],
            'shipping_date' =>
                (string)$shipping[
                    'shipping_date'
                ],
            'shipping_amount' =>
                (float)$shipping[
                    'shipping_amount'
                ],
            'sisa_before' =>
                $sisaShipping
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI RETUR BERDASARKAN CUSTOMER
    |--------------------------------------------------------------------------
    | Retur berdiri sendiri sebagai kredit customer.
    | invoice_no / shipping_no asal retur (termasuk dokumen CP-MCP)
    | TIDAK dipakai untuk menghubungkan retur ke item pembayaran.
    |
    | Syarat retur hanya:
    | - return_id aktif / tidak Cancelled
    | - milik customer pembayaran yang sama
    | - belum dipakai pada pembayaran lain
    |--------------------------------------------------------------------------
    */
    if ($returnId !== '') {
        $selectedReturnAmount =
            validateSelectedReturnByCustomer(
                $conn,
                $returnId,
                $customerId
            );
    }

    $selectedTotal =
        round(
            $selectedTotal,
            2
        );

    $selectedReturnAmount =
        round(
            $selectedReturnAmount,
            2
        );

    /*
     * Retur mengurangi total yang harus dibayar.
     * Jika retur lebih besar dari tagihan, target pembayaran minimum Rp 0.
     * Selisih retur tidak otomatis menjadi titip uang.
     */
    $netPayable =
        max(
            $selectedTotal -
            $selectedReturnAmount,
            0
        );

    $netPayable =
        round(
            $netPayable,
            2
        );

    $totalBayar =
        round(
            $totalBayar,
            2
        );

    /*
    |--------------------------------------------------------------------------
    | VALIDASI TOTAL SETELAH RETUR - SUPPORT PEMBAYARAN PARSIAL
    |--------------------------------------------------------------------------
    | Total Cash + Titip boleh lebih kecil dari netPayable.
    | Yang tidak diperbolehkan adalah pembayaran melebihi sisa tagihan.
    */
    if ($totalBayar > $netPayable + 0.01) {
        throw new Exception(
            'Total bayar tidak boleh melebihi total tagihan setelah dikurangi Retur. ' .
            'Total tagihan: Rp ' .
            number_format($selectedTotal, 2, ',', '.') .
            ' | Retur: Rp ' .
            number_format($selectedReturnAmount, 2, ',', '.') .
            ' | Maksimal pembayaran: Rp ' .
            number_format($netPayable, 2, ',', '.') .
            ' | Total bayar: Rp ' .
            number_format($totalBayar, 2, ',', '.')
        );
    }

    if (
        $totalBayar <= 0.0001 &&
        $netPayable > 0.0001 &&
        $returnId === ''
    ) {
        throw new Exception(
            'Nominal pembayaran harus lebih dari Rp 0,00.'
        );
    }

    /*
     * Jika net payable Rp 0 karena Retur, transaksi tetap boleh disimpan.
     */
    if (
        $netPayable <= 0.0001 &&
        $returnId === ''
    ) {
        throw new Exception(
            'Pembayaran Rp 0,00 hanya dapat disimpan jika No. Retur dipilih.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI SALDO TITIP
    |--------------------------------------------------------------------------
    */
    if ($nominalTitip > 0.0001) {
        $stmt =
            mysqli_prepare(
                $conn,
                "
                SELECT
                    COALESCE(
                        SUM(
                            balance_amount
                        ),
                        0
                    ) AS saldo_titip
                FROM head_titip
                WHERE
                    customer_id = ?
                    AND balance_amount > 0
                FOR UPDATE
                "
            );

        mysqli_stmt_bind_param(
            $stmt,
            's',
            $customerId
        );

        mysqli_stmt_execute($stmt);

        $saldoRow =
            mysqli_fetch_assoc(
                mysqli_stmt_get_result(
                    $stmt
                )
            );

        mysqli_stmt_close($stmt);

        $saldoTitip =
            (float)(
                $saldoRow[
                    'saldo_titip'
                ]
                ?? 0
            );

        if (
            $nominalTitip >
            $saldoTitip + 0.0001
        ) {
            throw new Exception(
                'Nominal titip yang dipakai melebihi saldo titip uang customer.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | INSERT HEAD BAYAR
    |--------------------------------------------------------------------------
    */
    $stmtHead =
        mysqli_prepare(
            $conn,
            "
            INSERT INTO head_bayar
            (
                bayar_no,
                bayar_date,
                customer_id,
                customer_name,
                customer_address,
                customer_city,
                total_bayar,
                keterangan,
                bank_name,
                remarks,
                create_user,
                date_created
            )
            VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                NOW()
            )
            "
        );

    mysqli_stmt_bind_param(
        $stmtHead,
        'ssssssdssss',
        $bayarNo,
        $bayarDate,
        $customerId,
        $customerName,
        $customerAddress,
        $customerCity,
        $totalBayar,
        $keterangan,
        $bankName,
        $remarks,
        $username
    );

    mysqli_stmt_execute(
        $stmtHead
    );

    mysqli_stmt_close(
        $stmtHead
    );

    /*
    |--------------------------------------------------------------------------
    | INSERT DETAIL BAYAR
    |--------------------------------------------------------------------------
    | Alokasi:
    | - Titip dipakai dahulu secara berurutan.
    | - Sisanya cash/transfer.
    | - bayar_amount per detail mengikuti nominal pembayaran yang tersedia.
    | - Jika nominal lebih kecil dari sisa tagihan, pembayaran disimpan sebagai Partial.
    | - Alokasi berjalan berurutan ke Shipping yang dipilih.
    |--------------------------------------------------------------------------
    */
    $remainingTitip =
        $nominalTitip;

    $remainingCash =
        $nominalBayar;

    /*
     * Total pembayaran yang masih harus dialokasikan ke detail setelah Retur.
     */
    $remainingPaymentToAllocate =
        $totalBayar;

    $stmtDetail =
        mysqli_prepare(
            $conn,
            "
            INSERT INTO detail_bayar
            (
                bayar_no,
                invoice_no,
                shipping_no,
                return_id,
                invoice_date,
                invoice_amount,
                cash_amount,
                titip_amount,
                bayar_amount,
                sisa_after,
                remarks,
                create_user,
                date_created
            )
            VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?,
                NOW()
            )
            "
        );

    if (!$stmtDetail) {
        throw new Exception(
            'Gagal prepare INSERT detail_bayar: ' .
            mysqli_error($conn)
        );
    }

    $affectedInvoices = [];

    /*
     * Karena schema existing menyimpan return_id di detail_bayar,
     * return_id standalone disimpan SATU KALI saja pada detail pertama.
     * Detail berikutnya dikosongkan supaya tidak terjadi duplikasi Retur.
     */
    $returnStored = false;

    foreach (
        $validItems
        as $item
    ) {
        $sisaBefore =
            round(
                (float)$item[
                    'sisa_before'
                ],
                2
            );

        /*
         * Retur standalone mengurangi total pembayaran customer.
         * Alokasi pembayaran dibagikan berurutan ke Invoice/Shipping terpilih.
         * Baris setelah net payable habis akan mendapat bayar_amount = 0.
         */
        $detailAmount =
            min(
                $sisaBefore,
                $remainingPaymentToAllocate
            );

        $detailAmount =
            round(
                $detailAmount,
                2
            );

        $remainingPaymentToAllocate -=
            $detailAmount;

        if (
            abs(
                $remainingPaymentToAllocate
            ) < 0.0001
        ) {
            $remainingPaymentToAllocate = 0;
        }

        $detailTitip =
            min(
                $remainingTitip,
                $detailAmount
            );

        $detailCash =
            $detailAmount -
            $detailTitip;

        if (
            $detailCash >
            $remainingCash + 0.01
        ) {
            throw new Exception(
                'Alokasi pembayaran cash/transfer tidak mencukupi.'
            );
        }

        $remainingTitip -=
            $detailTitip;

        $remainingCash -=
            $detailCash;

        if (
            abs(
                $remainingTitip
            ) < 0.0001
        ) {
            $remainingTitip = 0;
        }

        if (
            abs(
                $remainingCash
            ) < 0.0001
        ) {
            $remainingCash = 0;
        }

        $detailRemarks = $remarks;

        $detailReturnId = '';
        $detailReturnApplied = 0.0;

        if (
            !$returnStored &&
            $returnId !== ''
        ) {
            $detailReturnId = $returnId;

            /*
             * return_id secara schema disimpan satu kali pada detail pertama.
             * sisa_after dibuat konsisten dengan sisa efektif.
             */
            $detailReturnApplied = min(
                $selectedReturnAmount,
                $sisaBefore
            );

            $returnStored = true;
        }

        $sisaAfter = max(
            $sisaBefore
            - $detailAmount
            - $detailReturnApplied,
            0
        );

        mysqli_stmt_bind_param(
            $stmtDetail,
            'sssssdddddss',
            $bayarNo,
            $item['invoice_no'],
            $item['shipping_no'],
            $detailReturnId,
            $item['invoice_date'],
            $item['shipping_amount'],
            $detailCash,
            $detailTitip,
            $detailAmount,
            $sisaAfter,
            $detailRemarks,
            $username
        );

        mysqli_stmt_execute(
            $stmtDetail
        );

        $affectedInvoices[
            $item[
                'invoice_no'
            ]
        ] = true;
    }

    /*
     * Retur standalone tanpa invoice/shipping.
     * invoice_no dan shipping_no sengaja kosong agar TIDAK terhubung
     * ke dokumen CP-MCP asal retur.
     */
    if (
        empty($validItems) &&
        $returnId !== ''
    ) {
        $blankInvoiceNo = '';
        $blankShippingNo = '';
        $blankInvoiceDate = '';
        $zeroAmount = 0.0;

        $returnOnlyRemarks =
            $remarks !== ''
                ? $remarks
                : 'Retur Customer Standalone';

        mysqli_stmt_bind_param(
            $stmtDetail,
            'sssssdddddss',
            $bayarNo,
            $blankInvoiceNo,
            $blankShippingNo,
            $returnId,
            $blankInvoiceDate,
            $zeroAmount,
            $zeroAmount,
            $zeroAmount,
            $zeroAmount,
            $zeroAmount,
            $returnOnlyRemarks,
            $username
        );

        mysqli_stmt_execute(
            $stmtDetail
        );

        $returnStored = true;
    }

    mysqli_stmt_close(
        $stmtDetail
    );

    /*
     * Setelah seluruh detail selesai,
     * total alokasi harus habis.
     */
    if (
        abs($remainingCash)
        > 0.01 ||
        abs($remainingTitip)
        > 0.01 ||
        abs($remainingPaymentToAllocate)
        > 0.01
    ) {
        throw new Exception(
            'Alokasi pembayaran tidak seimbang. ' .
            'Sisa Cash: Rp ' .
            number_format(
                $remainingCash,
                2,
                ',',
                '.'
            ) .
            ' | Sisa Titip: Rp ' .
            number_format(
                $remainingTitip,
                2,
                ',',
                '.'
            ) .
            ' | Sisa Alokasi: Rp ' .
            number_format(
                $remainingPaymentToAllocate,
                2,
                ',',
                '.'
            )
        );
    }

    /*
    |--------------------------------------------------------------------------
    | MUTASI TITIP UANG
    |--------------------------------------------------------------------------
    | Satu kali per bayar_no / customer,
    | walaupun detail bayar terdiri dari banyak invoice.
    |--------------------------------------------------------------------------
    */
    applyTitipUsage(
        $conn,
        $customerId,
        $customerName,
        $bayarNo,
        $bayarDate,
        $nominalTitip,
        $username
    );

    /*
    |--------------------------------------------------------------------------
    | UPDATE SELURUH INVOICE TERDAMPAK
    |--------------------------------------------------------------------------
    */
    foreach (
        array_keys(
            $affectedInvoices
        )
        as $invoiceNo
    ) {
        updateInvoicePaymentStatus(
            $conn,
            $invoiceNo,
            $username
        );
    }

    mysqli_commit($conn);

    redirectWithAlert(
        'success',
        'Pembayaran berhasil disimpan dengan No. Bayar ' .
        $bayarNo .
        ' untuk ' .
        count($validItems) .
        ' Invoice / Shipping. Total Tagihan Rp ' .
        number_format(
            $selectedTotal,
            2,
            ',',
            '.'
        ) .
        ($returnId !== ''
            ? ' | Retur ' . $returnId .
              ' Rp ' .
              number_format(
                  $selectedReturnAmount,
                  2,
                  ',',
                  '.'
              )
            : '') .
        ' | Total Bayar Rp ' .
        number_format(
            $totalBayar,
            2,
            ',',
            '.'
        ) .
        ' | Sisa Setelah Pembayaran Rp ' .
        number_format(
            max($netPayable - $totalBayar, 0),
            2,
            ',',
            '.'
        ) .
        '.',
        'pembayaran'
    );

} catch (Throwable $e) {
    mysqli_rollback($conn);

    redirectWithAlert(
        'error',
        $e->getMessage(),
        'add_bayar'
    );
}