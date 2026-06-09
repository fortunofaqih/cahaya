<?php
session_start();
// Jika user sudah login, langsung alihkan ke halaman utama
if (isset($_SESSION['username'])) {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ERP Pabrik Plastik</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f4f6f9;
            height: 100vh;
        }
        .login-container {
            max-width: 400px;
            width: 100%;
        }
        .card {
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center">

<div class="login-container p-3">
    <div class="card">
        <div class="card-body p-4">
            <h4 class="text-center fw-bold text-primary mb-1">PT. Mutiaracahaya Plastindo</h4>
            <p class="text-center text-muted small mb-4">ERP System</p>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger p-2 small text-center" role="alert">
                    <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <form action="cek_login.php" method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label small fw-bold">Username</label>
                    <input type="text" name="username" id="username" class="form-control form-control-sm" placeholder="Masukkan username" required autocomplete="off">
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label small fw-bold">Password</label>
                    <input type="password" name="password" id="password" class="form-control form-control-sm" placeholder="Masukkan password" required>
                </div>
                <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">Masuk ke Sistem</button>
            </form>
        </div>
    </div>
    <div class="text-center mt-3 text-muted" style="font-size: 11px;">
        &copy; <?= date('Y'); ?> IT Department - PT Cahaya
    </div>
    <div class="text-center mt-3 text-muted" style="font-size: 11px;">
       admin , 123456
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>