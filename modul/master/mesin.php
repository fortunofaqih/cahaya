<?php
// modul/master/mesin.php

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

// ----------------------------------------------------
// LOGIC BACKEND (INSERT, UPDATE, DELETE)
// ----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_form'])) {
    $action_form        = $_POST['action_form'];
    $id                 = mysqli_real_escape_string($conn, trim($_POST['mesin_id'] ?? ''));
    $name               = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
    $capacity           = mysqli_real_escape_string($conn, trim($_POST['capacity'] ?? ''));
    $spec               = mysqli_real_escape_string($conn, trim($_POST['spec'] ?? ''));
    
    // Normalisasi Input Tanggal agar tidak bermasalah di database jika kosong
    $manufactured_date  = !empty($_POST['manufactured_date']) ? $_POST['manufactured_date'] : NULL;
    $purchase_date      = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : NULL;
    
    $manufactured_by    = mysqli_real_escape_string($conn, trim($_POST['manufactured_by'] ?? ''));
    $supplier           = mysqli_real_escape_string($conn, trim($_POST['supplier'] ?? ''));
    $purchase_price     = floatval($_POST['purchase_price'] ?? 0);
    $acc_reff           = mysqli_real_escape_string($conn, trim($_POST['acc_reff'] ?? ''));
    $remarks            = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
    $active             = isset($_POST['active']) ? 'Checked' : 'Unchecked';

    if ($action_form == 'insert') {
        // Auto generate ID jika kosong
        if (empty($id)) {
            $id = generateMesinId($conn);
        }
        
        $cek = mysqli_query($conn, "SELECT mesin_id FROM m_mesin WHERE mesin_id='$id'");
        if (mysqli_num_rows($cek) > 0) {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Gagal Simpan: ID Mesin sudah ada!</div>";
        } else {
            // Pengondisian nilai null untuk kolom tipe date
            $m_date_val = $manufactured_date ? "'$manufactured_date'" : "NULL";
            $p_date_val = $purchase_date ? "'$purchase_date'" : "NULL";
            
            $sql_insert = "INSERT INTO m_mesin (mesin_id, name, spec, manufactured_date, manufactured_by, supplier, purchase_price, purchase_date, acc_reff, remarks, active, capacity) 
                           VALUES ('$id', '$name', '$spec', $m_date_val, '$manufactured_by', '$supplier', '$purchase_price', $p_date_val, '$acc_reff', '$remarks', '$active', '$capacity')";
            if (mysqli_query($conn, $sql_insert)) {
                $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Mesin Berhasil Disimpan!</div>";
            } else {
                $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
            }
        }
        
        echo "<script>window.location.href='index.php?page=mesin';</script>";
        exit;
        
    } elseif ($action_form == 'update') {
        $m_date_val = $manufactured_date ? "'$manufactured_date'" : "NULL";
        $p_date_val = $purchase_date ? "'$purchase_date'" : "NULL";
        
        $sql_update = "UPDATE m_mesin SET 
                        name='$name', capacity='$capacity', spec='$spec', manufactured_date=$m_date_val, 
                        manufactured_by='$manufactured_by', supplier='$supplier', purchase_price='$purchase_price', 
                        purchase_date=$p_date_val, acc_reff='$acc_reff', remarks='$remarks', active='$active' 
                       WHERE mesin_id='$id'";
        if (mysqli_query($conn, $sql_update)) {
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
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error Delete: " . mysqli_error($conn) . "</div>";
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
        $where_clause = "WHERE mesin_id LIKE '%$search_keyword%' OR name LIKE '%$search_keyword%' OR capacity LIKE '%$search_keyword%' OR supplier LIKE '%$search_keyword%'";
    }
}
?>

<style>
    /* Crystal Report Style */
    .btn-vs {
        padding: 8px 20px !important;
        font-size: 13px !important;
        font-weight: 600 !important;
        border-radius: 5px !important;
        transition: all 0.2s ease;
        box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    }
    .btn-vs i {
        margin-right: 8px;
        font-size: 14px;
    }
    .btn-vs:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 6px rgba(0,0,0,0.15);
    }
    .btn-excel {
        background: #1d6f42 !important;
        color: white !important;
        border: none;
    }
    .btn-excel:hover {
        background: #0f5a36 !important;
    }
    .btn-add {
        background: #0d6efd !important;
        color: white !important;
        border: none;
    }
    .btn-add:hover {
        background: #0b5ed7 !important;
    }
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
    .btn-edit:hover, .btn-delete:hover {
        transform: translateY(-1px);
    }
    
    /* Tabel Crystal Report Style */
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
    .table-crystal-report tbody tr:hover {
        background-color: #e8f2fe !important;
    }
    .badge-crystal {
        font-size: 9px !important;
        padding: 3px 8px !important;
        border-radius: 10px !important;
    }
    .sticky-col-aksi {
        position: sticky;
        left: 0;
        background: white;
        z-index: 2;
    }
    .sticky-col-id {
        position: sticky;
        left: 90px;
        background: white;
        z-index: 2;
    }
    .sticky-col-name {
        position: sticky;
        left: 195px;
        background: white;
        z-index: 2;
    }
    .text-nowrap {
        white-space: nowrap;
    }
</style>

<!-- ---------------------------------------------------- -->
<!-- FRONTEND VIEW INTERFACE                              -->
<!-- ---------------------------------------------------- -->
<div class="d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
        <h5 class="fw-bold text-dark m-0">
            <i class="fa fa-cogs text-success"></i> Master Data Mesin
        </h5>
        <div class="d-flex gap-2">
            <button class="btn-vs btn-add" onclick="showModalTambah()">
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
                       placeholder="Cari ID, Nama Mesin, Kapasitas, Supplier..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari Data</button>
                <a href="index.php?page=mesin" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Data Mesin - Crystal Report Style -->
<div class="table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #c0cddb;">
    <table class="table-crystal-report table-hover">
        <thead>
            <tr>
                <th class="d-print-none sticky-col-aksi" style="min-width: 100px;">Aksi</th>
                <th class="sticky-col-id" style="min-width: 100px;">ID Mesin</th>
                <th class="sticky-col-name" style="min-width: 200px;">Nama Mesin</th>
                <th style="min-width: 100px;">Kapasitas</th>
                <th style="min-width: 70px;">Status</th>
                <th style="min-width: 200px;">Spesifikasi Teknik</th>
                <th style="min-width: 110px;">Manufactured Date</th>
                <th style="min-width: 120px;">Manufactured By</th>
                <th style="min-width: 150px;">Supplier</th>
                <th style="min-width: 100px;">Harga Beli</th>
                <th style="min-width: 100px;">Tanggal Beli</th>
                <th style="min-width: 100px;">Acc Reff</th>
                <th style="min-width: 150px;">Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query_list = mysqli_query($conn, "SELECT * FROM m_mesin $where_clause ORDER BY mesin_id ASC");
            if (!$query_list) {
                echo "<tr><td colspan='13' class='text-danger fw-bold p-3'>Error SQL: " . mysqli_error($conn) . "</td></tr>";
            } else if (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='13' class='text-center text-muted py-3'>Tidak ada data mesin ditemukan.</td></tr>";
            } else {
                while ($d = mysqli_fetch_assoc($query_list)) {
                    $status_badge = ($d['active'] == 'Checked') ? 'success' : 'danger';
                    $status_text = ($d['active'] == 'Checked') ? 'Aktif' : 'Non-Aktif';
            ?>
            <tr>
                <td class="text-center d-print-none sticky-col-aksi" style="white-space: nowrap;">
                    <button class="btn-edit" onclick='showModalEdit(<?= json_encode($d); ?>)'>
                        <i class="fa fa-edit"></i> Edit
                    </button>
                    <a href="javascript:void(0)" onclick="confirmDelete('<?= $d['mesin_id'] ?>', '<?= addslashes($d['name']) ?>')" 
                       class="btn-delete">
                        <i class="fa fa-trash"></i> Hapus
                    </a>
                </td>
                <td class="fw-bold text-secondary text-center sticky-col-id"><?= htmlspecialchars($d['mesin_id']) ?></td>
                <td class="fw-bold text-info sticky-col-name"><?= htmlspecialchars($d['name']) ?></td>
                <td class="text-center"><?= htmlspecialchars($d['capacity']) ?></td>
                <td class="text-center">
                    <span class="badge bg-<?= $status_badge ?> badge-crystal"><?= $status_text ?></span>
                </td>
                <td style="white-space: normal;"><?= htmlspecialchars($d['spec']) ?></td>
                <td class="text-center"><?= htmlspecialchars($d['manufactured_date']) ?></td>
                <td><?= htmlspecialchars($d['manufactured_by']) ?></td>
                <td><?= htmlspecialchars($d['supplier']) ?></td>
                <td class="text-end fw-bold text-primary"><?= number_format($d['purchase_price'], 2) ?></td>
                <td class="text-center"><?= htmlspecialchars($d['purchase_date']) ?></td>
                <td class="text-center"><?= htmlspecialchars($d['acc_reff']) ?></td>
                <td style="white-space: normal;"><?= htmlspecialchars($d['remarks']) ?></td>
            </tr>
            <?php 
                }
            } 
            ?>
        </tbody>
    </table>
</div>

<!-- ---------------------------------------------------- -->
<!-- MODAL FORM MESIN                                     -->
<!-- ---------------------------------------------------- -->
<div class="modal fade d-print-none" id="modalMesin" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 10px;">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold" id="modalTitle">Form Master Mesin Produksi</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formMesin" method="POST" action="index.php?page=mesin">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <div class="modal-body p-3">
                    
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold m-0">ID / Kode Mesin</label>
                            <input type="text" name="mesin_id" id="form_mesin_id" class="form-control form-control-sm" 
                                   placeholder="Auto Generate jika dikosongkan">
                            <small class="text-muted">Kosongkan untuk auto generate (MSN001, MSN002, ...)</small>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold m-0">Nama Mesin <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="form_name" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold m-0">Capacity</label>
                            <input type="text" name="capacity" id="form_capacity" class="form-control form-control-sm" 
                                   placeholder="Contoh: 500 kg/jam, 200 pcs/mnt">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold m-0">Produsen / Manufactured By</label>
                            <input type="text" name="manufactured_by" id="form_manufactured_by" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold m-0">Tanggal Pembuatan (Mfg Date)</label>
                            <input type="date" name="manufactured_date" id="form_manufactured_date" class="form-control form-control-sm">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold m-0">Supplier</label>
                            <input type="text" name="supplier" id="form_supplier" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold m-0">Purchase Price</label>
                            <input type="number" step="0.01" name="purchase_price" id="form_purchase_price" class="form-control form-control-sm" value="0.00">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold m-0">Purchase Date</label>
                            <input type="date" name="purchase_date" id="form_purchase_date" class="form-control form-control-sm">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold m-0">Accounting Reference Code (Acc Reff)</label>
                            <input type="text" name="acc_reff" id="form_acc_reff" class="form-control form-control-sm" 
                                   placeholder="Kode Aset / Akun Jurnal">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="active" id="form_active" value="Checked" checked>
                                <label class="form-check-label fw-bold" for="form_active">Mesin Siap Operasi (Active)</label>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">Spesifikasi Detail Teknik</label>
                        <textarea name="spec" id="form_spec" class="form-control form-control-sm" rows="3" 
                                  placeholder="Daya listrik (Watt), Tegangan, Dimensi mekanik, dll..."></textarea>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold m-0">Remarks / Catatan Maintenance Awal</label>
                        <textarea name="remarks" id="form_remarks" class="form-control form-control-sm" rows="2"></textarea>
                    </div>

                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-3"><i class="fa fa-save"></i> Simpan Mesin</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ---------------------------------------------------- -->
<!-- JAVASCRIPT                                           -->
<!-- ---------------------------------------------------- -->
<script>
var bootstrapModalMesin;

document.addEventListener("DOMContentLoaded", function() {
    const modalElement = document.getElementById('modalMesin');
    if (modalElement) {
        bootstrapModalMesin = new bootstrap.Modal(modalElement);
    }
});

function showModalTambah() {
    document.getElementById('formMesin').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Tambah Mesin Produksi Baru';
    document.getElementById('form_mesin_id').readOnly = false;
    document.getElementById('form_mesin_id').value = '';
    document.getElementById('form_active').checked = true;
    bootstrapModalMesin.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formMesin').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Mesin: ' + dataObj.name;
    
    document.getElementById('form_mesin_id').value = dataObj.mesin_id;
    document.getElementById('form_mesin_id').readOnly = true;
    document.getElementById('form_name').value = dataObj.name || '';
    document.getElementById('form_capacity').value = dataObj.capacity || '';
    document.getElementById('form_manufactured_by').value = dataObj.manufactured_by || '';
    document.getElementById('form_manufactured_date').value = dataObj.manufactured_date || '';
    document.getElementById('form_supplier').value = dataObj.supplier || '';
    document.getElementById('form_purchase_price').value = dataObj.purchase_price || 0;
    document.getElementById('form_purchase_date').value = dataObj.purchase_date || '';
    document.getElementById('form_acc_reff').value = dataObj.acc_reff || '';
    document.getElementById('form_spec').value = dataObj.spec || '';
    document.getElementById('form_remarks').value = dataObj.remarks || '';
    
    document.getElementById('form_active').checked = (dataObj.active === 'Checked');
    bootstrapModalMesin.show();
}

function confirmDelete(id, name) {
    if (confirm('Hapus mesin ' + name + '?')) {
        window.location.href = 'index.php?page=mesin&action=delete&id=' + encodeURIComponent(id);
    }
}
</script>