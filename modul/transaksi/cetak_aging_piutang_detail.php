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
        COALESCE(di.shipping_no, '') AS shipping_no,
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
    ) di ON di.invoice_no = hi.invoice_no
    $whereInvoice
    GROUP BY
        hi.invoice_no,
        di.shipping_no,
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

    if ($sisaPiutang <= 0.0001) {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?></title>
    <style>
        @page {
            size: 330mm 215mm;
            margin: 7mm;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            color: #000;
            background: #eef1f5;
            padding: 16px;
        }

        /* Toolbar Tombol Cetak */
        .toolbar {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }

        .btn-print {
            border: none;
            border-radius: 6px;
            background: #2b5797;
            color: #fff;
            padding: 12px 30px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
            transition: 0.2s;
        }

        .btn-print:hover {
            background: #1a3f6a;
            transform: scale(1.02);
        }

        /* Container Scroll */
        .screen-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            padding-bottom: 12px;
            -webkit-overflow-scrolling: touch;
        }

        .screen-scroll::-webkit-scrollbar {
            height: 10px;
        }
        .screen-scroll::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }
        .screen-scroll::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 5px;
        }
        .screen-scroll::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        .print-wrap {
            width: 1480px;
            min-width: 1480px;
            margin: 0 auto;
            padding: 20px 24px;
            background: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,0.1);
            border-radius: 4px;
        }

        .title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 2px;
        }

        .subtitle {
            text-align: center;
            font-size: 13px;
            margin-bottom: 2px;
        }

        .printed {
            text-align: right;
            font-size: 11px;
            margin-bottom: 8px;
            color: #555;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }

        th {
            border: 1px solid #000;
            background: #e8e8e8;
            padding: 6px 4px;
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
        }

        td {
            border-left: 1px solid #000;
            border-right: 1px solid #000;
            padding: 5px 4px;
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
            background: #e8e8e8;
            font-weight: bold;
            padding: 6px 4px;
        }

        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }

        .money-cell {
            text-align: right;
        }

        .label-cell {
            white-space: normal;
        }

        .customer-cell {
            white-space: normal;
            font-weight: bold;
        }

        /* Print Styles */
        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .no-print {
                display: none !important;
            }

            .screen-scroll {
                overflow: visible !important;
                padding: 0;
            }

            .screen-scroll::-webkit-scrollbar {
                display: none;
            }

            .print-wrap {
                width: 100%;
                min-width: 0;
                margin: 0;
                padding: 8px 10px;
                box-shadow: none;
                border-radius: 0;
            }

            .title {
                font-size: 15px;
            }
            .subtitle {
                font-size: 10px;
            }
            .printed {
                font-size: 8.5px;
            }

            table {
                font-size: 7.4px;
            }

            th {
                padding: 3px 2px;
                background: #f2f2f2 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            td {
                padding: 2.5px 2px;
            }

            tfoot td {
                padding: 3px 2px;
                background: #f2f2f2 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        /* Mobile */
        @media screen and (max-width: 768px) {
            body {
                padding: 8px;
            }

            .toolbar {
                justify-content: center;
            }

            .btn-print {
                width: 100%;
                padding: 14px;
                font-size: 16px;
            }

            .print-wrap {
                padding: 12px;
                min-width: 1200px;
                width: 1200px;
            }
        }
    </style>
</head>
<body>

<!-- Toolbar Cetak -->
<div class="toolbar no-print">
    <button type="button" class="btn-print" onclick="window.print()">
        🖨️ CETAK LAPORAN
    </button>
</div>

<!-- Container Scroll -->
<div class="screen-scroll">
    <div class="print-wrap">
        <div class="title"><?= h($title) ?></div>
        <div class="subtitle"><?= h($subtitle) ?></div>
        <div class="printed">Dicetak: <?= h($printedAt) ?></div>

        <table>
            <thead>
                <tr>
                    <th style="width:28px;">No</th>
                    <th style="width:50px;">Grup</th>
                    <th style="width:55px;">Kota</th>
                    <th style="width:55px;">Cust ID</th>
                    <th style="width:130px;">Nama Customer</th>
                    <th style="width:85px;">Shipping No.</th>
                    <th style="width:62px;">Tgl Inv</th>
                    <th style="width:62px;">Jth Tempo</th>
                    <th style="width:38px;">Umur</th>
                    <th style="width:78px;">Nilai Invoice</th>
                    <th style="width:78px;">Pembayaran</th>
                    <th style="width:78px;">Sisa Piutang</th>
                    <th style="width:72px;">Belum JT</th>
                    <th style="width:68px;">1-30</th>
                    <th style="width:68px;">31-60</th>
                    <th style="width:68px;">61-90</th>
                    <th style="width:68px;">Lebih</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="17" class="text-center" style="padding:20px; font-size:13px; color:#999;">
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
                            <td class="text-center" style="font-size:9px;"><?= h($row['shipping_no']) ?></td>
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
    </div>
</div>

</body>
</html>