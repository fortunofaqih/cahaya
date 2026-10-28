<?php
/*
 * REV7 TAHAP 1
 * Fokus revisi:
 * 1. Payment September+ terhadap invoice <= baseline mengikuti bucket invoice
 *    yang dipilih, bukan otomatis bucket tertua.
 * 2. RETURN yang berpasangan dengan INVOICE pada bayar_no yang sama tidak
 *    membentuk residual bucket +/- palsu.
 *
 * Formula 1, rollover existing, query penjualan, dan tampilan TIDAK diubah.
 */
/*
 * modul/transaksi/cetak_aging_piutang_detail.php
 *
 * RULE FINAL AGING PIUTANG - STRICT MONTHLY CARRY-FORWARD:
 * 1. Customer yang tampil WAJIB masih ada dan aktif di m_customer.
 * 2. CUT-OFF GLOBAL = 31-Jul-2026.
 *    customer_opening_balance adalah SNAPSHOT resmi saldo per 31-Jul-2026,
 *    bukan adjustment yang dijumlahkan lagi dengan transaksi Juli.
 *    SALDO AWAL Agustus = opening_balance snapshot 31-Jul-2026.
 *    SALDO AWAL bulan berikutnya = snapshot cutoff + mutasi efektif setelah
 *    cutoff sampai akhir bulan sebelumnya.
 * 3. TRANSAKSI SEBELUM BULAN FILTER tidak boleh tampil ulang pada kolom bulan berjalan:
 *      - invoice sebelum startDate -> bagian dari SALDO AWAL;
 *      - pembayaran sebelum startDate -> bagian dari SALDO AWAL;
 *      - titip-used sebelum startDate -> bagian dari SALDO AWAL;
 *      - retur sebelum startDate -> bagian dari SALDO AWAL.
 * 4. Kolom PENJUALAN hanya memuat invoice pada bulan yang difilter
 *    (dikurangi retur bulan berjalan sesuai rule laporan yang sudah digunakan).
 * 5. Kolom BAYAR hanya memuat pembayaran efektif pada bulan yang difilter.
 *    payment_source=OPENING / opening_id>0 hanya efektif bila
 *    detail_bayar.opening_effect_piutang=1, mengikuti Kartu Piutang.
 * 6. Kolom TITIP memuat PERUBAHAN saldo titip carry-forward bulan filter:
 *      saldo titip akhir bulan - saldo titip awal bulan.
 *    Titip bulan sebelumnya yang habis dipakai bulan ini tampil negatif.
 *    Titip yang masuk dan habis dipakai dalam bulan yang sama net menjadi 0.
 * 7. Invoice/Retur/Pembayaran <= 31-Jul-2026 tidak dihitung ulang karena
 *    sudah tercakup dalam snapshot opening_balance.
 * 8. Histori setelah cutoff tetapi sebelum startDate menjadi carry-forward
 *    SALDO AWAL bulan berikutnya.
 * 9. Invoice / Shipping CP-MCP tidak masuk Penjualan/Pembayaran normal.
 * 10. Retur CP-MCP tetap dihitung standalone memakai grand_total;
 *     retur normal memakai return_amount.
 * 11. Retur normal mengurangi outstanding invoice terkait.
 *     Pemakaian Retur (payment_source=RETURN) masuk BAYAR sebagai nilai negatif.
 *     Data baru memakai bayar_amount; data legacy 0 fallback ke nilai master Retur.
 *     Sisa Retur yang belum digunakan tetap menjadi credit balance.
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
 * 15. Koreksi Invoice memakai invoice_correction sebagai source of truth:
 *     - sebelum bulan filter menjadi carry-forward SALDO AWAL;
 *     - dalam bulan filter masuk PENJUALAN signed (minus);
 *     - tidak memiliki due_date, sehingga komposisi bucket diselesaikan oleh FINAL RESIDUAL LEBIH.
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

        /* Bucket invoice setelah migration baseline; Formula 1 tidak memakai field ini. */
        'post_base_b_1_30' => 0.0,
        'post_base_b_31_60' => 0.0,
        'post_base_b_61_90' => 0.0,
        'post_base_b_lebih' => 0.0,
        'post_base_bjt' => 0.0,
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
    /*
     * FIX MIGRATION BASELINE AKHIR BULAN:
     * Normalisasi bucket_date ke tanggal 1 terlebih dahulu, BARU maju 1 bulan.
     * Contoh 31-Aug-2026 tidak boleh dihitung langsung '+1 month' karena PHP
     * dapat overflow ke Oktober dan melewatkan rollover September.
     *
     * Rule rollover bucket existing TIDAK diubah.
     */
    $cursor = new DateTime(date('Y-m-01', strtotime($bucketDate)));
    $cursor->modify('+1 month');
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


/* ============================================================
 * HELPER MIGRATION BASELINE BUCKET
 * ============================================================ */
function sumMigrationBucketBaseline(array $row): float
{
    return
        (float)($row['b_1_30'] ?? 0)
        + (float)($row['b_31_60'] ?? 0)
        + (float)($row['b_61_90'] ?? 0)
        + (float)($row['b_lebih'] ?? 0)
        + (float)($row['belum_jatuh_tempo'] ?? 0);
}

function makeMigrationBucketSeed(array $row): array
{
    return [
        'bucket_date' => (string)($row['baseline_date'] ?? $row['bucket_date'] ?? ''),
        'b_1_30' => (float)($row['b_1_30'] ?? 0),
        'b_31_60' => (float)($row['b_31_60'] ?? 0),
        'b_61_90' => (float)($row['b_61_90'] ?? 0),
        'b_lebih' => (float)($row['b_lebih'] ?? 0),
        'belum_jatuh_tempo' => (float)($row['belum_jatuh_tempo'] ?? 0),
    ];
}

$bulan = (int)($_GET['bulan'] ?? date('n'));
$tahun = (int)($_GET['tahun'] ?? date('Y'));
$filterBy = trim((string)($_GET['filter_by'] ?? 'semua'));
$filterValue = trim((string)($_GET['filter_value'] ?? ''));

/*
 * CUT-OFF RESMI MIGRASI
 * ------------------------------------------------------------
 * Semua posisi sampai dengan 31-Jul-2026 dianggap sudah terkandung di
 * customer_opening_balance. Mutasi efektif Aging dimulai 01-Aug-2026.
 */
$cutoffDate = '2026-07-31';
$postCutoffDate = '2026-08-01';

/* ============================================================
 * MIGRATION BASELINE KHUSUS BUCKET AGING
 * Closing 31-Aug-2026 menjadi source of truth 5 bucket saja.
 * TIDAK dipakai untuk Awal / Penjualan / Bayar / Titip / Akhir.
 * ============================================================ */
$bucketBaselineDate = '2026-08-31';
$bucketPostBaselineDate = '2026-09-01';

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

$openingParams = [$cutoffDate];
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

/*
 * OPENING SNAPSHOT
 * ------------------------------------------------------------
 * Rule lama yang menjumlah seluruh customer_opening_balance sebagai manual
 * adjustment dinonaktifkan. Snapshot cutoff sudah dipilih pada $openingMap.
 * Tidak ada SUM opening_balance tambahan setelah ini.
 */
$manualOpeningBeforeMap = [];

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


/* ============================================================
 * 2B. MIGRATION BASELINE BUCKET 31-AUG-2026
 * ============================================================
 * KHUSUS komposisi 5 bucket. Formula 1 tidak membaca tabel ini.
 */
$bucketBaselineMap = [];
$bucketBaselineTableAvailable = tableExists($conn, 'aging_bucket_migration_baseline');

if ($bucketBaselineTableAvailable) {
    $sqlBucketBaseline = "
        SELECT baseline_id, customer_id, baseline_date,
               b_1_30, b_31_60, b_61_90, b_lebih, belum_jatuh_tempo
        FROM aging_bucket_migration_baseline
        WHERE baseline_date = ?
    ";

    $stmtBucketBaseline = mysqli_prepare($conn, $sqlBucketBaseline);
    mysqli_stmt_bind_param($stmtBucketBaseline, 's', $bucketBaselineDate);
    mysqli_stmt_execute($stmtBucketBaseline);
    $resBucketBaseline = mysqli_stmt_get_result($stmtBucketBaseline);

    while ($bb = mysqli_fetch_assoc($resBucketBaseline)) {
        $cid = trim((string)($bb['customer_id'] ?? ''));
        if ($cid === '' || !isset($masterCustomers[$cid])) {
            continue;
        }

        $bucketBaselineMap[$cid] = [
            'baseline_id' => (int)($bb['baseline_id'] ?? 0),
            'customer_id' => $cid,
            'baseline_date' => (string)($bb['baseline_date'] ?? ''),
            'bucket_date' => (string)($bb['baseline_date'] ?? ''),
            'b_1_30' => (float)($bb['b_1_30'] ?? 0),
            'b_31_60' => (float)($bb['b_31_60'] ?? 0),
            'b_61_90' => (float)($bb['b_61_90'] ?? 0),
            'b_lebih' => (float)($bb['b_lebih'] ?? 0),
            'belum_jatuh_tempo' => (float)($bb['belum_jatuh_tempo'] ?? 0),
        ];
    }

    mysqli_stmt_close($stmtBucketBaseline);
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
        WHERE period_date >= '2026-08-01'
          AND period_date <= '{$safeEnd}'
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
        WHERE period_date >= '2026-08-01'
          AND period_date <= '{$safeEndTimeline}'
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
 * Rule cutoff terbaru:
 * - snapshot resmi berasal dari customer_opening_balance <=31-Jul-2026;
 * - transaksi <=31-Jul-2026 tidak dihitung ulang;
 * - hanya transaksi >=01-Aug-2026 yang membentuk carry-forward berikutnya.
 *
 * Dengan demikian saldo akhir Agustus otomatis menjadi saldo awal September,
 * saldo akhir September menjadi saldo awal Oktober, dan seterusnya.
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
        'pre_invoice_correction' => 0.0,
        'period_invoice_correction' => 0.0,
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
        'unused_titip_cutoff' => 0.0,
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
 * RULE BUCKET FINAL:
 * Umur piutang dihitung dari BERAPA HARI invoice sudah melewati due_date
 * sampai tanggal akhir laporan ($asOfDate), BUKAN berdasarkan bulan invoice.
 *
 *   <= 0 hari  = BELUM JATUH TEMPO
 *   1 - 30     = 1-30 HARI
 *   31 - 60    = 31-60 HARI
 *   61 - 90    = 61-90 HARI
 *   > 90       = LEBIH
 *
 * Batas bucket sengaja FIX 30/60/90. Jangan mengikuti jumlah hari kalender
 * bulan (31/30/28), karena nama dan definisi laporan adalah umur tunggakan.
 */
$bucket1Limit = 30;
$bucket2Limit = 60;
$bucket3Limit = 90;

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
    WHERE hi.invoice_date >= '2026-08-01'
      AND hi.invoice_date <= ?
      AND UPPER(COALESCE(hi.invoice_no, '')) NOT LIKE '%CP-MCP%'
";

$invoiceAggParams = [$cutoffDate, $endDate, $endDate, $endDate, $endDate];
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
    /*
     * Semua invoice riil tetap dihitung.
     * Manual opening adalah adjustment tambahan dan tidak menjadi boundary
     * yang menghapus invoice sebelum opening_date.
     */
    SELECT
        *,
        (gross - paid_total - return_total) AS outstanding
    FROM inv_base
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
         AND DATEDIFF(?, due_date) BETWEEN 61 AND {$bucket3Limit}
        THEN outstanding ELSE 0 END
    ) AS b_61_90,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) BETWEEN 31 AND {$bucket2Limit}
        THEN outstanding ELSE 0 END
    ) AS b_31_60,

    SUM(CASE
        WHEN outstanding > 0
         AND DATEDIFF(?, due_date) BETWEEN 1 AND {$bucket1Limit}
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

/*
 * Placeholder setelah CTE inv_base:
 * 1 = pre_invoice startDate
 * 2 = period_invoice startDate, endDate
 * 1 = b_lebih asOfDate
 * 1 = b_61_90 asOfDate
 * 1 = b_31_60 asOfDate
 * 1 = b_1_30 asOfDate
 * 1 = belum_jatuh_tempo asOfDate
 * Total = 8 placeholder.
 */
$invoiceAggParams[] = $startDate;
$invoiceAggParams[] = $startDate;
$invoiceAggParams[] = $endDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggParams[] = $asOfDate;
$invoiceAggTypes .= 'ssssssss';

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
 * VERIFIKASI OUTSTANDING PER-INVOICE UNTUK CUSTOMER TANPA BASELINE
 * ============================================================
 * Gunakan pembayaran efektif per invoice hingga akhir periode.
 * Pembayaran setelah endDate tidak mengurangi outstanding bulan laporan.
 * Verifikasi digunakan hanya jika bucket BJT lama masih berisi bruto seluruh
 * invoice periode; tidak digunakan untuk retur/koreksi/umur campuran.
 * Formula 1, master transaksi, dan query invoice utama tidak diubah.
 */
$verifiedCurrentNotDue = [];
$sqlVerifiedCurrentNotDue = "
    SELECT
        hi.customer_id,
        COUNT(*) AS invoice_count,
        SUM(ic.gross) AS gross_total,
        SUM(LEAST(GREATEST(COALESCE(pi.paid_total, 0), 0), ic.gross)) AS paid_total,
        SUM(GREATEST(ic.gross - COALESCE(pi.paid_total, 0), 0)) AS outstanding_total,
        /* Klasifikasi bruto dan sisa invoice berdasarkan due_date aktual.
         * Ini mendukung invoice lunas yang sudah lewat jatuh tempo 1-30 hari,
         * selain invoice belum jatuh tempo yang telah ditangani sebelumnya. */
        SUM(CASE WHEN DATEDIFF(?, DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days,0) DAY)) BETWEEN 1 AND 30
                 THEN ic.gross ELSE 0 END) AS gross_1_30,
        SUM(CASE WHEN DATEDIFF(?, DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days,0) DAY)) BETWEEN 1 AND 30
                 THEN GREATEST(ic.gross - COALESCE(pi.paid_total,0),0) ELSE 0 END) AS outstanding_1_30,
        SUM(CASE WHEN pi.paid_total > ic.gross + 0.01 THEN 1 ELSE 0 END) AS overpaid_count,
        SUM(CASE WHEN DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days,0) DAY) < ?
                 THEN 1 ELSE 0 END) AS overdue_count,
        SUM(CASE WHEN EXISTS (
                SELECT 1 FROM head_retur_invoice hri
                WHERE hri.invoice_no = hi.invoice_no
                  AND hri.return_date <= ?
                  AND LOWER(COALESCE(hri.status,'Open')) <> 'cancelled'
            ) THEN 1 ELSE 0 END) AS return_count
    FROM head_invoice hi
    INNER JOIN (
        SELECT invoice_no,
               ROUND(SUM(CASE WHEN COALESCE(total,0) > 0
                        THEN COALESCE(total,0) ELSE COALESCE(subtotal,0) END), 0) AS gross
        FROM det_invoice GROUP BY invoice_no
    ) ic ON ic.invoice_no = hi.invoice_no
    LEFT JOIN (
        SELECT db.invoice_no,
               SUM(CASE
                   WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE')) = 'RETURN'
                     OR (LOWER(TRIM(COALESCE(hb.keterangan,''))) = 'retur'
                         AND COALESCE(TRIM(db.return_id),'') <> '')
                     THEN 0
                   WHEN hb.bayar_date <= ?
                     THEN (CASE WHEN COALESCE(db.cash_amount,0) > 0
                            THEN COALESCE(db.cash_amount,0)
                            ELSE GREATEST(COALESCE(db.bayar_amount,0)-COALESCE(db.titip_amount,0),0)
                           END) + COALESCE(db.titip_amount,0)
                   ELSE 0 END) AS paid_total
        FROM detail_bayar db
        INNER JOIN head_bayar hb ON hb.bayar_no = db.bayar_no
        WHERE COALESCE(db.invoice_no,'') <> ''
          AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
          AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'
        GROUP BY db.invoice_no
    ) pi ON pi.invoice_no = hi.invoice_no
    WHERE hi.invoice_date BETWEEN ? AND ?
      AND UPPER(COALESCE(hi.invoice_no,'')) NOT LIKE '%CP-MCP%'
      AND ic.gross > 0
    GROUP BY hi.customer_id
";
$stmtVerifiedCurrentNotDue = mysqli_prepare($conn, $sqlVerifiedCurrentNotDue);
mysqli_stmt_bind_param(
    $stmtVerifiedCurrentNotDue, 'sssssss',
    $asOfDate, $asOfDate, $asOfDate, $endDate, $endDate, $startDate, $endDate
);
mysqli_stmt_execute($stmtVerifiedCurrentNotDue);
$resVerifiedCurrentNotDue = mysqli_stmt_get_result($stmtVerifiedCurrentNotDue);
while ($ivr = mysqli_fetch_assoc($resVerifiedCurrentNotDue)) {
    $verifiedCurrentNotDue[trim((string)$ivr['customer_id'])] = $ivr;
}
mysqli_stmt_close($stmtVerifiedCurrentNotDue);

/* ============================================================
 * 3A. BUCKET INVOICE POST MIGRATION BASELINE
 * ============================================================
 * Clone query invoice production agar seluruh rule pay_inv / retur / titip /
 * filter tetap IDENTIK. Satu-satunya perubahan adalah boundary invoice untuk
 * komposisi bucket: 01-Aug-2026 -> 01-Sep-2026.
 *
 * pre_invoice / period_invoice dari query clone DIABAIKAN.
 */
if ($endDate > $bucketBaselineDate && !empty($bucketBaselineMap)) {
    /*
     * Jangan clone query dengan str_replace boundary secara implisit.
     * Buat boundary post-baseline eksplisit pada copy query production.
     *
     * Tujuan:
     * - invoice <= 31-Aug tetap diwakili migration baseline;
     * - invoice >= 01-Sep dihitung per invoice dari gross - payment invoice
     *   terkait - retur invoice terkait;
     * - bucket tetap memakai due_date production.
     *
     * Ini penting untuk payment yang menunjuk invoice tertentu. Contoh AR PLASTIK:
     * payment September ke invoice Agustus tidak boleh mengurangi invoice
     * September lain yang belum jatuh tempo.
     */
    $sqlInvoiceBucketPostBaseline = $sqlInvoiceAgg;
    $postBoundaryNeedle = "hi.invoice_date >= '2026-08-01'";
    $postBoundaryReplacement = "hi.invoice_date >= '" . $bucketPostBaselineDate . "'";

    if (strpos($sqlInvoiceBucketPostBaseline, $postBoundaryNeedle) !== false) {
        $sqlInvoiceBucketPostBaseline = str_replace(
            $postBoundaryNeedle,
            $postBoundaryReplacement,
            $sqlInvoiceBucketPostBaseline
        );
    }

    $stmtInvoiceBucketPostBaseline = mysqli_prepare($conn, $sqlInvoiceBucketPostBaseline);
    mysqli_stmt_bind_param(
        $stmtInvoiceBucketPostBaseline,
        $invoiceAggTypes,
        ...$invoiceAggParams
    );
    mysqli_stmt_execute($stmtInvoiceBucketPostBaseline);
    $resInvoiceBucketPostBaseline = mysqli_stmt_get_result($stmtInvoiceBucketPostBaseline);

    while ($r = mysqli_fetch_assoc($resInvoiceBucketPostBaseline)) {
        $cid = trim((string)($r['customer_id'] ?? ''));
        if (!isset($calc[$cid])) {
            continue;
        }

        $calc[$cid]['post_base_b_1_30'] = (float)($r['b_1_30'] ?? 0);
        $calc[$cid]['post_base_b_31_60'] = (float)($r['b_31_60'] ?? 0);
        $calc[$cid]['post_base_b_61_90'] = (float)($r['b_61_90'] ?? 0);
        $calc[$cid]['post_base_b_lebih'] = (float)($r['b_lebih'] ?? 0);
        $calc[$cid]['post_base_bjt'] = (float)($r['belum_jatuh_tempo'] ?? 0);
    }

    mysqli_stmt_close($stmtInvoiceBucketPostBaseline);

    /*
     * REV7 TAHAP 2 - SOURCE-AWARE RECOVERY POST-BASELINE
     * ----------------------------------------------------------
     * Rule existing 3A di atas TETAP menjadi source utama.
     * Recovery ini HANYA aktif bila seluruh post_base_* customer = 0.
     *
     * Tujuan:
     * - invoice setelah migration baseline dihitung PER INVOICE;
     * - payment hanya mengurangi invoice_no yang benar-benar dipilih;
     * - retur hanya mengurangi invoice_no sumber retur;
     * - outstanding diklasifikasikan memakai due_date invoice tersebut.
     *
     * Dengan demikian payment/retur invoice historical tidak boleh memakan
     * invoice September lain yang belum jatuh tempo.
     * Formula 1 / rollover / baseline / renderer TIDAK diubah.
     */
    $sqlPostBaseRecovery = "
        WITH invoice_calc_recovery AS (
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
        pay_inv_recovery AS (
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
            LEFT JOIN (
                SELECT
                    ref_no AS bayar_no,
                    customer_id,
                    MIN(titip_date) AS titip_date
                FROM detail_titip
                WHERE UPPER(COALESCE(transaction_type,'')) = 'PAKAI'
                  AND COALESCE(ref_no,'') <> ''
                GROUP BY ref_no, customer_id
            ) tu
                ON tu.bayar_no = db.bayar_no
               AND tu.customer_id = hb.customer_id
            WHERE COALESCE(db.invoice_no, '') <> ''
              AND UPPER(COALESCE(db.invoice_no, '')) NOT LIKE '%CP-MCP%'
              AND UPPER(COALESCE(db.shipping_no, '')) NOT LIKE '%CP-MCP%'
            GROUP BY db.invoice_no
        ),
        return_inv_recovery AS (
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
        post_invoice_recovery AS (
            SELECT
                hi.customer_id,
                hi.invoice_no,
                hi.invoice_date,
                DATE_ADD(hi.invoice_date, INTERVAL COALESCE(hi.days, 0) DAY) AS due_date,
                COALESCE(ic.gross, 0) AS gross,
                COALESCE(pi.paid_total, 0) AS paid_total,
                COALESCE(ri.return_total, 0) AS return_total,
                (
                    COALESCE(ic.gross, 0)
                    - COALESCE(pi.paid_total, 0)
                    - COALESCE(ri.return_total, 0)
                ) AS outstanding
            FROM head_invoice hi
            INNER JOIN m_customer mc
                ON mc.customer_id = hi.customer_id
               AND COALESCE(mc.is_active, 'Checked') = 'Checked'
            LEFT JOIN invoice_calc_recovery ic
                ON ic.invoice_no = hi.invoice_no
            LEFT JOIN pay_inv_recovery pi
                ON pi.invoice_no = hi.invoice_no
            LEFT JOIN return_inv_recovery ri
                ON ri.invoice_no = hi.invoice_no
            WHERE hi.invoice_date >= ?
              AND hi.invoice_date <= ?
              AND UPPER(COALESCE(hi.invoice_no, '')) NOT LIKE '%CP-MCP%'
    ";

    $postRecoveryParams = [
        $endDate,
        $endDate,
        $endDate,
        $bucketPostBaselineDate,
        $endDate
    ];
    $postRecoveryTypes = 'sssss';

    if ($filterBy === 'grup' && $filterValue !== '') {
        $sqlPostBaseRecovery .= " AND COALESCE(mc.area_code, '') = ? ";
        $postRecoveryParams[] = $filterValue;
        $postRecoveryTypes .= 's';
    } elseif ($filterBy === 'kota' && $filterValue !== '') {
        $sqlPostBaseRecovery .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
        $postRecoveryParams[] = $filterValue;
        $postRecoveryTypes .= 's';
    } elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
        $sqlPostBaseRecovery .= " AND mc.customer_id = ? ";
        $postRecoveryParams[] = $filterValue;
        $postRecoveryTypes .= 's';
    }

    $sqlPostBaseRecovery .= "
        )
        SELECT
            customer_id,
            SUM(CASE
                WHEN outstanding > 0
                 AND DATEDIFF(?, due_date) BETWEEN 1 AND {$bucket1Limit}
                THEN outstanding ELSE 0 END
            ) AS b_1_30,
            SUM(CASE
                WHEN outstanding > 0
                 AND DATEDIFF(?, due_date) BETWEEN 31 AND {$bucket2Limit}
                THEN outstanding ELSE 0 END
            ) AS b_31_60,
            SUM(CASE
                WHEN outstanding > 0
                 AND DATEDIFF(?, due_date) BETWEEN 61 AND {$bucket3Limit}
                THEN outstanding ELSE 0 END
            ) AS b_61_90,
            SUM(CASE
                WHEN outstanding < 0 THEN outstanding
                WHEN outstanding > 0
                 AND DATEDIFF(?, due_date) > {$bucket3Limit}
                THEN outstanding ELSE 0 END
            ) AS b_lebih,
            SUM(CASE
                WHEN outstanding > 0
                 AND DATEDIFF(?, due_date) <= 0
                THEN outstanding ELSE 0 END
            ) AS belum_jatuh_tempo
        FROM post_invoice_recovery
        GROUP BY customer_id
    ";

    $postRecoveryParams[] = $asOfDate;
    $postRecoveryParams[] = $asOfDate;
    $postRecoveryParams[] = $asOfDate;
    $postRecoveryParams[] = $asOfDate;
    $postRecoveryParams[] = $asOfDate;
    $postRecoveryTypes .= 'sssss';

    $stmtPostBaseRecovery = mysqli_prepare($conn, $sqlPostBaseRecovery);
    mysqli_stmt_bind_param(
        $stmtPostBaseRecovery,
        $postRecoveryTypes,
        ...$postRecoveryParams
    );
    mysqli_stmt_execute($stmtPostBaseRecovery);
    $resPostBaseRecovery = mysqli_stmt_get_result($stmtPostBaseRecovery);

    while ($rr = mysqli_fetch_assoc($resPostBaseRecovery)) {
        $cid = trim((string)($rr['customer_id'] ?? ''));
        if (!isset($calc[$cid]) || !isset($bucketBaselineMap[$cid])) {
            continue;
        }

        $existingPostBaseTotal =
            (float)($calc[$cid]['post_base_b_1_30'] ?? 0)
            + (float)($calc[$cid]['post_base_b_31_60'] ?? 0)
            + (float)($calc[$cid]['post_base_b_61_90'] ?? 0)
            + (float)($calc[$cid]['post_base_b_lebih'] ?? 0)
            + (float)($calc[$cid]['post_base_bjt'] ?? 0);

        /*
         * PENTING: customer yang query 3A existing-nya sudah menghasilkan
         * bucket tidak disentuh sama sekali.
         */
        if (abs($existingPostBaseTotal) > 0.0001) {
            continue;
        }

        $recoveryTotal =
            (float)($rr['b_1_30'] ?? 0)
            + (float)($rr['b_31_60'] ?? 0)
            + (float)($rr['b_61_90'] ?? 0)
            + (float)($rr['b_lebih'] ?? 0)
            + (float)($rr['belum_jatuh_tempo'] ?? 0);

        if (abs($recoveryTotal) <= 0.0001) {
            continue;
        }

        $calc[$cid]['post_base_b_1_30'] = (float)($rr['b_1_30'] ?? 0);
        $calc[$cid]['post_base_b_31_60'] = (float)($rr['b_31_60'] ?? 0);
        $calc[$cid]['post_base_b_61_90'] = (float)($rr['b_61_90'] ?? 0);
        $calc[$cid]['post_base_b_lebih'] = (float)($rr['b_lebih'] ?? 0);
        $calc[$cid]['post_base_bjt'] = (float)($rr['belum_jatuh_tempo'] ?? 0);
    }
    mysqli_stmt_close($stmtPostBaseRecovery);

    /*
     * Defensive source-based fallback.
     * ----------------------------------------------------------
     * Bila customer mempunyai PENJUALAN invoice pada bulan laporan tetapi
     * query post-baseline tidak menghasilkan bucket sama sekali, gunakan
     * komposisi bucket dari query production ($c b_*), HANYA sebesar posisi
     * invoice yang memang berasal setelah baseline.
     *
     * Fallback ini tidak memindahkan residual Lebih secara paksa dan tidak
     * mengubah Formula 1. Normalnya blok ini tidak aktif; ia hanya mencegah
     * invoice September yang valid hilang dari post_base_* akibat boundary
     * migration. Fallback hanya dipakai bila TOTAL bucket production sama
     * dengan period_invoice, sehingga tidak mencampur saldo historical baseline.
     */
    foreach ($calc as $cid => &$postCalc) {
        if (!isset($bucketBaselineMap[$cid])) {
            continue;
        }

        $postBucketTotal =
            (float)($postCalc['post_base_b_1_30'] ?? 0)
            + (float)($postCalc['post_base_b_31_60'] ?? 0)
            + (float)($postCalc['post_base_b_61_90'] ?? 0)
            + (float)($postCalc['post_base_b_lebih'] ?? 0)
            + (float)($postCalc['post_base_bjt'] ?? 0);

        $productionBucketTotal =
            (float)($postCalc['b_1_30'] ?? 0)
            + (float)($postCalc['b_31_60'] ?? 0)
            + (float)($postCalc['b_61_90'] ?? 0)
            + (float)($postCalc['b_lebih'] ?? 0)
            + (float)($postCalc['belum_jatuh_tempo'] ?? 0);

        $periodInvoiceForFallback = (float)($postCalc['period_invoice'] ?? 0);

        if (
            abs($postBucketTotal) <= 0.0001
            && abs($periodInvoiceForFallback) > 0.0001
            && abs($productionBucketTotal - $periodInvoiceForFallback) <= 0.01
        ) {
            $postCalc['post_base_b_1_30'] = (float)($postCalc['b_1_30'] ?? 0);
            $postCalc['post_base_b_31_60'] = (float)($postCalc['b_31_60'] ?? 0);
            $postCalc['post_base_b_61_90'] = (float)($postCalc['b_61_90'] ?? 0);
            $postCalc['post_base_b_lebih'] = (float)($postCalc['b_lebih'] ?? 0);
            $postCalc['post_base_bjt'] = (float)($postCalc['belum_jatuh_tempo'] ?? 0);
        }
    }
    unset($postCalc);
}

/* ============================================================
 * 3B. KOREKSI INVOICE
 * ============================================================
 * SOURCE OF TRUTH = invoice_correction.
 *
 * Rule sama dengan Kartu Piutang:
 * - correction_amount disimpan signed negatif;
 * - koreksi post-cutoff sebelum bulan filter menjadi carry-forward SALDO AWAL;
 * - koreksi dalam bulan filter masuk PENJUALAN;
 * - status Cancelled tidak dihitung.
 *
 * Koreksi tidak mempunyai due_date, sehingga tidak dipaksa ke bucket invoice.
 * Selisih komposisi bucket otomatis ditampung oleh FINAL RESIDUAL LEBIH.
 */
$sqlInvoiceCorrection = "
    SELECT
        ic.customer_id,
        COALESCE(SUM(CASE
            WHEN ic.correction_date >= ?
             AND ic.correction_date < ?
            THEN COALESCE(ic.correction_amount,0)
            ELSE 0
        END),0) AS pre_invoice_correction,

        COALESCE(SUM(CASE
            WHEN ic.correction_date BETWEEN ? AND ?
            THEN COALESCE(ic.correction_amount,0)
            ELSE 0
        END),0) AS period_invoice_correction

    FROM invoice_correction ic
    INNER JOIN m_customer mc
        ON mc.customer_id = ic.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    WHERE ic.correction_date >= ?
      AND ic.correction_date <= ?
      AND LOWER(COALESCE(ic.status, 'Active')) = 'active'
";

$invoiceCorrectionParams = [
    $postCutoffDate,
    $startDate,
    $startDate,
    $endDate,
    $postCutoffDate,
    $endDate
];
$invoiceCorrectionTypes = 'ssssss';

if ($filterBy === 'grup' && $filterValue !== '') {
    $sqlInvoiceCorrection .= " AND COALESCE(mc.area_code, '') = ? ";
    $invoiceCorrectionParams[] = $filterValue;
    $invoiceCorrectionTypes .= 's';
} elseif ($filterBy === 'kota' && $filterValue !== '') {
    $sqlInvoiceCorrection .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
    $invoiceCorrectionParams[] = $filterValue;
    $invoiceCorrectionTypes .= 's';
} elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
    $sqlInvoiceCorrection .= " AND mc.customer_id = ? ";
    $invoiceCorrectionParams[] = $filterValue;
    $invoiceCorrectionTypes .= 's';
}

$sqlInvoiceCorrection .= " GROUP BY ic.customer_id ";

$stmtInvoiceCorrection = mysqli_prepare($conn, $sqlInvoiceCorrection);
mysqli_stmt_bind_param(
    $stmtInvoiceCorrection,
    $invoiceCorrectionTypes,
    ...$invoiceCorrectionParams
);
mysqli_stmt_execute($stmtInvoiceCorrection);
$resInvoiceCorrection = mysqli_stmt_get_result($stmtInvoiceCorrection);

while ($r = mysqli_fetch_assoc($resInvoiceCorrection)) {
    $cid = trim((string)($r['customer_id'] ?? ''));
    if (!isset($calc[$cid])) {
        continue;
    }

    $calc[$cid]['pre_invoice_correction'] =
        (float)($r['pre_invoice_correction'] ?? 0);

    $calc[$cid]['period_invoice_correction'] =
        (float)($r['period_invoice_correction'] ?? 0);
}

mysqli_stmt_close($stmtInvoiceCorrection);

/* ============================================================
 * 4. PEMBAYARAN CASH + TITIP TERPAKAI
 * ============================================================
 * Cash mengikuti head_bayar.bayar_date.
 * Hanya pembayaran efektif terhadap piutang yang tanggal bayarnya berada pada
 * bulan filter yang masuk kolom BAYAR.
 * payment_source=OPENING / opening_id>0 mengikuti Kartu Piutang:
 *   - opening_effect_piutang=0 -> histori/audit, efek BAYAR = 0;
 *   - opening_effect_piutang=1 -> pembayaran efektif dan ikut BAYAR/carry-forward.
 * Pembayaran bulan sebelumnya hanya mempengaruhi carry-forward SALDO AWAL.
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
/*
 * Rekonsiliasi pembayaran campuran CASH + RETURN pada nomor bayar yang sama.
 * Berlaku hanya jika selisih nilai INVOICE vs cash+titip persis ditutup RETURN,
 * jumlah detail signed cocok dengan header, dan header bernilai positif.
 * Pembayaran RETURN murni (header 0) tetap mengikuti rule existing.
 */
mixed_return_payment AS (
    SELECT hb_mix.bayar_no
    FROM head_bayar hb_mix
    INNER JOIN detail_bayar db_mix ON db_mix.bayar_no = hb_mix.bayar_no
    GROUP BY hb_mix.bayar_no, hb_mix.total_bayar
    HAVING COALESCE(hb_mix.total_bayar, 0) > 0
       AND SUM(CASE
            WHEN UPPER(COALESCE(NULLIF(TRIM(db_mix.payment_source), ''), 'INVOICE')) = 'RETURN'
            THEN ABS(COALESCE(db_mix.bayar_amount, 0)) ELSE 0 END) > 0.0001
       AND SUM(CASE
            WHEN UPPER(COALESCE(NULLIF(TRIM(db_mix.payment_source), ''), 'INVOICE')) = 'INVOICE'
             AND COALESCE(db_mix.cash_amount, 0) > 0
            THEN GREATEST(
                COALESCE(db_mix.bayar_amount, 0)
                - COALESCE(db_mix.cash_amount, 0)
                - COALESCE(db_mix.titip_amount, 0), 0)
            ELSE 0 END) > 0.0001
       AND ABS(
           SUM(CASE
               WHEN UPPER(COALESCE(NULLIF(TRIM(db_mix.payment_source), ''), 'INVOICE')) = 'INVOICE'
                AND COALESCE(db_mix.cash_amount, 0) > 0
               THEN GREATEST(
                   COALESCE(db_mix.bayar_amount, 0)
                   - COALESCE(db_mix.cash_amount, 0)
                   - COALESCE(db_mix.titip_amount, 0), 0)
               ELSE 0 END)
           - SUM(CASE
               WHEN UPPER(COALESCE(NULLIF(TRIM(db_mix.payment_source), ''), 'INVOICE')) = 'RETURN'
               THEN ABS(COALESCE(db_mix.bayar_amount, 0)) ELSE 0 END)
       ) <= 0.01
       AND ABS(SUM(COALESCE(db_mix.bayar_amount, 0)) - hb_mix.total_bayar) <= 0.01
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
        COALESCE(db.opening_effect_piutang, 0) AS opening_effect_piutang,
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
            /*
             * RETURN adalah pembayaran signed negatif.
             * Data baru memakai bayar_amount negatif; data legacy 0 fallback
             * ke nilai master Retur.
             */
            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'RETURN'
            THEN
                CASE
                    WHEN ABS(COALESCE(db.bayar_amount,0)) > 0.0001
                        THEN -1 * ABS(COALESCE(db.bayar_amount,0))
                    ELSE -1 * ABS(
                        CASE
                            WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
                              OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                                THEN COALESCE(hri.grand_total,0)
                            ELSE COALESCE(hri.return_amount,0)
                        END
                    )
                END

            /*
             * LEGACY RETUR - SOURCE OF TRUTH KARTU PIUTANG
             * Data legacy masih dapat tersimpan sebagai payment_source=INVOICE,
             * tetapi head_bayar.keterangan='Retur' dan mempunyai return_id.
             * Jangan hitung kembali sebagai cash payment normal.
             */
            WHEN LOWER(TRIM(COALESCE(hb.keterangan, ''))) = 'retur'
              AND COALESCE(TRIM(db.return_id), '') <> ''
            THEN 0

            /*
             * SOURCE OF TRUTH KARTU PIUTANG - PAYMENT OPENING
             * ----------------------------------------------------------
             * Kartu Piutang tidak memakai GREATEST() untuk OPENING.
             * Nominal opening dinormalisasi ABS(), kemudian tanda akhirnya
             * ditentukan oleh opening_sign / opening_balance target.
             *
             * Ini penting untuk data migrasi yang bayar_amount-nya dapat
             * tersimpan negatif. Dengan rule lama GREATEST(negatif,0),
             * pembayaran opening menjadi 0 walaupun opening_effect_piutang=1.
             */
            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'OPENING'
              OR COALESCE(db.opening_id, 0) > 0
            THEN ABS(
                CASE
                    WHEN ABS(COALESCE(db.bayar_amount,0)) > 0.0001
                        THEN COALESCE(db.bayar_amount,0)
                    ELSE COALESCE(db.cash_amount,0) + COALESCE(db.titip_amount,0)
                END
            )

            /* Jika retur telah mengisi selisih bruto invoice vs cash,
             * gunakan bruto pada INVOICE, lalu RETURN negatif sekali saja.
             * Hanya untuk pembayaran campuran yang lolos rekonsiliasi CTE.
             */
            WHEN mrp.bayar_no IS NOT NULL
             AND UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'INVOICE'
             AND COALESCE(db.cash_amount, 0) > 0
             AND COALESCE(db.bayar_amount, 0)
                 > COALESCE(db.cash_amount, 0) + COALESCE(db.titip_amount, 0)
            THEN GREATEST(
                COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0), 0
            )

            WHEN COALESCE(db.cash_amount, 0) > 0
                THEN COALESCE(db.cash_amount, 0)

            ELSE GREATEST(
                COALESCE(db.bayar_amount, 0) - COALESCE(db.titip_amount, 0),
                0
            )
        END AS cash_raw,

        CASE
            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'RETURN'
              OR UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'OPENING'
              OR COALESCE(db.opening_id, 0) > 0
              OR (
                    LOWER(TRIM(COALESCE(hb.keterangan, ''))) = 'retur'
                AND COALESCE(TRIM(db.return_id), '') <> ''
              )
            THEN 0
            ELSE COALESCE(db.titip_amount, 0)
        END AS titip_raw,

        /*
         * Opening target hanya yang eksplisit.
         * RETURN/orphan tidak boleh otomatis dianggap pembayaran opening.
         */
        CASE
            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'OPENING'
              OR COALESCE(db.opening_id, 0) > 0
            THEN 1 ELSE 0
        END AS is_opening_target,

        CASE
            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) <> 'RETURN'
             AND UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) <> 'OPENING'
             AND COALESCE(db.opening_id,0) = 0
             AND (COALESCE(db.invoice_no, '') = '' OR hi.invoice_no IS NULL)
            THEN 1 ELSE 0
        END AS is_orphan,

        CASE
            WHEN COALESCE(cob_target.opening_balance, lo.opening_balance, 0) < 0
                THEN -1
            ELSE 1
        END AS opening_sign

    FROM detail_bayar db
    INNER JOIN head_bayar hb
        ON hb.bayar_no = db.bayar_no
    INNER JOIN m_customer mc
        ON mc.customer_id = hb.customer_id
       AND COALESCE(mc.is_active, 'Checked') = 'Checked'
    LEFT JOIN titip_use tu
        ON tu.bayar_no = db.bayar_no
       AND tu.customer_id = hb.customer_id
    LEFT JOIN mixed_return_payment mrp
        ON mrp.bayar_no = hb.bayar_no
    LEFT JOIN latest_opening lo
        ON lo.customer_id = hb.customer_id
    LEFT JOIN head_invoice hi
        ON hi.invoice_no = db.invoice_no
    LEFT JOIN head_retur_invoice hri
        ON TRIM(hri.return_id) = TRIM(db.return_id)
       AND LOWER(COALESCE(hri.status,'Open')) <> 'cancelled'
    LEFT JOIN customer_opening_balance cob_target
        ON cob_target.opening_id = db.opening_id
       AND cob_target.customer_id = hb.customer_id
    WHERE (
            hb.bayar_date <= ?
         OR COALESCE(tu.titip_date, hb.bayar_date) <= ?
    )
      AND UPPER(COALESCE(db.invoice_no, '')) NOT LIKE '%CP-MCP%'
      AND UPPER(COALESCE(db.shipping_no, '')) NOT LIKE '%CP-MCP%'
";

$payAggParams = [$cutoffDate, $endDate, $endDate];
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

/*
 * SOURCE OF TRUTH = KARTU PIUTANG
 * ----------------------------------------------------------
 * OPENING hanya memengaruhi piutang bila opening_effect_piutang = 1.
 * OPENING dengan flag 0 tetap histori/audit dan tidak masuk Bayar efektif.
 *
 * Untuk OPENING negatif, opening_sign mengikuti opening_balance target
 * sebagaimana paymentGrossExpr pada Kartu Piutang.
 */
SUM(CASE
    WHEN cash_date >= '2026-08-01'
     AND cash_date < ?
     AND (
            is_opening_target = 0
            OR (
                is_opening_target = 1
                AND opening_effect_piutang = 1
            )
         )
    THEN
        CASE
            WHEN is_opening_target = 1
                THEN cash_raw * opening_sign
            ELSE cash_raw
        END
    ELSE 0
END) AS pre_cash,

SUM(CASE
    WHEN titip_date >= '2026-08-01'
     AND titip_date < ?
     AND (
            is_opening_target = 0
            OR (
                is_opening_target = 1
                AND opening_effect_piutang = 1
            )
         )
    THEN
        CASE
            WHEN is_opening_target = 1
                THEN titip_raw * opening_sign
            ELSE titip_raw
        END
    ELSE 0
END) AS pre_titip,

SUM(CASE
    WHEN cash_date BETWEEN ? AND ?
     AND (
            is_opening_target = 0
            OR (
                is_opening_target = 1
                AND opening_effect_piutang = 1
            )
         )
    THEN
        CASE
            WHEN is_opening_target = 1
                THEN cash_raw * opening_sign
            ELSE cash_raw
        END
    ELSE 0
END) AS period_cash,

SUM(CASE
    WHEN titip_date BETWEEN ? AND ?
     AND (
            is_opening_target = 0
            OR (
                is_opening_target = 1
                AND opening_effect_piutang = 1
            )
         )
    THEN
        CASE
            WHEN is_opening_target = 1
                THEN titip_raw * opening_sign
            ELSE titip_raw
        END
    ELSE 0
END) AS period_titip,

    SUM(CASE
        WHEN is_opening_target = 1
         AND opening_effect_piutang = 1 THEN
            (
                CASE
                    WHEN cash_date <= ?
                    THEN cash_raw * opening_sign ELSE 0
                END
                +
                CASE
                    WHEN titip_date <= ?
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
    hb.bayar_no AS payment_no,
    hb.bayar_date AS cash_date,
    COALESCE(tu.titip_date, hb.bayar_date) AS titip_date,
    lo.opening_id AS active_opening_id,
    lo.opening_date,
    lo.opening_balance,
    db.opening_id,
    COALESCE(db.opening_effect_piutang, 0) AS opening_effect_piutang,
    db.invoice_no,
    db.bayar_amount AS signed_bayar_amount,
    UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) AS payment_source,
    hi.invoice_no AS linked_invoice_no,
    hi.invoice_date AS linked_invoice_date,
    COALESCE(hi.days, 0) AS linked_invoice_days,
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

$openingTimelineParams = [$cutoffDate, $endDate, $endDate];
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
    $openingEffectPiutang = (int)($pr['opening_effect_piutang'] ?? 0);
    $activeOpeningId = (int)($pr['active_opening_id'] ?? 0);
    $invoiceNo = trim((string)($pr['invoice_no'] ?? ''));
    $linkedInvoiceNo = trim((string)($pr['linked_invoice_no'] ?? ''));
    $linkedInvoiceDate = (string)($pr['linked_invoice_date'] ?? '');

    /*
     * SOURCE OF TRUTH = KARTU PIUTANG
     * ----------------------------------------------------------
     * Pembayaran yang benar-benar mengurangi snapshot opening:
     * 1. payment_source=OPENING / opening_id eksplisit DAN
     *    opening_effect_piutang=1; ATAU
     * 2. pembayaran post-cutoff yang masih menunjuk invoice <=31-Jul-2026.
     *
     * OPENING dengan opening_effect_piutang=0 hanya histori/audit:
     * jangan mengurangi bucket opening.
     *
     * Kasus (2) tetap dipertahankan untuk transaksi lama yang sudah terlanjur
     * dibuat: invoice Juli tetap histori, tetapi pembayaran Agustus terhadap
     * invoice tersebut tetap harus mengurangi saldo/bucket opening.
     */
    $isExplicitOpeningTarget = (
        $paymentSource === 'OPENING'
        || $dbOpeningId > 0
    );

    $isEffectiveExplicitOpening = (
        $isExplicitOpeningTarget
        && $openingEffectPiutang === 1
    );

    $isCutoffInvoiceTarget = (
        $linkedInvoiceNo !== ''
        && $linkedInvoiceDate !== ''
        && $linkedInvoiceDate !== '0000-00-00'
        && $linkedInvoiceDate <= $cutoffDate
    );

    if (!$isEffectiveExplicitOpening && !$isCutoffInvoiceTarget) {
        continue;
    }

    $sign = $isEffectiveExplicitOpening
        ? (((float)($pr['opening_balance'] ?? 0) < 0) ? -1.0 : 1.0)
        : 1.0;

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

/* ============================================================
 * PAYMENT TIMELINE KHUSUS MIGRATION BASELINE 31-AUG-2026
 * ============================================================
 * Clone query payment production agar cash/titip/RETURN memakai rule existing.
 * INNER JOIN latest_opening diubah menjadi LEFT JOIN supaya customer yang baru
 * bertransaksi di Agustus tetap dapat mengurangi baseline pada September+.
 *
 * Hanya pembayaran SETELAH 31-Aug terhadap invoice <=31-Aug yang diterapkan
 * ke bucket baseline. Kolom BAYAR Formula 1 tetap berasal dari query existing.
 */
$baselinePaymentTimeline = [];
$postBaselineLinkedPaymentTotal = [];

/* REV7 TAHAP 1 - source-aware migration payment. */
$baselineInvoicePaymentByBucket = [];

/* Pasangan INVOICE + RETURN pada bayar_no yang sama. */
$migrationPaymentPair = [];
$migrationPairedReturnAbs = [];

/*
 * STEP 6 REV5 - SOURCE-SPECIFIC MIGRATION ADJUSTMENT
 * Menyimpan transaksi September+ RETURN / OPENING signed yang tidak boleh
 * membentuk credit/residual bucket baru. Formula 1 tidak diubah.
 */
$migrationReturnSigned = [];
$migrationOpeningSigned = [];

/*
 * REV7 TAHAP 3 - HISTORICAL RETURN SETTLEMENT
 * ------------------------------------------------------------
 * Sebagian transaksi legacy RETURN setelah migration baseline hanya menjadi
 * marker penyelesaian: bayar_amount/cash/titip = 0, sedangkan nominal aslinya
 * berada di head_retur_invoice sebelum/hingga baseline.
 *
 * Referensi legacy dapat tersimpan di detail_bayar.return_id ATAU
 * detail_bayar.shipping_no. Nominal ini hanya dipakai sebagai BATAS MAKSIMUM
 * untuk membersihkan pasangan bucket historical negatif <-> LEBIH positif.
 * Formula 1 tidak disentuh.
 */
$historicalReturnSettlementAbs = [];
$historicalCpMcpZeroMarkerSettlementAbs = [];

if ($endDate > $bucketBaselineDate && !empty($bucketBaselineMap)) {
    $sqlBaselinePayTimeline = str_replace(
        "INNER JOIN latest_opening lo\n    ON lo.customer_id = hb.customer_id",
        "LEFT JOIN latest_opening lo\n    ON lo.customer_id = hb.customer_id",
        $sqlOpeningPayTimeline
    );

    $stmtBaselineTimeline = mysqli_prepare($conn, $sqlBaselinePayTimeline);
    mysqli_stmt_bind_param(
        $stmtBaselineTimeline,
        $openingTimelineTypes,
        ...$openingTimelineParams
    );
    mysqli_stmt_execute($stmtBaselineTimeline);
    $resBaselineTimeline = mysqli_stmt_get_result($stmtBaselineTimeline);

    while ($pr = mysqli_fetch_assoc($resBaselineTimeline)) {
        $cid = trim((string)($pr['customer_id'] ?? ''));
        if ($cid === '' || !isset($bucketBaselineMap[$cid])) {
            continue;
        }

        $paymentSource = strtoupper(trim((string)($pr['payment_source'] ?? 'INVOICE')));
        $dbOpeningId = (int)($pr['opening_id'] ?? 0);
        $openingEffectPiutang = (int)($pr['opening_effect_piutang'] ?? 0);
        $linkedInvoiceNo = trim((string)($pr['linked_invoice_no'] ?? ''));
        $linkedInvoiceDate = (string)($pr['linked_invoice_date'] ?? '');
        $linkedInvoiceDays = (int)($pr['linked_invoice_days'] ?? 0);
        $paymentNo = trim((string)($pr['payment_no'] ?? ''));
        $signedBayarAmount = (float)($pr['signed_bayar_amount'] ?? 0);
        $sourceCashDate = (string)($pr['cash_date'] ?? '');

        /*
         * REV7: catat pasangan INVOICE/RETURN dalam nomor pembayaran yang sama.
         * Digunakan hanya untuk mencegah normalisasi RETURN ganda.
         */
        if (
            $paymentNo !== ''
            && $sourceCashDate !== ''
            && $sourceCashDate > $bucketBaselineDate
            && $sourceCashDate <= $endDate
        ) {
            if (!isset($migrationPaymentPair[$cid][$paymentNo])) {
                $migrationPaymentPair[$cid][$paymentNo] = [
                    'invoice' => 0.0,
                    'return' => 0.0,
                ];
            }

            if ($paymentSource === 'INVOICE' && $signedBayarAmount > 0.0001) {
                $migrationPaymentPair[$cid][$paymentNo]['invoice'] += $signedBayarAmount;
            } elseif ($paymentSource === 'RETURN' && $signedBayarAmount < -0.0001) {
                $migrationPaymentPair[$cid][$paymentNo]['return'] += abs($signedBayarAmount);
            }
        }

        /* REV5: catat hanya transaksi setelah migration baseline. */
        if (
            $sourceCashDate !== ''
            && $sourceCashDate > $bucketBaselineDate
            && $sourceCashDate <= $endDate
            && $signedBayarAmount < -0.0001
        ) {
            if ($paymentSource === 'RETURN') {
                $migrationReturnSigned[$cid] =
                    (float)($migrationReturnSigned[$cid] ?? 0)
                    + $signedBayarAmount;
            } elseif ($paymentSource === 'OPENING' && $openingEffectPiutang === 1) {
                $migrationOpeningSigned[$cid] =
                    (float)($migrationOpeningSigned[$cid] ?? 0)
                    + $signedBayarAmount;
            }
        }

        $isEffectiveExplicitOpening = (
            ($paymentSource === 'OPENING' || $dbOpeningId > 0)
            && $openingEffectPiutang === 1
        );

        $isBaselineInvoiceTarget = (
            $linkedInvoiceNo !== ''
            && $linkedInvoiceDate !== ''
            && $linkedInvoiceDate !== '0000-00-00'
            && $linkedInvoiceDate <= $bucketBaselineDate
        );

        /*
         * STEP 6 REV4:
         * Payment yang secara eksplisit menunjuk invoice SETELAH baseline
         * adalah payment invoice baru. Nilainya tidak boleh dipakai lagi oleh
         * fallback periodCash untuk mengurangi historical baseline.
         * Contoh: invoice September + payment September (ARDY).
         */
        $isPostBaselineInvoiceTarget = (
            $paymentSource === 'INVOICE'
            && $linkedInvoiceNo !== ''
            && $linkedInvoiceDate !== ''
            && $linkedInvoiceDate !== '0000-00-00'
            && $linkedInvoiceDate > $bucketBaselineDate
        );

        if ($isPostBaselineInvoiceTarget) {
            $cashDatePost = (string)($pr['cash_date'] ?? '');
            $cashRawPost = (float)($pr['cash_raw'] ?? 0);
            if (
                $cashDatePost !== ''
                && $cashDatePost > $bucketBaselineDate
                && $cashDatePost <= $endDate
                && $cashRawPost > 0.0001
            ) {
                $postBaselineLinkedPaymentTotal[$cid] =
                    (float)($postBaselineLinkedPaymentTotal[$cid] ?? 0)
                    + $cashRawPost;
            }

            $titipDatePost = (string)($pr['titip_date'] ?? '');
            $titipRawPost = (float)($pr['titip_raw'] ?? 0);
            if (
                $titipDatePost !== ''
                && $titipDatePost > $bucketBaselineDate
                && $titipDatePost <= $endDate
                && $titipRawPost > 0.0001
            ) {
                $postBaselineLinkedPaymentTotal[$cid] =
                    (float)($postBaselineLinkedPaymentTotal[$cid] ?? 0)
                    + $titipRawPost;
            }
        }

        if (!$isEffectiveExplicitOpening && !$isBaselineInvoiceTarget) {
            continue;
        }

        /* Explicit OPENING negatif tetap mengikuti sign snapshot existing. */
        $sign = $isEffectiveExplicitOpening
            ? (((float)($pr['opening_balance'] ?? 0) < 0) ? -1.0 : 1.0)
            : 1.0;

        $cashDate = (string)($pr['cash_date'] ?? '');
        $cashRaw = (float)($pr['cash_raw'] ?? 0);
        $titipDate = (string)($pr['titip_date'] ?? '');
        $titipRaw = (float)($pr['titip_raw'] ?? 0);

        /*
         * REV7 TAHAP 1:
         * Payment yang menunjuk invoice <= baseline mengikuti umur invoice
         * tersebut pada as-of report. Explicit OPENING tetap memakai timeline
         * existing agar rule yang sudah benar tidak berubah.
         */
        if ($isBaselineInvoiceTarget && $linkedInvoiceDate !== '') {
            $dueTs = strtotime(
                $linkedInvoiceDate . ' +' . max($linkedInvoiceDays, 0) . ' days'
            );
            $asOfTs = strtotime($asOfDate);
            $diffDays = ($dueTs !== false && $asOfTs !== false)
                ? (int)floor(($asOfTs - $dueTs) / 86400)
                : 0;

            if ($diffDays <= 0) {
                $targetBucketKey = 'belum_jatuh_tempo';
            } elseif ($diffDays <= $bucket1Limit) {
                $targetBucketKey = 'b_1_30';
            } elseif ($diffDays <= $bucket2Limit) {
                $targetBucketKey = 'b_31_60';
            } elseif ($diffDays <= $bucket3Limit) {
                $targetBucketKey = 'b_61_90';
            } else {
                $targetBucketKey = 'b_lebih';
            }

            if (
                $cashDate !== ''
                && $cashDate > $bucketBaselineDate
                && $cashDate <= $endDate
                && $cashRaw > 0.0001
            ) {
                $baselineInvoicePaymentByBucket[$cid][$targetBucketKey] =
                    (float)($baselineInvoicePaymentByBucket[$cid][$targetBucketKey] ?? 0)
                    + $cashRaw;
            }

            if (
                $titipDate !== ''
                && $titipDate > $bucketBaselineDate
                && $titipDate <= $endDate
                && $titipRaw > 0.0001
            ) {
                $baselineInvoicePaymentByBucket[$cid][$targetBucketKey] =
                    (float)($baselineInvoicePaymentByBucket[$cid][$targetBucketKey] ?? 0)
                    + $titipRaw;
            }
        } else {
            if (
                $cashDate !== ''
                && $cashDate > $bucketBaselineDate
                && $cashDate <= $endDate
                && abs($cashRaw) > 0.0001
            ) {
                $monthKey = date('Y-m', strtotime($cashDate));
                $baselinePaymentTimeline[$cid][$monthKey] =
                    (float)($baselinePaymentTimeline[$cid][$monthKey] ?? 0)
                    + ($cashRaw * $sign);
            }

            if (
                $titipDate !== ''
                && $titipDate > $bucketBaselineDate
                && $titipDate <= $endDate
                && abs($titipRaw) > 0.0001
            ) {
                $monthKey = date('Y-m', strtotime($titipDate));
                $baselinePaymentTimeline[$cid][$monthKey] =
                    (float)($baselinePaymentTimeline[$cid][$monthKey] ?? 0)
                    + ($titipRaw * $sign);
            }
        }
    }

    mysqli_stmt_close($stmtBaselineTimeline);
}

/*
 * REV7 TAHAP 3 - ambil marker settlement RETURN historical secara eksplisit.
 * Hanya RETURN master yang tanggal return-nya <= baseline, tetapi marker
 * pembayarannya terjadi SETELAH baseline sampai endDate.
 *
 * Penting:
 * - tidak memakai nama customer / hardcode customer_id;
 * - tidak mengubah Penjualan/Bayar/Titip/Akhir;
 * - return baru setelah baseline tidak masuk rule ini;
 * - reference legacy boleh berada di db.return_id atau db.shipping_no.
 */
if ($endDate > $bucketBaselineDate && !empty($bucketBaselineMap)) {
    $sqlHistoricalReturnSettlement = "
        SELECT
            hb.customer_id,
            SUM(COALESCE(hri.return_amount, 0)) AS settlement_amount,
            SUM(CASE
                WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                  OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                THEN CASE
                    WHEN ABS(COALESCE(hri.grand_total, 0)) > 0.0001
                    THEN ABS(hri.grand_total)
                    ELSE ABS(COALESCE(hri.return_amount, 0))
                END
                ELSE 0
            END) AS cp_mcp_settlement_amount
        FROM detail_bayar db
        INNER JOIN head_bayar hb
            ON hb.bayar_no = db.bayar_no
        INNER JOIN m_customer mc
            ON mc.customer_id = hb.customer_id
           AND COALESCE(mc.is_active, 'Checked') = 'Checked'
        INNER JOIN head_retur_invoice hri
            ON hri.customer_id = hb.customer_id
           AND hri.return_id = COALESCE(
                NULLIF(TRIM(db.return_id), ''),
                NULLIF(TRIM(db.shipping_no), '')
           )
        WHERE UPPER(COALESCE(NULLIF(TRIM(db.payment_source), ''), 'INVOICE')) = 'RETURN'
          AND hb.bayar_date > ?
          AND hb.bayar_date <= ?
          AND hri.return_date <= ?
          AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
          AND ABS(COALESCE(db.bayar_amount, 0)) <= 0.0001
          AND ABS(COALESCE(db.cash_amount, 0)) <= 0.0001
          AND ABS(COALESCE(db.titip_amount, 0)) <= 0.0001
    ";

    $historicalReturnSettlementParams = [
        $bucketBaselineDate,
        $endDate,
        $bucketBaselineDate,
    ];
    $historicalReturnSettlementTypes = 'sss';

    if ($filterBy === 'grup' && $filterValue !== '') {
        $sqlHistoricalReturnSettlement .= " AND COALESCE(mc.area_code, '') = ? ";
        $historicalReturnSettlementParams[] = $filterValue;
        $historicalReturnSettlementTypes .= 's';
    } elseif ($filterBy === 'kota' && $filterValue !== '') {
        $sqlHistoricalReturnSettlement .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
        $historicalReturnSettlementParams[] = $filterValue;
        $historicalReturnSettlementTypes .= 's';
    } elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
        $sqlHistoricalReturnSettlement .= " AND mc.customer_id = ? ";
        $historicalReturnSettlementParams[] = $filterValue;
        $historicalReturnSettlementTypes .= 's';
    }

    $sqlHistoricalReturnSettlement .= " GROUP BY hb.customer_id ";

    $stmtHistoricalReturnSettlement = mysqli_prepare($conn, $sqlHistoricalReturnSettlement);
    mysqli_stmt_bind_param(
        $stmtHistoricalReturnSettlement,
        $historicalReturnSettlementTypes,
        ...$historicalReturnSettlementParams
    );
    mysqli_stmt_execute($stmtHistoricalReturnSettlement);
    $resHistoricalReturnSettlement = mysqli_stmt_get_result($stmtHistoricalReturnSettlement);

    while ($hrs = mysqli_fetch_assoc($resHistoricalReturnSettlement)) {
        $hrsCid = trim((string)($hrs['customer_id'] ?? ''));
        if ($hrsCid === '' || !isset($bucketBaselineMap[$hrsCid])) {
            continue;
        }
        $historicalReturnSettlementAbs[$hrsCid] =
            (float)($historicalReturnSettlementAbs[$hrsCid] ?? 0)
            + abs((float)($hrs['settlement_amount'] ?? 0));
        $historicalCpMcpZeroMarkerSettlementAbs[$hrsCid] =
            (float)($historicalCpMcpZeroMarkerSettlementAbs[$hrsCid] ?? 0)
            + abs((float)($hrs['cp_mcp_settlement_amount'] ?? 0));
    }
    mysqli_stmt_close($stmtHistoricalReturnSettlement);
}

/*
 * REV7 TAHAP 4 - HISTORICAL CP-MCP CREDIT AGE MAP
 * ------------------------------------------------------------
 * CP-MCP return yang sudah menjadi credit pada migration baseline tetap
 * harus mempunyai umur berdasarkan return_date ketika dibawa ke September+.
 *
 * Nilai source memakai grand_total return master (fallback return_amount),
 * karena CP-MCP pada rule existing menggunakan nilai dokumen, bukan selisih
 * teknis return_amount. Reclassification final tetap dibatasi oleh LEBIH
 * negatif yang benar-benar masih tersisa, sehingga Formula 1 tidak berubah.
 */
$historicalCpMcpCreditByBucket = [];

if ($endDate > $bucketBaselineDate && !empty($bucketBaselineMap)) {
    $sqlHistoricalCpMcpCredit = "
        SELECT
            hri.customer_id,
            hri.return_date,
            CASE
                WHEN ABS(COALESCE(hri.grand_total, 0)) > 0.0001
                    THEN ABS(COALESCE(hri.grand_total, 0))
                ELSE ABS(COALESCE(hri.return_amount, 0))
            END AS credit_value
        FROM head_retur_invoice hri
        INNER JOIN m_customer mc
            ON mc.customer_id = hri.customer_id
           AND COALESCE(mc.is_active, 'Checked') = 'Checked'
        WHERE hri.return_date >= '2026-08-01'
          AND hri.return_date <= ?
          AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
          AND (
                UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
             OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
          )
    ";

    $historicalCpMcpCreditParams = [$bucketBaselineDate];
    $historicalCpMcpCreditTypes = 's';

    if ($filterBy === 'grup' && $filterValue !== '') {
        $sqlHistoricalCpMcpCredit .= " AND COALESCE(mc.area_code, '') = ? ";
        $historicalCpMcpCreditParams[] = $filterValue;
        $historicalCpMcpCreditTypes .= 's';
    } elseif ($filterBy === 'kota' && $filterValue !== '') {
        $sqlHistoricalCpMcpCredit .= " AND COALESCE(NULLIF(mc.city, ''), 'TANPA KOTA') = ? ";
        $historicalCpMcpCreditParams[] = $filterValue;
        $historicalCpMcpCreditTypes .= 's';
    } elseif ($filterBy === 'pelanggan' && $filterValue !== '') {
        $sqlHistoricalCpMcpCredit .= " AND mc.customer_id = ? ";
        $historicalCpMcpCreditParams[] = $filterValue;
        $historicalCpMcpCreditTypes .= 's';
    }

    $stmtHistoricalCpMcpCredit = mysqli_prepare($conn, $sqlHistoricalCpMcpCredit);
    mysqli_stmt_bind_param(
        $stmtHistoricalCpMcpCredit,
        $historicalCpMcpCreditTypes,
        ...$historicalCpMcpCreditParams
    );
    mysqli_stmt_execute($stmtHistoricalCpMcpCredit);
    $resHistoricalCpMcpCredit = mysqli_stmt_get_result($stmtHistoricalCpMcpCredit);

    $reportMonthTs = strtotime(date('Y-m-01', strtotime($asOfDate)));

    while ($cpRow = mysqli_fetch_assoc($resHistoricalCpMcpCredit)) {
        $cpCid = trim((string)($cpRow['customer_id'] ?? ''));
        $cpReturnDate = (string)($cpRow['return_date'] ?? '');
        $cpValue = abs((float)($cpRow['credit_value'] ?? 0));

        if (
            $cpCid === ''
            || !isset($bucketBaselineMap[$cpCid])
            || $cpReturnDate === ''
            || $cpValue <= 0.0001
        ) {
            continue;
        }

        $returnMonthTs = strtotime(date('Y-m-01', strtotime($cpReturnDate)));
        if ($reportMonthTs === false || $returnMonthTs === false) {
            continue;
        }

        $reportYear = (int)date('Y', $reportMonthTs);
        $reportMonth = (int)date('n', $reportMonthTs);
        $returnYear = (int)date('Y', $returnMonthTs);
        $returnMonth = (int)date('n', $returnMonthTs);
        $monthDiff = (($reportYear - $returnYear) * 12) + ($reportMonth - $returnMonth);

        if ($monthDiff <= 0) {
            $cpBucketKey = 'belum_jatuh_tempo';
        } elseif ($monthDiff === 1) {
            $cpBucketKey = 'b_1_30';
        } elseif ($monthDiff === 2) {
            $cpBucketKey = 'b_31_60';
        } elseif ($monthDiff === 3) {
            $cpBucketKey = 'b_61_90';
        } else {
            $cpBucketKey = 'b_lebih';
        }

        if (!isset($historicalCpMcpCreditByBucket[$cpCid])) {
            $historicalCpMcpCreditByBucket[$cpCid] = [
                'b_1_30' => 0.0,
                'b_31_60' => 0.0,
                'b_61_90' => 0.0,
                'b_lebih' => 0.0,
                'belum_jatuh_tempo' => 0.0,
            ];
        }

        $historicalCpMcpCreditByBucket[$cpCid][$cpBucketKey] += $cpValue;
    }

    mysqli_stmt_close($stmtHistoricalCpMcpCredit);
}

/*
 * REV7 TAHAP 1:
 * RETURN yang saling menutup dengan INVOICE pada bayar_no yang sama
 * tidak perlu dinormalisasi lagi sebagai residual bucket.
 */
foreach ($migrationPaymentPair as $pairCid => $paymentRows) {
    foreach ($paymentRows as $pair) {
        $paired = min(
            max((float)($pair['invoice'] ?? 0), 0.0),
            max((float)($pair['return'] ?? 0), 0.0)
        );
        if ($paired > 0.0001) {
            $migrationPairedReturnAbs[$pairCid] =
                (float)($migrationPairedReturnAbs[$pairCid] ?? 0)
                + $paired;
        }
    }
}

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
 * - Saldo titip yang BELUM dipakai merupakan credit balance customer.
 * - Kolom TITIP periode dihitung dari perubahan saldo titip awal ke akhir bulan.
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
        END),0) AS unused_titip_cutoff,

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

$unusedTitipParams = [$cutoffDate, $previousDate, $endDate, $endDate];
$unusedTitipTypes = 'ssss';

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
    $calc[$cid]['unused_titip_cutoff'] = max(
        (float)($ut['unused_titip_cutoff'] ?? 0),
        0.0
    );
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
return_usage AS (
    /*
     * Pemakaian Retur sampai akhir periode.
     * - RETURN baru: jumlah ABS(bayar_amount), mendukung parsial/multi.
     * - Legacy non-RETURN link atau RETURN bernilai 0 ditandai legacy_full=1.
     */
    SELECT
        TRIM(db.return_id) AS return_id,
        SUM(
            CASE
                WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='RETURN'
                 AND ABS(COALESCE(db.bayar_amount,0)) > 0.0001
                    THEN ABS(COALESCE(db.bayar_amount,0))
                ELSE 0
            END
        ) AS used_amount_new,
        MAX(
            CASE
                WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))<>'RETURN'
                  OR (
                        UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='RETURN'
                    AND ABS(COALESCE(db.bayar_amount,0)) <= 0.0001
                  )
                THEN 1 ELSE 0
            END
        ) AS legacy_full
    FROM detail_bayar db
    INNER JOIN head_bayar hb
        ON hb.bayar_no = db.bayar_no
    WHERE COALESCE(TRIM(db.return_id),'') <> ''
      AND hb.bayar_date <= ?
    GROUP BY TRIM(db.return_id)
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
              OR (
                    hi_ret.invoice_no IS NOT NULL
                AND hi_ret.invoice_date <= '2026-07-31'
              )
            THEN 1 ELSE 0
        END AS is_standalone_credit,

        CASE
            WHEN (
                    UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                 OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                 OR COALESCE(hri.invoice_no, '') = ''
                 OR hi_ret.invoice_no IS NULL
                 OR (
                        hi_ret.invoice_no IS NOT NULL
                    AND hi_ret.invoice_date <= '2026-07-31'
                    )
                 )
             AND (
                    CASE
                        WHEN COALESCE(ur.legacy_full,0)=1 THEN 0
                        ELSE GREATEST(
                            (
                                CASE
                                    WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                                      OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                                    THEN COALESCE(hri.grand_total, 0)
                                    ELSE COALESCE(hri.return_amount, 0)
                                END
                            ) - COALESCE(ur.used_amount_new,0),
                            0
                        )
                    END
                 ) > 0.0001
            THEN 1 ELSE 0
        END AS is_unused_credit,

        CASE
            WHEN COALESCE(ur.legacy_full,0)=1 THEN 0
            ELSE GREATEST(
                (
                    CASE
                        WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                          OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                        THEN COALESCE(hri.grand_total, 0)
                        ELSE COALESCE(hri.return_amount, 0)
                    END
                ) - COALESCE(ur.used_amount_new,0),
                0
            )
        END AS remaining_credit_value,

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
    LEFT JOIN return_usage ur
        ON ur.return_id = TRIM(hri.return_id)
    WHERE hri.return_date >= '2026-08-01'
      AND hri.return_date <= ?
      AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
";

$returnAggParams = [$cutoffDate, $endDate, $endDate];
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
    /*
     * Semua Retur riil tetap dihitung; manual opening bukan boundary histori.
     */
    SELECT *
    FROM ret_base
    WHERE is_legacy_target = 0
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
        THEN remaining_credit_value ELSE 0 END
    ) AS credit_bjt,

    SUM(CASE
        WHEN is_unused_credit = 1
         AND return_date >= DATE_SUB(?, INTERVAL 1 MONTH)
         AND return_date < ?
        THEN remaining_credit_value ELSE 0 END
    ) AS credit_b_1_30,

    SUM(CASE
        WHEN is_unused_credit = 1
         AND return_date >= DATE_SUB(?, INTERVAL 2 MONTH)
         AND return_date < DATE_SUB(?, INTERVAL 1 MONTH)
        THEN remaining_credit_value ELSE 0 END
    ) AS credit_b_31_60,

    SUM(CASE
        WHEN is_unused_credit = 1
         AND return_date >= DATE_SUB(?, INTERVAL 3 MONTH)
         AND return_date < DATE_SUB(?, INTERVAL 2 MONTH)
        THEN remaining_credit_value ELSE 0 END
    ) AS credit_b_61_90,

    SUM(CASE
        WHEN is_unused_credit = 1
         AND return_date < DATE_SUB(?, INTERVAL 3 MONTH)
        THEN remaining_credit_value ELSE 0 END
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
 * 5A. REKLASIFIKASI BUCKET PER BULAN - REPORTING ONLY
 * ============================================================
 * Tidak mengubah saldo, pembayaran, titip, atau transaksi sumber.
 * Hanya bulan laporan yang sama dengan period_date yang menerima delta.
 * Delta harus net 0 agar formula aging tetap balance.
 */
$bucketReclassMap = [];
if (tableExists($conn, 'aging_bucket_reclassification')) {
    $stmtBucketReclass = mysqli_prepare($conn, "
        SELECT customer_id, b_1_30_delta, b_lebih_delta, bjt_delta
        FROM aging_bucket_reclassification
        WHERE period_date = ?
    ");
    mysqli_stmt_bind_param($stmtBucketReclass, 's', $startDate);
    mysqli_stmt_execute($stmtBucketReclass);
    $resBucketReclass = mysqli_stmt_get_result($stmtBucketReclass);
    while ($bc = mysqli_fetch_assoc($resBucketReclass)) {
        $bcCid = trim((string)($bc['customer_id'] ?? ''));
        if ($bcCid === '' || !isset($masterCustomers[$bcCid])) continue;
        $b1Delta = (float)($bc['b_1_30_delta'] ?? 0);
        $lebihDelta = (float)($bc['b_lebih_delta'] ?? 0);
        $bjtDelta = (float)($bc['bjt_delta'] ?? 0);
        if (abs($b1Delta + $lebihDelta + $bjtDelta) > 0.01) {
            throw new RuntimeException('Reklasifikasi bucket tidak balance untuk customer: ' . $bcCid);
        }
        $bucketReclassMap[$bcCid] = [
            'b_1_30_delta' => $b1Delta,
            'b_lebih_delta' => $lebihDelta,
            'bjt_delta' => $bjtDelta
        ];
    }
    mysqli_stmt_close($stmtBucketReclass);
}

/* Source marker khusus retur CP-MCP dalam bulan laporan.
 * Nilai ini hanya menjadi pengaman penempatan bucket; angka Formula 1
 * dan mekanisme retur existing tetap memakai $sqlReturnAgg.
 */
$currentCpMcpReturnSource = [];
$sqlCurrentCpMcpReturn = "
    SELECT hri.customer_id,
           SUM(COALESCE(hri.grand_total, 0)) AS cp_mcp_return_total
    FROM head_retur_invoice hri
    WHERE hri.return_date BETWEEN ? AND ?
      AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
      AND (
          UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
          OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
      )
    GROUP BY hri.customer_id
";
$stmtCurrentCpMcpReturn = mysqli_prepare($conn, $sqlCurrentCpMcpReturn);
mysqli_stmt_bind_param($stmtCurrentCpMcpReturn, 'ss', $startDate, $endDate);
mysqli_stmt_execute($stmtCurrentCpMcpReturn);
$resCurrentCpMcpReturn = mysqli_stmt_get_result($stmtCurrentCpMcpReturn);
while ($cpSource = mysqli_fetch_assoc($resCurrentCpMcpReturn)) {
    $cpCid = trim((string)($cpSource['customer_id'] ?? ''));
    if ($cpCid !== '' && isset($masterCustomers[$cpCid])) {
        $currentCpMcpReturnSource[$cpCid] = (float)($cpSource['cp_mcp_return_total'] ?? 0);
    }
}
mysqli_stmt_close($stmtCurrentCpMcpReturn);

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

    /*
     * SOURCE OF TRUTH FORMULA 1 tetap KARTU PIUTANG:
     * opening_balance adalah saldo snapshot resmi 31-Jul-2026.
     *
     * customer_opening_balance_bucket HANYA menjelaskan komposisi umur saldo
     * tersebut. Bucket tidak boleh mengganti nominal Saldo Awal hanya karena
     * total seed bucket kebetulan berbeda.
     */
    $openingBalance = $hasOpening
        ? (float)$openingMap[$cid]['opening_balance']
        : 0.0;

    $adj = $adjustmentMap[$cid] ?? [
        'pre_awal'=>0.0,'pre_penjualan'=>0.0,'pre_bayar'=>0.0,'pre_titip'=>0.0,
        'period_awal'=>0.0,'period_penjualan'=>0.0,'period_bayar'=>0.0,'period_titip'=>0.0,
        'opening_payment_until_end'=>0.0
    ];

    /*
     * BAYAR mengikuti transaksi pembayaran nyata pada head_bayar.bayar_date.
     * payment_source=RETURN mengikuti signed payment (negatif), sama dengan
     * Kartu Piutang. Jika tidak ada transaksi bayar pada bulan laporan,
     * kolom BAYAR tetap 0.
     */
    $preCashForFormula = $c['pre_cash'];
    $periodCash = $c['period_cash'];

    /*
     * SALDO AWAL - RULE CUT-OFF 31-Jul-2026
     * ----------------------------------------------------------
     * Agustus 2026:
     *   saldo_awal = opening_balance snapshot 31-Jul-2026.
     *
     * September dan seterusnya:
     *   opening snapshot
     *   + invoice post-cutoff sebelum bulan filter
     *   + koreksi invoice post-cutoff sebelum bulan filter (signed/minus)
     *   - retur post-cutoff sebelum bulan filter
     *   - pembayaran post-cutoff sebelum bulan filter
     *   - titip-used post-cutoff sebelum bulan filter.
     *
     * Transaksi Juli dan sebelumnya TIDAK pernah ditambahkan lagi.
     */
    $preGrossPayment =
        (float)$preCashForFormula
        + (float)$c['pre_titip'];

    $preTitipDelta =
        (float)$c['unused_titip_before']
        - (float)$c['unused_titip_cutoff'];

    $saldoAwal =
        $openingBalance
        + $c['pre_invoice']
        + $c['pre_invoice_correction']
        - $c['pre_return']
        - $preGrossPayment
        - $preTitipDelta;

    // Adjustment migrasi existing tetap dipertahankan.
    $saldoAwal +=
        $adj['pre_awal']
        + $adj['pre_penjualan']
        - $adj['pre_bayar']
        - $adj['pre_titip'];

    $periodInvoice = $c['period_invoice'];
    $periodInvoiceCorrection = $c['period_invoice_correction'];
    $periodReturn = $c['period_return'];

    /*
     * SOURCE OF TRUTH KARTU PIUTANG:
     * Koreksi invoice adalah PENJUALAN signed (minus), bukan pembayaran.
     */
    $penjualanNeto =
        $periodInvoice
        + $periodInvoiceCorrection
        - $periodReturn
        + $adj['period_penjualan'];

    $periodCash =
        (float)$c['period_cash']
        + (float)$c['period_titip']
        + (float)$adj['period_bayar'];

    $saldoAwal += $adj['period_awal'];

    /*
     * RULE TITIP PERIODE:
     * TITIP = saldo titip akhir bulan - saldo titip awal bulan.
     *
     * - Titip bulan sebelumnya yang habis dipakai bulan ini tampil negatif.
     * - Titip yang masuk dan habis dipakai pada bulan yang sama net menjadi 0.
     * - Bulan berikutnya tidak tampil lagi bila saldo titip sudah habis.
     *
     * Adjustment period_titip tetap dipertahankan.
     * REV7: movement wajib tetap SIGNED seperti program lama.
     * Jangan ABS()/max() hasil delta ini: pemakaian titip lama harus dapat tampil negatif.
     */
    $periodTitipMovement =
        (float)$c['unused_titip_end']
        - (float)$c['unused_titip_before'];

    $periodTitip =
        $periodTitipMovement
        + (float)$adj['period_titip'];

    /*
     * Titip belum digunakan adalah credit balance Aging.
     * Saldo Awal memakai saldo titip belum terpakai per H-1.
     * Saldo Akhir memakai saldo titip belum terpakai per akhir periode.
     *
     * Kolom TITIP menunjukkan perubahan saldo titip awal -> akhir bulan.
     */
    $saldoAwalBase = $saldoAwal;
    $unusedTitipBefore = (float)$c['unused_titip_before'];
    $unusedTitipEnd = (float)$c['unused_titip_end'];

    $akhir =
        $saldoAwal
        + $penjualanNeto
        - $periodCash
        - $periodTitip;

    /* ========================================================
     * FINAL BUCKET SOURCE - MIGRATION BASELINE
     * ========================================================
     * HANYA memilih source 5 bucket. Formula 1 di atas tidak disentuh.
     */
    $hasMigrationBaseline = isset($bucketBaselineMap[$cid]);
    $useMigrationBaseline = (
        $hasMigrationBaseline
        && $endDate >= $bucketBaselineDate
    );

    if ($useMigrationBaseline) {

        if ($endDate === $bucketBaselineDate) {
            /* Closing Agustus = snapshot hasil rekonsiliasi. */
            $migrationSeed = $bucketBaselineMap[$cid];

            $b1 = (float)$migrationSeed['b_1_30'];
            $b2 = (float)$migrationSeed['b_31_60'];
            $b3 = (float)$migrationSeed['b_61_90'];
            $bLebih = (float)$migrationSeed['b_lebih'];
            $bBelumJatuhTempo = (float)$migrationSeed['belum_jatuh_tempo'];

        } else {
            /* September+ = rollover baseline + payment baseline + invoice baru.
             * Revisi ini mempertahankan signed bucket historical apa adanya. */
            $migrationSeed = makeMigrationBucketSeed($bucketBaselineMap[$cid]);

            /*
             * STEP 5 - September+:
             * 1) rollover snapshot closing 31-Aug sesuai rule existing;
             * 2) kurangi porsi BAYAR periode yang masih diperlukan untuk
             *    melunasi saldo baseline, maksimal sebesar saldo baseline
             *    positif. Invoice post-baseline tetap dihitung oleh query
             *    post_base_* sehingga payment tidak dihitung dua kali.
             *
             * baselinePaymentTimeline tetap dipakai untuk payment yang dapat
             * ditelusuri eksplisit ke invoice <= baseline. Kekurangannya
             * diambil dari periodCash HANYA sampai saldo baseline habis.
             */
            $migrationTimeline = $baselinePaymentTimeline[$cid] ?? [];

            $legacyBucket = simulateOpeningBuckets(
                $migrationSeed,
                $asOfDate,
                $migrationTimeline
            );

            /*
             * REV7 TAHAP 1:
             * Terapkan payment invoice historical langsung ke bucket invoice
             * yang dipilih. Tidak mengubah Formula 1.
             */
            foreach (
                ($baselineInvoicePaymentByBucket[$cid] ?? [])
                as $targetBucketKey => $targetPayment
            ) {
                $targetPayment = max((float)$targetPayment, 0.0);
                $availableTarget = max(
                    (float)($legacyBucket[$targetBucketKey] ?? 0),
                    0.0
                );
                $takeTarget = min($availableTarget, $targetPayment);

                if ($takeTarget > 0.0001) {
                    $legacyBucket[$targetBucketKey] -= $takeTarget;
                }
            }

            $baselineRemain = sumOpeningBucketSeed($legacyBucket);
            $timelineApplied = array_sum($migrationTimeline);
            $targetedBaselinePaid = array_sum(
                $baselineInvoicePaymentByBucket[$cid] ?? []
            );
            $postBaselineLinkedPaid = (float)($postBaselineLinkedPaymentTotal[$cid] ?? 0);
            $periodCashForBaseline = max(
                (float)$periodCash
                - (float)$timelineApplied
                - (float)$targetedBaselinePaid
                - $postBaselineLinkedPaid,
                0.0
            );

            if ($baselineRemain > 0.0001 && $periodCashForBaseline > 0.0001) {
                $fallbackOpeningPayment = min($baselineRemain, $periodCashForBaseline);
                applySignedOpeningPayment($legacyBucket, $fallbackOpeningPayment);
            }

            /*
             * REVISI SEPTEMBER - SIGNED BUCKET
             * ----------------------------------------------------
             * JANGAN menetralkan bucket negatif secara global.
             * Hasil program lama membuktikan saldo/kredit negatif dapat
             * memiliki umur bucket yang sah dan harus ikut rollover, contoh:
             * SARMIN, HENDRA MULYONO, LIE PEK WAN, LUSIANI, HERMAN.
             *
             * Karena itu legacyBucket hasil baseline 31-Aug + rollover +
             * payment baseline dipertahankan apa adanya. Pembersihan pasangan
             * +/- palsu untuk customer lunas tetap ditangani oleh mekanisme
             * STEP 6 setelah bucket historical dan post-baseline digabung.
             */

            $b1 = (float)$legacyBucket['b_1_30']
                + (float)($c['post_base_b_1_30'] ?? 0);

            $b2 = (float)$legacyBucket['b_31_60']
                + (float)($c['post_base_b_31_60'] ?? 0);

            $b3 = (float)$legacyBucket['b_61_90']
                + (float)($c['post_base_b_61_90'] ?? 0);

            $bLebih = (float)$legacyBucket['b_lebih']
                + (float)($c['post_base_b_lebih'] ?? 0);

            $bBelumJatuhTempo = (float)$legacyBucket['belum_jatuh_tempo']
                + (float)($c['post_base_bjt'] ?? 0);

            /*
             * STEP 6 - ALOKASI SISA PAYMENT KE BUCKET POST-BASELINE
             * ----------------------------------------------------
             * Query post_base_* tetap menjadi source bucket invoice September+
             * dan seluruh rule pay_inv / retur / titip existing TIDAK diubah.
             *
             * Pada sebagian transaksi, periodCash sudah membuat Formula 1 lunas
             * tetapi masih tersisa bucket invoice post-baseline. Sebelumnya
             * FINAL RESIDUAL menutupnya dengan LEBIH negatif (contoh:
             * BJT +9.502.000 dan LEBIH -9.502.000 saat AKHIR = 0).
             *
             * Jangan kurangi seluruh periodCash lagi karena payment yang sudah
             * terikat di query invoice dapat terhitung dua kali. Yang dialokasikan
             * hanya EXCESS bucket positif terhadap AKHIR positif Formula 1.
             * Dengan demikian Formula 1, baseline Agustus, dan query existing
             * tetap tidak disentuh.
             */
            $combinedBucket = [
                'b_1_30' => (float)$b1,
                'b_31_60' => (float)$b2,
                'b_61_90' => (float)$b3,
                'b_lebih' => (float)$bLebih,
                'belum_jatuh_tempo' => (float)$bBelumJatuhTempo,
            ];

            /*
             * STEP 6 REV5 - NORMALISASI RETURN / OPENING MIGRATION
             * ----------------------------------------------------
             * RETURN signed negatif pada pembayaran sudah tercermin pada
             * Formula 1 (Penjualan/ Bayar) dan tidak boleh sekali lagi
             * mengurangi aging invoice historical. Kembalikan nominal RETURN
             * ke bucket 1-30 bulan berjalan.
             *
             * OPENING signed negatif yang efektif adalah adjustment historical,
             * bukan credit baru. Setelah rollover, hapus komponen negatifnya
             * dari bucket historical. Offset yang sebelumnya akan jatuh ke
             * residual LEBIH dikompensasikan di sini agar total bucket tetap
             * sama dengan AKHIR tanpa hardcode customer.
             */
            $rev5ReturnAbs = abs(min((float)($migrationReturnSigned[$cid] ?? 0), 0.0));

            /* REV7: jangan normalisasi ulang RETURN yang sudah berpasangan. */
            $rev7PairedReturnAbs = (float)($migrationPairedReturnAbs[$cid] ?? 0);
            $rev5ReturnAbs = max(
                $rev5ReturnAbs - $rev7PairedReturnAbs,
                0.0
            );

            $rev5OpeningAbs = abs(min((float)($migrationOpeningSigned[$cid] ?? 0), 0.0));

            if ($rev5ReturnAbs > 0.0001) {
                $combinedBucket['b_1_30'] += $rev5ReturnAbs;
                $combinedBucket['b_lebih'] -= $rev5ReturnAbs;
            }

            if ($rev5OpeningAbs > 0.0001) {
                /* Cari bucket historical negatif hasil rollover OPENING. */
                foreach (['b_lebih', 'b_61_90', 'b_31_60', 'b_1_30', 'belum_jatuh_tempo'] as $rev5Key) {
                    if ($rev5OpeningAbs <= 0.0001) {
                        break;
                    }
                    $rev5Value = (float)$combinedBucket[$rev5Key];
                    if ($rev5Value < -0.0001) {
                        $rev5Clear = min(abs($rev5Value), $rev5OpeningAbs);
                        $combinedBucket[$rev5Key] += $rev5Clear;
                        $combinedBucket['b_lebih'] -= $rev5Clear;
                        $rev5OpeningAbs -= $rev5Clear;
                    }
                }
            }

            $b1 = (float)$combinedBucket['b_1_30'];
            $b2 = (float)$combinedBucket['b_31_60'];
            $b3 = (float)$combinedBucket['b_61_90'];
            $bLebih = (float)$combinedBucket['b_lebih'];
            $bBelumJatuhTempo = (float)$combinedBucket['belum_jatuh_tempo'];

            $positiveBucketTotal = 0.0;
            foreach ($combinedBucket as $bucketValue) {
                if ($bucketValue > 0.0001) {
                    $positiveBucketTotal += $bucketValue;
                }
            }

            /*
             * FIX SOURCE-AWARE SIGNED BUCKET (SEPTEMBER DAN SETERUSNYA)
             * ---------------------------------------------------------
             * Pada migration baseline, bucket umur negatif merupakan posisi
             * historis yang sah. Jika LEBIH juga negatif sementara ada invoice
             * post-baseline yang positif, membandingkan seluruh bucket positif
             * langsung dengan AKHIR NET akan menganggap bucket negatif umur
             * sebagai "kelebihan pembayaran" dan memotong invoice baru.
             *
             * Pertahankan nilai negatif umur (bukan LEBIH) ketika menentukan
             * batas outstanding positif. Kelebihan positif yang memang masih
             * perlu dibersihkan tetap ditangani allocator existing.
             *
             * Tidak ada customer_id, tanggal periode, atau nominal hardcode.
             */
            $protectedAgedCredit = 0.0;
            if (
                (float)$combinedBucket['b_lebih'] < -0.0001
                && (
                    (float)($c['post_base_b_1_30'] ?? 0) > 0.0001
                    || (float)($c['post_base_b_31_60'] ?? 0) > 0.0001
                    || (float)($c['post_base_b_61_90'] ?? 0) > 0.0001
                    || (float)($c['post_base_bjt'] ?? 0) > 0.0001
                )
            ) {
                foreach (['b_1_30', 'b_31_60', 'b_61_90'] as $ageKey) {
                    $protectedAgedCredit += abs(min(
                        (float)$combinedBucket[$ageKey],
                        0.0
                    ));
                }
            }

            /*
             * FIX CREDIT LEBIH HISTORIS - SOURCE-AWARE
             * ------------------------------------------
             * Apabila closing baseline mempunyai LEBIH negatif yang masih
             * tersisa sesudah rollover dan pembayaran, ia adalah komponen
             * kredit historis yang sah. Jangan mengurangi bucket invoice
             * positif sebesar kredit tersebut hanya karena AKHIR bersifat net.
             *
             * Pelindung ini tidak diterapkan bersamaan dengan bucket umur
             * negatif agar rule perlindungan SARMIN tetap dipertahankan dan
             * tidak terjadi perlindungan kredit ganda.
             */
            $protectedHistoricalLebihCredit = 0.0;
            $hasNegativeAgedBucket = false;
            foreach (['b_1_30', 'b_31_60', 'b_61_90'] as $ageKey) {
                if ((float)$combinedBucket[$ageKey] < -0.0001) {
                    $hasNegativeAgedBucket = true;
                    break;
                }
            }

            $hasPositivePostBaselineBucket = (
                (float)($c['post_base_b_1_30'] ?? 0) > 0.0001
                || (float)($c['post_base_b_31_60'] ?? 0) > 0.0001
                || (float)($c['post_base_b_61_90'] ?? 0) > 0.0001
                || (float)($c['post_base_bjt'] ?? 0) > 0.0001
            );

            if (
                !$hasNegativeAgedBucket
                && $hasPositivePostBaselineBucket
                && (float)($migrationSeed['b_lebih'] ?? 0) < -0.0001
                && (float)$combinedBucket['b_lebih'] < -0.0001
            ) {
                $protectedHistoricalLebihCredit = min(
                    abs((float)$combinedBucket['b_lebih']),
                    abs((float)$migrationSeed['b_lebih'])
                );
            }

            $targetPositiveOutstanding = max(
                (float)$akhir + $protectedAgedCredit + $protectedHistoricalLebihCredit,
                0.0
            );
            $postBasePaymentExcess = max(
                $positiveBucketTotal - $targetPositiveOutstanding,
                0.0
            );

            if ($postBasePaymentExcess > 0.0001) {
                /*
                 * Pakai allocator existing: payment menutup bucket tertua dulu.
                 * Nilai dibatasi sebesar excess, sehingga tidak membuat credit
                 * baru dan tidak mengubah total AKHIR Formula 1.
                 */
                applySignedOpeningPayment(
                    $combinedBucket,
                    $postBasePaymentExcess
                );

                $b1 = (float)$combinedBucket['b_1_30'];
                $b2 = (float)$combinedBucket['b_31_60'];
                $b3 = (float)$combinedBucket['b_61_90'];
                $bLebih = (float)$combinedBucket['b_lebih'];
                $bBelumJatuhTempo = (float)$combinedBucket['belum_jatuh_tempo'];
            }
        }

    } else {
        /* ====================================================
         * EXISTING BUCKET LOGIC - TIDAK DIUBAH
         * ==================================================== */
        $b1 = $c['b_1_30'];
        $b2 = $c['b_31_60'];
        $b3 = $c['b_61_90'];
        $bLebih = $c['b_lebih'];
        $bBelumJatuhTempo = $c['belum_jatuh_tempo'];

        /* SOURCE-AWARE: verifikasi ulang invoice bulan berjalan ketika raw
         * bucket ternyata masih menunjukkan BRUTO meski invoice sudah dibayar.
         * Pertahankan rule lama BELUM JT (RUSLAN/ABDUL), tambahkan 1-30
         * (EVERGREEN), hanya bila seluruh gross cocok dengan satu bucket,
         * dan hasil outstanding cocok dengan Formula 1. Tidak menyentuh
         * baseline, opening, retur, koreksi, serta transaksi periode lain.
         */
        $verifiedInv = $verifiedCurrentNotDue[$cid] ?? null;
        if (
            !$hasOpening
            && !$hasMigrationBaseline
            && $verifiedInv !== null
            && (int)$verifiedInv['invoice_count'] > 0
            && (int)$verifiedInv['return_count'] === 0
            && (int)$verifiedInv['overpaid_count'] === 0
            && abs((float)$c['pre_invoice']) <= 0.01
            && abs((float)$c['pre_return']) <= 0.01
            && abs((float)$c['period_return']) <= 0.01
            && abs((float)$c['pre_invoice_correction']) <= 0.01
            && abs((float)$c['period_invoice_correction']) <= 0.01
            && abs((float)$c['orphan_total']) <= 0.01
            /* Pemakaian titip pada detail invoice adalah pembayaran sah.
             * Jangan wajibkan period_titip = 0: invoice dengan cash + titip
             * tetap diverifikasi melalui paid_total per invoice serta guard
             * outstanding_total - unused_titip_end == AKHIR di bawah.
             * Guard saldo titip historis dan transaksi retur tetap aktif. */
            && abs((float)$c['pre_cash']) <= 0.01
            && abs((float)$c['pre_titip']) <= 0.01
            && abs((float)$c['unused_titip_before']) <= 0.01
            && abs((float)$c['period_invoice'] - (float)$verifiedInv['gross_total']) <= 0.01
            && (
                /* Invoice belum JT: rule existing tidak diubah. */
                (
                    (int)$verifiedInv['overdue_count'] === 0
                    && abs((float)$bBelumJatuhTempo - (float)$verifiedInv['gross_total']) <= 0.01
                    && abs((float)$b1) <= 0.01
                )
                ||
                /* Invoice overdue 1-30: bruto mentah harus sama persis
                 * dengan invoice yang terverifikasi, bukan residual palsu. */
                (
                    abs((float)$verifiedInv['gross_1_30'] - (float)$verifiedInv['gross_total']) <= 0.01
                    && abs((float)$b1 - (float)$verifiedInv['gross_total']) <= 0.01
                    && abs((float)$bBelumJatuhTempo) <= 0.01
                )
            )
            && abs((float)$b2) <= 0.01
            && abs((float)$b3) <= 0.01
            && abs((float)$bLebih) <= 0.01
            && (float)$verifiedInv['outstanding_total'] >= -0.01
            && (float)$verifiedInv['outstanding_total'] <= (float)$verifiedInv['gross_total'] + 0.01
            && (float)$verifiedInv['paid_total'] > 0.01
            /* Guard agar nilai verifikasi konsisten dengan Formula 1. */
            && abs(
                (float)$akhir
                - ((float)$verifiedInv['outstanding_total'] - (float)$c['unused_titip_end'])
            ) <= 0.01
        ) {
            if (abs((float)$b1 - (float)$verifiedInv['gross_total']) <= 0.01) {
                $b1 = (float)$verifiedInv['outstanding_1_30'];
            } else {
                $bBelumJatuhTempo = (float)$verifiedInv['outstanding_total'];
            }
        }

        if ($hasOpening) {
            if ($hasValidOpeningBucket) {

                $seed = $openingBucketMap[$cid];

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

                $seedManualTotal = sumOpeningBucketSeed($seed);
                $snapshotUnbucketed = $openingBalance - $seedManualTotal;
                if (abs($snapshotUnbucketed) > 0.0001) {
                    $bLebih += $snapshotUnbucketed;
                }
            } else {
                $openingTimelineTotal =
                    array_sum($openingPaymentTimeline[$cid] ?? []);

                $openingOutstanding =
                    $openingBalance
                    - $openingTimelineTotal;

                $bLebih += $openingOutstanding;
            }
        }
    }

    /*
     * Closing migration Agustus sudah merupakan posisi NET final.
     * Jangan kurangi orphan/return/titip sekali lagi pada snapshot tersebut.
     * September+ kembali memakai rule credit existing; residual Lebih tetap
     * menjadi safety net Formula 2.
     */
    $isExactMigrationClosing = (
        $useMigrationBaseline
        && $endDate === $bucketBaselineDate
    );

    $unusedReturnCredit =
        (float)$c['credit_bjt']
        + (float)$c['credit_b_1_30']
        + (float)$c['credit_b_31_60']
        + (float)$c['credit_b_61_90']
        + (float)$c['credit_b_lebih'];

    $unusedTitipEnd = (float)$c['unused_titip_end'];

    if (!$isExactMigrationClosing) {
        /* Orphan payment tetap diperlakukan sebagai credit customer. */
        $bLebih -= $c['orphan_total'];

        /* RULE FINAL BUCKET LEBIH existing. */
        $bLebih -= $unusedReturnCredit;
        $bLebih -= $unusedTitipEnd;
    }

    /*
     * FINAL RESIDUAL LEBIH
     * ----------------------------------------------------------
     * Source of truth Formula 2 adalah AKHIR dari Formula 1.
     * Setelah BJT, 1-30, 31-60, dan 61-90 selesai dihitung dari
     * invoice/outstanding yang memiliki umur yang dapat ditentukan,
     * seluruh sisa posisi ditempatkan pada LEBIH.
     *
     * Dengan demikian secara matematis WAJIB:
     *   1-30 + 31-60 + 61-90 + LEBIH + BJT = AKHIR
     *
     * Residual ini juga menampung posisi migrasi/cutoff yang umur
     * historisnya tidak dapat ditentukan secara lebih rinci.
     */
    $bLebihSourceBased = $bLebih;
    $bLebih =
        (float)$akhir
        - (
            (float)$bBelumJatuhTempo
            + (float)$b1
            + (float)$b2
            + (float)$b3
        );

    /*
     * STEP 6 REV6 - BERSIHKAN OFFSET OPENING MIGRATION
     * --------------------------------------------------
     * Khusus migration September+ dan hanya bila periode mempunyai
     * payment_source=OPENING signed negatif. Jika setelah seluruh rule
     * Rev5 + FINAL RESIDUAL masih terbentuk pasangan:
     *   bucket umur negatif  <-> LEBIH positif
     * dengan nominal yang berasal dari OPENING tersebut, keduanya adalah
     * offset dari adjustment historical yang sama dan tidak boleh tampil
     * sebagai dua bucket aging terpisah.
     *
     * Penting: ini BUKAN netting signed bucket global. Nilai yang boleh
     * dibersihkan dibatasi oleh nominal OPENING periode berjalan, sehingga
     * signed bucket valid customer lain tetap dipertahankan.
     */
    if ($useMigrationBaseline && $endDate > $bucketBaselineDate) {
        $rev6OpeningRemaining = abs(min(
            (float)($migrationOpeningSigned[$cid] ?? 0),
            0.0
        ));

        if ($rev6OpeningRemaining > 0.0001 && $bLebih > 0.0001) {
            foreach (['b3', 'b2', 'b1'] as $rev6BucketVar) {
                if ($rev6OpeningRemaining <= 0.0001 || $bLebih <= 0.0001) {
                    break;
                }

                $rev6BucketValue = (float)$$rev6BucketVar;
                if ($rev6BucketValue < -0.0001) {
                    $rev6Clear = min(
                        abs($rev6BucketValue),
                        $bLebih,
                        $rev6OpeningRemaining
                    );

                    $$rev6BucketVar += $rev6Clear;
                    $bLebih -= $rev6Clear;
                    $rev6OpeningRemaining -= $rev6Clear;
                }
            }
        }

        /*
         * REV7 TAHAP 3 - BERSIHKAN HISTORICAL RETURN SETTLEMENT
         * ------------------------------------------------------
         * Jika RETURN sebelum/hingga baseline sudah membentuk credit negatif
         * pada migration baseline, lalu September+ mempunyai marker RETURN
         * legacy bernilai 0 yang mereferensikan return master tersebut, maka
         * pasangan bucket historical negatif <-> LEBIH positif adalah offset
         * dari settlement yang sama.
         *
         * Bersihkan hanya sebesar nominal return master yang terbukti terkait.
         * Signed bucket lain (mis. HERMAN) tidak disentuh bila tidak memenuhi
         * sumber/marker ini.
         */
        $rev7HistoricalReturnRemaining = (float)(
            $historicalReturnSettlementAbs[$cid] ?? 0
        );

        if ($rev7HistoricalReturnRemaining > 0.0001 && $bLebih > 0.0001) {
            foreach (['b3', 'b2', 'b1'] as $rev7ReturnBucketVar) {
                if (
                    $rev7HistoricalReturnRemaining <= 0.0001
                    || $bLebih <= 0.0001
                ) {
                    break;
                }

                $rev7ReturnBucketValue = (float)$$rev7ReturnBucketVar;
                if ($rev7ReturnBucketValue < -0.0001) {
                    $rev7ReturnClear = min(
                        abs($rev7ReturnBucketValue),
                        $bLebih,
                        $rev7HistoricalReturnRemaining
                    );

                    $$rev7ReturnBucketVar += $rev7ReturnClear;
                    $bLebih -= $rev7ReturnClear;
                    $rev7HistoricalReturnRemaining -= $rev7ReturnClear;
                }
            }
        }
    }

    /*
     * REV8 - CP-MCP historical zero marker dengan credit baru sesudah baseline.
     * ----------------------------------------------------------------------
     * Bukti: RETURN zero di detail_bayar yang valid terhubung ke retur CP-MCP
     * <= baseline. Pada Formula 1, RETURN legacy efektif (signed negatif)
     * menutup saldo kredit lama; posisi bucket lama yang masih terbawa dari
     * baseline tidak boleh ikut menjadi outstanding kredit baru.
     *
     * Setelah kredit historis dibersihkan, sisa credit CP-MCP yang masih
     * belum terpakai diklasifikasikan sesuai umur RETUR (bukan umur invoice).
     * Semua perpindahan di sini zero-sum; tidak mengubah AWAL, PENJUALAN,
     * BAYAR, TITIP, maupun AKHIR. Tidak menggunakan customer atau angka tetap.
     *
     * Aktif hanya pada kombinasi marker CP-MCP legacy bernilai 0 DAN
     * kredit retur belum terpakai yang telah ditemukan source query existing.
     */
    $cpZeroMarkerValue = (float)($historicalCpMcpZeroMarkerSettlementAbs[$cid] ?? 0);
    $cpCreditByAge = [
        'b1' => max((float)($c['credit_b_1_30'] ?? 0), 0.0),
        'b2' => max((float)($c['credit_b_31_60'] ?? 0), 0.0),
        'b3' => max((float)($c['credit_b_61_90'] ?? 0), 0.0),
        'bBelumJatuhTempo' => max((float)($c['credit_bjt'] ?? 0), 0.0),
    ];
    $cpRemainingCreditAgeTotal = array_sum($cpCreditByAge);

    if (
        $useMigrationBaseline
        && $endDate > $bucketBaselineDate
        && $cpZeroMarkerValue > 0.0001
        && $cpRemainingCreditAgeTotal > 0.0001
        && $bLebih < -0.0001
    ) {
        // Bersihkan hanya nilai kredit umur historis yang benar-benar negatif.
        // Sisanya dipindahkan sementara ke LEBIH supaya total tetap sama.
        $cpHistoricalToClear = $cpZeroMarkerValue;
        foreach (['b3', 'b2', 'b1'] as $cpHistoricalAgeVariable) {
            if ($cpHistoricalToClear <= 0.0001) break;
            if ((float)$$cpHistoricalAgeVariable >= -0.0001) continue;
            $cpClear = min(abs((float)$$cpHistoricalAgeVariable), $cpHistoricalToClear);
            $$cpHistoricalAgeVariable += $cpClear;
            $bLebih -= $cpClear;
            $cpHistoricalToClear -= $cpClear;
        }

        // Credit return yang benar-benar belum digunakan ditempatkan menurut
        // periode retur, dengan batas sebesar residual LEBIH negatif.
        // BJT = bulan retur, 1-30 = bulan setelahnya, dst.
        foreach ($cpCreditByAge as $cpTargetVariable => $cpAmount) {
            if ($cpAmount <= 0.0001 || $bLebih >= -0.0001) continue;
            $cpMove = min($cpAmount, abs($bLebih));
            $$cpTargetVariable -= $cpMove;
            $bLebih += $cpMove;
        }
    }

    /*
     * REV7 TAHAP 4 - RECLASS HISTORICAL CP-MCP CREDIT
     * --------------------------------------------------
     * Jalankan SETELAH Tahap 3 settlement cleanup.
     * Hanya LEBIH negatif yang masih tersisa yang boleh dipindahkan.
     * Karena itu:
     * - ABU yang sudah settle menjadi 0 tidak terbuka kembali;
     * - customer tanpa historical CP-MCP return tidak disentuh;
     * - nominal tidak pernah melebihi posisi credit migration yang tersisa;
     * - total 5 bucket tetap sama, sehingga Formula 2 tidak berubah.
     */
    if (
        $useMigrationBaseline
        && $endDate > $bucketBaselineDate
        && $bLebih < -0.0001
        && isset($historicalCpMcpCreditByBucket[$cid])
    ) {
        $rev7CpRemaining = abs((float)$bLebih);
        $rev7CpSource = $historicalCpMcpCreditByBucket[$cid];

        foreach (
            [
                'b_1_30' => 'b1',
                'b_31_60' => 'b2',
                'b_61_90' => 'b3',
                'belum_jatuh_tempo' => 'bBelumJatuhTempo',
            ]
            as $rev7CpKey => $rev7CpVar
        ) {
            if ($rev7CpRemaining <= 0.0001) {
                break;
            }

            $rev7CpAvailable = max((float)($rev7CpSource[$rev7CpKey] ?? 0), 0.0);
            if ($rev7CpAvailable <= 0.0001) {
                continue;
            }

            $rev7CpMove = min($rev7CpRemaining, $rev7CpAvailable);
            $$rev7CpVar -= $rev7CpMove;
            $bLebih += $rev7CpMove;
            $rev7CpRemaining -= $rev7CpMove;
        }
    }

    /* OPENING signed negatif yang efektif dan masuk snapshot sebelum
     * baseline dapat meninggalkan umur kredit negatif historis sementara
     * BJT tetap positif. Bersihkan hanya nilai yang terverifikasi dari
     * transaksi opening, cap sesuai saldo baseline negatif, dan pindahkan
     * imbangannya dari BJT. Tidak mengubah AKHIR dan tidak menyentuh
     * refund / titip / customer tanpa baseline.
     */
    if ($useMigrationBaseline && $endDate > $bucketBaselineDate) {
        $effectiveNegativeOpening = abs(min(
            (float)($migrationOpeningSigned[$cid] ?? 0), 0.0
        ));
        $negativeSeedTotal = 0.0;
        foreach (['b_1_30','b_31_60','b_61_90','b_lebih','belum_jatuh_tempo'] as $openingSeedKey) {
            $negativeSeedTotal += abs(min(
                (float)($bucketBaselineMap[$cid][$openingSeedKey] ?? 0), 0.0
            ));
        }
        $openingCleanupCap = min($effectiveNegativeOpening, $negativeSeedTotal);
        if (
            $openingCleanupCap > 0.0001
            && abs((float)$bLebih) <= 0.01
            && $bBelumJatuhTempo > 0.0001
        ) {
            foreach (['b3','b2','b1'] as $openingAgeVar) {
                if ($openingCleanupCap <= 0.0001 || $bBelumJatuhTempo <= 0.0001) break;
                $agedCredit = (float)$$openingAgeVar;
                if ($agedCredit >= -0.0001) continue;
                $clearOpening = min(abs($agedCredit), $openingCleanupCap, $bBelumJatuhTempo);
                $$openingAgeVar += $clearOpening;
                $bBelumJatuhTempo -= $clearOpening;
                $openingCleanupCap -= $clearOpening;
            }
        }
    }

    /* ============================================================
     * SOURCE-AWARE CREDIT BULAN BERJALAN TANPA BASELINE / OPENING
     * ------------------------------------------------------------
     * Dua sumber kredit yang tidak mempunyai due_date invoice normal:
     * 1) koreksi invoice aktif (signed negatif) bulan berjalan;
     * 2) retur CP-MCP bulan berjalan yang belum digunakan (credit_bjt).
     *
     * HANYA untuk kasus murni: tanpa transaksi historis, invoice normal,
     * payment, titip, saldo lain, ataupun reklasifikasi existing; kredit
     * dari sumbernya harus tepat sama dengan AKHIR dan LEBIH residual.
     * Offset ZERO-SUM dari LEBIH ke BJT. Tidak hardcode customer/angka/bulan.
     * Customer yang mempunyai campuran/migrasi tidak disentuh.
     * ============================================================ */
    $creditOnlySource = 0.0;
    $isCurrentCorrectionOnly = (
        (float)$c['period_invoice_correction'] < -0.0001
        && abs((float)$c['period_return']) <= 0.01
        && abs((float)$c['credit_bjt']) <= 0.01
        && abs((float)$penjualanNeto - (float)$c['period_invoice_correction']) <= 0.01
    );
    $isCurrentUnusedReturnOnly = (
        (float)$c['period_return'] > 0.0001
        && abs((float)$c['period_invoice_correction']) <= 0.01
        && abs((float)$c['credit_bjt'] - (float)$c['period_return']) <= 0.01
        && abs((float)($currentCpMcpReturnSource[$cid] ?? 0) - (float)$c['period_return']) <= 0.01
        && abs((float)$c['period_return_offset'] - (float)$c['period_return']) <= 0.01
        && abs((float)$penjualanNeto + (float)$c['period_return']) <= 0.01
    );

    if (
        !$hasOpening
        && !$hasMigrationBaseline
        && !isset($bucketReclassMap[$cid])
        && ($isCurrentCorrectionOnly || $isCurrentUnusedReturnOnly)
        && abs((float)$saldoAwal) <= 0.01
        && abs((float)$c['pre_invoice']) <= 0.01
        && abs((float)$c['pre_invoice_correction']) <= 0.01
        && abs((float)$c['pre_return']) <= 0.01
        && abs((float)$c['period_invoice']) <= 0.01
        && abs((float)$c['pre_cash']) <= 0.01
        && abs((float)$c['pre_titip']) <= 0.01
        && abs((float)$c['period_cash']) <= 0.01
        && abs((float)$c['period_titip']) <= 0.01
        && abs((float)$c['orphan_total']) <= 0.01
        && abs((float)$c['unused_titip_end']) <= 0.01
        && abs((float)$periodCash) <= 0.01
        && abs((float)$periodTitip) <= 0.01
        && abs((float)$b1) <= 0.01
        && abs((float)$b2) <= 0.01
        && abs((float)$b3) <= 0.01
        && abs((float)$bBelumJatuhTempo) <= 0.01
        && (float)$akhir < -0.0001
        && abs((float)$bLebih - (float)$akhir) <= 0.01
    ) {
        $creditOnlySource = $isCurrentCorrectionOnly
            ? (float)$c['period_invoice_correction']
            : -(float)$c['credit_bjt'];
        if (abs($creditOnlySource - (float)$akhir) <= 0.01) {
            $bLebih -= $creditOnlySource;
            $bBelumJatuhTempo += $creditOnlySource;
        }
    }

    /* Reklasifikasi akhir khusus penyajian bucket, sesudah semua rule Rev7.
     * Tidak ikut perhitungan $akhir / timeline / payment allocator.
     */
    if (isset($bucketReclassMap[$cid])) {
        $b1 += $bucketReclassMap[$cid]['b_1_30_delta'];
        $bLebih += $bucketReclassMap[$cid]['b_lebih_delta'];
        $bBelumJatuhTempo += $bucketReclassMap[$cid]['bjt_delta'];
    }

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
            'using_migration_baseline' => $useMigrationBaseline ? 1 : 0,
            'migration_baseline_total' => isset($bucketBaselineMap[$cid])
                ? sumMigrationBucketBaseline($bucketBaselineMap[$cid])
                : 0.0,
            'baseline_payment_timeline_total' => array_sum($baselinePaymentTimeline[$cid] ?? []),
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
            'b_lebih_source_based' => (float)$bLebihSourceBased,
            'b_lebih_residual' => (float)$bLebih,
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

/* ============================================================
 * EXPORT EXCEL
 * ============================================================
 * Seluruh perhitungan di atas disinkronkan dengan Cetak Aging revisi FOCUS COLOR & ROBERT (source-aware) terbaru.
 * Bagian ini hanya mengganti renderer cetak menjadi file Excel.
 */
function excelNumber($value): string
{
    $value = (float)$value;
    $value = $value >= 0 ? floor($value) : ceil($value);
    return (string)(int)$value;
}

$filename = 'Aging_Piutang_Detail_' . sprintf('%02d', $bulan) . '_' . $tahun . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
echo "\\xEF\\xBB\\xBF";
?>
<html><head><meta charset="UTF-8"><style>
table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:10pt}th,td{border:1px solid #000;padding:4px 6px}th{font-weight:bold;background:#eee;text-align:center}.num{text-align:right;mso-number-format:'#,##0'}.label{font-weight:bold;background:#f5f5f5}.total{font-weight:bold;background:#eee}
</style></head><body>
<table>
<tr><th colspan="11"><?= h($title) ?></th></tr>
<tr><th colspan="11"><?= h($subtitle) ?></th></tr>
<tr><th>Nama Customer</th><th>Awal</th><th>Penjualan</th><th>Bayar</th><th>Titip</th><th>Akhir</th><th>1-30 Hari</th><th>31-60 Hari</th><th>61-90 Hari</th><th>Lebih</th><th>Blm Jth. Tempo</th></tr>
<?php foreach ($grouped as $city => $cityGroup): ?>
<tr class="label"><td colspan="11"><?= h($city) ?></td></tr>
<?php foreach ($cityGroup['rows'] as $customer): $a=$customer['amounts']; ?>
<tr><td><?= h($customer['customer_id'] . ' - ' . $customer['customer_name']) ?></td><td class="num"><?= excelNumber($a['saldo_awal']) ?></td><td class="num"><?= excelNumber($a['penjualan']) ?></td><td class="num"><?= excelNumber($a['bayar']) ?></td><td class="num"><?= excelNumber($a['titip_used']) ?></td><td class="num"><?= excelNumber($a['akhir']) ?></td><td class="num"><?= excelNumber($a['b_1_30']) ?></td><td class="num"><?= excelNumber($a['b_31_60']) ?></td><td class="num"><?= excelNumber($a['b_61_90']) ?></td><td class="num"><?= excelNumber($a['b_lebih']) ?></td><td class="num"><?= excelNumber($a['belum_jatuh_tempo']) ?></td></tr>
<?php endforeach; $t=$cityGroup['totals']; ?>
<tr class="total"><td>TOTAL <?= h($city) ?></td><td class="num"><?= excelNumber($t['saldo_awal']) ?></td><td class="num"><?= excelNumber($t['penjualan']) ?></td><td class="num"><?= excelNumber($t['bayar']) ?></td><td class="num"><?= excelNumber($t['titip_used']) ?></td><td class="num"><?= excelNumber($t['akhir']) ?></td><td class="num"><?= excelNumber($t['b_1_30']) ?></td><td class="num"><?= excelNumber($t['b_31_60']) ?></td><td class="num"><?= excelNumber($t['b_61_90']) ?></td><td class="num"><?= excelNumber($t['b_lebih']) ?></td><td class="num"><?= excelNumber($t['belum_jatuh_tempo']) ?></td></tr>
<?php endforeach; ?>
<tr class="total"><td>GRAND TOTAL</td><td class="num"><?= excelNumber($grand['saldo_awal']) ?></td><td class="num"><?= excelNumber($grand['penjualan']) ?></td><td class="num"><?= excelNumber($grand['bayar']) ?></td><td class="num"><?= excelNumber($grand['titip_used']) ?></td><td class="num"><?= excelNumber($grand['akhir']) ?></td><td class="num"><?= excelNumber($grand['b_1_30']) ?></td><td class="num"><?= excelNumber($grand['b_31_60']) ?></td><td class="num"><?= excelNumber($grand['b_61_90']) ?></td><td class="num"><?= excelNumber($grand['b_lebih']) ?></td><td class="num"><?= excelNumber($grand['belum_jatuh_tempo']) ?></td></tr>
</table></body></html>
