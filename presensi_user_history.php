<?php
// presensi_user_history.php - ENCRYPTED (POST preferred)
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
include "encryption.php";

randomDelay();
validateApiKey();

header('Content-Type: application/json');

// --- DECRYPT INPUT ---
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);

$data = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    $data = $decrypted ? json_decode($decrypted, true) : [];
} else {
    $data = array_merge($_GET, $_POST, $input_json ?? []);
}
// ---------------------

$user_id = $data['user_id'] ?? '';

if (empty($user_id)) {
    $response = ["status" => false, "message" => "user_id required"];
    echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($response))]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$history_data = [];
while ($row = $result->fetch_assoc()) {
    $history_data[] = $row;
}

$response = ["status" => true, "data" => $history_data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);

$stmt->close();
$conn->close();
?>