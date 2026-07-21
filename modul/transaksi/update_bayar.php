<?php
// modul/transaksi/update_bayar.php

// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// ======================================================================
// FUNCTIONS
// ======================================================================

function redirectWithAlert($type, $message, $page = 'pembayaran') {
    $_SESSION['alert'] = "
        <div style='padding:10px;margin-bottom:10px;border-radius:4px;background:" . ($type === 'success' ? '#d1e7dd' : '#f8d7da') . ";color:" . ($type === 'success' ? '#0f5132' : '#842029') . ";border:1px solid " . ($type === 'success' ? '#badbcc' : '#f5c2c7') . ";'>
            " . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . "
        </div>
    ";
    // Kirim langsung tanpa encode berlebihan
    header("Location: ../../index.php?page=" . $page);
    exit;
}

function parseDateInput($value) {
    $value = trim((string)$value);
    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'];

    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    return null;
}

function parseNumber($value) {
    $value = trim((string)$value);
    $value = str_replace(['Rp', ' ', '.'], '', $value);
    $value = str_replace(',', '.', $value);
    return (float)$value;
}

function rollbackTitipUsage($conn, $bayar_no, $username) {
    $sqlOldUsage = "
        SELECT titip_no, amount_out
        FROM detail_titip
        WHERE transaction_type = 'PAKAI'
          AND ref_no = ?
    ";
    $stmtOldUsage = mysqli_prepare($conn, $sqlOldUsage);
    
    if (!$stmtOldUsage) {
        throw new Exception('Gagal prepare query rollback: ' . mysqli_error($conn));
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
                used_amount = GREATEST(used_amount - ?, 0),
                balance_amount = balance_amount + ?,
                status = 'Open',
                user_modified = ?,
                date_modified = NOW()
            WHERE titip_no = ?
        ";
        $stmtReturn = mysqli_prepare($conn, $sqlReturn);
        
        if (!$stmtReturn) {
            throw new Exception('Gagal prepare query update titip: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmtReturn, 'ddss', $amount_out, $amount_out, $username, $titip_no);
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
    
    if (!$stmtDeleteUsage) {
        throw new Exception('Gagal prepare query delete: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmtDeleteUsage, 's', $bayar_no);
    mysqli_stmt_execute($stmtDeleteUsage);
    mysqli_stmt_close($stmtDeleteUsage);
}

function applyTitipUsage($conn, $customer_id, $customer_name, $bayar_no, $bayar_date, $amount, $username) {
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
    
    if (!$stmtTitip) {
        throw new Exception('Gagal prepare query ambil titip: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($stmtTitip, 's', $customer_id);
    mysqli_stmt_execute($stmtTitip);
    $resTitip = mysqli_stmt_get_result($stmtTitip);

    while ($rowTitip = mysqli_fetch_assoc($resTitip)) {
        if ($remaining <= 0) {
            break;
        }

        $titip_no = $rowTitip['titip_no'];
        $balance_before = (float)$rowTitip['balance_amount'];
        $used_now = min($balance_before, $remaining);
        $balance_after = $balance_before - $used_now;

        $status = $balance_after <= 0 ? 'Closed' : 'Open';

        $sqlUpdateTitip = "
            UPDATE head_titip
            SET
                used_amount = used_amount + ?,
                balance_amount = balance_amount - ?,
                status = ?,
                user_modified = ?,
                date_modified = NOW()
            WHERE titip_no = ?
        ";
        $stmtUpdateTitip = mysqli_prepare($conn, $sqlUpdateTitip);
        
        if (!$stmtUpdateTitip) {
            throw new Exception('Gagal prepare query update titip apply: ' . mysqli_error($conn));
        }
        
        mysqli_stmt_bind_param($stmtUpdateTitip, 'ddsss', $used_now, $used_now, $status, $username, $titip_no);
        mysqli_stmt_execute($stmtUpdateTitip);
        mysqli_stmt_close($stmtUpdateTitip);

        $remarksTitip = 'Dipakai untuk pembayaran ' . $bayar_no;

        // Query INSERT dengan 16 kolom dan 16 placeholder
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
                date_created,
                user_modified,
                date_modified
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $stmtDetailTitip = mysqli_prepare($conn, $sqlDetailTitip);
        
        if (!$stmtDetailTitip) {
            throw new Exception('Gagal prepare query insert detail titip: ' . mysqli_error($conn));
        }
        
        $transaction_type = 'PAKAI';
        $amount_in = 0;
        $keterangan = 'PEMBAYARAN';
        $bank_name = '';
        $date_now = date('Y-m-d H:i:s');
        
        mysqli_stmt_bind_param(
            $stmtDetailTitip,
            'ssssssddssssssss',
            $titip_no,
            $bayar_date,
            $customer_id,
            $customer_name,
            $transaction_type,
            $bayar_no,
            $amount_in,
            $used_now,
            $balance_after,
            $keterangan,
            $bank_name,
            $remarksTitip,
            $username,
            $date_now,
            $username,
            $date_now
        );
        mysqli_stmt_execute($stmtDetailTitip);
        mysqli_stmt_close($stmtDetailTitip);

        $remaining -= $used_now;
    }

    mysqli_stmt_close($stmtTitip);

    if ($remaining > 0.0001) {
        throw new Exception('Saldo titip uang tidak mencukupi.');
    }
}

// ======================================================================
// MAIN PROCESS
// ======================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectWithAlert('error', 'Invalid request.');
}

$bayar_no = trim((string)($_POST['bayar_no'] ?? ''));
$invoice_no = trim((string)($_POST['invoice_no'] ?? ''));
$bayar_date = parseDateInput($_POST['bayar_date'] ?? '');
$nominal_bayar = parseNumber($_POST['nominal_bayar'] ?? 0);
$keterangan = trim((string)($_POST['keterangan'] ?? ''));
$bank_name = trim((string)($_POST['bank_name'] ?? ''));
$remarks = trim((string)($_POST['remarks'] ?? ''));
$username = $_SESSION['username'] ?? 'system';
$pakai_titip = isset($_POST['pakai_titip']) && $_POST['pakai_titip'] == '1';
$nominal_titip = $pakai_titip ? parseNumber($_POST['nominal_titip'] ?? 0) : 0;
$total_bayar_invoice = $nominal_bayar + $nominal_titip;

if ($bayar_no === '' || $invoice_no === '' || !$bayar_date || $total_bayar_invoice <= 0 || $keterangan === '') {
    redirectWithAlert('error', 'Data pembayaran belum lengkap.', 'edit_bayar&bayar_no=' . urlencode($bayar_no));
}

mysqli_begin_transaction($conn);

try {
    // ============================================================
    // QUERY 1: Ambil data pembayaran lama
    // ============================================================
    $sqlOld = "
        SELECT 
            hb.bayar_no,
            db.invoice_no
        FROM head_bayar hb
        INNER JOIN detail_bayar db ON db.bayar_no = hb.bayar_no
        WHERE hb.bayar_no = ?
        LIMIT 1
    ";
    $stmtOld = mysqli_prepare($conn, $sqlOld);
    if (!$stmtOld) {
        throw new Exception('Gagal prepare query ambil data lama: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmtOld, 's', $bayar_no);
    mysqli_stmt_execute($stmtOld);
    $resOld = mysqli_stmt_get_result($stmtOld);
    $old = mysqli_fetch_assoc($resOld);
    mysqli_stmt_close($stmtOld);

    if (!$old) {
        throw new Exception('Data pembayaran tidak ditemukan.');
    }

    // ============================================================
    // QUERY 2: Ambil data invoice
    // ============================================================
    $sqlInv = "
        SELECT
            hi.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            CASE
                WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
                WHEN COALESCE(hi.grand_total, 0) > 0 THEN COALESCE(hi.grand_total, 0)
                ELSE COALESCE(hi.subtotal, 0)
            END AS invoice_amount
        FROM head_invoice hi
        WHERE hi.invoice_no = ?
        LIMIT 1
    ";
    $stmtInv = mysqli_prepare($conn, $sqlInv);
    if (!$stmtInv) {
        throw new Exception('Gagal prepare query ambil invoice: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmtInv, 's', $invoice_no);
    mysqli_stmt_execute($stmtInv);
    $resInv = mysqli_stmt_get_result($stmtInv);
    $inv = mysqli_fetch_assoc($resInv);
    mysqli_stmt_close($stmtInv);

    if (!$inv) {
        throw new Exception('Invoice tidak ditemukan.');
    }

    // Rollback penggunaan titip sebelumnya
    rollbackTitipUsage($conn, $bayar_no, $username);

    $invoice_amount = (float)$inv['invoice_amount'];

    // ============================================================
    // QUERY 3: Hitung pembayaran yang sudah dilakukan (selain ini)
    // ============================================================
    $sqlPaid = "
        SELECT COALESCE(SUM(CASE WHEN bayar_no <> ? THEN bayar_amount ELSE 0 END), 0) AS paid_except_current
        FROM detail_bayar
        WHERE invoice_no = ?
    ";
    $stmtPaid = mysqli_prepare($conn, $sqlPaid);
    if (!$stmtPaid) {
        throw new Exception('Gagal prepare query hitung pembayaran: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmtPaid, 'ss', $bayar_no, $invoice_no);
    mysqli_stmt_execute($stmtPaid);
    $resPaid = mysqli_stmt_get_result($stmtPaid);
    $paid = mysqli_fetch_assoc($resPaid);
    mysqli_stmt_close($stmtPaid);

    $paid_except_current = (float)($paid['paid_except_current'] ?? 0);
    $sisa_before_current = $invoice_amount - $paid_except_current;

    if ($total_bayar_invoice > $sisa_before_current) {
        throw new Exception('Total bayar melebihi sisa invoice.');
    }

    // ============================================================
    // QUERY 4: Cek saldo titip uang
    // ============================================================
    if ($nominal_titip > 0) {
        $sqlSaldoTitip = "
            SELECT COALESCE(SUM(balance_amount), 0) AS saldo_titip
            FROM head_titip
            WHERE customer_id = ?
              AND balance_amount > 0
        ";
        $stmtSaldoTitip = mysqli_prepare($conn, $sqlSaldoTitip);
        if (!$stmtSaldoTitip) {
            throw new Exception('Gagal prepare query cek saldo: ' . mysqli_error($conn));
        }
        mysqli_stmt_bind_param($stmtSaldoTitip, 's', $inv['customer_id']);
        mysqli_stmt_execute($stmtSaldoTitip);
        $resSaldoTitip = mysqli_stmt_get_result($stmtSaldoTitip);
        $rowSaldoTitip = mysqli_fetch_assoc($resSaldoTitip);
        mysqli_stmt_close($stmtSaldoTitip);

        $saldo_titip = (float)($rowSaldoTitip['saldo_titip'] ?? 0);

        if ($nominal_titip > $saldo_titip) {
            throw new Exception('Nominal titip yang dipakai melebihi saldo titip uang.');
        }
    }

    $sisa_after = $sisa_before_current - $total_bayar_invoice;

    // ============================================================
    // QUERY 5: Update HEAD_BAYAR
    // ============================================================
    $sqlHead = "
        UPDATE head_bayar
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
        WHERE bayar_no = ?
    ";
    $stmtHead = mysqli_prepare($conn, $sqlHead);
    if (!$stmtHead) {
        throw new Exception('Gagal prepare query update head_bayar: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param(
        $stmtHead,
        'sssssdsssss',
        $bayar_date,
        $inv['customer_id'],
        $inv['customer_name'],
        $inv['customer_address'],
        $inv['customer_city'],
        $total_bayar_invoice,
        $keterangan,
        $bank_name,
        $remarks,
        $username,
        $bayar_no
    );
    mysqli_stmt_execute($stmtHead);
    mysqli_stmt_close($stmtHead);

    // ============================================================
    // QUERY 6: Update DETAIL_BAYAR
    // ============================================================
    $sqlDetail = "
        UPDATE detail_bayar
        SET
            invoice_no = ?,
            invoice_date = ?,
            invoice_amount = ?,
            cash_amount = ?,
            titip_amount = ?,
            bayar_amount = ?,
            sisa_after = ?,
            remarks = ?,
            user_modified = ?,
            date_modified = NOW()
        WHERE bayar_no = ?
    ";
    $stmtDetail = mysqli_prepare($conn, $sqlDetail);
    if (!$stmtDetail) {
        throw new Exception('Gagal prepare query update detail_bayar: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param(
        $stmtDetail,
        'ssdddddsss',
        $invoice_no,
        $inv['invoice_date'],
        $invoice_amount,
        $nominal_bayar,
        $nominal_titip,
        $total_bayar_invoice,
        $sisa_after,
        $remarks,
        $username,
        $bayar_no
    );
    mysqli_stmt_execute($stmtDetail);
    mysqli_stmt_close($stmtDetail);

    // ============================================================
    // Apply penggunaan titip uang
    // ============================================================
    applyTitipUsage(
        $conn,
        $inv['customer_id'],
        $inv['customer_name'],
        $bayar_no,
        $bayar_date,
        $nominal_titip,
        $username
    );

    // ============================================================
    // QUERY 7: Update status invoice
    // ============================================================
    $newStatus = $sisa_after <= 0 ? 'Paid' : 'Partial';
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
    if (!$stmtUpdateInvoice) {
        throw new Exception('Gagal prepare query update invoice: ' . mysqli_error($conn));
    }
    mysqli_stmt_bind_param($stmtUpdateInvoice, 'dsss', $sisa_after, $newStatus, $username, $invoice_no);
    mysqli_stmt_execute($stmtUpdateInvoice);
    mysqli_stmt_close($stmtUpdateInvoice);

    // ============================================================
    // COMMIT TRANSACTION
    // ============================================================
    mysqli_commit($conn);

    redirectWithAlert('success', 'Pembayaran berhasil diupdate.', 'pembayaran');

} catch (Exception $e) {
    mysqli_rollback($conn);
    redirectWithAlert('error', $e->getMessage(), 'edit_bayar&bayar_no=' . urlencode($bayar_no));
}
?>