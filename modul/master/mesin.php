<?php
// modul/master/mesin.php

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

$alert_message = "";
if (isset($_SESSION['alert'])) {
    $alert_message = $_SESSION['alert'];
    unset($_SESSION['alert']);
}

// Auto generate ID
function generateMesinId($conn) {
    $query = mysqli_query($conn, "SELECT mesin_id FROM m_mesin WHERE mesin_id LIKE 'MSN%' ORDER BY mesin_id DESC LIMIT 1");
    $row = mysqli_fetch_assoc($query);
    if ($row) {
        $last_num = intval(substr($row['mesin_id'], 3));
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    return "MSN" . str_pad($next_num, 3, '0', STR_PAD_LEFT);
}

// Proses Insert, Update, Delete
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['btn_search'])) {
    $action_form = $_POST['action_form'];
    $mesin_id = mysqli_real_escape_string($conn, trim($_POST['mesin_id'] ?? ''));
    $name = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
    $spec = mysqli_real_escape_string($conn, trim($_POST['spec'] ?? ''));
    $manufactured_date = !empty($_POST['manufactured_date']) ? mysqli_real_escape_string($conn, trim($_POST['manufactured_date'])) : NULL;
    $manufactured_by = mysqli_real_escape_string($conn, trim($_POST['manufactured_by'] ?? ''));
    $supplier = mysqli_real_escape_string($conn, trim($_POST['supplier'] ?? ''));
    $purchase_price = floatval($_POST['purchase_price'] ?? 0);
    $purchase_date = !empty($_POST['purchase_date']) ? mysqli_real_escape_string($conn, trim($_POST['purchase_date'])) : NULL;
    $acc_reff = mysqli_real_escape_string($conn, trim($_POST['acc_reff'] ?? ''));
    $remarks = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    $capacity = mysqli_real_escape_string($conn, trim($_POST['capacity'] ?? ''));
    $active = isset($_POST['active']) ? 'Checked' : 'Unchecked';
    
    $user_now = $_SESSION['username'];
    $datetime_now = date('Y-m-d H:i:s');

    if ($action_form == 'insert') {
        if (empty($mesin_id)) {
            $mesin_id = generateMesinId($conn);
        }
        
        $cek = mysqli_query($conn, "SELECT mesin_id FROM m_mesin WHERE mesin_id='$mesin_id'");
        if (mysqli_num_rows($cek) > 0) {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Gagal Simpan: ID Mesin sudah terdaftar!</div>";
        } else {
            $sql = "INSERT INTO m_mesin (mesin_id, name, spec, manufactured_date, manufactured_by, 
                    supplier, purchase_price, purchase_date, acc_reff, remarks, active, capacity) 
                    VALUES ('$mesin_id', '$name', '$spec', " . ($manufactured_date ? "'$manufactured_date'" : "NULL") . ", 
                    '$manufactured_by', '$supplier', '$purchase_price', " . ($purchase_date ? "'$purchase_date'" : "NULL") . ", 
                    '$acc_reff', '$remarks', '$active', '$capacity')";
            if (mysqli_query($conn, $sql)) {
                $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Mesin Berhasil Disimpan!</div>";
            } else {
                $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
            }
        }
        echo "<script>window.location.href='index.php?page=mesin';</script>";
        exit;
        
    } elseif ($action_form == 'update') {
        $sql = "UPDATE m_mesin SET 
                name='$name', spec='$spec', manufactured_date=" . ($manufactured_date ? "'$manufactured_date'" : "NULL") . ",
                manufactured_by='$manufactured_by', supplier='$supplier', purchase_price='$purchase_price',
                purchase_date=" . ($purchase_date ? "'$purchase_date'" : "NULL") . ", acc_reff='$acc_reff',
                remarks='$remarks', active='$active', capacity='$capacity'
                WHERE mesin_id='$mesin_id'";
        if (mysqli_query($conn, $sql)) {
            $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Perubahan Data Mesin Berhasil Disimpan!</div>";
        } else {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
        }
        echo "<script>window.location.href='index.php?page=mesin';</script>";
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $id_del = mysqli_real_escape_string($conn, $_GET['id']);
    if (mysqli_query($conn, "DELETE FROM m_mesin WHERE mesin_id='$id_del'")) {
        $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Mesin Berhasil Dihapus!</div>";
    } else {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
    }
    echo "<script>window.location.href='index.php?page=mesin';</script>";
    exit;
}

// Filter Pencarian
$search_keyword = "";
$where_clause = "";
if (isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE mesin_id LIKE '%$search_keyword%' OR name LIKE '%$search_keyword%' OR spec LIKE '%$search_keyword%'";
    }
}
?>

<style>
    .style-crystal { font-family: "Segoe UI", Tahoma, Arial, sans-serif !important; font-size: 11px !important; }
    .table-crystal-report { font-family: "Segoe UI", Tahoma, Arial, sans-serif !important; font-size: 11px !important; border-collapse: collapse !important; width: 100%; }
    .table-crystal-report th { background-color: #f0f4f8 !important; color: #2b4c7e !important; font-weight: bold !important; text-align: center !important; padding: 6px 8px !important; border: 1px solid #c0cddb !important; white-space: nowrap !important; }
    .table-crystal-report td { padding: 4px 8px !important; border: 1px solid #d3d3d3 !important; line-height: 1.2 !important; vertical-align: middle !important; }
    .table-crystal-report tbody tr:hover { background-color: #e8f2fe !important; }
    .btn-micro { padding: 0px 5px !important; font-size: 10px !important; margin: 0 2px; }
    .badge-active { background-color: #28a745 !important; }
    .badge-inactive { background-color: #dc3545 !important; }
</style>

<div class="style-crystal d-print-none">
    <div class="d-flex justify-content-between flex-wrap align-items-center pt-1 pb-2 mb-3 border-bottom">
        <h5 class="fw-bold text-dark m-0"><i class="fa fa-gear text-primary"></i> Master Data Mesin</h5>
        <div class="btn-group gap-2">
            <button class="btn btn-success fw-bold" style="padding: 6px 14px; font-size: 12px;" onclick="window.location.href='modul/master/export_mesin.php'">
                <i class="fa fa-file-excel-o"></i> Export Excel
            </button>
            <button class="btn btn-info fw-bold text-white" style="padding: 6px 14px; font-size: 12px;" onclick="window.location.href='modul/master/import_mesin.php'">
                <i class="fa fa-upload"></i> Import CSV
            </button>
            <button class="btn btn-primary fw-bold" style="padding: 6px 14px; font-size: 12px;" onclick="showModalTambah()">
                <i class="fa fa-plus-circle"></i> Tambah Mesin
            </button>
        </div>
    </div>
    <?= $alert_message; ?>
</div>

<!-- Form Pencarian -->
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="POST" action="index.php?page=mesin" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search_keyword" class="form-control form-control-sm" 
                       placeholder="Cari ID, Nama, atau Spesifikasi Mesin..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari</button>
                <a href="index.php?page=mesin" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Data Mesin -->
<div class="table-responsive" style="max-height: 540px; overflow-y: auto; border: 1px solid #c0cddb;">
    <table class="table-crystal-report table-hover">
        <thead>
            <tr>
                <th class="d-print-none" style="position: sticky; left: 0; background: #f0f4f8; z-index: 3; min-width: 80px;">Aksi</th>
                <th style="position: sticky; left: 80px; background: #f0f4f8; z-index: 3; min-width: 100px;">ID Mesin</th>
                <th style="position: sticky; left: 180px; background: #f0f4f8; z-index: 3; min-width: 200px;">Nama Mesin</th>
                <th>Spesifikasi</th>
                <th>Tgl Manufacture</th>
                <th>Manufactured By</th>
                <th>Supplier</th>
                <th>Harga Beli</th>
                <th>Tgl Beli</th>
                <th>ACC Reff</th>
                <th>Kapasitas</th>
                <th>Keterangan</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query_list = mysqli_query($conn, "SELECT * FROM m_mesin $where_clause ORDER BY mesin_id ASC");
            if (!$query_list) {
                echo "<tr><td colspan='13' class='text-danger'>Error: " . mysqli_error($conn) . "</td></tr>";
            } elseif (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='13' class='text-center text-muted'>Tidak ada data mesin ditemukan.</td></tr>";
            } else {
                while ($d = mysqli_fetch_assoc($query_list)) {
                    $status_class = ($d['active'] == 'Checked') ? 'badge-active' : 'badge-inactive';
                    $status_text = ($d['active'] == 'Checked') ? 'Active' : 'Inactive';
            ?>
                <tr>
                    <td class="text-center d-print-none" style="position: sticky; left: 0; background: #fff; z-index: 2;">
                        <button class="btn btn-micro btn-warning text-dark" onclick='showModalEdit(<?= json_encode($d); ?>)'><i class="fa fa-edit"></i></button>
                        <a href="index.php?page=mesin&action=delete&id=<?= urlencode($d['mesin_id']) ?>" class="btn btn-micro btn-danger" onclick="return confirm('Hapus mesin <?= addslashes($d['name']) ?>?')"><i class="fa fa-trash"></i></a>
                    </td>
                    <td class="fw-bold text-secondary" style="position: sticky; left: 80px; background: #fff; z-index: 2;"><?= htmlspecialchars($d['mesin_id']) ?></td>
                    <td class="fw-bold text-primary" style="position: sticky; left: 180px; background: #fff; z-index: 2;"><?= htmlspecialchars($d['name']) ?></td>
                    <td><?= htmlspecialchars($d['spec']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($d['manufactured_date']) ?></td>
                    <td><?= htmlspecialchars($d['manufactured_by']) ?></td>
                    <td><?= htmlspecialchars($d['supplier']) ?></td>
                    <td class="text-end"><?= number_format($d['purchase_price'], 2) ?></td>
                    <td class="text-center"><?= htmlspecialchars($d['purchase_date']) ?></td>
                    <td><?= htmlspecialchars($d['acc_reff']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($d['capacity']) ?></td>
                    <td><?= htmlspecialchars($d['remarks']) ?></td>
                    <td class="text-center"><span class="badge <?= $status_class ?>"><?= $status_text ?></span></td>
                </tr>
            <?php 
                }
            } 
            ?>
        </tbody>
    </table>
</div>

<!-- Modal Form Mesin -->
<div class="modal fade d-print-none" id="modalMesin" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title fw-bold" id="modalTitle">Form Master Mesin</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formMesin" method="POST" action="index.php?page=mesin">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label fw-bold">ID Mesin</label>
                                <input type="text" name="mesin_id" id="form_mesin_id" class="form-control form-control-sm" placeholder="Auto Generate jika kosong">
                                <small class="text-muted">Kosongkan untuk auto generate (MSN001, MSN002, ...)</small>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold">Nama Mesin *</label>
                                <input type="text" name="name" id="form_name" class="form-control form-control-sm" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold">Spesifikasi</label>
                                <input type="text" name="spec" id="form_spec" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold">Tanggal Manufacture</label>
                                <input type="date" name="manufactured_date" id="form_manufactured_date" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold">Dibuat Oleh</label>
                                <input type="text" name="manufactured_by" id="form_manufactured_by" class="form-control form-control-sm">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label fw-bold">Supplier</label>
                                <input type="text" name="supplier" id="form_supplier" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold">Harga Beli</label>
                                <input type="number" step="0.01" name="purchase_price" id="form_purchase_price" class="form-control form-control-sm text-end" value="0">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold">Tanggal Beli</label>
                                <input type="date" name="purchase_date" id="form_purchase_date" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold">ACC Reff</label>
                                <input type="text" name="acc_reff" id="form_acc_reff" class="form-control form-control-sm">
                            </div>
                            <div class="mb-2">
                                <label class="form-label fw-bold">Kapasitas</label>
                                <input type="text" name="capacity" id="form_capacity" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold">Keterangan</label>
                        <textarea name="remarks" id="form_remarks" class="form-control form-control-sm" rows="2"></textarea>
                    </div>
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="active" id="form_active" value="Checked" checked>
                            <label class="form-check-label fw-bold" for="form_active">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-3"><i class="fa fa-save"></i> Simpan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var bootstrapModal;

document.addEventListener("DOMContentLoaded", function() {
    bootstrapModal = new bootstrap.Modal(document.getElementById('modalMesin'));
});

function showModalTambah() {
    document.getElementById('formMesin').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Tambah Mesin Baru';
    document.getElementById('form_mesin_id').readOnly = false;
    document.getElementById('form_mesin_id').value = '';
    document.getElementById('form_active').checked = true;
    bootstrapModal.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formMesin').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Mesin: ' + dataObj.name;
    document.getElementById('form_mesin_id').value = dataObj.mesin_id;
    document.getElementById('form_mesin_id').readOnly = true;
    document.getElementById('form_name').value = dataObj.name;
    document.getElementById('form_spec').value = dataObj.spec;
    document.getElementById('form_manufactured_date').value = dataObj.manufactured_date;
    document.getElementById('form_manufactured_by').value = dataObj.manufactured_by;
    document.getElementById('form_supplier').value = dataObj.supplier;
    document.getElementById('form_purchase_price').value = dataObj.purchase_price;
    document.getElementById('form_purchase_date').value = dataObj.purchase_date;
    document.getElementById('form_acc_reff').value = dataObj.acc_reff;
    document.getElementById('form_remarks').value = dataObj.remarks;
    document.getElementById('form_capacity').value = dataObj.capacity;
    document.getElementById('form_active').checked = (dataObj.active === 'Checked');
    bootstrapModal.show();
}
</script>