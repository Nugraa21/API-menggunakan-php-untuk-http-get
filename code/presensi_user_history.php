<?php
/**
 * PRESENSI USER HISTORY - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? '';

if (empty($user_id)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "user_id required"]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode([
    "status" => true,
    "message" => "History Presensi User (Test Mode)",
    "data" => $data
]);

$stmt->close();
$conn->close();
?>