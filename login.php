<?php
include "config.php";

$input = $_POST["input"] ?? '';
$password = $_POST["password"] ?? '';

if ($input == "" || $password == "") {
    echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
    exit;
}

$sql = "SELECT * FROM users 
        WHERE username='$input' 
        OR nip_nisn='$input'";

$run = mysqli_query($conn, $sql);

if (mysqli_num_rows($run) == 0) {
    echo json_encode(["status" => "error", "message" => "Akun tidak ditemukan"]);
    exit;
}

$user = mysqli_fetch_assoc($run);

// verifikasi password
if (!password_verify($password, $user["password"])) {
    echo json_encode(["status" => "error", "message" => "Password salah"]);
    exit;
}

echo json_encode([
    "status" => "success",
    "message" => "Login berhasil",
    "data" => [
        "id" => $user["id"],
        "username" => $user["username"],
        "nama_lengkap" => $user["nama_lengkap"],
        "nip_nisn" => $user["nip_nisn"],
        "role" => $user["role"]
    ]
]);
?>
