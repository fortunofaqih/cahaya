<?php
// modul/transaksi/cetak_kartu_piutang.php
// REVISI:
// 1. Retur Invoice / Sales Return ikut mengurangi piutang.
// 2. Retur ditampilkan sebagai nilai minus (-).
// 3. Saldo Awal memperhitungkan retur sebelum periode.
// 4. Saldo berjalan = saldo sebelumnya + penjualan - retur - pembayaran.

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

$start_date = parseReportDate(
    $_GET['start_date'] ?? '',
    $today
);

$end_date = parseReportDate(
    $_GET['end_date'] ?? '',
    $today
);

if (strtotime($start_date) > strtotime($end_date)) {
    [$start_date, $end_date] = [$end_date, $start_date];
}

if ($customer_id === '') {
    die('Customer belum dipilih.');
}

/*
 * Informasi customer.
 */
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

mysqli_stmt_bind_param(
    $stmtCustomer,
    's',
    $customer_id
);

mysqli_stmt_execute($stmtCustomer);

$resCustomer = mysqli_stmt_get_result($stmtCustomer);

$customerData = mysqli_fetch_assoc($resCustomer);

mysqli_stmt_close($stmtCustomer);

if (!$customerData) {
    die('Data customer tidak ditemukan.');
}

/*
 * SALDO AWAL:
 * Invoice sebelum periode
 * - Pembayaran sebelum periode
 * - Retur sebelum periode
 *
 * Retur berstatus Cancelled tidak dihitung.
 */
$sqlSaldo = "
    SELECT
        (
            COALESCE((
                SELECT
                    SUM(
                        CASE
                            WHEN COALESCE(hi.piutang, 0) > 0
                                THEN COALESCE(hi.piutang, 0)

                            WHEN COALESCE(hi.payment_balance, 0) > 0
                                THEN COALESCE(hi.payment_balance, 0)

                            ELSE COALESCE(hi.grand_total, 0)
                        END
                    )
                FROM head_invoice hi
                WHERE hi.customer_id = ?
                  AND hi.invoice_date < ?
            ), 0)

            -

            COALESCE((
                SELECT
                    SUM(db.bayar_amount)
                FROM detail_bayar db
                INNER JOIN head_bayar hb
                    ON hb.bayar_no = db.bayar_no
                WHERE hb.customer_id = ?
                  AND hb.bayar_date < ?
            ), 0)

            -

            COALESCE((
                SELECT
                    SUM(hri.return_amount)
                FROM head_retur_invoice hri
                WHERE hri.customer_id = ?
                  AND hri.return_date < ?
                  AND LOWER(
                        COALESCE(hri.status, 'Open')
                      ) <> 'cancelled'
            ), 0)
        ) AS saldo_awal
";

$stmtSaldo = mysqli_prepare($conn, $sqlSaldo);

if (!$stmtSaldo) {
    die('SQL Error Saldo Awal: ' . h(mysqli_error($conn)));
}

mysqli_stmt_bind_param(
    $stmtSaldo,
    'ssssss',
    $customer_id,
    $start_date,
    $customer_id,
    $start_date,
    $customer_id,
    $start_date
);

mysqli_stmt_execute($stmtSaldo);

$resSaldo = mysqli_stmt_get_result($stmtSaldo);

$rowSaldo = mysqli_fetch_assoc($resSaldo);

$saldo_awal = (float)($rowSaldo['saldo_awal'] ?? 0);

mysqli_stmt_close($stmtSaldo);

/*
 * TRANSAKSI PERIODE:
 * 1. Invoice / Penjualan
 * 2. Retur
 * 3. Pembayaran
 */
$sqlRows = "
    SELECT
        trans_date,
        shipping_no,
        return_id,
        bayar_no,
        penjualan,
        retur,
        pembayaran,
        sort_order,
        invoice_no_sort
    FROM
    (
        /*
         * INVOICE / PENJUALAN
         */
        SELECT
            hi.invoice_date AS trans_date,
            COALESCE(di.shipping_no, '') AS shipping_no,
            '' AS return_id,
            '' AS bayar_no,

            CASE
                WHEN COALESCE(hi.piutang, 0) > 0
                    THEN COALESCE(hi.piutang, 0)

                WHEN COALESCE(hi.payment_balance, 0) > 0
                    THEN COALESCE(hi.payment_balance, 0)

                ELSE COALESCE(hi.grand_total, 0)
            END AS penjualan,

            0 AS retur,
            0 AS pembayaran,
            1 AS sort_order,
            hi.invoice_no AS invoice_no_sort

        FROM head_invoice hi

        LEFT JOIN (
            SELECT
                invoice_no,
                GROUP_CONCAT(
                    DISTINCT shipping_no
                    ORDER BY shipping_no
                    SEPARATOR ', '
                ) AS shipping_no
            FROM det_invoice
            GROUP BY invoice_no
        ) di
            ON di.invoice_no = hi.invoice_no

        WHERE hi.customer_id = ?
          AND hi.invoice_date BETWEEN ? AND ?

        UNION ALL

        /*
         * RETUR INVOICE / SALES RETURN
         */
        SELECT
            hri.return_date AS trans_date,
            COALESCE(hri.shipping_no, '') AS shipping_no,
            hri.return_id AS return_id,
            '' AS bayar_no,
            0 AS penjualan,
            COALESCE(hri.return_amount, 0) AS retur,
            0 AS pembayaran,
            2 AS sort_order,
            COALESCE(hri.invoice_no, '') AS invoice_no_sort

        FROM head_retur_invoice hri

        WHERE hri.customer_id = ?
          AND hri.return_date BETWEEN ? AND ?
          AND LOWER(
                COALESCE(hri.status, 'Open')
              ) <> 'cancelled'

        UNION ALL

        /*
         * PEMBAYARAN
         */
        SELECT
            hb.bayar_date AS trans_date,
            COALESCE(
                NULLIF(db.shipping_no, ''),
                di.shipping_no,
                ''
            ) AS shipping_no,
            '' AS return_id,
            hb.bayar_no AS bayar_no,
            0 AS penjualan,
            0 AS retur,
            COALESCE(db.bayar_amount, 0) AS pembayaran,
            3 AS sort_order,
            COALESCE(db.invoice_no, '') AS invoice_no_sort

        FROM head_bayar hb

        INNER JOIN detail_bayar db
            ON db.bayar_no = hb.bayar_no

        LEFT JOIN (
            SELECT
                invoice_no,
                GROUP_CONCAT(
                    DISTINCT shipping_no
                    ORDER BY shipping_no
                    SEPARATOR ', '
                ) AS shipping_no
            FROM det_invoice
            GROUP BY invoice_no
        ) di
            ON di.invoice_no = db.invoice_no

        WHERE hb.customer_id = ?
          AND hb.bayar_date BETWEEN ? AND ?
    ) x

    ORDER BY
        trans_date ASC,
        sort_order ASC,
        shipping_no ASC,
        invoice_no_sort ASC,
        return_id ASC,
        bayar_no ASC
";

$stmtRows = mysqli_prepare($conn, $sqlRows);

if (!$stmtRows) {
    die('SQL Error Detail Kartu Piutang: ' . h(mysqli_error($conn)));
}

mysqli_stmt_bind_param(
    $stmtRows,
    'sssssssss',
    $customer_id,
    $start_date,
    $end_date,

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
$total_retur = 0;
$total_pembayaran = 0;

$runningSaldo = $saldo_awal;

while ($row = mysqli_fetch_assoc($resRows)) {
    $penjualan = (float)($row['penjualan'] ?? 0);
    $retur = (float)($row['retur'] ?? 0);
    $pembayaran = (float)($row['pembayaran'] ?? 0);

    $runningSaldo +=
        $penjualan
        - $retur
        - $pembayaran;

    $row['sisa'] = $runningSaldo;

    $total_penjualan += $penjualan;
    $total_retur += $retur;
    $total_pembayaran += $pembayaran;

    $rows[] = $row;
}

mysqli_stmt_close($stmtRows);

$saldo_akhir =
    $saldo_awal
    + $total_penjualan
    - $total_retur
    - $total_pembayaran;

$tgl_cetak = date('d-M-Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Kartu Piutang -
        <?= h($customerData['customer_id']) ?>
    </title>

    <style>
        @page {
            size: A4 portrait;
            margin: 8mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            font-size: 9px;
            color: #000;
            margin: 0;
            padding: 16px;
            background: #eef1f5;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
        }

        .toolbar {
            width: 100%;
            max-width: 210mm;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 12px;
        }

        .btn-action {
            border: none;
            border-radius: 6px;
            color: #fff;
            padding: 10px 20px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 2px 6px rgba(0,0,0,.2);
        }

        .btn-back {
            background: #6c757d;
        }

        .btn-print {
            background: #2b5797;
        }

        .btn-action:hover {
            filter: brightness(.92);
            text-decoration: none;
        }

        .print-wrap {
            width: 100%;
            max-width: 210mm;
            margin: 0 auto;
            padding: 18px 18px;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,.1);
            border-radius: 4px;
        }

        .title {
            text-align: center;
            font-size: 16px;
            font-weight: bold;
            letter-spacing: .5px;
            margin-bottom: 4px;
        }

        .period {
            text-align: center;
            font-size: 11px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .top-info {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 9px;
        }

        .top-info td {
            padding: 3px 0;
            vertical-align: top;
        }

        .top-info .label {
            width: 72px;
            font-weight: bold;
        }

        .top-info .sep {
            width: 10px;
            text-align: center;
        }

        .top-info .right-label {
            width: 60px;
            font-weight: bold;
        }

        .detail-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 7.5px;
            border: 1px solid #000;
        }

        .detail-table th {
            border: 1px solid #000;
            padding: 4px 2px;
            text-align: center;
            font-weight: bold;
            background: #f2f2f2;
            white-space: nowrap;
        }

        .detail-table tbody td {
            padding: 3px 2px;
            vertical-align: middle;
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            border-top: none;
            border-bottom: none;
        }

        .detail-table tbody tr.return-row td {
            background: #fff0f0;
        }

        .detail-table .summary-row td {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            font-weight: bold;
            background: #f2f2f2;
            padding: 4px 2px;
        }

        .money-cell {
            text-align: right;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
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

        .text-retur {
            color: #a00000;
            font-weight: bold;
        }

        .no-data {
            text-align: center;
            padding: 12px;
            color: #555;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
                display: block;
                min-height: auto;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .no-print {
                display: none !important;
            }

            .print-wrap {
                max-width: 100%;
                margin: 0;
                padding: 4px 5px;
                box-shadow: none;
                border-radius: 0;
            }
        }

        @media screen and (max-width: 768px) {
            body {
                padding: 8px;
            }

            .toolbar {
                justify-content: center;
            }

            .btn-action {
                flex: 1;
                text-align: center;
            }

            .print-wrap {
                overflow-x: auto;
                padding: 12px 10px;
            }
        }
    </style>
</head>

<body>

<div class="toolbar no-print">
    <a
        href="../../index.php?page=kartu_piutang"
        class="btn-action btn-back"
    >
        KEMBALI
    </a>

    <button
        type="button"
        class="btn-action btn-print"
        onclick="window.print()"
    >
        CETAK
    </button>
</div>

<div class="print-wrap">
    <div class="title">
        KARTU PIUTANG CP
    </div>

    <div class="period">
        Periode
        <?= h(formatDateDisplay($start_date)) ?>
        s/d
        <?= h(formatDateDisplay($end_date)) ?>
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
                <th style="width:11%;">SALDO AWAL</th>
                <th style="width:8%;">TANGGAL</th>
                <th style="width:15%;">SHIPPING NO.</th>
                <th style="width:11%;">NO. RETUR</th>
                <th style="width:10%;">NO. BAYAR</th>
                <th style="width:11%;">PENJUALAN</th>
                <th style="width:10%;">RETUR</th>
                <th style="width:11%;">PEMBAYARAN</th>
                <th style="width:13%;">SISA</th>
            </tr>
        </thead>

        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td class="money-cell">
                        Rp <?= h(formatMoney($saldo_awal)) ?>
                    </td>

                    <td
                        colspan="7"
                        class="no-data"
                    >
                        Tidak ada data piutang pada periode ini.
                    </td>

                    <td class="money-cell text-bold">
                        Rp <?= h(formatMoney($saldo_akhir)) ?>
                    </td>
                </tr>
            <?php else: ?>

                <?php foreach ($rows as $i => $row): ?>
                    <?php
                    $isReturn =
                        (float)($row['retur'] ?? 0) > 0;
                    ?>

                    <tr class="<?= $isReturn ? 'return-row' : '' ?>">
                        <td class="money-cell">
                            <?= $i === 0
                                ? 'Rp ' . h(formatMoney($saldo_awal))
                                : ''
                            ?>
                        </td>

                        <td class="text-center">
                            <?= h(
                                formatDateDisplay(
                                    $row['trans_date']
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= h($row['shipping_no']) ?>
                        </td>

                        <td class="text-center text-retur">
                            <?= h($row['return_id']) ?>
                        </td>

                        <td class="text-center">
                            <?= h($row['bayar_no']) ?>
                        </td>

                        <td class="money-cell">
                            <?php if ((float)$row['penjualan'] > 0): ?>
                                Rp <?= h(formatMoney($row['penjualan'])) ?>
                            <?php endif; ?>
                        </td>

                        <td class="money-cell text-retur">
                            <?php if ((float)$row['retur'] > 0): ?>
                                - Rp <?= h(formatMoney($row['retur'])) ?>
                            <?php endif; ?>
                        </td>

                        <td class="money-cell">
                            <?php if ((float)$row['pembayaran'] > 0): ?>
                                Rp <?= h(formatMoney($row['pembayaran'])) ?>
                            <?php endif; ?>
                        </td>

                        <td class="money-cell text-bold">
                            Rp <?= h(formatMoney($row['sisa'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>

            <tr class="summary-row">
                <td
                    colspan="5"
                    class="text-right"
                >
                    TOTAL
                </td>

                <td class="money-cell">
                    Rp <?= h(formatMoney($total_penjualan)) ?>
                </td>

                <td class="money-cell text-retur">
                    - Rp <?= h(formatMoney($total_retur)) ?>
                </td>

                <td class="money-cell">
                    Rp <?= h(formatMoney($total_pembayaran)) ?>
                </td>

                <td class="money-cell">
                    Rp <?= h(formatMoney($saldo_akhir)) ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

</body>
</html>
