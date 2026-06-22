<?php
// user_management.php
// Hanya bisa diakses oleh IT Admin (id_role = 1)

include __DIR__ . '/../../koneksi.php';

// Proteksi halaman: Jika belum login, tendang balik ke login.php
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

// Cek apakah user adalah IT Admin
if ($_SESSION['id_role'] != 1) {
    echo "<div class='alert alert-danger'>Anda tidak memiliki akses ke halaman ini!</div>";
    return;
}

// Proses hapus user
if (isset($_GET['delete'])) {
    $id_user = intval($_GET['delete']);
    $query = "DELETE FROM sys_users WHERE id_user = '$id_user'";
    mysqli_query($conn, $query);
    echo "<script>window.location.href='index.php?page=user-akses&msg=deleted';</script>";
    exit;
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h4">Manajemen User & Akses Menu</h1>
    <button class="btn btn-primary btn-sm" onclick="showAddUserModal()">
        <i class="fa fa-plus"></i> Tambah User Baru
    </button>
</div>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php 
            $msg = $_GET['msg'];
            if ($msg == 'added') echo "User baru berhasil ditambahkan!";
            elseif ($msg == 'updated') echo "Data user berhasil diupdate!";
            elseif ($msg == 'deleted') echo "User berhasil dihapus!";
            elseif ($msg == 'password_changed') echo "Password berhasil direset!";
        ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="table-responsive">
    <table class="table table-bordered table-hover table-crystal" id="userTable">
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Nama Lengkap</th>
                <th>Status</th>
                <th>Terakhir Login</th>
                <th>Aksi</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $query = "SELECT * FROM sys_users ORDER BY id_user ASC";
            $result = mysqli_query($conn, $query);
            while ($row = mysqli_fetch_assoc($result)):
            ?>
            <tr>
                <td><?= $row['id_user'] ?></td>
                <td><strong><?= $row['username'] ?></strong></td>
                <td><?= $row['nama_lengkap'] ?></td>
                <td>
                    <span class="badge bg-<?= $row['is_active'] == 'Checked' ? 'success' : 'danger' ?>">
                        <?= $row['is_active'] == 'Checked' ? 'Aktif' : 'Nonaktif' ?>
                    </span>
                </td>
                <td><?= $row['last_login'] ?? '-' ?></td>
                <td>
                    <button class="btn btn-info btn-micro" onclick="editUser(<?= $row['id_user'] ?>)">
                        <i class="fa fa-edit"></i> Atur Menu
                    </button>
                    <button class="btn btn-warning btn-micro" onclick="resetPassword(<?= $row['id_user'] ?>)">
                        <i class="fa fa-key"></i> Reset
                    </button>
                    <button class="btn btn-danger btn-micro" onclick="deleteUser(<?= $row['id_user'] ?>, '<?= $row['username'] ?>')">
                        <i class="fa fa-trash"></i>
                    </button>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<!-- Modal Tambah/Edit User -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalTitle">Tambah User Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="userForm" method="POST" action="modul/program/save_user.php">
                    <input type="hidden" id="edit_id" name="edit_id" value="">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Username <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="username" name="username" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control form-control-sm" id="password" name="password" placeholder="Minimal 6 karakter">
                                <small class="text-muted">Kosongkan jika tidak mengubah password</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Nama Lengkap</label>
                        <input type="text" class="form-control form-control-sm" id="nama_lengkap" name="nama_lengkap">
                    </div>
                    
                    <hr>
                    <h6 class="fw-bold">Pilih Menu yang Dapat Diakses:</h6>
                    <p class="text-muted small">Centang menu yang ingin diakses oleh user ini</p>
                    
                    <div class="row">
                        <?php
                        // Ambil daftar menu dari database
                        $menuQuery = "SELECT * FROM sys_menus ORDER BY menu_group, sort_order";
                        $menuResult = mysqli_query($conn, $menuQuery);
                        $currentGroup = '';
                        while ($menu = mysqli_fetch_assoc($menuResult)):
                        ?>
                        <?php if ($currentGroup != $menu['menu_group']): 
                            $currentGroup = $menu['menu_group'];
                            $groupLabel = ucfirst($currentGroup);
                        ?>
                            <div class="col-12 mt-2"><strong class="text-primary"><?= $groupLabel ?></strong></div>
                        <?php endif; ?>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input menu-checkbox" 
                                       id="menu_<?= $menu['menu_key'] ?>" 
                                       name="menus[]" value="<?= $menu['menu_key'] ?>">
                                <label class="form-check-label small" for="menu_<?= $menu['menu_key'] ?>">
                                    <i class="fa <?= $menu['icon'] ?>"></i> <?= $menu['menu_name'] ?>
                                </label>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    
                    <div class="mb-3 mt-3">
                        <label class="form-label">Status Akun</label>
                        <select class="form-select form-select-sm" id="is_active" name="is_active">
                            <option value="Checked">Aktif</option>
                            <option value="Unchecked">Nonaktif</option>
                        </select>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="saveUserBtn">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function showAddUserModal() {
    document.getElementById('modalTitle').innerHTML = 'Tambah User Baru';
    document.getElementById('userForm').reset();
    document.getElementById('edit_id').value = '';
    document.getElementById('password').required = true;
    document.getElementById('password').placeholder = 'Minimal 6 karakter';
    document.querySelectorAll('.menu-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('saveUserBtn').innerHTML = 'Simpan';
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function editUser(id) {
    document.getElementById('modalTitle').innerHTML = 'Edit User & Atur Akses Menu';
    document.getElementById('edit_id').value = id;
    document.getElementById('password').required = false;
    document.getElementById('password').placeholder = 'Kosongkan jika tidak diubah';
    document.getElementById('saveUserBtn').innerHTML = 'Update';
    
    // Load data user via AJAX
    $.ajax({
        url: 'modul/program/get_user_data.php',
        type: 'POST',
        data: { id_user: id },
        dataType: 'json',
        success: function(data) {
            if (data.success) {
                document.getElementById('username').value = data.user.username;
                document.getElementById('nama_lengkap').value = data.user.nama_lengkap || '';
                document.getElementById('is_active').value = data.user.is_active;
                
                // Reset semua checkbox
                document.querySelectorAll('.menu-checkbox').forEach(cb => cb.checked = false);
                
                // Centang menu yang diakses
                if (data.menus) {
                    data.menus.forEach(menuKey => {
                        const cb = document.getElementById('menu_' + menuKey);
                        if (cb) cb.checked = true;
                    });
                }
                
                new bootstrap.Modal(document.getElementById('userModal')).show();
            } else {
                alert('Error: ' + data.message);
            }
        },
        error: function() {
            alert('Terjadi kesalahan saat mengambil data user.');
        }
    });
}

function resetPassword(id) {
    if (confirm('Reset password untuk user ini menjadi default "123456"?')) {
        window.location.href = 'modul/program/reset_password.php?id=' + id;
    }
}

function deleteUser(id, username) {
    if (confirm('Yakin ingin menghapus user "' + username + '"?')) {
        window.location.href = 'index.php?page=user-akses&delete=' + id;
    }
}
</script>