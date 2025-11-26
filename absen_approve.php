<!-- absen_approve.php -->

<?php
include "config.php";

$id = $_POST['id'];
$status = $_POST['status']; // Disetujui / Ditolak

$q = $conn->query("UPDATE absensi SET status='$status' WHERE id='$id'");

echo json_encode(["status" => true, "message" => "Status diperbarui"]);
