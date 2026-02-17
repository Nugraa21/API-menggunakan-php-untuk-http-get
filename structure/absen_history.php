<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
randomDelay();
validateApiKey();
include "encryption.php";
header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? '';
if (empty($user_id)) {
    echo json_encode(["status" => false, "message" => "user_id required"]);
    exit;
}

$q = $conn->query("SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC");
$data = [];
while ($r = $q->fetch_assoc()) $data[] = $r;

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>