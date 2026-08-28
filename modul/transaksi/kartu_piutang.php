<?php
/*
 * modul/transaksi/kartu_piutang.php
 *
 * RULE FINAL:
 * - Saldo Awal = posisi piutang sampai H-1 Start Date (carry-forward otomatis).
 * - customer_opening_balance hanya base migrasi.
 * - Jika opening tersedia, transaksi dengan tanggal <= opening_date dianggap sudah
 *   tercakup snapshot; transaksi baru dihitung strict > opening_date.
 * - CP-MCP tidak masuk Penjualan/Pembayaran normal; retur CP-MCP tetap standalone.
 * - Cash mengikuti head_bayar.bayar_date.
 * - Titip Used mengikuti tanggal pemakaian detail_bayar.date_created (fallback head_bayar).
 * - Titip masuk tidak mengurangi piutang.
 * - Sisa = Awal + Penjualan - Retur - Cash - Titip Used.
 */

if (session_status() === PHP_SESSION_NONE) session_start();
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
    if ($value === '') return $fallback;
    foreach (['d-M-Y','Y-m-d','d-m-Y','d/m/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $value);
        if ($dt instanceof DateTime) return $dt->format('Y-m-d');
    }
    return $fallback;
}
function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}
function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}
function formatBayarWithRetur($bayarNo, $returnId) {
    $bayarNo = trim((string)$bayarNo);
    $returnId = trim((string)$returnId);
    if ($bayarNo === '' || $returnId === '') return $bayarNo;
    $parts = explode('/', $returnId);
    $prefix = trim((string)($parts[0] ?? ''));
    return $prefix === '' ? $bayarNo : $bayarNo . '/R-' . $prefix;
}
function appIcon($name) {
    $icons = [
        'report' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h9l5 5v15H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm8 2H6v16h12V8h-4V4Zm-5 8h6v2H9v-2Zm0 4h6v2H9v-2Zm0-8h3v2H9V8Z"/></svg>',
        'search' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.5 4a6.5 6.5 0 0 1 5.18 10.43l4.45 4.44-1.42 1.42-4.44-4.45A6.5 6.5 0 1 1 10.5 4Zm0 2a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9Z"/></svg>',
        'reset' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5a7 7 0 1 1-6.33 10H7.9A5 5 0 1 0 12 7H8.83l2.58 2.59L10 11 5 6l5-5 1.41 1.41L8.83 5H12Z"/></svg>',
        'print' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10v5H7V3Zm2 2v1h6V5H9ZM6 9h12a3 3 0 0 1 3 3v5h-4v4H7v-4H3v-5a3 3 0 0 1 3-3Zm3 7v3h6v-3H9Zm8-3h2v-1a1 1 0 0 0-1-1H6a1 1 0 0 0-1 1v3h2v-1h10v1h2v-2h-2Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'customer' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-2a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM4 22a8 8 0 0 1 16 0h-2a6 6 0 0 0-12 0H4Z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$today = date('Y-m-d');
$start_date = parseReportDate($_GET['start_date'] ?? '', $today);
$end_date = parseReportDate($_GET['end_date'] ?? '', $today);
if (strtotime($start_date) > strtotime($end_date)) [$start_date, $end_date] = [$end_date, $start_date];

$start_date_display = formatDateDisplay($start_date);
$end_date_display = formatDateDisplay($end_date);
$previous_date = date('Y-m-d', strtotime($start_date . ' -1 day'));
$customer_id = trim((string)($_GET['customer_id'] ?? ''));

$customers = [];
$resCustomer = mysqli_query($conn, "SELECT customer_id, customer FROM m_customer WHERE COALESCE(is_active,'Checked')='Checked' ORDER BY customer ASC");
while ($resCustomer && ($r = mysqli_fetch_assoc($resCustomer))) $customers[] = $r;

$customerData = null;
$rows = [];
$saldo_awal = 0.0;
$total_penjualan = 0.0;
$total_retur = 0.0;
$total_pembayaran = 0.0;      // nominal yang tampil di kolom pembayaran, termasuk pembayaran link-retur
$total_titip_masuk = 0.0;
$total_titip_terpakai = 0.0;
$saldo_titip_akhir = 0.0;
$saldo_akhir = 0.0;

if ($customer_id !== '') {
    $stmt = mysqli_prepare($conn, "
        SELECT mc.customer_id, mc.customer, mc.city, mc.area_code,
               COALESCE(ma.area, mc.area_code, '') AS area_name
        FROM m_customer mc
        LEFT JOIN m_area ma ON ma.kode COLLATE utf8mb4_general_ci = mc.area_code
        WHERE mc.customer_id=? AND COALESCE(mc.is_active,'Checked')='Checked'
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 's', $customer_id);
    mysqli_stmt_execute($stmt);
    $customerData = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$customerData) $customer_id = '';
}

if ($customer_id !== '') {
    $cidEsc = mysqli_real_escape_string($conn, $customer_id);
    $startEsc = mysqli_real_escape_string($conn, $start_date);
    $endEsc = mysqli_real_escape_string($conn, $end_date);

    /* Latest active opening sampai end date. */
    $opening_id = 0;
    $opening_date = '';
    $opening_balance = 0.0;
    $stmt = mysqli_prepare($conn, "
        SELECT opening_id, opening_date, opening_balance
        FROM customer_opening_balance
        WHERE customer_id=?
          AND LOWER(COALESCE(status,'Active'))='active'
          AND opening_date<=?
        ORDER BY opening_date DESC, opening_id DESC
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, 'ss', $customer_id, $end_date);
    mysqli_stmt_execute($stmt);
    if ($op = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        $opening_id = (int)$op['opening_id'];
        $opening_date = (string)$op['opening_date'];
        $opening_balance = (float)$op['opening_balance'];
    }
    mysqli_stmt_close($stmt);

    $openingApplies = ($opening_date !== '' && $opening_date <= $start_date);
    $boundary = $openingApplies ? $opening_date : '1000-01-01';
    $boundaryEsc = mysqli_real_escape_string($conn, $boundary);
    $openingDateEsc = mysqli_real_escape_string($conn, $opening_date);
    $openingSign = ($openingApplies && $opening_balance < 0) ? -1 : 1;

    /* 1) Invoice eligible sebelum start. */
    $sql = "
        SELECT COALESCE(SUM(CASE
            WHEN COALESCE(piutang,0)>0 THEN COALESCE(piutang,0)
            WHEN COALESCE(payment_balance,0)>0 THEN COALESCE(payment_balance,0)
            ELSE COALESCE(grand_total,0)
        END),0) AS v
        FROM head_invoice
        WHERE customer_id='{$cidEsc}'
          AND invoice_date<'{$startEsc}'
          AND invoice_date>'{$boundaryEsc}'
          AND UPPER(COALESCE(invoice_no,'')) NOT LIKE '%CP-MCP%'
    ";
    $preInvoice = (float)(mysqli_fetch_assoc(mysqli_query($conn, $sql))['v'] ?? 0);

    /* 2) Cash + Titip Used eligible sebelum start.
       Target opening negatif menggunakan sign -1 agar pengurangan negatif menaikkan saldo menuju 0. */
    $openingTargetExpr = $openingApplies
        ? "CASE WHEN
                UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE')) <> 'RETURN'
                AND (
                    UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='OPENING'
                 OR COALESCE(db.opening_id,0)>0
                 OR COALESCE(db.invoice_no,'')=''
                 OR hi.invoice_no IS NULL
                 OR hi.invoice_date<='{$openingDateEsc}'
                )
           THEN 1 ELSE 0 END"
        : "0";

    /*
     * PEMBAYARAN DISPLAY vs EFFECT PIUTANG:
     * - Finance meminta pembayaran yang terhubung ke retur tetap tampil di kolom PEMBAYARAN.
     * - Namun retur sudah mengurangi piutang melalui kolom RETUR, sehingga pembayaran link-retur
     *   TIDAK boleh mengurangi SISA untuk kedua kalinya.
     */
    /*
     * Khusus payment_source=RETURN:
     * - detail_bayar pada retur standalone hanya berfungsi sebagai link/audit dan nominalnya bisa 0.
     * - nominal display pembayaran diambil dari head_retur_invoice.
     * - effect piutang dibuat NEGATIF karena running balance menghitung: saldo - payment_effect.
     *   Dengan begitu pembayaran/refund retur MENAMBAH saldo kembali menuju 0.
     */
    $returnAmountExpr = "CASE
        WHEN hri.return_id IS NULL THEN COALESCE(db.bayar_amount,0)
        WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
          OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
            THEN COALESCE(hri.grand_total,0)
        ELSE COALESCE(hri.return_amount,0)
    END";

    $cashEffectExpr = "CASE
        WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='RETURN'
             AND COALESCE(TRIM(db.return_id),'')<>''
            THEN -1 * ({$returnAmountExpr})
        WHEN LOWER(TRIM(COALESCE(hb.keterangan,'')))='retur'
             AND COALESCE(TRIM(db.return_id),'')<>''
            THEN 0
        WHEN COALESCE(db.cash_amount,0)>0 THEN COALESCE(db.cash_amount,0)
        ELSE GREATEST(COALESCE(db.bayar_amount,0)-COALESCE(db.titip_amount,0),0)
    END";

    $paymentDisplayExpr = "CASE
        WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='RETURN'
             AND COALESCE(TRIM(db.return_id),'')<>''
            THEN ({$returnAmountExpr})
        WHEN LOWER(TRIM(COALESCE(hb.keterangan,'')))='retur'
             AND COALESCE(TRIM(db.return_id),'')<>''
            THEN COALESCE(db.bayar_amount,0)
        WHEN COALESCE(db.cash_amount,0)>0 THEN COALESCE(db.cash_amount,0)
        ELSE GREATEST(COALESCE(db.bayar_amount,0)-COALESCE(db.titip_amount,0),0)
    END";
    $titipExpr = "CASE
        WHEN LOWER(TRIM(COALESCE(hb.keterangan,'')))='retur' AND COALESCE(TRIM(db.return_id),'')<>'' THEN 0
        ELSE COALESCE(db.titip_amount,0)
    END";
    $signExpr = "CASE WHEN ({$openingTargetExpr})=1 THEN {$openingSign} ELSE 1 END";

    $sql = "
        SELECT
            COALESCE(SUM(CASE
                WHEN hb.bayar_date<'{$startEsc}' AND hb.bayar_date>'{$boundaryEsc}'
                THEN ({$cashEffectExpr})*({$signExpr}) ELSE 0 END),0) AS cash_before,
            COALESCE(SUM(CASE
                WHEN DATE(COALESCE(db.date_created,hb.date_created,hb.bayar_date))<'{$startEsc}'
                 AND DATE(COALESCE(db.date_created,hb.date_created,hb.bayar_date))>'{$boundaryEsc}'
                THEN ({$titipExpr})*({$signExpr}) ELSE 0 END),0) AS titip_before
        FROM detail_bayar db
        INNER JOIN head_bayar hb ON hb.bayar_no=db.bayar_no
        LEFT JOIN head_invoice hi ON hi.invoice_no=db.invoice_no
        LEFT JOIN head_retur_invoice hri
            ON TRIM(hri.return_id)=TRIM(db.return_id)
           AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'
        WHERE hb.customer_id='{$cidEsc}'
          AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
          AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'
    ";
    $prePay = mysqli_fetch_assoc(mysqli_query($conn, $sql));
    $preCash = (float)($prePay['cash_before'] ?? 0);
    $preTitip = (float)($prePay['titip_before'] ?? 0);

    /* 3) Retur eligible sebelum start. */
    $sql = "
        SELECT COALESCE(SUM(CASE
            WHEN UPPER(COALESCE(invoice_no,'')) LIKE '%CP-MCP%'
              OR UPPER(COALESCE(shipping_no,'')) LIKE '%CP-MCP%'
            THEN COALESCE(grand_total,0)
            ELSE COALESCE(return_amount,0)
        END),0) AS v
        FROM head_retur_invoice
        WHERE customer_id='{$cidEsc}'
          AND return_date<'{$startEsc}'
          AND return_date>'{$boundaryEsc}'
          AND LOWER(COALESCE(status,'Open'))<>'cancelled'
    ";
    $preReturn = (float)(mysqli_fetch_assoc(mysqli_query($conn, $sql))['v'] ?? 0);

    $saldo_awal = ($openingApplies ? $opening_balance : 0.0)
        + $preInvoice - $preReturn - $preCash - $preTitip;

    /* =========================================================
     * ROW TRANSAKSI PERIODE
     * =========================================================
     * Payment cash dan titip used dibuat sebagai row terpisah karena tanggalnya
     * memang bisa berbeda.
     */
    $invoicePeriodBoundary = ($opening_date !== '' && $opening_date <= $end_date) ? $opening_date : '1000-01-01';
    $periodBoundaryEsc = mysqli_real_escape_string($conn, $invoicePeriodBoundary);

    $sqlRows = "
        SELECT * FROM (
            /* PENJUALAN */
            SELECT
                hi.invoice_date AS trans_date,
                COALESCE(di.shipping_no,'') AS shipping_no,
                '' AS return_id,
                '' AS bayar_no,
                'INVOICE' AS payment_source,
                0 AS opening_id,
                CASE
                    WHEN COALESCE(hi.piutang,0)>0 THEN COALESCE(hi.piutang,0)
                    WHEN COALESCE(hi.payment_balance,0)>0 THEN COALESCE(hi.payment_balance,0)
                    ELSE COALESCE(hi.grand_total,0)
                END AS penjualan,
                0 AS retur,
                0 AS pembayaran,
                0 AS payment_effect_piutang,
                0 AS titip,
                0 AS titip_effect_piutang,
                'INVOICE' AS row_type,
                10 AS sort_order,
                hi.invoice_no AS ref_sort
            FROM head_invoice hi
            LEFT JOIN (
                SELECT invoice_no, GROUP_CONCAT(DISTINCT shipping_no ORDER BY shipping_no SEPARATOR ', ') AS shipping_no
                FROM det_invoice GROUP BY invoice_no
            ) di ON di.invoice_no=hi.invoice_no
            WHERE hi.customer_id='{$cidEsc}'
              AND hi.invoice_date BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND hi.invoice_date>'{$periodBoundaryEsc}'
              AND UPPER(COALESCE(hi.invoice_no,'')) NOT LIKE '%CP-MCP%'

            UNION ALL

            /* RETUR */
            SELECT
                hri.return_date,
                COALESCE(hri.shipping_no,''),
                hri.return_id,
                '',
                'RETURN',
                0,
                0,
                CASE
                    WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
                      OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                    THEN COALESCE(hri.grand_total,0)
                    ELSE COALESCE(hri.return_amount,0)
                END,
                0,0,0,0,
                'RETURN',20,COALESCE(hri.invoice_no,'')
            FROM head_retur_invoice hri
            WHERE hri.customer_id='{$cidEsc}'
              AND hri.return_date BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND hri.return_date>'{$periodBoundaryEsc}'
              AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'

            UNION ALL

            /* PEMBAYARAN / REFUND RETUR STANDALONE
             * detail_bayar payment_source=RETURN menyimpan link return_id,
             * sedangkan nominal detail_bayar bisa 0. Nominal diambil langsung
             * dari head_retur_invoice agar transaksi tetap tampil.
             */
            SELECT
                hb.bayar_date,
                '',
                COALESCE(db.return_id,''),
                hb.bayar_no,
                'RETURN',
                COALESCE(db.opening_id,0),
                0,0,
                CASE
                    WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
                      OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                    THEN COALESCE(hri.grand_total,0)
                    ELSE COALESCE(hri.return_amount,0)
                END AS pembayaran,
                -1 * (CASE
                    WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
                      OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                    THEN COALESCE(hri.grand_total,0)
                    ELSE COALESCE(hri.return_amount,0)
                END) AS payment_effect_piutang,
                0,0,
                'PAYMENT_RETURN',25,COALESCE(db.return_id,'')
            FROM detail_bayar db
            INNER JOIN head_bayar hb ON hb.bayar_no=db.bayar_no
            INNER JOIN head_retur_invoice hri
                ON TRIM(hri.return_id)=TRIM(db.return_id)
               AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'
            WHERE hb.customer_id='{$cidEsc}'
              AND hb.bayar_date BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND hb.bayar_date>'{$periodBoundaryEsc}'
              AND UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='RETURN'
              AND COALESCE(TRIM(db.return_id),'')<>''

            UNION ALL

            /* CASH / TRANSFER */
            SELECT
                hb.bayar_date,
                COALESCE(NULLIF(db.shipping_no,''),di.shipping_no,''),
                COALESCE(db.return_id,''),
                hb.bayar_no,
                COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'),
                COALESCE(db.opening_id,0),
                0,0,
                ({$paymentDisplayExpr}) * ({$signExpr}) AS pembayaran,
                ({$cashEffectExpr}) * ({$signExpr}) AS payment_effect_piutang,
                0,0,
                'PAYMENT_CASH',30,COALESCE(db.invoice_no,'')
            FROM detail_bayar db
            INNER JOIN head_bayar hb ON hb.bayar_no=db.bayar_no
            LEFT JOIN head_invoice hi ON hi.invoice_no=db.invoice_no
            LEFT JOIN head_retur_invoice hri
                ON TRIM(hri.return_id)=TRIM(db.return_id)
               AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'
            LEFT JOIN (
                SELECT invoice_no, GROUP_CONCAT(DISTINCT shipping_no ORDER BY shipping_no SEPARATOR ', ') AS shipping_no
                FROM det_invoice GROUP BY invoice_no
            ) di ON di.invoice_no=db.invoice_no
            WHERE hb.customer_id='{$cidEsc}'
              AND hb.bayar_date BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND hb.bayar_date>'{$periodBoundaryEsc}'
              AND UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))<>'RETURN'
              AND (({$paymentDisplayExpr})<>0 OR ({$cashEffectExpr})<>0)
              AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
              AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'

            UNION ALL

            /* TITIP USED: tanggal saat benar-benar dipakai */
            SELECT
                DATE(COALESCE(db.date_created,hb.date_created,hb.bayar_date)),
                COALESCE(NULLIF(db.shipping_no,''),di.shipping_no,''),
                COALESCE(db.return_id,''),
                hb.bayar_no,
                COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'),
                COALESCE(db.opening_id,0),
                0,0,0,0,
                -1 * (({$titipExpr}) * ({$signExpr})) AS titip,
                (({$titipExpr}) * ({$signExpr})) AS titip_effect_piutang,
                'TITIP_USED',40,COALESCE(db.invoice_no,'')
            FROM detail_bayar db
            INNER JOIN head_bayar hb ON hb.bayar_no=db.bayar_no
            LEFT JOIN head_invoice hi ON hi.invoice_no=db.invoice_no
            LEFT JOIN (
                SELECT invoice_no, GROUP_CONCAT(DISTINCT shipping_no ORDER BY shipping_no SEPARATOR ', ') AS shipping_no
                FROM det_invoice GROUP BY invoice_no
            ) di ON di.invoice_no=db.invoice_no
            WHERE hb.customer_id='{$cidEsc}'
              AND DATE(COALESCE(db.date_created,hb.date_created,hb.bayar_date)) BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND DATE(COALESCE(db.date_created,hb.date_created,hb.bayar_date))>'{$periodBoundaryEsc}'
              AND ({$titipExpr})<>0
              AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
              AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'

            UNION ALL

            /* TITIP MASUK: informasional, tidak mengurangi piutang */
            SELECT
                dt.titip_date,
                '', '', '', 'TITIP', 0,
                0,0,0,0,
                COALESCE(dt.amount_in,0),
                0,
                'TITIP_IN',50,COALESCE(dt.titip_no,'')
            FROM detail_titip dt
            WHERE dt.customer_id='{$cidEsc}'
              AND dt.titip_date BETWEEN '{$startEsc}' AND '{$endEsc}'
              AND COALESCE(dt.amount_in,0)>0
        ) x
        ORDER BY trans_date ASC, sort_order ASC, shipping_no ASC, ref_sort ASC, return_id ASC, bayar_no ASC
    ";

    $resRows = mysqli_query($conn, $sqlRows);
    $runningSaldo = $saldo_awal;

    /* Baris saldo awal selalu ada agar tidak terlihat kosong meski tidak ada transaksi. */
    $rows[] = [
        'trans_date' => $previous_date,
        'shipping_no' => 'Saldo Awal',
        'return_id' => '',
        'bayar_no' => '',
        'payment_source' => 'OPENING_BALANCE_ROW',
        'opening_id' => $opening_id,
        'penjualan' => 0.0,
        'retur' => 0.0,
        'pembayaran' => 0.0,
        'payment_effect_piutang' => 0.0,
        'titip' => 0.0,
        'titip_effect_piutang' => 0.0,
        'row_type' => 'OPENING_ROW',
        'sisa' => $runningSaldo,
    ];

    while ($row = mysqli_fetch_assoc($resRows)) {
        $penjualan = (float)($row['penjualan'] ?? 0);
        $retur = (float)($row['retur'] ?? 0);
        $pembayaran = (float)($row['pembayaran'] ?? 0);
        $paymentEffect = (float)($row['payment_effect_piutang'] ?? $pembayaran);
        $titip = (float)($row['titip'] ?? 0);
        $titipEffect = (float)($row['titip_effect_piutang'] ?? 0);

        /* paymentEffect normal bernilai + dan mengurangi saldo.
         * payment_source=RETURN bernilai - sehingga pembayaran refund retur menambah saldo kembali. */
        $runningSaldo += $penjualan - $retur - $paymentEffect - $titipEffect;
        $row['sisa'] = $runningSaldo;

        $total_penjualan += $penjualan;
        $total_retur += $retur;
        $total_pembayaran += $pembayaran;
        if (($row['row_type'] ?? '') === 'TITIP_IN') $total_titip_masuk += max($titip, 0);
        if (($row['row_type'] ?? '') === 'TITIP_USED') $total_titip_terpakai += $titipEffect;

        $rows[] = $row;
    }

    $saldo_akhir = $runningSaldo;

    /* Saldo titip aktual kumulatif sampai end date. */
    $sql = "
        SELECT COALESCE(SUM(COALESCE(amount_in,0)-COALESCE(amount_out,0)),0) AS saldo
        FROM detail_titip
        WHERE customer_id='{$cidEsc}' AND titip_date<='{$endEsc}'
    ";
    $saldo_titip_akhir = (float)(mysqli_fetch_assoc(mysqli_query($conn, $sql))['saldo'] ?? 0);
}
?>


<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>


<style>
.kartu-piutang-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.kartu-piutang-wrap {
    background: #f0f2f5;
    padding: 12px;
    color: #212529;
    font-size: 11px;
}
.app-icon {
    width: 14px;
    height: 14px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    vertical-align: -2px;
}
.app-icon svg {
    width: 14px;
    height: 14px;
    display: block;
    fill: currentColor;
}
.title-icon svg {
    width: 18px;
    height: 18px;
}
.crystal-header {
    background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
    color: #fff;
    padding: 10px 15px;
    border-radius: 5px;
}
.filter-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin-bottom: 10px;
}
.filter-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr auto;
    gap: 8px;
    align-items: end;
}
.ff label {
    display: block;
    font-size: 10px;
    font-weight: 700;
    color: #0d6efd;
    margin-bottom: 3px;
    text-transform: uppercase;
}
.ff input,
.ff select {
    width: 100%;
    border: 1px solid #ced4da;
    border-radius: 3px;
    padding: 6px 8px;
    font-size: 11px;
    background: #fff;
}
.btn-vs {
    padding: 6px 12px;
    font-size: 11px;
    font-weight: bold;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 5px;
    line-height: 1;
    min-height: 30px;
}
.btn-vs:hover {
    filter: brightness(.95);
    text-decoration: none;
}
.btn-primary { background: #0d6efd; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.btn-success { background: #198754; color: #fff; }
.btn-dark { background: #212529; color: #fff; }
.btn-disabled {
    background: #adb5bd;
    color: #fff;
    cursor: not-allowed;
    pointer-events: none;
}
.summary-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(135px, 1fr));
    gap: 8px;
    margin-bottom: 10px;
}
.summary-card {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
}
.summary-card .label {
    color: #6c757d;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    margin-bottom: 5px;
}
.summary-card .value {
    font-size: 13px;
    font-weight: 800;
    color: #1e3c72;
}
.table-wrap {
    max-height: 560px;
    overflow: auto;
    border: 1px solid #c0cddb;
    background: #fff;
}
.kartu-table {
    width: 100%;
    min-width: 1250px;
    border-collapse: collapse;
    font-size: 9.5px;
}
.kartu-table th {
    position: sticky;
    top: 0;
    background: #e9ecef;
    color: #2b4c7e;
    border: 1px solid #c0cddb;
    padding: 5px 4px;
    text-align: center;
    white-space: nowrap;
    z-index: 2;
}
.money-cell {
    text-align: right;
    font-family: Arial, Helvetica, sans-serif;
    font-variant-numeric: tabular-nums;
}
.kartu-table td {
    border: 1px solid #d3d3d3;
    padding: 4px 4px;
    vertical-align: middle;
    white-space: nowrap;
}
.kartu-table tbody tr:hover td {
    background: #e8f2fe;
}
.kartu-table tfoot td {
    background: #f8f9fa;
    font-weight: bold;
}
.text-right { text-align: right; }
.text-center { text-align: center; }
.text-bold { font-weight: bold; }
.text-blue { color: #0d6efd; }
.text-retur { color:#b02a37; font-weight:bold; }
.kartu-table tbody tr.return-row td { background:#fff5f5; }
.kartu-table tbody tr.return-row:hover td { background:#ffe8e8; }
.kartu-table tbody tr.payment-return-link td { background:#fffbea; }
.kartu-table tbody tr.payment-return-link:hover td { background:#fff3bf; }
.kartu-table tbody tr.opening-row td { background:#eef6ff; font-weight:700; }
.kartu-table tbody tr.opening-row:hover td { background:#e3f0ff; }
.summary-card.retur-card { border-color:#f1aeb5; background:#fff5f5; }
.summary-card.retur-card .value { color:#b02a37; }
.summary-card.titip-card { border-color:#9ec5fe; background:#f3f8ff; }
.summary-card.titip-card .value { color:#0a58ca; }
.text-titip { color:#0a58ca; font-weight:bold; }
.customer-info {
    background: #fff;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 10px;
    margin-bottom: 10px;
    line-height: 1.7;
}
@media (max-width: 900px) {
    .filter-grid {
        grid-template-columns: 1fr 1fr;
    }
    .summary-grid {
        grid-template-columns: 1fr 1fr;
    }
}
.select2-container {
    width: 100% !important;
    font-size: 11px;
}

.select2-container--default .select2-selection--single {
    height: 30px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    display: flex;
    align-items: center;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 28px;
    color: #212529;
    padding-left: 8px;
    font-size: 11px;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 28px;
}

.select2-dropdown {
    font-size: 11px;
}

.select2-search--dropdown .select2-search__field {
    font-size: 11px;
    padding: 5px;
}
</style>

<div class="kartu-piutang-wrap">
    <?php if (isset($_SESSION['alert'])): ?>
        <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
    <?php endif; ?>

    <div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('report') ?></span>
            Kartu Piutang
        </h5>

        <?php if ($customer_id !== ''): ?>
            <a class="btn-vs btn-success"
               href="modul/transaksi/cetak_kartu_piutang.php?customer_id=<?= urlencode($customer_id) ?>&start_date=<?= urlencode($start_date_display) ?>&end_date=<?= urlencode($end_date_display) ?>"
               target="_blank">
                <span class="app-icon"><?= appIcon('print') ?></span>
                Cetak Kartu Piutang
            </a>
        <?php else: ?>
            <a class="btn-vs btn-disabled" href="javascript:void(0);">
                <span class="app-icon"><?= appIcon('print') ?></span>
                Cetak Kartu Piutang
            </a>
        <?php endif; ?>
    </div>

    <div class="filter-card">
        <form method="GET" action="index.php">
            <input type="hidden" name="page" value="kartu_piutang">

            <div class="filter-grid">
                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('customer') ?></span> Customer</label>
                   <select name="customer_id" id="customer_id" class="select2-customer" required>
                        <option value="">-- Ketik / Pilih Customer --</option>
                        <?php foreach ($customers as $cust): ?>
                            <option value="<?= h($cust['customer_id']) ?>" <?= $customer_id === $cust['customer_id'] ? 'selected' : '' ?>>
                                <?= h($cust['customer_id'] . ' - ' . $cust['customer']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Start Date</label>
                    <input type="text" name="start_date" class="js-date-picker" value="<?= h($start_date_display) ?>" autocomplete="off">
                </div>

                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> End Date</label>
                    <input type="text" name="end_date" class="js-date-picker" value="<?= h($end_date_display) ?>" autocomplete="off">
                </div>

                <div style="display:flex;gap:6px;">
                    <button type="submit" class="btn-vs btn-dark">
                        <span class="app-icon"><?= appIcon('search') ?></span>
                        Cari
                    </button>
                    <a href="index.php?page=kartu_piutang" class="btn-vs btn-secondary">
                        <span class="app-icon"><?= appIcon('reset') ?></span>
                        Reset
                    </a>
                </div>
            </div>
        </form>
    </div>

    <?php if ($customer_id === ''): ?>
        <div class="customer-info text-center" style="color:#777;">
            Silakan pilih customer dan periode terlebih dahulu.
        </div>
    <?php else: ?>
        <div class="customer-info">
            <div><b>Periode:</b> <?= h($start_date_display) ?> s/d <?= h($end_date_display) ?></div>
            <div><b>Area:</b> <?= h($customerData['area_name'] ?? '') ?></div>
            <div><b>Customer ID:</b> <?= h($customerData['customer_id'] ?? '') ?></div>
            <div><b>Nama Customer:</b> <?= h($customerData['customer'] ?? '') ?></div>
        </div>

        <div class="summary-grid">
            <div class="summary-card">
                <div class="label">Saldo Awal</div>
                <div class="value">Rp <?= h(formatMoney($saldo_awal)) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Penjualan</div>
                <div class="value">Rp <?= h(formatMoney($total_penjualan)) ?></div>
            </div>
            <div class="summary-card retur-card">
                <div class="label">Total Retur</div>
                <div class="value">- Rp <?= h(formatMoney($total_retur)) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total Pembayaran</div>
                <div class="value">Rp <?= h(formatMoney($total_pembayaran)) ?></div>
            </div>
           <div class="summary-card titip-card">
                <div class="label">Mutasi Titip</div>
                <div class="value">
                    Masuk Periode Rp <?= h(formatMoney($total_titip_masuk)) ?>
                    | Terpakai Periode Rp <?= h(formatMoney($total_titip_terpakai)) ?>
                    | Saldo Akhir Rp <?= h(formatMoney($saldo_titip_akhir)) ?>
                </div>
            </div>
            <div class="summary-card">
                <div class="label">Saldo Akhir</div>
                <div class="value">Rp <?= h(formatMoney($saldo_akhir)) ?></div>
            </div>
        </div>

        <div class="table-wrap">
            <table class="kartu-table">
                <thead>
                    <tr>
                      <th>SALDO AWAL</th>
                    <th>TANGGAL</th>
                    <th>SHIPPING NO.</th>
                    <th>NO. RETUR</th>
                    <th>NO. BAYAR</th>
                    <th>PENJUALAN</th>
                    <th>RETUR</th>
                    <th>PEMBAYARAN</th>
                    <th>TITIP</th>
                    <th>SISA</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr>
                            <td colspan="10" class="text-center" style="color:#777;padding:15px;">
                                Tidak ada data piutang pada periode ini.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($rows as $i => $row): ?>
                            <?php
                            $rowType = strtoupper(trim((string)($row['row_type'] ?? '')));
                            $isOpeningRow = $rowType === 'OPENING_ROW';
                            $isReturn = (float)($row['retur'] ?? 0) > 0;
                            $isTitip = abs((float)($row['titip'] ?? 0)) > 0.0001;

                            $paymentSource = strtoupper(
                                trim((string)($row['payment_source'] ?? ''))
                            );

                            if ($isOpeningRow) {
                                $shippingDisplay = 'Saldo Awal';
                            } elseif ($isReturn) {
                                $shippingDisplay = 'Retur';
                            } elseif ($paymentSource === 'RETURN') {
                                $shippingDisplay = 'Bayar Retur';
                            } elseif ($paymentSource === 'OPENING') {
                                $shippingDisplay = 'Saldo Awal';
                            } elseif ($isTitip) {
                                /*
                                 * Titip masuk biasanya tidak memiliki shipping_no -> tampil "Titip".
                                 * Titip yang dipakai pada pembayaran memiliki shipping_no ->
                                 * tampil contoh: "Titip-00123".
                                 */
                                $titipShippingNo = trim(
                                    (string)($row['shipping_no'] ?? '')
                                );

                                $shippingDisplay = $titipShippingNo !== ''
                                    ? 'Titip-' . $titipShippingNo
                                    : 'Titip';
                            } else {
                                $shippingDisplay = (string)($row['shipping_no'] ?? '');
                            }
                            ?>
                            <?php
                            $isPaymentReturnLink =
                                !$isOpeningRow
                                && !$isReturn
                                && trim((string)($row['bayar_no'] ?? '')) !== ''
                                && trim((string)($row['return_id'] ?? '')) !== '';

                            $bayarDisplay = formatBayarWithRetur(
                                $row['bayar_no'] ?? '',
                                $row['return_id'] ?? ''
                            );
                            ?>
                            <tr class="<?= $isOpeningRow ? 'opening-row' : ($isReturn ? 'return-row' : ($isPaymentReturnLink ? 'payment-return-link' : '')) ?>">
                                <td class="money-cell">
                                    <?= $isOpeningRow ? 'Rp ' . h(formatMoney($saldo_awal)) : '' ?>
                                </td>
                                <td class="text-center"><?= h(formatDateDisplay($row['trans_date'])) ?></td>
                                <td class="text-bold text-blue"><?= h($shippingDisplay) ?></td>
                                <td class="text-center text-retur"><?= h($row['return_id']) ?></td>
                                <td class="text-center"><?= h($bayarDisplay) ?></td>
                                <td class="money-cell">
                                    <?= ((float)$row['penjualan'] > 0) ? 'Rp ' . h(formatMoney($row['penjualan'])) : '' ?>
                                </td>
                                <td class="money-cell text-retur">
                                    <?= ((float)$row['retur'] > 0) ? '- Rp ' . h(formatMoney($row['retur'])) : '' ?>
                                </td>
                                <td class="money-cell">
                                    <?php
                                        $paymentValue = (float)($row['pembayaran'] ?? 0);
                                        if (abs($paymentValue) > 0.0001) {
                                            echo ($paymentValue < 0 ? '- Rp ' : 'Rp ') .
                                                h(formatMoney(abs($paymentValue)));
                                        }
                                    ?>
                                </td>
                                <td class="money-cell text-titip">
                                    <?php
                                    $titipMutasi = (float)($row['titip'] ?? 0);
                                    echo abs($titipMutasi) > 0.0001
                                        ? (($titipMutasi < 0 ? '- ' : '') . 'Rp ' . h(formatMoney(abs($titipMutasi))))
                                        : '';
                                    ?>
                                </td>
                                <td class="money-cell text-bold">Rp <?= h(formatMoney($row['sisa'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="5" class="text-right">TOTAL</td>
                        <td class="money-cell">Rp <?= h(formatMoney($total_penjualan)) ?></td>
                        <td class="money-cell text-retur">- Rp <?= h(formatMoney($total_retur)) ?></td>
                        <td class="money-cell"><?= $total_pembayaran < 0 ? '- Rp ' : 'Rp ' ?><?= h(formatMoney(abs($total_pembayaran))) ?></td>
                        <td class="money-cell text-titip">
                            <?php
                            $totalMutasiTitip =
                                $total_titip_masuk
                                - $total_titip_terpakai;
                            ?>
                            <?= $totalMutasiTitip < 0 ? '- ' : '' ?>Rp <?= h(formatMoney(abs($totalMutasiTitip))) ?>
                        </td>
                        <td class="money-cell">Rp <?= h(formatMoney($saldo_akhir)) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>


<script>
if (typeof flatpickr !== 'undefined') {
    flatpickr('.js-date-picker', {
        dateFormat: 'd-M-Y',
        allowInput: true,
        disableMobile: true
    });
}

$(document).ready(function () {
    $('.select2-customer').select2({
        placeholder: '-- Ketik / Pilih Customer --',
        allowClear: true,
        width: '100%'
    });
});
</script>