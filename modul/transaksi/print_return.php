<?php
// modul/transaksi/print_return.php
// Format cetak Retur Invoice / Kredit Nota
// Tampilan browser dan print mengikuti pola cetak_slip_without_uom_default.php
// Area nota: setengah F4 / 21.5 cm x 16.5 cm di pojok kiri atas.

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

$returnId = isset($_GET['return_id'])
    ? trim((string)$_GET['return_id'])
    : '';

if ($returnId === '') {
    die('Return ID tidak ditemukan!');
}

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function safeText($value): string
{
    return trim((string)($value ?? ''));
}

function fmtNumber($number, int $decimals = 2): string
{
    $number = (float)$number;

    if (abs($number) < 0.000001) {
        return '';
    }

    $formatted = number_format(
        $number,
        $decimals,
        ',',
        '.'
    );

    return rtrim(
        rtrim($formatted, '0'),
        ','
    );
}

function fmtMoney($number): string
{
    return number_format(
        (float)$number,
        2,
        ',',
        '.'
    );
}

function formatReturnDate($date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return '';
    }

    $ts = strtotime($date);

    return $ts
        ? date('d-m-Y', $ts)
        : '';
}

function splitAddressLines(
    $name,
    $address,
    $city
): array {
    $lines = [];

    $name = safeText($name);
    $address = preg_replace(
        '/\s+/',
        ' ',
        safeText($address)
    );
    $city = safeText($city);

    if ($name !== '') {
        $lines[] = $name;
    }

    if ($address !== '') {
        $wrapped = wordwrap(
            $address,
            48,
            "\n",
            false
        );

        foreach (explode("\n", $wrapped) as $line) {
            if (count($lines) >= 4) {
                break;
            }

            $line = trim($line);

            if ($line !== '') {
                $lines[] = $line;
            }
        }
    }

    if ($city !== '' && count($lines) < 4) {
        $lines[] = $city;
    }

    while (count($lines) < 4) {
        $lines[] = '';
    }

    return array_slice($lines, 0, 4);
}

function wrapItemName(
    $text,
    float $maxWidthMm = 63,
    float $fontPt = 9.5,
    int $maxLines = 2
): array {
    $text = trim((string)$text);

    if ($text === '') {
        return [''];
    }

    $charWidthMm =
        $fontPt *
        0.56 *
        0.3528;

    $maxChars = max(
        1,
        (int)floor(
            $maxWidthMm /
            $charWidthMm
        )
    );

    $wrapped = wordwrap(
        $text,
        $maxChars,
        "\n",
        true
    );

    $lines = explode(
        "\n",
        $wrapped
    );

    if (count($lines) > $maxLines) {
        $lines = array_slice(
            $lines,
            0,
            $maxLines
        );

        $lastIndex = $maxLines - 1;
        $cut = max(
            0,
            $maxChars - 3
        );

        $lines[$lastIndex] =
            mb_substr(
                $lines[$lastIndex],
                0,
                $cut
            ) .
            '...';
    }

    return $lines;
}

function buildQtyColumns(array $detail): array
{
    $qtyMain = (float)($detail['return_quantity'] ?? 0);
    $uomMain = strtoupper(
        safeText($detail['uom'] ?? '')
    );

    $qtyPack = (float)($detail['return_quantity_pack'] ?? 0);
    $uomPack = strtoupper(
        safeText($detail['uom_pack'] ?? '')
    );

    $qtyDetail = (float)($detail['return_quantity_detail'] ?? 0);
    $uomDetail = strtoupper(
        safeText($detail['uom_detail'] ?? '')
    );

    $entries = [];

    if ($qtyDetail > 0 && $uomDetail !== '') {
        $entries[$uomDetail] = $qtyDetail;
    }

    if ($qtyPack > 0 && $uomPack !== '') {
        $entries[$uomPack] = $qtyPack;
    }

    if ($qtyMain > 0 && $uomMain !== '') {
        $entries[$uomMain] = $qtyMain;
    }

    $qtyCol1 = isset($entries['BAL'])
        ? fmtNumber($entries['BAL']) . ' BAL'
        : '';

    $qtyCol2 = '';

    foreach (
        [
            'ROLL',
            'ROL',
            'PAK',
            'PACK',
            'LBR',
            'IKT',
            'KRG',
            'DUS'
        ] as $unit
    ) {
        if (isset($entries[$unit])) {
            $qtyCol2 =
                fmtNumber($entries[$unit]) .
                ' ' .
                $unit;
            break;
        }
    }

    $qtyCol3 = '';

    if (isset($entries['KG'])) {
        $qtyCol3 =
            fmtNumber($entries['KG']) .
            ' KG';
    }

    /*
     * Fallback agar qty tetap muncul jika UoM
     * bukan BAL/ROLL/KG.
     */
    if (
        $qtyCol1 === '' &&
        $qtyCol2 === '' &&
        $qtyCol3 === ''
    ) {
        foreach ($entries as $unit => $qty) {
            $qtyCol2 =
                fmtNumber($qty) .
                ' ' .
                $unit;
            break;
        }
    }

    return [
        $qtyCol1,
        $qtyCol2,
        $qtyCol3
    ];
}

/*
 * Header retur
 */
$stmt = mysqli_prepare($conn, "
    SELECT
        h.*
    FROM head_retur_invoice h
    WHERE h.return_id = ?
    LIMIT 1
");

if (!$stmt) {
    die(
        'Prepare header gagal: ' .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $stmt,
    's',
    $returnId
);

mysqli_stmt_execute($stmt);

$headerResult =
    mysqli_stmt_get_result($stmt);

$header =
    mysqli_fetch_assoc($headerResult);

mysqli_stmt_close($stmt);

if (!$header) {
    die('Data Sales Return tidak ditemukan!');
}

/*
 * Detail retur
 */
$stmtDetail = mysqli_prepare($conn, "
    SELECT
        d.*
    FROM detail_retur_invoice d
    WHERE d.return_id = ?
    ORDER BY d.id ASC
");

if (!$stmtDetail) {
    die(
        'Prepare detail gagal: ' .
        mysqli_error($conn)
    );
}

mysqli_stmt_bind_param(
    $stmtDetail,
    's',
    $returnId
);

mysqli_stmt_execute($stmtDetail);

$resultDetail =
    mysqli_stmt_get_result($stmtDetail);

$details = [];

while (
    $row =
    mysqli_fetch_assoc($resultDetail)
) {
    $details[] = $row;
}

mysqli_stmt_close($stmtDetail);

$returnDate =
    formatReturnDate(
        $header['return_date'] ?? ''
    );

$customerLines =
    splitAddressLines(
        $header['customer_name'] ?? '',
        $header['customer_address'] ?? '',
        $header['customer_city'] ?? ''
    );

$reasonText =
    safeText(
        $header['reason_return'] ?? ''
    );

$remarksText =
    safeText(
        $header['remarks_return'] ?? ''
    );

$maxRowSlots = 7;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <title>
        Cetak Sales Return -
        <?= e($returnId) ?>
    </title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            background: #f5f5f5;
            color: #000;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            font-weight: normal;
        }

        .no-print {
            padding: 12px;
            background: #fff;
            border-bottom: 1px solid #ddd;
            text-align: center;
            font-family: Arial, sans-serif;
        }

        .btn {
            display: inline-block;
            margin: 0 4px;
            padding: 8px 16px;
            border: 0;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
        }

        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }

        .btn-success {
            background: #28a745;
            color: #fff;
        }

        .note {
            margin-top: 8px;
            color: #555;
            font-size: 12px;
        }

        /*
         * Halaman fisik F4 portrait.
         * Nota retur menggunakan setengah bagian atas.
         */
        .page {
            position: relative;
            width: 215mm;
            height: 330mm;
            margin: 0 auto;
            overflow: hidden;
            background: #fff;
        }

        .half-sheet {
            position: absolute;
            left: 0;
            top: 0;
            width: 215mm;
            height: 165mm;
            overflow: hidden;
            background: #fff;
            border: 0.25mm solid #000;
        }

        .field {
            position: absolute;
            overflow: hidden;
            color: #000;
            line-height: 1.1;
        }

        .company-field {
            left: 7mm;
            top: 8mm;
            width: 105mm;
            font-size: 14pt;
            font-weight: bold;
            white-space: nowrap;
        }

        .customer-box {
            position: absolute;
            left: 126mm;
            top: 3mm;
            width: 84mm;
            height: 39mm;
            padding: 4mm 5mm;
            border: 0.25mm solid #000;
            border-radius:
                0 7mm 7mm 7mm;
            font-size: 9.8pt;
            line-height: 5mm;
            overflow: hidden;
        }

        .return-no-label {
            left: 7mm;
            top: 34mm;
            width: 32mm;
            font-size: 10pt;
            white-space: nowrap;
        }

        .return-no-sep {
            left: 40mm;
            top: 34mm;
            width: 4mm;
            font-size: 10pt;
        }

        .return-no-value {
            left: 45mm;
            top: 34mm;
            width: 73mm;
            font-size: 10pt;
            white-space: nowrap;
        }

        .shipping-label {
            left: 7mm;
            top: 41mm;
            width: 32mm;
            font-size: 10pt;
            white-space: nowrap;
        }

        .shipping-sep {
            left: 40mm;
            top: 41mm;
            width: 4mm;
            font-size: 10pt;
        }

        .shipping-value {
            left: 45mm;
            top: 41mm;
            width: 73mm;
            font-size: 10pt;
            white-space: nowrap;
        }

        .table-area {
            position: absolute;
            left: 2mm;
            top: 52mm;
            width: 211mm;
            height: 82mm;
            border: 0.25mm solid #000;
        }

        .table-header {
            position: absolute;
            top: 0;
            height: 10mm;
            border-bottom: 0.25mm solid #000;
            text-align: center;
            font-size: 9pt;
            line-height: 10mm;
            white-space: nowrap;
        }

        .col-qty {
            left: 0;
            width: 74mm;
            border-right: 0.25mm solid #000;
        }

        .col-name {
            left: 74mm;
            width: 72mm;
            border-right: 0.25mm solid #000;
        }

        .col-price {
            left: 146mm;
            width: 28mm;
            border-right: 0.25mm solid #000;
        }

        .col-amount {
            left: 174mm;
            width: 37mm;
        }

        .table-body {
            position: absolute;
            left: 0;
            top: 10mm;
            width: 211mm;
            height: 72mm;
        }

        .vertical-line {
            position: absolute;
            top: 0;
            bottom: 0;
            width: 0;
            border-left: 0.25mm solid #000;
        }

        .line-name {
            left: 74mm;
        }

        .line-price {
            left: 146mm;
        }

        .line-amount {
            left: 174mm;
        }

        .row-field {
            position: absolute;
            height: 6mm;
            font-size: 9pt;
            line-height: 6mm;
            white-space: nowrap;
            overflow: hidden;
        }

        .qty-col-1 {
            left: 2mm;
            width: 22mm;
            text-align: center;
        }

        .qty-col-2 {
            left: 25mm;
            width: 24mm;
            text-align: center;
        }

        .qty-col-3 {
            left: 50mm;
            width: 22mm;
            text-align: center;
        }

        .name-item {
            left: 77mm;
            width: 66mm;
            text-align: left;
        }

        .price-item {
            left: 148mm;
            width: 24mm;
            padding-right: 1mm;
            text-align: right;
        }

        .amount-item {
            left: 176mm;
            width: 33mm;
            padding-right: 1mm;
            text-align: right;
        }

        .footer-area {
            position: absolute;
            left: 2mm;
            top: 134mm;
            width: 211mm;
            height: 29mm;
            border-left: 0.25mm solid #000;
            border-right: 0.25mm solid #000;
            border-bottom: 0.25mm solid #000;
        }

        .sign-box {
            position: absolute;
            top: 0;
            height: 29mm;
            border-right: 0.25mm solid #000;
            text-align: center;
            font-size: 9.5pt;
            font-weight: bold;
            padding-top: 2mm;
        }

        .receiver-box {
            left: 0;
            width: 74mm;
        }

        .known-box {
            left: 74mm;
            width: 72mm;
        }

        .total-label-box {
            position: absolute;
            left: 146mm;
            top: 0;
            width: 28mm;
            height: 29mm;
            border-right: 0.25mm solid #000;
        }

        .total-value-box {
            position: absolute;
            left: 174mm;
            top: 0;
            width: 37mm;
            height: 29mm;
        }

        .total-row {
            height: 9.5mm;
            padding: 2.5mm 1.5mm 0;
            border-bottom: 0.25mm solid #000;
            font-size: 9pt;
        }

        .total-row:last-child {
            border-bottom: none;
        }

        .total-value {
            text-align: right;
            padding-right: 2mm;
        }

        .reason-field {
            position: absolute;
            left: 5mm;
            top: 155mm;
            width: 138mm;
            height: 7mm;
            overflow: hidden;
            font-size: 8pt;
            line-height: 3.5mm;
            white-space: normal;
        }

        .extra-warning {
            position: absolute;
            left: 5mm;
            top: 126mm;
            color: #000;
            font-size: 8pt;
        }

        @page {
            size: 21.5cm 33cm portrait;
            margin: 0;
        }

        @media print {
            html,
            body {
                width: 215mm !important;
                height: auto !important;
                min-height: 0 !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
                background: #fff !important;
            }

            body * {
                visibility: hidden !important;
            }

            .no-print,
            header,
            footer,
            nav,
            aside,
            .navbar,
            .topbar,
            .sidebar,
            .main-header,
            .main-footer,
            .content-header,
            .breadcrumb,
            #header,
            #footer,
            #sidebar {
                display: none !important;
            }

            .page,
            .page * {
                visibility: visible !important;
            }

            .page {
                display: block !important;
                position: fixed !important;
                left: 0 !important;
                top: 0 !important;
                z-index: 999999 !important;
                width: 215mm !important;
                height: 165mm !important;
                min-height: 165mm !important;
                max-height: 165mm !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                break-inside: avoid-page !important;
                page-break-inside: avoid !important;
                page-break-after: avoid !important;
                background: #fff !important;
                box-shadow: none !important;
            }

            .half-sheet {
                border: none !important;
            }
        }
    </style>
</head>

<body>

<div class="no-print">
    <a
        class="btn btn-secondary"
        href="index.php?page=return_invoice"
    >
        Kembali
    </a>

    <button
        type="button"
        class="btn btn-success"
        onclick="window.print()"
    >
        Cetak / Print
    </button>

    <div class="note">
        Format dikonfigurasi untuk kertas
        <strong>F4 Portrait</strong>
        dengan area nota retur pada setengah bagian atas sebesar
        <strong>21,5 cm × 16,5 cm</strong>.
        Pastikan orientasi print menggunakan
        <strong>Portrait</strong>,
        skala <strong>100%</strong>,
        serta margin <strong>None</strong>.
    </div>
</div>

<div class="page">
    <div class="half-sheet">
        <!--<div class="field company-field">
            PT. MUTIARACAHAYA PLASTINDO
        </div>-->

        <div class="customer-box">
            <div>
                Surabaya,
                <?= e($returnDate) ?>
            </div>

            <div>Kepada Yth.</div>

            <div>
                <?= e($customerLines[0]) ?>
            </div>

            <div>
                <?= e($customerLines[1]) ?>
            </div>

            <div>
                <?= e($customerLines[2]) ?>
            </div>

            <div>
                <?= e($customerLines[3]) ?>
            </div>
        </div>

        <div class="field return-no-label">
            NOMOR RETUR
        </div>

        <div class="field return-no-sep">
            :
        </div>

        <div class="field return-no-value">
            <?= e($header['return_id']) ?>
        </div>

        <div class="field shipping-label">
            SURAT JALAN NO
        </div>

        <div class="field shipping-sep">
            :
        </div>

        <div class="field shipping-value">
            <?= e($header['shipping_no']) ?>
        </div>

        <div class="table-area">
            <div class="table-header col-qty">
                BANYAKNYA
            </div>

            <div class="table-header col-name">
                NAMA BARANG
            </div>

            <div class="table-header col-price">
                HARGA SATUAN
            </div>

            <div class="table-header col-amount">
                JUMLAH
            </div>

            <div class="table-body">
                <div class="vertical-line line-name"></div>
                <div class="vertical-line line-price"></div>
                <div class="vertical-line line-amount"></div>

                <?php
                $startTop = 1.5;
                $rowHeight = 6.0;
                $currentTop = $startTop;
                $usedSlots = 0;
                $hasMoreRows = false;

                foreach ($details as $detail):
                    $nameText =
                        safeText(
                            $detail['inventory_name'] ?? ''
                        );

                    $remarksDetail =
                        safeText(
                            $detail['remarks_detail'] ?? ''
                        );

                    if ($remarksDetail !== '') {
                        $nameText .=
                            ' ' .
                            $remarksDetail;
                    }

                    $nameLines =
                        wrapItemName(
                            $nameText,
                            63,
                            9.5,
                            2
                        );

                    $lineCount =
                        count($nameLines);

                    if (
                        $usedSlots +
                        $lineCount >
                        $maxRowSlots
                    ) {
                        $hasMoreRows = true;
                        break;
                    }

                    $qtyParts =
                        buildQtyColumns($detail);

                    $qtyCol1 =
                        $qtyParts[0] ?? '';

                    $qtyCol2 =
                        $qtyParts[1] ?? '';

                    $qtyCol3 =
                        $qtyParts[2] ?? '';
                ?>

                    <div
                        class="row-field qty-col-1"
                        style="top: <?= e($currentTop) ?>mm;"
                    >
                        <?= e($qtyCol1) ?>
                    </div>

                    <div
                        class="row-field qty-col-2"
                        style="top: <?= e($currentTop) ?>mm;"
                    >
                        <?= e($qtyCol2) ?>
                    </div>

                    <div
                        class="row-field qty-col-3"
                        style="top: <?= e($currentTop) ?>mm;"
                    >
                        <?= e($qtyCol3) ?>
                    </div>

                    <?php
                    foreach (
                        $nameLines as
                        $lineIndex =>
                        $nameLine
                    ):
                    ?>
                        <div
                            class="row-field name-item"
                            style="
                                top:
                                <?= e(
                                    $currentTop +
                                    (
                                        $lineIndex *
                                        $rowHeight
                                    )
                                ) ?>mm;
                            "
                        >
                            <?= e($nameLine) ?>
                        </div>
                    <?php endforeach; ?>

                    <div
                        class="row-field price-item"
                        style="top: <?= e($currentTop) ?>mm;"
                    >
                        <?= e(
                            fmtMoney(
                                $detail['price'] ?? 0
                            )
                        ) ?>
                    </div>

                    <div
                        class="row-field amount-item"
                        style="top: <?= e($currentTop) ?>mm;"
                    >
                        <?= e(
                            fmtMoney(
                                $detail['return_subtotal'] ?? 0
                            )
                        ) ?>
                    </div>

                <?php
                    $currentTop +=
                        $rowHeight *
                        $lineCount;

                    $usedSlots +=
                        $lineCount;
                endforeach;
                ?>
            </div>
        </div>

        <?php if ($hasMoreRows): ?>
            <div class="extra-warning">
                * Item melebihi kapasitas nota retur.
            </div>
        <?php endif; ?>

        <div class="footer-area">
            <div class="sign-box receiver-box">
                Penerima,
            </div>

            <div class="sign-box known-box">
                Mengetahui,
            </div>

            <div class="total-label-box">
                <div class="total-row">
                    SUB TOTAL
                </div>

                <div class="total-row">
                    TOTAL RETUR
                </div>

                <div class="total-row">
                    SISA TAGIHAN
                </div>
            </div>

            <div class="total-value-box">
                <div class="total-row total-value">
                    <?= e(
                        fmtMoney(
                            $header['return_amount'] ?? 0
                        )
                    ) ?>
                </div>

                <div class="total-row total-value">
                    <?= e(
                        fmtMoney(
                            $header['return_amount'] ?? 0
                        )
                    ) ?>
                </div>

                <div class="total-row total-value">
                    <?= e(
                        fmtMoney(
                            $header['remaining_invoice_balance'] ?? 0
                        )
                    ) ?>
                </div>
            </div>
        </div>

        <?php
        $reasonCombined = '';

        ?>

        <?php if ($reasonCombined !== ''): ?>
            <div class="reason-field">
                <?= e($reasonCombined) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
<?php
if (
    isset($_GET['print']) &&
    $_GET['print'] == '1'
):
?>
window.addEventListener(
    'load',
    function () {
        setTimeout(
            function () {
                window.print();
            },
            400
        );
    }
);
<?php endif; ?>
</script>

</body>
</html>
