<?php
// modul/master/ajax_inventory.php

session_start();

// =====================================================
// AJAX INVENTORY HANDLER
// Output wajib JSON bersih.
// =====================================================

ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/ajax_inventory_error.log');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

function sendJson($payload, $httpCode = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['username'])) {
    sendJson([
        'status'  => 'error',
        'message' => 'Session expired'
    ], 401);
}

include __DIR__ . '/../../koneksi.php';

if (!isset($conn) || !$conn) {
    sendJson([
        'status'  => 'error',
        'message' => 'Database connection failed'
    ], 500);
}

$ajax = $_GET['ajax'] ?? '';

// =====================================================
// Handler: get_inventory_uom
// URL: modul/master/ajax_inventory.php?ajax=get_inventory_uom&id=CP-xxx
// =====================================================
if ($ajax === 'get_inventory_uom') {
    $inventoryId = trim($_GET['id'] ?? '');

    if ($inventoryId === '') {
        sendJson([
            'status'  => 'error',
            'message' => 'Inventory ID kosong'
        ], 400);
    }

    $stmt = mysqli_prepare($conn, "
        SELECT inventory_id, unit, `Default`, `Value`
        FROM m_inventory_uom
        WHERE inventory_id = ?
        ORDER BY `Default` DESC, unit ASC
    ");

    if (!$stmt) {
        error_log('Prepare get_inventory_uom failed: ' . mysqli_error($conn));
        sendJson([
            'status'  => 'error',
            'message' => 'Gagal menyiapkan query UOM inventory'
        ], 500);
    }

    mysqli_stmt_bind_param($stmt, 's', $inventoryId);

    if (!mysqli_stmt_execute($stmt)) {
        error_log('Execute get_inventory_uom failed: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        sendJson([
            'status'  => 'error',
            'message' => 'Gagal mengambil data UOM inventory'
        ], 500);
    }

    $result = mysqli_stmt_get_result($stmt);
    $data = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $unit = $row['unit'] ?? '';
        $default = (int)($row['Default'] ?? 0);
        $value = (float)($row['Value'] ?? 0);

        $data[] = [
            'inventory_id' => $row['inventory_id'] ?? $inventoryId,
            'Uom'         => $unit,
            'unit'        => $unit,
            'Default'     => $default,
            'is_default'  => $default === 1 ? 'Checked' : 'Unchecked',
            'Value'       => $value,
            'value_roll'  => $value
        ];
    }

    mysqli_stmt_close($stmt);

    sendJson([
        'status' => 'success',
        'data'   => $data
    ]);
}

// =====================================================
// Handler: get_uom_list
// URL: modul/master/ajax_inventory.php?ajax=get_uom_list
// =====================================================
if ($ajax === 'get_uom_list') {
    // Kompatibel jika m_uom pakai kolom unit atau uom_id.
    $columns = [];
    $colQuery = mysqli_query($conn, "SHOW COLUMNS FROM m_uom");

    if ($colQuery) {
        while ($col = mysqli_fetch_assoc($colQuery)) {
            $columns[] = $col['Field'];
        }
    }

    if (in_array('unit', $columns, true)) {
        $uomColumn = 'unit';
    } elseif (in_array('uom_id', $columns, true)) {
        $uomColumn = 'uom_id';
    } else {
        sendJson([
            'status'  => 'error',
            'message' => 'Kolom unit/uom_id tidak ditemukan pada tabel m_uom'
        ], 500);
    }

    $activeWhere = '';
    if (in_array('is_active', $columns, true)) {
        $activeWhere = "WHERE is_active = 'Checked'";
    }

    $sql = "SELECT `$uomColumn` AS unit FROM m_uom $activeWhere ORDER BY `$uomColumn` ASC";
    $query = mysqli_query($conn, $sql);

    if (!$query) {
        error_log('Query get_uom_list failed: ' . mysqli_error($conn));
        sendJson([
            'status'  => 'error',
            'message' => 'Gagal mengambil daftar UOM'
        ], 500);
    }

    $list = [];
    while ($row = mysqli_fetch_assoc($query)) {
        $unit = trim($row['unit'] ?? '');
        if ($unit !== '') {
            $list[] = ['unit' => $unit];
        }
    }

    sendJson([
        'status' => 'success',
        'data'   => $list
    ]);
}

sendJson([
    'status'  => 'error',
    'message' => 'Invalid AJAX request'
], 400);
