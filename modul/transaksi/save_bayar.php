<?php
// modul/transaksi/save_bayar_shipping_revisi.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function redirectWithAlert($type, $message, $page = 'pembayaran') {
    $_SESSION['alert'] = "
        <div style='padding:10px;margin-bottom:10px;border-radius:4px;background:" . ($type === 'success' ? '#d1e7dd' : '#f8d7da') . ";color:" . ($type === 'success' ? '#0f5132' : '#842029') . ";border:1px solid " . ($type === 'success' ? '#badbcc' : '#f5c2c7') . ";'>
            " . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "
        </div>
    ";

    header("Location: ../../index.php?page=" . urlencode($page));
    exit;
}

function parseDateInput($value) {
    $value = trim((string)$value);
    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();

        if (
            $date instanceof DateTime &&
            ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
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

    return is_numeric($value) ? (float)$value : 0.0;
}

function generateBayarNo($conn) {
    $prefix = 'B-';
    $sql = "
        SELECT bayar_no
        FROM head_bayar
        WHERE bayar_no LIKE 'B-%'
        ORDER BY CAST(SUBSTRING(bayar_no, 3) AS UNSIGNED) DESC
        LIMIT 1
        FOR UPDATE
    ";

    $result = mysqli_query($conn, $sql);
    $lastNumber = 0;

    if ($result && $row = mysqli_fetch_assoc($result)) {
        $lastNumber = (int)substr($row['bayar_no'], 2);
    }

    return $prefix . str_pad($lastNumber + 1, 9, '0', STR_PAD_LEFT);
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
    if ($amount <= 0) {
        return;
    }

    $remaining = $amount;

    $sqlTitip = "
        SELECT titip_no, balance_amount
        FROM head_titip
        WHERE customer_id = ?
          AND balance_amount > 0
        ORDER BY titip_date ASC, titip_no ASC
        FOR UPDATE
    ";

    $stmtTitip = mysqli_prepare($conn, $sqlTitip);
    mysqli_stmt_bind_param($stmtTitip, 's', $customerId);
    mysqli_stmt_execute($stmtTitip);
    $resultTitip = mysqli_stmt_get_result($stmtTitip);

    while ($rowTitip = mysqli_fetch_assoc($resultTitip)) {
        if ($remaining <= 0.0001) {
            break;
        }

        $titipNo = $rowTitip['titip_no'];
        $balanceBefore = (float)$rowTitip['balance_amount'];
        $usedNow = min($balanceBefore, $remaining);
        $balanceAfter = $balanceBefore - $usedNow;
        $status = $balanceAfter <= 0.0001 ? 'Closed' : 'Open';

        $sqlUpdateTitip = "
            UPDATE head_titip
            SET
                used_amount = COALESCE(used_amount, 0) + ?,
                balance_amount = balance_amount - ?,
                status = ?,
                user_modified = ?,
                date_modified = NOW()
            WHERE titip_no = ?
        ";

        $stmtUpdateTitip = mysqli_prepare($conn, $sqlUpdateTitip);
        mysqli_stmt_bind_param(
            $stmtUpdateTitip,
            'ddsss',
            $usedNow,
            $usedNow,
            $status,
            $username,
            $titipNo
        );
        mysqli_stmt_execute($stmtUpdateTitip);
        mysqli_stmt_close($stmtUpdateTitip);

        $remarksTitip = 'Dipakai untuk pembayaran ' . $bayarNo;

        $sqlDetailTitip = "
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
            VALUES (?, ?, ?, ?, 'PAKAI', ?, 0, ?, ?, 'PEMBAYARAN', '', ?, ?, NOW())
        ";

        $stmtDetailTitip = mysqli_prepare($conn, $sqlDetailTitip);
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
        mysqli_stmt_execute($stmtDetailTitip);
        mysqli_stmt_close($stmtDetailTitip);

        $remaining -= $usedNow;
    }

    mysqli_stmt_close($stmtTitip);

    if ($remaining > 0.0001) {
        throw new Exception('Saldo titip uang tidak mencukupi.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithAlert('error', 'Invalid request.');
}

$shippingNo = trim((string)($_POST['shipping_no'] ?? ''));
$postedInvoiceNo = trim((string)($_POST['invoice_no'] ?? ''));
$bayarDate = parseDateInput($_POST['bayar_date'] ?? '');
$nominalBayar = parseNumber($_POST['nominal_bayar'] ?? 0);
$pakaiTitip = isset($_POST['pakai_titip']) && (string)$_POST['pakai_titip'] === '1';
$nominalTitip = $pakaiTitip ? parseNumber($_POST['nominal_titip'] ?? 0) : 0;
$totalBayarShipping = $nominalBayar + $nominalTitip;
$keterangan = trim((string)($_POST['keterangan'] ?? ''));
$bankName = trim((string)($_POST['bank_name'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$username = $_SESSION['username'] ?? 'system';

if (
    $shippingNo === '' ||
    $postedInvoiceNo === '' ||
    !$bayarDate ||
    $totalBayarShipping <= 0 ||
    $keterangan === ''
) {
    redirectWithAlert('error', 'Data pembayaran belum lengkap.', 'add_bayar');
}

if ($nominalBayar < 0 || $nominalTitip < 0) {
    redirectWithAlert('error', 'Nominal pembayaran tidak boleh negatif.', 'add_bayar');
}

mysqli_begin_transaction($conn);

try {
    $bayarNo = generateBayarNo($conn);

    /*
     * Ambil data shipping dari relasi head_invoice dan det_invoice.
     * invoice_no dari form hanya dipakai untuk memvalidasi pasangan invoice-shipping.
     */
    $sqlShipping = "
        SELECT
            hi.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            di.shipping_no,
            di.shipping_date,
            di.order_no,
            CASE
                WHEN COALESCE(di.total, 0) > 0 THEN COALESCE(di.total, 0)
                ELSE COALESCE(di.subtotal, 0)
            END AS shipping_amount
        FROM head_invoice hi
        INNER JOIN det_invoice di
            ON di.invoice_no = hi.invoice_no
        WHERE hi.invoice_no = ?
          AND di.shipping_no = ?
        LIMIT 1
        FOR UPDATE
    ";

    $stmtShipping = mysqli_prepare($conn, $sqlShipping);
    mysqli_stmt_bind_param($stmtShipping, 'ss', $postedInvoiceNo, $shippingNo);
    mysqli_stmt_execute($stmtShipping);
    $resultShipping = mysqli_stmt_get_result($stmtShipping);
    $shipping = mysqli_fetch_assoc($resultShipping);
    mysqli_stmt_close($stmtShipping);

    if (!$shipping) {
        throw new Exception('No. Shipping tidak ditemukan atau tidak sesuai dengan invoice.');
    }

    $invoiceNo = $shipping['invoice_no'];
    $shippingAmount = (float)$shipping['shipping_amount'];

    if ($shippingAmount <= 0) {
        throw new Exception('Nilai tagihan Shipping No. ' . $shippingNo . ' tidak valid atau bernilai nol.');
    }

    /* Total pembayaran sebelumnya khusus shipping yang dipilih. */
    $sqlPaidShipping = "
        SELECT COALESCE(SUM(bayar_amount), 0) AS paid_amount
        FROM detail_bayar
        WHERE invoice_no = ?
          AND shipping_no = ?
        FOR UPDATE
    ";

    $stmtPaidShipping = mysqli_prepare($conn, $sqlPaidShipping);
    mysqli_stmt_bind_param($stmtPaidShipping, 'ss', $invoiceNo, $shippingNo);
    mysqli_stmt_execute($stmtPaidShipping);
    $resultPaidShipping = mysqli_stmt_get_result($stmtPaidShipping);
    $rowPaidShipping = mysqli_fetch_assoc($resultPaidShipping);
    mysqli_stmt_close($stmtPaidShipping);

    $paidShipping = (float)($rowPaidShipping['paid_amount'] ?? 0);
    $sisaShipping = $shippingAmount - $paidShipping;

    if ($sisaShipping <= 0.0001) {
        throw new Exception('No. Shipping ' . $shippingNo . ' sudah lunas.');
    }

    if ($totalBayarShipping > ($sisaShipping + 0.0001)) {
        throw new Exception('Total bayar melebihi sisa tagihan Shipping No. ' . $shippingNo . '.');
    }

    $customerId = $shipping['customer_id'];
    $customerName = $shipping['customer_name'];
    $customerAddress = $shipping['customer_address'];
    $customerCity = $shipping['customer_city'];

    /* Cek saldo titip berdasarkan customer yang benar dari database. */
    if ($nominalTitip > 0) {
        $sqlSaldoTitip = "
            SELECT COALESCE(SUM(balance_amount), 0) AS saldo_titip
            FROM head_titip
            WHERE customer_id = ?
              AND balance_amount > 0
            FOR UPDATE
        ";

        $stmtSaldoTitip = mysqli_prepare($conn, $sqlSaldoTitip);
        mysqli_stmt_bind_param($stmtSaldoTitip, 's', $customerId);
        mysqli_stmt_execute($stmtSaldoTitip);
        $resultSaldoTitip = mysqli_stmt_get_result($stmtSaldoTitip);
        $rowSaldoTitip = mysqli_fetch_assoc($resultSaldoTitip);
        mysqli_stmt_close($stmtSaldoTitip);

        $saldoTitip = (float)($rowSaldoTitip['saldo_titip'] ?? 0);

        if ($nominalTitip > ($saldoTitip + 0.0001)) {
            throw new Exception('Nominal titip yang dipakai melebihi saldo titip uang customer.');
        }
    }

    $sisaShippingAfter = max($sisaShipping - $totalBayarShipping, 0);

    $sqlHead = "
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
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";

    $stmtHead = mysqli_prepare($conn, $sqlHead);
    mysqli_stmt_bind_param(
        $stmtHead,
        'ssssssdssss',
        $bayarNo,
        $bayarDate,
        $customerId,
        $customerName,
        $customerAddress,
        $customerCity,
        $totalBayarShipping,
        $keterangan,
        $bankName,
        $remarks,
        $username
    );
    mysqli_stmt_execute($stmtHead);
    mysqli_stmt_close($stmtHead);

    $sqlDetail = "
        INSERT INTO detail_bayar
        (
            bayar_no,
            invoice_no,
            shipping_no,
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
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ";

    $stmtDetail = mysqli_prepare($conn, $sqlDetail);
    mysqli_stmt_bind_param(
        $stmtDetail,
        'ssssdddddss',
        $bayarNo,
        $invoiceNo,
        $shippingNo,
        $shipping['invoice_date'],
        $shippingAmount,
        $nominalBayar,
        $nominalTitip,
        $totalBayarShipping,
        $sisaShippingAfter,
        $remarks,
        $username
    );
    mysqli_stmt_execute($stmtDetail);
    mysqli_stmt_close($stmtDetail);

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
     * Hitung ulang saldo invoice dari seluruh shipping di invoice tersebut.
     * Ini mencegah satu shipping yang lunas langsung membuat seluruh invoice Paid.
     */
    $sqlInvoiceBalance = "
        SELECT
            COALESCE((
                SELECT SUM(
                    CASE
                        WHEN COALESCE(di.total, 0) > 0 THEN COALESCE(di.total, 0)
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
            ), 0) AS paid_amount
    ";

    $stmtInvoiceBalance = mysqli_prepare($conn, $sqlInvoiceBalance);
    mysqli_stmt_bind_param($stmtInvoiceBalance, 'ss', $invoiceNo, $invoiceNo);
    mysqli_stmt_execute($stmtInvoiceBalance);
    $resultInvoiceBalance = mysqli_stmt_get_result($stmtInvoiceBalance);
    $rowInvoiceBalance = mysqli_fetch_assoc($resultInvoiceBalance);
    mysqli_stmt_close($stmtInvoiceBalance);

    $invoiceAmount = (float)($rowInvoiceBalance['invoice_amount'] ?? 0);
    $invoicePaid = (float)($rowInvoiceBalance['paid_amount'] ?? 0);
    $invoiceBalance = max($invoiceAmount - $invoicePaid, 0);

    $newStatus = $invoiceBalance <= 0.0001 ? 'Paid' : 'Partial';

    $sqlUpdateInvoice = "
        UPDATE head_invoice
        SET
            payment_balance = ?,
            status = ?,
            user_modified = ?,
            date_modified = NOW()
        WHERE invoice_no = ?
    ";

    $stmtUpdateInvoice = mysqli_prepare($conn, $sqlUpdateInvoice);
    mysqli_stmt_bind_param(
        $stmtUpdateInvoice,
        'dsss',
        $invoiceBalance,
        $newStatus,
        $username,
        $invoiceNo
    );
    mysqli_stmt_execute($stmtUpdateInvoice);
    mysqli_stmt_close($stmtUpdateInvoice);

    mysqli_commit($conn);

    redirectWithAlert(
        'success',
        'Pembayaran Shipping No. ' . $shippingNo . ' berhasil disimpan dengan No. Bayar ' . $bayarNo . '.',
        'pembayaran'
    );
} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirectWithAlert('error', $e->getMessage(), 'add_bayar');
}