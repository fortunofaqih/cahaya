<?php
// modul/transaksi/cetak_aging_piutang_global.php

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
    if (empty($date) || $date === '0000-00-00') return '';
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

function initRow($label) {
    return [
        'label' => $label,
        'saldo_awal' => 0,
        'penjualan' => 0,
        'pembayaran' => 0,
        'titip' => 0,
        'saldo_akhir' => 0,
        'b_1_30' => 0,
        'b_31_60' => 0,
        'b_61_90' => 0,
        'b_lebih' => 0,
        'belum_jatuh_tempo' => 0,
    ];
}

function getGroupLabel($row, $filterBy) {
    $city = trim((string)($row['city'] ?? ''));
    $customerId = trim((string)($row['customer_id'] ?? ''));
    $customerName = trim((string)($row['customer_name'] ?? ''));

    if ($filterBy === 'pelanggan') {
        return trim($customerId . ' - ' . $customerName);
    }

    // Untuk report global, kolom Daerah/Kota dikelompokkan berdasarkan city.
    // Filter "grup" tetap memakai m_customer.area_code pada WHERE,
    // tetapi hasil baris report ditampilkan dan dijumlahkan per city.
    return $city !== '' ? $city : 'TANPA KOTA';
}

function getLabelTitle($filterBy) {
    if ($filterBy === 'kota') return 'Kota';
    if ($filterBy === 'pelanggan') return 'Pelanggan';
    return 'Daerah';
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

$labelColumn = getLabelTitle($filterBy);

$titleFilter = 'Semua Grup';
if ($filterBy === 'grup') $titleFilter = 'Grup: ' . $filterValue;
if ($filterBy === 'kota') $titleFilter = 'Kota: ' . $filterValue;
if ($filterBy === 'pelanggan') $titleFilter = 'Pelanggan: ' . $filterValue;

$title = 'AGING PIUTANG - GLOBAL - CP';
$subtitle = 'Periode ' . getMonthName($bulan) . ' ' . $tahun . ' | ' . $titleFilter;
$printedAt = date('d-M-Y');

$whereInvoice = " WHERE hi.invoice_date <= ? ";
$paramsInvoice = [$startDate, $startDate, $endDate, $endDate, $endDate];
$typesInvoice = "sssss";

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereInvoice .= " AND c.area_code = ? ";
    $paramsInvoice[] = $filterValue;
    $typesInvoice .= "s";
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereInvoice .= " AND COALESCE(NULLIF(c.city, ''), NULLIF(hi.customer_city, '')) = ? ";
    $paramsInvoice[] = $filterValue;
    $typesInvoice .= "s";
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $whereInvoice .= " AND hi.customer_id = ? ";
    $paramsInvoice[] = $filterValue;
    $typesInvoice .= "s";
}

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

$sqlInvoice = "
    SELECT
        hi.invoice_no,
        hi.invoice_date,
        DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days, 0) DAY) AS due_date,
        hi.customer_id,
        hi.customer_name,
        hi.customer_city,
        c.area_code,
        COALESCE(NULLIF(c.city, ''), NULLIF(hi.customer_city, ''), '') AS city,
        $invoiceAmountExpr AS invoice_amount,

        COALESCE((
            SELECT SUM(db1.bayar_amount)
            FROM detail_bayar db1
            INNER JOIN head_bayar hb1 ON hb1.bayar_no = db1.bayar_no
            WHERE db1.invoice_no = hi.invoice_no
              AND hb1.bayar_date < ?
        ), 0) AS paid_before,

        COALESCE((
            SELECT SUM(db2.bayar_amount)
            FROM detail_bayar db2
            INNER JOIN head_bayar hb2 ON hb2.bayar_no = db2.bayar_no
            WHERE db2.invoice_no = hi.invoice_no
              AND hb2.bayar_date BETWEEN ? AND ?
        ), 0) AS paid_period,

        COALESCE((
            SELECT SUM(db3.bayar_amount)
            FROM detail_bayar db3
            INNER JOIN head_bayar hb3 ON hb3.bayar_no = db3.bayar_no
            WHERE db3.invoice_no = hi.invoice_no
              AND hb3.bayar_date <= ?
        ), 0) AS paid_until_end

    FROM head_invoice hi
    LEFT JOIN m_customer c ON c.customer_id = hi.customer_id
    $whereInvoice
    ORDER BY hi.customer_id ASC, hi.invoice_date ASC, hi.invoice_no ASC
";

$stmtInvoice = mysqli_prepare($conn, $sqlInvoice);
mysqli_stmt_bind_param($stmtInvoice, $typesInvoice, ...$paramsInvoice);
mysqli_stmt_execute($stmtInvoice);
$resInvoice = mysqli_stmt_get_result($stmtInvoice);

$rows = [];

while ($inv = mysqli_fetch_assoc($resInvoice)) {
    $groupLabel = getGroupLabel($inv, $filterBy);

    if (!isset($rows[$groupLabel])) {
        $rows[$groupLabel] = initRow($groupLabel);
    }

    $invoiceDate = $inv['invoice_date'];
    $dueDate = $inv['due_date'];

    $invoiceAmount = (float)$inv['invoice_amount'];
    $paidBefore = (float)$inv['paid_before'];
    $paidPeriod = (float)$inv['paid_period'];
    $paidUntilEnd = (float)$inv['paid_until_end'];

    $outstandingBefore = max($invoiceAmount - $paidBefore, 0);
    $outstandingEnd = max($invoiceAmount - $paidUntilEnd, 0);

    if ($invoiceDate < $startDate) {
        $rows[$groupLabel]['saldo_awal'] += $outstandingBefore;
    }

    if ($invoiceDate >= $startDate && $invoiceDate <= $endDate) {
        $rows[$groupLabel]['penjualan'] += $invoiceAmount;
    }

    $rows[$groupLabel]['pembayaran'] += $paidPeriod;
    $rows[$groupLabel]['saldo_akhir'] += $outstandingEnd;

    if ($outstandingEnd > 0) {
        $ageDays = (int)floor((strtotime($asOfDate) - strtotime($dueDate)) / 86400);

        if ($ageDays <= 0) {
            $rows[$groupLabel]['belum_jatuh_tempo'] += $outstandingEnd;
        } elseif ($ageDays <= 30) {
            $rows[$groupLabel]['b_1_30'] += $outstandingEnd;
        } elseif ($ageDays <= 60) {
            $rows[$groupLabel]['b_31_60'] += $outstandingEnd;
        } elseif ($ageDays <= 90) {
            $rows[$groupLabel]['b_61_90'] += $outstandingEnd;
        } else {
            $rows[$groupLabel]['b_lebih'] += $outstandingEnd;
        }
    }
}
mysqli_stmt_close($stmtInvoice);

$whereTitip = " WHERE ht.titip_date BETWEEN ? AND ? ";
$paramsTitip = [$startDate, $endDate];
$typesTitip = "ss";

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereTitip .= " AND c.area_code = ? ";
    $paramsTitip[] = $filterValue;
    $typesTitip .= "s";
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereTitip .= " AND COALESCE(NULLIF(c.city, ''), NULLIF(ht.customer_city, '')) = ? ";
    $paramsTitip[] = $filterValue;
    $typesTitip .= "s";
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $whereTitip .= " AND ht.customer_id = ? ";
    $paramsTitip[] = $filterValue;
    $typesTitip .= "s";
}

$sqlTitip = "
    SELECT
        ht.customer_id,
        ht.customer_name,
        ht.customer_city,
        c.area_code,
        COALESCE(NULLIF(c.city, ''), NULLIF(ht.customer_city, ''), '') AS city,
        COALESCE(SUM(ht.total_titip), 0) AS total_titip
    FROM head_titip ht
    LEFT JOIN m_customer c ON c.customer_id = ht.customer_id
    $whereTitip
    GROUP BY
        ht.customer_id,
        ht.customer_name,
        ht.customer_city,
        c.area_code,
        c.city
";

$stmtTitip = mysqli_prepare($conn, $sqlTitip);

if (!$stmtTitip) {
    die("SQL TITIP ERROR: " . mysqli_error($conn) . "<br><pre>" . htmlspecialchars($sqlTitip) . "</pre>");
}

mysqli_stmt_bind_param($stmtTitip, $typesTitip, ...$paramsTitip);
mysqli_stmt_execute($stmtTitip);
$resTitip = mysqli_stmt_get_result($stmtTitip);

while ($titip = mysqli_fetch_assoc($resTitip)) {
    $groupLabel = getGroupLabel($titip, $filterBy);

    if (!isset($rows[$groupLabel])) {
        $rows[$groupLabel] = initRow($groupLabel);
    }

    $rows[$groupLabel]['titip'] += (float)$titip['total_titip'];
}
mysqli_stmt_close($stmtTitip);

ksort($rows);

$grand = initRow('GRAND TOTAL');

foreach ($rows as $row) {
    foreach ($grand as $key => $value) {
        if ($key !== 'label') {
            $grand[$key] += (float)$row[$key];
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= h($title) ?></title>
    <style>
        @page {
            size: 330mm 215mm;
            margin: 8mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 9px;
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
            font-size: 8.5px;
            margin-bottom: 5px;
        }

        .info-line {
            font-size: 9px;
            margin-bottom: 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 8px;
        }

        th {
            border: 1px solid #000;
            background: #f2f2f2;
            padding: 4px 3px;
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
        }

        td {
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            padding: 3px 3px;
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
            padding: 4px 3px;
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
            font-weight: bold;
        }

        .small-note {
            margin-top: 6px;
            font-size: 8px;
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
        Jenis Report: <b>Global</b>
    </div>-->

    <table>
        <thead>
            <tr>
                <th style="width:28px;">No</th>
                <th style="width:120px;"><?= h($labelColumn) ?></th>
                <th style="width:74px;">Saldo Awal</th>
                <th style="width:74px;">Penjualan</th>
                <th style="width:74px;">Pembayaran</th>
                <th style="width:74px;">Titip</th>
                <th style="width:74px;">Saldo Akhir</th>
                <th style="width:68px;">1 - 30 Hari</th>
                <th style="width:68px;">31 - 60 Hari</th>
                <th style="width:68px;">61 - 90 Hari</th>
                <th style="width:68px;">Lebih</th>
                <th style="width:78px;">Belum Jatuh Tempo</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="12" class="text-center" style="padding:12px;">
                        Tidak ada data aging piutang untuk filter ini.
                    </td>
                </tr>
            <?php else: ?>
                <?php $no = 1; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-center"><?= $no++ ?></td>
                        <td class="label-cell"><?= h($row['label']) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['saldo_awal'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['penjualan'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['pembayaran'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['titip'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['saldo_akhir'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['b_1_30'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['b_31_60'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['b_61_90'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['b_lebih'])) ?></td>
                        <td class="money-cell"><?= h(formatMoney($row['belum_jatuh_tempo'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2" class="text-right">GRAND TOTAL</td>
                <td class="money-cell"><?= h(formatMoney($grand['saldo_awal'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['penjualan'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['pembayaran'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['titip'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['saldo_akhir'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['b_1_30'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['b_31_60'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['b_61_90'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['b_lebih'])) ?></td>
                <td class="money-cell"><?= h(formatMoney($grand['belum_jatuh_tempo'])) ?></td>
            </tr>
        </tfoot>
    </table>

    <!--<div class="small-note">
        Catatan: Kolom Titip adalah uang titipan yang diterima pada periode laporan. Titip baru mengurangi piutang jika sudah dipakai pada transaksi pembayaran.
        Bucket aging dihitung dari saldo invoice yang masih outstanding sampai tanggal cut off.
    </div>-->
</div>

</body>
</html>