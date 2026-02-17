<?php
include "config.php";
header('Content-Type: application/json');
ini_set('display_errors', 0);

// Ambil ID dari body JSON atau POST
$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? $_POST['id'] ?? '';

if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "ID kosong"]);
    exit;
}

// Cek user yang mau dihapus
$cek = mysqli_query($conn, "SELECT role FROM users WHERE id = '$id'");
if (mysqli_num_rows($cek) == 0) {
    echo json_encode(["status" => "error", "message" => "User tidak ditemukan"]);
    exit;
}

$user = mysqli_fetch_assoc($cek);

// Tidak boleh hapus superadmin
if ($user["role"] == "superadmin") {
    echo json_encode(["status" => "error", "message" => "Tidak boleh menghapus akun superadmin"]);
    exit;
}

// Hapus langsung (tanpa cek siapa yang request)
$del = mysqli_query($conn, "DELETE FROM users WHERE id = '$id'");
if ($del) {
    echo json_encode(["status" => "success", "message" => "User berhasil dihapus"]);
} else {
    echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
}
?>