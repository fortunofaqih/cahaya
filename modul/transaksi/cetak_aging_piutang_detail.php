<?php
/*
 * modul/transaksi/cetak_aging_piutang_detail.php
 *
 * RULE FINAL AGING PIUTANG - STRICT MONTHLY CARRY-FORWARD:
 * 1. Customer yang tampil WAJIB masih ada dan aktif di m_customer.
 * 2. SALDO AWAL bulan berjalan = posisi piutang sampai H-1 bulan laporan.
 *    Bucket opening TIDAK dihitung dari opening_date. Bucket berasal dari snapshot
 *    customer_opening_balance_bucket dan digulirkan bulan demi bulan.
 *    customer_opening_balance hanya merupakan base migrasi, bukan mutasi bulanan.
 * 3. TRANSAKSI SEBELUM BULAN FILTER tidak boleh tampil ulang pada kolom bulan berjalan:
 *      - invoice sebelum startDate -> bagian dari SALDO AWAL;
 *      - pembayaran sebelum startDate -> bagian dari SALDO AWAL;
 *      - titip-used sebelum startDate -> bagian dari SALDO AWAL;
 *      - retur sebelum startDate -> bagian dari SALDO AWAL.
 * 4. Kolom PENJUALAN hanya memuat invoice pada bulan yang difilter
 *    (dikurangi retur bulan berjalan sesuai rule laporan yang sudah digunakan).
 * 5. Kolom BAYAR hanya memuat pembayaran cash/transfer pada bulan yang difilter.
 * 6. Kolom TITIP hanya memuat titip yang DIPAKAI pada bulan yang difilter.
 *    Titip-used bulan sebelumnya tidak tampil lagi karena efeknya sudah masuk
 *    Saldo Akhir bulan sebelumnya dan otomatis menjadi Saldo Awal bulan ini.
 * 7. Untuk customer yang memiliki opening balance:
 *      - invoice dengan invoice_date <= opening_date sudah tercakup pada opening;
 *      - hanya mutasi setelah opening_date yang dihitung lagi.
 * 8. Untuk customer TANPA opening balance, histori sebelum startDate tetap
 *    dihitung sebagai carry-forward SALDO AWAL, bukan dipindahkan ke Penjualan.
 * 9. Invoice / Shipping CP-MCP tidak masuk Penjualan/Pembayaran normal.
 * 10. Retur CP-MCP tetap dihitung standalone memakai grand_total;
 *     retur normal memakai return_amount.
 * 11. Retur normal mengurangi outstanding invoice terkait.
 *     Retur standalone/CP-MCP tidak membuat Bayar negatif sintetis.
 *     Retur yang belum digunakan masuk bucket LEBIH sebagai credit balance.
 * 12. Titip yang belum digunakan masuk bucket LEBIH sebagai credit balance.
 *     Titip yang sudah digunakan tetap tampil di kolom TITIP.
 * 12. Rumus wajib balance:
 *       Saldo Awal + Penjualan - Bayar - Titip = Akhir
 *       1-30 + 31-60 + 61-90 + Lebih + Belum Jatuh Tempo = Akhir
 * 13. Saldo Akhir bulan N otomatis menjadi Saldo Awal bulan N+1.
 *     User tidak perlu input customer_opening_balance setiap bulan.
 *     customer_opening_balance hanya base migrasi/snapshot historis bila memang tersedia.
 * 14. Nilai invoice resmi mengikuti det_invoice.total, fallback det_invoice.subtotal.
 *     Aging tidak menghitung ulang harga dari SO/Shipping.
 */

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

function h($value)
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatMoney($value)
{
    $value = (float)$value;
    $value = $value >= 0 ? floor($value) : ceil($value);
    return number_format($value, 2, ',', '.');
}

function getMonthName($month)
{
    $names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    return $names[(int)$month] ?? '';
}

function getTitleFilter($filterBy, $filterValue)
{
    if ($filterBy === 'grup' && $filterValue !== '') {
        return 'Grup: ' . $filterValue;
    }
    if ($filterBy === 'kota' && $filterValue !== '') {
        return 'Kota: ' . $filterValue;
    }
    if ($filterBy === 'pelanggan' && $filterValue !== '') {
        return 'Pelanggan: ' . $filterValue;
    }
    return 'Semua Grup';
}

function initAmountRow()
{
    return [
        'saldo_awal' => 0.0,
        'penjualan' => 0.0,
        'bayar' => 0.0,
        'titip_used' => 0.0,
        'titip_unused' => 0.0,
        'retur' => 0.0,
        'akhir' => 0.0,
        'b_1_30' => 0.0,
        'b_31_60' => 0.0,
        'b_61_90' => 0.0,
        'b_lebih' => 0.0,
        'belum_jatuh_tempo' => 0.0,
    ];
}

function addAmounts(array &$target, array $source)
{
    foreach (array_keys(initAmountRow()) as $key) {
        $target[$key] += (float)($source[$key] ?? 0);
    }
}

function normalizeCity($city)
{
    $city = trim((string)$city);
    return $city !== '' ? strtoupper($city) : 'TANPA KOTA';
}

function paymentCashComponent(array $row)
{
    $cash = (float)($row['cash_amount'] ?? 0);
    if ($cash > 0) {
        return $cash;
    }

    return max(
        (float)($row['bayar_amount'] ?? 0) - (float)($row['titip_amount'] ?? 0),
        0
    );
}

function tableExists(mysqli $conn, string $table): bool
{
    $safe = mysqli_real_escape_string($conn, $table);
    $res = mysqli_query($conn, "SHOW TABLES LIKE '{$safe}'");
    return $res && mysqli_num_rows($res) > 0;
}

function sumOpeningBucketSeed(array $seed): float
{
    return
        (float)($seed['b_1_30'] ?? 0)
        + (float)($seed['b_31_60'] ?? 0)
        + (float)($seed['b_61_90'] ?? 0)
        + (float)($seed['b_lebih'] ?? 0)
        + (float)($seed['belum_jatuh_tempo'] ?? 0);
}

function rollOpeningBucketsOneMonth(array $bucket): array
{
    /*
     * RULE PROGRAM LAMA - ROLLOVER BULANAN:
     * posisi akhir bulan N menjadi umur awal bulan N+1:
     *   BJT   -> 1-30
     *   1-30  -> 31-60
     *   31-60 -> 61-90
     *   61-90 -> Lebih
     *   Lebih -> tetap Lebih
     *
     * Nilai negatif tetap mengikuti bucket asalnya. Jangan paksa kredit
     * negatif ke Lebih, karena PDF legacy membuktikan saldo negatif juga
     * dapat berada pada 1-30 / 31-60 / bucket lain.
     */
    return [
        'b_1_30' => (float)($bucket['belum_jatuh_tempo'] ?? 0),
        'b_31_60' => (float)($bucket['b_1_30'] ?? 0),
        'b_61_90' => (float)($bucket['b_31_60'] ?? 0),
        'b_lebih' => (float)($bucket['b_lebih'] ?? 0) + (float)($bucket['b_61_90'] ?? 0),
        'belum_jatuh_tempo' => 0.0,
    ];
}

function applySignedOpeningPayment(array &$bucket, float $signedAmount): void
{
    if (abs($signedAmount) < 0.0001) {
        return;
    }

    /*
     * Pembayaran positif mengurangi piutang opening tertua terlebih dahulu.
     * Ini terbukti dari SETIA TANI / MUMBUL TANI pada PDF legacy.
     */
    if ($signedAmount > 0) {
        $remaining = $signedAmount;
        foreach (['b_lebih','b_61_90','b_31_60','b_1_30','belum_jatuh_tempo'] as $k) {
            if ($remaining <= 0.0001) {
                break;
            }
            $available = max((float)($bucket[$k] ?? 0), 0.0);
            if ($available <= 0) {
                continue;
            }
            $take = min($available, $remaining);
            $bucket[$k] -= $take;
            $remaining -= $take;
        }

        /* Overpayment terhadap opening menjadi credit balance. */
        if ($remaining > 0.0001) {
            $bucket['b_lebih'] -= $remaining;
        }
        return;
    }

    /*
     * Bayar negatif / offset migrasi dipakai untuk menutup saldo opening
     * yang negatif, juga dari bucket tertua terlebih dahulu.
     */
    $remaining = abs($signedAmount);
    foreach (['b_lebih','b_61_90','b_31_60','b_1_30','belum_jatuh_tempo'] as $k) {
        if ($remaining <= 0.0001) {
            break;
        }
        $negative = min((float)($bucket[$k] ?? 0), 0.0);
        if ($negative >= 0) {
            continue;
        }
        $take = min(abs($negative), $remaining);
        $bucket[$k] += $take;
        $remaining -= $take;
    }
}

function simulateOpeningBuckets(
    array $seed,
    string $asOfDate,
    array $monthlyPayments = []
): array {
    $bucket = [
        'b_1_30' => (float)($seed['b_1_30'] ?? 0),
        'b_31_60' => (float)($seed['b_31_60'] ?? 0),
        'b_61_90' => (float)($seed['b_61_90'] ?? 0),
        'b_lebih' => (float)($seed['b_lebih'] ?? 0),
        'belum_jatuh_tempo' => (float)($seed['belum_jatuh_tempo'] ?? 0),
    ];

    $bucketDate = (string)($seed['bucket_date'] ?? '');
    if ($bucketDate === '' || $asOfDate === '' || $asOfDate <= $bucketDate) {
        return $bucket;
    }

    /*
     * Seed adalah POSISI AKHIR bulan bucket_date (31-Jul-2026).
     * Maka setiap bulan sesudahnya harus diproses berurutan:
     *   1. rollover umur satu bulan;
     *   2. terapkan pembayaran opening pada bulan tersebut;
     *   3. lanjut ke bulan berikutnya.
     *
     * Ini berbeda dari cara lama yang menggulung Juli langsung beberapa bulan
     * lalu mengurangi total pembayaran secara kumulatif di akhir. Cara lama
     * akan salah mulai September karena pembayaran Agustus seharusnya terjadi
     * SEBELUM rollover Agustus -> September.
     */
    $cursor = new DateTime(date('Y-m-01', strtotime($bucketDate . ' +1 month')));
    $lastMonth = new DateTime(date('Y-m-01', strtotime($asOfDate)));

    while ($cursor <= $lastMonth) {
        $bucket = rollOpeningBucketsOneMonth($bucket);

        $monthKey = $cursor->format('Y-m');
        $payment = (float)($monthlyPayments[$monthKey] ?? 0.0);
        if (abs($payment) > 0.0001) {
            applySignedOpeningPayment($bucket, $payment);
        }

        $cursor->modify('+1 month');
    }

    return $bucket;
}

$bulan = (int)($_GET['bulan'] ?? date('n'));
$tahun = (int)($_GET['tahun'] ?? date('Y'));
$filterBy = trim((string)($_GET['filter_by'] ?? 'semua'));
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

$title = 'Laporan Aging Periode Bulan ' . getMonthName($bulan) . ' Tahun ' . $tahun . ' CAHAYA';
$subtitle = getTitleFilter($filterBy, $filterValue);
$printedAt = date('d-M-Y H:i:s');

/* ============================================================
 * 1. MASTER CUSTOMER AKTIF = GERBANG UTAMA
 * ============================================================
 * Penting: gunakan m_customer sebagai source of truth.
 * Histori transaksi yang customer_id-nya sudah tidak ada di master
 * tidak akan dimasukkan ke Aging.
 */
$whereCustomer = "
    WHERE COALESCE(mc.is_active, 'Checked') = 'Checked'
";
$customerParams = [];
$customerTypes = '';

if ($filterBy === 'grup' && $filterValue !== '') {
    $whereCustomer .= " AND COALESCE(mc.area_code, '') = ? ";
    $customerParams[] = $filterValue;
    $customerTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $whereCustomer .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $customerParams[] = $filterValue;
    $customerTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $whereCustomer .= " AND mc.customer_id = ? ";
    $customerParams[] = $filterValue;
    $customerTypes .= 's';
}

$sqlCustomer = "
    SELECT
        mc.customer_id,
        mc.customer,
        mc.city,
        mc.area_code
    FROM m_customer mc
    $whereCustomer
";

$stmtCustomer = mysqli_prepare($conn, $sqlCustomer);
if ($customerTypes !== '') {
    mysqli_stmt_bind_param($stmtCustomer, $customerTypes, ...$customerParams);
}
mysqli_stmt_execute($stmtCustomer);
$resCustomer = mysqli_stmt_get_result($stmtCustomer);

$masterCustomers = [];
while ($mc = mysqli_fetch_assoc($resCustomer)) {
    $cid = trim((string)$mc['customer_id']);
    if ($cid === '') {
        continue;
    }
    $masterCustomers[$cid] = [
        'customer_id' => $cid,
        'customer_name' => trim((string)($mc['customer'] ?? '')) ?: '-',
        'city' => normalizeCity($mc['city'] ?? ''),
        'area_code' => trim((string)($mc['area_code'] ?? '')),
    ];
}
mysqli_stmt_close($stmtCustomer);

/* Struktur hasil per customer. */
$customers = [];
$openingMap = [];
$openingBucket = [];
$invoiceMeta = [];
$invoicePaymentMap = [];
$orphanPaymentBucket = [];

function ensureCustomerRow(array &$customers, array $masterCustomers, string $customerId)
{
    if (isset($customers[$customerId])) {
        return true;
    }
    if (!isset($masterCustomers[$customerId])) {
        return false;
    }

    $m = $masterCustomers[$customerId];
    $customers[$customerId] = [
        'customer_id' => $customerId,
        'customer_name' => $m['customer_name'],
        'city' => $m['city'],
        'opening_date' => '',
        'amounts' => initAmountRow(),
        'selisih_balance' => 0.0,
    ];
    return true;
}

/* ============================================================
 * 2. CUT-OFF & OPENING BALANCE
 * ============================================================
 * Saldo Awal bulan berjalan = posisi piutang per H-1 periode.
 * Semua mutasi sebelum startDate sudah menjadi carry-forward dan tidak boleh
 * tampil ulang pada kolom Penjualan/Bayar/Titip bulan berjalan.
 * customer_opening_balance hanya menjadi BASE migrasi.
 */
$previousDate = date('Y-m-d', strtotime($startDate . ' -1 day'));

$sqlOpening = "
    SELECT
        cob.opening_id,
        cob.opening_date,
        cob.customer_id,
        cob.opening_balance
    FROM customer_opening_balance cob
    INNER JOIN (
        SELECT customer_id, MAX(opening_id) AS opening_id
        FROM customer_opening_balance
        WHERE LOWER(COALESCE(status, 'Active')) = 'active'
          AND opening_date <= ?
        GROUP BY customer_id
    ) latest
        ON latest.opening_id = cob.opening_id
    INNER JOIN m_customer mc
        ON mc.customer_id = cob.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    WHERE 1=1
";

$openingParams = [$previousDate];
$openingTypes = 's';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlOpening .= " AND COALESCE(mc.area_code, '') = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlOpening .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlOpening .= " AND mc.customer_id = ? ";
    $openingParams[] = $filterValue;
    $openingTypes .= 's';
}

$stmtOpening = mysqli_prepare($conn, $sqlOpening);
mysqli_stmt_bind_param($stmtOpening, $openingTypes, ...$openingParams);
mysqli_stmt_execute($stmtOpening);
$resOpening = mysqli_stmt_get_result($stmtOpening);

$globalOpeningCutoff = '';
while ($op = mysqli_fetch_assoc($resOpening)) {
    $cid = trim((string)($op['customer_id'] ?? ''));
    if ($cid === '' || !isset($masterCustomers[$cid])) {
        continue;
    }

    $openingMap[$cid] = [
        'opening_id' => (int)($op['opening_id'] ?? 0),
        'opening_date' => (string)($op['opening_date'] ?? ''),
        'opening_balance' => (float)($op['opening_balance'] ?? 0),
    ];

    $od = (string)($op['opening_date'] ?? '');
    if ($od !== '' && ($globalOpeningCutoff === '' || $od > $globalOpeningCutoff)) {
        $globalOpeningCutoff = $od;
    }
}
mysqli_stmt_close($stmtOpening);


/* ============================================================
 * 2A. BUCKET OPENING MIGRASI (OPSIONAL TAPI DIREKOMENDASIKAN)
 * ============================================================
 * opening_date = tanggal snapshot saldo, BUKAN umur piutang.
 * Bucket historis WAJIB berasal dari customer_opening_balance_bucket.
 * Seed yang dipakai adalah posisi AKHIR bucket_date (contoh 31-Jul-2026),
 * kemudian kode melakukan rollover per bulan secara kronologis.
 */
$openingBucketMap = [];
$openingBucketTableAvailable = tableExists($conn, 'customer_opening_balance_bucket');
if ($openingBucketTableAvailable) {
    $sqlOB = "
        SELECT opening_id, customer_id, bucket_date,
               b_1_30, b_31_60, b_61_90, b_lebih, belum_jatuh_tempo
        FROM customer_opening_balance_bucket
    ";
    $resOB = mysqli_query($conn, $sqlOB);
    while ($ob = mysqli_fetch_assoc($resOB)) {
        $cid = trim((string)$ob['customer_id']);
        if ($cid === '') continue;
        $openingBucketMap[$cid] = [
            'opening_id' => (int)$ob['opening_id'],
            'bucket_date' => (string)$ob['bucket_date'],
            'b_1_30' => (float)$ob['b_1_30'],
            'b_31_60' => (float)$ob['b_31_60'],
            'b_61_90' => (float)$ob['b_61_90'],
            'b_lebih' => (float)$ob['b_lebih'],
            'belum_jatuh_tempo' => (float)$ob['belum_jatuh_tempo'],
        ];
    }
}

/* Adjustment migrasi dipakai hanya bila angka program lama memang tidak
 * mempunyai transaksi ekuivalen di database baru. Adjustment bulan lalu
 * otomatis ikut carry-forward ke Saldo Awal bulan berikutnya.
 */
$adjustmentMap = [];
$adjustmentTableAvailable = tableExists($conn, 'aging_migration_adjustment');
if ($adjustmentTableAvailable) {
    $safeEnd = mysqli_real_escape_string($conn, $endDate);
    $sqlAdj = "
        SELECT period_date, customer_id, awal_adjust, penjualan_adjust,
               bayar_adjust, titip_adjust, opening_payment_adjust
        FROM aging_migration_adjustment
        WHERE period_date <= '{$safeEnd}'
    ";
    $resAdj = mysqli_query($conn, $sqlAdj);
    while ($ad = mysqli_fetch_assoc($resAdj)) {
        $cid = trim((string)$ad['customer_id']);
        if ($cid === '' || !isset($masterCustomers[$cid])) continue;
        if (!isset($adjustmentMap[$cid])) {
            $adjustmentMap[$cid] = [
                'pre_awal'=>0.0,'pre_penjualan'=>0.0,'pre_bayar'=>0.0,'pre_titip'=>0.0,
                'period_awal'=>0.0,'period_penjualan'=>0.0,'period_bayar'=>0.0,'period_titip'=>0.0,
                'opening_payment_until_end'=>0.0
            ];
        }
        $isPre = ((string)$ad['period_date'] < $startDate);
        $prefix = $isPre ? 'pre_' : 'period_';
        $adjustmentMap[$cid][$prefix.'awal'] += (float)$ad['awal_adjust'];
        $adjustmentMap[$cid][$prefix.'penjualan'] += (float)$ad['penjualan_adjust'];
        $adjustmentMap[$cid][$prefix.'bayar'] += (float)$ad['bayar_adjust'];
        $adjustmentMap[$cid][$prefix.'titip'] += (float)$ad['titip_adjust'];
        $adjustmentMap[$cid]['opening_payment_until_end'] += (float)$ad['opening_payment_adjust'];
    }
}

/*
 * Timeline adjustment yang memang ditandai sebagai pembayaran opening.
 * Disimpan per bulan agar urutan rollover -> pembayaran tetap benar.
 */
$openingAdjustmentTimeline = [];
if ($adjustmentTableAvailable) {
    $safeEndTimeline = mysqli_real_escape_string($conn, $endDate);
    $sqlAdjTimeline = "
        SELECT period_date, customer_id, opening_payment_adjust
        FROM aging_migration_adjustment
        WHERE period_date <= '{$safeEndTimeline}'
          AND ABS(COALESCE(opening_payment_adjust,0)) > 0
    ";
    $resAdjTimeline = mysqli_query($conn, $sqlAdjTimeline);
    while ($at = mysqli_fetch_assoc($resAdjTimeline)) {
        $cid = trim((string)$at['customer_id']);
        if ($cid === '' || !isset($masterCustomers[$cid])) {
            continue;
        }
        $monthKey = date('Y-m', strtotime((string)$at['period_date']));
        if (!isset($openingAdjustmentTimeline[$cid])) {
            $openingAdjustmentTimeline[$cid] = [];
        }
        if (!isset($openingAdjustmentTimeline[$cid][$monthKey])) {
            $openingAdjustmentTimeline[$cid][$monthKey] = 0.0;
        }
        $openingAdjustmentTimeline[$cid][$monthKey] += (float)$at['opening_payment_adjust'];
    }
}
/*
 * CUT-OFF MIGRASI GLOBAL
 * ------------------------------------------------------------
 * Tetap disimpan untuk kompatibilitas data migrasi/adjustment lama, tetapi
 * TIDAK lagi dipakai untuk membuang histori customer yang tidak mempunyai
 * customer_opening_balance.
 *
 * Rule normal:
 * - customer dengan opening manual: snapshot menjadi base; transaksi setelah
 *   opening_date yang digulirkan;
 * - customer tanpa opening manual: seluruh histori transaksi menjadi dasar
 *   carry-forward otomatis.
 *
 * Dengan demikian saldo akhir Juli otomatis menjadi saldo awal Agustus,
 * saldo akhir Agustus menjadi saldo awal September, dan seterusnya.
 */
$migrationCutoff = $globalOpeningCutoff !== ''
    ? $globalOpeningCutoff
    : '1000-01-01';

$migrationCutoffSql = mysqli_real_escape_string(
    $conn,
    $migrationCutoff
);
/*
 * Tidak ada lagi pengecualian "periode migrasi pertama".
 * Semua mutasi sebelum startDate selalu menjadi carry-forward Saldo Awal.
 * Dengan demikian transaksi bulan lalu tidak akan tampil ulang sebagai
 * Penjualan / Bayar / Titip bulan berjalan.
 */

/* Temp result per customer supaya finalisasi ringan. */
$calc = [];
foreach ($masterCustomers as $cid => $m) {
    $calc[$cid] = [
        'pre_invoice' => 0.0,
        'period_invoice' => 0.0,
        'pre_cash' => 0.0,
        'pre_titip' => 0.0,
        'period_cash' => 0.0,
        'period_titip' => 0.0,
        'opening_target_total' => 0.0,
        'orphan_total' => 0.0,
        'pre_return' => 0.0,
        'period_return' => 0.0,
        'pre_return_offset' => 0.0,
        'period_return_offset' => 0.0,
        'bucket_credit_return' => 0.0,
        'credit_b_1_30' => 0.0,
        'credit_b_31_60' => 0.0,
        'credit_b_61_90' => 0.0,
        'credit_b_lebih' => 0.0,
        'credit_bjt' => 0.0,
        'unused_titip_before' => 0.0,
        'unused_titip_end' => 0.0,
        'b_1_30' => 0.0,
        'b_31_60' => 0.0,
        'b_61_90' => 0.0,
        'b_lebih' => 0.0,
        'belum_jatuh_tempo' => 0.0,
    ];
}

/* ============================================================
 * 3. INVOICE + AGING BUCKET
 * ============================================================
 * SOURCE OF TRUTH NILAI INVOICE:
 * det_invoice.total, fallback det_invoice.subtotal.
 * Nilai tidak dihitung ulang dari SO/Shipping agar konsisten dengan Invoice,
 * Add Bayar, dan Kartu Piutang.
 *
 * Outstanding per invoice:
 * invoice_calc - cash - titip_used - retur_linked_invoice.
 *
 * Bucket memakai umur lewat jatuh tempo, tetapi lebar bucket mengikuti
 * jumlah hari kalender aktual:
 * contoh Agustus 2026 => 1-31, 32-62, 63-92, >92.
 */
$bucket1Days = (int)date('t', strtotime($startDate));
$prevMonthStart = date('Y-m-01', strtotime($startDate . ' -1 month'));
$prev2MonthStart = date('Y-m-01', strtotime($startDate . ' -2 month'));
$bucket2Limit = $bucket1Days + (int)date('t', strtotime($prevMonthStart));
$bucket3Limit = $bucket2Limit + (int)date('t', strtotime($prev2MonthStart));

$sqlInvoiceAgg = "
WITH latest_opening AS (
    SELECT cob.customer_id, cob.opening_date, cob.opening_balance
    FROM customer_opening_balance cob
    INNER JOIN (
        SELECT customer_id, MAX(opening_id) AS opening_id
        FROM customer_opening_balance
        WHERE LOWER(COALESCE(status, 'Active')) = 'active'
          AND opening_date <= ?
        GROUP BY customer_id
    ) x ON x.opening_id = cob.opening_id
),
titip_use AS (
    SELECT
        ref_no AS bayar_no,
        customer_id,
        MIN(titip_date) AS titip_date
    FROM detail_titip
    WHERE UPPER(COALESCE(transaction_type,'')) = 'PAKAI'
      AND COALESCE(ref_no,'') <> ''
    GROUP BY ref_no, customer_id
),
invoice_calc AS (
    /*
     * SOURCE OF TRUTH NILAI INVOICE:
     * det_invoice.total, fallback det_invoice.subtotal.
     */
    SELECT
        di.invoice_no,
        ROUND(
            COALESCE(
                SUM(
                    CASE
                        WHEN COALESCE(di.total, 0) > 0
                            THEN COALESCE(di.total, 0)
                        ELSE COALESCE(di.subtotal, 0)
                    END
                ),
                0
            ),
            0
        ) AS gross
    FROM det_invoice di
    GROUP BY di.invoice_no
),
pay_inv AS (
    SELECT
        db.invoice_no,
        SUM(
            CASE
                WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'RETURN'
                  OR (
                        LOWER(TRIM(COALESCE(hb.keterangan, ''))) = 'retur'
                    AND COALESCE(TRIM(db.return_id), '') <> ''
                  )
                THEN 0
                ELSE
                    CASE
                        WHEN hb.bayar_date <= ? THEN
                            CASE
                                WHEN COALESCE(db.cash_amount, 0) > 0
                                    THEN COALESCE(db.cash_amount, 0)
                                ELSE GREATEST(
                                    COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
                                    0
                                )
                            END
                        ELSE 0
                    END
                    +
                    CASE
                        WHEN COALESCE(tu.titip_date, hb.bayar_date) <= ?
                            THEN COALESCE(db.titip_amount, 0)
                        ELSE 0
                    END
            END
        ) AS paid_total
    FROM detail_bayar db
    INNER JOIN head_bayar hb
        ON hb.bayar_no = db.bayar_no
    LEFT JOIN titip_use tu
        ON tu.bayar_no = db.bayar_no
       AND tu.customer_id = hb.customer_id
    WHERE COALESCE(db.invoice_no, '') <> ''
      AND UPPER(COALESCE(db.invoice_no, '')) NOT LIKE '%CP-MCP%'
      AND UPPER(COALESCE(db.shipping_no, '')) NOT LIKE '%CP-MCP%'
    GROUP BY db.invoice_no
),
return_inv AS (
    SELECT
        hri.invoice_no,
        SUM(COALESCE(hri.return_amount, 0)) AS return_total
    FROM head_retur_invoice hri
    WHERE hri.return_date <= ?
      AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
      AND COALESCE(hri.invoice_no, '') <> ''
      AND UPPER(COALESCE(hri.invoice_no, '')) NOT LIKE '%CP-MCP%'
      AND UPPER(COALESCE(hri.shipping_no, '')) NOT LIKE '%CP-MCP%'
    GROUP BY hri.invoice_no
),
inv_base AS (
    SELECT
        hi.customer_id,
        hi.invoice_no,
        hi.invoice_date,
        DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days, 0) DAY) AS due_date,
        lo.opening_date,
        COALESCE(ic.gross, 0) AS gross,
        COALESCE(pi.paid_total, 0) AS paid_total,
        COALESCE(ri.return_total, 0) AS return_total
    FROM head_invoice hi
    INNER JOIN m_customer mc
        ON mc.customer_id = hi.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    LEFT JOIN latest_opening lo
        ON lo.customer_id = hi.customer_id
    LEFT JOIN invoice_calc ic
        ON ic.invoice_no = hi.invoice_no
    LEFT JOIN pay_inv pi
        ON pi.invoice_no = hi.invoice_no
    LEFT JOIN return_inv ri
        ON ri.invoice_no = hi.invoice_no
    WHERE hi.invoice_date <= ?
      AND UPPER(COALESCE(hi.invoice_no, '')) NOT LIKE '%CP-MCP%'
";

$invoiceAggParams = [$previousDate, $endDate, $endDate, $endDate, $endDate];
$invoiceAggTypes = 'sssss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlInvoiceAgg .= " AND COALESCE(mc.area_code, '') = ? ";
    $invoiceAggParams[] = $filterValue;
    $invoiceAggTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlInvoiceAgg .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $invoiceAggParams[] = $filterValue;
    $invoiceAggTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlInvoiceAgg .= " AND mc.customer_id = ? ";
    $invoiceAggParams[] = $filterValue;
    $invoiceAggTypes .= 's';
}

$sqlInvoiceAgg .= "
),
eligible AS (
    SELECT
        *,
        (gross - paid_total - return_total) AS outstanding
    FROM inv_base
    WHERE
        (
            opening_date IS NOT NULL
            AND invoice_date > opening_date
        )
        OR
        (
            opening_date IS NULL
        )
)
SELECT
    customer_id,
    SUM(CASE WHEN invoice_date < ? THEN gross ELSE 0 END) AS pre_invoice,
    SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN gross ELSE 0 END) AS period_invoice,

    SUM(CASE
        WHEN outstanding < 0 THEN outstanding
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) > {$bucket3Limit} THEN outstanding
        ELSE 0 END
    ) AS b_lebih,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) > {$bucket2Limit}
         AND DATEDIFF(?, due_date) <= {$bucket3Limit}
        THEN outstanding ELSE 0 END
    ) AS b_61_90,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) > {$bucket1Days}
         AND DATEDIFF(?, due_date) <= {$bucket2Limit}
        THEN outstanding ELSE 0 END
    ) AS b_31_60,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) BETWEEN 1 AND {$bucket1Days}
        THEN outstanding ELSE 0 END
    ) AS b_1_30,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) <= 0
        THEN outstanding ELSE 0 END
    ) AS belum_jatuh_tempo
FROM eligible
GROUP BY customer_id
";

$invoiceAggParams[] = $startDate;
$invoiceAggParams[] = $startDate;
$invoiceAggParams[] = $endDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggTypes .= 'ssssssssss';

$stmtInvoiceAgg = mysqli_prepare($conn, $sqlInvoiceAgg);
mysqli_stmt_bind_param($stmtInvoiceAgg, $invoiceAggTypes, ...$invoiceAggParams);
mysqli_stmt_execute($stmtInvoiceAgg);
$resInvoiceAgg = mysqli_stmt_get_result($stmtInvoiceAgg);

while ($r = mysqli_fetch_assoc($resInvoiceAgg)) {
    $cid = trim((string)($r['customer_id'] ?? ''));
    if (!isset($calc[$cid])) {
        continue;
    }
    foreach (['pre_invoice','period_invoice','b_lebih','b_61_90','b_31_60','b_1_30','belum_jatuh_tempo'] as $k) {
        $calc[$cid][$k] = (float)($r[$k] ?? 0);
    }
}
mysqli_stmt_close($stmtInvoiceAgg);

/* ============================================================
 * 4. PEMBAYARAN CASH + TITIP TERPAKAI
 * ============================================================
 * Cash mengikuti head_bayar.bayar_date.
 * Hanya pembayaran yang tanggal bayarnya berada pada bulan filter yang tampil
 * di kolom BAYAR; pembayaran bulan sebelumnya hanya mempengaruhi SALDO AWAL.
 * Titip hanya mengurangi A/R ketika benar-benar DIPAKAI pada detail_bayar,
 * dengan tanggal efektif date_created (fallback tanggal head_bayar).
 * Titip masuk di detail_titip tidak mengurangi piutang.
 */
$sqlPayAgg = "
WITH latest_opening AS (
    SELECT cob.customer_id, cob.opening_date, cob.opening_balance
    FROM customer_opening_balance cob
    INNER JOIN (
        SELECT customer_id, MAX(opening_id) AS opening_id
        FROM customer_opening_balance
        WHERE LOWER(COALESCE(status, 'Active')) = 'active'
          AND opening_date <= ?
        GROUP BY customer_id
    ) x ON x.opening_id = cob.opening_id
),
titip_use AS (
    SELECT
        ref_no AS bayar_no,
        customer_id,
        MIN(titip_date) AS titip_date,
        SUM(COALESCE(amount_out,0)) AS amount_out
    FROM detail_titip
    WHERE UPPER(COALESCE(transaction_type,'')) = 'PAKAI'
      AND COALESCE(ref_no,'') <> ''
    GROUP BY ref_no, customer_id
),
pay_base AS (
    SELECT
        hb.customer_id,
        hb.bayar_date AS cash_date,
        COALESCE(tu.titip_date, hb.bayar_date) AS titip_date,
        lo.opening_date,
        lo.opening_balance,
        db.invoice_no,
        db.opening_id,
        UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) AS payment_source,
        hi.invoice_date AS linked_invoice_date,
                /*
        * LEGACY TARGET:
        * Customer tidak mempunyai opening balance, tetapi pembayaran
        * diarahkan ke invoice lama sebelum/sampai cut-off migrasi.
        *
        * Karena invoice lama tersebut tidak dibawa ke Aging baru,
        * pembayarannya juga tidak boleh mengurangi Aging baru.
        */
        0 AS is_legacy_target,
        CASE
            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'RETURN'
              OR (
                    LOWER(TRIM(COALESCE(hb.keterangan, ''))) = 'retur'
                AND COALESCE(TRIM(db.return_id), '') <> ''
              )
            THEN 0
            WHEN COALESCE(db.cash_amount, 0) > 0
                THEN COALESCE(db.cash_amount, 0)
            ELSE GREATEST(
                COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
                0
            )
        END AS cash_raw,

        CASE
            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'RETURN'
              OR (
                    LOWER(TRIM(COALESCE(hb.keterangan, ''))) = 'retur'
                AND COALESCE(TRIM(db.return_id), '') <> ''
              )
            THEN 0
            ELSE COALESCE(db.titip_amount, 0)
        END AS titip_raw,

        CASE
            WHEN lo.opening_date IS NOT NULL
             AND (
                    UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'OPENING'
                 OR COALESCE(db.opening_id, 0) > 0
                 OR COALESCE(db.invoice_no, '') = ''
                 OR hi.invoice_date <= lo.opening_date
                 OR hi.invoice_no IS NULL
             ) THEN 1
            ELSE 0
        END AS is_opening_target,

        CASE
            WHEN lo.opening_date IS NULL
             AND (COALESCE(db.invoice_no, '') = '' OR hi.invoice_no IS NULL)
            THEN 1 ELSE 0
        END AS is_orphan,

        CASE WHEN COALESCE(lo.opening_balance, 0) < 0 THEN -1 ELSE 1 END AS opening_sign

    FROM detail_bayar db
    INNER JOIN head_bayar hb
        ON hb.bayar_no = db.bayar_no
    INNER JOIN m_customer mc
        ON mc.customer_id = hb.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    LEFT JOIN titip_use tu
        ON tu.bayar_no = db.bayar_no
       AND tu.customer_id = hb.customer_id
    LEFT JOIN latest_opening lo
        ON lo.customer_id = hb.customer_id
    LEFT JOIN head_invoice hi
        ON hi.invoice_no = db.invoice_no
    WHERE (
            hb.bayar_date <= ?
         OR COALESCE(tu.titip_date, hb.bayar_date) <= ?
    )
      AND UPPER(COALESCE(db.invoice_no, '')) NOT LIKE '%CP-MCP%'
      AND UPPER(COALESCE(db.shipping_no, '')) NOT LIKE '%CP-MCP%'
";

$payAggParams = [$previousDate, $endDate, $endDate];
$payAggTypes = 'sss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlPayAgg .= " AND COALESCE(mc.area_code, '') = ? ";
    $payAggParams[] = $filterValue;
    $payAggTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlPayAgg .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $payAggParams[] = $filterValue;
    $payAggTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlPayAgg .= " AND mc.customer_id = ? ";
    $payAggParams[] = $filterValue;
    $payAggTypes .= 's';
}

$sqlPayAgg .= "
)
SELECT
    customer_id,

SUM(CASE
    WHEN cash_date < ?
     AND (
            (opening_date IS NOT NULL AND cash_date > opening_date)
         OR (opening_date IS NULL)
     )
    THEN cash_raw * (
        CASE
            WHEN is_opening_target = 1 THEN opening_sign
            ELSE 1
        END
    )
    ELSE 0
END) AS pre_cash,

SUM(CASE
    WHEN titip_date < ?
     AND (
            (opening_date IS NOT NULL AND titip_date > opening_date)
         OR (opening_date IS NULL)
     )
    THEN titip_raw * (
        CASE
            WHEN is_opening_target = 1 THEN opening_sign
            ELSE 1
        END
    )
    ELSE 0
END) AS pre_titip,

SUM(CASE
    WHEN cash_date BETWEEN ? AND ?
     AND (
            (opening_date IS NOT NULL AND cash_date > opening_date)
         OR (opening_date IS NULL)
     )
    THEN cash_raw * (
        CASE
            WHEN is_opening_target = 1 THEN opening_sign
            ELSE 1
        END
    )
    ELSE 0
END) AS period_cash,

SUM(CASE
    WHEN titip_date BETWEEN ? AND ?
     AND (
            (opening_date IS NOT NULL AND titip_date > opening_date)
         OR (opening_date IS NULL)
     )
    THEN titip_raw * (
        CASE
            WHEN is_opening_target = 1 THEN opening_sign
            ELSE 1
        END
    )
    ELSE 0
END) AS period_titip,

    SUM(CASE
        WHEN is_opening_target = 1 THEN
            (
                CASE
                    WHEN cash_date <= ?
                     AND (opening_date IS NULL OR cash_date > opening_date)
                    THEN cash_raw * opening_sign ELSE 0
                END
                +
                CASE
                    WHEN titip_date <= ?
                     AND (opening_date IS NULL OR titip_date > opening_date)
                    THEN titip_raw * opening_sign ELSE 0
                END
            )
        ELSE 0 END
    ) AS opening_target_total,

SUM(CASE
    WHEN is_orphan = 1
    THEN
        (
            CASE
                WHEN cash_date <= ?
                THEN cash_raw
                ELSE 0
            END
            +
            CASE
                WHEN titip_date <= ?
                THEN titip_raw
                ELSE 0
            END
        )
    ELSE 0
END) AS orphan_total

FROM pay_base
GROUP BY customer_id
";

$payAggParams[] = $startDate;
$payAggParams[] = $startDate;
$payAggParams[] = $startDate;
$payAggParams[] = $endDate;
$payAggParams[] = $startDate;
$payAggParams[] = $endDate;
$payAggParams[] = $endDate;
$payAggParams[] = $endDate;
$payAggParams[] = $endDate;
$payAggParams[] = $endDate;
$payAggTypes .= 'ssssssssss';

$stmtPayAgg = mysqli_prepare($conn, $sqlPayAgg);
mysqli_stmt_bind_param($stmtPayAgg, $payAggTypes, ...$payAggParams);
mysqli_stmt_execute($stmtPayAgg);
$resPayAgg = mysqli_stmt_get_result($stmtPayAgg);

while ($r = mysqli_fetch_assoc($resPayAgg)) {
    $cid = trim((string)($r['customer_id'] ?? ''));
    if (!isset($calc[$cid])) {
        continue;
    }
    foreach (['pre_cash','pre_titip','period_cash','period_titip','opening_target_total','orphan_total'] as $k) {
        $calc[$cid][$k] = (float)($r[$k] ?? 0);
    }
}
mysqli_stmt_close($stmtPayAgg);

/* ============================================================
 * 4A. TIMELINE PEMBAYARAN KHUSUS OPENING
 * ============================================================
 * Agregat opening_target_total cukup untuk rumus saldo, tetapi TIDAK cukup
 * untuk bucket bulan berikutnya. Bucket harus tahu pembayaran terjadi di
 * bulan mana agar urutannya:
 *      rollover bulan -> bayar opening bulan itu -> rollover berikutnya.
 */
$openingPaymentTimeline = [];

$sqlOpeningPayTimeline = "
WITH latest_opening AS (
    SELECT cob.opening_id, cob.customer_id, cob.opening_date, cob.opening_balance
    FROM customer_opening_balance cob
    INNER JOIN (
        SELECT customer_id, MAX(opening_id) AS opening_id
        FROM customer_opening_balance
        WHERE LOWER(COALESCE(status, 'Active')) = 'active'
          AND opening_date <= ?
        GROUP BY customer_id
    ) x ON x.opening_id = cob.opening_id
),
titip_use AS (
    SELECT
        ref_no AS bayar_no,
        customer_id,
        MIN(titip_date) AS titip_date
    FROM detail_titip
    WHERE UPPER(COALESCE(transaction_type,'')) = 'PAKAI'
      AND COALESCE(ref_no,'') <> ''
    GROUP BY ref_no, customer_id
)
SELECT
    hb.customer_id,
    hb.bayar_date AS cash_date,
    COALESCE(tu.titip_date, hb.bayar_date) AS titip_date,
    lo.opening_id AS active_opening_id,
    lo.opening_date,
    lo.opening_balance,
    db.opening_id,
    db.invoice_no,
    UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) AS payment_source,
    hi.invoice_no AS linked_invoice_no,
    hi.invoice_date AS linked_invoice_date,
    CASE
        WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'RETURN'
          OR (
                LOWER(TRIM(COALESCE(hb.keterangan, ''))) = 'retur'
            AND COALESCE(TRIM(db.return_id), '') <> ''
          )
        THEN 0
        WHEN COALESCE(db.cash_amount, 0) > 0
            THEN COALESCE(db.cash_amount, 0)
        ELSE GREATEST(
            COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
            0
        )
    END AS cash_raw,
    CASE
        WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'RETURN'
          OR (
                LOWER(TRIM(COALESCE(hb.keterangan, ''))) = 'retur'
            AND COALESCE(TRIM(db.return_id), '') <> ''
          )
        THEN 0
        ELSE COALESCE(db.titip_amount, 0)
    END AS titip_raw
FROM detail_bayar db
INNER JOIN head_bayar hb
    ON hb.bayar_no = db.bayar_no
INNER JOIN m_customer mc
    ON mc.customer_id = hb.customer_id
   AND COALESCE(mc.is_active, 'Checked') = 'Checked'
INNER JOIN latest_opening lo
    ON lo.customer_id = hb.customer_id
LEFT JOIN titip_use tu
    ON tu.bayar_no = db.bayar_no
   AND tu.customer_id = hb.customer_id
LEFT JOIN head_invoice hi
    ON hi.invoice_no = db.invoice_no
WHERE (
        hb.bayar_date <= ?
     OR COALESCE(tu.titip_date, hb.bayar_date) <= ?
)
  AND UPPER(COALESCE(db.invoice_no, '')) NOT LIKE '%CP-MCP%'
  AND UPPER(COALESCE(db.shipping_no, '')) NOT LIKE '%CP-MCP%'
";

$openingTimelineParams = [$previousDate, $endDate, $endDate];
$openingTimelineTypes = 'sss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlOpeningPayTimeline .= " AND COALESCE(mc.area_code, '') = ? ";
    $openingTimelineParams[] = $filterValue;
    $openingTimelineTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlOpeningPayTimeline .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $openingTimelineParams[] = $filterValue;
    $openingTimelineTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlOpeningPayTimeline .= " AND mc.customer_id = ? ";
    $openingTimelineParams[] = $filterValue;
    $openingTimelineTypes .= 's';
}

$stmtOpeningTimeline = mysqli_prepare($conn, $sqlOpeningPayTimeline);
mysqli_stmt_bind_param(
    $stmtOpeningTimeline,
    $openingTimelineTypes,
    ...$openingTimelineParams
);
mysqli_stmt_execute($stmtOpeningTimeline);
$resOpeningTimeline = mysqli_stmt_get_result($stmtOpeningTimeline);

while ($pr = mysqli_fetch_assoc($resOpeningTimeline)) {
    $cid = trim((string)($pr['customer_id'] ?? ''));
    if ($cid === '' || !isset($openingMap[$cid])) {
        continue;
    }

    $openingDate = (string)($pr['opening_date'] ?? '');
    $paymentSource = strtoupper(trim((string)($pr['payment_source'] ?? 'INVOICE')));
    $dbOpeningId = (int)($pr['opening_id'] ?? 0);
    $activeOpeningId = (int)($pr['active_opening_id'] ?? 0);
    $invoiceNo = trim((string)($pr['invoice_no'] ?? ''));
    $linkedInvoiceNo = trim((string)($pr['linked_invoice_no'] ?? ''));
    $linkedInvoiceDate = (string)($pr['linked_invoice_date'] ?? '');

    $isOpeningTarget = (
        $paymentSource === 'OPENING'
        || ($dbOpeningId > 0 && $dbOpeningId === $activeOpeningId)
        || $invoiceNo === ''
        || $linkedInvoiceNo === ''
        || ($linkedInvoiceDate !== '' && $openingDate !== '' && $linkedInvoiceDate <= $openingDate)
    );

    if (!$isOpeningTarget) {
        continue;
    }

    $sign = ((float)($pr['opening_balance'] ?? 0) < 0) ? -1.0 : 1.0;

    $cashDate = (string)($pr['cash_date'] ?? '');
    $cashRaw = (float)($pr['cash_raw'] ?? 0);
    if ($cashDate !== '' && $cashDate > $openingDate && $cashDate <= $endDate && abs($cashRaw) > 0.0001) {
        $monthKey = date('Y-m', strtotime($cashDate));
        $openingPaymentTimeline[$cid][$monthKey] =
            (float)($openingPaymentTimeline[$cid][$monthKey] ?? 0)
            + ($cashRaw * $sign);
    }

    $titipDate = (string)($pr['titip_date'] ?? '');
    $titipRaw = (float)($pr['titip_raw'] ?? 0);
    if ($titipDate !== '' && $titipDate > $openingDate && $titipDate <= $endDate && abs($titipRaw) > 0.0001) {
        $monthKey = date('Y-m', strtotime($titipDate));
        $openingPaymentTimeline[$cid][$monthKey] =
            (float)($openingPaymentTimeline[$cid][$monthKey] ?? 0)
            + ($titipRaw * $sign);
    }
}
mysqli_stmt_close($stmtOpeningTimeline);

/* Gabungkan adjustment opening ke timeline bulan yang sama. */
foreach ($openingAdjustmentTimeline as $cid => $months) {
    foreach ($months as $monthKey => $amount) {
        $openingPaymentTimeline[$cid][$monthKey] =
            (float)($openingPaymentTimeline[$cid][$monthKey] ?? 0)
            + (float)$amount;
    }
}


/* ============================================================
 * 4B. TITIP BELUM DIGUNAKAN -> BUCKET LEBIH
 * ============================================================
 * Rule bisnis final:
 * - Kolom TITIP tetap hanya memuat titip yang SUDAH dipakai pada bulan laporan.
 * - Saldo titip yang BELUM dipakai merupakan credit balance customer.
 * - Credit titip tersebut ditempatkan di bucket LEBIH.
 *
 * Untuk menjaga carry-forward:
 * - unused_titip_before = saldo titip sampai H-1 bulan laporan.
 * - unused_titip_end    = saldo titip sampai akhir bulan laporan.
 */
$sqlUnusedTitip = "
    SELECT
        dt.customer_id,
        COALESCE(SUM(CASE
            WHEN dt.titip_date <= ?
            THEN COALESCE(dt.amount_in,0) - COALESCE(dt.amount_out,0)
            ELSE 0
        END),0) AS unused_titip_before,

        COALESCE(SUM(CASE
            WHEN dt.titip_date <= ?
            THEN COALESCE(dt.amount_in,0) - COALESCE(dt.amount_out,0)
            ELSE 0
        END),0) AS unused_titip_end
    FROM detail_titip dt
    INNER JOIN m_customer mc
        ON mc.customer_id = dt.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    WHERE dt.titip_date <= ?
";

$unusedTitipParams = [$previousDate, $endDate, $endDate];
$unusedTitipTypes = 'sss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlUnusedTitip .= " AND COALESCE(mc.area_code, '') = ? ";
    $unusedTitipParams[] = $filterValue;
    $unusedTitipTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlUnusedTitip .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $unusedTitipParams[] = $filterValue;
    $unusedTitipTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlUnusedTitip .= " AND mc.customer_id = ? ";
    $unusedTitipParams[] = $filterValue;
    $unusedTitipTypes .= 's';
}

$sqlUnusedTitip .= " GROUP BY dt.customer_id ";

$stmtUnusedTitip = mysqli_prepare($conn, $sqlUnusedTitip);
mysqli_stmt_bind_param(
    $stmtUnusedTitip,
    $unusedTitipTypes,
    ...$unusedTitipParams
);
mysqli_stmt_execute($stmtUnusedTitip);
$resUnusedTitip = mysqli_stmt_get_result($stmtUnusedTitip);

while ($ut = mysqli_fetch_assoc($resUnusedTitip)) {
    $cid = trim((string)($ut['customer_id'] ?? ''));
    if (!isset($calc[$cid])) {
        continue;
    }

    /*
     * Hanya saldo positif yang dianggap titip belum digunakan.
     * Saldo negatif tidak dipaksa menjadi credit balance.
     */
    $calc[$cid]['unused_titip_before'] = max(
        (float)($ut['unused_titip_before'] ?? 0),
        0.0
    );
    $calc[$cid]['unused_titip_end'] = max(
        (float)($ut['unused_titip_end'] ?? 0),
        0.0
    );
}
mysqli_stmt_close($stmtUnusedTitip);

/* ============================================================
 * 5. RETUR
 * ============================================================
 * Retur periode tetap mengurangi kolom PENJUALAN.
 * Untuk bucket:
 * - retur normal yang terhubung invoice post-opening sudah mengurangi outstanding
 *   invoice pada CTE return_inv di atas;
 * - retur CP-MCP / orphan / retur terhadap invoice historis yang sudah tercakup
 *   opening tidak memiliki invoice eligible untuk ditempati, sehingga efek kreditnya
 *   ditempatkan pada bucket Lebih sebagai credit balance, BUKAN sebagai "bucket retur".
 */
$sqlReturnAgg = "
WITH latest_opening AS (
    SELECT cob.customer_id, cob.opening_date
    FROM customer_opening_balance cob
    INNER JOIN (
        SELECT customer_id, MAX(opening_id) AS opening_id
        FROM customer_opening_balance
        WHERE LOWER(COALESCE(status, 'Active')) = 'active'
          AND opening_date <= ?
        GROUP BY customer_id
    ) x ON x.opening_id = cob.opening_id
),
used_return AS (
    /*
     * RETURN YANG BENAR-BENAR SUDAH DIGUNAKAN
     * ----------------------------------------------------------
     * payment_source='RETURN' hanyalah baris audit/link retur standalone.
     * Baris tersebut TIDAK berarti nilai retur sudah dipakai untuk settlement.
     *
     * Retur baru dianggap terpakai bila return_id melekat pada detail pembayaran
     * non-RETURN (mis. INVOICE) sampai akhir periode.
     */
    SELECT DISTINCT
        TRIM(db.return_id) AS return_id
    FROM detail_bayar db
    INNER JOIN head_bayar hb
        ON hb.bayar_no = db.bayar_no
    WHERE COALESCE(TRIM(db.return_id),'') <> ''
      AND hb.bayar_date <= ?
      AND UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE')) <> 'RETURN'
),
ret_base AS (
    SELECT
        hri.customer_id,
        hri.return_date,
        hri.invoice_no,
        hri.shipping_no,
        lo.opening_date,
        hi_ret.invoice_date AS linked_invoice_date,
        0 AS is_legacy_target,
        CASE
            WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
              OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
            THEN COALESCE(hri.grand_total, 0)
            ELSE COALESCE(hri.return_amount, 0)
        END AS return_value,
        CASE
            WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
              OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
              OR COALESCE(hri.invoice_no, '') = ''
              OR hi_ret.invoice_no IS NULL
            THEN 1 ELSE 0
        END AS is_standalone_credit,

        CASE
            WHEN ur.return_id IS NULL
             AND (
                    UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                 OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                 OR COALESCE(hri.invoice_no, '') = ''
                 OR hi_ret.invoice_no IS NULL
             )
            THEN 1 ELSE 0
        END AS is_unused_credit,

        CASE
            WHEN (
                    COALESCE(hri.invoice_no, '') = ''
                 OR hi_ret.invoice_no IS NULL
                 )
             AND UPPER(COALESCE(hri.invoice_no, '')) NOT LIKE '%CP-MCP%'
             AND UPPER(COALESCE(hri.shipping_no, '')) NOT LIKE '%CP-MCP%'
            THEN 1 ELSE 0
        END AS is_bucket_credit
    FROM head_retur_invoice hri
    INNER JOIN m_customer mc
        ON mc.customer_id = hri.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    LEFT JOIN latest_opening lo
        ON lo.customer_id = hri.customer_id
    LEFT JOIN head_invoice hi_ret
        ON hi_ret.invoice_no = hri.invoice_no
    LEFT JOIN used_return ur
        ON ur.return_id = TRIM(hri.return_id)
    WHERE hri.return_date <= ?
      AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
";

$returnAggParams = [$previousDate, $endDate, $endDate];
$returnAggTypes = 'sss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlReturnAgg .= " AND COALESCE(mc.area_code, '') = ? ";
    $returnAggParams[] = $filterValue;
    $returnAggTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlReturnAgg .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $returnAggParams[] = $filterValue;
    $returnAggTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlReturnAgg .= " AND mc.customer_id = ? ";
    $returnAggParams[] = $filterValue;
    $returnAggTypes .= 's';
}

$sqlReturnAgg .= "
),
applicable AS (
    SELECT *
    FROM ret_base
    WHERE is_legacy_target = 0
      AND (
            (
                opening_date IS NOT NULL
                AND return_date > opening_date
            )
            OR
            (
                opening_date IS NULL
            )
      )
)
SELECT
    customer_id,
    SUM(CASE WHEN return_date < ? THEN return_value ELSE 0 END) AS pre_return,
    SUM(CASE WHEN return_date BETWEEN ? AND ? THEN return_value ELSE 0 END) AS period_return,
    SUM(CASE WHEN return_date < ? AND is_standalone_credit = 1 THEN return_value ELSE 0 END) AS pre_return_offset,
    SUM(CASE WHEN return_date BETWEEN ? AND ? AND is_standalone_credit = 1 THEN return_value ELSE 0 END) AS period_return_offset,
    SUM(CASE WHEN is_bucket_credit = 1 THEN return_value ELSE 0 END) AS bucket_credit_return,

    /*
     * BUCKET CREDIT RETUR STANDALONE / CP-MCP
     * ----------------------------------------------------------
     * Retur bulan berjalan -> Belum Jatuh Tempo (credit negatif).
     * Retur bulan sebelumnya -> 1-30.
     * Dua bulan sebelumnya -> 31-60.
     * Tiga bulan sebelumnya -> 61-90.
     * Lebih lama -> Lebih.
     *
     * Ini membuat credit carry-forward mengikuti prinsip rollover bulanan
     * yang sama dengan saldo positif.
     */
    SUM(CASE
        WHEN is_unused_credit = 1
         AND return_date BETWEEN ? AND ?
        THEN return_value ELSE 0 END
    ) AS credit_bjt,

    SUM(CASE
        WHEN is_unused_credit = 1
         AND return_date >= DATE_SUB(?, INTERVAL 1 MONTH)
         AND return_date < ?
        THEN return_value ELSE 0 END
    ) AS credit_b_1_30,

    SUM(CASE
        WHEN is_unused_credit = 1
         AND return_date >= DATE_SUB(?, INTERVAL 2 MONTH)
         AND return_date < DATE_SUB(?, INTERVAL 1 MONTH)
        THEN return_value ELSE 0 END
    ) AS credit_b_31_60,

    SUM(CASE
        WHEN is_unused_credit = 1
         AND return_date >= DATE_SUB(?, INTERVAL 3 MONTH)
         AND return_date < DATE_SUB(?, INTERVAL 2 MONTH)
        THEN return_value ELSE 0 END
    ) AS credit_b_61_90,

    SUM(CASE
        WHEN is_unused_credit = 1
         AND return_date < DATE_SUB(?, INTERVAL 3 MONTH)
        THEN return_value ELSE 0 END
    ) AS credit_b_lebih
FROM applicable
GROUP BY customer_id
";

$returnAggParams[] = $startDate;
$returnAggParams[] = $startDate;
$returnAggParams[] = $endDate;
$returnAggParams[] = $startDate;
$returnAggParams[] = $startDate;
$returnAggParams[] = $endDate;

/* credit_bjt */
$returnAggParams[] = $startDate;
$returnAggParams[] = $endDate;

/* credit_b_1_30 */
$returnAggParams[] = $startDate;
$returnAggParams[] = $startDate;

/* credit_b_31_60 */
$returnAggParams[] = $startDate;
$returnAggParams[] = $startDate;

/* credit_b_61_90 */
$returnAggParams[] = $startDate;
$returnAggParams[] = $startDate;

/* credit_b_lebih */
$returnAggParams[] = $startDate;

$returnAggTypes .= 'sssssssssssssss';

$stmtReturnAgg = mysqli_prepare($conn, $sqlReturnAgg);
mysqli_stmt_bind_param($stmtReturnAgg, $returnAggTypes, ...$returnAggParams);
mysqli_stmt_execute($stmtReturnAgg);
$resReturnAgg = mysqli_stmt_get_result($stmtReturnAgg);

while ($r = mysqli_fetch_assoc($resReturnAgg)) {
    $cid = trim((string)($r['customer_id'] ?? ''));
    if (!isset($calc[$cid])) {
        continue;
    }
    foreach ([
        'pre_return',
        'period_return',
        'pre_return_offset',
        'period_return_offset',
        'bucket_credit_return',
        'credit_bjt',
        'credit_b_1_30',
        'credit_b_31_60',
        'credit_b_61_90',
        'credit_b_lebih'
    ] as $k) {
        $calc[$cid][$k] = (float)($r[$k] ?? 0);
    }
}
mysqli_stmt_close($stmtReturnAgg);

/* ============================================================
 * 6. FINALISASI CARRY-FORWARD PER CUSTOMER
 * ============================================================ */
foreach ($masterCustomers as $cid => $m) {
    $c = $calc[$cid];
    $hasOpening = isset($openingMap[$cid]);

    /*
     * SOURCE OF TRUTH SALDO AWAL MIGRASI:
     * Bila seed bucket valid tersedia dan opening_id cocok, total bucket menjadi
     * saldo awal resmi Aging. Ini memastikan sisi formula dan sisi bucket
     * berasal dari snapshot yang sama.
     *
     * customer_opening_balance tetap menjadi fallback bila seed bucket belum ada.
     */
    $hasValidOpeningBucket = (
        $hasOpening
        && isset($openingBucketMap[$cid])
        && (int)$openingBucketMap[$cid]['opening_id'] === (int)$openingMap[$cid]['opening_id']
    );

    $openingBalance = 0.0;
    if ($hasValidOpeningBucket) {
        $openingBalance = sumOpeningBucketSeed($openingBucketMap[$cid]);
    } elseif ($hasOpening) {
        $openingBalance = (float)$openingMap[$cid]['opening_balance'];
    }

    $adj = $adjustmentMap[$cid] ?? [
        'pre_awal'=>0.0,'pre_penjualan'=>0.0,'pre_bayar'=>0.0,'pre_titip'=>0.0,
        'period_awal'=>0.0,'period_penjualan'=>0.0,'period_bayar'=>0.0,'period_titip'=>0.0,
        'opening_payment_until_end'=>0.0
    ];

    /*
     * BAYAR mengikuti transaksi pembayaran nyata saja.
     * Jangan membuat Bayar negatif sintetis dari Retur.
     *
     * Retur sudah mengurangi piutang melalui Penjualan Neto.
     * Jika belum ada head_bayar pada bulan laporan, kolom Bayar harus tetap 0.
     */
    $preCashForFormula = $c['pre_cash'];
    $periodCash = $c['period_cash'];

    if ($hasOpening) {
        $saldoAwal =
            $openingBalance
            + $c['pre_invoice']
            - $c['pre_return']
            - $preCashForFormula
            - $c['pre_titip'];
    } else {
        $saldoAwal =
            $c['pre_invoice']
            - $c['pre_return']
            - $preCashForFormula
            - $c['pre_titip'];
    }

    // Adjustment periode sebelumnya ikut carry-forward.
    $saldoAwal +=
        $adj['pre_awal']
        + $adj['pre_penjualan']
        - $adj['pre_bayar']
        - $adj['pre_titip'];

    $periodInvoice = $c['period_invoice'];
    $periodTitip = $c['period_titip'];
    $periodReturn = $c['period_return'];

    $penjualanNeto = $periodInvoice - $periodReturn + $adj['period_penjualan'];
    $periodCash += $adj['period_bayar'];
    $periodTitip += $adj['period_titip'];
    $saldoAwal += $adj['period_awal'];

    /*
     * Titip belum digunakan adalah credit balance Aging.
     * Saldo Awal memakai saldo titip belum terpakai per H-1.
     * Saldo Akhir memakai saldo titip belum terpakai per akhir periode.
     *
     * Kolom TITIP tetap hanya menunjukkan titip yang SUDAH dipakai.
     */
    $saldoAwalBase = $saldoAwal;
    $unusedTitipBefore = (float)$c['unused_titip_before'];
    $unusedTitipEnd = (float)$c['unused_titip_end'];

    $saldoAwal = $saldoAwalBase - $unusedTitipBefore;

    $akhirBase =
        $saldoAwalBase
        + $penjualanNeto
        - $periodCash
        - $periodTitip;

    $akhir = $akhirBase - $unusedTitipEnd;

    /* Bucket invoice post-cutoff dihitung dari invoice eligible. */
    $b1 = $c['b_1_30'];
    $b2 = $c['b_31_60'];
    $b3 = $c['b_61_90'];
    $bLebih = $c['b_lebih'];
    $bBelumJatuhTempo = $c['belum_jatuh_tempo'];

    if ($hasOpening) {
        if ($hasValidOpeningBucket) {

            $seed = $openingBucketMap[$cid];

            /*
             * RULE PRESISI:
             * Jangan hitung umur dari opening_date dan jangan mengurangi seluruh
             * pembayaran secara kumulatif setelah semua rollover selesai.
             * Simulasikan kronologis per bulan dari bucket_date sampai asOfDate.
             */
            $legacyBucket = simulateOpeningBuckets(
                $seed,
                $asOfDate,
                $openingPaymentTimeline[$cid] ?? []
            );

            $b1 += $legacyBucket['b_1_30'];
            $b2 += $legacyBucket['b_31_60'];
            $b3 += $legacyBucket['b_61_90'];
            $bLebih += $legacyBucket['b_lebih'];
            $bBelumJatuhTempo += $legacyBucket['belum_jatuh_tempo'];
        } else {
            /*
             * Bucket seed sekarang merupakan data migrasi WAJIB.
             * Fallback hanya menjaga laporan tetap dapat dibuka. Tidak ada lagi
             * tebakan umur berdasarkan opening_date.
             */
            $openingOutstanding =
                $openingBalance
                - $c['opening_target_total']
                - $adj['opening_payment_until_end'];

            $bLebih += $openingOutstanding;
        }
    }

    /* Orphan payment tetap diperlakukan sebagai credit customer. */
    $bLebih -= $c['orphan_total'];

    /*
     * RULE FINAL BUCKET LEBIH
     * ----------------------------------------------------------
     * Kolom LEBIH merupakan nilai NET:
     *   + outstanding invoice > 90 hari
     *   - retur yang belum digunakan
     *   - titip yang belum digunakan
     *
     * Bila customer memiliki ketiganya, semuanya dijumlahkan pada kolom LEBIH.
     */
    $unusedReturnCredit =
        (float)$c['credit_bjt']
        + (float)$c['credit_b_1_30']
        + (float)$c['credit_b_31_60']
        + (float)$c['credit_b_61_90']
        + (float)$c['credit_b_lebih'];

    $unusedTitipEnd = (float)$c['unused_titip_end'];

    $bLebih -= $unusedReturnCredit;
    $bLebih -= $unusedTitipEnd;

    $amounts = initAmountRow();
    $amounts['saldo_awal'] = $saldoAwal;
    $amounts['penjualan'] = $penjualanNeto;
    $amounts['bayar'] = $periodCash;
    $amounts['titip_used'] = $periodTitip;
    $amounts['titip_unused'] = $unusedTitipEnd;
    $amounts['retur'] = $periodReturn;
    $amounts['akhir'] = $akhir;
    $amounts['b_1_30'] = $b1;
    $amounts['b_31_60'] = $b2;
    $amounts['b_61_90'] = $b3;
    $amounts['b_lebih'] = $bLebih;
    $amounts['belum_jatuh_tempo'] = $bBelumJatuhTempo;

    $bucketTotal =
        $amounts['b_1_30']
        + $amounts['b_31_60']
        + $amounts['b_61_90']
        + $amounts['b_lebih']
        + $amounts['belum_jatuh_tempo'];

    $selisih = $akhir - $bucketTotal;

    $activityTotal = 0.0;
    foreach ($amounts as $v) {
        $activityTotal += abs((float)$v);
    }
    if ($activityTotal < 0.0001) continue;

    $customers[$cid] = [
        'customer_id' => $cid,
        'customer_name' => $m['customer_name'],
        'city' => $m['city'],
        'opening_date' => $hasOpening ? $openingMap[$cid]['opening_date'] : '',
        'amounts' => $amounts,
        'selisih_balance' => $selisih,
        'bucket_seed_missing' => ($hasOpening && !$hasValidOpeningBucket),
        'debug' => [
            'bucket_total' => $bucketTotal,
            'opening_target_total' => (float)$c['opening_target_total'],
            'opening_timeline_total' => array_sum($openingPaymentTimeline[$cid] ?? []),
            'orphan_total' => (float)$c['orphan_total'],
            'bucket_credit_return' => (float)$c['bucket_credit_return'],
            'period_return_offset' => (float)$c['period_return_offset'],
            'credit_bjt' => (float)$c['credit_bjt'],
            'credit_b_1_30' => (float)$c['credit_b_1_30'],
            'credit_b_31_60' => (float)$c['credit_b_31_60'],
            'credit_b_61_90' => (float)$c['credit_b_61_90'],
            'credit_b_lebih' => (float)$c['credit_b_lebih'],
            'unused_titip_before' => (float)$c['unused_titip_before'],
            'unused_titip_end' => (float)$c['unused_titip_end'],
        ],
    ];
}

/* ============================================================
 * 7. GROUP KOTA + GRAND TOTAL
 * ============================================================ */
$grouped = [];
foreach ($customers as $customer) {
    $city = $customer['city'];
    if (!isset($grouped[$city])) {
        $grouped[$city] = [
            'rows' => [],
            'totals' => initAmountRow(),
        ];
    }
    $grouped[$city]['rows'][] = $customer;
    addAmounts($grouped[$city]['totals'], $customer['amounts']);
}

ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);
foreach ($grouped as &$cityGroup) {
    usort($cityGroup['rows'], function ($a, $b) {
        return strcasecmp($a['customer_name'], $b['customer_name']);
    });
}
unset($cityGroup);

$grand = initAmountRow();
foreach ($grouped as $cityGroup) {
    addAmounts($grand, $cityGroup['totals']);
}

/*
 * Karena Titip belum digunakan adalah credit bucket internal dan tidak ditampilkan
 * pada kolom TITIP, GRAND AKHIR mengikuti penjumlahan akhir per customer.
 */
$grandFormula1 = $grand['akhir'];

$grandFormula2 =
    $grand['b_1_30']
    + $grand['b_31_60']
    + $grand['b_61_90']
    + $grand['b_lebih']
    + $grand['belum_jatuh_tempo'];

$grandSelisih = $grandFormula1 - $grandFormula2;

/*
 * DEBUG BALANCE PER CUSTOMER
 * Ditampilkan hanya bila ada selisih. Ini membantu menemukan sumber
 * Grand Total yang tidak balance tanpa menebak-nebak.
 */
$balanceDebugRows = [];
foreach ($customers as $dbgCustomer) {
    if (abs((float)$dbgCustomer['selisih_balance']) <= 0.01) {
        continue;
    }

    $da = $dbgCustomer['amounts'];
    $dd = $dbgCustomer['debug'] ?? [];

    $balanceDebugRows[] = [
        'customer_id' => $dbgCustomer['customer_id'],
        'customer_name' => $dbgCustomer['customer_name'],
        'awal' => (float)$da['saldo_awal'],
        'penjualan' => (float)$da['penjualan'],
        'bayar' => (float)$da['bayar'],
        'titip' => (float)$da['titip_used'],
        'akhir' => (float)$da['akhir'],
        'bucket_total' => (float)($dd['bucket_total'] ?? 0),
        'selisih' => (float)$dbgCustomer['selisih_balance'],
        'opening_target_total' => (float)($dd['opening_target_total'] ?? 0),
        'opening_timeline_total' => (float)($dd['opening_timeline_total'] ?? 0),
        'orphan_total' => (float)($dd['orphan_total'] ?? 0),
        'bucket_credit_return' => (float)($dd['bucket_credit_return'] ?? 0),
        'period_return_offset' => (float)($dd['period_return_offset'] ?? 0),
    ];
}

usort($balanceDebugRows, function ($a, $b) {
    return abs($b['selisih']) <=> abs($a['selisih']);
});

/*
 * PAGINATION KHUSUS CETAK
 * ------------------------------------------------------------
 * Browser biasa akan mengulang <tfoot> pada setiap lembar cetak.
 * Karena GRAND TOTAL hanya boleh tampil pada halaman terakhir,
 * data cetak dibagi menjadi beberapa halaman secara eksplisit.
 *
 * Setiap halaman antara menampilkan:
 *     "Berlanjut ke halaman berikutnya..."
 *
 * GRAND TOTAL hanya dirender pada halaman terakhir.
 */
$printPages = [];
$printPageRows = [];
$printPageUnits = 0;

// Batas konservatif agar satu halaman tidak terpotong oleh browser.
// Ukuran kertas cetak report ini: 377.8mm x 279.4mm landscape.
$maxPrintUnitsPerPage = 44;

$flushPrintPage = static function () use (&$printPages, &$printPageRows, &$printPageUnits) {
    if (!empty($printPageRows)) {
        $printPages[] = $printPageRows;
        $printPageRows = [];
        $printPageUnits = 0;
    }
};

foreach ($grouped as $printCity => $printCityGroup) {
    // Hindari judul kota sendirian di ujung halaman.
    if ($printPageUnits > 0 && ($printPageUnits + 3) > $maxPrintUnitsPerPage) {
        $flushPrintPage();
    }

    $printPageRows[] = [
        'type' => 'city',
        'city' => $printCity,
    ];
    $printPageUnits++;

    foreach ($printCityGroup['rows'] as $printCustomer) {
        if (($printPageUnits + 1) > $maxPrintUnitsPerPage) {
            $flushPrintPage();

            // Jika satu kota berlanjut ke halaman berikutnya,
            // ulangi nama kota agar pembaca tidak kehilangan konteks.
            $printPageRows[] = [
                'type' => 'city_continued',
                'city' => $printCity,
            ];
            $printPageUnits++;
        }

        $printPageRows[] = [
            'type' => 'customer',
            'customer' => $printCustomer,
        ];
        $printPageUnits++;
    }

    // Pastikan TOTAL KOTA tidak terpisah dari halaman secara liar.
    if (($printPageUnits + 1) > $maxPrintUnitsPerPage) {
        $flushPrintPage();

        $printPageRows[] = [
            'type' => 'city_continued',
            'city' => $printCity,
        ];
        $printPageUnits++;
    }

    $printPageRows[] = [
        'type' => 'city_total',
        'city' => $printCity,
        'totals' => $printCityGroup['totals'],
    ];
    $printPageUnits++;
}

$flushPrintPage();

// Bila tidak ada data, tetap sediakan satu halaman cetak.
if (empty($printPages)) {
    $printPages[] = [];
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= h($title) ?></title>

    <style>
        @page {
            size: 377.8mm 279.4mm;
            margin: 5mm 6mm;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family:
                "Courier New",
                Courier,
                monospace;
            font-size: 12px;
            color: #000;
            background: #eef1f5;
            padding: 16px;
        }

        .toolbar {
            width: 100%;
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 16px;
        }

        .btn-action {
            border: none;
            border-radius: 6px;
            color: #fff;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
            box-shadow:
                0 2px 6px rgba(0,0,0,.2);
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

        .screen-scroll {
            width: 100%;
            overflow-x: auto;
            overflow-y: visible;
            padding-bottom: 12px;
            -webkit-overflow-scrolling: touch;
        }

        .screen-scroll::-webkit-scrollbar {
            height: 11px;
        }

        .screen-scroll::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 5px;
        }

        .screen-scroll::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 5px;
        }

        .print-wrap {
            width: 1680px;
            min-width: 1680px;
            margin: 0 auto;
            padding: 14px 18px;
            background: #fff;
            box-shadow:
                0 2px 12px rgba(0,0,0,.1);
            border-radius: 4px;
        }

        .title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 3px;
        }

        .subtitle {
            font-size: 11px;
            margin-bottom: 3px;
        }

        .printed {
            text-align: right;
            font-size: 10px;
            color: #555;
            margin-bottom: 6px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 9px;
        }

        th {
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
            padding: 5px 3px;
            text-align: center;
            font-weight: bold;
            white-space: nowrap;
        }

        td {
            padding: 3px 4px;
            vertical-align: middle;
            white-space: nowrap;
        }

        .customer-col {
            width: 245px;
            text-align: left;
            white-space: normal;
        }

        .money-col {
            width: 112px;
        }

        .money-cell {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .city-row td {
            padding-top: 8px;
            padding-bottom: 3px;
            font-weight: bold;
            font-size: 10px;
            border-top: 1px solid #000;
        }

        .city-total td {
            border-top: 1px solid #777;
            border-bottom: 1px solid #000;
            font-weight: bold;
            background: #f7f7f7;
            padding-top: 4px;
            padding-bottom: 4px;
        }

        .grand-total td {
            border-top: 2px solid #000;
            border-bottom: 2px solid #000;
            font-weight: bold;
            background: #e8e8e8;
            padding-top: 6px;
            padding-bottom: 6px;
        }

        .return-negative {
            color: #a00000;
            font-weight: bold;
        }

        .text-right {
            text-align: right;
        }

        .text-center {
            text-align: center;
        }

        .no-data {
            padding: 20px;
            text-align: center;
            color: #777;
            font-size: 12px;
        }

        .report-note {
            margin-top: 7px;
            font-size: 9px;
            color: #555;
        }

        .print-report {
            display: none;
        }

        .print-page {
            background: #fff;
        }

        .continuation-message {
            margin-top: 4mm;
            padding-top: 2mm;
            border-top: 1px dashed #777;
            text-align: right;
            font-size: 8pt;
            font-style: italic;
            font-weight: bold;
        }

        @media print {
            body {
                padding: 0;
                background: #fff;
            }

            .no-print {
                display: none !important;
            }


            .screen-report {
                display: none !important;
            }

            .print-report {
                display: block !important;
            }

            .print-report .print-page {
                width: 365.8mm !important;
                min-width: 365.8mm !important;
                max-width: 365.8mm !important;
                height: 267mm !important;
                min-height: 267mm !important;
                margin: 0 !important;
                padding: 0 !important;
                display: flex !important;
                flex-direction: column !important;
                page-break-after: always;
                break-after: page;
                overflow: hidden !important;
            }

            .print-report .print-page:last-child {
                page-break-after: auto;
                break-after: auto;
            }

            .print-report .page-table-wrap {
                flex: 0 0 auto;
            }

            .print-report .continuation-message {
                margin-top: auto;
                padding-top: 2mm;
                padding-bottom: 1mm;
                border-top: 1px dashed #777;
                text-align: right;
                font-size: 7pt;
                font-style: italic;
                font-weight: bold;
            }

            .print-report .grand-total {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            .screen-scroll {
                overflow: visible !important;
                padding: 0;
            }

            html,
            body {
                width: 377.8mm !important;
                min-width: 377.8mm !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            }

            .print-wrap {
                width: 365.8mm !important;
                min-width: 365.8mm !important;
                max-width: 365.8mm !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }

            .title {
                font-size: 10pt;
                line-height: 1.1;
            }

            .subtitle {
                font-size: 8pt;
                line-height: 1.1;
            }

            .printed {
                font-size: 7pt;
                margin-bottom: 2mm;
            }

            table {
                width: 100% !important;
                table-layout: fixed !important;
                font-size: 7pt;
                line-height: 1.05;
            }

            th {
                padding: 1.5mm 0.7mm;
                font-weight: bold;
            }

            td {
                padding: 1.1mm 0.7mm;
            }

            .customer-col {
                width: 55mm !important;
            }

            .money-col {
                width: 28.2mm !important;
            }

            .city-row td {
                font-size: 7.5pt;
                padding-top: 2mm;
                padding-bottom: 0.8mm;
            }

            .city-total td {
                padding-top: 1.2mm;
                padding-bottom: 1.2mm;
            }

            .grand-total td {
                padding-top: 1.5mm;
                padding-bottom: 1.5mm;
            }

            .report-note {
                font-size: 6.5pt;
                margin-top: 2mm;
            }

            .city-total td,
            .grand-total td {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
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
                width: 1500px;
                min-width: 1500px;
            }
        }
    </style>
</head>

<body>

<div class="toolbar no-print">
    <a
        href="../../index.php?page=aging_piutang"
        class="btn-action btn-back"
    >
        KEMBALI
    </a>

    <button
        type="button"
        class="btn-action btn-print"
        onclick="window.print()"
    >
        CETAK LAPORAN
    </button>
</div>

<div class="no-print" style="
    max-width:1680px;
    margin:0 auto 12px;
    padding:9px 12px;
    background:#fffbe6;
    border:1px solid #e0c97f;
    font-family:Arial,Helvetica,sans-serif;
    font-size:12px;
    line-height:1.5;
">
    Setting Epson LQ-2190:
    <strong>Paper 14 7/8 × 11 inch</strong>,
    ukuran <strong>377,8 × 279,4 mm</strong>,
    orientasi <strong>Landscape</strong>,
    skala <strong>100%</strong>,
    margin minimum, dan nonaktifkan header/footer browser.
</div>

<?php
$missingBucketCount = 0;
foreach ($customers as $cc) {
    if (!empty($cc['bucket_seed_missing'])) $missingBucketCount++;
}
?>
<?php if ($missingBucketCount > 0): ?>
<div class="no-print" style="max-width:1680px;margin:0 auto 12px;padding:9px 12px;background:#fff3cd;border:1px solid #d6b656;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;">
    <strong>INFO MIGRASI BUCKET:</strong>
    <?= h($missingBucketCount) ?> customer mempunyai opening balance tetapi belum mempunyai seed
    di <code>customer_opening_balance_bucket</code>. Total saldo tetap dihitung, namun letak bucket historis
    customer tersebut sementara difallback ke kolom Lebih.
</div>
<?php endif; ?>

<?php if (abs($grandSelisih) > 0.01): ?>
<div class="no-print" style="max-width:1680px;margin:0 auto 12px;padding:9px 12px;background:#ffe8e8;border:1px solid #d9534f;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;">
    <strong>PERINGATAN BALANCE:</strong> Grand Total Rumus 1 dan Rumus 2 berbeda sebesar
    Rp <?= h(formatMoney($grandSelisih)) ?>. Periksa transaksi orphan/overpayment.
</div>
<?php endif; ?>

<?php if (!empty($balanceDebugRows)): ?>
<div class="no-print" style="
    max-width:1680px;
    margin:0 auto 12px;
    padding:10px 12px;
    background:#fff;
    border:1px solid #999;
    font-family:Arial,Helvetica,sans-serif;
    font-size:11px;
    overflow-x:auto;
">
    <div style="font-weight:bold;margin-bottom:8px;">
        DEBUG CUSTOMER TIDAK BALANCE
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:10px;">
        <thead>
            <tr>
                <th style="border:1px solid #aaa;padding:4px;">Customer ID</th>
                <th style="border:1px solid #aaa;padding:4px;">Customer</th>
                <th style="border:1px solid #aaa;padding:4px;">Awal</th>
                <th style="border:1px solid #aaa;padding:4px;">Penjualan</th>
                <th style="border:1px solid #aaa;padding:4px;">Bayar</th>
                <th style="border:1px solid #aaa;padding:4px;">Titip</th>
                <th style="border:1px solid #aaa;padding:4px;">Akhir</th>
                <th style="border:1px solid #aaa;padding:4px;">Bucket Total</th>
                <th style="border:1px solid #aaa;padding:4px;">Selisih</th>
                <th style="border:1px solid #aaa;padding:4px;">Opening Target</th>
                <th style="border:1px solid #aaa;padding:4px;">Opening Timeline</th>
                <th style="border:1px solid #aaa;padding:4px;">Orphan</th>
                <th style="border:1px solid #aaa;padding:4px;">Bucket Credit Return</th>
                <th style="border:1px solid #aaa;padding:4px;">Return Offset</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($balanceDebugRows as $dbg): ?>
            <tr>
                <td style="border:1px solid #ccc;padding:4px;"><?= h($dbg['customer_id']) ?></td>
                <td style="border:1px solid #ccc;padding:4px;"><?= h($dbg['customer_name']) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['awal'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['penjualan'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['bayar'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['titip'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['akhir'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['bucket_total'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;font-weight:bold;"><?= h(formatMoney($dbg['selisih'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['opening_target_total'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['opening_timeline_total'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['orphan_total'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['bucket_credit_return'])) ?></td>
                <td style="border:1px solid #ccc;padding:4px;text-align:right;"><?= h(formatMoney($dbg['period_return_offset'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="screen-scroll screen-report">
    <div class="print-wrap">
        <div class="title">
            <?= h($title) ?>
        </div>

        <div class="subtitle">
            <?= h($subtitle) ?>
        </div>

        <div class="printed">
            Dicetak:
            <?= h($printedAt) ?>
        </div>

        <table>
            <thead>
                <tr>
                    <th class="customer-col">
                        Nama Customer
                    </th>

                    <th class="money-col">
                        Awal
                    </th>

                    <th class="money-col">
                        Penjualan
                    </th>

                    <th class="money-col">
                        Bayar
                    </th>

                    <th class="money-col">
                        Titip
                    </th>

                    <th class="money-col">
                        Akhir
                    </th>

                    <th class="money-col">
                        1-30 Hari
                    </th>

                    <th class="money-col">
                        31-60 Hari
                    </th>

                    <th class="money-col">
                        61-90 Hari
                    </th>

                    <th class="money-col">
                        Lebih
                    </th>

                    <th class="money-col">
                        Blm Jth. Tempo
                    </th>
                </tr>
            </thead>

            <tbody>
                <?php if (empty($grouped)): ?>
                    <tr>
                        <td
                            colspan="11"
                            class="no-data"
                        >
                            Tidak ada data aging piutang untuk filter ini.
                        </td>
                    </tr>
                <?php else: ?>

                    <?php foreach ($grouped as $city => $cityGroup): ?>
                        <tr class="city-row">
                            <td colspan="11">
                                <?= h($city) ?>
                            </td>
                        </tr>

                        <?php foreach ($cityGroup['rows'] as $customer): ?>
                            <?php $a = $customer['amounts']; ?>

                            <tr>
                                <td class="customer-col">
                                    <?= h($customer['customer_name']) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['saldo_awal'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['penjualan'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['bayar'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['titip_used'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['akhir'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['b_1_30'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['b_31_60'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['b_61_90'])) ?>
                                </td>

                                <td
                                    class="money-cell
                                    <?= $a['b_lebih'] < 0 ? 'return-negative' : '' ?>"
                                >
                                    <?= h(formatMoney($a['b_lebih'])) ?>
                                </td>

                                <td class="money-cell">
                                    <?= h(formatMoney($a['belum_jatuh_tempo'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php $ct = $cityGroup['totals']; ?>

                        <tr class="city-total">
                            <td class="customer-col">
                                TOTAL <?= h($city) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['saldo_awal'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['penjualan'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['bayar'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['titip_used'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['akhir'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['b_1_30'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['b_31_60'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['b_61_90'])) ?>
                            </td>

                            <td
                                class="money-cell
                                <?= $ct['b_lebih'] < 0 ? 'return-negative' : '' ?>"
                            >
                                <?= h(formatMoney($ct['b_lebih'])) ?>
                            </td>

                            <td class="money-cell">
                                <?= h(formatMoney($ct['belum_jatuh_tempo'])) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <tr class="grand-total">
                    <td class="customer-col">
                        GRAND TOTAL
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['saldo_awal'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['penjualan'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['bayar'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['titip_used'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['akhir'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['b_1_30'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['b_31_60'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['b_61_90'])) ?>
                    </td>

                    <td
                        class="money-cell
                        <?= $grand['b_lebih'] < 0 ? 'return-negative' : '' ?>"
                    >
                        <?= h(formatMoney($grand['b_lebih'])) ?>
                    </td>

                    <td class="money-cell">
                        <?= h(formatMoney($grand['belum_jatuh_tempo'])) ?>
                    </td>
                </tr>
            </tbody>


        </table>
    </div>
</div>


<div class="print-report">
    <?php $totalPrintPages = count($printPages); ?>

    <?php foreach ($printPages as $printPageIndex => $printRows): ?>
        <?php $isLastPrintPage = ($printPageIndex === $totalPrintPages - 1); ?>

        <section class="print-page">
            <div class="title">
                <?= h($title) ?>
            </div>

            <div class="subtitle">
                <?= h($subtitle) ?>
            </div>

            <div class="printed">
                Dicetak: <?= h($printedAt) ?>
                &nbsp; | &nbsp;
                Halaman <?= h($printPageIndex + 1) ?> / <?= h($totalPrintPages) ?>
            </div>

            <div class="page-table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th class="customer-col">Nama Customer</th>
                            <th class="money-col">Awal</th>
                            <th class="money-col">Penjualan</th>
                            <th class="money-col">Bayar</th>
                            <th class="money-col">Titip</th>
                            <th class="money-col">Akhir</th>
                            <th class="money-col">1-30 Hari</th>
                            <th class="money-col">31-60 Hari</th>
                            <th class="money-col">61-90 Hari</th>
                            <th class="money-col">Lebih</th>
                            <th class="money-col">Blm Jth. Tempo</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($printRows) && empty($grouped)): ?>
                            <tr>
                                <td colspan="11" class="no-data">
                                    Tidak ada data aging piutang untuk filter ini.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($printRows as $printRow): ?>
                                <?php if ($printRow['type'] === 'city' || $printRow['type'] === 'city_continued'): ?>
                                    <tr class="city-row">
                                        <td colspan="11">
                                            <?= h($printRow['city']) ?>
                                            <?php if ($printRow['type'] === 'city_continued'): ?>
                                                <span style="font-weight:normal;">(lanjutan)</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                <?php elseif ($printRow['type'] === 'customer'): ?>
                                    <?php
                                    $printCustomer = $printRow['customer'];
                                    $pa = $printCustomer['amounts'];
                                    ?>
                                    <tr>
                                        <td class="customer-col">
                                            <?= h($printCustomer['customer_name']) ?>
                                        </td>
                                        <td class="money-cell"><?= h(formatMoney($pa['saldo_awal'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['penjualan'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['bayar'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['titip_used'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['akhir'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['b_1_30'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['b_31_60'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pa['b_61_90'])) ?></td>
                                        <td class="money-cell <?= $pa['b_lebih'] < 0 ? 'return-negative' : '' ?>">
                                            <?= h(formatMoney($pa['b_lebih'])) ?>
                                        </td>
                                        <td class="money-cell"><?= h(formatMoney($pa['belum_jatuh_tempo'])) ?></td>
                                    </tr>

                                <?php elseif ($printRow['type'] === 'city_total'): ?>
                                    <?php $pct = $printRow['totals']; ?>
                                    <tr class="city-total">
                                        <td class="customer-col">
                                            TOTAL <?= h($printRow['city']) ?>
                                        </td>
                                        <td class="money-cell"><?= h(formatMoney($pct['saldo_awal'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['penjualan'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['bayar'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['titip_used'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['akhir'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['b_1_30'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['b_31_60'])) ?></td>
                                        <td class="money-cell"><?= h(formatMoney($pct['b_61_90'])) ?></td>
                                        <td class="money-cell <?= $pct['b_lebih'] < 0 ? 'return-negative' : '' ?>">
                                            <?= h(formatMoney($pct['b_lebih'])) ?>
                                        </td>
                                        <td class="money-cell"><?= h(formatMoney($pct['belum_jatuh_tempo'])) ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <?php if ($isLastPrintPage): ?>
                            <tr class="grand-total">
                                <td class="customer-col">GRAND TOTAL</td>
                                <td class="money-cell"><?= h(formatMoney($grand['saldo_awal'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['penjualan'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['bayar'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['titip_used'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['akhir'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['b_1_30'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['b_31_60'])) ?></td>
                                <td class="money-cell"><?= h(formatMoney($grand['b_61_90'])) ?></td>
                                <td class="money-cell <?= $grand['b_lebih'] < 0 ? 'return-negative' : '' ?>">
                                    <?= h(formatMoney($grand['b_lebih'])) ?>
                                </td>
                                <td class="money-cell"><?= h(formatMoney($grand['belum_jatuh_tempo'])) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!$isLastPrintPage): ?>
                <div class="continuation-message">
                    Berlanjut ke halaman berikutnya...
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

</body>
</html>