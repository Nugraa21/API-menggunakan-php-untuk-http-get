<?php
// presensi_approve.php 
include 'config.php';
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);
$id = trim($_POST['id'] ?? '');
$status = trim($_POST['status'] ?? ''); // Disetujui / Ditolak
if (empty($id) || empty($status)) {
    echo json_encode(["status" => false, "message" => "ID atau status kosong"]);
    exit;
}
// Cek ID ada atau tidak
$check = mysqli_query($conn, "SELECT id FROM absensi WHERE id = '$id'");
if (!$check) {
    echo json_encode(["status" => false, "message" => "Query check gagal: " . mysqli_error($conn)]);
    exit;
}
if (mysqli_num_rows($check) == 0) {
    echo json_encode(["status" => false, "message" => "ID '$id' tidak ditemukan"]);
    exit;
}
// Update sesuai kolom yang ADA di tabel
$sql = "UPDATE absensi SET status = '$status' WHERE id = '$id'";
if ($conn->query($sql)) {
    echo json_encode(["status" => true, "message" => "Status berhasil diupdate ke '$status'"]);
} else {
    echo json_encode(["status" => false, "message" => "Query update gagal: " . mysqli_error($conn)]);
}
?>