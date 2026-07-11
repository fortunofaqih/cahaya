<?php
// modul/transaksi/delete_invoice.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$invoiceNo = trim($_GET['invoice_no'] ?? $_POST['invoice_no'] ?? $_GET['id'] ?? '');

if ($invoiceNo === '') {
    $_SESSION['alert'] = '<div class="alert alert-danger">Invoice No kosong.</div>';
    echo "<script>window.location.href='../../index.php?page=invoice ';</script>";
    exit;
}

$stmt = mysqli_prepare($conn, "SELECT invoice_no, invoice_date, customer_name, grand_total FROM head_invoice WHERE invoice_no = ? LIMIT 1");
if (!$stmt) {
    die('Gagal prepare invoice: ' . mysqli_error($conn));
}
mysqli_stmt_bind_param($stmt, 's', $invoiceNo);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$invoice = $res ? mysqli_fetch_assoc($res) : null;
mysqli_stmt_close($stmt);

if (!$invoice) {
    $_SESSION['alert'] = '<div class="alert alert-danger">Invoice tidak ditemukan.</div>';
    echo "<script>window.location.href='../../index.php?page=invoice ';</script>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    mysqli_begin_transaction($conn);
    try {
        $stmtDet = mysqli_prepare($conn, "DELETE FROM det_invoice WHERE invoice_no = ?");
        if (!$stmtDet) throw new Exception('Gagal prepare delete detail: ' . mysqli_error($conn));
        mysqli_stmt_bind_param($stmtDet, 's', $invoiceNo);
        if (!mysqli_stmt_execute($stmtDet)) throw new Exception('Gagal delete detail: ' . mysqli_stmt_error($stmtDet));
        mysqli_stmt_close($stmtDet);

        $stmtHead = mysqli_prepare($conn, "DELETE FROM head_invoice WHERE invoice_no = ?");
        if (!$stmtHead) throw new Exception('Gagal prepare delete header: ' . mysqli_error($conn));
        mysqli_stmt_bind_param($stmtHead, 's', $invoiceNo);
        if (!mysqli_stmt_execute($stmtHead)) throw new Exception('Gagal delete header: ' . mysqli_stmt_error($stmtHead));
        mysqli_stmt_close($stmtHead);

        mysqli_commit($conn);
        $_SESSION['alert'] = '<div class="alert alert-success">Invoice ' . h($invoiceNo) . ' berhasil dihapus.</div>';
        echo "<script>alert('Invoice " . addslashes($invoiceNo) . " berhasil dihapus.'); window.location.href='../../index.php?page=invoice ';</script>";
        exit;
    } catch (Exception $e) {
        mysqli_rollback($conn);
        $_SESSION['alert'] = '<div class="alert alert-danger">Error: ' . h($e->getMessage()) . '</div>';
        echo "<script>alert('Error: " . addslashes($e->getMessage()) . "'); window.location.href='../../index.php?page=invoice';</script>";
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Delete Invoice <?= h($invoiceNo) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width:720px;">
    <div class="card border-danger shadow-sm">
        <div class="card-header bg-danger text-white fw-bold">Konfirmasi Delete Invoice</div>
        <div class="card-body">
            <p>Anda yakin ingin menghapus invoice berikut?</p>
            <table class="table table-sm table-bordered">
                <tr><th style="width:180px;">Invoice No</th><td><?= h($invoice['invoice_no']) ?></td></tr>
                <tr><th>Invoice Date</th><td><?= h($invoice['invoice_date']) ?></td></tr>
                <tr><th>Customer</th><td><?= h($invoice['customer_name']) ?></td></tr>
                <tr><th>Grand Total</th><td>Rp <?= number_format((float)$invoice['grand_total'], 2, ',', '.') ?></td></tr>
            </table>
            <div class="alert alert-warning mb-0">Data header invoice dan detail invoice akan dihapus. Surat jalan/shipping akan bisa dipilih kembali untuk invoice baru.</div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="index.php?page=invoice" class="btn btn-secondary">Batal</a>
            <form method="POST" class="m-0">
                <input type="hidden" name="invoice_no" value="<?= h($invoiceNo) ?>">
                <button type="submit" class="btn btn-danger">Ya, Hapus</button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
