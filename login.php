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
    <title>Login - ERP PT Mutiara Cahaya Plastindo</title>
    <link rel="icon" type="image/png" href="assets/img/logo_mcp.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <style>
        :root {
            --primary-color: #0d6efd;
            --overlay-color: rgba(15, 23, 42, 0.6); /* Gelap modern */
        }

      body {
            /* Menggunakan gambar lokal background01.jpg dengan overlay gelap */
            background: linear-gradient(var(--overlay-color), var(--overlay-color)), 
                        url('assets/img/background02.jpg') no-repeat center center fixed;
            background-size: cover;
            height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .login-container {
            max-width: 420px;
            width: 100%;
        }

        /* Efek Glassmorphism Modern */
        .card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 16px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 12px 40px 0 rgba(0, 0, 0, 0.4);
        }

        .brand-logo {
            max-height: 50px;
            object-fit: contain;
        }

        .form-control {
            border-radius: 8px;
            padding: 10px 14px;
            border: 1px solid #ced4da;
            transition: all 0.2s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
        }

        .btn-login {
            border-radius: 8px;
            padding: 10px;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.2s;
        }

        .btn-login:hover {
            transform: translateY(-1px);
        }

        .footer-text {
            color: rgba(255, 255, 255, 0.7);
            font-size: 11px;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">

<div class="login-container">
    <div class="card">
        <div class="card-body p-4 p-sm-5">
            <div class="text-center mb-4">
                <img src="assets/img/logo_mcp.png" alt="Logo MCP" class="brand-logo mb-2" onerror="this.style.display='none'">
                <h4 class="fw-bold text-dark mb-0" style="letter-spacing: 1px;">CP SYSTEM</h4>
                <p class="text-muted small">Enterprise Resource Planning</p>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger p-3 small rounded-3 d-flex align-items-center mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div>
                        <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                </div>
            <?php endif; ?>

            <form action="cek_login.php" method="POST">
                <div class="mb-3">
                    <label for="username" class="form-label small fw-semibold text-secondary">Username</label>
                    <input type="text" name="username" id="username" class="form-control" placeholder="Masukkan username Anda" required autocomplete="off">
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label small fw-semibold text-secondary">Password</label>
                    <input type="password" name="password" id="password" class="form-control" placeholder="Masukkan password Anda" required>
                </div>
                <button type="submit" class="btn btn-primary btn-login w-100 shadow-sm">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Masuk ke Sistem
                </button>
            </form>
        </div>
    </div>
    
    <div class="text-center mt-4 footer-text">
        &copy; <?= date('Y'); ?> IT Department - PT Mutiaracahaya Plastindo
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>