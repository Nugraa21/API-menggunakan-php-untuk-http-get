<?php
/**
 * LOGIN API - VERSI MINIMAL (UNTUK DEBUG ERROR 500)
 * Nonaktifkan randomDelay & validateApiKey sementara
 */

require_once "config.php";  // Pastikan config.php koneksi DB benar

// ===================== SECURITY HEADERS =====================
header('Content-Type: application/json');

// ===================== INPUT HANDLING =====================
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$device_id = trim($data['device_id'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(["status" => false, "message" => "Username dan password wajib diisi"]);
    exit;
}

// ===================== QUERY USER =====================
$stmt = $conn->prepare("SELECT id, username, password, nama_lengkap, role, device_id FROM users WHERE username = ? LIMIT 1");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Server error (prepare)"]);
    exit;
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Username atau password salah"]);
    $stmt->close();
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// ===================== VERIFY PASSWORD =====================
if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Username atau password salah"]);
    exit;
}

// ===================== DEVICE BINDING (HANYA USER) =====================
if ($user['role'] === 'user' && $device_id !== '') {
    if ($user['device_id'] !== null && $user['device_id'] !== '' && $user['device_id'] !== $device_id) {
        http_response_code(403);
        echo json_encode(["status" => false, "message" => "Akun terikat perangkat lain. Hubungi admin."]);
        exit;
    }

    if ($user['device_id'] === null || $user['device_id'] === '') {
        $update = $conn->prepare("UPDATE users SET device_id = ? WHERE id = ?");
        if ($update) {
            $update->bind_param("si", $device_id, $user['id']);
            $update->execute();
            $update->close();
        }
    }
}

// ===================== GENERATE TOKEN =====================
$token = bin2hex(random_bytes(32));

// ===================== RESPONSE SUKSES =====================
http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => "Login berhasil",
    "user" => [
        "id" => (string)$user['id'],
        "username" => $user['username'],
        "nama_lengkap" => $user['nama_lengkap'],
        "role" => $user['role']
    ],
    "token" => $token
]);

$conn->close();
?>