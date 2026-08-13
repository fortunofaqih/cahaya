<?php
// modul/transaksi/update_bayar.php
// Update Pembayaran Multi Invoice / Multi Shipping.
//
// Strategi aman:
// 1. Ambil seluruh detail lama.
// 2. Rollback titip lama.
// 3. Validasi seluruh Invoice/Shipping baru dengan mengabaikan bayar_no ini.
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
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
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
                    WHEN COALESCE(di.total,0) > 0 THEN COALESCE(di.total,0)
                    ELSE COALESCE(di.subtotal,0)
                END
            ) AS shipping_amount
         FROM head_invoice hi
         INNER JOIN det_invoice di
            ON di.invoice_no = hi.invoice_no
         WHERE hi.invoice_no = ?
           AND TRIM(di.shipping_no) = ?
         GROUP BY
            hi.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            TRIM(di.shipping_no)
         LIMIT 1
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($stmt, 'ss', $invoiceNo, $shippingNo);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$row) {
        throw new Exception(
            'Invoice / Shipping tidak ditemukan: ' .
            $invoiceNo . ' / ' . $shippingNo
        );
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT COALESCE(SUM(bayar_amount),0) AS paid
         FROM detail_bayar
         WHERE invoice_no = ?
           AND shipping_no = ?
           AND bayar_no <> ?
         FOR UPDATE"
    );
    mysqli_stmt_bind_param(
        $stmt,
        'sss',
        $invoiceNo,
        $shippingNo,
        $excludeBayarNo
    );
    mysqli_stmt_execute($stmt);
    $paidRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $row['paid_except_current'] = (float)($paidRow['paid'] ?? 0);
    $row['sisa_before_current'] = max(
        (float)$row['shipping_amount'] -
        $row['paid_except_current'],
        0
    );

    return $row;
}

function validateReturnByCustomer(
    mysqli $conn,
    string $returnId,
    string $customerId
): float {
    if ($returnId === '') {
        return 0.0;
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT return_amount
         FROM head_retur_invoice
         WHERE return_id = ?
           AND customer_id = ?
           AND LOWER(COALESCE(status,'Open')) <> 'cancelled'
         LIMIT 1
         FOR UPDATE"
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
            ' tidak ditemukan atau bukan milik customer pembayaran.'
        );
    }

    return (float)($row['return_amount'] ?? 0);
}

function updateInvoiceBalance(
    mysqli $conn,
    string $invoiceNo,
    string $username
): void {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            COALESCE((
                SELECT SUM(
                    CASE
                        WHEN COALESCE(di.total,0) > 0 THEN COALESCE(di.total,0)
                        ELSE COALESCE(di.subtotal,0)
                    END
                )
                FROM det_invoice di
                WHERE di.invoice_no = ?
            ),0) AS invoice_amount,

            COALESCE((
                SELECT SUM(db.bayar_amount)
                FROM detail_bayar db
                WHERE db.invoice_no = ?
            ),0) AS paid_amount,

            COALESCE((
                SELECT SUM(hri.return_amount)
                FROM head_retur_invoice hri
                WHERE hri.invoice_no = ?
                  AND LOWER(COALESCE(hri.status,'Open')) <> 'cancelled'
            ),0) AS return_amount"
    );

    mysqli_stmt_bind_param(
        $stmt,
        'sss',
        $invoiceNo,
        $invoiceNo,
        $invoiceNo
    );
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    $invoiceAmount = (float)($row['invoice_amount'] ?? 0);
    $paid = (float)($row['paid_amount'] ?? 0);
    $retur = (float)($row['return_amount'] ?? 0);

    $balance = max($invoiceAmount - $retur - $paid, 0);

    if ($balance <= 0.0001) {
        $status = 'Paid';
    } elseif ($paid > 0.0001 || $retur > 0.0001) {
        $status = 'Partial';
    } else {
        $status = 'Open';
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE head_invoice
         SET
            payment_balance = ?,
            status = ?,
            user_modified = ?,
            date_modified = NOW()
         WHERE invoice_no = ?"
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

    $invoiceNo = trim((string)($item['invoice_no'] ?? ''));
    $shippingNo = trim((string)($item['shipping_no'] ?? ''));
    
    if ($invoiceNo === '' || $shippingNo === '') {
        redirectWithAlert(
            'error',
            'Ada Invoice / Shipping terpilih yang tidak lengkap.',
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

    $selectedPosted[$key] = [
        'invoice_no' => $invoiceNo,
        'shipping_no' => $shippingNo
    ];
}

if (!$selectedPosted) {
    redirectWithAlert(
        'error',
        'Minimal pilih 1 Invoice / Shipping.',
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
    $selectedReturnAmount = 0.0;

    foreach ($selectedPosted as $posted) {
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

        $sisa = round(
            (float)$shipping['sisa_before_current'],
            2
        );

        if ($sisa <= 0.0001 && $returnId === '') {
            throw new Exception(
                'Shipping ' . $posted['shipping_no'] .
                ' sudah lunas. Untuk transaksi Rp 0,00 wajib pilih No. Retur.'
            );
        }

        $selectedTotal += $sisa;

        $validItems[] = [
            'invoice_no' => (string)$shipping['invoice_no'],
            'invoice_date' => (string)$shipping['invoice_date'],
            'shipping_no' => (string)$shipping['shipping_no'],
            'shipping_amount' => (float)$shipping['shipping_amount'],
            'sisa_before' => $sisa
        ];

        $affectedInvoices[
            (string)$shipping['invoice_no']
        ] = true;
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
            $customerIdPosted
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

    if (abs($totalBayar - $netPayable) > 0.01) {
        throw new Exception(
            'Total bayar harus sama dengan total tagihan setelah dikurangi Retur. ' .
            'Total Tagihan: Rp ' .
            number_format($selectedTotal, 2, ',', '.') .
            ' | Retur: Rp ' .
            number_format($selectedReturnAmount, 2, ',', '.') .
            ' | Target Bayar: Rp ' .
            number_format($netPayable, 2, ',', '.')
        );
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

    if ($validItems) {
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
    $remainingPaymentToAllocate = $netPayable;

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
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    foreach ($validItems as $item) {
        $sisaBefore = round(
            (float)$item['sisa_before'],
            2
        );

        /*
         * Pembayaran dialokasikan berurutan hanya sampai net payable.
         * Retur standalone mengurangi total pembayaran customer.
         */
        $detailAmount = min(
            $sisaBefore,
            $remainingPaymentToAllocate
        );

        $detailAmount = round(
            $detailAmount,
            2
        );

        $remainingPaymentToAllocate -= $detailAmount;

        if (abs($remainingPaymentToAllocate) < 0.0001) {
            $remainingPaymentToAllocate = 0;
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

        $sisaAfter = max(
            $sisaBefore - $detailAmount,
            0
        );

        $detailReturnId = '';

        if (!$returnStored && $returnId !== '') {
            $detailReturnId = $returnId;
            $returnStored = true;
        }

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
            $remarks,
            $username
        );

        mysqli_stmt_execute($stmtDetail);
    }

    mysqli_stmt_close($stmtDetail);

    if (
        abs($remainingTitip) > 0.01 ||
        abs($remainingCash) > 0.01 ||
        abs($remainingPaymentToAllocate) > 0.01
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
        ' Invoice / Shipping. ' .
        'Total Tagihan Rp ' .
        number_format($selectedTotal, 2, ',', '.') .
        ($returnId !== ''
            ? ' | Retur ' . $returnId .
              ' Rp ' .
              number_format($selectedReturnAmount, 2, ',', '.')
            : '') .
        ' | Total Bayar Rp ' .
        number_format($totalBayar, 2, ',', '.') .
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