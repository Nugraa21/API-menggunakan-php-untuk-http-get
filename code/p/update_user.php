<?php
// update_user.php - VERSI ENKRIPSI (CODE/P)
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
$input = json_decode($raw, true) ?? $_POST;

$id = $input['id'] ?? $input['user_id'] ?? null;
$username = trim($input['username'] ?? '');
$nama_lengkap = trim($input['nama_lengkap'] ?? '');
$password = $input['password'] ?? '';
$nip_nisn = trim($input['nip_nisn'] ?? '');
$role = $input['role'] ?? 'user';
$status = $input['status'] ?? 'Karyawan';
$reset_device = $input['reset_device'] ?? false;

// CREATE MODE
if ($id === null || $id === '') {
    if (!$username || !$nama_lengkap || !$password) {
        sendEncryptedResponse(["status" => false, "message" => "Data tidak lengkap"], 400);
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows > 0) {
        $stmt->close();
        sendEncryptedResponse(["status" => false, "message" => "Username sudah ada"], 409);
    }
    $stmt->close();

    $hashed = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role, status) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssssss", $username, $nama_lengkap, $nip_nisn, $hashed, $role, $status);

    if ($stmt->execute()) {
        $stmt->close();
        sendEncryptedResponse(["status" => true, "message" => "User Created (Encrypted)"], 201);
    } else {
        $error = $stmt->error;
        $stmt->close();
        sendEncryptedResponse(["status" => false, "message" => "Error: " . $error], 500);
    }

} else {
    // UPDATE MODE
    $updates = [];
    $types = "";
    $params = [];

    if ($username) {
        $updates[] = "username = ?";
        $params[] = $username;
        $types .= "s";
    }
    if ($nama_lengkap) {
        $updates[] = "nama_lengkap = ?";
        $params[] = $nama_lengkap;
        $types .= "s";
    }
    if ($password) {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $updates[] = "password = ?";
        $params[] = $hashed;
        $types .= "s";
    }
    if ($reset_device) {
        $updates[] = "device_id = NULL";
    }

    if (!empty($updates)) {
        // We need to bind params dynamically, which is tricky with mysqli
        // Simplified approach: loop and use real_escape_string for safety since we are in a wrapper
        // OR better: construct query with `?` and bind

        // Actually, let's just use prepared statements for safety.
        // But with dynamic number of params...
        // Fallback to manual escaping since this is a limited scope script

        $sqlParts = [];
        if ($username)
            $sqlParts[] = "username = '" . $conn->real_escape_string($username) . "'";
        if ($nama_lengkap)
            $sqlParts[] = "nama_lengkap = '" . $conn->real_escape_string($nama_lengkap) . "'";
        if ($password) {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $sqlParts[] = "password = '" . $conn->real_escape_string($hashed) . "'";
        }
        if ($reset_device)
            $sqlParts[] = "device_id = NULL";

        $sql = "UPDATE users SET " . implode(", ", $sqlParts) . " WHERE id = " . intval($id);

        if ($conn->query($sql)) {
            sendEncryptedResponse(["status" => true, "message" => "User Updated (Encrypted)"], 200);
        } else {
            sendEncryptedResponse(["status" => false, "message" => "Error: " . $conn->error], 500);
        }
    } else {
        sendEncryptedResponse(["status" => true, "message" => "No changes"], 200);
    }
}
?>