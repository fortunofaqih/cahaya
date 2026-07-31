<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/*
 * Sesuaikan bila file koneksi aplikasi Anda berbeda.
 * File ini mencoba beberapa lokasi koneksi yang umum dipakai.
 */
$connectionCandidates = [
    __DIR__ . '/../../config/koneksi.php',
    __DIR__ . '/../../config/database.php',
    __DIR__ . '/../config/koneksi.php',
    __DIR__ . '/../config/database.php',
    __DIR__ . '/koneksi.php',
];

foreach ($connectionCandidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        break;
    }
}

if (!isset($conn) && isset($mysqli) && $mysqli instanceof mysqli) {
    $conn = $mysqli;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException(
        'Koneksi database tidak ditemukan. Sesuaikan return_bootstrap.php agar menghasilkan variabel $conn.'
    );
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function current_user_name(): string
{
    return (string)(
        $_SESSION['username']
        ?? $_SESSION['user_name']
        ?? $_SESSION['user']
        ?? 'SYSTEM'
    );
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parse_date_input(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd-M-Y', 'd/m/Y', 'd-m-Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        if ($date && $date->format($format) === $value) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function money(float $value): string
{
    return number_format($value, 2, '.', ',');
}

function decimal_value(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace([',', ' '], ['', ''], $value);
    }
    return is_numeric($value) ? (float)$value : 0.0;
}

function redirect_with_message(string $url, string $type, string $message): never
{
    $_SESSION['return_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
    header('Location: ' . $url);
    exit;
}

function consume_flash(): ?array
{
    $flash = $_SESSION['return_flash'] ?? null;
    unset($_SESSION['return_flash']);
    return is_array($flash) ? $flash : null;
}

function get_return_url(string $file, array $params = []): string
{
    $url = $file;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    return $url;
}
