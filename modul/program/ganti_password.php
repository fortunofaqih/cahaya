<?php
// modul/program/ganti_password.php

// 2. Proteksi jika belum login
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit;
}

// 3. Include koneksi
include __DIR__ . '/../../koneksi.php';

$id_user = $_SESSION['id_user'];
$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // 4. Validasi input tidak kosong
    if (empty($_POST['password_lama']) || empty($_POST['password_baru']) || empty($_POST['konfirmasi_baru'])) {
        $error_msg = "Semua field harus diisi!";
    } else {
        $password_lama = trim($_POST['password_lama']);
        $password_baru = trim($_POST['password_baru']);
        $konfirmasi   = trim($_POST['konfirmasi_baru']);

        // 5. Validasi password baru
        if ($password_baru !== $konfirmasi) {
            $error_msg = "Konfirmasi password baru tidak cocok!";
        } elseif (strlen($password_baru) < 6) {
            $error_msg = "Password baru minimal 6 karakter!";
        } elseif (!preg_match('/[A-Z]/', $password_baru)) {
            $error_msg = "Password baru harus mengandung minimal 1 huruf kapital!";
        } elseif (!preg_match('/[a-z]/', $password_baru)) {
            $error_msg = "Password baru harus mengandung minimal 1 huruf kecil!";
        } elseif (!preg_match('/[0-9]/', $password_baru)) {
            $error_msg = "Password baru harus mengandung minimal 1 angka!";
        } elseif (strpos($password_baru, ' ') !== false) {
            $error_msg = "Password baru tidak boleh mengandung spasi!";
        } elseif (strlen($password_lama) < 1) {
            $error_msg = "Password lama tidak boleh kosong!";
        } else {
            // 6. Ambil password lama dari database
            $query_cek = $conn->prepare("SELECT password FROM sys_users WHERE id_user = ?");
            $query_cek->bind_param("i", $id_user);
            $query_cek->execute();
            $result = $query_cek->get_result();
            
            if ($result->num_rows === 1) {
                $d = $result->fetch_assoc();

                // 7. Verifikasi password lama
                if (password_verify($password_lama, $d['password'])) {
                    // 8. Cek apakah password baru sama dengan yang lama
                    if (password_verify($password_baru, $d['password'])) {
                        $error_msg = "Password baru tidak boleh sama dengan password lama!";
                    } else {
                        // 9. Hash password baru
                        $password_hash_baru = password_hash($password_baru, PASSWORD_BCRYPT);
                        
                        // 10. Update password dengan prepared statement
                        $update = $conn->prepare("UPDATE sys_users SET password = ? WHERE id_user = ?");
                        $update->bind_param("si", $password_hash_baru, $id_user);
                        
                        if ($update->execute()) {
                            $success_msg = "✅ Password berhasil diperbarui!";
                            
                            // 11. Log aktivitas (opsional, jika ada tabel log)
                            // $logQuery = "INSERT INTO sys_activity_log (id_user, activity, timestamp) VALUES (?, 'Change Password', NOW())";
                            // $logStmt = $conn->prepare($logQuery);
                            // $logStmt->bind_param("i", $id_user);
                            // $logStmt->execute();
                            
                            // 12. Reset form setelah sukses
                            // Tidak perlu reset karena akan redirect atau reload
                        } else {
                            $error_msg = "Gagal memperbarui database! Silakan coba lagi.";
                        }
                    }
                } else {
                    $error_msg = "❌ Password lama Anda salah!";
                }
            } else {
                $error_msg = "User tidak ditemukan!";
            }
            $query_cek->close();
        }
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h4"><i class="fa fa-key text-warning"></i> Keamanan: Ganti Password</h1>
</div>

<div class="row">
    <div class="col-md-6 col-sm-12">
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white fw-bold py-2">
                <i class="fa fa-lock me-2"></i> Form Ubah Password (Bcrypt Encryption)
            </div>
            <div class="card-body p-3">
                
                <!-- 13. Pesan Sukses dengan Timer Auto Close -->
                <?php if($success_msg): ?>
                    <div class="alert alert-success alert-dismissible fade show p-2 small" role="alert">
                        <i class="fa fa-check-circle me-1"></i> <?= $success_msg; ?>
                        <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
                    </div>
                    <script>
                        // Auto close alert setelah 5 detik
                        setTimeout(function() {
                            document.querySelector('.alert-success')?.remove();
                        }, 5000);
                    </script>
                <?php endif; ?>
                
                <?php if($error_msg): ?>
                    <div class="alert alert-danger alert-dismissible fade show p-2 small" role="alert">
                        <i class="fa fa-exclamation-circle me-1"></i> <?= $error_msg; ?>
                        <button type="button" class="btn-close p-2" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- 14. Form dengan Validasi Client Side -->
                <form method="POST" action="" id="formGantiPassword" onsubmit="return validatePasswordForm()">
                    <div class="mb-2">
                        <label class="form-label small fw-bold">
                            <i class="fa fa-key me-1"></i> Password Lama
                            <span class="text-danger">*</span>
                        </label>
                        <input type="password" name="password_lama" id="password_lama" 
                               class="form-control form-control-sm" required 
                               placeholder="Masukkan password lama">
                    </div>
                    
                    <div class="mb-2">
                        <label class="form-label small fw-bold">
                            <i class="fa fa-lock me-1"></i> Password Baru
                            <span class="text-danger">*</span>
                        </label>
                        <input type="password" name="password_baru" id="password_baru" 
                               class="form-control form-control-sm" required minlength="6"
                               placeholder="Minimal 6 karakter, huruf & angka">
                        <div id="passwordStrength" class="mt-1" style="display:none;">
                            <div class="progress" style="height:5px;">
                                <div id="strengthBar" class="progress-bar" role="progressbar" style="width:0%;"></div>
                            </div>
                            <small id="strengthText" class="text-muted"></small>
                        </div>
                        <small class="text-muted">
                            <i class="fa fa-info-circle"></i> Minimal 6 karakter, mengandung huruf kapital, huruf kecil, dan angka
                        </small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label small fw-bold">
                            <i class="fa fa-check-circle me-1"></i> Konfirmasi Password Baru
                            <span class="text-danger">*</span>
                        </label>
                        <input type="password" name="konfirmasi_baru" id="konfirmasi_baru" 
                               class="form-control form-control-sm" required
                               placeholder="Ulangi password baru">
                        <small id="confirmMsg" class="text-danger" style="display:none;">Password tidak cocok!</small>
                    </div>
                    
                    <button type="submit" class="btn btn-sm btn-primary fw-bold">
                        <i class="fa fa-save"></i> Update Password
                    </button>
                    <button type="button" class="btn btn-sm btn-secondary fw-bold" onclick="window.location.href='index.php'">
                        <i class="fa fa-arrow-left"></i> Kembali
                    </button>
                    <button type="reset" class="btn btn-sm btn-warning fw-bold">
                        <i class="fa fa-undo"></i> Reset
                    </button>
                </form>
            </div>
        </div>
        
        <!-- 15. Tips Keamanan -->
        <div class="card mt-3 shadow-sm">
            <div class="card-body p-2">
                <small class="text-muted">
                    <i class="fa fa-shield-alt text-success me-1"></i> 
                    <strong>Tips Keamanan:</strong>
                    <ul class="mb-0 ps-3 small">
                        <li>Gunakan kombinasi huruf kapital, huruf kecil, dan angka</li>
                        <li>Jangan gunakan password yang sama dengan username</li>
                        <li>Ganti password secara rutin setiap 3 bulan sekali</li>
                        <li>Jangan beritahu password kepada siapapun</li>
                    </ul>
                </small>
            </div>
        </div>
    </div>
</div>

<!-- 16. JavaScript untuk Validasi Client Side dan Password Strength -->
<script>
function validatePasswordForm() {
    const passwordLama = document.getElementById('password_lama').value.trim();
    const passwordBaru = document.getElementById('password_baru').value;
    const konfirmasi = document.getElementById('konfirmasi_baru').value;
    
    // Validasi password lama tidak boleh kosong
    if (passwordLama === '') {
        alert('Password lama harus diisi!');
        document.getElementById('password_lama').focus();
        return false;
    }
    
    // Validasi password baru minimal 6 karakter
    if (passwordBaru.length < 6) {
        alert('Password baru minimal 6 karakter!');
        document.getElementById('password_baru').focus();
        return false;
    }
    
    // Validasi password baru mengandung huruf kapital
    if (!/[A-Z]/.test(passwordBaru)) {
        alert('Password baru harus mengandung minimal 1 huruf kapital!');
        document.getElementById('password_baru').focus();
        return false;
    }
    
    // Validasi password baru mengandung huruf kecil
    if (!/[a-z]/.test(passwordBaru)) {
        alert('Password baru harus mengandung minimal 1 huruf kecil!');
        document.getElementById('password_baru').focus();
        return false;
    }
    
    // Validasi password baru mengandung angka
    if (!/[0-9]/.test(passwordBaru)) {
        alert('Password baru harus mengandung minimal 1 angka!');
        document.getElementById('password_baru').focus();
        return false;
    }
    
    // Validasi password baru tidak boleh ada spasi
    if (passwordBaru.includes(' ')) {
        alert('Password baru tidak boleh mengandung spasi!');
        document.getElementById('password_baru').focus();
        return false;
    }
    
    // Validasi konfirmasi password
    if (passwordBaru !== konfirmasi) {
        alert('Konfirmasi password baru tidak cocok!');
        document.getElementById('konfirmasi_baru').focus();
        return false;
    }
    
    // Validasi password baru tidak sama dengan lama
    if (passwordBaru === passwordLama) {
        alert('Password baru tidak boleh sama dengan password lama!');
        document.getElementById('password_baru').focus();
        return false;
    }
    
    return true;
}

// 17. Password Strength Meter (Real-time)
document.addEventListener('DOMContentLoaded', function() {
    const passwordBaru = document.getElementById('password_baru');
    const strengthDiv = document.getElementById('passwordStrength');
    const strengthBar = document.getElementById('strengthBar');
    const strengthText = document.getElementById('strengthText');
    
    passwordBaru.addEventListener('input', function() {
        const val = this.value;
        if (val.length === 0) {
            strengthDiv.style.display = 'none';
            return;
        }
        
        strengthDiv.style.display = 'block';
        
        let score = 0;
        if (val.length >= 6) score++;
        if (val.length >= 10) score++;
        if (/[A-Z]/.test(val)) score++;
        if (/[a-z]/.test(val)) score++;
        if (/[0-9]/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;
        
        const percentage = Math.min((score / 6) * 100, 100);
        strengthBar.style.width = percentage + '%';
        
        let color = 'danger';
        let text = 'Sangat Lemah';
        if (percentage >= 80) {
            color = 'success';
            text = 'Kuat';
        } else if (percentage >= 60) {
            color = 'info';
            text = 'Sedang';
        } else if (percentage >= 40) {
            color = 'warning';
            text = 'Lemah';
        }
        
        strengthBar.className = 'progress-bar bg-' + color;
        strengthText.textContent = 'Kekuatan Password: ' + text + ' (' + Math.round(percentage) + '%)';
    });
    
    // 18. Validasi konfirmasi password real-time
    const konfirmasi = document.getElementById('konfirmasi_baru');
    const confirmMsg = document.getElementById('confirmMsg');
    
    konfirmasi.addEventListener('input', function() {
        if (this.value.length === 0) {
            confirmMsg.style.display = 'none';
            return;
        }
        
        if (this.value !== passwordBaru.value) {
            confirmMsg.style.display = 'block';
            this.classList.add('is-invalid');
        } else {
            confirmMsg.style.display = 'none';
            this.classList.remove('is-invalid');
            this.classList.add('is-valid');
        }
    });
    
    passwordBaru.addEventListener('input', function() {
        if (konfirmasi.value.length > 0 && konfirmasi.value !== this.value) {
            confirmMsg.style.display = 'block';
            konfirmasi.classList.add('is-invalid');
        } else if (konfirmasi.value.length > 0) {
            confirmMsg.style.display = 'none';
            konfirmasi.classList.remove('is-invalid');
            konfirmasi.classList.add('is-valid');
        }
    });
});
</script>