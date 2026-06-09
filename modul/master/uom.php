<?php
// modul/master/uom.php

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

// ----------------------------------------------------
// TAMPILKAN ALERT DARI SESSION (PRG Pattern)
// ----------------------------------------------------
$alert_message = "";
if (isset($_SESSION['alert'])) {
    $alert_message = $_SESSION['alert'];
    unset($_SESSION['alert']);
}



// ----------------------------------------------------
// AUTO GENERATE ID
// ----------------------------------------------------
function generateUomId($conn) {
    $query = mysqli_query($conn, "SELECT uom_id FROM m_uom WHERE uom_id LIKE 'UOM%' ORDER BY uom_id DESC LIMIT 1");
    $row = mysqli_fetch_assoc($query);
    if ($row) {
        $last_num = intval(substr($row['uom_id'], 3));
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    return "UOM" . str_pad($next_num, 3, '0', STR_PAD_LEFT);
}

// ----------------------------------------------------
// LOGIC BACKEND (INSERT, UPDATE, DELETE)
// ----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_form'])) {
    $action_form = $_POST['action_form'];
    $uom_id = mysqli_real_escape_string($conn, trim($_POST['uom_id'] ?? ''));
    $unit = strtoupper(mysqli_real_escape_string($conn, trim($_POST['unit'] ?? ''))); // Uppercase
    $remarks = strtoupper(mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''))); // Uppercase
    $is_active = isset($_POST['is_active']) ? 'Checked' : 'Unchecked';
    
    $user_now = $_SESSION['username'];
    $datetime_now = date('Y-m-d H:i:s');

    if ($action_form == 'insert') {
        // Auto generate ID jika kosong
        if (empty($uom_id)) {
            $uom_id = generateUomId($conn);
        }
        
        $cek = mysqli_query($conn, "SELECT uom_id FROM m_uom WHERE uom_id='$uom_id'");
        if (mysqli_num_rows($cek) > 0) {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Gagal Simpan: UOM ID sudah terdaftar!</div>";
        } else {
            $sql_insert = "INSERT INTO m_uom (uom_id, unit, remarks, is_active, create_user, date_created) 
                           VALUES ('$uom_id', '$unit', '$remarks', '$is_active', '$user_now', '$datetime_now')";
            if (mysqli_query($conn, $sql_insert)) {
                $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data UOM Berhasil Disimpan!</div>";
            } else {
                $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
            }
        }
        
        echo "<script>window.location.href='index.php?page=uom';</script>";
        exit;
        
    } elseif ($action_form == 'update') {
        $sql_update = "UPDATE m_uom SET 
                        unit='$unit', 
                        remarks='$remarks',
                        is_active='$is_active',
                        user_modified='$user_now', 
                        date_modified='$datetime_now' 
                       WHERE uom_id='$uom_id'";
        if (mysqli_query($conn, $sql_update)) {
            $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Perubahan Data UOM Berhasil Disimpan!</div>";
        } else {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
        }
        
        echo "<script>window.location.href='index.php?page=uom';</script>";
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $id_del = mysqli_real_escape_string($conn, $_GET['id']);
    if (mysqli_query($conn, "DELETE FROM m_uom WHERE uom_id='$id_del'")) {
        $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data UOM Berhasil Dihapus!</div>";
    } else {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error Delete: " . mysqli_error($conn) . "</div>";
    }
    echo "<script>window.location.href='index.php?page=uom';</script>";
    exit;
}

// Filter Pencarian
$search_keyword = "";
$where_clause = "";
if (isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE uom_id LIKE '%$search_keyword%' OR unit LIKE '%$search_keyword%' OR remarks LIKE '%$search_keyword%'";
    }
}
?>

<style>
    .btn-vs {
        padding: 8px 20px !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        border-radius: 5px !important;
        transition: all 0.2s ease;
        box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    }
    .btn-vs i { margin-right: 8px; font-size: 14px; }
    .btn-vs:hover { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
    .btn-excel { background: #1d6f42 !important; color: white !important; border: none; }
    .btn-excel:hover { background: #0f5a36 !important; }
    .btn-add { background: #0d6efd !important; color: white !important; border: none; }
    .btn-add:hover { background: #0b5ed7 !important; }
    .btn-edit {
        background: #ffc107 !important;
        color: #000 !important;
        border: none;
        padding: 4px 10px !important;
        font-size: 10px !important;
        border-radius: 3px;
        cursor: pointer;
    }
    .btn-delete {
        background: #dc3545 !important;
        color: white !important;
        border: none;
        padding: 4px 10px !important;
        font-size: 10px !important;
        border-radius: 3px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }
    .btn-edit:hover, .btn-delete:hover { transform: translateY(-1px); }
    
    .table-crystal-report {
        font-family: 'Segoe UI', Tahoma, Arial, sans-serif !important;
        font-size: 11px !important;
        border-collapse: collapse !important;
        width: 100%;
    }
    .table-crystal-report th {
        background-color: #f0f4f8 !important;
        color: #2b4c7e !important;
        font-weight: bold !important;
        text-align: center !important;
        padding: 8px 10px !important;
        border: 1px solid #c0cddb !important;
        white-space: nowrap !important;
    }
    .table-crystal-report td {
        padding: 6px 10px !important;
        border: 1px solid #d3d3d3 !important;
        line-height: 1.2 !important;
        vertical-align: middle !important;
    }
    .table-crystal-report tbody tr:hover { background-color: #e8f2fe !important; }
    .sticky-col-aksi { position: sticky; left: 0; background: white; z-index: 2; }
    .sticky-col-id { position: sticky; left: 90px; background: white; z-index: 2; }
</style>

<!-- HEADER -->
<div class="d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
        <h5 class="fw-bold text-dark m-0">
            <i class="fa fa-balance-scale text-info"></i> Master Satuan (UOM)
        </h5>
        <div class="d-flex gap-2">
            <button class="btn-vs btn-add" onclick="showModalTambah()">
                <i class="fa fa-plus-circle"></i> Tambah UOM
            </button>
        </div>
    </div>
    <?= $alert_message; ?>
</div>

<!-- Form Pencarian -->
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="POST" action="index.php?page=uom" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search_keyword" class="form-control form-control-sm" 
                       placeholder="Cari ID, Unit, atau Remarks..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari Data</button>
                <a href="index.php?page=uom" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Data UOM -->
<div class="table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #c0cddb;">
    <table class="table-crystal-report table-hover">
        <thead>
            <tr>
                <th class="sticky-col-aksi" style="min-width: 100px;">Aksi</th>
                <th class="sticky-col-id" style="min-width: 100px;">UOM ID</th>
                <th style="min-width: 120px;">Unit</th>
                <th style="min-width: 200px;">Remarks</th>
                <th style="min-width: 80px;">Status</th>
                <th style="min-width: 120px;">Create User</th>
                <th style="min-width: 150px;">Date Created</th>
             </tr>
        </thead>
        <tbody>
            <?php
            $query_list = mysqli_query($conn, "SELECT * FROM m_uom $where_clause ORDER BY uom_id ASC");
            if (!$query_list) {
                echo "<tr><td colspan='7' class='text-danger fw-bold p-3'>Error SQL: " . mysqli_error($conn) . "</td></tr>";
            } else if (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='7' class='text-center text-muted py-3'>Tidak ada data UOM ditemukan.</td></tr>";
            } else {
                while ($d = mysqli_fetch_assoc($query_list)) {
                    $status_badge = ($d['is_active'] == 'Checked') ? 'success' : 'danger';
                    $status_text = ($d['is_active'] == 'Checked') ? 'Active' : 'Inactive';
            ?>
            <tr>
                <td class="text-center sticky-col-aksi" style="white-space: nowrap;">
                    <button class="btn-edit" onclick='showModalEdit(<?= json_encode($d); ?>)'>
                        <i class="fa fa-edit"></i> Edit
                    </button>
                    <a href="javascript:void(0)" onclick="confirmDelete('<?= $d['uom_id'] ?>', '<?= addslashes($d['unit']) ?>')" 
                       class="btn-delete">
                        <i class="fa fa-trash"></i> Hapus
                    </a>
                </td>
                <td class="fw-bold text-secondary sticky-col-id"><?= htmlspecialchars($d['uom_id']) ?></td>
                <td class="fw-bold text-primary"><?= strtoupper(htmlspecialchars($d['unit'])) ?></td>
                <td><?= strtoupper(htmlspecialchars($d['remarks'])) ?></td>
                <td class="text-center">
                    <span class="badge bg-<?= $status_badge ?>" style="font-size: 10px;"><?= $status_text ?></span>
                </td>
                <td class="text-center"><?= htmlspecialchars($d['create_user']) ?></td>
                <td class="text-center small"><?= htmlspecialchars($d['date_created']) ?></td>
            </tr>
            <?php 
                }
            } 
            ?>
        </tbody>
    </table>
</div>

<!-- MODAL FORM TAMBAH / EDIT UOM -->
<div class="modal fade d-print-none" id="modalUom" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content" style="border-radius: 10px;">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold" id="modalTitle">Form Master Satuan (UOM)</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formUom" method="POST" action="index.php?page=uom">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">UOM ID</label>
                        <input type="text" name="uom_id" id="form_uom_id" class="form-control form-control-sm" 
                               placeholder="Auto Generate jika dikosongkan">
                        <small class="text-muted">Kosongkan untuk auto generate (UOM001, UOM002, ...)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">Unit / Satuan <span class="text-danger">*</span></label>
                        <input type="text" name="unit" id="form_unit" class="form-control form-control-sm text-uppercase" 
                               style="text-transform: uppercase;" required placeholder="Contoh: KG, PCS, UNIT, MTR">
                        <small class="text-muted">Akan otomatis menjadi huruf besar (uppercase)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">Remarks / Keterangan</label>
                        <input type="text" name="remarks" id="form_remarks" class="form-control form-control-sm text-uppercase" 
                               style="text-transform: uppercase;" placeholder="Contoh: KILOGRAM, PIECES, METER">
                        <small class="text-muted">Akan otomatis menjadi huruf besar (uppercase)</small>
                    </div>
                    
                    <div class="mb-2">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="form_is_active" value="Checked" checked>
                            <label class="form-check-label fw-bold" for="form_is_active">Is Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-3"><i class="fa fa-save"></i> Simpan UOM</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var bootstrapModalUom;

document.addEventListener("DOMContentLoaded", function() {
    const modalElement = document.getElementById('modalUom');
    if (modalElement) {
        bootstrapModalUom = new bootstrap.Modal(modalElement);
    }
    
    // Force uppercase on input fields
    const unitInput = document.getElementById('form_unit');
    const remarksInput = document.getElementById('form_remarks');
    
    if (unitInput) {
        unitInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
    
    if (remarksInput) {
        remarksInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
    }
});

function showModalTambah() {
    document.getElementById('formUom').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Tambah Satuan (UOM) Baru';
    document.getElementById('form_uom_id').readOnly = false;
    document.getElementById('form_uom_id').value = '';
    document.getElementById('form_is_active').checked = true;
    bootstrapModalUom.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formUom').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Satuan (UOM): ' + dataObj.unit;
    document.getElementById('form_uom_id').value = dataObj.uom_id;
    document.getElementById('form_uom_id').readOnly = true;
    document.getElementById('form_unit').value = dataObj.unit || '';
    document.getElementById('form_remarks').value = dataObj.remarks || '';
    document.getElementById('form_is_active').checked = (dataObj.is_active === 'Checked');
    bootstrapModalUom.show();
}

function confirmDelete(id, name) {
    if (confirm('Hapus satuan ' + name + '?')) {
        window.location.href = 'index.php?page=uom&action=delete&id=' + encodeURIComponent(id);
    }
}
</script>