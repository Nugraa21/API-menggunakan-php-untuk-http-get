<?php
/**
 * PRESENSI REKAP - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

echo json_encode([
    "status" => true,
    "message" => "Rekap Presensi (Test Mode)",
    "data" => $data
]);

$stmt->close();
$conn->close();
?>