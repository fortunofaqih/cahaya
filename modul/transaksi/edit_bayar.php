<?php
// modul/transaksi/edit_bayar.php
// Edit Pembayaran Multi Invoice / Multi Shipping.
//
// Satu bayar_no dapat memiliki banyak detail_bayar.
// Customer pembayaran tidak diganti saat edit.
// User dapat menambah/mengurangi Invoice/Shipping yang dicentang.
// Checkbox = bayar penuh sebesar sisa shipping sebelum pembayaran ini.
// Retur dipilih standalone berdasarkan Customer untuk kebutuhan cross-check.

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
    $key = trim((string)$row['invoice_no']) . '|' . trim((string)$row['shipping_no']);
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

        COALESCE(pay_other.paid_amount, 0) AS paid_except_current,

        GREATEST(
            src.shipping_amount - COALESCE(pay_other.paid_amount, 0),
            0
        ) AS sisa_before_current,

        COALESCE(ret_shipping.return_amount, 0) AS retur_amount

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
            ) AS shipping_amount
        FROM head_invoice hi
        INNER JOIN det_invoice di
            ON di.invoice_no = hi.invoice_no
        WHERE hi.customer_id = ?
          AND COALESCE(TRIM(di.shipping_no), '') <> ''
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
            invoice_no,
            TRIM(shipping_no) AS shipping_no,
            SUM(COALESCE(bayar_amount, 0)) AS paid_amount
        FROM detail_bayar
        WHERE bayar_no <> ?
          AND COALESCE(TRIM(shipping_no), '') <> ''
        GROUP BY invoice_no, TRIM(shipping_no)
    ) pay_other
        ON pay_other.invoice_no = src.invoice_no
       AND pay_other.shipping_no = src.shipping_no

    LEFT JOIN
    (
        SELECT
            invoice_no,
            TRIM(shipping_no) AS shipping_no,
            SUM(COALESCE(return_amount, 0)) AS return_amount
        FROM head_retur_invoice
        WHERE LOWER(COALESCE(status, 'Open')) <> 'cancelled'
        GROUP BY invoice_no, TRIM(shipping_no)
    ) ret_shipping
        ON ret_shipping.invoice_no = src.invoice_no
       AND ret_shipping.shipping_no = src.shipping_no

    WHERE
        src.shipping_amount > 0
        AND (
            GREATEST(
                src.shipping_amount - COALESCE(pay_other.paid_amount, 0),
                0
            ) > 0.01
            OR COALESCE(ret_shipping.return_amount, 0) > 0.01
        )

    ORDER BY src.invoice_date DESC, src.shipping_date DESC, src.shipping_no DESC
";

$stmt = mysqli_prepare($conn, $sqlShipping);
mysqli_stmt_bind_param($stmt, 'ss', $customerId, $bayarNo);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$rows = [];
while ($row = mysqli_fetch_assoc($res)) {
    $rows[] = $row;
}
mysqli_stmt_close($stmt);

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
        return_id,
        return_date,
        invoice_no,
        TRIM(shipping_no) AS shipping_no,
        return_amount,
        reason_return
     FROM head_retur_invoice
     WHERE customer_id = ?
       AND LOWER(COALESCE(status, 'Open')) <> 'cancelled'
     ORDER BY return_date DESC, return_id DESC"
);

mysqli_stmt_bind_param($stmt, 's', $customerId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

while ($ret = mysqli_fetch_assoc($res)) {
    $returnOptions[] = $ret;

    if ((string)$ret['return_id'] === $currentReturnId) {
        $currentReturnAmount = (float)($ret['return_amount'] ?? 0);
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
.money{text-align:right}.text-center{text-align:center}.row-checkbox{width:16px;height:16px}
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
        2. Invoice / Shipping Pembayaran
        <span id="selected_count_label" style="float:right;font-weight:800;color:#0d6efd;">0 dipilih</span>
    </div>
    <div class="form-section-body">
        <div class="invoice-table-wrap">
            <table class="invoice-pay-table">
                <thead>
                    <tr>
                        <th>Pilih</th>
                        <th>Invoice No.</th>
                        <th>Invoice Date</th>
                        <th>Shipping No.</th>
                        <th>Shipping Date</th>
                        <th>Nilai Shipping</th>
                        <th>Dibayar Selain Transaksi Ini</th>
                        <th>Sisa Sebelum Transaksi Ini</th>
                    </tr>
                </thead>
                <tbody>
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

                            <input type="hidden"
                                   name="items[<?= $i ?>][invoice_no]"
                                   value="<?= h($row['invoice_no']) ?>">
                            <input type="hidden"
                                   name="items[<?= $i ?>][shipping_no]"
                                   value="<?= h($row['shipping_no']) ?>">
                        </td>
                        <td><?= h($row['invoice_no']) ?></td>
                        <td><?= h(formatDateDisplay($row['invoice_date'])) ?></td>
                        <td><?= h($row['shipping_no']) ?></td>
                        <td><?= h(formatDateDisplay($row['shipping_date'])) ?></td>
                        <td class="money">Rp <?= h(formatMoney($row['shipping_amount'])) ?></td>
                        <td class="money">Rp <?= h(formatMoney($row['paid_except_current'])) ?></td>
                        <td class="money"><strong>Rp <?= h(formatMoney($row['sisa_before_current'])) ?></strong></td>
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
                            data-return-amount="<?= h($ret['return_amount']) ?>"
                            data-invoice-no="<?= h($ret['invoice_no']) ?>"
                            data-shipping-no="<?= h($ret['shipping_no']) ?>"
                            data-reason-return="<?= h($ret['reason_return']) ?>"
                            <?= $currentReturnId === (string)$ret['return_id'] ? 'selected' : '' ?>
                        >
                            <?= h(
                                $ret['return_id'] .
                                ' | ' .
                                formatDateDisplay($ret['return_date']) .
                                ' | Rp ' .
                                formatMoney($ret['return_amount']) .
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

function selectedSummary() {
    let total = 0;
    let count = 0;

    $('.invoice-row').each(function () {
        if ($(this).find('.js-select-invoice').is(':checked')) {
            total += parseFloat($(this).data('sisa')) || 0;
            count++;
        }
    });

    return {total, count};
}

function checkNominal() {
    const selected = selectedSummary();
    const returAmount = parseFloat($('#retur_invoice').val()) || 0;
    const target = Math.max(
        selected.total - returAmount,
        0
    );

    const saldoTitip = parseFloat($('#saldo_titip').val()) || 0;
    const cash = parseNumber($('#nominal_bayar').val());
    const titip = $('#pakai_titip').is(':checked')
        ? parseNumber($('#nominal_titip').val())
        : 0;

    const total = cash + titip;

    $('#selected_total').val(selected.total);
    $('#selected_total_display').val(
        'Rp ' + formatRupiah(selected.total)
    );

    $('#net_payable').val(target);
    $('#net_payable_display').val(
        'Rp ' + formatRupiah(target)
    );

    $('#selected_count_label').text(
        selected.count + ' dipilih'
    );

    $('#total_bayar_display').val(
        'Rp ' + formatRupiah(total)
    );

    const warning = $('#warningNominal');

    warning
        .removeClass(
            'warning-danger warning-info warning-ok'
        )
        .hide();

    if (titip > saldoTitip + 0.01) {
        warning
            .addClass('warning-danger')
            .html('Nominal titip melebihi saldo titip tersedia.')
            .show();
        return;
    }

    if (selected.count < 1) {
        return;
    }

    if (returAmount > selected.total + 0.01) {
        warning
            .addClass('warning-info')
            .html(
                'Nilai Retur lebih besar dari total tagihan. ' +
                'Total yang harus dibayar menjadi Rp 0,00. ' +
                'Sisa Retur tidak otomatis menjadi titip uang.'
            )
            .show();
    }

    const diff = total - target;

    if (Math.abs(diff) <= 0.01) {
        warning
            .addClass('warning-ok')
            .html('Total bayar sudah sesuai setelah dikurangi Retur.')
            .show();
    } else if (diff < 0) {
        warning
            .addClass('warning-info')
            .html(
                'Total bayar kurang Rp ' +
                formatRupiah(Math.abs(diff)) +
                '.'
            )
            .show();
    } else {
        warning
            .addClass('warning-danger')
            .html(
                'Total bayar melebihi total setelah Retur Rp ' +
                formatRupiah(diff) +
                '.'
            )
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
        const row = $(this).closest('.invoice-row');

        if ($(this).is(':checked')) {
            row.addClass('selected-row');
        } else {
            row.removeClass('selected-row');
        }

        const selected = selectedSummary();
        const returAmount = parseFloat($('#retur_invoice').val()) || 0;
        const target = Math.max(selected.total - returAmount, 0);
        const saldo = parseFloat($('#saldo_titip').val()) || 0;

        if ($('#pakai_titip').is(':checked')) {
            const useTitip = Math.min(saldo, target);
            $('#nominal_titip').prop('disabled', false).val(formatRupiah(useTitip));
            $('#nominal_bayar').val(formatRupiah(Math.max(target - useTitip, 0)));
        } else {
            $('#nominal_titip').prop('disabled', true).val('0,00');
            $('#nominal_bayar').val(formatRupiah(target));
        }

        checkNominal();
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

            const selected = selectedSummary();
            const target = selected.total;
            const saldo = parseFloat($('#saldo_titip').val()) || 0;

            if ($('#pakai_titip').is(':checked')) {
                const useTitip = Math.min(saldo, target);
                $('#nominal_titip').prop('disabled', false).val(formatRupiah(useTitip));
                $('#nominal_bayar').val(formatRupiah(Math.max(target - useTitip, 0)));
            } else {
                $('#nominal_titip').prop('disabled', true).val('0,00');
                $('#nominal_bayar').val(formatRupiah(target));
            }

            checkNominal();
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

            if (invoiceNo) html += 'Invoice ' + invoiceNo;
            if (invoiceNo && shippingNo) html += ' | ';
            if (shippingNo) html += 'Shipping ' + shippingNo;

            html += '</span>';
        }

        info
            .removeClass('warning-danger warning-ok')
            .addClass('warning-info')
            .html(html)
            .show();

        const selected = selectedSummary();
        const target = Math.max(
            selected.total - amount,
            0
        );
        const saldo = parseFloat($('#saldo_titip').val()) || 0;

        if ($('#pakai_titip').is(':checked')) {
            const useTitip = Math.min(saldo, target);
            $('#nominal_titip').prop('disabled', false).val(formatRupiah(useTitip));
            $('#nominal_bayar').val(formatRupiah(Math.max(target - useTitip, 0)));
        } else {
            $('#nominal_titip').prop('disabled', true).val('0,00');
            $('#nominal_bayar').val(formatRupiah(target));
        }

        checkNominal();
    });

    $('#pakai_titip').on('change', function () {
        const selected = selectedSummary();
        const returAmount = parseFloat($('#retur_invoice').val()) || 0;
        const target = Math.max(selected.total - returAmount, 0);
        const saldo = parseFloat($('#saldo_titip').val()) || 0;

        if ($(this).is(':checked')) {
            const useTitip = Math.min(saldo, target);
            $('#nominal_titip').prop('disabled', false).val(formatRupiah(useTitip));
            $('#nominal_bayar').val(formatRupiah(Math.max(target - useTitip, 0)));
        } else {
            $('#nominal_titip').prop('disabled', true).val('0,00');
            $('#nominal_bayar').val(formatRupiah(target));
        }

        checkNominal();
    });

    $('#nominal_bayar,#nominal_titip').on('input keyup blur', checkNominal);

    $('#nominal_bayar,#nominal_titip').on('blur', function () {
        $(this).val(formatRupiah(parseNumber($(this).val())));
    });

    $('#keterangan').on('change', function () {
        if ($(this).val() === 'Cash') {
            $('#bank_name').val('');
        }
    });

    $('#formBayar').on('submit', function (e) {
        const selected = selectedSummary();
        const saldo = parseFloat($('#saldo_titip').val()) || 0;
        const returAmount = parseFloat($('#retur_invoice').val()) || 0;
        const target = Math.max(selected.total - returAmount, 0);

        const cash = parseNumber($('#nominal_bayar').val());
        const titip = $('#pakai_titip').is(':checked')
            ? parseNumber($('#nominal_titip').val())
            : 0;
        const total = cash + titip;

        if (selected.count < 1) {
            alert('Minimal pilih 1 Invoice / Shipping.');
            e.preventDefault();
            return false;
        }

        if (titip > saldo + 0.01) {
            alert('Nominal titip melebihi saldo titip tersedia.');
            e.preventDefault();
            return false;
        }

        if (Math.abs(total - target) > 0.01) {
            alert(
                'Total Bayar harus sama dengan total tagihan setelah dikurangi Retur. Target: Rp ' +
                formatRupiah(target)
            );
            e.preventDefault();
            return false;
        }

        $('#nominal_bayar').val(cash);
        $('#nominal_titip').prop('disabled', false).val(titip);

        /*
         * Retur berdiri sendiri pada level pembayaran/customer.
         */
        $('#return_id').prop('disabled', false);

        return true;
    });

    checkNominal();
});
</script>