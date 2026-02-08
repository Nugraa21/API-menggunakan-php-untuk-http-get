<?php
// presensi_rekap.php - VERSI ENKRIPSI (CODE/P)
// Output: JSON {"encrypted_data": "..."} berisi string enkripsi dari response asli.
// Tidak ada HTML error yang muncul.

require_once "config.php";
require_once "encryption.php";

header('Content-Type: application/json');
error_reporting(0);
ini_set('display_errors', 0);

function sendEncryptedResponse($data, $httpCode = 200)
{
    global $conn;
    http_response_code($httpCode);
    $json = json_encode($data);
    $encrypted = Encryption::encrypt($json);
    echo json_encode(["encrypted_data" => $encrypted]);
    if ($conn)
        $conn->close();
    exit;
}

randomDelay();
validateApiKey();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true) ?? $_POST; // Support JSON or POST

$month = isset($input['month']) ? intval($input['month']) : (isset($_GET['month']) ? intval($_GET['month']) : null);
$year = isset($input['year']) ? intval($input['year']) : (isset($_GET['year']) ? intval($_GET['year']) : null);

$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p
        JOIN users u ON p.user_id = u.id";

$whereClause = [];
$params = [];
$types = '';

if ($month !== null && $year !== null) {
    $whereClause[] = "MONTH(p.created_at) = ? AND YEAR(p.created_at) = ?";
    $params[] = $month;
    $params[] = $year;
    $types .= 'ii';
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
$stmt->close();

sendEncryptedResponse(["status" => true, "data" => $data], 200);
?>