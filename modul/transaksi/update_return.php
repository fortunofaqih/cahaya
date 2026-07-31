<?php
// modul/transaksi/update_return.php
// Versi mandiri tanpa return_bootstrap.php

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

/**
 * Parsing tanggal dari beberapa format menjadi Y-m-d.
 */
function parseReturnDate($value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $formats = [
        'Y-m-d',
        'd-M-Y',
        'd-m-Y',
        'd/m/Y'
    ];

    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);

        if ($date instanceof DateTime) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

/**
 * Konversi nilai input menjadi float.
 */
function returnDecimal($value): float
{
    if (is_string($value)) {
        $value = str_replace(
            [',', ' '],
            ['', ''],
            $value
        );
    }

    return is_numeric($value)
        ? (float)$value
        : 0.0;
}

/**
 * Redirect aman untuk aplikasi yang dimuat melalui index.php?page=...
 * Menggunakan JavaScript agar tidak terkena warning headers already sent.
 */
function redirectReturn(
    string $type,
    string $message,
    string $url
): void {
    $_SESSION['alert'] =
        '<div class="alert alert-' .
        htmlspecialchars($type, ENT_QUOTES, 'UTF-8') .
        '">' .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '</div>';

    echo '<script>';
    echo 'window.location.href = ' .
        json_encode(
            $url,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) .
        ';';
    echo '</script>';

    echo '<noscript>';
    echo '<meta http-equiv="refresh" content="0;url=' .
        htmlspecialchars($url, ENT_QUOTES, 'UTF-8') .
        '">';
    echo '</noscript>';

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectReturn(
        'danger',
        'Metode request tidak valid.',
        'index.php?page=return_invoice'
    );
}

$returnId = trim(
    (string)($_POST['return_id'] ?? '')
);

$returnDate = parseReturnDate(
    $_POST['return_date'] ?? ''
);

$reasonReturn = trim(
    (string)($_POST['reason_return'] ?? '')
);

$remarksReturn = trim(
    (string)($_POST['remarks_return'] ?? '')
);

$items = $_POST['items'] ?? [];

$user = (string)(
    $_SESSION['username'] ?? 'SYSTEM'
);

/*
 * Validasi header.
 */
if ($returnId === '') {
    redirectReturn(
        'danger',
        'Sales Return ID tidak ditemukan.',
        'index.php?page=return_invoice'
    );
}

if (!$returnDate) {
    redirectReturn(
        'danger',
        'Return Date tidak valid.',
        'index.php?page=edit_return&return_id=' .
            urlencode($returnId)
    );
}

if ($reasonReturn === '') {
    redirectReturn(
        'danger',
        'Reason Return wajib diisi.',
        'index.php?page=edit_return&return_id=' .
            urlencode($returnId)
    );
}

if (!is_array($items)) {
    redirectReturn(
        'danger',
        'Detail Sales Return tidak valid.',
        'index.php?page=edit_return&return_id=' .
            urlencode($returnId)
    );
}

try {
    mysqli_begin_transaction($conn);

    /*
     * Lock header retur.
     */
    $headerSql = "
        SELECT
            return_id,
            payment_balance,
            approval_status,
            status
        FROM head_retur_invoice
        WHERE return_id = ?
        LIMIT 1
        FOR UPDATE
    ";

    $stmt = mysqli_prepare($conn, $headerSql);

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal prepare header retur: ' .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $returnId
    );

    mysqli_stmt_execute($stmt);

    $headerResult = mysqli_stmt_get_result($stmt);
    $header = $headerResult
        ? mysqli_fetch_assoc($headerResult)
        : null;

    mysqli_stmt_close($stmt);

    if (!$header) {
        throw new RuntimeException(
            'Data Sales Return tidak ditemukan.'
        );
    }

    if (
        ($header['approval_status'] ?? 'Pending') !==
        'Pending'
    ) {
        throw new RuntimeException(
            'Sales Return yang sudah diproses ' .
            'tidak dapat diedit.'
        );
    }

    if (
        strtolower((string)($header['status'] ?? 'Open'))
        === 'cancelled'
    ) {
        throw new RuntimeException(
            'Sales Return yang sudah dibatalkan ' .
            'tidak dapat diedit.'
        );
    }

    $returnAmount = 0.0;
    $updatedCount = 0;
    $positiveItemCount = 0;

    foreach ($items as $item) {
        $detailId = (int)(
            $item['id'] ?? 0
        );

        if ($detailId <= 0) {
            continue;
        }

        $returnQty = returnDecimal(
            $item['return_quantity'] ?? 0
        );

        $returnPack = returnDecimal(
            $item['return_quantity_pack'] ?? 0
        );

        $remarksDetail = trim(
            (string)(
                $item['remarks_detail'] ?? ''
            )
        );

        /*
         * Lock detail retur.
         */
        $detailSql = "
            SELECT
                id,
                return_id,
                inventory_id,
                inventory_name,
                original_quantity,
                original_quantity_pack,
                uom,
                uom_pack,
                price,
                price_unit
            FROM detail_retur_invoice
            WHERE id = ?
              AND return_id = ?
            LIMIT 1
            FOR UPDATE
        ";

        $stmt = mysqli_prepare(
            $conn,
            $detailSql
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Gagal prepare detail retur: ' .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'is',
            $detailId,
            $returnId
        );

        mysqli_stmt_execute($stmt);

        $detailResult = mysqli_stmt_get_result(
            $stmt
        );

        $detail = $detailResult
            ? mysqli_fetch_assoc($detailResult)
            : null;

        mysqli_stmt_close($stmt);

        if (!$detail) {
            throw new RuntimeException(
                "Detail retur ID {$detailId} " .
                'tidak ditemukan.'
            );
        }

        $originalQty = (float)(
            $detail['original_quantity'] ?? 0
        );

        $originalPack = (float)(
            $detail['original_quantity_pack'] ?? 0
        );

        /*
         * Validasi Qty Return.
         */
        if ($returnQty < 0) {
            throw new RuntimeException(
                "Qty Return inventory " .
                "{$detail['inventory_id']} " .
                'tidak boleh negatif.'
            );
        }

        if (
            $returnQty >
            $originalQty + 0.0001
        ) {
            throw new RuntimeException(
                "Qty Return inventory " .
                "{$detail['inventory_id']} " .
                'melebihi Qty Shipping.'
            );
        }

        /*
         * Sinkronisasi Qty Return dan Qty Pack Return.
         *
         * Jika UoM Pack = KG:
         * Qty Pack Return = Qty Return
         *
         * Selain itu:
         * Qty Pack Return =
         * Qty Return / (Original Qty / Original Qty Pack)
         */
        $uomPack = strtoupper(
            trim(
                (string)(
                    $detail['uom_pack'] ?? ''
                )
            )
        );

        if ($uomPack === 'KG') {
            $returnPack = $returnQty;
        } elseif (
            $originalQty > 0 &&
            $originalPack > 0
        ) {
            $conversion =
                $originalQty /
                $originalPack;

            $returnPack =
                $returnQty /
                $conversion;
        }

        /*
         * Validasi Qty Pack Return.
         */
        if ($returnPack < 0) {
            throw new RuntimeException(
                "Qty Pack Return inventory " .
                "{$detail['inventory_id']} " .
                'tidak boleh negatif.'
            );
        }

        if (
            $originalPack > 0 &&
            $returnPack >
            $originalPack + 0.0001
        ) {
            throw new RuntimeException(
                "Qty Pack Return inventory " .
                "{$detail['inventory_id']} " .
                'melebihi Qty Pack Shipping.'
            );
        }

        /*
         * Price berasal dari detail_sales_order
         * dan sudah disimpan ke detail_retur_invoice.
         */
        $price = (float)(
            $detail['price'] ?? 0
        );

        if (
            $returnQty > 0 &&
            $price <= 0
        ) {
            throw new RuntimeException(
                "Price inventory " .
                "{$detail['inventory_id']} " .
                'tidak ditemukan atau bernilai 0.'
            );
        }

        /*
         * Mengikuti rumus add_sales_order:
         *
         * Return Subtotal =
         * Price × Qty Pack Return
         */
        $returnSubtotal = round(
            $price * $returnPack,
            2
        );

        /*
         * Update detail, termasuk apabila qty
         * diubah menjadi 0.
         */
        $updateDetailSql = "
            UPDATE detail_retur_invoice
            SET
                return_quantity = ?,
                return_quantity_pack = ?,
                return_subtotal = ?,
                remarks_detail = ?,
                user_modified = ?,
                date_modified = NOW()
            WHERE id = ?
              AND return_id = ?
        ";

        $stmt = mysqli_prepare(
            $conn,
            $updateDetailSql
        );

        if (!$stmt) {
            throw new RuntimeException(
                'Gagal prepare update detail: ' .
                mysqli_error($conn)
            );
        }

        mysqli_stmt_bind_param(
            $stmt,
            'dddssis',
            $returnQty,
            $returnPack,
            $returnSubtotal,
            $remarksDetail,
            $user,
            $detailId,
            $returnId
        );

        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $returnAmount += $returnSubtotal;
        $updatedCount++;

        if ($returnQty > 0) {
            $positiveItemCount++;
        }
    }

    if ($updatedCount === 0) {
        throw new RuntimeException(
            'Tidak ada detail Sales Return ' .
            'yang dapat diperbarui.'
        );
    }

    if ($positiveItemCount === 0) {
        throw new RuntimeException(
            'Minimal satu inventory harus ' .
            'memiliki Qty Return lebih dari 0.'
        );
    }

    /*
     * Balance After Return.
     */
    $paymentBalance = (float)(
        $header['payment_balance'] ?? 0
    );

    $remainingBalance = max(
        0,
        $paymentBalance - $returnAmount
    );

    /*
     * Update header retur.
     */
    $updateHeaderSql = "
        UPDATE head_retur_invoice
        SET
            return_date = ?,
            reason_return = ?,
            remarks_return = ?,
            return_amount = ?,
            remaining_invoice_balance = ?,
            user_modified = ?,
            date_modified = NOW()
        WHERE return_id = ?
    ";

    $stmt = mysqli_prepare(
        $conn,
        $updateHeaderSql
    );

    if (!$stmt) {
        throw new RuntimeException(
            'Gagal prepare update header: ' .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param(
        $stmt,
        'sssddss',
        $returnDate,
        $reasonReturn,
        $remarksReturn,
        $returnAmount,
        $remainingBalance,
        $user,
        $returnId
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    mysqli_commit($conn);

    redirectReturn(
        'success',
        "Sales Return {$returnId} " .
        'berhasil diperbarui.',
        'index.php?page=return_invoice'
    );

} catch (Throwable $e) {
    try {
        mysqli_rollback($conn);
    } catch (Throwable $rollbackError) {
        /*
         * Abaikan error rollback agar pesan
         * error utama tetap ditampilkan.
         */
    }

    redirectReturn(
        'danger',
        'Error: ' . $e->getMessage(),
        'index.php?page=edit_return&return_id=' .
            urlencode($returnId)
    );
}
