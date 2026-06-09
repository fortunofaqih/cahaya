<?php
// modul/master/gudang.php

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
function generateGudangId($conn) {
    $query = mysqli_query($conn, "SELECT gudang_id FROM m_gudang WHERE gudang_id LIKE 'FC-%' ORDER BY CAST(SUBSTRING(gudang_id, 4) AS UNSIGNED) DESC LIMIT 1");
    $row = mysqli_fetch_assoc($query);
    if ($row) {
        $last_num = intval(substr($row['gudang_id'], 3)); // ambil angka setelah "FC-"
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    return "FC-" . str_pad($next_num, 2, '0', STR_PAD_LEFT);
}

// ----------------------------------------------------
// LOGIC BACKEND (INSERT, UPDATE, DELETE)
// ----------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_form'])) {
    $action_form = $_POST['action_form'];
    $id          = mysqli_real_escape_string($conn, trim($_POST['id'] ?? ''));
    $name        = mysqli_real_escape_string($conn, trim($_POST['name'] ?? ''));
    $station     = mysqli_real_escape_string($conn, trim($_POST['station'] ?? ''));

    if ($action_form == 'insert') {
        // Auto generate ID jika kosong
        if (empty($id)) {
            $id = generateGudangId($conn);
        }
        
        $cek = mysqli_query($conn, "SELECT gudang_id FROM m_gudang WHERE gudang_id='$id'");
        if (mysqli_num_rows($cek) > 0) {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Gagal Simpan: ID Gudang sudah terdaftar!</div>";
        } else {
            $sql_insert = "INSERT INTO m_gudang (gudang_id, name, station) VALUES ('$id', '$name', '$station')";
            if (mysqli_query($conn, $sql_insert)) {
                $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Gudang Berhasil Disimpan!</div>";
            } else {
                $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
            }
        }
        
        echo "<script>window.location.href='index.php?page=gudang';</script>";
        exit;
        
    } elseif ($action_form == 'update') {
        $sql_update = "UPDATE m_gudang SET name='$name', station='$station' WHERE gudang_id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Perubahan Data Gudang Berhasil Disimpan!</div>";
        } else {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
        }
        
        echo "<script>window.location.href='index.php?page=gudang';</script>";
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $id_del = mysqli_real_escape_string($conn, $_GET['id']);
    if (mysqli_query($conn, "DELETE FROM m_gudang WHERE gudang_id='$id_del'")) {
        $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Gudang Berhasil Dihapus!</div>";
    } else {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error Delete: " . mysqli_error($conn) . "</div>";
    }
    echo "<script>window.location.href='index.php?page=gudang';</script>";
    exit;
}

// Filter Pencarian
$search_keyword = "";
$where_clause = "";
if (isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE gudang_id LIKE '%$search_keyword%' OR name LIKE '%$search_keyword%' OR station LIKE '%$search_keyword%'";
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
        padding: 8px 12px !important;
        border: 1px solid #c0cddb !important;
        white-space: nowrap !important;
    }
    .table-crystal-report td {
        padding: 6px 12px !important;
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
</style>

<!-- ---------------------------------------------------- -->
<!-- FRONTEND VIEW INTERFACE                              -->
<!-- ---------------------------------------------------- -->
<div class="d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
        <h5 class="fw-bold text-dark m-0">
            <i class="fa fa-warehouse text-primary"></i> Master Data Gudang (Warehouse)
        </h5>
        <div class="d-flex gap-2">
            <button class="btn-vs btn-add" onclick="showModalTambah()">
                <i class="fa fa-plus-circle"></i> Tambah Gudang
            </button>
        </div>
    </div>
    <?= $alert_message; ?>
</div>

<!-- Form Pencarian -->
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="POST" action="index.php?page=gudang" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search_keyword" class="form-control form-control-sm" 
                       placeholder="Cari ID, Nama Gudang, Station..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari Data</button>
                <a href="index.php?page=gudang" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Data Gudang - Crystal Report Style -->
<div class="table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #c0cddb;">
    <table class="table-crystal-report table-hover">
        <thead>
            <tr>
                <th class="d-print-none sticky-col-aksi" style="min-width: 100px;">Aksi</th>
                <th style="min-width: 120px;">ID Gudang</th>
                <th style="min-width: 200px;">Nama Gudang</th>
                <th style="min-width: 200px;">Station / Area Kerja</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query_list = mysqli_query($conn, "SELECT * FROM m_gudang $where_clause ORDER BY gudang_id ASC");
            if (!$query_list) {
                echo "<tr><td colspan='4' class='text-danger fw-bold p-3'>Error SQL: " . mysqli_error($conn) . "</td></tr>";
            } else if (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='4' class='text-center text-muted py-3'>Tidak ada data gudang ditemukan.</td></tr>";
            } else {
                while ($d = mysqli_fetch_assoc($query_list)) {
            ?>
            <tr>
                <td class="text-center d-print-none sticky-col-aksi" style="white-space: nowrap;">
                    <button class="btn-edit" onclick='showModalEdit(<?= json_encode($d); ?>)'>
                        <i class="fa fa-edit"></i> Edit
                    </button>
                    <a href="javascript:void(0)" onclick="confirmDelete('<?= $d['gudang_id'] ?>', '<?= addslashes($d['name']) ?>')" 
                       class="btn-delete">
                        <i class="fa fa-trash"></i> Hapus
                    </a>
                </td>
                <td class="fw-bold text-secondary text-center"><?= htmlspecialchars($d['gudang_id']) ?></td>
                <td class="fw-bold text-dark"><?= htmlspecialchars($d['name']) ?></td>
                <td><?= htmlspecialchars($d['station']) ?></td>
            </tr>
            <?php 
                }
            } 
            ?>
        </tbody>
    </table>
</div>

<!-- ---------------------------------------------------- -->
<!-- MODAL FORM GUDANG                                    -->
<!-- ---------------------------------------------------- -->
<div class="modal fade d-print-none" id="modalGudang" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-md">
        <div class="modal-content" style="border-radius: 10px;">
            <div class="modal-header bg-dark text-white py-2">
                <h6 class="modal-title fw-bold" id="modalTitle">Form Master Gudang</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formGudang" method="POST" action="index.php?page=gudang">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <div class="modal-body p-3">
                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">ID / Kode Gudang</label>
                        <input type="text" name="id" id="form_id" class="form-control form-control-sm" 
                               placeholder="Auto Generate jika dikosongkan">
                        <small class="text-muted">Kosongkan untuk auto generate (GD001, GD002, ...)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold m-0">Nama Gudang <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="form_name" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold m-0">Station / Lokasi Area</label>
                        <input type="text" name="station" id="form_station" class="form-control form-control-sm" 
                               placeholder="Contoh: Extrusion Area, Bag Making, Gudang Depan">
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold px-3"><i class="fa fa-save"></i> Simpan Gudang</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ---------------------------------------------------- -->
<!-- JAVASCRIPT                                           -->
<!-- ---------------------------------------------------- -->
<script>
var bootstrapModalGudang;

document.addEventListener("DOMContentLoaded", function() {
    const modalElement = document.getElementById('modalGudang');
    if (modalElement) {
        bootstrapModalGudang = new bootstrap.Modal(modalElement);
    }
});

function showModalTambah() {
    document.getElementById('formGudang').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Tambah Gudang Baru';
    document.getElementById('form_id').readOnly = false;
    document.getElementById('form_id').value = '';
    bootstrapModalGudang.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formGudang').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Gudang: ' + dataObj.name;
    document.getElementById('form_id').value = dataObj.gudang_id;
    document.getElementById('form_id').readOnly = true;
    document.getElementById('form_name').value = dataObj.name;
    document.getElementById('form_station').value = dataObj.station || '';
    bootstrapModalGudang.show();
}

function confirmDelete(id, name) {
    if (confirm('Hapus gudang ' + name + '?')) {
        window.location.href = 'index.php?page=gudang&action=delete&id=' + encodeURIComponent(id);
    }
}
</script>