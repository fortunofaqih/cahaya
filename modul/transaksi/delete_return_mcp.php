<?php
// modul/transaksi/delete_return_mcp.php
// Delete Sales Return CP-MCP.
// Hanya return dengan approval_status Pending yang dapat dihapus.

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
        htmlspecialchars(
            $type,
            ENT_QUOTES,
            'UTF-8'
        ) .
        '">' .
        htmlspecialchars(
            $message,
            ENT_QUOTES,
            'UTF-8'
        ) .
        '</div>';

    $url = 'index.php?page=return_invoice';

    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }

    echo '<script>
        window.location.href = ' .
        json_encode(
            $url,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        ) .
        ';
    </script>';

    exit;
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
    | LOCK HEADER
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "SELECT
            return_id,
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

    /*
    |--------------------------------------------------------------------------
    | DELETE DETAIL
    |--------------------------------------------------------------------------
    */
    $stmt = mysqli_prepare(
        $conn,
        "DELETE FROM detail_retur_invoice
         WHERE return_id = ?"
    );

    mysqli_stmt_bind_param(
        $stmt,
        's',
        $returnId
    );

    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    /*
    |--------------------------------------------------------------------------
    | DELETE HEADER
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

    mysqli_commit($conn);

    mcpDeleteRedirect(
        'success',
        "Sales Return CP-MCP {$returnId} berhasil dihapus."
    );

} catch (Throwable $e) {
    mysqli_rollback($conn);

    mcpDeleteRedirect(
        'danger',
        'Error: ' . $e->getMessage()
    );
}
