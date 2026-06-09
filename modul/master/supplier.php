<?php
// modul/master/supplier.php

// Proteksi akses langsung ke file modul tanpa melalui index.php
if (!isset($_SESSION['username'])) {
    header("Location: ../../login.php");
    exit;
}
// Include koneksi database
include __DIR__ . '/../../koneksi.php';
// ----------------------------------------------------
// FEATURE 1: PROSES EXPORT EXCEL (NATIVE ALL COLUMNS)
// ----------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
    ob_end_clean();
    header("Content-type: application/vnd-ms-excel");
    header("Content-Disposition: attachment; filename=Master_Supplier_".date('Ymd_His').".xls");
    ?>
    <table border="1">
        <thead>
            <tr style="background-color: #f2f2f2; font-weight: bold;">
                <th>ID</th><th>Supplier</th><th>Contact Person</th><th>NPWP</th><th>Address</th>
                <th>City</th><th>Phone</th><th>Fax</th><th>Credit Limit</th><th>Email</th>
                <th>Old Code</th><th>Area Code</th><th>Remarks</th><th>Type</th><th>No Rekening</th>
                <th>Bank</th><th>Rekening</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $q_excel = mysqli_query($conn, "SELECT * FROM m_supplier ORDER BY id ASC");
            while ($d = mysqli_fetch_assoc($q_excel)) {
                echo "<tr>
                    <td>'".$d['id']."</td>
                    <td>".$d['supplier']."</td>
                    <td>".$d['contact_person']."</td>
                    <td>'".$d['npwp']."</td>
                    <td>".$d['address']."</td>
                    <td>".$d['city']."</td>
                    <td>".$d['phone']."</td>
                    <td>".$d['fax']."</td>
                    <td>".$d['credit_limit']."</td>
                    <td>".$d['email']."</td>
                    <td>".$d['old_code']."</td>
                    <td>".$d['area_code']."</td>
                    <td>".$d['remarks']."</td>
                    <td>".$d['type']."</td>
                    <td>'".$d['no_rekening']."</td>
                    <td>".$d['bank']."</td>
                    <td>".$d['rekening']."</td>
                </tr>";
            }
            ?>
        </tbody>
    </table>
    <?php
    exit;
}

// ----------------------------------------------------
// FEATURE 2: LOGIC BACKEND (INSERT, UPDATE, DELETE)
// ----------------------------------------------------
$alert_message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action_form    = $_POST['action_form'];
    $id             = mysqli_real_escape_string($conn, trim($_POST['id']));
    $supplier       = mysqli_real_escape_string($conn, trim($_POST['supplier']));
    $contact_person = mysqli_real_escape_string($conn, trim($_POST['contact_person']));
    $npwp           = mysqli_real_escape_string($conn, trim($_POST['npwp']));
    $address        = mysqli_real_escape_string($conn, trim($_POST['address']));
    $city           = mysqli_real_escape_string($conn, trim($_POST['city']));
    $phone          = mysqli_real_escape_string($conn, trim($_POST['phone']));
    $fax            = mysqli_real_escape_string($conn, trim($_POST['fax']));
    $credit_limit   = floatval($_POST['credit_limit']);
    $email          = mysqli_real_escape_string($conn, trim($_POST['email']));
    $old_code       = mysqli_real_escape_string($conn, trim($_POST['old_code']));
    $area_code      = mysqli_real_escape_string($conn, trim($_POST['area_code']));
    $remarks        = mysqli_real_escape_string($conn, trim($_POST['remarks']));
    $type           = mysqli_real_escape_string($conn, trim($_POST['type']));
    $no_rekening    = mysqli_real_escape_string($conn, trim($_POST['no_rekening']));
    $bank           = mysqli_real_escape_string($conn, trim($_POST['bank']));
    $rekening       = mysqli_real_escape_string($conn, trim($_POST['rekening']));

    if ($action_form == 'insert') {
        $cek = mysqli_query($conn, "SELECT id FROM m_supplier WHERE id='$id'");
        if (mysqli_num_rows($cek) > 0) {
            $alert_message = "<div class='alert alert-danger p-2 small'>Gagal Simpan: ID Supplier sudah terdaftar!</div>";
        } else {
            $sql_insert = "INSERT INTO m_supplier (id, supplier, contact_person, npwp, address, city, phone, fax, credit_limit, email, old_code, area_code, remarks, type, no_rekening, bank, rekening) 
                           VALUES ('$id', '$supplier', '$contact_person', '$npwp', '$address', '$city', '$phone', '$fax', '$credit_limit', '$email', '$old_code', '$area_code', '$remarks', '$type', '$no_rekening', '$bank', '$rekening')";
            if (mysqli_query($conn, $sql_insert)) {
                $alert_message = "<div class='alert alert-success p-2 small'>Data Supplier Berhasil Disimpan!</div>";
            } else {
                $alert_message = "<div class='alert alert-danger p-2 small'>Error: ".mysqli_error($conn)."</div>";
            }
        }
    } elseif ($action_form == 'update') {
        $sql_update = "UPDATE m_supplier SET 
                        supplier='$supplier', contact_person='$contact_person', npwp='$npwp', address='$address', 
                        city='$city', phone='$phone', fax='$fax', credit_limit='$credit_limit', email='$email', 
                        old_code='$old_code', area_code='$area_code', remarks='$remarks', type='$type', 
                        no_rekening='$no_rekening', bank='$bank', rekening='$rekening' 
                       WHERE id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            $alert_message = "<div class='alert alert-success p-2 small'>Perubahan Data Supplier Berhasil Disimpan!</div>";
        } else {
            $alert_message = "<div class='alert alert-danger p-2 small'>Error: ".mysqli_error($conn)."</div>";
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $id_del = mysqli_real_escape_string($conn, $_GET['id']);
    if (mysqli_query($conn, "DELETE FROM m_supplier WHERE id='$id_del'")) {
        echo "<script>window.location.href='index.php?page=supplier';</script>";
        exit;
    }
}

// Filter Pencarian
$search_keyword = "";
$where_clause = "";
if (isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE id LIKE '%$search_keyword%' OR supplier LIKE '%$search_keyword%' OR city LIKE '%$search_keyword%'";
    }
}
?>

<!-- ---------------------------------------------------- -->
<!-- FEATURE 3: FRONTEND VIEW INTERFACE (ALL COLUMNS)     -->
<!-- ---------------------------------------------------- -->
<div class="d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-1 mb-2 border-bottom">
        <h5 class="fw-bold text-dark m-0"><i class="fa fa-truck text-success"></i> Master Data Supplier</h5>
        <div class="btn-group">
            <button class="btn btn-success btn-sm fw-bold" onclick="window.location.href='index.php?page=supplier&action=export_excel'"><i class="fa fa-file-excel"></i> Export Excel</button>
            <button class="btn btn-secondary btn-sm fw-bold" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
            <button class="btn btn-primary btn-sm fw-bold" onclick="showModalTambah()"><i class="fa fa-plus"></i> Tambah Data</button>
        </div>
    </div>
    <?= $alert_message; ?>
</div>

<div class="d-none d-print-block mb-3">
    <h4 class="fw-bold m-0">PT. MUTIARACAHAYA PLASTINDO</h4>
    <p class="small text-muted m-0">Laporan Master Data Supplier - Tanggal: <?= date('d-m-Y H:i:s') ?></p>
    <hr class="m-1">
</div>

<div class="card shadow-sm mb-2 d-print-none">
    <div class="card-body p-2">
        <form method="POST" action="index.php?page=supplier" class="row g-2 align-items-center">
            <div class="col-auto col-md-4">
                <input type="text" name="search_keyword" class="form-control form-control-sm" placeholder="Cari ID, Supplier, atau Kota..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari Data</button>
                <a href="index.php?page=supplier" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="table-responsive" style="max-height: 550px; overflow-y: auto;">
    <table class="table table-crystal table-hover table-striped w-100 m-0">
        <thead>
            <tr class="text-center">
                <th class="d-print-none" style="position: sticky; left: 0; background: #e9ecef; z-index: 3;">Aksi</th>
                <th style="position: sticky; left: 50px; background: #e9ecef; z-index: 3;">ID</th>
                <th style="position: sticky; left: 105px; background: #e9ecef; z-index: 3;">Supplier</th>
                <th>Contact Person</th>
                <th>NPWP</th>
                <th>Address</th>
                <th>City</th>
                <th>Phone</th>
                <th>Fax</th>
                <th>Credit Limit</th>
                <th>Email</th>
                <th>Old Code</th>
                <th>Area Code</th>
                <th>Remarks</th>
                <th>Type</th>
                <th>No Rekening</th>
                <th>Bank</th>
                <th>Rekening</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query_list = mysqli_query($conn, "SELECT * FROM m_supplier $where_clause ORDER BY id DESC");
            if (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='17' class='text-center text-muted py-3'>Tidak ada data supplier ditemukan.</td></tr>";
            }
            
            while ($d = mysqli_fetch_assoc($query_list)) {
            ?>
            <tr>
                <td class="text-center d-print-none" style="position: sticky; left: 0; background: #fff; z-index: 2; box-shadow: 2px 0 5px rgba(0,0,0,0.05);">
                    <button class="btn btn-micro btn-warning text-dark" onclick='showModalEdit(<?= json_encode($d); ?>)'><i class="fa fa-edit"></i></button>
                    <a href="index.php?page=supplier&action=delete&id=<?= urlencode($d['id']) ?>" class="btn btn-micro btn-danger" onclick="return confirm('Hapus data supplier <?= $d['supplier'] ?>?')"><i class="fa fa-trash"></i></a>
                </td>
                <td class="fw-bold text-secondary" style="position: sticky; left: 50px; background: #fff; z-index: 2; box-shadow: 2px 0 5px rgba(0,0,0,0.05);"><?= htmlspecialchars($d['id']) ?></td>
                <td class="fw-bold text-success" style="position: sticky; left: 105px; background: #fff; z-index: 2; box-shadow: 2px 0 5px rgba(0,0,0,0.05);"><?= htmlspecialchars($d['supplier']) ?></td>
                <td><?= htmlspecialchars($d['contact_person']) ?></td>
                <td><?= htmlspecialchars($d['npwp']) ?></td>
                <td style="white-space: normal; min-width: 250px; font-size:10px;"><?= htmlspecialchars($d['address']) ?></td>
                <td><?= htmlspecialchars($d['city']) ?></td>
                <td><?= htmlspecialchars($d['phone']) ?></td>
                <td><?= htmlspecialchars($d['fax']) ?></td>
                <td class="text-end fw-bold text-danger"><?= number_format($d['credit_limit'], 2) ?></td>
                <td><?= htmlspecialchars($d['email']) ?></td>
                <td><?= htmlspecialchars($d['old_code']) ?></td>
                <td><?= htmlspecialchars($d['area_code']) ?></td>
                <td style="white-space: normal; min-width: 200px; font-size:10px;"><?= htmlspecialchars($d['remarks']) ?></td>
                <td class="text-center"><?= htmlspecialchars($d['type']) ?></td>
                <td><?= htmlspecialchars($d['no_rekening']) ?></td>
                <td><?= htmlspecialchars($d['bank']) ?></td>
                <td><?= htmlspecialchars($d['rekening']) ?></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</div>

<!-- ---------------------------------------------------- -->
<!-- FEATURE 4: MODAL FORM LENGKAP SUPPLIER (BOOTSTRAP 5) -->
<!-- ---------------------------------------------------- -->
<div class="modal fade d-print-none" id="modalSupplier" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="font-size:11px;">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold" id="modalTitle">Form Data Supplier</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formSupplier" method="POST" action="index.php?page=supplier">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <div class="modal-body p-3">
                    
                    <div class="bg-light p-1 mb-2 fw-bold border-start border-success border-3">A. DATA UTAMA PERUSAHAAN</div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold m-0">ID Supplier *</label>
                            <input type="text" name="id" id="form_id" class="form-control form-control-sm" required placeholder="Contoh: S0000001">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold m-0">Nama Supplier *</label>
                            <input type="text" name="supplier" id="form_supplier" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold m-0">Contact Person (CP)</label>
                            <input type="text" name="contact_person" id="form_contact_person" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold m-0">City (Kota)</label>
                            <input type="text" name="city" id="form_city" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold m-0">No. NPWP</label>
                            <input type="text" name="npwp" id="form_npwp" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold m-0">Phone</label>
                            <input type="text" name="phone" id="form_phone" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold m-0">Fax</label>
                            <input type="text" name="fax" id="form_fax" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold m-0">Address (Alamat Lengkap Kantor/Gudang)</label>
                            <textarea name="address" id="form_address" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="bg-light p-1 mb-2 fw-bold border-start border-danger border-3">B. KEUANGAN, PERBANKAN & KLASIFIKASI</div>
                    <div class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label fw-bold m-0">Credit Limit (Plafond Utang)</label>
                            <input type="number" step="0.01" name="credit_limit" id="form_credit_limit" class="form-control form-control-sm" value="0.00">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold m-0">Email</label>
                            <input type="text" name="email" id="form_email" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold m-0">Old Code</label>
                            <input type="text" name="old_code" id="form_old_code" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold m-0">Area Code</label>
                            <input type="text" name="area_code" id="form_area_code" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-bold m-0">Type (Contoh: S)</label>
                            <input type="text" name="type" id="form_type" class="form-control form-control-sm" value="S">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold m-0">No. Rekening Bank</label>
                            <input type="text" name="no_rekening" id="form_no_rekening" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold m-0">Nama Bank (Contoh: Mandiri, BCA)</label>
                            <input type="text" name="bank" id="form_bank" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold m-0">Rekening Atas Nama (A/N)</label>
                            <input type="text" name="rekening" id="form_rekening" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-bold m-0">Remarks (Keterangan Perubahan Nama/Internal)</label>
                            <textarea name="remarks" id="form_remarks" class="form-control form-control-sm" rows="2"></textarea>
                        </div>
                    </div>

                </div>
                <div class="modal-footer py-1">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-save"></i> Simpan Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ---------------------------------------------------- -->
<!-- FEATURE 5: INTERAKSI DOM JAVASCRIPT MODAL             -->
<!-- ---------------------------------------------------- -->
<script>
var bootstrapModalSupplier;

document.addEventListener("DOMContentLoaded", function() {
    bootstrapModalSupplier = new bootstrap.Modal(document.getElementById('modalSupplier'));
});

function showModalTambah() {
    document.getElementById('formSupplier').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerText = 'Tambah Data Supplier Baru';
    document.getElementById('form_id').readOnly = false;
    bootstrapModalSupplier.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formSupplier').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerText = 'Aksi Edit Supplier: ' + dataObj.supplier;
    
    document.getElementById('form_id').value = dataObj.id;
    document.getElementById('form_id').readOnly = true;

    // Distribusi data JSON hasil query database ke form input masing-masing
    document.getElementById('form_supplier').value = dataObj.supplier;
    document.getElementById('form_contact_person').value = dataObj.contact_person;
    document.getElementById('form_npwp').value = dataObj.npwp;
    document.getElementById('form_address').value = dataObj.address;
    document.getElementById('form_city').value = dataObj.city;
    document.getElementById('form_phone').value = dataObj.phone;
    document.getElementById('form_fax').value = dataObj.fax;
    document.getElementById('form_credit_limit').value = dataObj.credit_limit;
    document.getElementById('form_email').value = dataObj.email;
    document.getElementById('form_old_code').value = dataObj.old_code;
    document.getElementById('form_area_code').value = dataObj.area_code;
    document.getElementById('form_remarks').value = dataObj.remarks;
    document.getElementById('form_type').value = dataObj.type;
    document.getElementById('form_no_rekening').value = dataObj.no_rekening;
    document.getElementById('form_bank').value = dataObj.bank;
    document.getElementById('form_rekening').value = dataObj.rekening;

    bootstrapModalSupplier.show();
}
</script>