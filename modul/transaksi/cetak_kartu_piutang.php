<?php
// modul/transaksi/cetak_kartu_piutang.php

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

function parseReportDate($value, $fallback) {
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    $formats = ['d-M-Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d');
        }
    }

    return $fallback;
}

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

$today = date('Y-m-d');
$customer_id = trim((string)($_GET['customer_id'] ?? ''));
$start_date = parseReportDate($_GET['start_date'] ?? '', $today);
$end_date = parseReportDate($_GET['end_date'] ?? '', $today);

if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

if ($customer_id === '') {
    die('Customer belum dipilih.');
}

$sqlCustomer = "
    SELECT 
        mc.customer_id,
        mc.customer,
        mc.area_code,
        COALESCE(ma.area, mc.area_code, '') AS area_name
    FROM m_customer mc
    LEFT JOIN m_area ma 
        ON ma.kode COLLATE utf8mb4_general_ci = mc.area_code
    WHERE mc.customer_id = ?
    LIMIT 1
";
$stmtCustomer = mysqli_prepare($conn, $sqlCustomer);
if (!$stmtCustomer) {
    die('SQL Error Customer: ' . h(mysqli_error($conn)));
}

mysqli_stmt_bind_param($stmtCustomer, 's', $customer_id);
mysqli_stmt_execute($stmtCustomer);
$resCustomer = mysqli_stmt_get_result($stmtCustomer);
$customerData = mysqli_fetch_assoc($resCustomer);
mysqli_stmt_close($stmtCustomer);

if (!$customerData) {
    die('Data customer tidak ditemukan.');
}

$amountExpr = "
    CASE
        WHEN COALESCE(piutang, 0) > 0 THEN COALESCE(piutang, 0)
        WHEN COALESCE(payment_balance, 0) > 0 THEN COALESCE(payment_balance, 0)
        ELSE COALESCE(grand_total, 0)
    END
";

$sqlSaldo = "
    SELECT
        (
            COALESCE((
                SELECT SUM(
                    CASE
                        WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
                        WHEN COALESCE(hi.payment_balance, 0) > 0 THEN COALESCE(hi.payment_balance, 0)
                        ELSE COALESCE(hi.grand_total, 0)
                    END
                )
                FROM head_invoice hi
                WHERE hi.customer_id = ?
                  AND hi.invoice_date < ?
            ), 0)
            -
            COALESCE((
                SELECT SUM(db.bayar_amount)
                FROM detail_bayar db
                INNER JOIN head_bayar hb ON hb.bayar_no = db.bayar_no
                WHERE hb.customer_id = ?
                  AND hb.bayar_date < ?
            ), 0)
        ) AS saldo_awal
";
$stmtSaldo = mysqli_prepare($conn, $sqlSaldo);
mysqli_stmt_bind_param($stmtSaldo, 'ssss', $customer_id, $start_date, $customer_id, $start_date);
mysqli_stmt_execute($stmtSaldo);
$resSaldo = mysqli_stmt_get_result($stmtSaldo);
$rowSaldo = mysqli_fetch_assoc($resSaldo);
$saldo_awal = (float)($rowSaldo['saldo_awal'] ?? 0);
mysqli_stmt_close($stmtSaldo);

$sqlRows = "
    SELECT
        trans_date,
        invoice_no,
        bayar_no,
        penjualan,
        pembayaran,
        sort_order
    FROM
    (
        SELECT
            hi.invoice_date AS trans_date,
            hi.invoice_no AS invoice_no,
            '' AS bayar_no,
            CASE
                WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
                WHEN COALESCE(hi.payment_balance, 0) > 0 THEN COALESCE(hi.payment_balance, 0)
                ELSE COALESCE(hi.grand_total, 0)
            END AS penjualan,
            0 AS pembayaran,
            1 AS sort_order
        FROM head_invoice hi
        WHERE hi.customer_id = ?
          AND hi.invoice_date BETWEEN ? AND ?

        UNION ALL

        SELECT
            hb.bayar_date AS trans_date,
            db.invoice_no AS invoice_no,
            hb.bayar_no AS bayar_no,
            0 AS penjualan,
            db.bayar_amount AS pembayaran,
            2 AS sort_order
        FROM head_bayar hb
        INNER JOIN detail_bayar db ON db.bayar_no = hb.bayar_no
        WHERE hb.customer_id = ?
          AND hb.bayar_date BETWEEN ? AND ?
    ) x
    ORDER BY trans_date ASC, sort_order ASC, invoice_no ASC, bayar_no ASC
";
$stmtRows = mysqli_prepare($conn, $sqlRows);
mysqli_stmt_bind_param(
    $stmtRows,
    'ssssss',
    $customer_id,
    $start_date,
    $end_date,
    $customer_id,
    $start_date,
    $end_date
);
mysqli_stmt_execute($stmtRows);
$resRows = mysqli_stmt_get_result($stmtRows);

$rows = [];
$total_penjualan = 0;
$total_pembayaran = 0;
$runningSaldo = $saldo_awal;

while ($row = mysqli_fetch_assoc($resRows)) {
    $penjualan = (float)($row['penjualan'] ?? 0);
    $pembayaran = (float)($row['pembayaran'] ?? 0);

    $runningSaldo += $penjualan - $pembayaran;

    $row['sisa'] = $runningSaldo;

    $total_penjualan += $penjualan;
    $total_pembayaran += $pembayaran;

    $rows[] = $row;
}

mysqli_stmt_close($stmtRows);

$saldo_akhir = $saldo_awal + $total_penjualan - $total_pembayaran;
$tgl_cetak = date('d-M-Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kartu Piutang - <?= h($customerData['customer_id']) ?></title>
    <style>
        @page {
            /* Untuk folio/F4 */
            size: 8.5in 13in portrait;
            margin: 10mm;

            /* Jika ingin A4, ganti menjadi:
               size: A4 portrait;
            */
        }

        * {
            box-sizing: border-box;
        }

       body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10px;
            color: #000;
            margin: 0;
            padding: 0;
            background: #fff;
        }

        .print-wrap {
            width: 100%;
            margin: 0 auto;
        }

        .title {
            text-align: center;
            font-size: 17px;
            font-weight: bold;
            letter-spacing: .5px;
            margin-bottom: 4px;
        }

        .period {
            text-align: center;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .top-info {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        .top-info td {
            padding: 2px 0;
            vertical-align: top;
        }

        .top-info .label {
            width: 90px;
            font-weight: bold;
        }

        .top-info .sep {
            width: 10px;
            text-align: center;
        }

        .top-info .right-label {
            width: 75px;
            font-weight: bold;
        }

        .detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5px;
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            border-bottom: 1px solid #000;
        }

        /* Header tetap full border */
        .detail-table th {
            border: 1px solid #000;
            padding: 3px 3px;
            text-align: center;
            font-weight: bold;
            background: #f2f2f2;
            white-space: nowrap;
        }

        /* Data tidak pakai all border */
        .detail-table tbody td {
            border-left: none;
            border-right: none;
            border-top: none;
            border-bottom: none;
            padding: 2px 3px;
            vertical-align: middle;
        }

        /* Supaya sisi kiri dan kanan tabel tetap tertutup */
        .detail-table tbody td:first-child {
            border-left: 1px solid #000;
        }

        .detail-table tbody td:last-child {
            border-right: 1px solid #000;
        }

        /* Baris total tetap diberi border agar terlihat sebagai penutup */
        .detail-table .summary-row td {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            font-weight: bold;
            background: #f2f2f2;
            padding: 3px;
        }

        .detail-table .summary-row td:first-child {
            border-left: 1px solid #000;
        }

        .detail-table .summary-row td:last-child {
            border-right: 1px solid #000;
        }
        .money-cell {
            text-align: right;
            font-family: Arial, Helvetica, sans-serif;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .text-bold {
            font-weight: bold;
        }

        .summary-row td {
            font-weight: bold;
            background: #f2f2f2;
        }

        .no-data {
            text-align: center;
            padding: 12px;
            color: #555;
        }

        .print-actions {
            margin-bottom: 10px;
            text-align: right;
        }

        .btn-print {
            border: none;
            background: #0d6efd;
            color: #fff;
            padding: 7px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
        }

        @media print {
            .print-actions {
                display: none;
            }

            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="print-actions">
        <button type="button" class="btn-print" onclick="window.print()">Print</button>
    </div>

    <div class="print-wrap">
        <div class="title">KARTU PIUTANG CP</div>
        <div class="period">
            Periode <?= h(formatDateDisplay($start_date)) ?> s/d <?= h(formatDateDisplay($end_date)) ?>
        </div>

        <table class="top-info">
            <tr>
                <td class="label">Dicetak</td>
                <td class="sep">:</td>
                <td><?= h($tgl_cetak) ?></td>

                <td class="right-label">Area</td>
                <td class="sep">:</td>
                <td><?= h($customerData['area_name']) ?></td>
            </tr>
            <tr>
                <td class="label">Customer ID</td>
                <td class="sep">:</td>
                <td><?= h($customerData['customer_id']) ?></td>

                <td class="right-label">Customer</td>
                <td class="sep">:</td>
                <td><?= h($customerData['customer']) ?></td>
            </tr>
        </table>

        <table class="detail-table">
            <thead>
                <tr>
                 <th style="width:14%;">SALDO AWAL</th>
                <th style="width:10%;">TANGGAL</th>
                <th style="width:16%;">NO. INV</th>
                <th style="width:14%;">NO. BAYAR</th>
                <th style="width:15%;">PENJUALAN</th>
                <th style="width:15%;">PEMBAYARAN</th>
                <th style="width:16%;">SISA</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                       <td class="money-cell">Rp <?= h(formatMoney($saldo_awal)) ?></td>
                        <td colspan="5" class="no-data">Tidak ada data invoice pada periode ini.</td>
                        <td class="text-right text-bold">Rp <?= h(formatMoney($saldo_akhir)) ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $i => $row): ?>
                        <tr>
                            <td class="money-cell">
                                <?= $i === 0 ? 'Rp ' . h(formatMoney($saldo_awal)) : '' ?>
                            </td>
                           <td class="text-center"><?= h(formatDateDisplay($row['trans_date'])) ?></td>
                            <td><?= h($row['invoice_no']) ?></td>
                            <td class="text-center"><?= h($row['bayar_no']) ?></td>
                            <td class="money-cell">
                                <?= ((float)$row['penjualan'] > 0) ? 'Rp ' . h(formatMoney($row['penjualan'])) : '' ?>
                            </td>
                            <td class="money-cell">
                                <?= ((float)$row['pembayaran'] > 0) ? 'Rp ' . h(formatMoney($row['pembayaran'])) : '' ?>
                            </td>
                            <td class="money-cell">Rp <?= h(formatMoney($row['sisa'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <tr class="summary-row">
                    <td colspan="4" class="text-right">TOTAL</td>
                    <td class="money-cell">Rp <?= h(formatMoney($total_penjualan)) ?></td>
                    <td class="money-cell">Rp <?= h(formatMoney($total_pembayaran)) ?></td>
                    <td class="money-cell">Rp <?= h(formatMoney($saldo_akhir)) ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <script>
        window.addEventListener('load', function () {
            // Auto print saat halaman cetak dibuka.
            window.print();
        });
    </script>
</body>
</html>