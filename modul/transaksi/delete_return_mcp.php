<?php
// modul/transaksi/delete_return_mcp.php
// Delete Sales Return CP-MCP - sinkron dengan relasi internal terbaru.
//
// Urutan aman terhadap foreign key:
// 1. detail_retur_invoice
// 2. head_retur_invoice
// 3. det_invoice
// 4. head_invoice
// 5. det_shipping
// 6. hed_shipping
// 7. detail_sales_order (jika ada)
// 8. head_sales_order
//
// Master m_inventory sengaja tidak dihapus otomatis untuk menghindari
// potensi FK lain dan menjaga audit trail inventory internal MCP.

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

function mcpDeleteRedirect(
    string $type,
    string $message
): void {
    $_SESSION['alert'] =
        '<div class="alert alert-' .
        htmlspecialchars($type, ENT_QUOTES, 'UTF-8') .
        '">' .
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
        '</div>';

    $url = 'index.php?page=return_invoice';

    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    echo '<script>window.location.href=' .
        json_encode(
            $url,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ) .
        ';</script>';

    exit;
}

function mcpDeleteTableExists(
    mysqli $conn,
    string $table
): bool {
    $safe = mysqli_real_escape_string($conn, $table);

    $res = mysqli_query(
        $conn,
        "SHOW TABLES LIKE '{$safe}'"
    );

    return $res && mysqli_num_rows($res) > 0;
}

function mcpDeleteBy(
    mysqli $conn,
    string $table,
    string $column,
    string $value
): void {
    if (!mcpDeleteTableExists($conn, $table)) {
        return;
    }

    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM `{$table}` WHERE `{$column}` = ?"
    );

    if (!$stmt) {
        throw new RuntimeException(
            "Gagal prepare delete {$table}: " .
            mysqli_error($conn)
        );
    }

    mysqli_stmt_bind_param($stmt, 's', $value);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcpDeleteRedirect(
        'danger',
        'Metode request tidak valid.'
    );
}

$returnId = trim(
    (string)($_POST['return_id'] ?? '')
);

if ($returnId === '') {
    mcpDeleteRedirect(
        'danger',
        'Sales Return ID kosong.'
    );
}

try {
    mysqli_begin_transaction($conn);

    /*
    |--------------------------------------------------------------------------
    | LOCK HEADER + AMBIL INTERNAL REFERENCES
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            return_id,
            invoice_no,
            shipping_no,
            order_no,
            approval_status
         FROM head_retur_invoice
         WHERE return_id = ?
         LIMIT 1
         FOR UPDATE"
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $returnId
    );

    mysqli_stmt_execute($stmt);

    $head = mysqli_fetch_assoc(
        mysqli_stmt_get_result($stmt)
    );

    mysqli_stmt_close($stmt);

    if (!$head) {
        throw new RuntimeException(
            'Sales Return tidak ditemukan.'
        );
    }

    if (
        strtolower(
            trim(
                (string)(
                    $head['approval_status']
                    ?? 'Pending'
                )
            )
        ) !== 'pending'
    ) {
        throw new RuntimeException(
            'Sales Return yang sudah Approved tidak dapat dihapus.'
        );
    }

    $internalInvoiceNo =
        trim((string)($head['invoice_no'] ?? ''));

    $internalShippingNo =
        trim((string)($head['shipping_no'] ?? ''));

    $internalOrderNo =
        trim((string)($head['order_no'] ?? ''));

    $isMcp =
        stripos($returnId, '/CP-MCP/') !== false ||
        strpos(
            $internalInvoiceNo,
            'CP-MCP/INV/'
        ) === 0;

    if (!$isMcp) {
        throw new RuntimeException(
            'Data ini bukan Sales Return CP-MCP.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE RETURN DETAIL
    |--------------------------------------------------------------------------
    */
    mcpDeleteBy(
        $conn,
        'detail_retur_invoice',
        'return_id',
        $returnId
    );

    /*
    |--------------------------------------------------------------------------
    | DELETE RETURN HEADER
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM head_retur_invoice
         WHERE return_id = ?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $returnId
    );

    mysqli_stmt_execute($stmt);

    if (mysqli_stmt_affected_rows($stmt) < 1) {
        mysqli_stmt_close($stmt);

        throw new RuntimeException(
            'Sales Return gagal dihapus.'
        );
    }

    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | DELETE INTERNAL INVOICE
    |--------------------------------------------------------------------------
    */
    if ($internalInvoiceNo !== '') {
        mcpDeleteBy(
            $conn,
            'det_invoice',
            'invoice_no',
            $internalInvoiceNo
        );

        mcpDeleteBy(
            $conn,
            'head_invoice',
            'invoice_no',
            $internalInvoiceNo
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE INTERNAL SHIPPING
    |--------------------------------------------------------------------------
    */
    if ($internalShippingNo !== '') {
        mcpDeleteBy(
            $conn,
            'det_shipping',
            'shipping_no',
            $internalShippingNo
        );

        mcpDeleteBy(
            $conn,
            'hed_shipping',
            'shipping_no',
            $internalShippingNo
        );
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE INTERNAL SALES ORDER
    |--------------------------------------------------------------------------
    */
    if ($internalOrderNo !== '') {
        if (
            mcpDeleteTableExists(
                $conn,
                'detail_sales_order'
            )
        ) {
            mcpDeleteBy(
                $conn,
                'detail_sales_order',
                'order_no',
                $internalOrderNo
            );
        }

        mcpDeleteBy(
            $conn,
            'head_sales_order',
            'order_no',
            $internalOrderNo
        );
    }

    mysqli_commit($conn);

    mcpDeleteRedirect(
        'success',
        "Sales Return CP-MCP {$returnId} beserta data internal terkait berhasil dihapus."
    );

} catch (Throwable $e) {
    try {
        mysqli_rollback($conn);
    } catch (Throwable $ignore) {
    }

    mcpDeleteRedirect(
        'danger',
        'Error: ' . $e->getMessage()
    );
}