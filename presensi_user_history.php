<!-- <?php
// presensi_user_history.php (deprecated, gunakan absen_history.php, tapi fix jika diperlukan)
include 'config.php';
$user_id = $_GET['user_id'];
$sql = "SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC";
$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = $row['status'] ?? 'Pending';
    $data[] = $row;
}
echo json_encode(["status" => true, "data" => $data]);
?>