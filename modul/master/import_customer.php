<?php
// import_customer.php - Letakkan di folder modul/master/

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

$alert_message = "";
$success_count = 0;
$error_count = 0;
$duplicate_count = 0;
$errors = array();
$debug_info = array();
$sample_data = array();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        $file_name = $_FILES['csv_file']['name'];
        
        $truncate_first = isset($_POST['truncate_first']) ? true : false;
        
        if ($truncate_first) {
            mysqli_query($conn, "TRUNCATE TABLE m_customer");
            $debug_info[] = "Table telah dikosongkan terlebih dahulu";
        }
        
        if (($handle = fopen($file_tmp, "r")) !== FALSE) {
            // Baca header (baris pertama)
            $headers = fgetcsv($handle, 0, ",");
            $debug_info[] = "Header ditemukan: " . count($headers) . " kolom";
            $debug_info[] = "Header: " . implode(", ", array_slice($headers, 0, 10)) . "...";
            
            // Mapping header dari CSV ke database (sesuai dengan header CSV Anda)
            $mapping = array(
                'ID' => 'customer_id',
                'Customer' => 'customer',
                'City' => 'city',
                'Address' => 'address',
                'NPWP_Address' => 'npwp_address',
                'Contact Person' => 'contact_person',
                'Contact Person Phone' => 'contact_person_phone',
                'Contact Person Mobile Phone' => 'contact_person_mobile',
                'NPWP' => 'npwp',
                'ID Number' => 'id_number',
                'ID Name' => 'id_name',
                'Phone' => 'phone',
                'Fax' => 'fax',
                'Credit Limit' => 'credit_limit',
                'Email' => 'email',
                'Old Code' => 'old_code',
                'Area Code' => 'area_code',
                'Remarks' => 'remarks',
                'Type' => 'type',
                'Tax Type' => 'tax_type',
                'Parent ID' => 'parent_id',
                'Parent Customer' => 'parent_customer',
                'ID TKU' => 'id_tku',
                'Bagian' => 'bagian',
                'Transaction Tax' => 'transaction_tax',
                'Transaction Tax Child' => 'transaction_tax_child',
                'Is Active' => 'is_active',
                'User Created' => 'user_created',
                'Date Created' => 'date_created',
                'User Modified' => 'user_modified',
                'Date Modified' => 'date_modified'
            );
            
            $user_now = $_SESSION['username'];
            $datetime_now = date('Y-m-d H:i:s');
            $row_number = 1;
            
            while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                $row_number++;
                
                // Lewati baris kosong
                if (count($data) < 2 || empty(trim($data[0]))) {
                    $debug_info[] = "Baris $row_number: data kosong atau tidak lengkap, dilewati";
                    continue;
                }
                
                // Buat array asosiatif dari data CSV
                $row = array();
                foreach ($headers as $index => $header) {
                    $header_clean = trim($header);
                    // Cari mapping berdasarkan header (case-sensitive)
                    if (isset($mapping[$header_clean]) && isset($data[$index])) {
                        $value = trim($data[$index]);
                        if ($value === '' || $value === 'NULL') {
                            $row[$mapping[$header_clean]] = null;
                        } else {
                            $row[$mapping[$header_clean]] = $value;
                        }
                    }
                }
                
                // Debug untuk 5 data pertama
                if ($row_number <= 6) {
                    $sample_data[] = "Baris $row_number: customer_id = " . ($row['customer_id'] ?? 'KOSONG');
                }
                
                // Validasi - coba gunakan kolom pertama sebagai ID jika mapping gagal
                if (empty($row['customer_id'])) {
                    // Coba ambil dari kolom pertama (index 0)
                    if (!empty($data[0])) {
                        $row['customer_id'] = trim($data[0]);
                        $debug_info[] = "Baris $row_number: Menggunakan kolom pertama sebagai customer_id: " . $row['customer_id'];
                    } else {
                        $error_count++;
                        $errors[] = "Baris $row_number: customer_id kosong, data dilewati";
                        continue;
                    }
                }
                
                if (empty($row['customer'])) {
                    // Coba ambil dari kolom kedua jika ada
                    if (!empty($data[1])) {
                        $row['customer'] = trim($data[1]);
                    } else {
                        $error_count++;
                        $errors[] = "Baris $row_number: Nama customer kosong untuk ID {$row['customer_id']}";
                        continue;
                    }
                }
                
                // Escape semua nilai
                $customer_id = mysqli_real_escape_string($conn, $row['customer_id']);
                $customer = mysqli_real_escape_string($conn, $row['customer']);
                $city = isset($row['city']) ? mysqli_real_escape_string($conn, $row['city']) : '';
                $address = isset($row['address']) ? mysqli_real_escape_string($conn, $row['address']) : '';
                $npwp_address = isset($row['npwp_address']) ? mysqli_real_escape_string($conn, $row['npwp_address']) : '';
                $contact_person = isset($row['contact_person']) ? mysqli_real_escape_string($conn, $row['contact_person']) : '';
                $contact_person_phone = isset($row['contact_person_phone']) ? mysqli_real_escape_string($conn, $row['contact_person_phone']) : '';
                $npwp = isset($row['npwp']) ? mysqli_real_escape_string($conn, $row['npwp']) : '';
                $id_number = isset($row['id_number']) ? mysqli_real_escape_string($conn, $row['id_number']) : '';
                $id_name = isset($row['id_name']) ? mysqli_real_escape_string($conn, $row['id_name']) : '';
                $phone = isset($row['phone']) ? mysqli_real_escape_string($conn, $row['phone']) : '';
                $fax = isset($row['fax']) ? mysqli_real_escape_string($conn, $row['fax']) : '';
                
                // Proses credit_limit
                $credit_limit = 0;
                if (isset($row['credit_limit']) && !empty($row['credit_limit'])) {
                    $credit_limit = str_replace(array(',', '.', ' ', 'Rp'), '', $row['credit_limit']);
                    $credit_limit = floatval($credit_limit);
                }
                
                $email = isset($row['email']) ? mysqli_real_escape_string($conn, $row['email']) : '';
                $old_code = isset($row['old_code']) ? mysqli_real_escape_string($conn, $row['old_code']) : '';
                $area_code = isset($row['area_code']) ? mysqli_real_escape_string($conn, $row['area_code']) : '';
                $remarks = isset($row['remarks']) ? mysqli_real_escape_string($conn, $row['remarks']) : '';
                $type = isset($row['type']) ? mysqli_real_escape_string($conn, $row['type']) : 'Lokal';
                $tax_type = isset($row['tax_type']) ? mysqli_real_escape_string($conn, $row['tax_type']) : 'PPN';
                $parent_id = isset($row['parent_id']) ? mysqli_real_escape_string($conn, $row['parent_id']) : '';
                $parent_customer = isset($row['parent_customer']) ? mysqli_real_escape_string($conn, $row['parent_customer']) : '';
                $id_tku = isset($row['id_tku']) ? mysqli_real_escape_string($conn, $row['id_tku']) : '';
                $bagian = isset($row['bagian']) ? mysqli_real_escape_string($conn, $row['bagian']) : '';
                $transaction_tax = isset($row['transaction_tax']) ? mysqli_real_escape_string($conn, $row['transaction_tax']) : '';
                $transaction_tax_child = isset($row['transaction_tax_child']) ? mysqli_real_escape_string($conn, $row['transaction_tax_child']) : '';
                $is_active = (isset($row['is_active']) && $row['is_active'] == 'Checked') ? 'Checked' : 'Unchecked';
                
                // Handle tanggal
                $date_created = $datetime_now;
                if (!empty($row['date_created'])) {
                    $date_created_tmp = date('Y-m-d H:i:s', strtotime($row['date_created']));
                    if ($date_created_tmp) $date_created = $date_created_tmp;
                }
                
                $user_created = !empty($row['user_created']) ? mysqli_real_escape_string($conn, $row['user_created']) : $user_now;
                
                // Cek apakah customer_id sudah ada
                $cek = mysqli_query($conn, "SELECT customer_id FROM m_customer WHERE customer_id = '$customer_id'");
                
                if (mysqli_num_rows($cek) > 0) {
                    // UPDATE
                    $sql = "UPDATE m_customer SET 
                            customer='$customer', city='$city', address='$address',
                            npwp_address='$npwp_address', contact_person='$contact_person',
                            contact_person_phone='$contact_person_phone', npwp='$npwp',
                            id_number='$id_number', id_name='$id_name', phone='$phone',
                            fax='$fax', credit_limit='$credit_limit', email='$email',
                            old_code='$old_code', area_code='$area_code', remarks='$remarks',
                            type='$type', tax_type='$tax_type', parent_id='$parent_id',
                            parent_customer='$parent_customer', id_tku='$id_tku',
                            bagian='$bagian', transaction_tax='$transaction_tax',
                            transaction_tax_child='$transaction_tax_child', is_active='$is_active',
                            user_modified='$user_now', date_modified=NOW()
                            WHERE customer_id='$customer_id'";
                    
                    if (mysqli_query($conn, $sql)) {
                        $success_count++;
                        if ($success_count <= 5) {
                            $debug_info[] = "UPDATE: $customer_id - $customer";
                        }
                    } else {
                        $error_count++;
                        $errors[] = "Baris $row_number - Update $customer_id: " . mysqli_error($conn);
                    }
                } else {
                    // INSERT baru
                    $sql = "INSERT INTO m_customer (
                        customer_id, customer, city, address, npwp_address, contact_person,
                        contact_person_phone, npwp, id_number, id_name, phone, fax,
                        credit_limit, email, old_code, area_code, remarks, type,
                        tax_type, parent_id, parent_customer, id_tku, bagian,
                        transaction_tax, transaction_tax_child, is_active,
                        user_created, date_created
                    ) VALUES (
                        '$customer_id', '$customer', '$city', '$address', '$npwp_address', '$contact_person',
                        '$contact_person_phone', '$npwp', '$id_number', '$id_name', '$phone', '$fax',
                        '$credit_limit', '$email', '$old_code', '$area_code', '$remarks', '$type',
                        '$tax_type', '$parent_id', '$parent_customer', '$id_tku', '$bagian',
                        '$transaction_tax', '$transaction_tax_child', '$is_active',
                        '$user_created', '$date_created'
                    )";
                    
                    if (mysqli_query($conn, $sql)) {
                        $success_count++;
                        if ($success_count <= 5) {
                            $debug_info[] = "INSERT: $customer_id - $customer";
                        }
                    } else {
                        $error_count++;
                        $errors[] = "Baris $row_number - Insert $customer_id: " . mysqli_error($conn);
                    }
                }
                
                // Batasi debug info
                if (count($debug_info) > 30) {
                    array_shift($debug_info);
                }
            }
            fclose($handle);
            
            // Cek jumlah data
            $count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM m_customer");
            $total_data = mysqli_fetch_assoc($count_query);
            
            $alert_message = "
            <div class='alert alert-success'>
                <strong>✅ Import Selesai!</strong><br>
                ✅ Berhasil: " . number_format($success_count) . " data<br>
                ❌ Gagal: " . number_format($error_count) . " data<br>
                📊 Total data di database: " . number_format($total_data['total']) . " data
            </div>";
            
            if (!empty($sample_data)) {
                $alert_message .= "
                <div class='alert alert-info'>
                    <strong>📋 Sample Data yang dibaca (5 baris pertama):</strong><br>
                    " . implode("<br>", $sample_data) . "
                </div>";
            }
            
            if (!empty($errors)) {
                $alert_message .= "
                <div class='alert alert-warning' style='max-height: 300px; overflow-y: auto;'>
                    <strong>⚠️ Error Details (10 data teratas):</strong><br>
                    " . implode("<br>", array_slice($errors, 0, 10)) . "
                </div>";
            }
            
            if (count($debug_info) > 0) {
                $alert_message .= "
                <div class='alert alert-secondary' style='max-height: 200px; overflow-y: auto; font-size: 11px;'>
                    <strong>📝 Debug Info:</strong><br>
                    " . implode("<br>", $debug_info) . "
                </div>";
            }
        }
    } else {
        $alert_message = "<div class='alert alert-danger'>Error upload file: " . $_FILES['csv_file']['error'] . "</div>";
    }
}

// Cek jumlah data saat ini
$count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM m_customer");
$current_total = mysqli_fetch_assoc($count_query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Import Customer CSV</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .instructions { background: #e7f3ff; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .stats-box { background: #f8f9fa; padding: 10px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h2><i class="fa fa-upload"></i> Import Master Customer</h2>
        
        <?= $alert_message ?>
        
        <div class="stats-box">
            <strong>📊 Status Database Saat Ini:</strong><br>
            Total data customer: <strong><?= number_format($current_total['total']) ?></strong> data
        </div>
        
        <div class="instructions">
            <h5><i class="fa fa-info-circle"></i> Petunjuk:</h5>
            <ol>
                <li>Pastikan file CSV dalam format UTF-8</li>
                <li>Kolom pertama (ID) harus berisi customer_id yang valid</li>
                <li>Kolom kedua (Customer) harus berisi nama customer</li>
                <li>Data akan di-<strong>UPDATE</strong> jika ID sudah ada</li>
                <li>Data akan di-<strong>INSERT</strong> jika ID baru</li>
            </ol>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="mb-3">
                <label class="form-label">Pilih File CSV:</label>
                <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                <small class="text-muted">File harus berformat .csv dengan encoding UTF-8</small>
            </div>
            
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" name="truncate_first" id="truncate_first">
                <label class="form-check-label text-warning" for="truncate_first">
                    Kosongkan tabel terlebih dahulu (Hapus semua data yang ada)
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-upload"></i> Import Data
            </button>
            <a href="index.php?page=customer" class="btn btn-secondary">
                <i class="fa fa-arrow-left"></i> Kembali ke Master Customer
            </a>
        </form>
        
        <hr>
        
        <h5>Cek Header CSV Anda:</h5>
        <div class="alert alert-light border">
            <strong>Header yang terdeteksi dari file Anda:</strong><br>
            <?php
            // Coba baca header dari file yang diupload (jika ada)
            if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
                $temp_handle = fopen($file_tmp, "r");
                $temp_headers = fgetcsv($temp_handle, 0, ",");
                fclose($temp_handle);
                echo "<code>" . implode(" | ", $temp_headers) . "</code>";
            } else {
                echo "<em>Upload file terlebih dahulu untuk melihat header</em>";
            }
            ?>
        </div>
        
        <h5>Format CSV yang diharapkan:</h5>
        <pre style="background: #f0f0f0; padding: 10px; font-size: 11px; overflow-x: auto;">
ID,Customer,City,Address,NPWP,Phone,Credit Limit,Email,Area Code,Type,Is Active
C0000003,PT. SYENSQO MANYAR,GRESIK,"JL RAYA SEMBAYAT KM.24",0014519342641000,3950388,0,-,INDUSTRI,C,Checked
C0000007,PT. BUMI MENARA INTERNUSA,SURABAYA,"JL MARGOMULYO NO 4E",0014540199631000,7491000,100000000,-,INDUSTRI,C,Checked
        </pre>
    </div>
</body>
</html>