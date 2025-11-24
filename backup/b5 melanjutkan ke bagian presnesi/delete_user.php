<?php
include "config.php";

$id = $_POST["id"] ?? '';

if ($id == "") {
    echo json_encode(["status" => "error", "message" => "ID kosong"]);
    exit;
}

$cek = mysqli_query($conn, "SELECT role FROM users WHERE id='$id'");
$user = mysqli_fetch_assoc($cek);

// Superadmin tidak bisa hapus dirinya sendiri
if ($user["role"] == "superadmin") {
    echo json_encode(["status" => "error", "message" => "Tidak boleh hapus superadmin"]);
    exit;
}

$del = mysqli_query($conn, "DELETE FROM users WHERE id='$id'");

echo json_encode(["status" => "success", "message" => "User dihapus"]);
?>

