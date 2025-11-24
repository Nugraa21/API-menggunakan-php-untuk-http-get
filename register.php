<?php
include "config.php";
include "utils.php";

$nama       = $_POST['nama_lengkap'];
$nip_nisn   = $_POST['nip_nisn'];
$password   = $_POST['password'];
$role       = $_POST['role']; // user / admin / superadmin

// Validasi admin & superadmin hanya boleh ada 1
if ($role == "admin") {
    $cek = $conn->query("SELECT * FROM users WHERE role='admin'");
    if ($cek->num_rows > 0) {
        echo json_encode(["status" => false, "message" => "Admin sudah ada"]);
        exit;
    }
}

if ($role == "superadmin") {
    $cek = $conn->query("SELECT * FROM users WHERE role='superadmin'");
    if ($cek->num_rows > 0) {
        echo json_encode(["status" => false, "message" => "Super Admin sudah ada"]);
        exit;
    }
}

$hash = hashPass($password);

$sql = "INSERT INTO users (nama_lengkap, nip_nisn, password, role)
        VALUES ('$nama', '$nip_nisn', '$hash', '$role')";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["status" => true, "message" => "Register berhasil"]);
} else {
    echo json_encode(["status" => false, "message" => "Gagal register"]);
}
?>
