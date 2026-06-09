<?php
// modul/master/category.php

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
// LOGIC BACKEND (INSERT, UPDATE, DELETE)
// ----------------------------------------------------

// Auto generate ID
function generateCategoryId($conn) {
    $query = mysqli_query($conn, "SELECT categori_id FROM m_category WHERE categori_id LIKE 'CAT%' ORDER BY categori_id DESC LIMIT 1");
    $row = mysqli_fetch_assoc($query);
    if ($row) {
        $last_num = intval(substr($row['categori_id'], 3));
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    return "CAT" . str_pad($next_num, 3, '0', STR_PAD_LEFT);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_form'])) {
    $action_form    = $_POST['action_form'];
    $id             = mysqli_real_escape_string($conn, trim($_POST['categori_id'] ?? ''));
    $name           = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
    $parent_code    = mysqli_real_escape_string($conn, trim($_POST['parent_code'] ?? ''));
    $type           = mysqli_real_escape_string($conn, trim($_POST['type'] ?? ''));
    $remarks        = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));

    if (empty($parent_code)) {
        $parent_code = "";
    }

    if ($action_form == 'insert') {
        // Auto generate ID jika kosong
        if (empty($id)) {
            $id = generateCategoryId($conn);
        }
        
        $cek = mysqli_query($conn, "SELECT categori_id FROM m_category WHERE categori_id='$id'");
        if (mysqli_num_rows($cek) > 0) {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Gagal Simpan: ID Kategori sudah digunakan!</div>";
        } else {
            $sql_insert = "INSERT INTO m_category (categori_id, name, parent_code, type, remarks) 
                           VALUES ('$id', '$name', '$parent_code', '$type', '$remarks')";
            if (mysqli_query($conn, $sql_insert)) {
                $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Kategori Berhasil Disimpan!</div>";
            } else {
                $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
            }
        }
        
        echo "<script>window.location.href='index.php?page=category';</script>";
        exit;
        
    } elseif ($action_form == 'update') {
        $sql_update = "UPDATE m_category SET 
                        name='$name', parent_code='$parent_code', type='$type', remarks='$remarks' 
                       WHERE categori_id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Perubahan Data Kategori Berhasil Disimpan!</div>";
        } else {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
        }
        
        echo "<script>window.location.href='index.php?page=category';</script>";
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $id_del = mysqli_real_escape_string($conn, $_GET['categori_id']);
    if (mysqli_query($conn, "DELETE FROM m_category WHERE categori_id='$id_del'")) {
        $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Kategori Berhasil Dihapus!</div>";
    } else {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error Delete: " . mysqli_error($conn) . "</div>";
    }
    echo "<script>window.location.href='index.php?page=category';</script>";
    exit;
}

// Filter Pencarian Data
$search_keyword = "";
$where_clause = "";
if (isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE categori_id LIKE '%$search_keyword%' OR name LIKE '%$search_keyword%' OR type LIKE '%$search_keyword%' OR parent_code LIKE '%$search_keyword%'";
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
    }
    .btn-delete {
        background: #dc3545 !important;
        color: white !important;
        border: none;
        padding: 4px 10px !important;
        font-size: 10px !important;
        border-radius: 3px;
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
        padding: 6px 8px !important;
        border: 1px solid #c0cddb !important;
        white-space: nowrap !important;
    }
    .table-crystal-report td {
        padding: 4px 8px !important;
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
</style>

<!-- ---------------------------------------------------- -->
<!-- FRONTEND VIEW INTERFACE                              -->
<!-- ---------------------------------------------------- -->
<div class="d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
        <h5 class="fw-bold text-dark m-0">
            <i class="fa fa-tags text-warning"></i> Master Data Kategori
        </h5>
        <div class="d-flex gap-2">
            <button class="btn-vs btn-add" onclick="showModalTambah()">
                <i class="fa fa-plus-circle"></i> Tambah Kategori
            </button>
        </div>
    </div>
    <?= $alert_message; ?>
</div>

<!-- Form Pencarian -->
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="POST" action="index.php?page=category" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search_keyword" class="form-control form-control-sm" 
                       placeholder="Cari ID, Nama Kategori, Tipe, atau Induk..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari Data</button>
                <a href="index.php?page=category" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Data Kategori - Crystal Report Style -->
<div class="table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #c0cddb;">
    <table class="table-crystal-report table-hover">
        <thead>
            <tr>
                <th class="d-print-none" style="position: sticky; left: 0; background: #f0f4f8; z-index: 3; width: 90px;">Aksi</th>
                <th style="position: sticky; left: 90px; background: #f0f4f8; z-index: 3; width: 120px;">ID Kategori</th>
                <th style="min-width: 200px;">Nama Kategori</th>
                <th style="min-width: 100px;">Type</th>
                <th style="min-width: 120px;">Parent Code (Induk)</th>
                <th style="min-width: 200px;">Remarks / Catatan</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query_list = mysqli_query($conn, "SELECT * FROM m_category $where_clause ORDER BY categori_id ASC");
            if (!$query_list) {
                echo "<tr><td colspan='6' class='text-danger fw-bold p-3'>Error SQL: " . mysqli_error($conn) . "</td></tr>";
            } else if (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='6' class='text-center text-muted py-3'>Tidak ada data kategori ditemukan.</td></tr>";
            } else {
                while ($d = mysqli_fetch_assoc($query_list)) {
            ?>
            <tr>
                <td class="text-center d-print-none" style="position: sticky; left: 0; background: #fff; z-index: 2; white-space: nowrap;">
                    <button class="btn-edit" onclick='showModalEdit(<?= json_encode($d); ?>)'>
                        <i class="fa fa-edit"></i> Edit
                    </button>
                    <a href="index.php?page=category&action=delete&categori_id=<?= urlencode($d['categori_id']) ?>" 
                       class="btn-delete" 
                       onclick="return confirm('Hapus kategori <?= addslashes($d['name']) ?>?')">
                        <i class="fa fa-trash"></i> Hapus
                    </a>
                </td>
                <td class="fw-bold text-secondary" style="position: sticky; left: 90px; background: #fff; z-index: 2;"><?= htmlspecialchars($d['categori_id']) ?></td>
                <td class="fw-bold text-dark"><?= htmlspecialchars($d['name']) ?></td>
                <td >
                    <span class="badge bg-info text-dark badge-crystal"><?= htmlspecialchars($d['type']) ?></span>
                </td>
                <td >
                    <?= empty($d['parent_code']) ? '<span class="text-muted">- Utama -</span>' : '<span class="fw-bold text-primary">' . htmlspecialchars($d['parent_code']) . '</span>' ?>
                </td>
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
<!-- MODAL FORM KATEGORI                                  -->
<!-- ---------------------------------------------------- -->
<div class="modal fade d-print-none" id="modalCategory" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content" style="border-radius: 10px;">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold" id="modalTitle">Form Master Kategori</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formCategory" method="POST" action="index.php?page=category">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <div class="modal-body p-3">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">ID / Kode Kategori</label>
                        <input type="text" name="categori_id" id="form_categori_id" class="form-control form-control-sm" 
                               placeholder="Auto Generate jika dikosongkan">
                        <small class="text-muted">Kosongkan untuk auto generate (CAT001, CAT002, ...)</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">Nama Kategori <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="form_name" class="form-control form-control-sm" required 
                               placeholder="Contoh: Raw Material, Bahan Pembantu">
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">Type / Aliran Klasifikasi</label>
                        <input type="text" name="type" id="form_type" class="form-control form-control-sm" 
                               placeholder="Contoh: RM, WIP, FG, ASSET">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">Parent Code</label>
                        <select name="parent_code" id="form_parent_code" class="form-select form-select-sm">
                            <option value="">-- Tanpa Induk (Kategori Utama) --</option>
                            <option value="HD KRESEK">HD KRESEK</option>
                            <option value="HD">HD</option>
                            <option value="HD WARNA">HD WARNA</option>
                            <option value="HD SABLON">HD SABLON</option>
                            <option value="KERTAS GRP">KERTAS GRP</option>
                            <option value="PE">PE</option>
                            <option value="PE WARNA">PE WARNA</option>
                            <option value="PP">PP</option>
                            <option value="SABLON">SABLON</option>
                            <option value="SEDOTAN">SEDOTAN</option>
                            <option value="SLONTONG">SLONTONG</option>
                            <option value="TALI KILO">TALI KILO</option>
                            <?php
                            // Mengambil referensi list untuk pilihan parent
                            $q_parent = mysqli_query($conn, "SELECT categori_id, name FROM m_category ORDER BY categori_id ASC");
                            while ($d_parent = mysqli_fetch_assoc($q_parent)) {
                                echo "<option value='" . htmlspecialchars($d_parent['categori_id']) . "'>" . htmlspecialchars($d_parent['categori_id']) . " - " . htmlspecialchars($d_parent['name']) . "</option>";
                            }
                            ?>
                        </select>
                        <div class="form-text text-muted" style="font-size: 10px;">Gunakan kolom ini jika kategori ini merupakan turunan/sub dari kategori lain.</div>
                    </div>

                    <div class="mb-2">
                        <label class="form-label fw-bold m-0">Remarks / Keterangan Tambahan</label>
                        <textarea name="remarks" id="form_remarks" class="form-control form-control-sm" rows="3" 
                                  placeholder="Tambahkan deskripsi cakupan kategori jika ada..."></textarea>
                    </div>

                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-3"><i class="fa fa-save"></i> Simpan Kategori</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ---------------------------------------------------- -->
<!-- JAVASCRIPT                                           -->
<!-- ---------------------------------------------------- -->
<script>
var bootstrapModalCategory;

document.addEventListener("DOMContentLoaded", function() {
    const modalElement = document.getElementById('modalCategory');
    if (modalElement) {
        bootstrapModalCategory = new bootstrap.Modal(modalElement);
    }
});

function showModalTambah() {
    document.getElementById('formCategory').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Tambah Kategori Baru';
    document.getElementById('form_categori_id').readOnly = false;
    document.getElementById('form_categori_id').value = '';
    bootstrapModalCategory.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formCategory').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Kategori: ' + dataObj.name;
    
    document.getElementById('form_categori_id').value = dataObj.categori_id;
    document.getElementById('form_categori_id').readOnly = true;
    document.getElementById('form_name').value = dataObj.name;
    document.getElementById('form_type').value = dataObj.type || '';
    document.getElementById('form_parent_code').value = dataObj.parent_code || '';
    document.getElementById('form_remarks').value = dataObj.remarks || '';

    bootstrapModalCategory.show();
}
</script>