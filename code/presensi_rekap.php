<?php
error_reporting(0);
ini_set('display_errors', 0);
include "config.php";
randomDelay();
validateApiKey();
include "encryption.php";
header('Content-Type: application/json');

// Sanitize input parameters
$month = isset($_GET['month']) ? intval($_GET['month']) : null;
$year = isset($_GET['year']) ? intval($_GET['year']) : null;

$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p
        JOIN users u ON p.user_id = u.id";

$whereClause = [];
$params = [];

if ($month !== null && $year !== null) {
    $whereClause[] = "MONTH(p.created_at) = ? AND YEAR(p.created_at) = ?";
    $params[] = $month;
    $params[] = $year;
}

if (!empty($whereClause)) {
    $sql .= " WHERE " . implode(" AND ", $whereClause);
}

$sql .= " ORDER BY p.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param(str_repeat('i', count($params)), ...$params);
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
?>