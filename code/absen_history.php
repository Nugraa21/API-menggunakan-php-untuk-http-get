<?php
/**
 * ABSEN HISTORY - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php"; // Menggunakan config lokal

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Ambil input dari POST atau GET (flexibel untuk testing)
$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? '';

if (empty($user_id)) {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "user_id required (GET/POST)"]);
    exit;
}

// Query History Absensi
$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
$stmt->close();

// Return Plain JSON
echo json_encode([
    "status" => true,
    "message" => "Data Absensi (Test Mode Tanpa Enkripsi)",
    "data" => $data
]);

$conn->close();
?>