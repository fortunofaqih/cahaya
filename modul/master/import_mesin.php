<?php
// modul/master/import_mesin.php

session_start();

if (!isset($_SESSION['username'])) {
    echo "<script>window.location.href='../../login.php';</script>";
    exit;
}

include __DIR__ . '/../../koneksi.php';

$alert_message = "";
$success_count = 0;
$error_count = 0;
$errors = array();
$debug_info = array();

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['csv_file'])) {
    if ($_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
        $file_tmp = $_FILES['csv_file']['tmp_name'];
        
        $truncate_first = isset($_POST['truncate_first']) ? true : false;
        
        if ($truncate_first) {
            mysqli_query($conn, "TRUNCATE TABLE m_mesin");
            $debug_info[] = "Table telah dikosongkan terlebih dahulu";
        }
        
        if (($handle = fopen($file_tmp, "r")) !== FALSE) {
            $headers = fgetcsv($handle, 0, ",");
            $debug_info[] = "Header: " . implode(", ", array_slice($headers, 0, 8)) . "...";
            
            $row_number = 1;
            
            while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
                $row_number++;
                
                if (count($data) < 2 || empty(trim($data[0]))) {
                    continue;
                }
                
                // Mapping berdasarkan urutan kolom CSV
                $mesin_id = trim($data[0] ?? '');
                $name = trim($data[1] ?? '');
                $spec = trim($data[2] ?? '');
                $manufactured_date = !empty($data[3]) ? date('Y-m-d', strtotime($data[3])) : NULL;
                $manufactured_by = trim($data[4] ?? '');
                $supplier = trim($data[5] ?? '');
                $purchase_price = floatval(str_replace(',', '', $data[6] ?? 0));
                $purchase_date = !empty($data[7]) ? date('Y-m-d', strtotime($data[7])) : NULL;
                $acc_reff = trim($data[8] ?? '');
                $remarks = trim($data[9] ?? '');
                $active = (trim($data[10] ?? 'Checked') == 'Checked') ? 'Checked' : 'Unchecked';
                $capacity = trim($data[11] ?? '');
                
                if (empty($mesin_id)) {
                    $error_count++;
                    $errors[] = "Baris $row_number: ID Mesin kosong";
                    continue;
                }
                
                if (empty($name)) {
                    $error_count++;
                    $errors[] = "Baris $row_number: Nama Mesin kosong untuk ID $mesin_id";
                    continue;
                }
                
                // Cek apakah sudah ada
                $cek = mysqli_query($conn, "SELECT mesin_id FROM m_mesin WHERE mesin_id = '$mesin_id'");
                
                if (mysqli_num_rows($cek) > 0) {
                    // UPDATE
                    $sql = "UPDATE m_mesin SET 
                            name='$name', spec='$spec', manufactured_date=" . ($manufactured_date ? "'$manufactured_date'" : "NULL") . ",
                            manufactured_by='$manufactured_by', supplier='$supplier', purchase_price='$purchase_price',
                            purchase_date=" . ($purchase_date ? "'$purchase_date'" : "NULL") . ", acc_reff='$acc_reff',
                            remarks='$remarks', active='$active', capacity='$capacity'
                            WHERE mesin_id='$mesin_id'";
                } else {
                    // INSERT
                    $sql = "INSERT INTO m_mesin (mesin_id, name, spec, manufactured_date, manufactured_by, 
                            supplier, purchase_price, purchase_date, acc_reff, remarks, active, capacity) 
                            VALUES ('$mesin_id', '$name', '$spec', " . ($manufactured_date ? "'$manufactured_date'" : "NULL") . ", 
                            '$manufactured_by', '$supplier', '$purchase_price', " . ($purchase_date ? "'$purchase_date'" : "NULL") . ", 
                            '$acc_reff', '$remarks', '$active', '$capacity')";
                }
                
                if (mysqli_query($conn, $sql)) {
                    $success_count++;
                    if ($success_count <= 5) {
                        $debug_info[] = "SUCCESS: $mesin_id - $name";
                    }
                } else {
                    $error_count++;
                    $errors[] = "Baris $row_number - $mesin_id: " . mysqli_error($conn);
                }
            }
            fclose($handle);
            
            $count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM m_mesin");
            $total_data = mysqli_fetch_assoc($count_query);
            
            $alert_message = "
            <div class='alert alert-success'>
                <strong>✅ Import Selesai!</strong><br>
                ✅ Berhasil: " . number_format($success_count) . " data<br>
                ❌ Gagal: " . number_format($error_count) . " data<br>
                📊 Total data di database: " . number_format($total_data['total']) . " data
            </div>";
            
            if (!empty($errors)) {
                $alert_message .= "
                <div class='alert alert-warning' style='max-height: 200px; overflow-y: auto;'>
                    <strong>⚠️ Error (10 teratas):</strong><br>" . implode("<br>", array_slice($errors, 0, 10)) . "
                </div>";
            }
        }
    }
}

$count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM m_mesin");
$current_total = mysqli_fetch_assoc($count_query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Import Master Mesin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        .instructions { background: #e7f3ff; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container">
    <h2><i class="fa fa-gear"></i> Import Master Mesin</h2>
    
    <?= $alert_message ?>
    
    <div class="instructions">
        <h5>Petunjuk:</h5>
        <ol>
            <li>Format CSV: ID, Name, Spec, Manufactured Date, Manufactured By, Supplier, Purchase Price, Purchase Date, ACC Reff, Remarks, Active, Capacity</li>
            <li>Tanggal format: DD-MMM-YY (contoh: 26-Apr-17) akan otomatis dikonversi</li>
            <li>Data akan di-UPDATE jika ID sudah ada, INSERT jika baru</li>
        </ol>
    </div>
    
    <div class="alert alert-info">
        <strong>Total data saat ini:</strong> <?= number_format($current_total['total']) ?> data
    </div>
    
    <form method="POST" enctype="multipart/form-data">
        <div class="mb-3">
            <label class="form-label">Pilih File CSV:</label>
            <input type="file" name="csv_file" class="form-control" accept=".csv" required>
        </div>
        
        <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" name="truncate_first" id="truncate_first">
            <label class="form-check-label text-warning" for="truncate_first">Kosongkan tabel terlebih dahulu</label>
        </div>
        
        <button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> Import Data</button>
        <a href="http://localhost/cahaya/index.php?page=mesin" class="btn btn-secondary"><i class="fa fa-arrow-left"></i> Kembali</a>
    </form>
    
    <hr>
    <h5>Contoh Format CSV:</h5>
    <pre>ID,Name,Spec,Manufactured Date,Manufactured By,Supplier,Purchase Price,Purchase Date,ACC Reff,Remarks,Active,Capacity
MSN001,BANDERA,,26-Apr-17,TAIWAN,,0,26-Apr-17,,,Checked,</pre>
</div>
</body>
</html>