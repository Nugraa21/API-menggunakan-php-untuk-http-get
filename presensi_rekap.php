<?php
// presensi_rekap.php - ENCRYPTED (POST preferred)
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

$input_data = [];
if (isset($input_json['encrypted_data'])) {
    $decrypted = Encryption::decrypt($input_json['encrypted_data']);
    $input_data = $decrypted ? json_decode($decrypted, true) : [];
} else {
    $input_data = array_merge($_GET, $_POST, $input_json ?? []);
}
// ---------------------

$month = isset($input_data['month']) ? intval($input_data['month']) : null;
$year = isset($input_data['year']) ? intval($input_data['year']) : null;

$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p
        JOIN users u ON p.user_id = u.id";

$whereClause = [];
$params = [];
$types = "";

if ($month !== null && $year !== null) {
    $whereClause[] = "MONTH(p.created_at) = ? AND YEAR(p.created_at) = ?";
    $params[] = $month;
    $params[] = $year;
    $types .= "ii";
}

if (!empty($whereClause)) {
    $sql .= " WHERE " . implode(" AND ", $whereClause);
}

$sql .= " ORDER BY p.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$response = ["status" => true, "data" => $data];
$json = json_encode($response, JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($json);

echo json_encode(["encrypted_data" => $encrypted]);

$stmt->close();
$conn->close();
?>