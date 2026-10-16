<?php
/*
 * modul/transaksi/kartu_piutang.php
 *
 * RULE FINAL:
 * - CUT-OFF GLOBAL 31-Jul-2026: customer_opening_balance adalah SNAPSHOT saldo awal resmi.
 * - Transaksi <=31-Jul-2026 tetap tampil sebagai histori, tetapi tidak dihitung ulang ke saldo setelah cutoff.
 * - Invoice/pembayaran bulan sebelumnya yang sudah lunas otomatis net 0.
 * - Opening balance cutoff tidak dijumlahkan lagi dengan invoice/pembayaran Juli.
 * - SEMUA detail pembayaran dalam periode tetap ditampilkan, termasuk nominal 0.
 * - CP-MCP tidak masuk Penjualan/Pembayaran normal; retur CP-MCP tetap standalone.
 * - Cash mengikuti head_bayar.bayar_date.
 * - Titip Used mengikuti tanggal pemakaian detail_bayar.date_created (fallback head_bayar).
 * - Titip masuk tidak mengurangi piutang.
 * - Nilai Penjualan invoice dihitung ulang mengikuti cetak_invoice.php (shipping × harga SO).
 * - Retur masuk kolom PENJUALAN dengan nilai minus.
 * - Pemakaian Retur masuk kolom PEMBAYARAN dengan nilai minus.
 * - Sisa = Awal + Penjualan - Pembayaran - Titip Used.
 * - Retur historis <= opening_date boleh tampil, tetapi effect SISA = 0 karena sudah masuk snapshot.
 * - Kolom PEMBAYARAN memakai nilai GROSS bayar_amount seperti ledger lama; bagian titip dikompensasikan oleh mutasi TITIP_USED negatif.
 * - TOTAL hanya menjumlah mutasi efektif setelah snapshot; histori yang tampil sebelum snapshot tidak ikut footer.
 * - PAYMENT_RETURN mengubah SISA sesuai nilai pembayaran Retur (negatif).
 * - PAYMENT_OPENING setelah cutoff adalah mutasi efektif: tampil untuk audit, mengurangi/menormalkan SISA, dan masuk TOTAL PEMBAYARAN.
 * - Nilai Penjualan mengikuti det_invoice resmi; tidak dihitung ulang dari SO/Shipping.
 * - Koreksi Invoice memakai invoice_correction sebagai source of truth dan masuk sebagai PENJUALAN signed (minus).
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
    $value = (float)$value;
    $value = $value >= 0 ? floor($value) : ceil($value);
    return number_format($value, 2, ',', '.');
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

$cutoff_date = '2026-07-31';
$post_cutoff_date = '2026-08-01';
$tgl_cetak = date('d-M-Y');

/*
 * Cetak seluruh customer memakai mesin perhitungan yang sama persis dengan
 * cetak_kartu_piutang.php. Hanya loop customer dan layout multi-page yang baru.
 */
function buildKartuPiutangAllCustomer($conn, $customer_id, $start_date, $end_date) {
    $previous_date = date('Y-m-d', strtotime($start_date . ' -1 day'));
    $cutoff_date = '2026-07-31';
    $post_cutoff_date = '2026-08-01';
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

    /*
     * SOURCE OF TRUTH NILAI INVOICE
     * ----------------------------------------------------------
     * Nilai Penjualan pada Cetak Kartu Piutang mengikuti nilai resmi invoice
     * yang sudah disimpan oleh save_invoice.php / update_invoice.php.
     *
     * Prioritas:
     * - det_invoice.total
     * - fallback det_invoice.subtotal
     *
     * Jangan hitung ulang dari Shipping × harga SO agar hasil cetak sama persis
     * dengan halaman Kartu Piutang dan modul pembayaran.
     */
    $invoiceValueExpr = "(
        SELECT ROUND(
            COALESCE(
                SUM(
                    CASE
                        WHEN COALESCE(di_calc.total, 0) > 0
                            THEN COALESCE(di_calc.total, 0)
                        ELSE COALESCE(di_calc.subtotal, 0)
                    END
                ),
                0
            ),
            0
        )
        FROM det_invoice di_calc
        WHERE di_calc.invoice_no = hi.invoice_no
    )";


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

        $cutoffEsc = mysqli_real_escape_string($conn, $cutoff_date);
        $postCutoffEsc = mysqli_real_escape_string($conn, $post_cutoff_date);

        /*
         * RULE BARU MANAJER - CUT-OFF 31 JULI 2026
         * ----------------------------------------------------------
         * customer_opening_balance adalah SNAPSHOT resmi per cutoff, bukan
         * adjustment yang dijumlahkan dengan transaksi Juli.
         *
         * - Transaksi <= 31-Jul-2026 tetap boleh tampil sebagai histori.
         * - Transaksi <= 31-Jul-2026 tidak boleh memengaruhi saldo setelah cutoff.
         * - Saldo setelah cutoff dimulai dari opening_balance snapshot.
         * - Pembayaran >= 01-Aug-2026 tetap dihitung walaupun membayar invoice Juli.
         */
        $opening_id = 0;
        $snapshot_balance = 0.0;
        $snapshot_date = $cutoff_date;

        $sql = "
            SELECT opening_id, opening_date, opening_balance
            FROM customer_opening_balance
            WHERE customer_id='{$cidEsc}'
              AND LOWER(COALESCE(status,'Active'))='active'
              AND opening_date<='{$cutoffEsc}'
            ORDER BY opening_date DESC, opening_id DESC
            LIMIT 1
        ";
        $snapshotRes = mysqli_query($conn, $sql);
        if ($snapshotRow = mysqli_fetch_assoc($snapshotRes)) {
            $opening_id = (int)($snapshotRow['opening_id'] ?? 0);
            $snapshot_balance = (float)($snapshotRow['opening_balance'] ?? 0);
            $snapshot_date = (string)($snapshotRow['opening_date'] ?? $cutoff_date);
            if ($snapshot_date === '' || $snapshot_date > $cutoff_date) {
                $snapshot_date = $cutoff_date;
            }
        }

        /*
         * Carry-forward invoice hanya mengambil transaksi SETELAH cutoff.
         * Invoice Juli dan sebelumnya sudah tercakup di snapshot opening_balance.
         */
        $sql = "
            SELECT COALESCE(SUM({$invoiceValueExpr}),0) AS v
            FROM head_invoice hi
            WHERE hi.customer_id='{$cidEsc}'
              AND hi.invoice_date>='{$postCutoffEsc}'
              AND hi.invoice_date<'{$startEsc}'
              AND UPPER(COALESCE(hi.invoice_no,'')) NOT LIKE '%CP-MCP%'
        ";
        $preInvoice =
            (float)(mysqli_fetch_assoc(mysqli_query($conn, $sql))['v'] ?? 0);

        /*
         * SOURCE OF TRUTH KOREKSI INVOICE
         * ----------------------------------------------------------
         * invoice_correction adalah mutasi PENJUALAN signed.
         * Nilai correction_amount sudah disimpan negatif.
         * Koreksi post-cutoff sebelum periode ikut carry-forward SALDO AWAL.
         */
        $sql = "
            SELECT COALESCE(SUM(COALESCE(correction_amount,0)),0) AS v
            FROM invoice_correction
            WHERE customer_id='{$cidEsc}'
              AND correction_date>='{$postCutoffEsc}'
              AND correction_date<'{$startEsc}'
              AND LOWER(COALESCE(status,'Active'))='active'
        ";
        $preInvoiceCorrection =
            (float)(mysqli_fetch_assoc(mysqli_query($conn, $sql))['v'] ?? 0);

        /*
         * PAYMENT TARGET OPENING bersifat eksplisit.
         * opening_id/payment_source hanya menentukan target pembayaran, BUKAN
         * membuat customer_opening_balance menjadi boundary histori.
         */
        $openingTargetExpr = "CASE WHEN
                UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='OPENING'
                OR COALESCE(db.opening_id,0)>0
            THEN 1 ELSE 0 END";

        /*
         * Nilai retur master.
         * CP-MCP memakai grand_total, retur normal memakai return_amount.
         */
        $returnAmountExpr = "CASE
            WHEN hri.return_id IS NULL THEN ABS(COALESCE(db.bayar_amount,0))
            WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
              OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                THEN COALESCE(hri.grand_total,0)
            ELSE COALESCE(hri.return_amount,0)
        END";

        /*
         * LEGACY / NET ZERO ADD BAYAR
         * ----------------------------------------------------------
         * Untuk transaksi total_bayar=0 karena Invoice dan Retur saling offset,
         * nilai detail Invoice positif dan Retur negatif tetap harus terlihat.
         * Berlaku juga untuk data legacy saat return_id menempel pada detail INVOICE.
         */
        $samePaymentReturnAmountExpr = "(
            SELECT COALESCE(
                SUM(
                    CASE
                        WHEN
                            UPPER(COALESCE(NULLIF(TRIM(dr.payment_source),''),'INVOICE'))='RETURN'
                            OR COALESCE(TRIM(dr.return_id),'')<>''
                        THEN
                            CASE
                                WHEN UPPER(COALESCE(NULLIF(TRIM(dr.payment_source),''),'INVOICE'))='RETURN'
                                 AND ABS(COALESCE(dr.bayar_amount,0)) > 0.0001
                                    THEN ABS(COALESCE(dr.bayar_amount,0))
                                ELSE ABS(
                                    CASE
                                        WHEN UPPER(COALESCE(hrr.invoice_no,'')) LIKE '%CP-MCP%'
                                          OR UPPER(COALESCE(hrr.shipping_no,'')) LIKE '%CP-MCP%'
                                            THEN COALESCE(hrr.grand_total,0)
                                        ELSE COALESCE(hrr.return_amount,0)
                                    END
                                )
                            END
                        ELSE 0
                    END
                ),
                0
            )
            FROM detail_bayar dr
            LEFT JOIN head_retur_invoice hrr
                ON TRIM(hrr.return_id)=TRIM(dr.return_id)
               AND LOWER(COALESCE(hrr.status,'Open'))<>'cancelled'
            WHERE dr.bayar_no=db.bayar_no
        )";

        $previousInvoiceSisaExpr = "COALESCE(
            (
                SELECT dp.sisa_after
                FROM detail_bayar dp
                WHERE dp.invoice_no=db.invoice_no
                  AND TRIM(COALESCE(dp.shipping_no,''))=TRIM(COALESCE(db.shipping_no,''))
                  AND UPPER(COALESCE(NULLIF(TRIM(dp.payment_source),''),'INVOICE'))='INVOICE'
                  AND dp.id < db.id
                ORDER BY dp.id DESC
                LIMIT 1
            ),
            COALESCE(db.invoice_amount,0)
        )";

        $samePaymentInvoiceCountExpr = "(
            SELECT COUNT(*)
            FROM detail_bayar dpi
            WHERE dpi.bayar_no=db.bayar_no
              AND UPPER(COALESCE(NULLIF(TRIM(dpi.payment_source),''),'INVOICE'))='INVOICE'
              AND COALESCE(TRIM(dpi.invoice_no),'')<>''
        )";

        $legacyZeroNetInvoiceExpr = "CASE
            WHEN
                UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='INVOICE'
                AND COALESCE(TRIM(db.invoice_no),'')<>''
                AND ABS(COALESCE(db.bayar_amount,0)) <= 0.0001
                AND ABS(COALESCE(db.cash_amount,0)) <= 0.0001
                AND ABS(COALESCE(db.titip_amount,0)) <= 0.0001
                AND ABS(COALESCE(hb.total_bayar,0)) <= 0.0001
                AND ({$samePaymentReturnAmountExpr}) > 0.0001
            THEN
                CASE
                    WHEN ({$previousInvoiceSisaExpr}) - COALESCE(db.sisa_after,0) > 0.0001
                    THEN LEAST(
                        ({$previousInvoiceSisaExpr}) - COALESCE(db.sisa_after,0),
                        ({$samePaymentReturnAmountExpr})
                    )
                    WHEN ({$samePaymentInvoiceCountExpr}) = 1
                    THEN LEAST(
                        CASE
                            WHEN COALESCE(db.invoice_amount,0) > 0
                                THEN COALESCE(db.invoice_amount,0)
                            ELSE ({$samePaymentReturnAmountExpr})
                        END,
                        ({$samePaymentReturnAmountExpr})
                    )
                    ELSE 0
                END
            ELSE 0
        END";

        /*
         * PAYMENT SIGNED SOURCE OF TRUTH
         * ----------------------------------------------------------
         * INVOICE normal  : positif
         * RETURN          : negatif
         * OPENING (+)     : positif
         * OPENING (-)     : negatif
         *
         * Data legacy RETURN dengan bayar_amount=0 memakai nominal master retur.
         * Data legacy OPENING yang bayar_amount-nya positif tetap diberi tanda
         * berdasarkan opening_balance target.
         */
        $paymentGrossExpr = "CASE
            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='RETURN'
                THEN CASE
                    WHEN ABS(COALESCE(db.bayar_amount,0)) > 0.0001
                        THEN -1 * ABS(COALESCE(db.bayar_amount,0))
                    ELSE -1 * ABS({$returnAmountExpr})
                END

            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='OPENING'
              OR COALESCE(db.opening_id,0)>0
                THEN CASE
                    WHEN COALESCE(cob_target.opening_balance,0) < 0
                        THEN -1 * ABS(
                            CASE
                                WHEN COALESCE(db.bayar_amount,0)<>0
                                    THEN COALESCE(db.bayar_amount,0)
                                ELSE COALESCE(db.cash_amount,0)+COALESCE(db.titip_amount,0)
                            END
                        )
                    ELSE ABS(
                            CASE
                                WHEN COALESCE(db.bayar_amount,0)<>0
                                    THEN COALESCE(db.bayar_amount,0)
                                ELSE COALESCE(db.cash_amount,0)+COALESCE(db.titip_amount,0)
                            END
                        )
                END

            /*
             * Net-zero Invoice + Retur harus dicek lebih dulu.
             * Jika tidak, data legacy keterangan='retur' + return_id akan dipaksa 0.
             */
            WHEN ({$legacyZeroNetInvoiceExpr}) > 0.0001
                THEN ({$legacyZeroNetInvoiceExpr})

            WHEN LOWER(TRIM(COALESCE(hb.keterangan,'')))='retur'
                 AND COALESCE(TRIM(db.return_id),'')<>''
                THEN 0

            WHEN COALESCE(db.bayar_amount,0)<>0
                THEN COALESCE(db.bayar_amount,0)

            ELSE COALESCE(db.cash_amount,0)+COALESCE(db.titip_amount,0)
        END";

        $paymentDisplayExpr = $paymentGrossExpr;
        $paymentEffectExpr  = $paymentGrossExpr;

        /*
         * Titip used tetap mengikuti rule existing.
         * RETURN tidak memiliki efek titip.
         */
        $titipExpr = "CASE
            WHEN UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='RETURN'
                THEN 0
            WHEN LOWER(TRIM(COALESCE(hb.keterangan,'')))='retur'
                 AND COALESCE(TRIM(db.return_id),'')<>''
                THEN 0
            ELSE COALESCE(db.titip_amount,0)
        END";

        /*
         * Semua pembayaran sebelum Start Date ikut membentuk saldo carry-forward.
         * Tidak ada lagi boundary opening_date.
         */
        $sql = "
            SELECT
                COALESCE(SUM(
                    CASE
                        WHEN hb.bayar_date>='{$postCutoffEsc}'
                         AND hb.bayar_date<'{$startEsc}'
                                                 THEN ({$paymentEffectExpr})
                        ELSE 0
                    END
                ),0) AS payment_before,

                COALESCE(SUM(
                    CASE WHEN hb.bayar_date>='{$postCutoffEsc}' AND hb.bayar_date<'{$startEsc}'
                         THEN -1 * ({$titipExpr}) ELSE 0 END
                ),0) AS titip_used_before

            FROM detail_bayar db
            INNER JOIN head_bayar hb
                ON hb.bayar_no=db.bayar_no
            LEFT JOIN head_invoice hi
                ON hi.invoice_no=db.invoice_no
            LEFT JOIN head_retur_invoice hri
                ON TRIM(hri.return_id)=TRIM(db.return_id)
               AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'
            LEFT JOIN customer_opening_balance cob_target
                ON cob_target.opening_id=db.opening_id
               AND cob_target.customer_id=hb.customer_id
            WHERE hb.customer_id='{$cidEsc}'
              AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
              AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'
        ";
        $prePay = mysqli_fetch_assoc(mysqli_query($conn, $sql));
        $prePayment =
            (float)($prePay['payment_before'] ?? 0);
        $preTitipUsed =
            (float)($prePay['titip_used_before'] ?? 0);

        /*
         * Titip masuk sebelum Start Date tetap mengikuti ledger existing:
         * amount_in mengurangi piutang, penggunaan titip dikompensasikan melalui
         * TITIP_USED negatif.
         */
        $sql = "
            SELECT COALESCE(SUM(COALESCE(amount_in,0)),0) AS titip_in_before
            FROM detail_titip
            WHERE customer_id='{$cidEsc}'
              AND titip_date>='{$postCutoffEsc}'
              AND titip_date<'{$startEsc}'
        ";
        $preTitipIn =
            (float)(mysqli_fetch_assoc(mysqli_query($conn, $sql))['titip_in_before'] ?? 0);
        $preTitipNet = $preTitipIn + $preTitipUsed;

        /* Retur sebelum Start Date adalah Penjualan negatif. */
        $sql = "
            SELECT COALESCE(SUM(CASE
                WHEN UPPER(COALESCE(invoice_no,'')) LIKE '%CP-MCP%'
                  OR UPPER(COALESCE(shipping_no,'')) LIKE '%CP-MCP%'
                THEN COALESCE(grand_total,0)
                ELSE COALESCE(return_amount,0)
            END),0) AS v
            FROM head_retur_invoice
            WHERE customer_id='{$cidEsc}'
              AND return_date>='{$postCutoffEsc}'
              AND return_date<'{$startEsc}'
              AND LOWER(COALESCE(status,'Open'))<>'cancelled'
        ";
        $preReturn =
            (float)(mysqli_fetch_assoc(mysqli_query($conn, $sql))['v'] ?? 0);

        /*
         * SALDO AWAL REPORT
         * ----------------------------------------------------------
         * Jika periode dimulai setelah cutoff:
         *   snapshot 31-Jul + seluruh mutasi efektif post-cutoff s/d H-1.
         *
         * Jika periode melintasi cutoff:
         *   Saldo Awal yang ditampilkan adalah snapshot 31-Jul dan seluruh transaksi
         *   sebelum/saat cutoff hanya histori.
         *
         * Jika periode seluruhnya sebelum cutoff:
         *   tidak ada saldo berjalan baru; transaksi hanya histori.
         */
        if ($start_date > $cutoff_date) {
            $saldo_awal =
                $snapshot_balance
                + $preInvoice
                + $preInvoiceCorrection
                - $preReturn
                - $prePayment
                - $preTitipNet;
            $opening_row_date = $previous_date;
        } elseif ($end_date >= $cutoff_date) {
            $saldo_awal = $snapshot_balance;
            $opening_row_date = $cutoff_date;
        } else {
            $saldo_awal = 0.0;
            $opening_row_date = '';
        }

        /* =========================================================
         * ROW TRANSAKSI PERIODE
         * =========================================================
         * Semua histori transaksi pada periode tetap ditampilkan.
         * Nilai 0 tetap boleh tampil selama record detail_bayar memang ada.
         * Manual opening dalam periode ditampilkan sebagai adjustment tersendiri
         * dan mengubah running saldo, bukan menggantikan histori.
         */
        $sqlRows = "
            SELECT * FROM (
                /* SALDO AWAL MANUAL / ADJUSTMENT DALAM PERIODE */
                SELECT
                    cob.opening_date AS trans_date,
                    'Saldo Awal Manual' AS shipping_no,
                    '' AS return_id,
                    '' AS bayar_no,
                    'OPENING_ADJUSTMENT' AS payment_source,
                    cob.opening_id AS opening_id,
                    0 AS penjualan,
                    COALESCE(cob.opening_balance,0) AS retur,
                    0 AS retur_effect_piutang,
                    0 AS pembayaran,
                    0 AS payment_effect_piutang,
                    0 AS titip,
                    0 AS titip_effect_piutang,
                    'OPENING_ADJUSTMENT' AS row_type,
                    5 AS sort_order,
                    CONCAT('OPENING-',cob.opening_id) AS ref_sort
                FROM customer_opening_balance cob
                WHERE cob.customer_id='{$cidEsc}'
                  AND cob.opening_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND LOWER(COALESCE(cob.status,'Active'))='active'
                  AND 1=0 /* opening balance adalah snapshot cutoff, bukan adjustment periode */

                UNION ALL

                /* PENJUALAN */
                SELECT
                    hi.invoice_date AS trans_date,
                    COALESCE(di.shipping_no,'') AS shipping_no,
                    '' AS return_id,
                    '' AS bayar_no,
                    'INVOICE' AS payment_source,
                    0 AS opening_id,
                    {$invoiceValueExpr} AS penjualan,
                    0 AS retur,
                    0 AS retur_effect_piutang,
                    0 AS pembayaran,
                    0 AS payment_effect_piutang,
                    0 AS titip,
                    0 AS titip_effect_piutang,
                    'INVOICE' AS row_type,
                    10 AS sort_order,
                    hi.invoice_no AS ref_sort
                FROM head_invoice hi
                LEFT JOIN (
                    SELECT invoice_no,
                           GROUP_CONCAT(DISTINCT shipping_no ORDER BY shipping_no SEPARATOR ', ') AS shipping_no
                    FROM det_invoice
                    GROUP BY invoice_no
                ) di ON di.invoice_no=hi.invoice_no
                WHERE hi.customer_id='{$cidEsc}'
                  AND hi.invoice_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND UPPER(COALESCE(hi.invoice_no,'')) NOT LIKE '%CP-MCP%'

                UNION ALL

                /*
                 * KOREKSI INVOICE
                 * ----------------------------------------------------------
                 * Source of truth: invoice_correction.
                 * correction_amount masuk sebagai PENJUALAN signed (minus).
                 */
                SELECT
                    ic.correction_date AS trans_date,
                    COALESCE(ic.correction_no,'') AS shipping_no,
                    '' AS return_id,
                    '' AS bayar_no,
                    'INVOICE_CORRECTION' AS payment_source,
                    0 AS opening_id,
                    COALESCE(ic.correction_amount,0) AS penjualan,
                    0 AS retur,
                    0 AS retur_effect_piutang,
                    0 AS pembayaran,
                    0 AS payment_effect_piutang,
                    0 AS titip,
                    0 AS titip_effect_piutang,
                    'INVOICE_CORRECTION' AS row_type,
                    15 AS sort_order,
                    COALESCE(ic.correction_no,'') AS ref_sort
                FROM invoice_correction ic
                WHERE ic.customer_id='{$cidEsc}'
                  AND ic.correction_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND LOWER(COALESCE(ic.status,'Active'))='active'

                UNION ALL

                /* RETUR = PENJUALAN MINUS */
                SELECT
                    hri.return_date,
                    COALESCE(hri.shipping_no,''),
                    hri.return_id,
                    '',
                    'RETURN',
                    0,
                    -1 * (CASE
                        WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
                          OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                        THEN COALESCE(hri.grand_total,0)
                        ELSE COALESCE(hri.return_amount,0)
                    END) AS penjualan,
                    0 AS retur,
                    0 AS retur_effect_piutang,
                    0 AS pembayaran,
                    0 AS payment_effect_piutang,
                    0 AS titip,
                    0 AS titip_effect_piutang,
                    'RETURN',20,COALESCE(hri.invoice_no,'')
                FROM head_retur_invoice hri
                WHERE hri.customer_id='{$cidEsc}'
                  AND hri.return_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'

                UNION ALL

                /* PEMBAYARAN RETUR = PEMBAYARAN MINUS
                 * Untuk data baru, bayar_amount menyimpan nominal Retur yang benar-benar
                 * dipakai (negatif), sehingga mendukung multi-retur dan parsial.
                 * Data legacy dengan bayar_amount=0 memakai nilai penuh Retur sebagai fallback.
                 */
                SELECT
                    hb.bayar_date,
                    '',
                    COALESCE(db.return_id,''),
                    hb.bayar_no,
                    'RETURN',
                    COALESCE(db.opening_id,0),
                    0 AS penjualan,
                    0 AS retur,
                    0 AS retur_effect_piutang,
                    CASE
                        WHEN ABS(COALESCE(db.bayar_amount,0)) > 0.0001
                            THEN -1 * ABS(COALESCE(db.bayar_amount,0))
                        ELSE -1 * (CASE
                            WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
                              OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                            THEN COALESCE(hri.grand_total,0)
                            ELSE COALESCE(hri.return_amount,0)
                        END)
                    END AS pembayaran,
                    CASE
                        WHEN ABS(COALESCE(db.bayar_amount,0)) > 0.0001
                            THEN -1 * ABS(COALESCE(db.bayar_amount,0))
                        ELSE -1 * (CASE
                            WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
                              OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                            THEN COALESCE(hri.grand_total,0)
                            ELSE COALESCE(hri.return_amount,0)
                        END)
                    END AS payment_effect_piutang,
                    0 AS titip,
                    0 AS titip_effect_piutang,
                    'PAYMENT_RETURN',25,COALESCE(hb.bayar_no,'')
                FROM detail_bayar db
                INNER JOIN head_bayar hb ON hb.bayar_no=db.bayar_no
                INNER JOIN head_retur_invoice hri
                    ON TRIM(hri.return_id)=TRIM(db.return_id)
                   AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'
                WHERE hb.customer_id='{$cidEsc}'
                  AND hb.bayar_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))='RETURN'
                  AND COALESCE(TRIM(db.return_id),'')<>''

                  /*
                   * CUT-OFF RETUR 31-Jul-2026:
                   * Retur Juli/sebelumnya sudah masuk Opening Balance dan tidak boleh
                   * muncul lagi sendirian sebagai pembayaran setelah cutoff.
                   *
                   * Pengecualian audit: bila bayar_no yang sama memang mempunyai
                   * detail INVOICE non-CP-MCP, pertahankan RETURN negatif agar dapat
                   * tampil berpasangan dengan pembayaran INVOICE positif (net 0).
                   */
                  AND (
                        hri.return_date > '{$cutoffEsc}'
                        OR EXISTS (
                            SELECT 1
                            FROM detail_bayar db_pair
                            WHERE db_pair.bayar_no = db.bayar_no
                              AND UPPER(COALESCE(NULLIF(TRIM(db_pair.payment_source),''),'INVOICE')) = 'INVOICE'
                              AND COALESCE(TRIM(db_pair.invoice_no),'') <> ''
                              AND UPPER(COALESCE(db_pair.invoice_no,'')) NOT LIKE '%CP-MCP%'
                              AND UPPER(COALESCE(db_pair.shipping_no,'')) NOT LIKE '%CP-MCP%'
                        )
                  )

                UNION ALL

                /*
                 * PEMBAYARAN RETUR LEGACY
                 * return_id dapat menempel pada detail INVOICE/non-RETURN.
                 * Tampilkan sebagai pembayaran negatif pada bayar_no yang sama.
                 */
                SELECT
                    hb.bayar_date,
                    '',
                    COALESCE(db.return_id,''),
                    hb.bayar_no,
                    'RETURN',
                    COALESCE(db.opening_id,0),
                    0 AS penjualan,
                    0 AS retur,
                    0 AS retur_effect_piutang,
                    -1 * ABS(
                        CASE
                            WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
                              OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                            THEN COALESCE(hri.grand_total,0)
                            ELSE COALESCE(hri.return_amount,0)
                        END
                    ) AS pembayaran,
                    -1 * ABS(
                        CASE
                            WHEN UPPER(COALESCE(hri.invoice_no,'')) LIKE '%CP-MCP%'
                              OR UPPER(COALESCE(hri.shipping_no,'')) LIKE '%CP-MCP%'
                            THEN COALESCE(hri.grand_total,0)
                            ELSE COALESCE(hri.return_amount,0)
                        END
                    ) AS payment_effect_piutang,
                    0 AS titip,
                    0 AS titip_effect_piutang,
                    'PAYMENT_RETURN_LEGACY',26,COALESCE(hb.bayar_no,'')
                FROM detail_bayar db
                INNER JOIN head_bayar hb
                    ON hb.bayar_no=db.bayar_no
                INNER JOIN head_retur_invoice hri
                    ON TRIM(hri.return_id)=TRIM(db.return_id)
                   AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'
                WHERE hb.customer_id='{$cidEsc}'
                  AND hb.bayar_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))<>'RETURN'
                  AND COALESCE(TRIM(db.return_id),'')<>''

                  /*
                   * Legacy return_id yang berasal dari retur <= cutoff hanya boleh
                   * disintesis menjadi pembayaran RETURN negatif bila detail invoice
                   * pada transaksi ini memang membentuk pasangan positif net-zero.
                   * Untuk retur setelah cutoff, rule legacy existing tetap berlaku.
                   */
                  AND (
                        hri.return_date > '{$cutoffEsc}'
                        OR ({$legacyZeroNetInvoiceExpr}) > 0.0001
                  )

                  AND NOT EXISTS (
                        SELECT 1
                        FROM detail_bayar drx
                        WHERE drx.bayar_no=db.bayar_no
                          AND TRIM(COALESCE(drx.return_id,''))=TRIM(COALESCE(db.return_id,''))
                          AND UPPER(COALESCE(NULLIF(TRIM(drx.payment_source),''),'INVOICE'))='RETURN'
                  )

                UNION ALL

                /* CASH / TRANSFER */
                SELECT
                    hb.bayar_date,
                    COALESCE(NULLIF(db.shipping_no,''),di.shipping_no,''),
                    COALESCE(db.return_id,''),
                    hb.bayar_no,
                    COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'),
                    COALESCE(db.opening_id,0),
                    0 AS penjualan,
                    0 AS retur,
                    0 AS retur_effect_piutang,
                    ({$paymentDisplayExpr}) AS pembayaran,
                    ({$paymentEffectExpr}) AS payment_effect_piutang,
                    0,0,
                    'PAYMENT_CASH',30,COALESCE(hb.bayar_no,'')
                FROM detail_bayar db
                INNER JOIN head_bayar hb ON hb.bayar_no=db.bayar_no
                LEFT JOIN head_invoice hi ON hi.invoice_no=db.invoice_no
                LEFT JOIN head_retur_invoice hri
                    ON TRIM(hri.return_id)=TRIM(db.return_id)
                   AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'
                LEFT JOIN customer_opening_balance cob_target
                    ON cob_target.opening_id=db.opening_id
                   AND cob_target.customer_id=hb.customer_id
                LEFT JOIN (
                    SELECT invoice_no,
                           GROUP_CONCAT(DISTINCT shipping_no ORDER BY shipping_no SEPARATOR ', ') AS shipping_no
                    FROM det_invoice
                    GROUP BY invoice_no
                ) di ON di.invoice_no=db.invoice_no
                WHERE hb.customer_id='{$cidEsc}'
                  AND hb.bayar_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))<>'RETURN'
                  AND ({$openingTargetExpr})=0
                  AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
                  AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'

                UNION ALL

                /*
                 * PEMBAYARAN SALDO AWAL / OPENING
                 * ----------------------------------------------------------
                 * payment_source=OPENING / opening_id tetap ditampilkan untuk AUDIT
                 * pelunasan Saldo Awal manual.
                 *
                 * customer_opening_balance adalah snapshot posisi per cutoff.
                 * Pembayaran OPENING sesudah cutoff adalah mutasi baru, sehingga nominal
                 * tampil di kolom PEMBAYARAN dan payment_effect_piutang harus aktif.
                 *
                 * Jika tanggal transaksi <= cutoff, mekanisme historical_only existing
                 * tetap membuat baris hanya tampil sebagai histori.
                 */
                SELECT
                    hb.bayar_date,
                    'Saldo Awal' AS shipping_no,
                    COALESCE(db.return_id,''),
                    hb.bayar_no,
                    'OPENING',
                    COALESCE(db.opening_id,0),
                    0 AS penjualan,
                    0 AS retur,
                    0 AS retur_effect_piutang,
                    ({$paymentDisplayExpr}) AS pembayaran,
                    ({$paymentEffectExpr}) AS payment_effect_piutang,
                    0 AS titip,
                    0 AS titip_effect_piutang,
                    'PAYMENT_OPENING',35,COALESCE(hb.bayar_no,'')
                FROM detail_bayar db
                INNER JOIN head_bayar hb ON hb.bayar_no=db.bayar_no
                LEFT JOIN head_retur_invoice hri
                    ON TRIM(hri.return_id)=TRIM(db.return_id)
                   AND LOWER(COALESCE(hri.status,'Open'))<>'cancelled'
                LEFT JOIN customer_opening_balance cob_target
                    ON cob_target.opening_id=db.opening_id
                   AND cob_target.customer_id=hb.customer_id
                WHERE hb.customer_id='{$cidEsc}'
                  AND hb.bayar_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND ({$openingTargetExpr})=1
                  AND UPPER(COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'))<>'RETURN'

                UNION ALL

                /* TITIP USED: ledger mengikuti tanggal PEMBAYARAN */
                SELECT
                    hb.bayar_date,
                    COALESCE(NULLIF(db.shipping_no,''),di.shipping_no,''),
                    COALESCE(db.return_id,''),
                    hb.bayar_no,
                    COALESCE(NULLIF(TRIM(db.payment_source),''),'INVOICE'),
                    COALESCE(db.opening_id,0),
                    0 AS penjualan,
                    0 AS retur,
                    0 AS retur_effect_piutang,
                    0 AS pembayaran,
                    0 AS payment_effect_piutang,
                    -1 * ({$titipExpr}) AS titip,
                    -1 * ({$titipExpr}) AS titip_effect_piutang,
                    'TITIP_USED',40,COALESCE(hb.bayar_no,'')
                FROM detail_bayar db
                INNER JOIN head_bayar hb ON hb.bayar_no=db.bayar_no
                LEFT JOIN head_invoice hi ON hi.invoice_no=db.invoice_no
                LEFT JOIN (
                    SELECT invoice_no,
                           GROUP_CONCAT(DISTINCT shipping_no ORDER BY shipping_no SEPARATOR ', ') AS shipping_no
                    FROM det_invoice
                    GROUP BY invoice_no
                ) di ON di.invoice_no=db.invoice_no
                WHERE hb.customer_id='{$cidEsc}'
                  AND hb.bayar_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND ({$titipExpr})<>0
                  AND UPPER(COALESCE(db.invoice_no,'')) NOT LIKE '%CP-MCP%'
                  AND UPPER(COALESCE(db.shipping_no,'')) NOT LIKE '%CP-MCP%'

                UNION ALL

                /* TITIP MASUK: mutasi ledger POSITIF dan mengurangi piutang */
                SELECT
                    dt.titip_date,
                    '', '', '', 'TITIP', 0,
                    0 AS penjualan,
                    0 AS retur,
                    0 AS retur_effect_piutang,
                    0 AS pembayaran,
                    0 AS payment_effect_piutang,
                    COALESCE(dt.amount_in,0),
                    COALESCE(dt.amount_in,0),
                    'TITIP_IN',50,COALESCE(dt.titip_no,'')
                FROM detail_titip dt
                WHERE dt.customer_id='{$cidEsc}'
                  AND dt.titip_date BETWEEN '{$startEsc}' AND '{$endEsc}'
                  AND COALESCE(dt.amount_in,0)>0
            ) x
            ORDER BY trans_date ASC, sort_order ASC, shipping_no ASC, ref_sort ASC, return_id ASC, bayar_no ASC
        ";

        $resRows = mysqli_query($conn, $sqlRows);

        /*
         * Ambil semua baris transaksi lebih dulu supaya baris opening dapat disisipkan
         * sesuai urutan tanggal, bukan selalu dipaksa berada paling atas.
         */
        $periodRows = [];
        while ($row = mysqli_fetch_assoc($resRows)) {
            $rowDate = (string)($row['trans_date'] ?? '');
            $row['historical_only'] =
                ($rowDate !== '' && $rowDate <= $cutoff_date) ? 1 : 0;
            $periodRows[] = $row;
        }

        /*
         * Baris SALDO AWAL SNAPSHOT / CARRY-FORWARD.
         * - Jika periode melintasi cutoff, row ditempatkan tepat 31-Jul-2026
         *   setelah histori transaksi hari cutoff.
         * - Jika periode dimulai setelah cutoff, row ditempatkan pada H-1 Start Date
         *   dengan nilai carry-forward dari snapshot + mutasi post-cutoff.
         * - Jika seluruh periode sebelum cutoff, tidak ada row saldo awal baru.
         */
        if ($opening_row_date !== '') {
            $periodRows[] = [
                'trans_date' => $opening_row_date,
                'shipping_no' => 'Saldo Awal',
                'return_id' => '',
                'bayar_no' => '',
                'payment_source' => 'OPENING_BALANCE_ROW',
                'opening_id' => $opening_id,
                'penjualan' => 0.0,
                'retur' => 0.0,
                'retur_effect_piutang' => 0.0,
                'pembayaran' => 0.0,
                'payment_effect_piutang' => 0.0,
                'titip' => 0.0,
                'titip_effect_piutang' => 0.0,
                'row_type' => 'OPENING_ROW',
                'sort_order' => 99,
                'ref_sort' => '',
                'historical_only' => 0,
            ];
        }

        usort($periodRows, static function ($a, $b) {
            $dateA = (string)($a['trans_date'] ?? '');
            $dateB = (string)($b['trans_date'] ?? '');
            if ($dateA !== $dateB) {
                return strcmp($dateA, $dateB);
            }

            $sortA = (int)($a['sort_order'] ?? 0);
            $sortB = (int)($b['sort_order'] ?? 0);
            if ($sortA !== $sortB) {
                return $sortA <=> $sortB;
            }

            return strcmp(
                (string)($a['ref_sort'] ?? ''),
                (string)($b['ref_sort'] ?? '')
            );
        });

        $runningSaldo = $saldo_awal;

        foreach ($periodRows as $row) {
            $rowType = strtoupper(trim((string)($row['row_type'] ?? '')));
            $isOpeningRow = $rowType === 'OPENING_ROW';
            $rowDate = (string)($row['trans_date'] ?? '');

            $penjualan = (float)($row['penjualan'] ?? 0);
            $retur = (float)($row['retur'] ?? 0);
            $returEffect = (float)($row['retur_effect_piutang'] ?? $retur);
            $pembayaran = (float)($row['pembayaran'] ?? 0);
            $paymentEffect = (float)($row['payment_effect_piutang'] ?? $pembayaran);
            $titip = (float)($row['titip'] ?? 0);
            $titipEffect = (float)($row['titip_effect_piutang'] ?? 0);

            if ($isOpeningRow) {
                $runningSaldo = $saldo_awal;
                $row['sisa'] = $runningSaldo;
                $rows[] = $row;
                continue;
            }

            /*
             * Semua transaksi <= cutoff hanya histori.
             * Nilainya tetap tampil untuk audit, tetapi tidak memengaruhi running
             * saldo maupun total mutasi efektif setelah snapshot.
             */
            $isHistoricalRow =
                ($rowDate !== '' && $rowDate <= $cutoff_date);

            if ($isHistoricalRow) {
                $row['historical_only'] = 1;
                $row['sisa'] = null;
                $rows[] = $row;
                continue;
            }

            $row['historical_only'] = 0;

            /* Running efektif setelah cutoff. */
            $runningSaldo +=
                $penjualan
                - $paymentEffect
                - $titipEffect;

            $row['sisa'] = $runningSaldo;

            $total_penjualan += $penjualan;
            $total_pembayaran += $pembayaran;

            if ($rowType === 'TITIP_IN') {
                $total_titip_masuk += max($titip, 0);
            }
            if ($rowType === 'TITIP_USED') {
                $total_titip_terpakai += abs($titipEffect);
            }

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



    return [
        'customerData' => $customerData,
        'rows' => $rows,
        'saldo_awal' => $saldo_awal,
        'total_penjualan' => $total_penjualan,
        'total_pembayaran' => $total_pembayaran,
        'total_titip_masuk' => $total_titip_masuk,
        'total_titip_terpakai' => $total_titip_terpakai,
        'saldo_titip_akhir' => $saldo_titip_akhir,
        'saldo_akhir' => $saldo_akhir,
    ];
}

$allCustomers = [];
$resAll = mysqli_query($conn, "
    SELECT customer_id, customer
    FROM m_customer
    WHERE COALESCE(is_active,'Checked')='Checked'
    ORDER BY customer ASC, customer_id ASC
");
while ($resAll && ($r = mysqli_fetch_assoc($resAll))) {
    $allCustomers[] = $r;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Kartu Piutang - All Customer</title>
<style>
@page { size:A4 portrait; margin:8mm; }
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;font-size:9px;color:#000;margin:0;padding:16px;background:#eef1f5}
.toolbar{width:100%;max-width:210mm;margin:0 auto 12px;display:flex;justify-content:flex-end;gap:8px}
.btn-action{border:none;border-radius:6px;color:#fff;padding:10px 20px;font-size:13px;font-weight:bold;cursor:pointer;text-decoration:none;box-shadow:0 2px 6px rgba(0,0,0,.2)}
.btn-back{background:#6c757d}.btn-print{background:#2b5797}.btn-action:hover{filter:brightness(.92);text-decoration:none}
.customer-page{width:100%;max-width:210mm;margin:0 auto 18px;padding:18px;background:#fff;box-shadow:0 2px 12px rgba(0,0,0,.1);border-radius:4px;page-break-after:always;break-after:page}
.customer-page:last-child{page-break-after:auto;break-after:auto}
.title{text-align:center;font-size:16px;font-weight:bold;letter-spacing:.5px;margin-bottom:4px}.period{text-align:center;font-size:11px;font-weight:bold;margin-bottom:10px}
.top-info{width:100%;border-collapse:collapse;margin-bottom:8px;font-size:9px}.top-info td{padding:3px 0;vertical-align:top}.top-info .label{width:72px;font-weight:bold}.top-info .sep{width:10px;text-align:center}.top-info .right-label{width:72px;font-weight:bold}
.summary{width:100%;border-collapse:collapse;margin-bottom:8px;font-size:8px}.summary td{border:1px solid #777;padding:3px 4px}.summary .label{font-weight:bold;background:#f2f2f2}.summary .money{text-align:right;font-weight:bold;white-space:nowrap}
.detail-table{width:100%;border-collapse:collapse;font-size:7.3px;border:1px solid #000}.detail-table th{border:1px solid #000;padding:4px 2px;text-align:center;font-weight:bold;background:#f2f2f2;white-space:nowrap}.detail-table tbody td{padding:3px 2px;vertical-align:middle;border-left:1px solid #000;border-right:1px solid #000}.detail-table tbody tr.return-row td{background:#fff0f0}.detail-table tbody tr.opening-row td{background:#eef5ff;font-weight:bold}.detail-table tbody tr.historical-row td{background:#f8f9fa;color:#666}.detail-table .summary-row td{border-top:1px solid #000;border-bottom:1px solid #000;font-weight:bold;background:#f2f2f2;padding:4px 2px}
.money-cell{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.text-center{text-align:center}.text-right{text-align:right}.text-bold{font-weight:bold}.text-titip{color:#0a58ca;font-weight:bold}.no-data{text-align:center;padding:12px;color:#555}
@media print{body{padding:0;background:#fff}.no-print{display:none!important}.customer-page{max-width:100%;margin:0;padding:4px 5px;box-shadow:none;border-radius:0}thead{display:table-header-group}tr{page-break-inside:avoid;break-inside:avoid}}
</style>
</head>
<body>
<div class="toolbar no-print">
<a href="../../index.php?page=kartu_piutang" class="btn-action btn-back">KEMBALI</a>
<button type="button" class="btn-action btn-print" onclick="window.print()">CETAK ALL CUSTOMER</button>
</div>

<?php foreach ($allCustomers as $cust): ?>
<?php
$data = buildKartuPiutangAllCustomer($conn, (string)$cust['customer_id'], $start_date, $end_date);
$customerData = $data['customerData'];
if (!$customerData) continue;
$rows = $data['rows'];
$saldo_awal = $data['saldo_awal'];
$total_penjualan = $data['total_penjualan'];
$total_pembayaran = $data['total_pembayaran'];
$total_titip_masuk = $data['total_titip_masuk'];
$total_titip_terpakai = $data['total_titip_terpakai'];
$saldo_titip_akhir = $data['saldo_titip_akhir'];
$saldo_akhir = $data['saldo_akhir'];
?>
<div class="customer-page">
<div class="title">KARTU PIUTANG CP</div>
<div class="period">Periode <?= h(formatDateDisplay($start_date)) ?> s/d <?= h(formatDateDisplay($end_date)) ?></div>
<table class="top-info">
<tr><td class="label">Dicetak</td><td class="sep">:</td><td><?= h($tgl_cetak) ?></td><td class="right-label">Area</td><td class="sep">:</td><td><?= h($customerData['area_name'] ?? '') ?></td></tr>
<tr><td class="label">Customer ID</td><td class="sep">:</td><td><?= h($customerData['customer_id'] ?? '') ?></td><td class="right-label">Customer</td><td class="sep">:</td><td><?= h($customerData['customer'] ?? '') ?></td></tr>
</table>
<table class="summary">
<tr>
<td class="label">Saldo Awal</td><td class="money">Rp <?= h(formatMoney($saldo_awal)) ?></td>
<td class="label">Penjualan</td><td class="money">Rp <?= h(formatMoney($total_penjualan)) ?></td>
<td class="label">Pembayaran</td><td class="money">Rp <?= h(formatMoney($total_pembayaran)) ?></td>
</tr>
<tr>
<td class="label">Titip Terpakai</td><td class="money text-titip">Rp <?= h(formatMoney($total_titip_terpakai)) ?></td>
<td class="label">Saldo Akhir</td><td class="money">Rp <?= h(formatMoney($saldo_akhir)) ?></td><td colspan="2"></td>
</tr>
<tr>
<td class="label">Titip Masuk Periode</td><td class="money text-titip">Rp <?= h(formatMoney($total_titip_masuk)) ?></td>
<td class="label">Saldo Titip Akhir</td><td class="money text-titip">Rp <?= h(formatMoney($saldo_titip_akhir)) ?></td><td colspan="2"></td>
</tr>
</table>
<table class="detail-table">
<thead><tr><th>SALDO AWAL</th><th>TANGGAL</th><th>FAKTUR</th><th>NO. BAYAR</th><th>PENJUALAN</th><th>PEMBAYARAN</th><th>TITIP</th><th>SISA</th></tr></thead>
<tbody>
<?php if (empty($rows)): ?>
<tr><td colspan="8" class="no-data">Tidak ada data piutang pada periode ini.</td></tr>
<?php else: ?>
<?php foreach ($rows as $row): ?>
<?php
$rowType=(string)($row['row_type']??'');
$isOpening=$rowType==='OPENING_ROW';
$isManualOpening=$rowType==='OPENING_ADJUSTMENT';
$isReturn=$rowType==='RETURN';
$paymentSource=strtoupper(trim((string)($row['payment_source']??'')));
if($isOpening){$shippingDisplay='';}
elseif($isManualOpening){$shippingDisplay='Saldo Awal Manual';}
elseif($rowType==='RETURN' || $paymentSource==='RETURN'){$shippingDisplay=(string)($row['return_id']??'');}
elseif($rowType==='INVOICE_CORRECTION'){$shippingDisplay=(string)($row['shipping_no']??'');}
elseif($rowType==='TITIP_USED'){$sn=trim((string)($row['shipping_no']??''));$shippingDisplay=$sn!==''?'Titip-'.$sn:'Titip';}
elseif($rowType==='TITIP_IN'){$shippingDisplay='Titip';}
elseif($paymentSource==='OPENING'){$shippingDisplay='Saldo Awal';}
else{$shippingDisplay=(string)($row['shipping_no']??'');}
$bayarDisplay=(string)($row['bayar_no']??'');
$isHistoricalRow=!empty($row['historical_only']);
?>
<tr class="<?= $isOpening?'opening-row':($isHistoricalRow?'historical-row':($isReturn?'return-row':'')) ?>">
<td class="money-cell"><?php if($isOpening){echo 'Rp '.h(formatMoney($saldo_awal));}elseif($isManualOpening){$adjValue=(float)($row['retur']??0);echo ($adjValue<0?'- Rp ':'Rp ').h(formatMoney(abs($adjValue)));} ?></td>
<td class="text-center"><?= h(formatDateDisplay($row['trans_date']??'')) ?></td>
<td><?= h($shippingDisplay) ?></td>
<td class="text-center"><?= h($bayarDisplay) ?></td>
<td class="money-cell"><?php $sv=(float)($row['penjualan']??0);if(abs($sv)>0.0001): ?><?= $sv<0?'- ':'' ?>Rp <?= h(formatMoney(abs($sv))) ?><?php endif; ?></td>
<td class="money-cell"><?php $pv=(float)($row['pembayaran']??0);if(abs($pv)>0.0001): ?><?= $pv<0?'- ':'' ?>Rp <?= h(formatMoney(abs($pv))) ?><?php endif; ?></td>
<td class="money-cell text-titip"><?php $tv=(float)($row['titip']??0);if(abs($tv)>0.0001): ?><?= $tv<0?'- ':'' ?>Rp <?= h(formatMoney(abs($tv))) ?><?php endif; ?></td>
<td class="money-cell text-bold"><?php if(($row['sisa']??null)===null): ?>-<?php else: ?>Rp <?= h(formatMoney($row['sisa'])) ?><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
<tr class="summary-row">
<td colspan="4" class="text-right">TOTAL MUTASI PERIODE</td>
<td class="money-cell"><?= $total_penjualan<0?'- ':'' ?>Rp <?= h(formatMoney(abs($total_penjualan))) ?></td>
<td class="money-cell"><?= $total_pembayaran<0?'- ':'' ?>Rp <?= h(formatMoney(abs($total_pembayaran))) ?></td>
<td class="money-cell text-titip"><?php $net=$total_titip_masuk-$total_titip_terpakai; ?><?= $net<0?'- ':'' ?>Rp <?= h(formatMoney(abs($net))) ?></td>
<td class="money-cell">Rp <?= h(formatMoney($saldo_akhir)) ?></td>
</tr>
</tbody>
</table>
</div>
<?php endforeach; ?>
</body>
</html>
