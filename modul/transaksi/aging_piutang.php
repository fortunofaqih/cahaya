<?php
// modul/transaksi/aging_piutang.php

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

function appIcon($name) {
    $icons = [
        'report' => '<svg viewBox="0 0 24 24"><path d="M5 3h14v18H5V3Zm2 2v14h10V5H7Zm2 3h6v2H9V8Zm0 4h6v2H9v-2Zm0 4h4v2H9v-2Z"/></svg>',
        'print' => '<svg viewBox="0 0 24 24"><path d="M6 9V3h12v6h2a2 2 0 0 1 2 2v7h-4v3H6v-3H2v-7a2 2 0 0 1 2-2h2Zm2-4v4h8V5H8Zm8 12H8v2h8v-2Zm2-2h2v-4H4v4h2v-2h12v2Z"/></svg>',
        'reset' => '<svg viewBox="0 0 24 24"><path d="M12 5a7 7 0 1 1-6.33 10H7.9A5 5 0 1 0 12 7H8.83l2.58 2.59L10 11 5 6l5-5 1.41 1.41L8.83 5H12Z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24"><path d="M7 2h2v2h6V2h2v2h3v18H4V4h3V2Zm11 8H6v10h12V10ZM6 6v2h12V6H6Z"/></svg>',
        'filter' => '<svg viewBox="0 0 24 24"><path d="M3 5h18l-7 8v6l-4 2v-8L3 5Z"/></svg>',
    ];

    return $icons[$name] ?? '';
}

$currentMonth = date('n');
$currentYear = date('Y');

$selectedMonth = (int)($_GET['bulan'] ?? $currentMonth);
$selectedYear = (int)($_GET['tahun'] ?? $currentYear);
$filterBy = $_GET['filter_by'] ?? 'semua';
$filterValue = $_GET['filter_value'] ?? '';
$reportType = $_GET['report_type'] ?? 'global';

if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = $currentMonth;
}

if ($selectedYear < 2020 || $selectedYear > ((int)$currentYear + 1)) {
    $selectedYear = $currentYear;
}

$monthNames = [
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

$groups = [];
$sqlGroup = "
    SELECT DISTINCT area_code
    FROM m_customer
    WHERE area_code IS NOT NULL
      AND area_code <> ''
    ORDER BY area_code ASC
";
$resGroup = mysqli_query($conn, $sqlGroup);
if ($resGroup) {
    while ($row = mysqli_fetch_assoc($resGroup)) {
        $groups[] = $row['area_code'];
    }
}

$cities = [];
$sqlCity = "
    SELECT DISTINCT city
    FROM m_customer
    WHERE city IS NOT NULL
      AND city <> ''
    ORDER BY city ASC
";
$resCity = mysqli_query($conn, $sqlCity);
if ($resCity) {
    while ($row = mysqli_fetch_assoc($resCity)) {
        $cities[] = $row['city'];
    }
}

$customers = [];
$sqlCustomer = "
    SELECT customer_id, customer
    FROM m_customer
    WHERE COALESCE(is_active, 'Checked') = 'Checked'
    ORDER BY customer ASC
";
$resCustomer = mysqli_query($conn, $sqlCustomer);
if ($resCustomer) {
    while ($row = mysqli_fetch_assoc($resCustomer)) {
        $customers[] = $row;
    }
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
.aging-wrap * {
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
}
.aging-wrap {
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
    vertical-align: -2px;
}
.app-icon svg {
    width: 14px;
    height: 14px;
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
    padding: 12px;
    margin-top: 10px;
}
.filter-section {
    border: 1px solid #d8e2ef;
    border-radius: 6px;
    background: #fff;
    margin-bottom: 12px;
    overflow: hidden;
}
.filter-section-title {
    background: linear-gradient(135deg, #eef5ff 0%, #f8fbff 100%);
    color: #1e3c72;
    font-size: 11px;
    font-weight: 800;
    padding: 8px 10px;
    border-bottom: 1px solid #d8e2ef;
    text-transform: uppercase;
}
.filter-section-body {
    padding: 10px;
}
.filter-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1.3fr 2fr;
    gap: 10px;
}
.report-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(170px, 1fr));
    gap: 10px;
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
.radio-card {
    border: 1px solid #ced4da;
    border-radius: 4px;
    padding: 8px;
    background: #fff;
    display: flex;
    gap: 8px;
    align-items: center;
    min-height: 34px;
}
.radio-card input {
    width: auto;
}
.help-box {
    background: #fff3cd;
    border: 1px solid #ffecb5;
    color: #664d03;
    padding: 9px 10px;
    border-radius: 5px;
    line-height: 1.5;
}
.btn-vs {
    padding: 7px 14px;
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
    min-height: 32px;
}
.btn-success { background: #198754; color: #fff; }
.btn-secondary { background: #6c757d; color: #fff; }
.select2-container {
    width: 100% !important;
    font-size: 11px;
}
.select2-container--default .select2-selection--single {
    height: 31px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    display: flex;
    align-items: center;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    line-height: 29px;
    color: #212529;
    padding-left: 8px;
    font-size: 11px;
}
.select2-container--default .select2-selection--single .select2-selection__arrow {
    height: 29px;
}
.select2-dropdown {
    font-size: 11px;
}
@media (max-width: 1000px) {
    .filter-grid,
    .report-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="aging-wrap">
    <div class="crystal-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h5 style="margin:0;display:flex;align-items:center;gap:7px;">
            <span class="app-icon title-icon"><?= appIcon('report') ?></span>
            Aging Piutang / Account Receivable Aging
        </h5>
    </div>

    <div class="filter-card">
        <form method="GET" action="modul/transaksi/cetak_aging_piutang_global.php" target="_blank" id="formAging">
            <div class="filter-section">
                <div class="filter-section-title">Periode Laporan</div>
                <div class="filter-section-body">
                    <div class="filter-grid">
                        <div class="ff">
                            <label><span class="app-icon"><?= appIcon('calendar') ?></span> Bulan</label>
                            <select name="bulan" id="bulan" required>
                                <?php foreach ($monthNames as $num => $name): ?>
                                    <option value="<?= $num ?>" <?= $selectedMonth === $num ? 'selected' : '' ?>>
                                        <?= h($name) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ff">
                            <label>Tahun</label>
                            <select name="tahun" id="tahun" required>
                                <?php for ($y = ((int)$currentYear - 5); $y <= ((int)$currentYear + 1); $y++): ?>
                                    <option value="<?= $y ?>" <?= $selectedYear === $y ? 'selected' : '' ?>>
                                        <?= $y ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="ff">
                            <label><span class="app-icon"><?= appIcon('filter') ?></span> Filter Berdasarkan</label>
                            <select name="filter_by" id="filter_by" required>
                                <option value="semua" <?= $filterBy === 'semua' ? 'selected' : '' ?>>Semua Grup</option>
                                <option value="grup" <?= $filterBy === 'grup' ? 'selected' : '' ?>>Grup</option>
                                <option value="kota" <?= $filterBy === 'kota' ? 'selected' : '' ?>>Kota</option>
                                <option value="pelanggan" <?= $filterBy === 'pelanggan' ? 'selected' : '' ?>>Pelanggan</option>
                            </select>
                        </div>

                        <div class="ff filter-value-box" id="box_filter_value">
                            <label>Nilai Filter</label>

                            <select name="filter_value_grup" id="filter_value_grup" class="select2-filter filter-value-select">
                                <option value="">-- Pilih Grup --</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?= h($group) ?>" <?= ($filterBy === 'grup' && $filterValue === $group) ? 'selected' : '' ?>>
                                        <?= h($group) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="filter_value_kota" id="filter_value_kota" class="select2-filter filter-value-select">
                                <option value="">-- Pilih Kota --</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?= h($city) ?>" <?= ($filterBy === 'kota' && $filterValue === $city) ? 'selected' : '' ?>>
                                        <?= h($city) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <select name="filter_value_pelanggan" id="filter_value_pelanggan" class="select2-filter filter-value-select">
                                <option value="">-- Pilih Pelanggan --</option>
                                <?php foreach ($customers as $cust): ?>
                                    <option value="<?= h($cust['customer_id']) ?>" <?= ($filterBy === 'pelanggan' && $filterValue === $cust['customer_id']) ? 'selected' : '' ?>>
                                        <?= h($cust['customer_id'] . ' - ' . $cust['customer']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <input type="hidden" name="filter_value" id="filter_value" value="<?= h($filterValue) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="filter-section">
                <div class="filter-section-title">Jenis Report</div>
                <div class="filter-section-body">
                    <div class="report-grid">
                        <label class="radio-card">
                            <input type="radio" name="report_type" value="global" <?= $reportType === 'global' ? 'checked' : '' ?>>
                            <span><b>Global</b> — Ringkasan per daerah/kota/pelanggan</span>
                        </label>

                        <label class="radio-card">
                            <input type="radio" name="report_type" value="detail" <?= $reportType === 'detail' ? 'checked' : '' ?>>
                            <span><b>Detail</b> — Per customer / invoice</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="help-box">
                Aging dihitung per akhir bulan yang dipilih. Bucket umur piutang memakai tanggal jatuh tempo:
                <b>invoice_date + days</b>. Jika kolom jatuh tempo di database berbeda, bagian query cetak perlu disesuaikan.
            </div>

            <div style="margin-top:12px;display:flex;gap:6px;justify-content:flex-end;">
                <a href="index.php?page=aging_piutang" class="btn-vs btn-secondary">
                    <span class="app-icon"><?= appIcon('reset') ?></span>
                    Reset
                </a>

                <button type="submit" class="btn-vs btn-success">
                    <span class="app-icon"><?= appIcon('print') ?></span>
                    Cetak Laporan
                </button>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function () {
    $('.select2-filter').select2({
        width: '100%',
        allowClear: true,
        placeholder: '-- Pilih --'
    });

    function syncFilterBox() {
        const filterBy = $('#filter_by').val();

        $('.filter-value-select').next('.select2-container').hide();
        $('.filter-value-select').hide();

        if (filterBy === 'semua') {
            $('#box_filter_value').hide();
            $('#filter_value').val('');
            return;
        }

        $('#box_filter_value').show();

        let activeSelector = '';
        if (filterBy === 'grup') activeSelector = '#filter_value_grup';
        if (filterBy === 'kota') activeSelector = '#filter_value_kota';
        if (filterBy === 'pelanggan') activeSelector = '#filter_value_pelanggan';

        $(activeSelector).show();
        $(activeSelector).next('.select2-container').show();

        $('#filter_value').val($(activeSelector).val() || '');
    }

    $('#filter_by').on('change', function () {
        $('#filter_value_grup').val('').trigger('change.select2');
        $('#filter_value_kota').val('').trigger('change.select2');
        $('#filter_value_pelanggan').val('').trigger('change.select2');
        syncFilterBox();
    });

    $('.filter-value-select').on('change', function () {
        $('#filter_value').val($(this).val() || '');
    });

    $('#formAging').on('submit', function (e) {
        const reportType = $('input[name="report_type"]:checked').val();
        const filterBy = $('#filter_by').val();

        if (filterBy !== 'semua' && !$('#filter_value').val()) {
            alert('Nilai filter wajib dipilih.');
            e.preventDefault();
            return false;
        }

       if (reportType === 'detail') {
            this.action = 'modul/transaksi/cetak_aging_piutang_detail.php';
        } else {
            this.action = 'modul/transaksi/cetak_aging_piutang_global.php';
        }

    });

    syncFilterBox();
});
</script>