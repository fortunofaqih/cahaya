<?php
// modul/master/marketing.php

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
if (isset($_GET['action']) && $_GET['action'] == 'export_excel') {
    if (ob_get_level()) ob_end_clean();
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=Master_Marketing_" . date('Ymd_His') . ".xls");
    header("Cache-Control: no-cache, must-revalidate");
    ?>
    <table border="1">
        <thead>
            <tr style="background-color: #f2f2f2; font-weight: bold;">
                <th width="20%">Marketing ID</th>
                <th width="20%">Marketing Name</th>
                <th width="12%">Is Active</th>
                <th width="12%">Create User</th>
                <th width="12%">Date Created</th>
                <th width="12%">User Modified</th>
                <th width="12%">Date Modified</th>
             </tr>
        </thead>
        <tbody>
            <?php
            $q_excel = mysqli_query($conn, "SELECT * FROM m_marketing ORDER BY marketing_id ASC");
            while ($d = mysqli_fetch_assoc($q_excel)) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($d['marketing_id']) . "</td>";
                echo "<td>" . htmlspecialchars($d['marketing_name']) . "</td>";
                echo "<td>" . htmlspecialchars($d['is_active']) . "</td>";
                echo "<td>" . htmlspecialchars($d['create_user']) . "</td>";
                echo "<td>" . htmlspecialchars($d['date_created']) . "</td>";
                echo "<td>" . htmlspecialchars($d['user_modified']) . "</td>";
                echo "<td>" . htmlspecialchars($d['date_modified']) . "</td>";
                echo "</tr>";
            }
            ?>
        </tbody>
    </table>
    <?php
    exit;
}

// ----------------------------------------------------
// LOGIC BACKEND (INSERT, UPDATE, DELETE)
// ----------------------------------------------------

// Auto generate ID
function generateMarketingId($conn) {
    $query = mysqli_query($conn, "SELECT marketing_id FROM m_marketing WHERE marketing_id LIKE 'MKT%' ORDER BY marketing_id DESC LIMIT 1");
    $row = mysqli_fetch_assoc($query);
    if ($row) {
        $last_num = intval(substr($row['marketing_id'], 3));
        $next_num = $last_num + 1;
    } else {
        $next_num = 1;
    }
    return "MKT" . str_pad($next_num, 3, '0', STR_PAD_LEFT);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action_form'])) {
    $action_form = $_POST['action_form'];
    $marketing_id = mysqli_real_escape_string($conn, trim($_POST['marketing_id'] ?? ''));
    $marketing_name = mysqli_real_escape_string($conn, trim($_POST['marketing_name'] ?? ''));
    $is_active = isset($_POST['is_active']) ? 'Checked' : 'Unchecked';
    
    $user_now = $_SESSION['username'];
    $datetime_now = date('Y-m-d H:i:s');

    if ($action_form == 'insert') {
        // Auto generate ID jika kosong
        if (empty($marketing_id)) {
            $marketing_id = generateMarketingId($conn);
        }
        
        $cek = mysqli_query($conn, "SELECT marketing_id FROM m_marketing WHERE marketing_id='$marketing_id'");
        if (mysqli_num_rows($cek) > 0) {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Gagal Simpan: Marketing ID sudah terdaftar!</div>";
        } else {
            $sql_insert = "INSERT INTO m_marketing (marketing_id, marketing_name, is_active, create_user, date_created) 
                           VALUES ('$marketing_id', '$marketing_name', '$is_active', '$user_now', '$datetime_now')";
            if (mysqli_query($conn, $sql_insert)) {
                $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Marketing Berhasil Disimpan!</div>";
            } else {
                $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
            }
        }
        
        // Redirect menggunakan JavaScript (menghindari headers already sent)
        echo "<script>window.location.href='index.php?page=marketing';</script>";
        exit;
        
    } elseif ($action_form == 'update') {
        $sql_update = "UPDATE m_marketing SET 
                        marketing_name='$marketing_name', 
                        is_active='$is_active',
                        user_modified='$user_now', 
                        date_modified='$datetime_now' 
                       WHERE marketing_id='$marketing_id'";
        if (mysqli_query($conn, $sql_update)) {
            $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Perubahan Data Marketing Berhasil Disimpan!</div>";
        } else {
            $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
        }
        
        // Redirect menggunakan JavaScript
        echo "<script>window.location.href='index.php?page=marketing';</script>";
        exit;
    }
}

if (isset($_GET['action']) && $_GET['action'] == 'delete') {
    $id_del = mysqli_real_escape_string($conn, $_GET['id']);
    if (mysqli_query($conn, "DELETE FROM m_marketing WHERE marketing_id='$id_del'")) {
        $_SESSION['alert'] = "<div class='alert alert-success p-2 small'>Data Marketing Berhasil Dihapus!</div>";
    } else {
        $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Error: " . mysqli_error($conn) . "</div>";
    }
    echo "<script>window.location.href='index.php?page=marketing';</script>";
    exit;
}

// Filter Pencarian
$search_keyword = "";
$where_clause = "";
if (isset($_POST['btn_search'])) {
    $search_keyword = mysqli_real_escape_string($conn, trim($_POST['search_keyword']));
    if (!empty($search_keyword)) {
        $where_clause = "WHERE marketing_id LIKE '%$search_keyword%' OR marketing_name LIKE '%$search_keyword%'";
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
        font-size: 11px !important;
    }
    .btn-delete {
        background: #dc3545 !important;
        color: white !important;
        border: none;
        padding: 2px 8px !important;
        font-size: 11px !important;
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
            <i class="fa fa-users text-info"></i> Master Data Marketing
        </h5>
        <div class="d-flex gap-2">
            <button class="btn-vs btn-add" onclick="showModalTambah()">
                <i class="fa fa-plus-circle"></i> Tambah Marketing
            </button>
        </div>
    </div>
    <?= $alert_message; ?>
</div>

<div class="card shadow-sm mb-3 d-print-none">
    <div class="card-body py-2">
        <form method="POST" action="index.php?page=marketing" class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" name="search_keyword" class="form-control form-control-sm" 
                       placeholder="Cari ID atau Nama Marketing..." value="<?= htmlspecialchars($search_keyword) ?>">
            </div>
            <div class="col-auto">
                <button type="submit" name="btn_search" class="btn btn-sm btn-dark"><i class="fa fa-search"></i> Cari</button>
                <a href="index.php?page=marketing" class="btn btn-sm btn-outline-secondary"><i class="fa fa-sync"></i> Reset</a>
            </div>
        </form>
    </div>
</div>

<div class="table-responsive" style="max-height: 500px; overflow-y: auto; border: 1px solid #c0cddb;">
    <table class="table-crystal-report table-hover">
        <thead class="table-light">
            <tr class="text-center">
                <th class="d-print-none sticky-col-aksi" style="min-width: 100px;">Aksi</th>
                <th style="width: 150px;">Marketing ID</th>
                <th style="width: 250px;">Marketing Name</th>
                <th style="width: 100px;">Status</th>
                <th style="width: 150px;">Create User</th>
                <th style="width: 150px;">Date Created</th>
                <th style="width: 150px;">User Modified</th>
                <th style="width: 150px;">Date Modified</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query_list = mysqli_query($conn, "SELECT * FROM m_marketing $where_clause ORDER BY marketing_id ASC");
            if (!$query_list) {
                echo "<tr><td colspan='8' class='text-danger fw-bold p-3'>Error SQL: " . mysqli_error($conn) . "</td></tr>";
            } else if (mysqli_num_rows($query_list) == 0) {
                echo "<tr><td colspan='8' class='text-center text-muted py-3'>Tidak ada data marketing ditemukan.</td></tr>";
            } else {
                while ($d = mysqli_fetch_assoc($query_list)) {
                    $status_badge = ($d['is_active'] == 'Checked') ? 'success' : 'danger';
                    $status_text = ($d['is_active'] == 'Checked') ? 'Active' : 'Inactive';
            ?>
                <tr>
                    <td class="text-center">
                        <button class="btn btn-edit btn-sm" onclick='showModalEdit(<?= json_encode($d); ?>)'>
                            <i class="fa fa-edit"></i> Edit
                        </button>
                        <a href="index.php?page=marketing&action=delete&id=<?= urlencode($d['marketing_id']) ?>" 
                           class="btn btn-delete btn-sm" 
                           onclick="return confirm('Hapus marketing <?= addslashes($d['marketing_name']) ?>?')">
                            <i class="fa fa-trash"></i> Hapus
                        </a>
                    </td>
                    <td class="fw-bold text-secondary"><?= htmlspecialchars($d['marketing_id']) ?></td>
                    <td><?= htmlspecialchars($d['marketing_name']) ?></td>
                    <td class="text-center">
                        <span class="badge bg-<?= $status_badge ?>" style="font-size: 10px;"><?= $status_text ?></span>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($d['create_user'] ?? '-') ?></td>
                    <td class="text-center small"><?= htmlspecialchars($d['date_created'] ?? '-') ?></td>
                    <td class="text-center"><?= htmlspecialchars($d['user_modified'] ?? '-') ?></td>
                    <td class="text-center small"><?= htmlspecialchars($d['date_modified'] ?? '-') ?></td>
                </tr>
            <?php 
                }
            } 
            ?>
        </tbody>
    </table>
</div>

<div class="modal fade d-print-none" id="modalMarketing" data-bs-backdrop="static" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h6 class="modal-title fw-bold" id="modalTitle">Form Master Marketing</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="formMarketing" method="POST" action="index.php?page=marketing">
                <input type="hidden" name="action_form" id="action_form" value="insert">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Marketing ID</label>
                        <input type="text" name="marketing_id" id="form_marketing_id" class="form-control" 
                               placeholder="Auto Generate jika dikosongkan">
                        <small class="text-muted">Kosongkan untuk auto generate (MKT001, MKT002, ...)</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Marketing Name *</label>
                        <input type="text" name="marketing_name" id="form_marketing_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="form_is_active" value="Checked" checked>
                            <label class="form-check-label fw-bold" for="form_is_active">Is Active</label>
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
var bootstrapModalMarketing;

document.addEventListener("DOMContentLoaded", function() {
    const modalElement = document.getElementById('modalMarketing');
    if (modalElement) {
        bootstrapModalMarketing = new bootstrap.Modal(modalElement);
    }
});

function showModalTambah() {
    document.getElementById('formMarketing').reset();
    document.getElementById('action_form').value = 'insert';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-plus-circle"></i> Tambah Marketing Baru';
    document.getElementById('form_marketing_id').readOnly = false;
    document.getElementById('form_marketing_id').value = '';
    document.getElementById('form_is_active').checked = true;
    bootstrapModalMarketing.show();
}

function showModalEdit(dataObj) {
    document.getElementById('formMarketing').reset();
    document.getElementById('action_form').value = 'update';
    document.getElementById('modalTitle').innerHTML = '<i class="fa fa-edit"></i> Edit Marketing: ' + dataObj.marketing_name;
    document.getElementById('form_marketing_id').value = dataObj.marketing_id;
    document.getElementById('form_marketing_id').readOnly = true;
    document.getElementById('form_marketing_name').value = dataObj.marketing_name;
    document.getElementById('form_is_active').checked = (dataObj.is_active === 'Checked');
    bootstrapModalMarketing.show();
}
</script>