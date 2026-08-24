<?php
/*
 * RULE NILAI RETUR CP-MCP:
 * - CP-MCP memakai grand_total.
 * - Retur normal memakai return_amount.
 */

// modul/transaksi/update_bayar.php
// Update Pembayaran Multi Invoice / Multi Shipping.
//
// Strategi aman:
// 1. Ambil seluruh detail lama.
// 2. Rollback titip lama.
// 3. Validasi seluruh Invoice/Shipping baru dengan mengabaikan bayar_no ini, termasuk kompatibilitas legacy single-shipping.
// 4. Hapus seluruh detail_bayar lama.
// 5. Update head_bayar.
// 6. Insert ulang detail_bayar baru dengan bayar_no yang SAMA.
// 7. Apply titip baru.
// 8. Hitung ulang semua invoice lama + baru yang terdampak.

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

    header("Location: ../../index.php?page=" . $page);
    exit;
}

function parseDateInput($value) {
    $value = trim((string)$value);

    foreach (['d-M-Y','Y-m-d','d-m-Y','d/m/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    return null;
}

function parseNumber($value) {
    $value = trim((string)$value);
    if ($value === '') return 0.0;

    $value = str_ireplace('Rp', '', $value);
    $value = str_replace([' ', '.'], '', $value);
    $value = str_replace(',', '.', $value);

    return is_numeric($value) ? (float)$value : 0.0;
}

function isCpMcpDocument(string $value): bool {
    return stripos(trim($value), 'CP-MCP') !== false;
}

function rollbackTitipUsage($conn, $bayarNo, $username) {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT titip_no, amount_out
         FROM detail_titip
         WHERE transaction_type = 'PAKAI'
           AND ref_no = ?
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($stmt, 's', $bayarNo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $titipNo = (string)$row['titip_no'];
        $amountOut = (float)$row['amount_out'];

        $stmtBack = mysqli_prepare(
            $conn,
            "UPDATE head_titip
             SET
                used_amount = GREATEST(COALESCE(used_amount,0) - ?, 0),
                balance_amount = COALESCE(balance_amount,0) + ?,
                status = 'Open',
                user_modified = ?,
                date_modified = NOW()
             WHERE titip_no = ?"
        );
        mysqli_stmt_bind_param(
            $stmtBack,
            'ddss',
            $amountOut,
            $amountOut,
            $username,
            $titipNo
        );
        mysqli_stmt_execute($stmtBack);
        mysqli_stmt_close($stmtBack);
    }
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM detail_titip
         WHERE transaction_type = 'PAKAI'
           AND ref_no = ?"
    );
    mysqli_stmt_bind_param($stmt, 's', $bayarNo);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
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
    if ($amount <= 0.0001) return;

    $remaining = $amount;

    $stmt = mysqli_prepare(
        $conn,
        "SELECT titip_no, balance_amount
         FROM head_titip
         WHERE customer_id = ?
           AND balance_amount > 0
         ORDER BY titip_date ASC, titip_no ASC
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($stmt, 's', $customerId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        if ($remaining <= 0.0001) break;

        $titipNo = (string)$row['titip_no'];
        $before = (float)$row['balance_amount'];
        $used = min($before, $remaining);
        $after = $before - $used;
        $status = $after <= 0.0001 ? 'Closed' : 'Open';

        $stmtUp = mysqli_prepare(
            $conn,
            "UPDATE head_titip
             SET
                used_amount = COALESCE(used_amount,0) + ?,
                balance_amount = balance_amount - ?,
                status = ?,
                user_modified = ?,
                date_modified = NOW()
             WHERE titip_no = ?"
        );
        mysqli_stmt_bind_param(
            $stmtUp,
            'ddsss',
            $used,
            $used,
            $status,
            $username,
            $titipNo
        );
        mysqli_stmt_execute($stmtUp);
        mysqli_stmt_close($stmtUp);

        $remarksTitip = 'Dipakai untuk pembayaran ' . $bayarNo;

        $stmtDet = mysqli_prepare(
            $conn,
            "INSERT INTO detail_titip
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
            VALUES (?, ?, ?, ?, 'PAKAI', ?, 0, ?, ?, 'PEMBAYARAN', '', ?, ?, NOW())"
        );
        mysqli_stmt_bind_param(
            $stmtDet,
            'sssssddss',
            $titipNo,
            $bayarDate,
            $customerId,
            $customerName,
            $bayarNo,
            $used,
            $after,
            $remarksTitip,
            $username
        );
        mysqli_stmt_execute($stmtDet);
        mysqli_stmt_close($stmtDet);

        $remaining -= $used;
    }

    mysqli_stmt_close($stmt);

    if ($remaining > 0.0001) {
        throw new Exception('Saldo titip uang tidak mencukupi.');
    }
}

function getShippingForUpdate(
    mysqli $conn,
    string $invoiceNo,
    string $shippingNo,
    string $excludeBayarNo
): array {
    if (
        isCpMcpDocument($invoiceNo) ||
        isCpMcpDocument($shippingNo)
    ) {
        throw new Exception(
            'Invoice / Shipping CP-MCP tidak boleh dijadikan transaksi pembayaran. ' .
            'Gunakan Retur Customer sebagai kredit standalone.'
        );
    }

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            hi.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            TRIM(di.shipping_no) AS shipping_no,
            MAX(di.shipping_date) AS shipping_date,

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
        "
    );

    mysqli_stmt_bind_param($stmt, 'ss', $invoiceNo, $shippingNo);
    mysqli_stmt_execute($stmt);

    $row = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    if (!$row) {
        throw new Exception(
            'Invoice / Shipping tidak ditemukan: ' .
            $invoiceNo . ' / ' . $shippingNo
        );
    }

    /*
     * Hitung pembayaran selain bayar_no yang sedang diedit.
     */
    if ((int)$row['shipping_count'] === 1) {
        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT COALESCE(SUM(db.bayar_amount), 0) AS paid
            FROM detail_bayar db
            WHERE db.invoice_no = ?
              AND db.bayar_no <> ?
            FOR UPDATE
            "
        );

        mysqli_stmt_bind_param(
            $stmt,
            'ss',
            $invoiceNo,
            $excludeBayarNo
        );
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT COALESCE(SUM(db.bayar_amount), 0) AS paid
            FROM detail_bayar db
            WHERE db.invoice_no = ?
              AND TRIM(db.shipping_no) = ?
              AND db.bayar_no <> ?
            FOR UPDATE
            "
        );

        mysqli_stmt_bind_param(
            $stmt,
            'sss',
            $invoiceNo,
            $shippingNo,
            $excludeBayarNo
        );
    }

    mysqli_stmt_execute($stmt);

    $paidRow = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    $paidExceptCurrent = (float)($paidRow['paid'] ?? 0);

    /*
     * Hitung retur yang sudah dipakai oleh pembayaran LAIN.
     * Retur milik bayar_no yang sedang diedit dikeluarkan,
     * lalu akan divalidasi/dialokasikan kembali dari form edit.
     */
    if ((int)$row['shipping_count'] === 1) {
        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT COALESCE(
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
                  AND db.bayar_no <> ?
                  AND COALESCE(TRIM(db.return_id), '') <> ''
            ) used
                ON TRIM(hri.return_id) = used.return_id
            WHERE LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
            "
        );

        mysqli_stmt_bind_param(
            $stmt,
            'ss',
            $invoiceNo,
            $excludeBayarNo
        );
    } else {
        $stmt = mysqli_prepare(
            $conn,
            "
            SELECT COALESCE(
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
                  AND db.bayar_no <> ?
                  AND COALESCE(TRIM(db.return_id), '') <> ''
            ) used
                ON TRIM(hri.return_id) = used.return_id
            WHERE LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
            "
        );

        mysqli_stmt_bind_param(
            $stmt,
            'sss',
            $invoiceNo,
            $shippingNo,
            $excludeBayarNo
        );
    }

    mysqli_stmt_execute($stmt);

    $returnRow = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    $returnExceptCurrent =
        (float)($returnRow['return_used'] ?? 0);

    $row['paid_except_current'] = $paidExceptCurrent;
    $row['return_except_current'] = $returnExceptCurrent;

    /*
     * SISA EFEKTIF sebelum transaksi ini:
     * Nilai Shipping - pembayaran lain - retur terpakai pembayaran lain.
     */
    $row['sisa_before_current'] = max(
        (float)$row['shipping_amount']
        - $paidExceptCurrent
        - $returnExceptCurrent,
        0
    );

    return $row;
}

function getOpeningForUpdate(
    mysqli $conn,
    int $openingId,
    string $customerId,
    string $excludeBayarNo
): array {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            opening_id,
            opening_date,
            customer_id,
            customer_name,
            customer_city,
            opening_balance
         FROM customer_opening_balance
         WHERE opening_id = ?
           AND customer_id = ?
           AND LOWER(COALESCE(status,'Active')) = 'active'
           AND ABS(opening_balance) > 0.01
         LIMIT 1
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception('Gagal prepare saldo awal: ' . mysqli_error($conn));
    }

    mysqli_stmt_bind_param($stmt,'is',$openingId,$customerId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        throw new Exception(
            'Saldo awal customer tidak ditemukan, tidak aktif, atau sudah bernilai nol.'
        );
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(SUM(ABS(bayar_amount)),0) AS paid
         FROM detail_bayar
         WHERE payment_source = 'OPENING'
           AND opening_id = ?
           AND bayar_no <> ?
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($stmt,'is',$openingId,$excludeBayarNo);
    mysqli_stmt_execute($stmt);
    $paidRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $openingBalance = (float)($row['opening_balance'] ?? 0);
    $paidExceptCurrent = (float)($paidRow['paid'] ?? 0);

    $row['paid_except_current'] = $paidExceptCurrent;
    $row['opening_sign'] = $openingBalance < 0 ? -1 : 1;
    $row['sisa_before_current'] = max(
        abs($openingBalance) - $paidExceptCurrent,
        0
    );

    return $row;
}

function validateReturnByCustomer(
    mysqli $conn,
    string $returnId,
    string $customerId,
    string $currentBayarNo
): float {
    if ($returnId === '') {
        return 0.0;
    }

    /*
     * Lock master retur agar dua proses tidak dapat memakai
     * return_id yang sama secara bersamaan.
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
        WHERE return_id = ?
          AND customer_id = ?
          AND LOWER(COALESCE(status, 'Open')) <> 'cancelled'
        LIMIT 1
        FOR UPDATE
        "
    );

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
            'No. Retur ' . $returnId .
            ' tidak ditemukan, sudah cancelled, atau bukan milik customer pembayaran.'
        );
    }

    /*
     * Retur yang sedang dipakai bayar_no ini boleh dipertahankan.
     * Tetapi jika return_id yang sama dipakai bayar_no LAIN,
     * update wajib ditolak.
     */
    $stmtUsed = mysqli_prepare(
        $conn,
        "
        SELECT bayar_no
        FROM detail_bayar
        WHERE TRIM(COALESCE(return_id, '')) = TRIM(?)
          AND bayar_no <> ?
        LIMIT 1
        "
    );

    mysqli_stmt_bind_param(
        $stmtUsed,
        'ss',
        $returnId,
        $currentBayarNo
    );

    mysqli_stmt_execute($stmtUsed);

    $usedRow = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmtUsed)
    );

    mysqli_stmt_close($stmtUsed);

    if ($usedRow) {
        throw new Exception(
            'No. Retur ' . $returnId .
            ' sudah digunakan pada pembayaran ' .
            (string)($usedRow['bayar_no'] ?? '') .
            ' dan tidak dapat digunakan kembali.'
        );
    }

    $amount = round(
        (float)($row['effective_return_amount'] ?? 0),
        2
    );

    if ($amount <= 0.0001) {
        throw new Exception(
            'Nilai No. Retur ' . $returnId .
            ' tidak valid atau Rp 0,00.'
        );
    }

    return $amount;
}

function updateInvoiceBalance(
    mysqli $conn,
    string $invoiceNo,
    string $username
): void {
    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            COALESCE((
                SELECT SUM(
                    CASE
                        WHEN COALESCE(di.total, 0) > 0
                            THEN COALESCE(di.total, 0)
                        ELSE COALESCE(di.subtotal, 0)
                    END
                )
                FROM det_invoice di
                WHERE di.invoice_no = ?
            ), 0) AS invoice_amount,

            COALESCE((
                SELECT SUM(db.bayar_amount)
                FROM detail_bayar db
                WHERE db.invoice_no = ?
            ), 0) AS paid_amount,

            COALESCE((
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
            ), 0) AS return_amount
        "
    );

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
    $paid = (float)($row['paid_amount'] ?? 0);
    $retur = (float)($row['return_amount'] ?? 0);

    $balance = max(
        $invoiceAmount - $paid - $retur,
        0
    );

    if ($balance <= 0.0001) {
        $status = 'Paid';
    } elseif ($paid > 0.0001 || $retur > 0.0001) {
        $status = 'Partial';
    } else {
        $status = 'Open';
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
        $balance,
        $status,
        $username,
        $invoiceNo
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithAlert('error', 'Invalid request.');
}

$bayarNo = trim((string)($_POST['bayar_no'] ?? ''));
$bayarDate = parseDateInput($_POST['bayar_date'] ?? '');
$customerIdPosted = trim((string)($_POST['customer_id'] ?? ''));
$returnId = trim((string)($_POST['return_id'] ?? ''));
$nominalCash = parseNumber($_POST['nominal_bayar'] ?? 0);
$pakaiTitip = isset($_POST['pakai_titip']) && (string)$_POST['pakai_titip'] === '1';
$nominalTitip = $pakaiTitip ? parseNumber($_POST['nominal_titip'] ?? 0) : 0.0;
$totalBayar = round($nominalCash + $nominalTitip, 2);
$keterangan = trim((string)($_POST['keterangan'] ?? ''));
$bankName = trim((string)($_POST['bank_name'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$postedItems = $_POST['items'] ?? [];
$username = $_SESSION['username'] ?? 'system';

if (
    $bayarNo === '' ||
    !$bayarDate ||
    $customerIdPosted === '' ||
    $keterangan === '' ||
    !is_array($postedItems)
) {
    redirectWithAlert(
        'error',
        'Data pembayaran belum lengkap.',
        'edit_bayar&bayar_no=' . urlencode($bayarNo)
    );
}

if ($nominalCash < 0 || $nominalTitip < 0) {
    redirectWithAlert(
        'error',
        'Nominal pembayaran tidak boleh negatif.',
        'edit_bayar&bayar_no=' . urlencode($bayarNo)
    );
}

$selectedPosted = [];

foreach ($postedItems as $item) {
    if (!is_array($item)) continue;

    $selected =
        isset($item['selected']) &&
        (string)$item['selected'] === '1';

    if (!$selected) continue;

    $source = strtoupper(trim((string)($item['source'] ?? 'INVOICE')));

    if (!in_array($source,['INVOICE','OPENING'],true)) {
        redirectWithAlert(
            'error',
            'Sumber pembayaran tidak valid.',
            'edit_bayar&bayar_no=' . urlencode($bayarNo)
        );
    }

    if ($source === 'OPENING') {
        $openingId = (int)($item['opening_id'] ?? 0);

        if ($openingId <= 0) {
            redirectWithAlert(
                'error',
                'Saldo awal terpilih tidak memiliki opening_id yang valid.',
                'edit_bayar&bayar_no=' . urlencode($bayarNo)
            );
        }

        $key = 'OPENING|' . $openingId;

        if (isset($selectedPosted[$key])) {
            redirectWithAlert(
                'error',
                'Saldo awal terpilih ganda.',
                'edit_bayar&bayar_no=' . urlencode($bayarNo)
            );
        }

        $payNow = parseNumber($item['pay_now'] ?? 0);

        if ($payNow < 0) {
            redirectWithAlert(
                'error',
                'Nilai Bayar Sekarang saldo awal tidak boleh negatif.',
                'edit_bayar&bayar_no=' . urlencode($bayarNo)
            );
        }

        $selectedPosted[$key] = [
            'source' => 'OPENING',
            'opening_id' => $openingId,
            'invoice_no' => '',
            'shipping_no' => '',
            'pay_now' => $payNow
        ];
        continue;
    }

    $invoiceNo = trim((string)($item['invoice_no'] ?? ''));
    $shippingNo = trim((string)($item['shipping_no'] ?? ''));

    if ($invoiceNo === '' || $shippingNo === '') {
        redirectWithAlert(
            'error',
            'Ada Invoice / Shipping terpilih yang tidak lengkap.',
            'edit_bayar&bayar_no=' . urlencode($bayarNo)
        );
    }

    if (
        isCpMcpDocument($invoiceNo) ||
        isCpMcpDocument($shippingNo)
    ) {
        redirectWithAlert(
            'error',
            'Invoice / Shipping CP-MCP tidak boleh dijadikan transaksi pembayaran.',
            'edit_bayar&bayar_no=' . urlencode($bayarNo)
        );
    }

    $key = $invoiceNo . '|' . $shippingNo;

    if (isset($selectedPosted[$key])) {
        redirectWithAlert(
            'error',
            'Invoice / Shipping terpilih ganda: ' . $key,
            'edit_bayar&bayar_no=' . urlencode($bayarNo)
        );
    }

    $payNow = parseNumber($item['pay_now'] ?? 0);

    if ($payNow < 0) {
        redirectWithAlert(
            'error',
            'Nilai Bayar Sekarang invoice tidak boleh negatif.',
            'edit_bayar&bayar_no=' . urlencode($bayarNo)
        );
    }

    $selectedPosted[$key] = [
        'source' => 'INVOICE',
        'opening_id' => 0,
        'invoice_no' => $invoiceNo,
        'shipping_no' => $shippingNo,
        'pay_now' => $payNow
    ];
}

if (
    !$selectedPosted &&
    $returnId === ''
) {
    redirectWithAlert(
        'error',
        'Minimal pilih 1 piutang (Invoice/Shipping atau Saldo Awal) atau 1 Retur Customer.',
        'edit_bayar&bayar_no=' . urlencode($bayarNo)
    );
}

mysqli_begin_transaction($conn);

try {
    /*
    |--------------------------------------------------------------------------
    | LOCK HEAD
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT *
         FROM head_bayar
         WHERE bayar_no = ?
         LIMIT 1
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($stmt, 's', $bayarNo);
    mysqli_stmt_execute($stmt);
    $oldHead = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$oldHead) {
        throw new Exception('Data pembayaran tidak ditemukan.');
    }

    if ((string)$oldHead['customer_id'] !== $customerIdPosted) {
        throw new Exception(
            'Customer pembayaran tidak boleh diganti saat edit.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AMBIL INVOICE LAMA UNTUK RECALC
    |--------------------------------------------------------------------------
    */
    $affectedInvoices = [];

    $stmt = mysqli_prepare(
        $conn,
        "SELECT DISTINCT invoice_no
         FROM detail_bayar
         WHERE bayar_no = ?
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($stmt, 's', $bayarNo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $invNo = trim((string)$row['invoice_no']);
        if ($invNo !== '') {
            $affectedInvoices[$invNo] = true;
        }
    }
    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK TITIP LAMA
    |--------------------------------------------------------------------------
    */
    rollbackTitipUsage(
        $conn,
        $bayarNo,
        $username
    );

    /*
    |--------------------------------------------------------------------------
    | VALIDASI ITEM BARU
    |--------------------------------------------------------------------------
    */
    $validItems = [];
    $selectedTotal = 0.0;
    $requestedPaymentTotal = 0.0;
    $selectedReturnAmount = 0.0;

    foreach ($selectedPosted as $posted) {
        if (($posted['source'] ?? 'INVOICE') === 'OPENING') {
            $opening = getOpeningForUpdate(
                $conn,
                (int)$posted['opening_id'],
                $customerIdPosted,
                $bayarNo
            );

            $sisa = round((float)$opening['sisa_before_current'],2);

            if ($sisa <= 0.01) {
                throw new Exception(
                    'Saldo awal customer sudah nol / tidak memiliki sisa yang dapat diproses.'
                );
            }

            $payNow = round((float)($posted['pay_now'] ?? 0), 2);

            if ($payNow > $sisa + 0.01) {
                throw new Exception(
                    'Bayar Sekarang untuk Saldo Awal melebihi sisa sebelum transaksi ini. Sisa: Rp ' .
                    number_format($sisa, 2, ',', '.')
                );
            }

            $selectedTotal += $sisa;
            $requestedPaymentTotal += $payNow;

            $validItems[] = [
                'source' => 'OPENING',
                'opening_id' => (int)$opening['opening_id'],
                'opening_sign' => (int)($opening['opening_sign'] ?? 1),
                'invoice_no' => '',
                'invoice_date' => (string)$opening['opening_date'],
                'shipping_no' => '',
                'shipping_amount' => abs((float)$opening['opening_balance']),
                'sisa_before' => $sisa,
                'pay_now' => $payNow
            ];

            continue;
        }

        $shipping = getShippingForUpdate(
            $conn,
            $posted['invoice_no'],
            $posted['shipping_no'],
            $bayarNo
        );

        if ((string)$shipping['customer_id'] !== $customerIdPosted) {
            throw new Exception(
                'Semua Invoice / Shipping harus milik customer yang sama.'
            );
        }

        $sisa = round((float)$shipping['sisa_before_current'],2);

        if ($sisa <= 0.01) {
            throw new Exception(
                'Shipping ' . $posted['shipping_no'] .
                ' sudah lunas / tidak memiliki sisa piutang dan tidak dapat diproses kembali.'
            );
        }

        $payNow = round((float)($posted['pay_now'] ?? 0), 2);

        if ($payNow > $sisa + 0.01) {
            throw new Exception(
                'Bayar Sekarang untuk Shipping ' . $posted['shipping_no'] .
                ' melebihi sisa sebelum transaksi ini. Sisa: Rp ' .
                number_format($sisa, 2, ',', '.')
            );
        }

        $selectedTotal += $sisa;
        $requestedPaymentTotal += $payNow;

        $invoiceDateValue =
            !empty($shipping['invoice_date']) &&
            $shipping['invoice_date'] !== '0000-00-00'
                ? (string)$shipping['invoice_date']
                : null;

        $validItems[] = [
            'source' => 'INVOICE',
            'opening_id' => null,
            'opening_sign' => 1,
            'invoice_no' => (string)$shipping['invoice_no'],
            'invoice_date' => $invoiceDateValue,
            'shipping_no' => (string)$shipping['shipping_no'],
            'shipping_amount' => (float)$shipping['shipping_amount'],
            'sisa_before' => $sisa,
            'pay_now' => $payNow
        ];

        $affectedInvoices[(string)$shipping['invoice_no']] = true;
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI ARAH TRANSAKSI OPENING
    |--------------------------------------------------------------------------
    */
    $hasNegativeOpening = false;
    $hasPositiveDebtItem = false;

    foreach ($validItems as $validItem) {
        if (
            ($validItem['source'] ?? 'INVOICE') === 'OPENING' &&
            (int)($validItem['opening_sign'] ?? 1) < 0
        ) {
            $hasNegativeOpening = true;
        } else {
            $hasPositiveDebtItem = true;
        }
    }

    if ($hasNegativeOpening && $hasPositiveDebtItem) {
        throw new Exception(
            'Saldo Awal (-) / Kredit harus diproses tersendiri dan tidak boleh digabung dengan Invoice atau Saldo Awal (+).'
        );
    }

    if ($hasNegativeOpening && $returnId !== '') {
        throw new Exception(
            'Saldo Awal (-) / Kredit tidak boleh digabung dengan Retur Customer.'
        );
    }

    if ($hasNegativeOpening && $nominalTitip > 0.0001) {
        throw new Exception(
            'Saldo Awal (-) / Kredit tidak boleh diselesaikan menggunakan Titip Uang.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | VALIDASI RETUR STANDALONE BERDASARKAN CUSTOMER
    |--------------------------------------------------------------------------
    */
    if ($returnId !== '') {
        $selectedReturnAmount = validateReturnByCustomer(
            $conn,
            $returnId,
            $customerIdPosted,
            $bayarNo
        );
    }

    $selectedTotal = round($selectedTotal, 2);
    $selectedReturnAmount = round($selectedReturnAmount, 2);

    /*
     * Retur mengurangi total yang harus dibayar.
     * Minimum target pembayaran adalah Rp 0.
     */
    $netPayable = max(
        $selectedTotal - $selectedReturnAmount,
        0
    );

    $netPayable = round($netPayable, 2);
    $totalBayar = round($totalBayar, 2);
    $requestedPaymentTotal = round($requestedPaymentTotal, 2);

    /*
     * Cash/Transfer + Titip harus sama persis dengan total Bayar Sekarang
     * yang diinput per item. Update tidak lagi mengalokasikan nominal
     * berdasarkan urutan item.
     */
    if (abs($totalBayar - $requestedPaymentTotal) > 0.01) {
        throw new Exception(
            'Total Cash/Transfer + Titip harus sama dengan total Bayar Sekarang per item. ' .
            'Bayar Sekarang: Rp ' . number_format($requestedPaymentTotal, 2, ',', '.') .
            ' | Cash/Titip: Rp ' . number_format($totalBayar, 2, ',', '.')
        );
    }

    if ($requestedPaymentTotal > $netPayable + 0.01) {
        throw new Exception(
            'Total Bayar Sekarang tidak boleh melebihi total tagihan setelah dikurangi Retur. ' .
            'Total Tagihan: Rp ' . number_format($selectedTotal, 2, ',', '.') .
            ' | Retur: Rp ' . number_format($selectedReturnAmount, 2, ',', '.') .
            ' | Maksimal Bayar: Rp ' . number_format($netPayable, 2, ',', '.')
        );
    }

    if (
        $requestedPaymentTotal <= 0.0001 &&
        $netPayable > 0.0001 &&
        $returnId === ''
    ) {
        throw new Exception('Isi Bayar Sekarang minimal pada satu item.');
    }

    if ($netPayable <= 0.0001 && $returnId === '') {
        throw new Exception(
            'Pembayaran Rp 0,00 hanya diperbolehkan jika No. Retur dipilih.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | CEK SALDO TITIP SETELAH ROLLBACK LAMA
    |--------------------------------------------------------------------------
    */
    if ($nominalTitip > 0.0001) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT COALESCE(SUM(balance_amount),0) AS saldo
             FROM head_titip
             WHERE customer_id = ?
               AND balance_amount > 0
             FOR UPDATE"
        );
        mysqli_stmt_bind_param($stmt, 's', $customerIdPosted);
        mysqli_stmt_execute($stmt);
        $saldoRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        $saldo = (float)($saldoRow['saldo'] ?? 0);

        if ($nominalTitip > $saldo + 0.0001) {
            throw new Exception(
                'Nominal titip melebihi saldo titip uang customer.'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE DETAIL LAMA
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM detail_bayar
         WHERE bayar_no = ?"
    );
    mysqli_stmt_bind_param($stmt, 's', $bayarNo);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | UPDATE HEAD BAYAR
    |--------------------------------------------------------------------------
    */
    $customerName = (string)$oldHead['customer_name'];
    $customerAddress = (string)$oldHead['customer_address'];
    $customerCity = (string)$oldHead['customer_city'];

    if (
        $validItems &&
        ($validItems[0]['source'] ?? 'INVOICE') === 'INVOICE' &&
        trim((string)$validItems[0]['invoice_no']) !== ''
    ) {
        $firstInvoice = $validItems[0]['invoice_no'];

        $stmt = mysqli_prepare(
            $conn,
            "SELECT
                customer_name,
                customer_address,
                customer_city
             FROM head_invoice
             WHERE invoice_no = ?
             LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 's', $firstInvoice);
        mysqli_stmt_execute($stmt);
        $custRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);

        if ($custRow) {
            $customerName = (string)$custRow['customer_name'];
            $customerAddress = (string)$custRow['customer_address'];
            $customerCity = (string)$custRow['customer_city'];
        }
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE head_bayar
         SET
            bayar_date = ?,
            customer_id = ?,
            customer_name = ?,
            customer_address = ?,
            customer_city = ?,
            total_bayar = ?,
            keterangan = ?,
            bank_name = ?,
            remarks = ?,
            user_modified = ?,
            date_modified = NOW()
         WHERE bayar_no = ?"
    );
    mysqli_stmt_bind_param(
        $stmt,
        'sssssdsssss',
        $bayarDate,
        $customerIdPosted,
        $customerName,
        $customerAddress,
        $customerCity,
        $totalBayar,
        $keterangan,
        $bankName,
        $remarks,
        $username,
        $bayarNo
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | INSERT DETAIL BARU DENGAN BAYAR_NO YANG SAMA
    |--------------------------------------------------------------------------
    */
    $remainingTitip = $nominalTitip;
    $remainingCash = $nominalCash;

    /*
     * return_id standalone disimpan hanya sekali pada detail pertama.
     */
    $returnStored = false;

    $stmtDetail = mysqli_prepare(
        $conn,
        "INSERT INTO detail_bayar
        (
            bayar_no,
            invoice_no,
            shipping_no,
            opening_id,
            payment_source,
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
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    foreach ($validItems as $item) {
        $sisaBefore = round(
            (float)$item['sisa_before'],
            2
        );

        /*
         * Nominal pembayaran detail berasal langsung dari kolom Bayar Sekarang.
         * Tidak lagi bergantung pada urutan invoice / saldo awal.
         */
        $detailAmount = round((float)($item['pay_now'] ?? 0), 2);

        if ($detailAmount < -0.01 || $detailAmount > $sisaBefore + 0.01) {
            throw new Exception('Nilai Bayar Sekarang per item tidak valid.');
        }

        $detailTitip = min(
            $remainingTitip,
            $detailAmount
        );

        $detailCash =
            $detailAmount -
            $detailTitip;

        if ($detailCash > $remainingCash + 0.01) {
            throw new Exception(
                'Alokasi Cash / Transfer tidak mencukupi.'
            );
        }

        $remainingTitip -= $detailTitip;
        $remainingCash -= $detailCash;

        if (abs($remainingTitip) < 0.0001) $remainingTitip = 0;
        if (abs($remainingCash) < 0.0001) $remainingCash = 0;

        $detailReturnId = '';
        $detailReturnApplied = 0.0;

        if (!$returnStored && $returnId !== '') {
            $detailReturnId = $returnId;

            /*
             * return_id disimpan satu kali pada detail pertama.
             * sisa_after mengikuti sisa efektif.
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

        $detailOpeningId =
            ($item['source'] ?? 'INVOICE') === 'OPENING'
                ? (int)$item['opening_id']
                : null;

        $detailPaymentSource =
            ($item['source'] ?? 'INVOICE') === 'OPENING'
                ? 'OPENING'
                : 'INVOICE';

        mysqli_stmt_bind_param(
            $stmtDetail,
            'sssisssdddddss',
            $bayarNo,
            $item['invoice_no'],
            $item['shipping_no'],
            $detailOpeningId,
            $detailPaymentSource,
            $detailReturnId,
            $item['invoice_date'],
            $item['shipping_amount'],
            $detailCash,
            $detailTitip,
            $detailAmount,
            $sisaAfter,
            $remarks,
            $username
        );

        mysqli_stmt_execute($stmtDetail);
    }

    /*
     * RETURN-ONLY STANDALONE:
     * tidak ada invoice/shipping, hanya return_id.
     * Dokumen CP-MCP asal retur tidak disimpan sebagai referensi pembayaran.
     */
    if (
        empty($validItems) &&
        $returnId !== ''
    ) {
        $blankInvoiceNo = '';
        $blankShippingNo = '';
        $blankInvoiceDate = null;
        $zeroAmount = 0.0;

        $returnOnlyRemarks =
            $remarks !== ''
                ? $remarks
                : 'Retur Customer Standalone';

        $blankOpeningId = null;
        $returnPaymentSource = 'RETURN';

        mysqli_stmt_bind_param(
            $stmtDetail,
            'sssisssdddddss',
            $bayarNo,
            $blankInvoiceNo,
            $blankShippingNo,
            $blankOpeningId,
            $returnPaymentSource,
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

        mysqli_stmt_execute($stmtDetail);
        $returnStored = true;
    }

    mysqli_stmt_close($stmtDetail);

    if (
        abs($remainingTitip) > 0.01 ||
        abs($remainingCash) > 0.01
    ) {
        throw new Exception(
            'Alokasi pembayaran tidak seimbang.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | APPLY TITIP BARU
    |--------------------------------------------------------------------------
    */
    applyTitipUsage(
        $conn,
        $customerIdPosted,
        $customerName,
        $bayarNo,
        $bayarDate,
        $nominalTitip,
        $username
    );

    /*
    |--------------------------------------------------------------------------
    | RECALC SEMUA INVOICE LAMA + BARU
    |--------------------------------------------------------------------------
    */
    foreach (array_keys($affectedInvoices) as $invoiceNo) {
        updateInvoiceBalance(
            $conn,
            $invoiceNo,
            $username
        );
    }

    mysqli_commit($conn);

    redirectWithAlert(
        'success',
        'Pembayaran ' . $bayarNo .
        ' berhasil diupdate untuk ' .
        count($validItems) .
        ' item piutang. ' .
        'Total Tagihan Rp ' .
        number_format($selectedTotal, 2, ',', '.') .
        ($returnId !== ''
            ? ' | Retur ' . $returnId .
              ' Rp ' .
              number_format($selectedReturnAmount, 2, ',', '.')
            : '') .
        ' | Total Bayar Rp ' .
        number_format($totalBayar, 2, ',', '.') .
        ' | Sisa Setelah Pembayaran Rp ' .
        number_format(max($netPayable - $requestedPaymentTotal, 0), 2, ',', '.') .
        '.',
        'pembayaran'
    );

} catch (Throwable $e) {
    mysqli_rollback($conn);

    redirectWithAlert(
        'error',
        $e->getMessage(),
        'edit_bayar&bayar_no=' . urlencode($bayarNo)
    );
}