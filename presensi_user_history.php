<!-- presensi_user_history.php -->

<?php
include 'config.php';

$user_id = $_GET['user_id'];

$sql = "SELECT * FROM presensi WHERE user_id='$user_id' ORDER BY id DESC";
$result = $conn->query($sql);

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode($data);
?>
