<?php
// modul/program/konfigurasi.php


// Proteksi - hanya IT Admin yang bisa akses
if (!isset($_SESSION['username']) || $_SESSION['id_role'] != 1) {
    header("Location: login.php");
    exit;
}

include __DIR__ . '/../../koneksi.php';

$success_msg = "";
$error_msg = "";

// Ambil konfigurasi saat ini
$configQuery = "SELECT * FROM sys_config WHERE id_config = 1";
$configResult = mysqli_query($conn, $configQuery);
$config = mysqli_fetch_assoc($configResult);

// Jika belum ada data, buat default
if (!$config) {
    $defaultConfig = [
        'company_name' => 'PT. Mutiaracahaya Plastindo',
        'company_address' => 'Jl. Raya Industri No. 123, Jakarta',
        'company_phone' => '(021) 1234-5678',
        'company_email' => 'info@cahaya.com',
        'timezone' => 'Asia/Jakarta',
        'date_format' => 'd-m-Y',
        'currency' => 'IDR',
        'tax_percentage' => 11,
        'max_login_attempts' => 5,
        'session_timeout' => 3600,
        'backup_path' => '../backup/',
        'auto_backup' => '1',
        'auto_backup_time' => '23:00',
        'maintenance_mode' => '0'
    ];
    
    // Insert default config
    $fields = implode(', ', array_keys($defaultConfig));
    $values = implode("', '", array_values($defaultConfig));
    mysqli_query($conn, "INSERT INTO sys_config ($fields) VALUES ('$values')");
    
    // Refresh data
    $configResult = mysqli_query($conn, $configQuery);
    $config = mysqli_fetch_assoc($configResult);
}

// Proses update konfigurasi
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $company_name = mysqli_real_escape_string($conn, trim($_POST['company_name']));
    $company_address = mysqli_real_escape_string($conn, trim($_POST['company_address']));
    $company_phone = mysqli_real_escape_string($conn, trim($_POST['company_phone']));
    $company_email = mysqli_real_escape_string($conn, trim($_POST['company_email']));
    $timezone = mysqli_real_escape_string($conn, trim($_POST['timezone']));
    $date_format = mysqli_real_escape_string($conn, trim($_POST['date_format']));
    $currency = mysqli_real_escape_string($conn, trim($_POST['currency']));
    $tax_percentage = floatval($_POST['tax_percentage']);
    $max_login_attempts = intval($_POST['max_login_attempts']);
    $session_timeout = intval($_POST['session_timeout']);
    $backup_path = mysqli_real_escape_string($conn, trim($_POST['backup_path']));
    $auto_backup = isset($_POST['auto_backup']) ? '1' : '0';
    $auto_backup_time = mysqli_real_escape_string($conn, trim($_POST['auto_backup_time']));
    $maintenance_mode = isset($_POST['maintenance_mode']) ? '1' : '0';
    
    $query = "UPDATE sys_config SET 
                company_name = '$company_name',
                company_address = '$company_address',
                company_phone = '$company_phone',
                company_email = '$company_email',
                timezone = '$timezone',
                date_format = '$date_format',
                currency = '$currency',
                tax_percentage = '$tax_percentage',
                max_login_attempts = '$max_login_attempts',
                session_timeout = '$session_timeout',
                backup_path = '$backup_path',
                auto_backup = '$auto_backup',
                auto_backup_time = '$auto_backup_time',
                maintenance_mode = '$maintenance_mode',
                updated_at = NOW()
              WHERE id_config = 1";
    
    if (mysqli_query($conn, $query)) {
        $success_msg = "✅ Konfigurasi berhasil diperbarui!";
        // Refresh data
        $configResult = mysqli_query($conn, $configQuery);
        $config = mysqli_fetch_assoc($configResult);
    } else {
        $error_msg = "❌ Gagal memperbarui konfigurasi: " . mysqli_error($conn);
    }
}

// Cek status koneksi database
$dbStatus = mysqli_ping($conn) ? 'Connected' : 'Disconnected';
$dbHost = $host;
$dbName = $db;

// Cek versi PHP dan MySQL
$phpVersion = phpversion();
$mysqlVersion = mysqli_get_server_info($conn);

// Cek ekstensi yang diperlukan
$extensions = [
    'mysqli' => extension_loaded('mysqli'),
    'gd' => extension_loaded('gd'),
    'zip' => extension_loaded('zip'),
    'mbstring' => extension_loaded('mbstring'),
    'json' => extension_loaded('json'),
    'curl' => extension_loaded('curl')
];

// Cek folder backup
$backupPath = __DIR__ . '/../../backup/';
$backupWritable = is_writable($backupPath);
if (!file_exists($backupPath)) {
    mkdir($backupPath, 0777, true);
    $backupWritable = is_writable($backupPath);
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h4"><i class="fa fa-sliders-h text-primary"></i> Konfigurasi Server</h1>
    <button class="btn btn-sm btn-success" onclick="location.reload()">
        <i class="fa fa-sync"></i> Refresh Status
    </button>
</div>

<?php if ($success_msg): ?>
    <div class="alert alert-success alert-dismissible fade show p-2 small" role="alert">
        <i class="fa fa-check-circle me-1"></i> <?= $success_msg; ?>
        <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error_msg): ?>
    <div class="alert alert-danger alert-dismissible fade show p-2 small" role="alert">
        <i class="fa fa-exclamation-circle me-1"></i> <?= $error_msg; ?>
        <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- ====================================================================== -->
    <!-- SISTEM INFO & STATUS -->
    <!-- ====================================================================== -->
    <div class="col-md-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-info text-white fw-bold py-2">
                <i class="fa fa-server me-2"></i> Sistem Status
            </div>
            <div class="card-body p-3">
                <table class="table table-sm table-bordered table-crystal mb-0">
                    <tr>
                        <td width="40%"><strong>Database</strong></td>
                        <td>
                            <span class="badge bg-<?= $dbStatus == 'Connected' ? 'success' : 'danger' ?>">
                                <?= $dbStatus ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>DB Host</strong></td>
                        <td><?= $dbHost ?></td>
                    </tr>
                    <tr>
                        <td><strong>DB Name</strong></td>
                        <td><?= $dbName ?></td>
                    </tr>
                    <tr>
                        <td><strong>PHP Version</strong></td>
                        <td><?= $phpVersion ?></td>
                    </tr>
                    <tr>
                        <td><strong>MySQL Version</strong></td>
                        <td><?= $mysqlVersion ?></td>
                    </tr>
                    <tr>
                        <td><strong>Backup Folder</strong></td>
                        <td>
                            <span class="badge bg-<?= $backupWritable ? 'success' : 'danger' ?>">
                                <?= $backupWritable ? 'Writable' : 'Not Writable' ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Maintenance Mode</strong></td>
                        <td>
                            <span class="badge bg-<?= $config['maintenance_mode'] == '1' ? 'warning' : 'success' ?>">
                                <?= $config['maintenance_mode'] == '1' ? 'ACTIVE' : 'Normal' ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- ====================================================================== -->
        <!-- PHP EXTENSIONS -->
        <!-- ====================================================================== -->
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-secondary text-white fw-bold py-2">
                <i class="fa fa-puzzle-piece me-2"></i> PHP Extensions
            </div>
            <div class="card-body p-2">
                <?php foreach ($extensions as $ext => $loaded): ?>
                    <div class="d-flex justify-content-between align-items-center border-bottom py-1">
                        <span><code><?= $ext ?></code></span>
                        <span class="badge bg-<?= $loaded ? 'success' : 'danger' ?>">
                            <?= $loaded ? '✓ Loaded' : '✗ Missing' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- ====================================================================== -->
    <!-- FORM KONFIGURASI -->
    <!-- ====================================================================== -->
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="fa fa-edit me-2"></i> Form Konfigurasi
            </div>
            <div class="card-body p-3">
                <form method="POST" action="">
                    <ul class="nav nav-tabs" id="configTab" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">
                                <i class="fa fa-building"></i> Umum
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="system-tab" data-bs-toggle="tab" data-bs-target="#system" type="button" role="tab">
                                <i class="fa fa-cog"></i> Sistem
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="backup-tab" data-bs-toggle="tab" data-bs-target="#backup" type="button" role="tab">
                                <i class="fa fa-database"></i> Backup
                            </button>
                        </li>
                    </ul>
                    
                    <div class="tab-content p-3 border border-top-0 rounded-bottom">
                        <!-- ========================================================== -->
                        <!-- TAB GENERAL -->
                        <!-- ========================================================== -->
                        <div class="tab-pane fade show active" id="general" role="tabpanel">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Nama Perusahaan</label>
                                <input type="text" name="company_name" class="form-control form-control-sm" 
                                       value="<?= $config['company_name'] ?? '' ?>" required>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Alamat</label>
                                <textarea name="company_address" class="form-control form-control-sm" rows="2"><?= $config['company_address'] ?? '' ?></textarea>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold">Telepon</label>
                                        <input type="text" name="company_phone" class="form-control form-control-sm" 
                                               value="<?= $config['company_phone'] ?? '' ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold">Email</label>
                                        <input type="email" name="company_email" class="form-control form-control-sm" 
                                               value="<?= $config['company_email'] ?? '' ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold">Timezone</label>
                                        <select name="timezone" class="form-select form-select-sm">
                                            <option value="Asia/Jakarta" <?= ($config['timezone'] ?? '') == 'Asia/Jakarta' ? 'selected' : '' ?>>Asia/Jakarta (WIB)</option>
                                            <option value="Asia/Makassar" <?= ($config['timezone'] ?? '') == 'Asia/Makassar' ? 'selected' : '' ?>>Asia/Makassar (WITA)</option>
                                            <option value="Asia/Jayapura" <?= ($config['timezone'] ?? '') == 'Asia/Jayapura' ? 'selected' : '' ?>>Asia/Jayapura (WIT)</option>
                                            <option value="Asia/Singapore" <?= ($config['timezone'] ?? '') == 'Asia/Singapore' ? 'selected' : '' ?>>Asia/Singapore</option>
                                            <option value="Asia/Kuala_Lumpur" <?= ($config['timezone'] ?? '') == 'Asia/Kuala_Lumpur' ? 'selected' : '' ?>>Asia/Kuala Lumpur</option>
                                            <option value="UTC" <?= ($config['timezone'] ?? '') == 'UTC' ? 'selected' : '' ?>>UTC</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold">Format Tanggal</label>
                                        <select name="date_format" class="form-select form-select-sm">
                                            <option value="d-m-Y" <?= ($config['date_format'] ?? '') == 'd-m-Y' ? 'selected' : '' ?>>DD-MM-YYYY</option>
                                            <option value="m-d-Y" <?= ($config['date_format'] ?? '') == 'm-d-Y' ? 'selected' : '' ?>>MM-DD-YYYY</option>
                                            <option value="Y-m-d" <?= ($config['date_format'] ?? '') == 'Y-m-d' ? 'selected' : '' ?>>YYYY-MM-DD</option>
                                            <option value="d/m/Y" <?= ($config['date_format'] ?? '') == 'd/m/Y' ? 'selected' : '' ?>>DD/MM/YYYY</option>
                                            <option value="m/d/Y" <?= ($config['date_format'] ?? '') == 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold">Mata Uang</label>
                                        <select name="currency" class="form-select form-select-sm">
                                            <option value="IDR" <?= ($config['currency'] ?? '') == 'IDR' ? 'selected' : '' ?>>IDR (Rp)</option>
                                            <option value="USD" <?= ($config['currency'] ?? '') == 'USD' ? 'selected' : '' ?>>USD ($)</option>
                                            <option value="EUR" <?= ($config['currency'] ?? '') == 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                                            <option value="SGD" <?= ($config['currency'] ?? '') == 'SGD' ? 'selected' : '' ?>>SGD (S$)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold">Pajak (%)</label>
                                        <input type="number" name="tax_percentage" class="form-control form-control-sm" 
                                               step="0.01" min="0" max="100"
                                               value="<?= $config['tax_percentage'] ?? 11 ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- ========================================================== -->
                        <!-- TAB SYSTEM -->
                        <!-- ========================================================== -->
                        <div class="tab-pane fade" id="system" role="tabpanel">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold">Max Login Attempts</label>
                                        <input type="number" name="max_login_attempts" class="form-control form-control-sm" 
                                               min="1" max="20" value="<?= $config['max_login_attempts'] ?? 5 ?>">
                                        <small class="text-muted">Jumlah percobaan login gagal sebelum akun terkunci</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold">Session Timeout (detik)</label>
                                        <input type="number" name="session_timeout" class="form-control form-control-sm" 
                                               min="300" step="60" value="<?= $config['session_timeout'] ?? 3600 ?>">
                                        <small class="text-muted">Default: 3600 detik (1 jam)</small>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-2">
                                <div class="form-check form-switch">
                                    <input type="checkbox" class="form-check-input" id="maintenance_mode" 
                                           name="maintenance_mode" <?= ($config['maintenance_mode'] ?? '0') == '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label small fw-bold" for="maintenance_mode">
                                        <i class="fa fa-exclamation-triangle text-warning"></i> Maintenance Mode
                                    </label>
                                </div>
                                <small class="text-muted ms-4">Jika aktif, hanya IT Admin yang bisa mengakses sistem</small>
                            </div>
                        </div>
                        
                        <!-- ========================================================== -->
                        <!-- TAB BACKUP -->
                        <!-- ========================================================== -->
                        <div class="tab-pane fade" id="backup" role="tabpanel">
                            <div class="mb-2">
                                <label class="form-label small fw-bold">Folder Backup</label>
                                <input type="text" name="backup_path" class="form-control form-control-sm" 
                                       value="<?= $config['backup_path'] ?? '../backup/' ?>">
                                <small class="text-muted">Path relatif dari root aplikasi</small>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <div class="form-check form-switch">
                                            <input type="checkbox" class="form-check-input" id="auto_backup" 
                                                   name="auto_backup" <?= ($config['auto_backup'] ?? '0') == '1' ? 'checked' : '' ?>>
                                            <label class="form-check-label small fw-bold" for="auto_backup">
                                                Auto Backup Otomatis
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-2">
                                        <label class="form-label small fw-bold">Waktu Backup</label>
                                        <input type="time" name="auto_backup_time" class="form-control form-control-sm" 
                                               value="<?= $config['auto_backup_time'] ?? '23:00' ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="alert alert-info p-2 small">
                                <i class="fa fa-info-circle me-1"></i>
                                <strong>Note:</strong> Auto backup akan berjalan menggunakan cron job atau task scheduler.
                                Pastikan folder backup memiliki izin tulis (writable).
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <button type="submit" class="btn btn-sm btn-primary fw-bold">
                            <i class="fa fa-save"></i> Simpan Konfigurasi
                        </button>
                        <button type="reset" class="btn btn-sm btn-secondary fw-bold">
                            <i class="fa fa-undo"></i> Reset
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>