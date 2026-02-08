<?php
/**
 * LOGIN API - UNTUK TESTING (TIDAK ENKRIPSI)
 */

require_once "config.php"; // Menggunakan config lokal di folder code yang tidak ada API_SECRET_KEY

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ===================== INPUT HANDLING =====================
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$login_input = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$device_id = trim($data['device_id'] ?? '');

if ($login_input === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Username dan Password wajib diisi"
    ]);
    exit;
}

// ===================== QUERY USER =====================
$stmt = $conn->prepare("
    SELECT id, username, nip_nisn, password, nama_lengkap, role, device_id 
    FROM users 
    WHERE (username = ? OR nip_nisn = ?) 
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Kesalahan Database: " . $conn->error]);
    exit;
}

$stmt->bind_param("ss", $login_input, $login_input);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "User tidak ditemukan"]);
    $stmt->close();
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// ===================== VERIFIKASI PASSWORD =====================
if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(["status" => false, "message" => "Password salah"]);
    exit;
}

// ===================== DEVICE BINDING DIPERMUDAH =====================
if ($user['role'] === 'user' && $device_id !== '') {
    // Jika user belum punya device, bind. Jika sudah punya dan beda, biarkan (untuk testing bebas saja, atau warning)
    if ($user['device_id'] === null || $user['device_id'] === '') {
        $update = $conn->prepare("UPDATE users SET device_id = ? WHERE id = ?");
        $update->bind_param("si", $device_id, $user['id']);
        $update->execute();
        $update->close();
    } elseif ($user['device_id'] !== $device_id) {
        // Versi testing: Abaikan error device mismatch, atau beri notifikasi
        // Untuk testing murni tanpa enkripsi, sebaiknya izinkan login dari mana saja
        // Tapi kita ikuti logic asli saja dengan sedikit kelonggaran jika mau
        // Kita biarkan logic standard: kalau beda device, tolak login
        http_response_code(403);
        echo json_encode(["status" => false, "message" => "Akun sudah login di device lain (Original Logic)"]);
        exit;
    }
}

// ===================== TOKEN GENERATION (SEDERHANA) =====================
$token = bin2hex(random_bytes(32));
$expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

$stmt_token = $conn->prepare("
    INSERT INTO login_tokens (user_id, token, expires_at) 
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE token = VALUES(token), expires_at = VALUES(expires_at)
");
$stmt_token->bind_param("iss", $user['id'], $token, $expires_at);
$stmt_token->execute();
$stmt_token->close();

// ===================== RESPONSE BERHASIL (PLAIN JSON) =====================
http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => "Login Berhasil (Versi Testing)",
    "user" => [
        "id" => (string) $user['id'],
        "username" => $user['username'],
        "nama_lengkap" => $user['nama_lengkap'],
        "role" => $user['role']
    ],
    "token" => $token
]);
$conn->close();
?>