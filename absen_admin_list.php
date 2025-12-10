<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";
header('Content-Type: application/json');

$q = $conn->query("SELECT absensi.*, users.nama_lengkap
                   FROM absensi
                   JOIN users ON users.id = absensi.user_id
                   ORDER BY absensi.id DESC");

$data = [];
while ($r = $q->fetch_assoc()) {
    $data[] = $r;
}

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>