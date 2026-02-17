<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
randomDelay();
validateApiKey();
include "encryption.php";
header('Content-Type: application/json');

$user_id = $_GET['user_id'] ?? '';
$sql = "SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC";
$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);
?>