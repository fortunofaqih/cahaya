<?php
/*
 * RULE NILAI RETUR CP-MCP:
 * - CP-MCP memakai grand_total.
 * - Retur normal memakai return_amount.
 */

// modul/transaksi/edit_bayar.php
// Edit Pembayaran Multi Invoice / Multi Shipping.
//
// Satu bayar_no dapat memiliki banyak detail_bayar.
// Customer pembayaran tidak diganti saat edit.
// User dapat menambah/mengurangi Invoice/Shipping yang dicentang.
// Checkbox = bayar penuh sebesar sisa shipping sebelum pembayaran ini.
// Retur dipilih standalone berdasarkan Customer; retur yang dipakai pembayaran lain tidak boleh dipilih.

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

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function appIcon($name) {
    $icons = [
        'payment' => '<svg viewBox="0 0 24 24"><path d="M3 5h18v14H3V5Zm2 4h14V7H5v2Zm0 3v5h14v-5H5Zm2 2h5v2H7v-2Z"/></svg>',
        'save' => '<svg viewBox="0 0 24 24"><path d="M17 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7l-4-4ZM5 5h10.2L19 8.8V19H5V5Zm2 8h10v5H7v-5Zm1-6h7v4H8V7Z"/></svg>',
        'back' => '<svg viewBox="0 0 24 24"><path d="M20 11v2H7.83l5.59 5.59L12 20 4 12l8-8 1.42 1.41L7.83 11H20Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

$bayarNo = trim((string)($_GET['bayar_no'] ?? ''));

if ($bayarNo === '') {
    echo "<script>alert('No. Bayar tidak ditemukan.');window.location.href='index.php?page=pembayaran';</script>";
    exit;
}

/*
|--------------------------------------------------------------------------
| HEAD BAYAR
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare(
    $conn,
    "SELECT *
     FROM head_bayar
     WHERE bayar_no = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 's', $bayarNo);
mysqli_stmt_execute($stmt);
$head = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$head) {
    echo "<script>alert('Data pembayaran tidak ditemukan.');window.location.href='index.php?page=pembayaran';</script>";
    exit;
}

$customerId = trim((string)$head['customer_id']);

/*
|--------------------------------------------------------------------------
| DETAIL BAYAR EXISTING
|--------------------------------------------------------------------------
*/
$currentDetails = [];
$currentDetailMap = [];
$currentCash = 0.0;
$currentTitip = 0.0;

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        id,
        invoice_no,
        shipping_no,
        opening_id,
        payment_source,
        return_id,
        invoice_date,
        invoice_amount,
        cash_amount,
        titip_amount,
        bayar_amount,
        sisa_after,
        remarks
     FROM detail_bayar
     WHERE bayar_no = ?
     ORDER BY id ASC"
);
mysqli_stmt_bind_param($stmt, 's', $bayarNo);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($res)) {
    $currentDetails[] = $row;
    $source = strtoupper(trim((string)($row['payment_source'] ?? 'INVOICE')));
    if ($source === 'OPENING' && (int)($row['opening_id'] ?? 0) > 0) {
        $key = 'OPENING|' . (int)$row['opening_id'];
    } else {
        $key = trim((string)$row['invoice_no']) . '|' . trim((string)$row['shipping_no']);
    }
    $currentDetailMap[$key] = $row;
    $currentCash += (float)($row['cash_amount'] ?? 0);
    $currentTitip += (float)($row['titip_amount'] ?? 0);
}
mysqli_stmt_close($stmt);

if (!$currentDetails) {
    die('Detail pembayaran tidak ditemukan.');
}

/*
|--------------------------------------------------------------------------
| RETUR STANDALONE EXISTING
|--------------------------------------------------------------------------
| Save terbaru menyimpan return_id hanya pada detail pertama.
| Untuk kompatibilitas data lama, ambil return_id non-empty pertama dari
| seluruh detail pembayaran.
|--------------------------------------------------------------------------
*/
$currentReturnId = '';

foreach ($currentDetails as $detail) {
    $candidateReturnId = trim((string)($detail['return_id'] ?? ''));

    if ($candidateReturnId !== '') {
        $currentReturnId = $candidateReturnId;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| DAFTAR INVOICE/SHIPPING CUSTOMER
|--------------------------------------------------------------------------
| Sisa untuk edit dihitung dengan mengabaikan pembayaran bayar_no ini.
| Jadi tagihan yang saat ini sudah lunas karena pembayaran ini tetap dapat
| ditampilkan dan tetap tercentang saat halaman edit dibuka.
|--------------------------------------------------------------------------
*/
$sqlShipping = "
    SELECT
        src.invoice_no,
        src.invoice_date,
        src.customer_id,
        src.customer_name,
        src.customer_address,
        src.customer_city,
        src.shipping_no,
        src.shipping_date,
        src.order_no,
        src.shipping_amount,

        CASE
            WHEN src.shipping_count = 1
                THEN COALESCE(inv_pay_other.total_paid_invoice, 0)
            ELSE COALESCE(pay_shipping_other.paid_amount, 0)
        END AS paid_except_current,

        CASE
            WHEN src.shipping_count = 1
                THEN COALESCE(ret_used_invoice_other.return_amount, 0)
            ELSE COALESCE(ret_used_shipping_other.return_amount, 0)
        END AS retur_except_current,

        /*
         * Sisa sebelum transaksi yang sedang diedit:
         * Nilai Shipping - pembayaran lain - retur terpakai pada pembayaran lain.
         *
         * Efek bayar_no yang sedang diedit sengaja dikeluarkan agar
         * item existing tetap bisa tampil dan tetap tercentang.
         */
        CASE
            WHEN src.shipping_count = 1
                THEN GREATEST(
                    src.shipping_amount
                    - COALESCE(inv_pay_other.total_paid_invoice, 0)
                    - COALESCE(ret_used_invoice_other.return_amount, 0),
                    0
                )
            ELSE GREATEST(
                src.shipping_amount
                - COALESCE(pay_shipping_other.paid_amount, 0)
                - COALESCE(ret_used_shipping_other.return_amount, 0),
                0
            )
        END AS sisa_before_current

    FROM
    (
        SELECT
            hi.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            TRIM(di.shipping_no) AS shipping_no,
            MAX(di.shipping_date) AS shipping_date,
            MAX(di.order_no) AS order_no,

            SUM(
                CASE
                    WHEN COALESCE(di.total, 0) > 0
                        THEN COALESCE(di.total, 0)
                    ELSE COALESCE(di.subtotal, 0)
                END
            ) AS shipping_amount,

            (
                SELECT COUNT(DISTINCT TRIM(di_count.shipping_no))
                FROM det_invoice di_count
                WHERE di_count.invoice_no = hi.invoice_no
                  AND COALESCE(TRIM(di_count.shipping_no), '') <> ''
            ) AS shipping_count

        FROM head_invoice hi
        INNER JOIN det_invoice di
            ON di.invoice_no = hi.invoice_no

        WHERE hi.customer_id = ?
          AND COALESCE(TRIM(di.shipping_no), '') <> ''
          AND UPPER(COALESCE(hi.invoice_no, '')) NOT LIKE '%CP-MCP%'
          AND UPPER(COALESCE(TRIM(di.shipping_no), '')) NOT LIKE '%CP-MCP%'

        GROUP BY
            hi.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            TRIM(di.shipping_no)
    ) src

    LEFT JOIN
    (
        SELECT
            db.invoice_no,
            TRIM(db.shipping_no) AS shipping_no,
            SUM(COALESCE(db.bayar_amount, 0)) AS paid_amount
        FROM detail_bayar db
        WHERE db.bayar_no <> ?
          AND COALESCE(TRIM(db.shipping_no), '') <> ''
        GROUP BY db.invoice_no, TRIM(db.shipping_no)
    ) pay_shipping_other
        ON pay_shipping_other.invoice_no = src.invoice_no
       AND pay_shipping_other.shipping_no = src.shipping_no

    LEFT JOIN
    (
        SELECT
            db.invoice_no,
            SUM(COALESCE(db.bayar_amount, 0)) AS total_paid_invoice
        FROM detail_bayar db
        WHERE db.bayar_no <> ?
        GROUP BY db.invoice_no
    ) inv_pay_other
        ON inv_pay_other.invoice_no = src.invoice_no

    LEFT JOIN
    (
        SELECT
            used.invoice_no,
            used.shipping_no,
            SUM(
                CASE
                    WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                      OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                        THEN COALESCE(hri.grand_total, 0)
                    ELSE COALESCE(hri.return_amount, 0)
                END
            ) AS return_amount
        FROM
        (
            SELECT DISTINCT
                db.invoice_no,
                TRIM(db.shipping_no) AS shipping_no,
                TRIM(db.return_id) AS return_id
            FROM detail_bayar db
            WHERE db.bayar_no <> ?
              AND COALESCE(TRIM(db.shipping_no), '') <> ''
              AND COALESCE(TRIM(db.return_id), '') <> ''
        ) used
        INNER JOIN head_retur_invoice hri
            ON TRIM(hri.return_id) = used.return_id
           AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
        GROUP BY used.invoice_no, used.shipping_no
    ) ret_used_shipping_other
        ON ret_used_shipping_other.invoice_no = src.invoice_no
       AND ret_used_shipping_other.shipping_no = src.shipping_no

    LEFT JOIN
    (
        SELECT
            used.invoice_no,
            SUM(
                CASE
                    WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
                      OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                        THEN COALESCE(hri.grand_total, 0)
                    ELSE COALESCE(hri.return_amount, 0)
                END
            ) AS return_amount
        FROM
        (
            SELECT DISTINCT
                db.invoice_no,
                TRIM(db.return_id) AS return_id
            FROM detail_bayar db
            WHERE db.bayar_no <> ?
              AND COALESCE(TRIM(db.return_id), '') <> ''
        ) used
        INNER JOIN head_retur_invoice hri
            ON TRIM(hri.return_id) = used.return_id
           AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
        GROUP BY used.invoice_no
    ) ret_used_invoice_other
        ON ret_used_invoice_other.invoice_no = src.invoice_no

    WHERE
        src.shipping_amount > 0
        AND (
            CASE
                WHEN src.shipping_count = 1
                    THEN GREATEST(
                        src.shipping_amount
                        - COALESCE(inv_pay_other.total_paid_invoice, 0)
                        - COALESCE(ret_used_invoice_other.return_amount, 0),
                        0
                    )
                ELSE GREATEST(
                    src.shipping_amount
                    - COALESCE(pay_shipping_other.paid_amount, 0)
                    - COALESCE(ret_used_shipping_other.return_amount, 0),
                    0
                )
            END
        ) > 0.01

    ORDER BY
        src.invoice_date DESC,
        src.shipping_date DESC,
        src.shipping_no DESC
";

$stmt = mysqli_prepare($conn, $sqlShipping);
mysqli_stmt_bind_param($stmt, 'sssss', $customerId, $bayarNo, $bayarNo, $bayarNo, $bayarNo);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$rows = [];
while ($row = mysqli_fetch_assoc($res)) {
    $rows[] = $row;
}
mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| SALDO AWAL CUSTOMER UNTUK EDIT
|--------------------------------------------------------------------------
| Saldo awal positif = piutang historis.
| Pembayaran dari bayar_no ini dikeluarkan agar item existing tetap tampil.
|--------------------------------------------------------------------------
*/
$openingRows = [];

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        cob.opening_id,
        cob.opening_date,
        cob.customer_id,
        cob.customer_name,
        cob.customer_city,
        cob.opening_balance,
        COALESCE((
            SELECT SUM(ABS(COALESCE(db.bayar_amount,0)))
            FROM detail_bayar db
            WHERE db.payment_source = 'OPENING'
              AND db.opening_id = cob.opening_id
              AND db.bayar_no <> ?
        ),0) AS paid_except_current,
        GREATEST(
            ABS(cob.opening_balance) - COALESCE((
                SELECT SUM(ABS(COALESCE(db2.bayar_amount,0)))
                FROM detail_bayar db2
                WHERE db2.payment_source = 'OPENING'
                  AND db2.opening_id = cob.opening_id
                  AND db2.bayar_no <> ?
            ),0),
            0
        ) AS sisa_before_current
     FROM customer_opening_balance cob
     WHERE cob.customer_id = ?
       AND LOWER(COALESCE(cob.status,'Active')) = 'active'
       AND ABS(cob.opening_balance) > 0.01
     HAVING sisa_before_current > 0.01
     ORDER BY cob.opening_date ASC, cob.opening_id ASC"
);

if ($stmt) {
    mysqli_stmt_bind_param($stmt,'sss',$bayarNo,$bayarNo,$customerId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $openingRows[] = $row;
    }
    mysqli_stmt_close($stmt);
}

/*
|--------------------------------------------------------------------------
| RETUR AKTIF CUSTOMER
|--------------------------------------------------------------------------
| Retur tidak lagi terikat Invoice / Shipping yang dicentang.
|--------------------------------------------------------------------------
*/
$returnOptions = [];
$currentReturnAmount = 0.0;

$stmt = mysqli_prepare(
    $conn,
    "SELECT
        hri.return_id,
        hri.return_date,
        hri.return_amount,
        hri.grand_total,
        CASE
            WHEN UPPER(COALESCE(hri.invoice_no, '')) LIKE '%CP-MCP%'
              OR UPPER(COALESCE(hri.shipping_no, '')) LIKE '%CP-MCP%'
                THEN COALESCE(hri.grand_total, 0)
            ELSE COALESCE(hri.return_amount, 0)
        END AS effective_return_amount,
        hri.reason_return
     FROM head_retur_invoice hri
     WHERE hri.customer_id = ?
       AND LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'

       /*
        * Saat edit:
        * - retur yang sedang dipakai bayar_no ini tetap ditampilkan;
        * - retur yang dipakai pembayaran lain tidak ditampilkan.
        */
       AND NOT EXISTS (
           SELECT 1
           FROM detail_bayar db_used
           WHERE TRIM(COALESCE(db_used.return_id, '')) = TRIM(hri.return_id)
             AND db_used.bayar_no <> ?
       )

     ORDER BY hri.return_date DESC, hri.return_id DESC"
);

mysqli_stmt_bind_param($stmt, 'ss', $customerId, $bayarNo);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

while ($ret = mysqli_fetch_assoc($res)) {
    $returnOptions[] = $ret;

    if ((string)$ret['return_id'] === $currentReturnId) {
        $currentReturnAmount = (float)($ret['effective_return_amount'] ?? 0);
    }
}

mysqli_stmt_close($stmt);

/*
|--------------------------------------------------------------------------
| SALDO TITIP YANG TERSEDIA UNTUK EDIT
|--------------------------------------------------------------------------
| Current titip usage sudah mengurangi head_titip.
| Saat edit, hak pemakaian lama perlu ditambahkan kembali sebagai available.
|--------------------------------------------------------------------------
*/
$stmt = mysqli_prepare(
    $conn,
    "SELECT COALESCE(SUM(balance_amount), 0) AS saldo
     FROM head_titip
     WHERE customer_id = ?
       AND balance_amount > 0"
);
mysqli_stmt_bind_param($stmt, 's', $customerId);
mysqli_stmt_execute($stmt);
$saldoRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

$saldoTitipAvailable =
    (float)($saldoRow['saldo'] ?? 0) +
    $currentTitip;
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<style>
.pay-form-wrap *{box-sizing:border-box;font-family:'Segoe UI',Tahoma,Arial,sans-serif}
.pay-form-wrap{background:#f0f2f5;padding:12px;color:#212529;font-size:11px}
.app-icon{width:14px;height:14px;display:inline-flex;align-items:center;justify-content:center;vertical-align:-2px}
.app-icon svg{width:14px;height:14px;fill:currentColor}.title-icon svg{width:18px;height:18px}
.crystal-header{background:linear-gradient(135deg,#1e3c72 0%,#2a5298 100%);color:#fff;padding:10px 15px;border-radius:5px}
.form-card{background:#fff;border:1px solid #dee2e6;border-radius:5px;padding:12px;margin-top:10px}
.form-section{border:1px solid #d8e2ef;border-radius:6px;background:#fff;margin-bottom:12px;overflow:hidden}
.form-section-title{background:linear-gradient(135deg,#eef5ff 0%,#f8fbff 100%);color:#1e3c72;font-size:11px;font-weight:800;padding:8px 10px;border-bottom:1px solid #d8e2ef;text-transform:uppercase}
.form-section-body{padding:10px}
.form-grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:10px}
.form-grid-3{display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px}
.form-grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.field-full{grid-column:1/-1}
.ff label{display:block;font-size:10px;font-weight:700;color:#0d6efd;margin-bottom:3px;text-transform:uppercase}
.ff input,.ff select,.ff textarea{width:100%;border:1px solid #ced4da;border-radius:3px;padding:6px 8px;font-size:11px;background:#fff}
.ff input[readonly],.ff textarea[readonly]{background:#f8f9fa}
.readonly-highlight{background:#f8f9fa!important;font-weight:700;color:#1e3c72}
.payment-summary-input{background:#eaf7ef!important;font-weight:800;color:#0f5132}
.btn-vs{padding:6px 12px;font-size:11px;font-weight:bold;border:none;border-radius:3px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:5px;line-height:1;min-height:30px}
.btn-success{background:#198754;color:#fff}.btn-secondary{background:#6c757d;color:#fff}
.checkbox-line{display:flex;align-items:center;gap:8px;min-height:30px;padding:6px 8px;border:1px solid #ced4da;border-radius:3px;background:#fff}
.checkbox-line input{width:auto!important;margin:0}
.invoice-table-wrap{overflow:auto;max-height:430px;border:1px solid #c9d5e2}
.invoice-pay-table{width:100%;min-width:1250px;border-collapse:collapse;font-size:10px}
.invoice-pay-table th{position:sticky;top:0;background:#e9ecef;color:#2b4c7e;border:1px solid #c0cddb;padding:6px 5px;white-space:nowrap;text-align:center;z-index:2}
.invoice-pay-table td{border:1px solid #d3d3d3;padding:5px;white-space:nowrap}
.invoice-pay-table tr.selected-row td{background:#eaf7ef}
.money{text-align:right}.pay-now-input{width:125px!important;text-align:right;font-weight:700;border:1px solid #8fb6e8!important;background:#fffdf2!important}.pay-now-input:disabled{background:#f1f3f5!important;color:#999}.text-center{text-align:center}.row-checkbox{width:16px;height:16px}
.return-select{min-width:210px}
.warning-box{display:none;margin-top:6px;padding:7px;border-radius:3px;font-size:11px;font-weight:bold}
.warning-danger{display:block;background:#f8d7da;color:#842029;border:1px solid #f5c2c7}
.warning-info{display:block;background:#fff3cd;color:#664d03;border:1px solid #ffecb5}
.warning-ok{display:block;background:#d1e7dd;color:#0f5132;border:1px solid #badbcc}
@media(max-width:1100px){.form-grid-4{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){.form-grid-2,.form-grid-3,.form-grid-4{grid-template-columns:1fr}}
</style>

<div class="pay-form-wrap">
<?php if (isset($_SESSION['alert'])): ?>
    <?= $_SESSION['alert']; unset($_SESSION['alert']); ?>
<?php endif; ?>

<div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
    <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
        <span class="app-icon title-icon"><?= appIcon('payment') ?></span>
        Edit Pembayaran Multi Invoice
    </h5>
    <a class="btn-vs btn-secondary" href="index.php?page=pembayaran">
        <span class="app-icon"><?= appIcon('back') ?></span>
        Kembali
    </a>
</div>

<form method="POST" action="modul/transaksi/update_bayar.php" id="formBayar">
<input type="hidden" name="bayar_no" value="<?= h($bayarNo) ?>">

<div class="form-card">

<div class="form-section">
    <div class="form-section-title">1. Data Pembayaran & Customer</div>
    <div class="form-section-body">
        <div class="form-grid-3">
            <div class="ff">
                <label>No. Bayar</label>
                <input type="text" value="<?= h($bayarNo) ?>" class="readonly-highlight" readonly>
            </div>
            <div class="ff">
                <label>Tanggal Bayar</label>
                <input type="text" name="bayar_date" class="js-date-picker"
                       value="<?= h(formatDateDisplay($head['bayar_date'])) ?>"
                       required autocomplete="off">
            </div>
            <div class="ff">
                <label>Customer ID</label>
                <input type="text" name="customer_id"
                       value="<?= h($head['customer_id']) ?>"
                       class="readonly-highlight" readonly>
            </div>
        </div>
        <div class="form-grid-2" style="margin-top:10px;">
            <div class="ff">
                <label>Nama Customer</label>
                <input type="text" value="<?= h($head['customer_name']) ?>" readonly>
            </div>
            <div class="ff">
                <label>Customer City</label>
                <input type="text" value="<?= h($head['customer_city']) ?>" readonly>
            </div>
            <div class="ff field-full">
                <label>Customer Address</label>
                <textarea rows="2" readonly><?= h($head['customer_address']) ?></textarea>
            </div>
        </div>
    </div>
</div>

<div class="form-section">
    <div class="form-section-title">
        2. Piutang Pembayaran
        <span id="selected_count_label" style="float:right;font-weight:800;color:#0d6efd;">0 dipilih</span>
    </div>
    <div class="form-section-body">
        <div class="invoice-table-wrap">
            <table class="invoice-pay-table">
                <thead>
                    <tr>
                        <th>Pilih</th>
                        <th>Sumber</th>
                        <th>Invoice No.</th>
                        <th>Invoice Date</th>
                        <th>Shipping No.</th>
                        <th>Shipping Date</th>
                        <th>Nilai Shipping</th>
                        <th>Dibayar Selain Transaksi Ini</th>
                        <th>Sisa Sebelum Transaksi Ini</th>
                        <th>Bayar Sekarang</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="10" class="text-center" style="padding:12px;color:#777;">
                            Tidak ada invoice/shipping NON CP-MCP atau saldo awal positif outstanding.
                            Transaksi tetap dapat diedit sebagai Retur Customer standalone.
                        </td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($rows as $i => $row): ?>
                    <?php
                        $key = trim((string)$row['invoice_no']) . '|' . trim((string)$row['shipping_no']);
                        $current = $currentDetailMap[$key] ?? null;
                        $checked = $current !== null;
                    ?>
                    <tr class="invoice-row <?= $checked ? 'selected-row' : '' ?>"
                        data-sisa="<?= h($row['sisa_before_current']) ?>">
                        <td class="text-center">
                            <input type="checkbox"
                                   class="row-checkbox js-select-invoice"
                                   name="items[<?= $i ?>][selected]"
                                   value="1"
                                   <?= $checked ? 'checked' : '' ?>>

                            <input type="hidden" name="items[<?= $i ?>][source]" value="INVOICE">
                            <input type="hidden" name="items[<?= $i ?>][opening_id]" value="">
                            <input type="hidden"
                                   name="items[<?= $i ?>][invoice_no]"
                                   value="<?= h($row['invoice_no']) ?>">
                            <input type="hidden"
                                   name="items[<?= $i ?>][shipping_no]"
                                   value="<?= h($row['shipping_no']) ?>">
                        </td>
                        <td><strong>INVOICE</strong></td>
                        <td><?= h($row['invoice_no']) ?></td>
                        <td><?= h(formatDateDisplay($row['invoice_date'])) ?></td>
                        <td><?= h($row['shipping_no']) ?></td>
                        <td><?= h(formatDateDisplay($row['shipping_date'])) ?></td>
                        <td class="money">Rp <?= h(formatMoney($row['shipping_amount'])) ?></td>
                        <td class="money">Rp <?= h(formatMoney($row['paid_except_current'])) ?></td>
                        <td class="money"><strong>Rp <?= h(formatMoney($row['sisa_before_current'])) ?></strong></td>
                        <td class="money">
                            <input type="text"
                                   class="pay-now-input js-pay-now"
                                   name="items[<?= $i ?>][pay_now]"
                                   value="<?= h(formatMoney($checked ? (float)($current['bayar_amount'] ?? 0) : 0)) ?>"
                                   autocomplete="off"
                                   <?= $checked ? '' : 'disabled' ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php $openingIndexBase = count($rows); ?>
                <?php foreach ($openingRows as $j => $opening): ?>
                    <?php
                        $itemIndex = $openingIndexBase + $j;
                        $openingKey = 'OPENING|' . (int)$opening['opening_id'];
                        $currentOpening = $currentDetailMap[$openingKey] ?? null;
                        $openingChecked = $currentOpening !== null;
                    ?>
                    <tr class="invoice-row opening-row <?= $openingChecked ? 'selected-row' : '' ?>"
                        data-sisa="<?= h($opening['sisa_before_current']) ?>"
                        data-opening-sign="<?= ((float)$opening['opening_balance'] < 0) ? '-1' : '1' ?>">
                        <td class="text-center">
                            <input type="checkbox" class="row-checkbox js-select-invoice"
                                   name="items[<?= $itemIndex ?>][selected]" value="1"
                                   <?= $openingChecked ? 'checked' : '' ?>>
                            <input type="hidden" name="items[<?= $itemIndex ?>][source]" value="OPENING">
                            <input type="hidden" name="items[<?= $itemIndex ?>][opening_id]" value="<?= h($opening['opening_id']) ?>">
                            <input type="hidden" name="items[<?= $itemIndex ?>][opening_sign]" value="<?= ((float)$opening['opening_balance'] < 0) ? '-1' : '1' ?>">
                            <input type="hidden" name="items[<?= $itemIndex ?>][invoice_no]" value="">
                            <input type="hidden" name="items[<?= $itemIndex ?>][shipping_no]" value="">
                        </td>
                        <td>
                            <strong>
                                <?= ((float)$opening['opening_balance'] < 0)
                                    ? 'SALDO AWAL (-) / KREDIT'
                                    : 'SALDO AWAL (+) / PIUTANG' ?>
                            </strong>
                        </td>
                        <td>-</td>
                        <td><?= h(formatDateDisplay($opening['opening_date'])) ?></td>
                        <td>-</td>
                        <td>-</td>
                        <td class="money">
                            <?= ((float)$opening['opening_balance'] < 0) ? '- ' : '' ?>Rp <?= h(formatMoney(abs((float)$opening['opening_balance']))) ?>
                        </td>
                        <td class="money">Rp <?= h(formatMoney($opening['paid_except_current'])) ?></td>
                        <td class="money"><strong>Rp <?= h(formatMoney($opening['sisa_before_current'])) ?></strong></td>
                        <td class="money">
                            <input type="text"
                                   class="pay-now-input js-pay-now"
                                   name="items[<?= $itemIndex ?>][pay_now]"
                                   value="<?= h(formatMoney($openingChecked ? (float)($currentOpening['bayar_amount'] ?? 0) : 0)) ?>"
                                   autocomplete="off"
                                   <?= $openingChecked ? '' : 'disabled' ?>>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="form-section">
    <div class="form-section-title">3. Retur Customer (Opsional)</div>
    <div class="form-section-body">
        <div class="form-grid-2">
            <div class="ff">
                <label>No. Retur</label>
                <select name="return_id" id="return_id">
                    <option value="">-- Tidak Ada / Pilih No. Retur --</option>
                    <?php foreach ($returnOptions as $ret): ?>
                        <option
                            value="<?= h($ret['return_id']) ?>"
                            data-return-amount="<?= h($ret['effective_return_amount']) ?>"
                            data-reason-return="<?= h($ret['reason_return']) ?>"
                            <?= $currentReturnId === (string)$ret['return_id'] ? 'selected' : '' ?>
                        >
                            <?= h(
                                $ret['return_id'] .
                                ' | ' .
                                formatDateDisplay($ret['return_date']) .
                                ' | Rp ' .
                                formatMoney($ret['effective_return_amount']) .
                                ((string)($ret['reason_return'] ?? '') !== ''
                                    ? ' | ' . $ret['reason_return']
                                    : '')
                            ) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="ff">
                <label>Nilai Retur Terpilih</label>
                <input type="text"
                       id="retur_amount_display"
                       class="readonly-highlight"
                       value="Rp <?= h(formatMoney($currentReturnAmount)) ?>"
                       readonly>
                <input type="hidden"
                       name="retur_invoice"
                       id="retur_invoice"
                       value="<?= h($currentReturnAmount) ?>">
            </div>

            <div class="ff field-full">
                <div id="returnInfo"
                     class="warning-box warning-info"
                     style="<?= $currentReturnId !== '' ? 'display:block;' : 'display:none;' ?>">
                    <?php if ($currentReturnId !== ''): ?>
                        Retur terpilih:
                        <strong><?= h($currentReturnId) ?></strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="form-section">
    <div class="form-section-title">4. Perhitungan Pembayaran</div>
    <div class="form-section-body">
        <div class="form-grid-4">
            <div class="ff">
                <label>Total Tagihan Terpilih</label>
                <input type="text" id="selected_total_display" class="readonly-highlight" readonly>
                <input type="hidden" id="selected_total" value="0">
            </div>

            <div class="ff">
                <label>Total Setelah Retur</label>
                <input type="text" id="net_payable_display" class="payment-summary-input" readonly>
                <input type="hidden" id="net_payable" value="0">
            </div>

            <div class="ff">
                <label>Jumlah Titip Uang Tersedia</label>
                <input type="text" id="saldo_titip_display"
                       value="Rp <?= h(formatMoney($saldoTitipAvailable)) ?>"
                       class="readonly-highlight" readonly>
                <input type="hidden" id="saldo_titip" value="<?= h($saldoTitipAvailable) ?>">
            </div>

            <div class="ff">
                <label>Pakai Titip Uang</label>
                <div class="checkbox-line">
                    <input type="checkbox" name="pakai_titip" id="pakai_titip" value="1"
                           <?= $currentTitip > 0 ? 'checked' : '' ?>>
                    <span>Gunakan titip uang customer</span>
                </div>
            </div>

            <div class="ff">
                <label>Nominal Titip</label>
                <input type="text" name="nominal_titip" id="nominal_titip"
                       value="<?= h(formatMoney($currentTitip)) ?>"
                       <?= $currentTitip > 0 ? '' : 'disabled' ?>>
            </div>

            <div class="ff">
                <label>Nominal Cash / Transfer</label>
                <input type="text" name="nominal_bayar" id="nominal_bayar"
                       value="<?= h(formatMoney($currentCash)) ?>" required>
            </div>

            <div class="ff">
                <label>Total Bayar</label>
                <input type="text" id="total_bayar_display" class="payment-summary-input" readonly>
            </div>

            <div class="ff field-full">
                <div id="warningNominal" class="warning-box"></div>
            </div>
        </div>
    </div>
</div>

<div class="form-section">
    <div class="form-section-title">5. Metode Pembayaran & Keterangan</div>
    <div class="form-section-body">
        <div class="form-grid-2">
            <div class="ff">
                <label>Keterangan</label>
                <select name="keterangan" id="keterangan" required>
                    <option value="">-- Pilih --</option>
                    <option value="Cash" <?= $head['keterangan'] === 'Cash' ? 'selected' : '' ?>>Cash</option>
                    <option value="Transfer" <?= $head['keterangan'] === 'Transfer' ? 'selected' : '' ?>>Transfer</option>
                    <option value="Retur" <?= $head['keterangan'] === 'Retur' ? 'selected' : '' ?>>Retur / Cross-check</option>
                </select>
            </div>

            <div class="ff">
                <label>Nama Bank</label>
                <input type="text" name="bank_name" id="bank_name"
                       value="<?= h($head['bank_name']) ?>">
            </div>

            <div class="ff field-full">
                <label>Remarks</label>
                <textarea name="remarks" rows="3"><?= h($head['remarks']) ?></textarea>
            </div>
        </div>
    </div>
</div>

<div style="display:flex;gap:6px;justify-content:flex-end;">
    <a href="index.php?page=pembayaran" class="btn-vs btn-secondary">Batal</a>
    <button type="submit" class="btn-vs btn-success">
        <span class="app-icon"><?= appIcon('save') ?></span>
        Update Pembayaran
    </button>
</div>

</div>
</form>
</div>

<script>
function parseNumber(value) {
    value = String(value || '').replace(/[^0-9,-]/g, '');
    value = value.replace(/\./g, '').replace(',', '.');
    return parseFloat(value) || 0;
}

function formatRupiah(value) {
    return (parseFloat(value) || 0).toLocaleString('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function selectedOpeningDirection() {
    let hasNegativeOpening = false;
    let hasPositiveDebt = false;

    $('.invoice-row').each(function () {
        const checkbox = $(this).find('.js-select-invoice');
        if (!checkbox.is(':checked')) return;

        const source = String(
            $(this).find('input[name$="[source]"]').val() || 'INVOICE'
        ).toUpperCase();

        if (source === 'OPENING') {
            const sign = parseInt(
                $(this).find('input[name$="[opening_sign]"]').val() || '1',
                10
            );
            if (sign < 0) {
                hasNegativeOpening = true;
            } else {
                hasPositiveDebt = true;
            }
        } else {
            hasPositiveDebt = true;
        }
    });

    return {
        hasNegativeOpening,
        hasPositiveDebt,
        invalidMix: hasNegativeOpening && hasPositiveDebt
    };
}

function selectedSummary() {
    let total = 0;
    let payNowTotal = 0;
    let count = 0;
    let invalidPayNow = false;

    $('.invoice-row').each(function () {
        const $row = $(this);
        const checked = $row.find('.js-select-invoice').is(':checked');
        if (!checked) return;

        const sisa = parseFloat($row.data('sisa')) || 0;
        const payNow = parseNumber($row.find('.js-pay-now').val());

        total += sisa;
        payNowTotal += payNow;
        count++;

        if (payNow < -0.01 || payNow > sisa + 0.01) {
            invalidPayNow = true;
        }
    });

    return { total, payNowTotal, count, invalidPayNow };
}

function syncCashFromPayNow() {
    const selected = selectedSummary();
    const saldo = parseFloat($('#saldo_titip').val()) || 0;

    if ($('#pakai_titip').is(':checked')) {
        const currentTitip = Math.min(parseNumber($('#nominal_titip').val()), saldo, selected.payNowTotal);
        $('#nominal_titip').prop('disabled', false).val(formatRupiah(currentTitip));
        $('#nominal_bayar').val(formatRupiah(Math.max(selected.payNowTotal - currentTitip, 0)));
    } else {
        $('#nominal_titip').prop('disabled', true).val('0,00');
        $('#nominal_bayar').val(formatRupiah(selected.payNowTotal));
    }
}

function checkNominal() {
    const selected = selectedSummary();
    const returAmount = parseFloat($('#retur_invoice').val()) || 0;
    const target = Math.max(selected.total - returAmount, 0);

    const saldoTitip = parseFloat($('#saldo_titip').val()) || 0;
    const cash = parseNumber($('#nominal_bayar').val());
    const titip = $('#pakai_titip').is(':checked')
        ? parseNumber($('#nominal_titip').val())
        : 0;
    const total = cash + titip;

    $('#selected_total').val(selected.total);
    $('#selected_total_display').val('Rp ' + formatRupiah(selected.total));
    $('#net_payable').val(target);
    $('#net_payable_display').val('Rp ' + formatRupiah(target));
    $('#selected_count_label').text(selected.count + ' dipilih');
    $('#total_bayar_display').val('Rp ' + formatRupiah(total));

    const warning = $('#warningNominal');
    warning.removeClass('warning-danger warning-info warning-ok').hide();

    const direction = selectedOpeningDirection();

    if (direction.invalidMix) {
        warning.addClass('warning-danger')
            .html('Saldo Awal (-) / Kredit harus diproses tersendiri.')
            .show();
        return;
    }

    if (selected.invalidPayNow) {
        warning.addClass('warning-danger')
            .html('Nilai Bayar Sekarang tidak boleh negatif atau melebihi Sisa Sebelum Transaksi Ini.')
            .show();
        return;
    }

    if (titip > saldoTitip + 0.01) {
        warning.addClass('warning-danger')
            .html('Nominal titip melebihi saldo titip tersedia.')
            .show();
        return;
    }

    if (selected.payNowTotal > target + 0.01) {
        warning.addClass('warning-danger')
            .html('Total Bayar Sekarang melebihi tagihan setelah Retur. Maksimal: Rp ' + formatRupiah(target))
            .show();
        return;
    }

    if (Math.abs(total - selected.payNowTotal) > 0.01) {
        warning.addClass('warning-danger')
            .html(
                'Total Cash/Transfer + Titip harus sama dengan total Bayar Sekarang. ' +
                'Bayar Sekarang: Rp ' + formatRupiah(selected.payNowTotal) +
                ' | Cash/Titip: Rp ' + formatRupiah(total)
            )
            .show();
        return;
    }

    if (selected.count < 1) return;

    const sisaSetelah = Math.max(target - selected.payNowTotal, 0);
    if (sisaSetelah <= 0.01) {
        warning.addClass('warning-ok')
            .html('Pembayaran sesuai. Seluruh tagihan terpilih setelah Retur terselesaikan.')
            .show();
    } else {
        warning.addClass('warning-info')
            .html('Pembayaran parsial. Masih tersisa Rp ' + formatRupiah(sisaSetelah) + ' dari tagihan terpilih setelah Retur.')
            .show();
    }
}

$(document).ready(function () {
    if (typeof flatpickr !== 'undefined') {
        flatpickr('.js-date-picker', {
            dateFormat: 'd-M-Y',
            allowInput: true,
            disableMobile: true
        });
    }

    $('.js-select-invoice').on('change', function () {
        const $row = $(this).closest('.invoice-row');
        const $payNow = $row.find('.js-pay-now');
        const sisa = parseFloat($row.data('sisa')) || 0;

        if ($(this).is(':checked')) {
            $row.addClass('selected-row');
            $payNow.prop('disabled', false);
            if (parseNumber($payNow.val()) <= 0.01) {
                $payNow.val(formatRupiah(sisa));
            }
        } else {
            $row.removeClass('selected-row');
            $payNow.val('0,00').prop('disabled', true);
        }

        syncCashFromPayNow();
        checkNominal();
    });

    $(document).on('input keyup', '.js-pay-now', function () {
        syncCashFromPayNow();
        checkNominal();
    });

    $(document).on('blur', '.js-pay-now', function () {
        $(this).val(formatRupiah(parseNumber($(this).val())));
        syncCashFromPayNow();
        checkNominal();
    });

    $('#return_id').on('change', function () {
        const opt = $(this).find(':selected');
        const returnId = $(this).val() || '';
        const amount = parseFloat(opt.attr('data-return-amount')) || 0;
        const reason = opt.attr('data-reason-return') || '';

        $('#retur_invoice').val(amount);
        $('#retur_amount_display').val('Rp ' + formatRupiah(amount));

        const info = $('#returnInfo');
        if (!returnId) {
            info.hide().html('');
        } else {
            let html = 'Retur terpilih: <strong>' + returnId + '</strong> | Rp ' + formatRupiah(amount);
            if (reason) html += ' | ' + reason;
            info.removeClass('warning-danger warning-ok').addClass('warning-info').html(html).show();
        }

        checkNominal();
    });

    $('#pakai_titip').on('change', function () {
        const selected = selectedSummary();
        const saldo = parseFloat($('#saldo_titip').val()) || 0;
        if ($(this).is(':checked')) {
            const useTitip = Math.min(saldo, selected.payNowTotal);
            $('#nominal_titip').prop('disabled', false).val(formatRupiah(useTitip));
            $('#nominal_bayar').val(formatRupiah(Math.max(selected.payNowTotal - useTitip, 0)));
        } else {
            $('#nominal_titip').prop('disabled', true).val('0,00');
            $('#nominal_bayar').val(formatRupiah(selected.payNowTotal));
        }
        checkNominal();
    });

    $('#nominal_bayar,#nominal_titip').on('input keyup blur', checkNominal);

    $('#nominal_bayar,#nominal_titip').on('blur', function () {
        $(this).val(formatRupiah(parseNumber($(this).val())));
    });

    $('#keterangan').on('change', function () {
        if ($(this).val() === 'Cash') $('#bank_name').val('');
    });

    $('#formBayar').on('submit', function (e) {
        const selected = selectedSummary();
        const saldo = parseFloat($('#saldo_titip').val()) || 0;
        const returAmount = parseFloat($('#retur_invoice').val()) || 0;
        const target = Math.max(selected.total - returAmount, 0);
        const cash = parseNumber($('#nominal_bayar').val());
        const titip = $('#pakai_titip').is(':checked') ? parseNumber($('#nominal_titip').val()) : 0;
        const total = cash + titip;
        const selectedReturnId = String($('#return_id').val() || '').trim();
        const direction = selectedOpeningDirection();

        if (direction.invalidMix) {
            alert('Saldo Awal (-) / Kredit harus diproses tersendiri dan tidak boleh digabung dengan Invoice atau Saldo Awal (+).');
            e.preventDefault(); return false;
        }

        if (direction.hasNegativeOpening && (titip > 0.01 || selectedReturnId !== '')) {
            alert('Saldo Awal (-) / Kredit tidak boleh digabung dengan Titip atau Retur Customer.');
            e.preventDefault(); return false;
        }

        if (selected.count < 1 && selectedReturnId === '') {
            alert('Minimal pilih 1 Invoice / Shipping atau Saldo Awal, atau pilih 1 Retur Customer.');
            e.preventDefault(); return false;
        }

        if (selected.invalidPayNow) {
            alert('Ada nilai Bayar Sekarang yang tidak valid atau melebihi sisa.');
            e.preventDefault(); return false;
        }

        if (selected.count > 0 && selected.payNowTotal <= 0.01 && selectedReturnId === '') {
            alert('Isi Bayar Sekarang minimal pada satu item.');
            e.preventDefault(); return false;
        }

        if (selected.payNowTotal > target + 0.01) {
            alert('Total Bayar Sekarang tidak boleh melebihi total tagihan setelah Retur. Maksimal: Rp ' + formatRupiah(target));
            e.preventDefault(); return false;
        }

        if (titip > saldo + 0.01) {
            alert('Nominal titip melebihi saldo titip tersedia.');
            e.preventDefault(); return false;
        }

        if (Math.abs(total - selected.payNowTotal) > 0.01) {
            alert('Total Cash/Transfer + Titip harus sama dengan total Bayar Sekarang. Bayar Sekarang: Rp ' + formatRupiah(selected.payNowTotal));
            e.preventDefault(); return false;
        }

        $('#nominal_bayar').val(cash);
        $('#nominal_titip').prop('disabled', false).val(titip);
        $('#return_id').prop('disabled', false);

        /* Hindari max_input_vars: hanya item terpilih yang dikirim. */
        $('.invoice-row').each(function () {
            const $row = $(this);
            if (!$row.find('.js-select-invoice').is(':checked')) {
                $row.find('input[name^="items["]').prop('disabled', true);
            } else {
                $row.find('.js-pay-now').prop('disabled', false);
            }
        });
        $('.js-pay-now').each(function () {
            $(this).val(
                String($(this).val() || '').replace(/\./g, '')
            );
        });

        return true;
    });

    checkNominal();
});
$(document).on('input', '.js-pay-now', function () {
    let value = String($(this).val() || '');

    // Ambil angka saja
    value = value.replace(/\D/g, '');

    if (value === '') {
        $(this).val('');
        return;
    }

    // Format ribuan dengan titik
    $(this).val(
        parseInt(value, 10).toLocaleString('id-ID')
    );
});
</script>