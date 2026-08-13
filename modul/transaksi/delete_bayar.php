<?php
// modul/transaksi/delete_bayar.php
// Delete Pembayaran Multi Invoice dengan Retur Standalone.
//
// Satu bayar_no dapat memiliki banyak detail_bayar.
// return_id standalone hanya tersimpan pada salah satu detail,
// sehingga delete cukup menghapus seluruh detail pembayaran.
// Retur master pada head_retur_invoice TIDAK dihapus.

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
                        WHEN COALESCE(di.total,0) > 0
                            THEN COALESCE(di.total,0)
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
    $paidAmount = (float)($row['paid_amount'] ?? 0);
    $returnAmount = (float)($row['return_amount'] ?? 0);

    $paymentBalance = max(
        $invoiceAmount -
        $returnAmount -
        $paidAmount,
        0
    );

    if ($paymentBalance <= 0.0001) {
        $status = 'Paid';
    } elseif (
        $paidAmount > 0.0001 ||
        $returnAmount > 0.0001
    ) {
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
        $paymentBalance,
        $status,
        $username,
        $invoiceNo
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$bayarNo = trim(
    (string)(
        $_POST['bayar_no']
        ?? $_GET['bayar_no']
        ?? ''
    )
);

$username = $_SESSION['username'] ?? 'system';

if ($bayarNo === '') {
    redirectWithAlert(
        'error',
        'No. Bayar tidak ditemukan.'
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
        "SELECT bayar_no
         FROM head_bayar
         WHERE bayar_no = ?
         LIMIT 1
         FOR UPDATE"
    );

    mysqli_stmt_bind_param($stmt, 's', $bayarNo);
    mysqli_stmt_execute($stmt);

    $head = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    if (!$head) {
        throw new Exception(
            'Data pembayaran tidak ditemukan.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | AMBIL SEMUA INVOICE + RETUR LINK SEBELUM DETAIL DIHAPUS
    |--------------------------------------------------------------------------
    */
    $invoiceNos = [];
    $linkedReturnId = '';

    $stmt = mysqli_prepare(
        $conn,
        "SELECT invoice_no, return_id
         FROM detail_bayar
         WHERE bayar_no = ?
         ORDER BY id ASC
         FOR UPDATE"
    );

    mysqli_stmt_bind_param($stmt, 's', $bayarNo);
    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $invoiceNo = trim((string)($row['invoice_no'] ?? ''));

        if ($invoiceNo !== '') {
            $invoiceNos[$invoiceNo] = true;
        }

        if (
            $linkedReturnId === '' &&
            trim((string)($row['return_id'] ?? '')) !== ''
        ) {
            $linkedReturnId = trim(
                (string)$row['return_id']
            );
        }
    }

    mysqli_stmt_close($stmt);

    if (!$invoiceNos) {
        throw new Exception(
            'Detail pembayaran tidak ditemukan.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK TITIP
    |--------------------------------------------------------------------------
    */
    rollbackTitipUsage(
        $conn,
        $bayarNo,
        $username
    );

    /*
    |--------------------------------------------------------------------------
    | DELETE DETAIL + HEAD
    |--------------------------------------------------------------------------
    | return_id hanyalah link cross-check. Master Retur TIDAK dihapus.
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

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM head_bayar
         WHERE bayar_no = ?"
    );

    mysqli_stmt_bind_param($stmt, 's', $bayarNo);
    mysqli_stmt_execute($stmt);

    if (mysqli_stmt_affected_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);

        throw new Exception(
            'Head pembayaran gagal dihapus.'
        );
    }

    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | RECALC SELURUH INVOICE
    |--------------------------------------------------------------------------
    */
    foreach (array_keys($invoiceNos) as $invoiceNo) {
        updateInvoiceBalance(
            $conn,
            $invoiceNo,
            $username
        );
    }

    mysqli_commit($conn);

    redirectWithAlert(
        'success',
        'Pembayaran ' .
        $bayarNo .
        ' berhasil dihapus. ' .
        count($invoiceNos) .
        ' invoice telah dihitung ulang.' .
        ($linkedReturnId !== ''
            ? ' Link Retur ' . $linkedReturnId . ' juga dilepas.'
            : '')
    );

} catch (Throwable $e) {
    mysqli_rollback($conn);

    redirectWithAlert(
        'error',
        $e->getMessage()
    );
}