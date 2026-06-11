<?php
// modul/master/area.php

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
// PROSES EXPORT EXCEL
// ----------------------------------------------------
//if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
  //  if (ob_get_level()) ob_end_clean();
   // header("Content-Type: application/vnd.ms-excel");
   // header("Content-Disposition: attachment; filename=Master_Area_" . date('Ymd_His') . ".xls");
   // header("Cache-Control: no-cache, must-revalidate");
    ?>
   <!-- <table border="1">
   //     <thead>
   //         <tr style="background-color: #f2f2f2; font-weight: bold;">
   //             <th>ID</th>
   //             <th>Area</th>
   //             <th>Area Description</th>
   //             <th>Kode</th>
   //             <th>Create User</th>
   //             <th>Date Created</th>
   //             <th>User Modified</th>
   //             <th>Date Modified</th>
   //         </tr>
   //     </thead>
   //     <tbody>
            <?php
   //         $q_excel = mysqli_query($conn, "SELECT * FROM m_area ORDER BY area ASC");
   //         while ($d = mysqli_fetch_assoc($q_excel)) {
   //             echo "<tr>";
   //             echo "<td>" . htmlspecialchars($d['id']) . "</td>";
   //             echo "<td>" . htmlspecialchars($d['area']) . "</td>";
   //             echo "<td>" . htmlspecialchars($d['area_description']) . "</td>";
   //             echo "<td>" . htmlspecialchars($d['kode']) . "</td>";
   //             echo "<td>" . htmlspecialchars($d['user_created']) . "</td>";
   //             echo "<td>" . htmlspecialchars($d['date_created']) . "</td>";
   //             echo "<td>" . htmlspecialchars($d['user_modified']) . "</td>";
   //             echo "<td>" . htmlspecialchars($d['date_modified']) . "</td>";
   //             echo "</tr>";
   //         }
            ?>
   //     </tbody>
   // </table>-->
    <?php
   // exit;
//}

// ----------------------------------------------------
// LOGIC BACKEND (INSERT, UPDATE, DELETE)
// ----------------------------------------------------

// Auto generate kode area
function generateAreaKode($conn) {
    $query = mysqli_query($conn, "SELECT kode FROM m_area WHERE kode LIKE 'AREA%' ORDER BY id DESC LIMIT 1");
    $row = mysqli_fetch_assoc($query);
    if ($row) {
        $last_num = intval(substr($row['kode'], 4));
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    return "AREA" . str_pad($next_num, 4, '0', STR_PAD_LEFT);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_form'])) {
    $action_form = $_POST['action_form'];
    $id = mysqli_real_escape_string($conn, trim($_POST['id'] ?? ''));
    $area = mysqli_real_escape_string($conn, trim($_POST['area'] ?? ''));
    $area_description = mysqli_real_escape_string($conn, trim($_POST['area_description'] ?? ''));
    $kode = mysqli_real_escape_string($conn, trim($_POST['kode'] ?? ''));
    
    $user_now = $_SESSION['username'];
    $datetime_now = date('Y-m-d H:i:s');

    if ($action_form == 'insert') {
        // Auto generate kode jika kosong
        if (empty($kode)) {
            $kode = generateAreaKode($conn);
        }
        
        $cek = mysqli_query($conn, "SELECT kode FROM m_area WHERE kode='$kode'");
        if (mysqli_num_rows($cek) > 0) {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Gagal Simpan: Kode Area sudah terdaftar!</div>";
        } else {
            $sql_insert = "INSERT INTO m_area (area, area_description, kode, user_created, date_created) 
                           VALUES ('$area', '$area_description', '$kode', '$user_now', '$datetime_now')";
            if (mysqli_query($conn, $sql_insert)) {
                $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Area Berhasil Disimpan!</div>";
            } else {
                $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
            }
        }
        
        // Redirect menggunakan JavaScript (menghindari headers already sent)
        echo "<script>window.location.href='index.php?page=area';</script>";
        exit;
        
    } elseif ($action_form == 'update') {
        $sql_update = "UPDATE m_area SET 
                        area='$area', 
                        area_description='$area_description',
                        kode='$kode',
                        user_modified='$user_now', 
                        date_modified='$datetime_now' 
                       WHERE id='$id'";
        if (mysqli_query($conn, $sql_update)) {
            $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Perubahan Data Area Berhasil Disimpan!</div>";
        } else {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
        }
        
        // Redirect menggunakan JavaScript
        echo "<script>window.location.href='index.php?page=area';</script>";
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $id_del = mysqli_real_escape_string($conn, $_GET['id']);
    if (mysqli_query($conn, "DELETE FROM m_area WHERE id='$id_del'")) {
        $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Area Berhasil Dihapus!</div>";
    } else {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
    }
    echo "<script>window.location.href='index.php?page=area';</script>";
    exit;
}

// Filter Pencarian
$search_keyword = "";
$where_clause = "";
if (isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE area LIKE '%$search_keyword%' OR area_description LIKE '%$search_keyword%' OR kode LIKE '%$search_keyword%'";
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
        padding: 2px 8px !important;
        font-size: 10px !important;
    }
    .btn-delete {
        background: #dc3545 !important;
        color: white !important;
        border: none;
        padding: 2px 8px !important;
        font-size: 10px !important;
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

<div class="d-print-none">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-1 pb-2 mb-3 border-bottom">
        <h5 class="fw-bold text-dark m-0">
            <i class="fa fa-map-marker text-info"></i> Master Data Area
        </h5>
        <div class="d-flex gap-2">
           <!-- <button class="btn-vs btn-excel" onclick="window.location.href='index.php?page=area&action=export_excel'">
                <i class="fa fa-file-excel-o"></i> Export Excel
            </button>-->
            <button class="btn-vs btn-add" onclick="showModalTambah()">
                <i class="fa fa-plus-circle"></i> Tambah Area
            </button>
        </div>
    </div>
    <?= $alert_message; ?>
</div>

<!-- Form Pencarian -->
<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="POST" action="index.php?page=area" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search_keyword" class="form-control form-control-sm" 
                       placeholder="Cari Area, Deskripsi atau Kode..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari</button>
                <a href="index.php?page=area" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Data Area -->
<div class="table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #c0cddb;">
    <table class="table-crystal-report table-hover">
        <thead class="table-light">
            <tr class="text-center">
                <th class="d-print-none sticky-col-aksi" style="min-width: 130px;">Aksi</th>
                <th style="width: 20px;">ID</th>
                <th style="width: 200px;">Area</th>
                <th style="width: 200px;">Area Description</th>
                <th style="width: 80px;">Kode</th>
                <th style="width: 150px;">Create User</th>
                <th style="width: 180px;">Date Created</th>
                <th style="width: 150px;">User Modified</th>
                <th style="width: 180px;">Date Modified</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $no = 1;
            $query_list = mysqli_query($conn, "SELECT * FROM m_area $where_clause ORDER BY area ASC");
            if (!$query_list) {
                echo "<tr><td colspan='9' class='text-danger fw-bold p-3'>Error SQL: " . mysqli_error($conn) . "</td></tr>";
            } else if (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='9' class='text-center text-muted py-3'>Tidak ada data area ditemukan.</td></tr>";
            } else {
                while ($d = mysqli_fetch_assoc($query_list)) {
            ?>
                <tr>
                    <td class="text-center">
                        <button class="btn btn-edit btn-sm" onclick='showModalEdit(<?= json_encode($d); ?>)'>
                            <i class="fa fa-edit"></i> Edit
                        </button>
                        <a href="index.php?page=area&action=delete&id=<?= urlencode($d['id']) ?>" 
                           class="btn btn-delete btn-sm" 
                           onclick="return confirm('Hapus area <?= addslashes($d['area']) ?>?')">
                            <i class="fa fa-trash"></i> Hapus
                        </a>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($d['id']) ?></td>
                    <td class="fw-bold text-secondary"><?= htmlspecialchars($d['area']) ?></td>
                    <td><?= htmlspecialchars($d['area_description']) ?></td>
                    <td class="text-center"><code><?= htmlspecialchars($d['kode']) ?></code></td>
                    <td class="text-center"><?= htmlspecialchars($d['user_created']) ?></td>
                    <td class="text-center small"><?= htmlspecialchars($d['date_created']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($d['user_modified']) ?></td>
                    <td class="text-center small"><?= htmlspecialchars($d['date_modified']) ?></td>
                </tr>
            <?php 
                }
            } 
            ?>
        </tbody>
    </table>
</div>

<!-- Modal Form Area -->
<div class="modal fade d-print-none" id="modalArea" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title fw-bold" id="modalTitle">Form Master Area</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formArea" method="POST" action="index.php?page=area">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <input type="hidden" name="id" id="form_id" value="">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Area *</label>
                        <input type="text" name="area" id="form_area" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Area Description</label>
                        <textarea name="area_description" id="form_area_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Kode</label>
                        <input type="text" name="kode" id="form_kode" class="form-control" 
                               placeholder="Auto Generate jika dikosongkan">
                        <small class="text-muted">Kosongkan untuk auto generate (AREA0001, AREA0002, ...)</small>
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
var bootstrapModalArea;

document.addEventListener("DOMContentLoaded", function() {
    const modalElement = document.getElementById('modalArea');
    if (modalElement) {
        bootstrapModalArea = new bootstrap.Modal(modalElement);
    }
});

function showModalTambah() {
    document.getElementById('formArea').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Tambah Area Baru';
    document.getElementById('form_id').value = '';
    document.getElementById('form_kode').readOnly = false;
    document.getElementById('form_kode').value = '';
    bootstrapModalArea.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formArea').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Area: ' + dataObj.area;
    document.getElementById('form_id').value = dataObj.id;
    document.getElementById('form_area').value = dataObj.area;
    document.getElementById('form_area_description').value = dataObj.area_description;
    document.getElementById('form_kode').value = dataObj.kode;
    document.getElementById('form_kode').readOnly = true;
    bootstrapModalArea.show();
}
</script>