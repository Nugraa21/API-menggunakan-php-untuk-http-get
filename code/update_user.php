<?php
/**
 * UPDATE USER - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php";

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$data = array_merge($_POST, $input ?? []);

$id = $data['id'] ?? null;
$user_id = $data['user_id'] ?? null;
$username = trim($data['username'] ?? '');
$nama_lengkap = trim($data['nama_lengkap'] ?? '');
$password = $data['password'] ?? '';
$nip_nisn = trim($data['nip_nisn'] ?? '');
$role = $data['role'] ?? 'user';
$status = $data['status'] ?? 'Karyawan';
$reset_device = $data['reset_device'] ?? false;

$target_id = $id ?? $user_id;

$response = ["status" => false, "message" => "Unknown error"];

if ($target_id === null || $target_id === '') {
    // Mode Tambah
    if (!$username || !$nama_lengkap || !$password) {
        echo json_encode(["status" => false, "message" => "Data tidak lengkap"]);
        exit;
    }

    $check = $conn->query("SELECT id FROM users WHERE username = '$username'");
    if ($check->num_rows > 0) {
        echo json_encode(["status" => false, "message" => "Username ada"]);
        exit;
    }

    $hashed = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $username, $nama_lengkap, $nip_nisn, $hashed, $role, $status);

    if ($stmt->execute()) {
        $response = ["status" => true, "message" => "User Created (Test Mode)"];
    } else {
        $response = ["status" => false, "message" => "Error: " . $stmt->error];
    }
} else {
    // Mode Edit
    // Simplified logic for testing
    $updates = [];
    if ($username)
        $updates[] = "username = '$username'";
    if ($nama_lengkap)
        $updates[] = "nama_lengkap = '$nama_lengkap'";
    if ($password) {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $updates[] = "password = '$hashed'";
    }
    if ($reset_device)
        $updates[] = "device_id = NULL";

    if (!empty($updates)) {
        $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = " . intval($target_id);
        if ($conn->query($sql)) {
            $response = ["status" => true, "message" => "User Updated"];
        } else {
            $response = ["status" => false, "message" => "Error: " . $conn->error];
        }
    } else {
        $response = ["status" => true, "message" => "No changes"];
    }
}

echo json_encode($response);
$conn->close();
?>