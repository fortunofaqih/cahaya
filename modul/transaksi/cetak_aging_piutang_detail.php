<?php
// modul/transaksi/cetak_aging_piutang_detail.php

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

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function formatDateIndo($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function getMonthName($month) {
    $names = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember'
    ];

    return $names[(int)$month] ?? '';
}

function getTitleFilter($filterBy, $filterValue) {
    if ($filterBy === 'grup') {
        return 'Grup: ' . $filterValue;
    }

    if ($filterBy === 'kota') {
        return 'Kota: ' . $filterValue;
    }

    if ($filterBy === 'pelanggan') {
        return 'Pelanggan: ' . $filterValue;
    }

    return 'Semua Grup';
}

function initGrandTotal() {
    return [
        'invoice_amount' => 0,
        'pembayaran' => 0,
        'sisa_piutang' => 0,
        'belum_jatuh_tempo' => 0,
        'b_1_30' => 0,
        'b_31_60' => 0,
        'b_61_90' => 0,
        'b_lebih' => 0,
    ];
}

$bulan = (int)($_GET['bulan'] ?? date('n'));
$tahun = (int)($_GET['tahun'] ?? date('Y'));
$filterBy = $_GET['filter_by'] ?? 'semua';
$filterValue = trim((string)($_GET['filter_value'] ?? ''));

if ($bulan < 1 || $bulan > 12) {
    $bulan = (int)date('n');
}

if ($tahun < 2020 || $tahun > ((int)date('Y') + 1)) {
    $tahun = (int)date('Y');
}

$startDate = sprintf('%04d-%02d-01', $tahun, $bulan);
$endDate = date('Y-m-t', strtotime($startDate));
$asOfDate = $endDate;

$title = 'AGING PIUTANG - DETAIL';
$subtitle = 'Periode ' . getMonthName($bulan) . ' ' . $tahun . ' | ' . getTitleFilter($filterBy, $filterValue);
$printedAt = date('d-M-Y H:i:s');

$invoiceAmountExpr = "
    GREATEST(
        CASE
            WHEN COALESCE(hi.piutang, 0) > 0 THEN COALESCE(hi.piutang, 0)
            WHEN COALESCE(hi.grand_total, 0) > 0 THEN
                (
                    COALESCE(hi.grand_total, 0)
                    - COALESCE(hi.down_payment, 0)
                    - COALESCE(hi.titip_applied, 0)
                )
            ELSE
                (
                    COALESCE(hi.subtotal, 0)
                    - COALESCE(hi.down_payment, 0)
                    - COALESCE(hi.titip_applied, 0)
                )
        END,
        0
    )
";

$whereInvoice = " WHERE hi.invoice_date <= ? ";
$params = [$endDate, $endDate];
$types = "ss";

/*
    Parameter order:
    1. paid_until_cutoff: hb.bayar_date <= ?
    2. WHERE hi.invoice_date <= ?
*/

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereInvoice .= " AND COALESCE(c.area_code, '') = ? ";
    $params[] = $filterValue;
    $types .= "s";
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereInvoice .= " AND COALESCE(NULLIF(c.city, ''), NULLIF(hi.customer_city, '')) = ? ";
    $params[] = $filterValue;
    $types .= "s";
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $whereInvoice .= " AND hi.customer_id = ? ";
    $params[] = $filterValue;
    $types .= "s";
}

$sql = "
    SELECT
        hi.invoice_no,
        hi.invoice_date,
        DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days, 0) DAY) AS due_date,
        hi.days,
        hi.customer_id,
        hi.customer_name,
        hi.customer_city,
        COALESCE(c.area_code, '') AS area_code,
        COALESCE(NULLIF(c.city, ''), NULLIF(hi.customer_city, ''), '') AS city,
        $invoiceAmountExpr AS invoice_amount,
        COALESCE((
            SELECT SUM(db.bayar_amount)
            FROM detail_bayar db
            INNER JOIN head_bayar hb ON hb.bayar_no = db.bayar_no
            WHERE db.invoice_no = hi.invoice_no
              AND hb.bayar_date <= ?
        ), 0) AS pembayaran_cutoff
    FROM head_invoice hi
    LEFT JOIN m_customer c ON c.customer_id = hi.customer_id
    $whereInvoice
    GROUP BY
        hi.invoice_no,
        hi.invoice_date,
        hi.days,
        hi.customer_id,
        hi.customer_name,
        hi.customer_city,
        c.area_code,
        c.city,
        hi.piutang,
        hi.grand_total,
        hi.subtotal,
        hi.down_payment,
        hi.titip_applied
    ORDER BY
        c.area_code ASC,
        city ASC,
        hi.customer_name ASC,
        hi.invoice_date ASC,
        hi.invoice_no ASC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("SQL DETAIL AGING ERROR: " . mysqli_error($conn) . "<br><pre>" . h($sql) . "</pre>");
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$rows = [];
$grand = initGrandTotal();

while ($row = mysqli_fetch_assoc($res)) {
    $invoiceAmount = (float)($row['invoice_amount'] ?? 0);
    $pembayaran = (float)($row['pembayaran_cutoff'] ?? 0);
    $sisaPiutang = $invoiceAmount - $pembayaran;

    if ($sisaPiutang <= 0.0001) { // HAPUS JIKA INVOICE LUNAS INGIN DITAMPILKAN
        continue;
    }

    $dueDate = $row['due_date'];
    $ageDays = 0;

    if (!empty($dueDate) && $dueDate !== '0000-00-00') {
        $ageDays = (int)floor((strtotime($asOfDate) - strtotime($dueDate)) / 86400);
    }

    $belumJatuhTempo = 0;
    $b1_30 = 0;
    $b31_60 = 0;
    $b61_90 = 0;
    $bLebih = 0;

    if ($ageDays <= 0) {
        $belumJatuhTempo = $sisaPiutang;
    } elseif ($ageDays <= 30) {
        $b1_30 = $sisaPiutang;
    } elseif ($ageDays <= 60) {
        $b31_60 = $sisaPiutang;
    } elseif ($ageDays <= 90) {
        $b61_90 = $sisaPiutang;
    } else {
        $bLebih = $sisaPiutang;
    }

    $row['invoice_amount_calc'] = $invoiceAmount;
    $row['pembayaran_calc'] = $pembayaran;
    $row['sisa_piutang'] = $sisaPiutang;
    $row['age_days'] = $ageDays;
    $row['belum_jatuh_tempo'] = $belumJatuhTempo;
    $row['b_1_30'] = $b1_30;
    $row['b_31_60'] = $b31_60;
    $row['b_61_90'] = $b61_90;
    $row['b_lebih'] = $bLebih;

    $rows[] = $row;

    $grand['invoice_amount'] += $invoiceAmount;
    $grand['pembayaran'] += $pembayaran;
    $grand['sisa_piutang'] += $sisaPiutang;
    $grand['belum_jatuh_tempo'] += $belumJatuhTempo;
    $grand['b_1_30'] += $b1_30;
    $grand['b_31_60'] += $b31_60;
    $grand['b_61_90'] += $b61_90;
    $grand['b_lebih'] += $bLebih;
}

mysqli_stmt_close($stmt);
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= h($title) ?></title>
    <style>
        @page {
            size: 330mm 215mm;
            margin: 7mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8px;
            color: #000;
            margin: 0;
            padding: 0;
            background: #fff;
        }

        .print-wrap {
            width: 100%;
        }

        .title {
            text-align: center;
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .subtitle {
            text-align: center;
            font-size: 10px;
            margin-bottom: 3px;
        }

        .printed {
            text-align: right;
            font-size: 8px;
            margin-bottom: 5px;
        }

        .info-line {
            font-size: 8.5px;
            margin-bottom: 5px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 7.4px;
        }

        th {
            border: 1px solid #000;
            background: #f2f2f2;
            padding: 3px 2px;
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
        }

        td {
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            padding: 2.5px 2px;
            vertical-align: middle;
            white-space: nowrap;
        }

        tbody tr:first-child td {
            border-top: 1px solid #000;
        }

        tbody tr:last-child td {
            border-bottom: 1px solid #000;
        }

        tfoot td {
            border: 1px solid #000;
            background: #f2f2f2;
            font-weight: bold;
            padding: 3px 2px;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .money-cell {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .label-cell {
            white-space: normal;
        }

        .customer-cell {
            white-space: normal;
            font-weight: bold;
        }

        .small-note {
            margin-top: 5px;
            font-size: 7.8px;
            line-height: 1.4;
        }

        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body onload="window.print()">

<div class="print-wrap">
    <div class="title"><?= h($title) ?></div>
    <div class="subtitle"><?= h($subtitle) ?></div>
    <div class="printed">Dicetak: <?= h($printedAt) ?></div>

    <!--<div class="info-line">
        Tanggal Cut Off: <b><?= h(formatDateIndo($asOfDate)) ?></b> |
        Basis Aging: <b>Jatuh Tempo Invoice</b> |
        Due Date: <b>Invoice Date + Days</b> |
        Jenis Report: <b>Detail</b>
    </div>-->

    <table>
        <thead>
            <tr>
                <th style="width:24px;">No</th>
                <th style="width:48px;">Grup</th>
                <th style="width:55px;">Kota</th>
                <th style="width:52px;">Cust ID</th>
                <th style="width:118px;">Nama Customer</th>
                <th style="width:70px;">No. Invoice</th>
                <th style="width:55px;">Tgl Inv</th>
                <th style="width:55px;">Jth Tempo</th>
                <th style="width:38px;">Umur</th>
                <th style="width:70px;">Nilai Invoice</th>
                <th style="width:70px;">Pembayaran</th>
                <th style="width:70px;">Sisa Piutang</th>
                <th style="width:66px;">Belum JT</th>
                <th style="width:62px;">1-30</th>
                <th style="width:62px;">31-60</th>
                <th style="width:62px;">61-90</th>
                <th style="width:62px;">Lebih</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="17" class="text-center" style="padding:12px;">
                        Tidak ada data aging piutang detail untuk filter ini.
                    </td>
                </tr>
            <?php else: ?>
                <?php $no = 1; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="label-cell"><?= h($row['area_code'] !== '' ? $row['area_code'] : 'TANPA GRUP') ?></td>
                        <td class="label-cell"><?= h($row['city'] !== '' ? $row['city'] : $row['customer_city']) ?></td>
                        <td class="text-center"><?= h($row['customer_id']) ?></td>
                        <td class="customer-cell"><?= h($row['customer_name']) ?></td>
                        <td class="text-center"><?= h($row['invoice_no']) ?></td>
                        <td class="text-center"><?= h(formatDateIndo($row['invoice_date'])) ?></td>
                        <td class="text-center"><?= h(formatDateIndo($row['due_date'])) ?></td>
                        <td class="text-center"><?= h($row['age_days']) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['invoice_amount_calc'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['pembayaran_calc'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['sisa_piutang'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['belum_jatuh_tempo'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['b_1_30'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['b_31_60'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['b_61_90'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['b_lebih'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="9" class="text-right">GRAND TOTAL</td>
                <td class="money-cell"><?= h(formatMoney($grand['invoice_amount'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['pembayaran'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['sisa_piutang'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['belum_jatuh_tempo'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['b_1_30'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['b_31_60'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['b_61_90'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['b_lebih'])) ?></td>
            </tr>
        </tfoot>
    </table>

    <!--<div class="small-note">
        Catatan: Laporan ini hanya menampilkan invoice yang masih memiliki sisa piutang sampai tanggal cut off.
        Nilai invoice tidak memakai payment_balance. Formula memakai piutang atau grand_total/subtotal dikurangi down payment dan titip applied.
    </div>-->
</div>

</body>
</html>