<?php
// modul/program/add_menu.php

include __DIR__ . '/../../koneksi.php';

// Hanya IT Admin yang bisa menambah menu
if (!isset($_SESSION['username']) || $_SESSION['id_role'] != 1) {
    header("Location: login.php");
    exit;
}

$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $menu_key = mysqli_real_escape_string($conn, trim($_POST['menu_key']));
    $menu_name = mysqli_real_escape_string($conn, trim($_POST['menu_name']));
    $menu_group = mysqli_real_escape_string($conn, trim($_POST['menu_group']));
    $icon = mysqli_real_escape_string($conn, trim($_POST['icon']));
    $sort_order = intval($_POST['sort_order']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    // Validasi
    if (empty($menu_key) || empty($menu_name)) {
        $error_msg = "Menu Key dan Menu Name harus diisi!";
    } else {
        // Cek duplikat
        $check = mysqli_query($conn, "SELECT id_menu FROM sys_menus WHERE menu_key = '$menu_key'");
        if (mysqli_num_rows($check) > 0) {
            $error_msg = "Menu Key '$menu_key' sudah ada!";
        } else {
            $query = "INSERT INTO sys_menus (menu_key, menu_name, menu_group, icon, sort_order, is_active) 
                      VALUES ('$menu_key', '$menu_name', '$menu_group', '$icon', '$sort_order', '$is_active')";
            
            if (mysqli_query($conn, $query)) {
                $success_msg = "✅ Menu '$menu_name' berhasil ditambahkan!";
            } else {
                $error_msg = "Gagal menambahkan menu: " . mysqli_error($conn);
            }
        }
    }
}

// Ambil daftar menu group yang sudah ada
$groupQuery = "SELECT DISTINCT menu_group FROM sys_menus ORDER BY menu_group";
$groupResult = mysqli_query($conn, $groupQuery);
$groups = [];
while ($row = mysqli_fetch_assoc($groupResult)) {
    $groups[] = $row['menu_group'];
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h4"><i class="fa fa-plus-circle text-success"></i> Tambah Menu Baru</h1>
    <a href="index.php?page=user-akses" class="btn btn-sm btn-secondary">
        <i class="fa fa-arrow-left"></i> Kembali
    </a>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white fw-bold py-2">
                <i class="fa fa-bars me-2"></i> Form Tambah Menu
            </div>
            <div class="card-body p-3">
                
                <?php if($success_msg): ?>
                    <div class="alert alert-success alert-dismissible fade show p-2 small" role="alert">
                        <i class="fa fa-check-circle me-1"></i> <?= $success_msg; ?>
                        <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if($error_msg): ?>
                    <div class="alert alert-danger alert-dismissible fade show p-2 small" role="alert">
                        <i class="fa fa-exclamation-circle me-1"></i> <?= $error_msg; ?>
                        <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">
                                    Menu Key <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="menu_key" class="form-control form-control-sm" required 
                                       placeholder="Contoh: laporan_penjualan" 
                                       value="<?= isset($_POST['menu_key']) ? $_POST['menu_key'] : '' ?>">
                                <small class="text-muted">Harus unique, gunakan underscore (_) bukan spasi</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">
                                    Menu Name <span class="text-danger">*</span>
                                </label>
                                <input type="text" name="menu_name" class="form-control form-control-sm" required 
                                       placeholder="Contoh: Laporan Penjualan"
                                       value="<?= isset($_POST['menu_name']) ? $_POST['menu_name'] : '' ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">
                                    Menu Group
                                </label>
                                <select name="menu_group" class="form-select form-select-sm">
                                    <option value="master" <?= (isset($_POST['menu_group']) && $_POST['menu_group'] == 'master') ? 'selected' : '' ?>>Master Data</option>
                                    <option value="transaksi" <?= (isset($_POST['menu_group']) && $_POST['menu_group'] == 'transaksi') ? 'selected' : '' ?>>Transaksi</option>
                                    <option value="laporan" <?= (isset($_POST['menu_group']) && $_POST['menu_group'] == 'laporan') ? 'selected' : '' ?>>Laporan</option>
                                    <option value="program" <?= (isset($_POST['menu_group']) && $_POST['menu_group'] == 'program') ? 'selected' : '' ?>>Program</option>
                                    
                                    <?php foreach ($groups as $group): ?>
                                        <?php if (!in_array($group, ['master', 'transaksi', 'laporan', 'program'])): ?>
                                            <option value="<?= $group ?>" <?= (isset($_POST['menu_group']) && $_POST['menu_group'] == $group) ? 'selected' : '' ?>>
                                                <?= ucfirst($group) ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    
                                    <option value="baru" <?= (isset($_POST['menu_group']) && $_POST['menu_group'] == 'baru') ? 'selected' : '' ?>>-- Buat Group Baru --</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">
                                    Icon (FontAwesome)
                                </label>
                                <input type="text" name="icon" class="form-control form-control-sm" 
                                       placeholder="fa-chart-bar" 
                                       value="<?= isset($_POST['icon']) ? $_POST['icon'] : 'fa-file' ?>">
                                <small class="text-muted">Contoh: fa-users, fa-file, fa-chart-bar</small>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">
                                    Sort Order
                                </label>
                                <input type="number" name="sort_order" class="form-control form-control-sm" 
                                       value="<?= isset($_POST['sort_order']) ? $_POST['sort_order'] : '0' ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="mb-2 mt-3">
                                <div class="form-check">
                                    <input type="checkbox" name="is_active" id="is_active" class="form-check-input" <?= isset($_POST['is_active']) ? 'checked' : 'checked' ?>>
                                    <label class="form-check-label small" for="is_active">Aktif</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-2">
                        <div class="alert alert-info p-2 small">
                            <i class="fa fa-info-circle"></i> 
                            <strong>Catatan:</strong> 
                            Setelah menambah menu, Anda harus menambahkan routing di <code>index.php</code> 
                            dan file modulnya di folder <code>modul/</code>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-sm btn-primary fw-bold">
                        <i class="fa fa-save"></i> Tambah Menu
                    </button>
                    <button type="reset" class="btn btn-sm btn-secondary fw-bold">
                        <i class="fa fa-undo"></i> Reset
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card shadow-sm">
            <div class="card-header bg-info text-white fw-bold py-2">
                <i class="fa fa-list me-2"></i> Daftar Menu Saat Ini
            </div>
            <div class="card-body p-2" style="max-height: 400px; overflow-y: auto;">
                <table class="table table-sm table-striped table-crystal">
                    <thead>
                        <tr>
                            <th>Menu Key</th>
                            <th>Group</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $listQuery = "SELECT menu_key, menu_name, menu_group FROM sys_menus ORDER BY menu_group, sort_order";
                        $listResult = mysqli_query($conn, $listQuery);
                        while ($row = mysqli_fetch_assoc($listResult)):
                        ?>
                        <tr>
                            <td><code><?= $row['menu_key'] ?></code></td>
                            <td><span class="badge bg-secondary"><?= $row['menu_group'] ?></span></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>