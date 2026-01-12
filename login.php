<?php
/**
 * LOGIN API - SUPPORT USERNAME ATAU NIP/NISN
 * Versi stabil - Januari 2026
 */

require_once "config.php";  // pastikan file ini ada & koneksi DB benar

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');     // sesuaikan di produksi
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ===================== INPUT HANDLING =====================
$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$login_input = trim($data['username'] ?? ''); // key tetap 'username' agar Flutter tidak perlu diubah banyak
$password    = $data['password']    ?? '';
$device_id   = trim($data['device_id'] ?? '');

if ($login_input === '' || $password === '') {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Username / NIP / NISN dan password wajib diisi"
    ]);
    exit;
}

// ===================== QUERY USER =====================
$stmt = $conn->prepare("
    SELECT 
        id, username, nip_nisn, password, nama_lengkap, role, device_id 
    FROM users 
    WHERE (username = ? OR nip_nisn = ?) 
    LIMIT 1
");

if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    http_response_code(500);
    echo json_encode(["status" => false, "message" => "Terjadi kesalahan server (database)"]);
    exit;
}

$stmt->bind_param("ss", $login_input, $login_input);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "message" => "Username/NIP/NISN atau password salah"
    ]);
    $stmt->close();
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// ===================== VERIFIKASI PASSWORD =====================
if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "message" => "Username/NIP/NISN atau password salah"
    ]);
    exit;
}

// ===================== DEVICE BINDING (khusus role user) =====================
if ($user['role'] === 'user' && $device_id !== '') {
    // Sudah terikat di device lain?
    if ($user['device_id'] !== null && $user['device_id'] !== '' && $user['device_id'] !== $device_id) {
        http_response_code(403);
        echo json_encode([
            "status" => false,
            "message" => "Akun ini sudah terdaftar di perangkat lain. Hubungi admin."
        ]);
        exit;
    }

    // Belum terikat → bind device sekarang
    if ($user['device_id'] === null || $user['device_id'] === '') {
        $update = $conn->prepare("UPDATE users SET device_id = ? WHERE id = ?");
        if ($update) {
            $update->bind_param("si", $device_id, $user['id']);
            $update->execute();
            $update->close();
        }
    }
}

// ===================== GENERATE SIMPLE TOKEN =====================
$token = bin2hex(random_bytes(32));

// ===================== RESPONSE SUKSES =====================
http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => "Login berhasil",
    "user" => [
        "id"            => (string)$user['id'],
        "username"      => $user['username'],
        "nip_nisn"      => $user['nip_nisn'] ?? '',
        "nama_lengkap"  => $user['nama_lengkap'],
        "role"          => $user['role']
    ],
    "token" => $token
]);

$conn->close();
?>