<?php
// modul/transaksi/delete_bayar.php
// REVISI RETUR:
// 1. rollback penggunaan titip jika pembayaran dihapus.
// 2. hapus detail_bayar lalu head_bayar.
// 3. saldo invoice dihitung ulang:
//    total invoice - retur aktif - pembayaran tersisa.
// 4. retur Cancelled tidak dihitung.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function redirectWithAlert($type, $message) {
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

    header("Location: ../../index.php?page=pembayaran");
    exit;
}

function rollbackTitipUsage($conn, $bayar_no, $username) {
    $sqlOldUsage = "
        SELECT titip_no, amount_out
        FROM detail_titip
        WHERE transaction_type = 'PAKAI'
          AND ref_no = ?
        FOR UPDATE
    ";

    $stmtOldUsage = mysqli_prepare($conn, $sqlOldUsage);

    if (!$stmtOldUsage) {
        throw new Exception(
            'Gagal prepare rollback titip: ' . mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmtOldUsage, 's', $bayar_no);
    mysqli_stmt_execute($stmtOldUsage);
    $resOldUsage = mysqli_stmt_get_result($stmtOldUsage);

    while ($row = mysqli_fetch_assoc($resOldUsage)) {
        $titip_no = $row['titip_no'];
        $amount_out = (float)$row['amount_out'];

        $sqlReturn = "
            UPDATE head_titip
            SET
                used_amount = GREATEST(COALESCE(used_amount, 0) - ?, 0),
                balance_amount = COALESCE(balance_amount, 0) + ?,
                status = 'Open',
                user_modified = ?,
                date_modified = NOW()
            WHERE titip_no = ?
        ";

        $stmtReturn = mysqli_prepare($conn, $sqlReturn);

        mysqli_stmt_bind_param(
            $stmtReturn,
            'ddss',
            $amount_out,
            $amount_out,
            $username,
            $titip_no
        );

        mysqli_stmt_execute($stmtReturn);
        mysqli_stmt_close($stmtReturn);
    }

    mysqli_stmt_close($stmtOldUsage);

    $sqlDeleteUsage = "
        DELETE FROM detail_titip
        WHERE transaction_type = 'PAKAI'
          AND ref_no = ?
    ";

    $stmtDeleteUsage = mysqli_prepare($conn, $sqlDeleteUsage);
    mysqli_stmt_bind_param($stmtDeleteUsage, 's', $bayar_no);
    mysqli_stmt_execute($stmtDeleteUsage);
    mysqli_stmt_close($stmtDeleteUsage);
}

$bayar_no = trim((string)($_GET['bayar_no'] ?? ''));
$username = $_SESSION['username'] ?? 'system';

if ($bayar_no === '') {
    redirectWithAlert('error', 'No. Bayar tidak ditemukan.');
}

mysqli_begin_transaction($conn);

try {
    /*
     * Ambil invoice + shipping sebelum data pembayaran dihapus.
     */
    $sqlOld = "
        SELECT
            invoice_no,
            shipping_no
        FROM detail_bayar
        WHERE bayar_no = ?
        LIMIT 1
        FOR UPDATE
    ";

    $stmtOld = mysqli_prepare($conn, $sqlOld);
    mysqli_stmt_bind_param($stmtOld, 's', $bayar_no);
    mysqli_stmt_execute($stmtOld);
    $resOld = mysqli_stmt_get_result($stmtOld);
    $old = mysqli_fetch_assoc($resOld);
    mysqli_stmt_close($stmtOld);

    if (!$old) {
        throw new Exception('Data pembayaran tidak ditemukan.');
    }

    $invoice_no = trim((string)$old['invoice_no']);
    $shipping_no = trim((string)($old['shipping_no'] ?? ''));

    /*
     * Kembalikan semua titip yang dipakai oleh No. Bayar ini.
     */
    rollbackTitipUsage(
        $conn,
        $bayar_no,
        $username
    );

    /*
     * Hapus detail lebih dulu agar tidak bergantung pada FK cascade.
     */
    $sqlDeleteDetail = "
        DELETE FROM detail_bayar
        WHERE bayar_no = ?
    ";

    $stmtDeleteDetail = mysqli_prepare($conn, $sqlDeleteDetail);
    mysqli_stmt_bind_param($stmtDeleteDetail, 's', $bayar_no);
    mysqli_stmt_execute($stmtDeleteDetail);
    mysqli_stmt_close($stmtDeleteDetail);

    /*
     * Hapus header.
     */
    $sqlDeleteHead = "
        DELETE FROM head_bayar
        WHERE bayar_no = ?
    ";

    $stmtDeleteHead = mysqli_prepare($conn, $sqlDeleteHead);
    mysqli_stmt_bind_param($stmtDeleteHead, 's', $bayar_no);
    mysqli_stmt_execute($stmtDeleteHead);
    mysqli_stmt_close($stmtDeleteHead);

    /*
     * Hitung ulang posisi invoice:
     * total invoice - retur aktif - pembayaran yang masih tersimpan.
     */
    $sqlInvoiceBalance = "
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
                SELECT SUM(hri.return_amount)
                FROM head_retur_invoice hri
                WHERE hri.invoice_no = ?
                  AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
            ), 0) AS return_amount
    ";

    $stmtInvoiceBalance = mysqli_prepare($conn, $sqlInvoiceBalance);

    mysqli_stmt_bind_param(
        $stmtInvoiceBalance,
        'sss',
        $invoice_no,
        $invoice_no,
        $invoice_no
    );

    mysqli_stmt_execute($stmtInvoiceBalance);
    $resInvoiceBalance = mysqli_stmt_get_result($stmtInvoiceBalance);
    $rowInvoiceBalance = mysqli_fetch_assoc($resInvoiceBalance);
    mysqli_stmt_close($stmtInvoiceBalance);

    $invoice_amount =
        (float)($rowInvoiceBalance['invoice_amount'] ?? 0);

    $paid_amount =
        (float)($rowInvoiceBalance['paid_amount'] ?? 0);

    $return_amount =
        (float)($rowInvoiceBalance['return_amount'] ?? 0);

    $payment_balance = max(
        $invoice_amount
        - $return_amount
        - $paid_amount,
        0
    );

    if ($payment_balance <= 0.0001) {
        $status = 'Paid';
    } elseif ($paid_amount > 0.0001 || $return_amount > 0.0001) {
        $status = 'Partial';
    } else {
        $status = 'Open';
    }

    $sqlUpdateInv = "
        UPDATE head_invoice
        SET
            payment_balance = ?,
            status = ?,
            user_modified = ?,
            date_modified = NOW()
        WHERE invoice_no = ?
    ";

    $stmtUpdateInv = mysqli_prepare($conn, $sqlUpdateInv);

    mysqli_stmt_bind_param(
        $stmtUpdateInv,
        'dsss',
        $payment_balance,
        $status,
        $username,
        $invoice_no
    );

    mysqli_stmt_execute($stmtUpdateInv);
    mysqli_stmt_close($stmtUpdateInv);

    mysqli_commit($conn);

    redirectWithAlert(
        'success',
        'Pembayaran ' . $bayar_no .
        ' berhasil dihapus. Saldo invoice telah dihitung ulang setelah retur.'
    );

} catch (Throwable $e) {
    mysqli_rollback($conn);
    redirectWithAlert('error', $e->getMessage());
}