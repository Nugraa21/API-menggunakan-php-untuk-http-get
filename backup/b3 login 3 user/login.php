<?php
include "config.php";
include "utils.php";

$nip_nisn = $_POST['nip_nisn']; 
$password = $_POST['password'];

$sql = "SELECT * FROM users WHERE nip_nisn='$nip_nisn' LIMIT 1";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    echo json_encode(["status" => false, "message" => "Akun tidak ditemukan"]);
    exit;
}

$user = $result->fetch_assoc();

if (verifyPass($password, $user['password'])) {
    echo json_encode([
        "status" => true,
        "message" => "Login berhasil",
        "data" => [
            "id" => $user["id"],
            "nama" => $user["nama_lengkap"],
            "role" => $user["role"]
        ]
    ]);
} else {
    echo json_encode(["status" => false, "message" => "Password salah"]);
}
?>
