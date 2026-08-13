<?php
// modul/transaksi/add_bayar_shipping_revisi_lunas_legacy.php

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

function formatDateDisplay($date) {
    if (empty($date) || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    return $ts ? date('d-M-Y', $ts) : '';
}

function formatMoney($value) {
    return number_format((float)$value, 2, ',', '.');
}

function generateBayarNo($conn) {
    $prefix = 'B-';
    $sql = "
        SELECT bayar_no 
        FROM head_bayar 
        WHERE bayar_no LIKE 'B-%'
        ORDER BY CAST(SUBSTRING(bayar_no, 3) AS UNSIGNED) DESC
        LIMIT 1
    ";
    $res = mysqli_query($conn, $sql);
    $lastNumber = 0;

    if ($res && $row = mysqli_fetch_assoc($res)) {
        $lastNumber = (int)substr($row['bayar_no'], 2);
    }

    return $prefix . str_pad($lastNumber + 1, 9, '0', STR_PAD_LEFT);
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

$bayar_no = generateBayarNo($conn);

$shippings = [];

/*
 * Dropdown pembayaran dihitung per pasangan invoice_no + shipping_no.
 * Rule:
 * - Belum pernah dibayar: tampil.
 * - Sudah dibayar sebagian: tampil dengan sisa terbaru.
 * - Total pembayaran >= nilai shipping: tidak tampil.
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

        /* Retur aktif khusus pasangan invoice_no + shipping_no. */
        COALESCE(ret_shipping.return_amount, 0) AS retur_amount,

        /* Pembayaran yang benar-benar tersimpan untuk Shipping No ini. */
        COALESCE(pay_shipping.paid_amount, 0) AS paid_amount,

        /*
         * Penentuan sisa:
         * 1. Jika invoice secara keseluruhan sudah lunas, sisa = 0.
         * 2. Jika pembayaran sudah memiliki shipping_no, hitung per shipping.
         * 3. Untuk data lama tanpa shipping_no dan invoice hanya memiliki satu
         *    shipping, pembayaran invoice lama dialokasikan ke shipping tersebut.
         * 4. Jika transaksi terakhir pasangan shipping menyimpan sisa_after <= 0,
         *    shipping dianggap lunas.
         */
        CASE
            WHEN src.shipping_count = 1
                THEN GREATEST(
                    src.shipping_amount
                    - COALESCE(inv_pay.total_paid_invoice, 0),
                    0
                )
            ELSE GREATEST(
                src.shipping_amount
                - COALESCE(pay_shipping.paid_amount, 0),
                0
            )
        END AS sisa_shipping,

        COALESCE((
            SELECT SUM(ht.balance_amount)
            FROM head_titip ht
            WHERE ht.customer_id = src.customer_id
              AND ht.balance_amount > 0
        ), 0) AS saldo_titip

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
                    WHEN COALESCE(di.total, 0) > 0 THEN COALESCE(di.total, 0)
                    ELSE COALESCE(di.subtotal, 0)
                END
            ) AS shipping_amount,

            (
                SELECT COUNT(DISTINCT TRIM(di_count.shipping_no))
                FROM det_invoice di_count
                WHERE di_count.invoice_no = hi.invoice_no
                  AND COALESCE(TRIM(di_count.shipping_no), '') <> ''
            ) AS shipping_count,

            (
                SELECT SUM(
                    CASE
                        WHEN COALESCE(di_total.total, 0) > 0
                            THEN COALESCE(di_total.total, 0)
                        ELSE COALESCE(di_total.subtotal, 0)
                    END
                )
                FROM det_invoice di_total
                WHERE di_total.invoice_no = hi.invoice_no
            ) AS invoice_amount

        FROM head_invoice hi
        INNER JOIN det_invoice di
            ON di.invoice_no = hi.invoice_no
        WHERE COALESCE(TRIM(di.shipping_no), '') <> ''
        GROUP BY
            hi.invoice_no,
            hi.invoice_date,
            hi.customer_id,
            hi.customer_name,
            hi.customer_address,
            hi.customer_city,
            TRIM(di.shipping_no)
    ) src

    /* Retur aktif per pasangan invoice_no + shipping_no. */
    LEFT JOIN
    (
        SELECT
            hri.invoice_no,
            TRIM(hri.shipping_no) AS shipping_no,
            SUM(COALESCE(hri.return_amount, 0)) AS return_amount
        FROM head_retur_invoice hri
        WHERE LOWER(COALESCE(hri.status, 'Open')) <> 'cancelled'
        GROUP BY hri.invoice_no, TRIM(hri.shipping_no)
    ) ret_shipping
        ON ret_shipping.invoice_no = src.invoice_no
       AND ret_shipping.shipping_no = src.shipping_no
/* Pembayaran per pasangan invoice_no + shipping_no. */
    LEFT JOIN
    (
        SELECT
            db.invoice_no,
            TRIM(db.shipping_no) AS shipping_no,
            SUM(COALESCE(db.bayar_amount, 0)) AS paid_amount
        FROM detail_bayar db
        INNER JOIN head_bayar hb
            ON hb.bayar_no = db.bayar_no
        WHERE COALESCE(TRIM(db.shipping_no), '') <> ''
        GROUP BY
            db.invoice_no,
            TRIM(db.shipping_no)
    ) pay_shipping
        ON pay_shipping.invoice_no = src.invoice_no
       AND pay_shipping.shipping_no = src.shipping_no

    /* Semua pembayaran invoice, termasuk data lama yang shipping_no-nya kosong. */
    LEFT JOIN
    (
        SELECT
            db.invoice_no,
            SUM(COALESCE(db.bayar_amount, 0)) AS total_paid_invoice
        FROM detail_bayar db
        INNER JOIN head_bayar hb
            ON hb.bayar_no = db.bayar_no
        GROUP BY db.invoice_no
    ) inv_pay
        ON inv_pay.invoice_no = src.invoice_no

    /* Transaksi pembayaran terakhir khusus pasangan invoice + shipping. */
    LEFT JOIN
    (
        SELECT
            db.invoice_no,
            TRIM(db.shipping_no) AS shipping_no,
            db.sisa_after
        FROM detail_bayar db
        INNER JOIN
        (
            SELECT
                invoice_no,
                TRIM(shipping_no) AS shipping_no,
                MAX(id) AS max_id
            FROM detail_bayar
            WHERE COALESCE(TRIM(shipping_no), '') <> ''
            GROUP BY invoice_no, TRIM(shipping_no)
        ) last_id
            ON last_id.max_id = db.id
    ) last_shipping
        ON last_shipping.invoice_no = src.invoice_no
       AND last_shipping.shipping_no = src.shipping_no

    WHERE
        src.shipping_amount > 0
        AND (
            (
                CASE
                    WHEN src.shipping_count = 1
                        THEN GREATEST(
                            src.shipping_amount
                            - COALESCE(inv_pay.total_paid_invoice, 0),
                            0
                        )
                    ELSE GREATEST(
                        src.shipping_amount
                        - COALESCE(pay_shipping.paid_amount, 0),
                        0
                    )
                END
            ) > 0.01
            OR COALESCE(ret_shipping.return_amount, 0) > 0.01
        )

    ORDER BY
        src.invoice_date DESC,
        src.shipping_date DESC,
        src.shipping_no DESC
";

$resShipping = mysqli_query($conn, $sqlShipping);
if (!$resShipping) {
    die('Query dropdown pembayaran gagal: ' . mysqli_error($conn));
}

while ($row = mysqli_fetch_assoc($resShipping)) {
    $shippings[] = $row;
}

/*
 * Daftar Retur aktif per customer.
 * Retur tidak lagi terikat ke invoice/shipping yang dicentang.
 */
$returnsByCustomer = [];

$sqlReturnList = "
    SELECT
        return_id,
        return_date,
        customer_id,
        customer_name,
        invoice_no,
        TRIM(shipping_no) AS shipping_no,
        return_amount,
        reason_return,
        remarks_return
    FROM head_retur_invoice
    WHERE LOWER(COALESCE(status, 'Open')) <> 'cancelled'
      AND COALESCE(customer_id, '') <> ''
    ORDER BY return_date DESC, return_id DESC
";

$resReturnList = mysqli_query($conn, $sqlReturnList);

if (!$resReturnList) {
    die('Query daftar retur gagal: ' . mysqli_error($conn));
}

while ($ret = mysqli_fetch_assoc($resReturnList)) {
    $customerKey = trim((string)($ret['customer_id'] ?? ''));

    if ($customerKey === '') {
        continue;
    }

    if (!isset($returnsByCustomer[$customerKey])) {
        $returnsByCustomer[$customerKey] = [];
    }

    $returnsByCustomer[$customerKey][] = [
        'return_id' => (string)($ret['return_id'] ?? ''),
        'return_date' => (string)($ret['return_date'] ?? ''),
        'return_amount' => (float)($ret['return_amount'] ?? 0),
        'reason_return' => (string)($ret['reason_return'] ?? ''),
        'invoice_no' => (string)($ret['invoice_no'] ?? ''),
        'shipping_no' => (string)($ret['shipping_no'] ?? ''),
    ];
}

/*
|--------------------------------------------------------------------------
| CUSTOMER YANG MEMILIKI TAGIHAN TERSEDIA
|--------------------------------------------------------------------------
| Sumbernya dari $shippings agar daftar customer selalu konsisten dengan
| invoice/shipping yang memang dapat dipilih untuk pembayaran.
|--------------------------------------------------------------------------
*/
$paymentCustomers = [];

foreach ($shippings as $ship) {
    $cid = (string)($ship['customer_id'] ?? '');

    if ($cid === '') {
        continue;
    }

    if (!isset($paymentCustomers[$cid])) {
        $paymentCustomers[$cid] = [
            'customer_id' => $cid,
            'customer_name' => (string)($ship['customer_name'] ?? ''),
            'customer_address' => (string)($ship['customer_address'] ?? ''),
            'customer_city' => (string)($ship['customer_city'] ?? ''),
            'saldo_titip' => (float)($ship['saldo_titip'] ?? 0),
        ];
    }
}

uasort($paymentCustomers, function ($a, $b) {
    return strcasecmp(
        (string)($a['customer_name'] ?? ''),
        (string)($b['customer_name'] ?? '')
    );
});
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

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
.warning-box{display:none;margin-top:6px;padding:7px;border-radius:3px;font-size:11px;font-weight:bold}
.warning-danger{display:block;background:#f8d7da;color:#842029;border:1px solid #f5c2c7}
.warning-info{display:block;background:#fff3cd;color:#664d03;border:1px solid #ffecb5}
.warning-ok{display:block;background:#d1e7dd;color:#0f5132;border:1px solid #badbcc}
.select2-container{width:100%!important;font-size:11px}
.select2-container--default .select2-selection--single{height:30px;border:1px solid #ced4da;border-radius:3px;display:flex;align-items:center}
.select2-container--default .select2-selection--single .select2-selection__rendered{line-height:28px;padding-left:8px;font-size:11px}
.select2-container--default .select2-selection--single .select2-selection__arrow{height:28px}
.invoice-table-wrap{overflow:auto;max-height:420px;border:1px solid #c9d5e2}
.invoice-pay-table{width:100%;min-width:1250px;border-collapse:collapse;font-size:10px}
.invoice-pay-table th{position:sticky;top:0;z-index:2;background:#e9ecef;color:#2b4c7e;border:1px solid #c0cddb;padding:6px 5px;white-space:nowrap;text-align:center}
.invoice-pay-table td{border:1px solid #d3d3d3;padding:5px;white-space:nowrap;vertical-align:middle}
.invoice-pay-table tbody tr:hover td{background:#f3f8ff}
.invoice-pay-table tbody tr.selected-row td{background:#eaf7ef}
.money{text-align:right;font-variant-numeric:tabular-nums}
.text-center{text-align:center}
.row-checkbox{width:16px;height:16px}
.return-select{min-width:210px}
.summary-count{font-weight:800;color:#0d6efd}
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
        Add Pembayaran Multi Invoice
    </h5>
    <a class="btn-vs btn-secondary" href="index.php?page=pembayaran">
        <span class="app-icon"><?= appIcon('back') ?></span>
        Kembali
    </a>
</div>

<form method="POST" action="modul/transaksi/save_bayar.php" id="formBayar">
<div class="form-card">

    <div class="form-section">
        <div class="form-section-title">1. Customer & Payment Header</div>
        <div class="form-section-body">
            <div class="form-grid-3">
                <div class="ff">
                    <label>Customer</label>
                    <select id="customer_selector" class="select2-customer" required>
                        <option value="">-- Pilih Customer --</option>
                        <?php foreach ($paymentCustomers as $cust): ?>
                            <option
                                value="<?= h($cust['customer_id']) ?>"
                                data-customer-name="<?= h($cust['customer_name']) ?>"
                                data-customer-address="<?= h($cust['customer_address']) ?>"
                                data-customer-city="<?= h($cust['customer_city']) ?>"
                                data-saldo-titip="<?= h($cust['saldo_titip']) ?>">
                                <?= h($cust['customer_id'] . ' | ' . $cust['customer_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <input type="hidden" name="customer_id" id="customer_id">
                    <input type="hidden" name="customer_name" id="customer_name">
                    <input type="hidden" name="customer_address" id="customer_address">
                    <input type="hidden" name="customer_city" id="customer_city">
                </div>

                <div class="ff">
                    <label>No. Bayar</label>
                    <input type="text" name="bayar_no" value="<?= h($bayar_no) ?>" class="readonly-highlight" readonly>
                </div>

                <div class="ff">
                    <label><span class="app-icon"><?= appIcon('calendar') ?></span> Tanggal Bayar</label>
                    <input type="text" name="bayar_date" class="js-date-picker"
                           value="<?= h(date('d-M-Y')) ?>" autocomplete="off" required>
                </div>
            </div>

            <div class="form-grid-2" style="margin-top:10px;">
                <div class="ff">
                    <label>Nama Customer</label>
                    <input type="text" id="customer_name_display" class="readonly-highlight" readonly>
                </div>
                <div class="ff">
                    <label>Customer City</label>
                    <input type="text" id="customer_city_display" class="readonly-highlight" readonly>
                </div>
                <div class="ff field-full">
                    <label>Customer Address</label>
                    <textarea id="customer_address_display" rows="2" readonly></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">
            2. Pilih Invoice / Shipping yang Dibayar
            <span class="summary-count" id="selected_count_label" style="float:right;">0 dipilih</span>
        </div>
        <div class="form-section-body">
            <div class="invoice-table-wrap">
                <table class="invoice-pay-table">
                    <thead>
                        <tr>
                            <th style="width:42px;">Pilih</th>
                            <th>Invoice No.</th>
                            <th>Invoice Date</th>
                            <th>Shipping No.</th>
                            <th>Shipping Date</th>
                            <th>Nilai Shipping</th>
                            <th>Sudah Dibayar</th>
                            <th>Sisa</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($shippings as $i => $ship): ?>
                        <tr class="invoice-row"
                            data-customer-id="<?= h($ship['customer_id']) ?>"
                            data-sisa="<?= h($ship['sisa_shipping']) ?>"
                            data-invoice-no="<?= h($ship['invoice_no']) ?>"
                            data-shipping-no="<?= h($ship['shipping_no']) ?>"
                            style="display:none;">
                            <td class="text-center">
                                <input type="checkbox"
                                       class="row-checkbox js-select-invoice"
                                       name="items[<?= $i ?>][selected]"
                                       value="1">

                                <input type="hidden" name="items[<?= $i ?>][invoice_no]" value="<?= h($ship['invoice_no']) ?>">
                                <input type="hidden" name="items[<?= $i ?>][invoice_date]" value="<?= h($ship['invoice_date']) ?>">
                                <input type="hidden" name="items[<?= $i ?>][shipping_no]" value="<?= h($ship['shipping_no']) ?>">
                                <input type="hidden" name="items[<?= $i ?>][shipping_date]" value="<?= h($ship['shipping_date']) ?>">
                                <input type="hidden" name="items[<?= $i ?>][invoice_amount]" value="<?= h($ship['shipping_amount']) ?>">
                                <input type="hidden" name="items[<?= $i ?>][sisa_before]" value="<?= h($ship['sisa_shipping']) ?>">
                            </td>
                            <td><?= h($ship['invoice_no']) ?></td>
                            <td><?= h(formatDateDisplay($ship['invoice_date'])) ?></td>
                            <td><?= h($ship['shipping_no']) ?></td>
                            <td><?= h(formatDateDisplay($ship['shipping_date'])) ?></td>
                            <td class="money">Rp <?= h(formatMoney($ship['shipping_amount'])) ?></td>
                            <td class="money">Rp <?= h(formatMoney($ship['paid_amount'])) ?></td>
                            <td class="money"><strong>Rp <?= h(formatMoney($ship['sisa_shipping'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="noInvoiceMessage" style="padding:12px;text-align:center;color:#777;">
                Pilih customer untuk melihat invoice/shipping yang masih dapat dibayar.
            </div>
        </div>
    </div>

    <div class="form-section">
        <div class="form-section-title">3. Retur Customer (Opsional)</div>
        <div class="form-section-body">
            <div class="form-grid-2">
                <div class="ff">
                    <label>No. Retur</label>
                    <select name="return_id" id="return_id" disabled>
                        <option value="">-- Tidak Ada / Pilih No. Retur --</option>
                    </select>
                </div>

                <div class="ff">
                    <label>Nilai Retur Terpilih</label>
                    <input type="text"
                           id="retur_amount_display"
                           class="readonly-highlight"
                           value="Rp 0,00"
                           readonly>
                    <input type="hidden"
                           name="retur_invoice"
                           id="retur_invoice"
                           value="0">
                </div>

                <div class="ff field-full">
                    <div id="returnInfo"
                         class="warning-box warning-info"
                         style="display:none;"></div>
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
                    <input type="hidden" name="selected_total" id="selected_total" value="0">
                </div>

                <div class="ff">
                    <label>Total Setelah Retur</label>
                    <input type="text" id="net_payable_display" class="payment-summary-input" readonly>
                    <input type="hidden" name="net_payable" id="net_payable" value="0">
                </div>

                <div class="ff">
                    <label>Jumlah Titip Uang</label>
                    <input type="text" id="saldo_titip_display" class="readonly-highlight" readonly>
                    <input type="hidden" name="saldo_titip" id="saldo_titip" value="0">
                </div>

                <div class="ff">
                    <label>Pakai Titip Uang</label>
                    <div class="checkbox-line">
                        <input type="checkbox" name="pakai_titip" id="pakai_titip" value="1">
                        <span>Gunakan titip uang customer</span>
                    </div>
                </div>

                <div class="ff">
                    <label>Nominal Titip yang Dipakai</label>
                    <input type="text" name="nominal_titip" id="nominal_titip"
                           value="0,00" autocomplete="off" disabled>
                </div>

                <div class="ff">
                    <label>Nominal Bayar Cash / Transfer</label>
                    <input type="text" name="nominal_bayar" id="nominal_bayar"
                           value="0,00" autocomplete="off" required>
                </div>

                <div class="ff">
                    <label>Total Bayar</label>
                    <input type="text" id="total_bayar_display"
                           class="payment-summary-input" readonly>
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
                        <option value="Cash">Cash</option>
                        <option value="Transfer">Transfer</option>
                        <option value="Retur">Retur / Cross-check</option>
                    </select>
                </div>

                <div class="ff">
                    <label>Nama Bank</label>
                    <input type="text" name="bank_name" id="bank_name"
                           placeholder="Contoh: BCA / Mandiri / BRI">
                </div>

                <div class="ff field-full">
                    <label>Remarks</label>
                    <textarea name="remarks" rows="3"></textarea>
                </div>
            </div>
        </div>
    </div>

    <div style="margin-top:12px;display:flex;gap:6px;justify-content:flex-end;">
        <a href="index.php?page=pembayaran" class="btn-vs btn-secondary">
            <span class="app-icon"><?= appIcon('back') ?></span>
            Batal
        </a>
        <button type="submit" class="btn-vs btn-success">
            <span class="app-icon"><?= appIcon('save') ?></span>
            Save Pembayaran
        </button>
    </div>

</div>
</form>
</div>

<script>
const returnsByCustomer = <?= json_encode(
    $returnsByCustomer,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

function parseNumber(value) {
    value = String(value || '').replace(/[^0-9,-]/g, '');
    value = value.replace(/\./g, '').replace(',', '.');
    return parseFloat(value) || 0;
}

function formatRupiah(value) {
    value = parseFloat(value) || 0;

    return value.toLocaleString('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function getSelectedTotal() {
    let total = 0;
    let count = 0;

    $('.invoice-row:visible').each(function () {
        const checkbox = $(this).find('.js-select-invoice');

        if (checkbox.is(':checked')) {
            total += parseFloat($(this).data('sisa')) || 0;
            count++;
        }
    });

    return { total, count };
}

function refreshPaymentCalculation(resetCash = true) {
    const selected = getSelectedTotal();
    const saldoTitip = parseFloat($('#saldo_titip').val()) || 0;
    const returAmount = parseFloat($('#retur_invoice').val()) || 0;

    const netPayable = Math.max(
        selected.total - returAmount,
        0
    );

    $('#selected_total').val(selected.total);
    $('#selected_total_display').val(
        'Rp ' + formatRupiah(selected.total)
    );

    $('#net_payable').val(netPayable);
    $('#net_payable_display').val(
        'Rp ' + formatRupiah(netPayable)
    );

    $('#selected_count_label').text(
        selected.count + ' dipilih'
    );

    if (resetCash) {
        if ($('#pakai_titip').is(':checked')) {
            const useTitip = Math.min(
                saldoTitip,
                netPayable
            );

            $('#nominal_titip')
                .prop('disabled', false)
                .val(formatRupiah(useTitip));

            $('#nominal_bayar').val(
                formatRupiah(
                    Math.max(netPayable - useTitip, 0)
                )
            );
        } else {
            $('#nominal_titip')
                .prop('disabled', true)
                .val('0,00');

            $('#nominal_bayar').val(
                formatRupiah(netPayable)
            );
        }
    }

    checkNominal();
}

function checkNominal() {
    const selectedTotal = parseFloat($('#selected_total').val()) || 0;
    const returAmount = parseFloat($('#retur_invoice').val()) || 0;
    const target = Math.max(
        selectedTotal - returAmount,
        0
    );

    const saldoTitip = parseFloat($('#saldo_titip').val()) || 0;
    const nominalCash = parseNumber($('#nominal_bayar').val());
    const nominalTitip = $('#pakai_titip').is(':checked')
        ? parseNumber($('#nominal_titip').val())
        : 0;

    const totalBayar = nominalCash + nominalTitip;
    const warning = $('#warningNominal');

    $('#net_payable').val(target);
    $('#net_payable_display').val(
        'Rp ' + formatRupiah(target)
    );

    $('#total_bayar_display').val(
        'Rp ' + formatRupiah(totalBayar)
    );

    warning
        .removeClass(
            'warning-danger warning-info warning-ok'
        )
        .hide();

    if (nominalTitip > saldoTitip + 0.01) {
        warning
            .addClass('warning-danger')
            .html(
                'Nominal titip melebihi saldo titip customer. ' +
                'Saldo: Rp ' + formatRupiah(saldoTitip)
            )
            .show();
        return;
    }

    if (selectedTotal <= 0) {
        return;
    }

    if (returAmount > selectedTotal + 0.01) {
        warning
            .addClass('warning-info')
            .html(
                'Nilai retur lebih besar dari total tagihan terpilih. ' +
                'Total yang harus dibayar menjadi Rp 0,00. ' +
                'Sisa retur tidak otomatis menjadi titip uang.'
            )
            .show();
    }

    const selisih = totalBayar - target;

    if (Math.abs(selisih) <= 0.01) {
        warning
            .addClass('warning-ok')
            .html(
                'Total bayar sudah sesuai setelah dikurangi Retur.'
            )
            .show();
    } else if (selisih < 0) {
        warning
            .addClass('warning-info')
            .html(
                'Total bayar masih kurang Rp ' +
                formatRupiah(Math.abs(selisih)) +
                '.'
            )
            .show();
    } else {
        warning
            .addClass('warning-danger')
            .html(
                'Total bayar melebihi total setelah Retur sebesar Rp ' +
                formatRupiah(selisih)
            )
            .show();
    }
}

function refreshReturnOptions(customerId) {
    const select = $('#return_id');
    const info = $('#returnInfo');

    select.empty().append(
        $('<option>', {
            value: '',
            text: '-- Tidak Ada / Pilih No. Retur --'
        })
    );

    $('#retur_invoice').val(0);
    $('#retur_amount_display').val('Rp ' + formatRupiah(0));
    info.hide().html('');

    if (!customerId) {
        select.prop('disabled', true);
        return;
    }

    const rows = returnsByCustomer[String(customerId)] || [];

    rows.forEach(function (ret) {
        let text =
            ret.return_id +
            ' | ' +
            ret.return_date +
            ' | Rp ' +
            formatRupiah(ret.return_amount);

        if (ret.reason_return) {
            text += ' | ' + ret.reason_return;
        }

        select.append(
            $('<option>', {
                value: ret.return_id,
                text: text
            })
            .attr('data-return-amount', ret.return_amount)
            .attr('data-invoice-no', ret.invoice_no || '')
            .attr('data-shipping-no', ret.shipping_no || '')
            .attr('data-reason-return', ret.reason_return || '')
        );
    });

    select.prop('disabled', false);

    if (!rows.length) {
        info
            .removeClass('warning-danger warning-ok')
            .addClass('warning-info')
            .html('Customer ini tidak memiliki Retur aktif.')
            .show();
    }
}

$(document).ready(function () {
    $('.select2-customer').select2({
        placeholder: '-- Pilih Customer --',
        allowClear: true,
        width: '100%'
    });

    if (typeof flatpickr !== 'undefined') {
        flatpickr('.js-date-picker', {
            dateFormat: 'd-M-Y',
            allowInput: true,
            disableMobile: true
        });
    }

    $('#customer_selector').on('change', function () {
        const opt = $(this).find(':selected');
        const customerId = $(this).val() || '';

        const name = opt.data('customer-name') || '';
        const address = opt.data('customer-address') || '';
        const city = opt.data('customer-city') || '';
        const saldoTitip = parseFloat(
            opt.data('saldo-titip')
        ) || 0;

        $('#customer_id').val(customerId);
        $('#customer_name').val(name);
        $('#customer_address').val(address);
        $('#customer_city').val(city);

        $('#customer_name_display').val(name);
        $('#customer_address_display').val(address);
        $('#customer_city_display').val(city);

        $('#saldo_titip').val(saldoTitip);
        $('#saldo_titip_display').val(
            'Rp ' + formatRupiah(saldoTitip)
        );

        $('.invoice-row')
            .hide()
            .removeClass('selected-row')
            .find('.js-select-invoice')
            .prop('checked', false);

        let visibleCount = 0;

        if (customerId) {
            $('.invoice-row').each(function () {
                if (
                    String($(this).data('customer-id')) ===
                    String(customerId)
                ) {
                    $(this).show();
                    visibleCount++;
                }
            });
        }

        if (customerId && visibleCount > 0) {
            $('#noInvoiceMessage').hide();
        } else if (customerId) {
            $('#noInvoiceMessage')
                .text(
                    'Tidak ada invoice/shipping outstanding untuk customer ini.'
                )
                .show();
        } else {
            $('#noInvoiceMessage')
                .text(
                    'Pilih customer untuk melihat invoice/shipping yang masih dapat dibayar.'
                )
                .show();
        }

        $('#pakai_titip').prop('checked', false);
        $('#nominal_titip')
            .prop('disabled', true)
            .val('0,00');

        refreshReturnOptions(customerId);
        refreshPaymentCalculation(true);
    });

    $(document).on('change', '.js-select-invoice', function () {
        const row = $(this).closest('.invoice-row');

        if ($(this).is(':checked')) {
            row.addClass('selected-row');
        } else {
            row.removeClass('selected-row');
        }

        refreshPaymentCalculation(true);
    });

    $('#return_id').on('change', function () {
        const opt = $(this).find(':selected');
        const returnId = $(this).val() || '';
        const amount = parseFloat(opt.attr('data-return-amount')) || 0;
        const invoiceNo = opt.attr('data-invoice-no') || '';
        const shippingNo = opt.attr('data-shipping-no') || '';
        const reason = opt.attr('data-reason-return') || '';

        $('#retur_invoice').val(amount);
        $('#retur_amount_display').val('Rp ' + formatRupiah(amount));

        const info = $('#returnInfo');

        if (!returnId) {
            info.hide().html('');
            refreshPaymentCalculation(true);
            return;
        }

        let html =
            'Retur terpilih: <strong>' +
            returnId +
            '</strong> | Rp ' +
            formatRupiah(amount);

        if (reason) {
            html += ' | ' + reason;
        }

        if (invoiceNo || shippingNo) {
            html += '<br><span style="font-weight:400;">Referensi retur: ';

            if (invoiceNo) {
                html += 'Invoice ' + invoiceNo;
            }

            if (invoiceNo && shippingNo) {
                html += ' | ';
            }

            if (shippingNo) {
                html += 'Shipping ' + shippingNo;
            }

            html += '</span>';
        }

        info
            .removeClass('warning-danger warning-ok')
            .addClass('warning-info')
            .html(html)
            .show();

        /*
         * Retur mengurangi total tagihan terpilih.
         */
        refreshPaymentCalculation(true);
    });

    $('#pakai_titip').on('change', function () {
        refreshPaymentCalculation(true);
    });

    $('#nominal_bayar, #nominal_titip').on(
        'input keyup blur',
        function () {
            checkNominal();
        }
    );

    $('#nominal_bayar, #nominal_titip').on(
        'blur',
        function () {
            $(this).val(
                formatRupiah(
                    parseNumber($(this).val())
                )
            );
        }
    );

    $('#keterangan').on('change', function () {
        if ($(this).val() === 'Cash') {
            $('#bank_name').val('');
        }
    });

    $('#formBayar').on('submit', function (e) {
        const selected = getSelectedTotal();
        const saldoTitip =
            parseFloat($('#saldo_titip').val()) || 0;

        const returAmount =
            parseFloat($('#retur_invoice').val()) || 0;

        const targetBayar = Math.max(
            selected.total - returAmount,
            0
        );

        const nominalCash =
            parseNumber($('#nominal_bayar').val());

        const nominalTitip =
            $('#pakai_titip').is(':checked')
                ? parseNumber($('#nominal_titip').val())
                : 0;

        const totalBayar =
            nominalCash + nominalTitip;

        if (!$('#customer_selector').val()) {
            alert('Customer wajib dipilih.');
            e.preventDefault();
            return false;
        }

        if (selected.count < 1) {
            alert(
                'Minimal centang 1 invoice/shipping yang akan dibayar.'
            );
            e.preventDefault();
            return false;
        }

        if (nominalTitip > saldoTitip + 0.01) {
            alert(
                'Nominal titip yang dipakai melebihi saldo titip customer.'
            );
            e.preventDefault();
            return false;
        }

        /*
         * Total bayar harus sama dengan:
         * Total tagihan terpilih - Retur terpilih.
         */
        if (
            Math.abs(
                totalBayar - targetBayar
            ) > 0.01
        ) {
            alert(
                'Total Bayar harus sama dengan total tagihan setelah dikurangi Retur. ' +
                'Target: Rp ' +
                formatRupiah(targetBayar)
            );

            e.preventDefault();
            return false;
        }

        $('#nominal_bayar').val(nominalCash);
        $('#nominal_titip')
            .prop('disabled', false)
            .val(nominalTitip);

        /*
         * Retur sekarang berdiri sendiri pada level pembayaran/customer.
         */
        $('#return_id').prop('disabled', false);

        return true;
    });

    $('#selected_total_display').val(
        'Rp ' + formatRupiah(0)
    );

    $('#net_payable_display').val(
        'Rp ' + formatRupiah(0)
    );

    $('#saldo_titip_display').val(
        'Rp ' + formatRupiah(0)
    );

    $('#total_bayar_display').val(
        'Rp ' + formatRupiah(0)
    );

    refreshReturnOptions('');
});
</script>