<?php
// <!-- delete_user.php -->
include "config.php";

header('Content-Type: application/json');
ini_set('display_errors', 0);

$id = $_POST["id"] ?? '';

if (empty($id)) {
    echo json_encode(["status" => "error", "message" => "ID kosong"]);
    exit;
}

$cek = mysqli_query($conn, "SELECT role FROM users WHERE id='$id'");
if (mysqli_num_rows($cek) == 0) {
    echo json_encode(["status" => "error", "message" => "User tidak ditemukan"]);
    exit;
}
$user = mysqli_fetch_assoc($cek);

// Superadmin tidak bisa hapus dirinya sendiri
if ($user["role"] == "superadmin") {
    echo json_encode(["status" => "error", "message" => "Tidak boleh hapus superadmin"]);
    exit;
}

$del = mysqli_query($conn, "DELETE FROM users WHERE id='$id'");

if ($del) {
    echo json_encode(["status" => "success", "message" => "User dihapus berhasil"]);
} else {
    echo json_encode(["status" => "error", "message" => mysqli_error($conn)]);
}
?>