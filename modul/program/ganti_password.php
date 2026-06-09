<?php
// modul/program/ganti_password.php
include __DIR__ . '/../../koneksi.php';

$id_user = $_SESSION['id_user'];
$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $password_lama = trim($_POST['password_lama']);
    $password_baru = trim($_POST['password_baru']);
    $konfirmasi   = trim($_POST['konfirmasi_baru']);

    if ($password_baru !== $konfirmasi) {
        $error_msg = "Konfirmasi password baru tidak cocok!";
    } elseif (strlen($password_baru) < 5) {
        $error_msg = "Password baru minimal 5 karakter!";
    } else {
        // Ambil password lama di DB menggunakan prepared statement
        $query_cek = $conn->prepare("SELECT password FROM sys_users WHERE id_user = ?");
        $query_cek->bind_param("i", $id_user);
        $query_cek->execute();
        $result = $query_cek->get_result();
        
        if ($result->num_rows === 1) {
            $d = $result->fetch_assoc();

            if (password_verify($password_lama, $d['password'])) {
                // Jika benar, lakukan Hash Bcrypt untuk password baru
                $password_hash_baru = password_hash($password_baru, PASSWORD_BCRYPT);
                
                $update = $conn->prepare("UPDATE sys_users SET password = ? WHERE id_user = ?");
                $update->bind_param("si", $password_hash_baru, $id_user);
                
                if ($update->execute()) {
                    $success_msg = "Password Berhasil Diperbarui!";
                } else {
                    $error_msg = "Gagal memperbarui database!";
                }
            } else {
                $error_msg = "Password lama Anda salah!";
            }
        } else {
            $error_msg = "User tidak ditemukan!";
        }
        $query_cek->close();
    }
}
?>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h4"><i class="fa fa-key text-warning"></i> Keamanan: Ganti Password</h1>
</div>

<div class="row">
    <div class="col-md-6 col-sm-12">
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white fw-bold py-2">Form Ubah Password (Bcrypt Encryption)</div>
            <div class="card-body p-3">
                
                <?php if($success_msg): ?>
                    <div class="alert alert-success p-2 small"><?= $success_msg; ?></div>
                <?php endif; ?>
                <?php if($error_msg): ?>
                    <div class="alert alert-danger p-2 small"><?= $error_msg; ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Password Lama</label>
                        <input type="password" name="password_lama" class="form-control form-control-sm" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-bold">Password Baru</label>
                        <input type="password" name="password_baru" class="form-control form-control-sm" required minlength="5">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Konfirmasi Password Baru</label>
                        <input type="password" name="konfirmasi_baru" class="form-control form-control-sm" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary fw-bold"><i class="fa fa-save"></i> Update Password</button>
                    <button type="button" class="btn btn-sm btn-secondary fw-bold" onclick="window.location.href='index.php'"><i class="fa fa-arrow-left"></i> Kembali</button>
                </form>
            </div>
        </div>
    </div>
</div>