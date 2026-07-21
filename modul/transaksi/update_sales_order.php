<?php
// modul/transaksi/update_sales_order.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

function parseRupiah($value) {
    if ($value === null || $value === '') {
        return 0;
    }

    $value = trim((string)$value);

    // Hilangkan karakter selain angka, titik, koma, minus
    $value = preg_replace('/[^0-9.,\-]/', '', $value);

    if ($value === '' || $value === '-' || $value === '.' || $value === ',') {
        return 0;
    }

    $hasDot   = strpos($value, '.') !== false;
    $hasComma = strpos($value, ',') !== false;

    if ($hasDot && $hasComma) {
        // Format Indonesia: 1.000.000,50
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif ($hasComma && !$hasDot) {
        // Format: 20000,50
        $value = str_replace(',', '.', $value);
    } elseif ($hasDot && !$hasComma) {
        $dotCount = substr_count($value, '.');

        if ($dotCount > 1) {
            // Format ribuan: 1.000.000
            $value = str_replace('.', '', $value);
        } else {
            // Bisa jadi 20.000 atau 20000.00
            $parts = explode('.', $value);
            $decimalLength = strlen($parts[1] ?? '');

            if ($decimalLength === 3) {
                // Format ribuan: 20.000
                $value = str_replace('.', '', $value);
            }
            // Kalau decimalLength 1 atau 2, biarkan sebagai desimal: 20000.00
        }
    }

    return floatval($value);
}

// ── FUNGSI CEK INVENTORY KHUSUS ──────────────────────────────────────────
function isSpecialInventory($inventoryName) {
    return strpos($inventoryName, "PE ROLL STOKAN SSB") !== false || 
           strpos($inventoryName, "PP ROLL BOLA") !== false;
}

// ── FUNGSI GET PRICE FORMULA FACTOR ──────────────────────────────────────
function getPriceFormulaFactor($inventoryName, $p, $l, $t) {
    $divisor = 1;
    
    // Pengecualian untuk inventory_name tertentu
    if (isSpecialInventory($inventoryName)) {
        if ($p == 50) {
            $divisor = 2;
        } else if ($p == 25) {
            $divisor = 4;
        }
    }
    
    return ($t * 10 * $l) / $divisor;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo "<script>window.location.href='index.php?page=sales_order';</script>";
    exit;
}

$user_now     = mysqli_real_escape_string($conn, $_SESSION['username']);
$datetime_now = date('Y-m-d H:i:s');

// ── Sanitize ──────────────────────────────────────────────────────────
$order_no          = mysqli_real_escape_string($conn, trim($_POST['order_no'] ?? ''));
$order_date        = mysqli_real_escape_string($conn, $_POST['order_date'] ?? '');
$no_po_input       = mysqli_real_escape_string($conn, trim($_POST['no_po'] ?? '')); // TAMBAHAN
$marketing_id      = mysqli_real_escape_string($conn, $_POST['marketing_id'] ?? '');
$sales_id          = mysqli_real_escape_string($conn, $_POST['sales_id'] ?? '');
$customer_id       = mysqli_real_escape_string($conn, $_POST['customer_id'] ?? '');
$customer_name     = mysqli_real_escape_string($conn, trim($_POST['customer_name'] ?? ''));
$customer_address  = mysqli_real_escape_string($conn, trim($_POST['customer_address'] ?? ''));
$customer_city     = mysqli_real_escape_string($conn, trim($_POST['customer_city'] ?? ''));
$station           = mysqli_real_escape_string($conn, trim($_POST['station'] ?? 'FACTORY'));
$shipment_due_date = mysqli_real_escape_string($conn, $_POST['shipment_due_date'] ?? '');
$shipment_location = mysqli_real_escape_string($conn, trim($_POST['shipment_location'] ?? ''));
$tolerance         = floatval($_POST['tolerance'] ?? 10);
$backward_calc     = isset($_POST['backward_calculation']) ? 'Checked' : 'Unchecked';
$payment_term      = mysqli_real_escape_string($conn, $_POST['payment_term'] ?? 'Franco');
$payment_type      = mysqli_real_escape_string($conn, $_POST['payment_type'] ?? 'Cash');
$days              = intval($_POST['days'] ?? 30);
$currency          = mysqli_real_escape_string($conn, $_POST['currency'] ?? 'IDR');
$kurs              = floatval($_POST['kurs'] ?? 1);
// Nilai dikirim oleh edit_sales_order.php sebagai Checked atau Unchecked.
// Jangan gunakan isset(), karena hidden input membuat field ini selalu tersedia.
$allow_auto_correct_input = trim((string)($_POST['allow_auto_correct'] ?? 'Unchecked'));
$allow_auto_correct = strcasecmp($allow_auto_correct_input, 'Checked') === 0
    ? 'Checked'
    : 'Unchecked';
$remarks           = mysqli_real_escape_string($conn, trim($_POST['remarks'] ?? ''));
$status            = mysqli_real_escape_string($conn, $_POST['status'] ?? 'Open');
$approval_status   = mysqli_real_escape_string($conn, $_POST['approval_status'] ?? 'Pending');
$down_payment      = parseRupiah($_POST['down_payment'] ?? 0);

// Grand total dihitung ulang dari detail
$grand_total = 0;

// ── Validasi ──────────────────────────────────────────────────────────
if (empty($order_no)) {
    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>Order No tidak valid!</div>";
    echo "<script>window.history.back();</script>";
    exit;
}

// ── Resolve customer_name ─────────────────────────────────────────────
if (empty($customer_name) && !empty($customer_id)) {
    $q = mysqli_query($conn, "SELECT customer FROM m_customer WHERE customer_id='$customer_id' LIMIT 1");
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $customer_name = mysqli_real_escape_string($conn, $r['customer']);
    }
}

$order_date            = $order_date ?: date('Y-m-d');
$shipment_due_date_sql = $shipment_due_date ? "'$shipment_due_date'" : 'NULL';

// ── Ambil no_po dari head_sales_order ─────────────────────────────────
// Gunakan no_po dari input jika ada, atau ambil dari database
$no_po = $no_po_input;

// Jika no_po kosong, ambil dari database
if (empty($no_po)) {
    $q_po = mysqli_query($conn, "SELECT po FROM head_sales_order WHERE order_no='$order_no' LIMIT 1");
    if ($q_po && $r_po = mysqli_fetch_assoc($q_po)) {
        $no_po = $r_po['po'];
    }
}

// ── Transaction: update head + replace detail ─────────────────────────
mysqli_begin_transaction($conn);

try {
    // Hapus detail lama
    if (!mysqli_query($conn, "DELETE FROM detail_sales_order WHERE order_no='$order_no'")) {
        throw new Exception('Hapus detail gagal: ' . mysqli_error($conn));
    }

    // Hapus detail PO lama
    if (!empty($no_po)) {
        if (!mysqli_query($conn, "DELETE FROM det_po WHERE no_po='$no_po'")) {
            throw new Exception('Hapus detail PO gagal: ' . mysqli_error($conn));
        }
    }

    // Insert detail baru
    $inventory_ids   = $_POST['inventory_id'] ?? [];
    $inventory_names = $_POST['inventory_name'] ?? [];
    $quantities      = $_POST['quantity'] ?? [];
    $uoms            = $_POST['uom'] ?? [];
    $quantity_packs  = $_POST['quantity_pack'] ?? [];
    $uom_packs       = $_POST['uom_pack'] ?? [];
    $uom_details     = $_POST['uom_detail'] ?? [];
    $price_units     = $_POST['price_unit'] ?? [];
    $prices          = $_POST['price'] ?? [];
    $remarks_details = $_POST['remarks_detail'] ?? [];

    $detail_count = 0;

    for ($i = 0; $i < count($inventory_ids); $i++) {
        $inv_id   = trim($inventory_ids[$i] ?? '');
        $inv_name = trim($inventory_names[$i] ?? '');
        $qty      = floatval($quantities[$i] ?? 0);

        if (empty($inv_id) && empty($inv_name) && $qty <= 0) {
            continue;
        }

        $qty_pack   = floatval($quantity_packs[$i] ?? 0);
        $price_unit = parseRupiah($price_units[$i] ?? 0);
        $price      = parseRupiah($prices[$i] ?? 0);

        // ============================================================
        // LOGIKA PERHITUNGAN PRICE DAN SUBTOTAL
        // ============================================================
        
        // Cek apakah inventory khusus
        $isSpecial = isSpecialInventory($inv_name);
        
        // Ambil data p, l, t dari database jika tersedia
        $p = 0;
        $l = 0; 
        $t = 0;
        
        if (!empty($inv_id)) {
            $q_inv = mysqli_query($conn, "SELECT p, l, t FROM m_inventory WHERE inventory_id='$inv_id' LIMIT 1");
            if ($q_inv && $r_inv = mysqli_fetch_assoc($q_inv)) {
                $p = floatval($r_inv['p'] ?? 0);
                $l = floatval($r_inv['l'] ?? 0);
                $t = floatval($r_inv['t'] ?? 0);
            }
        }
        
        // Untuk inventory khusus: Price Unit bisa diisi, Price dihitung dari rumus
        // Untuk inventory non-khusus: Price Unit = 0, Price diisi manual
        if ($isSpecial) {
            // Hitung faktor rumus
            $factor = getPriceFormulaFactor($inv_name, $p, $l, $t);
            
            // Jika Price Unit diisi, hitung Price dari Price Unit
            if ($price_unit > 0 && $factor > 0) {
                $price = $price_unit * $factor;
            } 
            // Jika Price diisi manual, hitung Price Unit dari Price
            else if ($price > 0 && $factor > 0) {
                $price_unit = $price / $factor;
            }
        } else {
            // Inventory non-khusus: Price Unit harus 0, hanya Price yang bisa diisi
            $price_unit = 0;
            // Price tetap dari input user
        }

        // Subtotal = Price x Qty Pack (sesuai poin 8)
        $subtotal = $qty_pack * $price;

        $grand_total += $subtotal;

        $sql_det = "INSERT INTO detail_sales_order (
            order_no, inventory_id, inventory_name, quantity, uom,
            quantity_pack, uom_pack, uom_detail,
            price_unit, price, subtotal, remarks
        ) VALUES (
            '$order_no',
            '" . mysqli_real_escape_string($conn, $inv_id) . "',
            '" . mysqli_real_escape_string($conn, $inv_name) . "',
            '$qty',
            '" . mysqli_real_escape_string($conn, $uoms[$i] ?? '') . "',
            '$qty_pack',
            '" . mysqli_real_escape_string($conn, $uom_packs[$i] ?? '') . "',
            '" . mysqli_real_escape_string($conn, $uom_details[$i] ?? '') . "',
            '$price_unit',
            '$price',
            '$subtotal',
            '" . mysqli_real_escape_string($conn, $remarks_details[$i] ?? '') . "'
        )";

        if (!mysqli_query($conn, $sql_det)) {
            throw new Exception('Insert detail gagal: ' . mysqli_error($conn));
        }

        // Insert detail PO
        if (!empty($no_po)) {
            $harga_po = $price > 0 ? $price : $price_unit;
            
            $sql_det_po = "INSERT INTO det_po (
                no_po, ukuran, jml_order, harga, harga_kg
            ) VALUES (
                '$no_po',
                '" . mysqli_real_escape_string($conn, $inv_name) . "',
                '$qty',
                '$harga_po',
                '$harga_po'
            )";

            if (!mysqli_query($conn, $sql_det_po)) {
                throw new Exception('Insert detail PO gagal: ' . mysqli_error($conn));
            }
        }

        $detail_count++;
    }

    // Update head_sales_order setelah grand_total dihitung ulang
    $sql_head = "UPDATE head_sales_order SET
        order_date        = '$order_date',
        marketing_id      = '$marketing_id',
        sales_id          = '$sales_id',
        customer_id       = '$customer_id',
        customer_name     = '$customer_name',
        customer_address  = '$customer_address',
        customer_city     = '$customer_city',
        station           = '$station',
        shipment_due_date = $shipment_due_date_sql,
        shipment_location = '$shipment_location',
        tolerance         = '$tolerance',
        backward_calculation = '$backward_calc',
        payment_term      = '$payment_term',
        payment_type      = '$payment_type',
        days              = '$days',
        currency          = '$currency',
        kurs              = '$kurs',
        allow_auto_correct= '$allow_auto_correct',
        remarks           = '$remarks',
        status            = '$status',
        approval_status   = '$approval_status',
        grand_total       = '$grand_total',
        down_payment      = '$down_payment',
        user_modified     = '$user_now',
        date_modified     = '$datetime_now'
    WHERE order_no = '$order_no'";

    if (!mysqli_query($conn, $sql_head)) {
        throw new Exception('Update header gagal: ' . mysqli_error($conn));
    }

    // ── TAMBAHAN: Update hed_po jika perlu ──────────────────────────────
    if (!empty($no_po)) {
        // Cek apakah data sudah ada di hed_po
        $cek_hed = mysqli_query($conn, "SELECT no_po FROM hed_po WHERE no_po='$no_po' LIMIT 1");
        if ($cek_hed && mysqli_num_rows($cek_hed) > 0) {
            // Update hed_po
            $sql_update_po = "UPDATE hed_po SET
                tgl_order = '$order_date',
                customer = '$customer_name',
                customer_id = '$customer_id',
                created_by = '$user_now',
                created_at = '$datetime_now'
                WHERE no_po = '$no_po'";
            
            if (!mysqli_query($conn, $sql_update_po)) {
                throw new Exception('Update hed_po gagal: ' . mysqli_error($conn));
            }
        } else {
            // Insert baru ke hed_po jika belum ada
            $sql_insert_po = "INSERT INTO hed_po (
                no_po, tgl_order, customer, customer_id, created_by, created_at
            ) VALUES (
                '$no_po', '$order_date', '$customer_name', '$customer_id', '$user_now', '$datetime_now'
            )";
            
            if (!mysqli_query($conn, $sql_insert_po)) {
                throw new Exception('Insert hed_po gagal: ' . mysqli_error($conn));
            }
        }
    }

    mysqli_commit($conn);

    $_SESSION['alert'] = "
        <div class='alert alert-success p-2 small'>
            <strong>✅ Sales Order <code>$order_no</code> berhasil diupdate!</strong><br>
            • No PO: <strong>$no_po</strong><br>
            • $detail_count item detail tersimpan<br>
            • Grand Total: <strong>Rp " . number_format($grand_total, 0, ',', '.') . "</strong><br>
            • Down Payment: Rp " . number_format($down_payment, 0, ',', '.') . "<br>
            • Sisa Bayar: Rp " . number_format($grand_total - $down_payment, 0, ',', '.') . "
        </div>";

    echo "<script>window.location.href='index.php?page=sales_order';</script>";

} catch (Exception $e) {
    mysqli_rollback($conn);

    $_SESSION['alert'] = "<div class='alert alert-danger p-2 small'>
        <strong>❌ Gagal update!</strong> " . htmlspecialchars($e->getMessage()) . "
    </div>";

    echo "<script>window.location.href='index.php?page=sales_order';</script>";
}

exit;