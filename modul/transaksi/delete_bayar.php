<?php
/*
 * RULE NILAI RETUR CP-MCP:
 * - CP-MCP memakai grand_total.
 * - Retur normal memakai return_amount.
 */

// modul/transaksi/delete_bayar.php
// Delete Pembayaran Multi Invoice dengan Retur Standalone.
//
// Rule sisa efektif:
// Nilai Invoice - Total Pembayaran - Retur yang BENAR-BENAR masih digunakan.
//
// Satu bayar_no dapat memiliki banyak detail_bayar.
// return_id standalone hanya tersimpan pada salah satu detail.
// Saat pembayaran dihapus, link return_id ikut terhapus sehingga
// retur otomatis tersedia kembali untuk pembayaran lain.
// Master retur pada head_retur_invoice TIDAK dihapus.

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

function rollbackTitipUsage(
    mysqli $conn,
    string $bayarNo,
    string $username
): void {
    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            titip_no,
            amount_out
        FROM detail_titip
        WHERE transaction_type = 'PAKAI'
          AND ref_no = ?
        FOR UPDATE
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $bayarNo
    );

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $titipNo =
            (string)$row['titip_no'];

        $amountOut =
            (float)$row['amount_out'];

        $stmtBack = mysqli_prepare(
            $conn,
            "
            UPDATE head_titip
            SET
                used_amount =
                    GREATEST(
                        COALESCE(used_amount, 0) - ?,
                        0
                    ),
                balance_amount =
                    COALESCE(balance_amount, 0) + ?,
                status = 'Open',
                user_modified = ?,
                date_modified = NOW()
            WHERE titip_no = ?
            "
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
        "
        DELETE FROM detail_titip
        WHERE transaction_type = 'PAKAI'
          AND ref_no = ?
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $bayarNo
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function updateInvoiceBalance(
    mysqli $conn,
    string $invoiceNo,
    string $username
): void {
    /*
     * Penting:
     * return_amount TIDAK lagi dihitung dari seluruh
     * head_retur_invoice berdasarkan invoice_no.
     *
     * Hanya retur yang return_id-nya masih benar-benar
     * digunakan pada detail_bayar invoice tersebut
     * yang boleh mengurangi piutang.
     */
    $stmt = mysqli_prepare(
        $conn,
        "
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
                        SELECT DISTINCT
                            TRIM(db.return_id) AS return_id
                        FROM detail_bayar db
                        WHERE db.invoice_no = ?
                          AND COALESCE(
                                TRIM(db.return_id),
                                ''
                              ) <> ''
                    ) used
                        ON TRIM(hri.return_id) = used.return_id
                    WHERE LOWER(
                        COALESCE(
                            hri.status,
                            'Open'
                        )
                    ) <> 'cancelled'
                ),
                0
            ) AS return_amount
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

    $invoiceAmount =
        (float)($row['invoice_amount'] ?? 0);

    $paidAmount =
        (float)($row['paid_amount'] ?? 0);

    $returnAmount =
        (float)($row['return_amount'] ?? 0);

    /*
     * SISA EFEKTIF INVOICE
     */
    $paymentBalance = max(
        $invoiceAmount
        - $paidAmount
        - $returnAmount,
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

$username =
    (string)(
        $_SESSION['username']
        ?? 'system'
    );

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
    | LOCK HEAD BAYAR
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT bayar_no
        FROM head_bayar
        WHERE bayar_no = ?
        LIMIT 1
        FOR UPDATE
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $bayarNo
    );

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
    | AMBIL INVOICE + RETUR SEBELUM DETAIL DIHAPUS
    |--------------------------------------------------------------------------
    */
    $invoiceNos = [];
    $linkedReturnIds = [];
    $detailCount = 0;

    $stmt = mysqli_prepare(
        $conn,
        "
        SELECT
            invoice_no,
            return_id
        FROM detail_bayar
        WHERE bayar_no = ?
        ORDER BY id ASC
        FOR UPDATE
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $bayarNo
    );

    mysqli_stmt_execute($stmt);

    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $detailCount++;

        $invoiceNo =
            trim(
                (string)(
                    $row['invoice_no']
                    ?? ''
                )
            );

        if ($invoiceNo !== '') {
            $invoiceNos[$invoiceNo] = true;
        }

        $returnId =
            trim(
                (string)(
                    $row['return_id']
                    ?? ''
                )
            );

        if ($returnId !== '') {
            $linkedReturnIds[$returnId] = true;
        }
    }

    mysqli_stmt_close($stmt);

    if ($detailCount < 1) {
        throw new Exception(
            'Detail pembayaran tidak ditemukan.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | ROLLBACK TITIP UANG
    |--------------------------------------------------------------------------
    */
    rollbackTitipUsage(
        $conn,
        $bayarNo,
        $username
    );

    /*
    |--------------------------------------------------------------------------
    | DELETE DETAIL BAYAR
    |--------------------------------------------------------------------------
    | Saat detail dihapus:
    | - bayar_amount transaksi ini hilang;
    | - return_id transaksi ini juga otomatis terlepas;
    | - master head_retur_invoice tetap ada.
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "
        DELETE FROM detail_bayar
        WHERE bayar_no = ?
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $bayarNo
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | DELETE HEAD BAYAR
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "
        DELETE FROM head_bayar
        WHERE bayar_no = ?
        "
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $bayarNo
    );

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
    | RECALC SELURUH INVOICE TERDAMPAK
    |--------------------------------------------------------------------------
    | Dilakukan SETELAH detail_bayar dihapus supaya retur transaksi
    | yang baru dihapus sudah tidak dianggap sebagai retur terpakai.
    |--------------------------------------------------------------------------
    */
    foreach (
        array_keys($invoiceNos)
        as $invoiceNo
    ) {
        updateInvoiceBalance(
            $conn,
            $invoiceNo,
            $username
        );
    }

    mysqli_commit($conn);

    $message =
        'Pembayaran ' .
        $bayarNo .
        ' berhasil dihapus.';

    if ($invoiceNos) {
        $message .=
            ' ' .
            count($invoiceNos) .
            ' invoice telah dihitung ulang.';
    } else {
        $message .=
            ' Transaksi ini adalah Retur Customer standalone sehingga tidak ada invoice yang perlu dihitung ulang.';
    }

    if ($linkedReturnIds) {
        $message .=
            ' Link Retur ' .
            implode(
                ', ',
                array_keys($linkedReturnIds)
            ) .
            ' telah dilepas dan retur dapat digunakan kembali.';
    }

    redirectWithAlert(
        'success',
        $message
    );

} catch (Throwable $e) {
    mysqli_rollback($conn);

    redirectWithAlert(
        'error',
        $e->getMessage()
    );
}