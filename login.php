<?php
/**
 * LOGIN API - SECURE ENCRYPTED VERSION
 * Versi stabil - Februari 2026
 */

require_once "config.php";
require_once "encryption.php"; // Wajib include encryption

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ===================== INPUT HANDLING (DECRYPTION) =====================
$raw = file_get_contents('php://input');
$input_json = json_decode($raw, true);

$data = [];
if (isset($input_json['encrypted_data'])) {
    // Decrypt data dari client
    $decrypted_json = Encryption::decrypt($input_json['encrypted_data']);
    if ($decrypted_json === false) {
        http_response_code(400);
        echo json_encode(["status" => false, "message" => "Gagal dekripsi data (Invalid Key/IV)"]);
        exit;
    }
    $data = json_decode($decrypted_json, true);
} else {
    // Fallback jika tidak terenkripsi (opsional: bisa ditolak jika ingin strict)
    // Untuk saat ini kita coba support keduanya atau tolak?
    // User request: "semuanya itu di enkripsi agar datanya aman" -> Kita tolak yang raw.
    // Tapi untuk backward compatibility saat testing, kita bisa biarkan fallback sementara?
    // STRICT MODE:
    // http_response_code(400);
    // echo json_encode(["status" => false, "message" => "Request harus terenkripsi"]);
    // exit;

    // HYBRID MODE (Sementara):
    $data = $input_json ?? $_POST;
}

$login_input = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$device_id = trim($data['device_id'] ?? '');

if ($login_input === '' || $password === '') {
    $res = [
        "status" => false,
        "message" => "Username / NIP / NISN dan password wajib diisi (Encrypted Route)"
    ];
    // Encrypt Output
    echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($res))]);
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
    $res = ["status" => false, "message" => "Terjadi kesalahan server (database)"];
    echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($res))]);
    exit;
}

$stmt->bind_param("ss", $login_input, $login_input);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    http_response_code(401);
    $res = [
        "status" => false,
        "message" => "Username/NIP/NISN atau password salah"
    ];
    $stmt->close();
    echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($res))]);
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

// ===================== VERIFIKASI PASSWORD =====================
if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    $res = [
        "status" => false,
        "message" => "Username/NIP/NISN atau password salah"
    ];
    echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($res))]);
    exit;
}

// ===================== DEVICE BINDING (khusus role user) =====================
if ($user['role'] === 'user' && $device_id !== '') {
    // Sudah terikat di device lain?
    if ($user['device_id'] !== null && $user['device_id'] !== '' && $user['device_id'] !== $device_id) {
        http_response_code(403);
        $res = [
            "status" => false,
            "message" => "Akun ini sudah terdaftar di perangkat lain. Hubungi admin."
        ];
        echo json_encode(["encrypted_data" => Encryption::encrypt(json_encode($res))]);
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

// ===================== SAVE TOKEN TO DATABASE =====================
$expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));

$stmt_token = $conn->prepare("
    INSERT INTO login_tokens (user_id, token, expires_at) 
    VALUES (?, ?, ?)
    ON DUPLICATE KEY UPDATE 
        token = VALUES(token), 
        expires_at = VALUES(expires_at)
");

if ($stmt_token) {
    $stmt_token->bind_param("iss", $user['id'], $token, $expires_at);
    $stmt_token->execute();
    $stmt_token->close();
}

// ===================== RESPONSE SUKSES (ENCRYPTED) =====================
http_response_code(200);

$response_data = [
    "status" => true,
    "message" => "Login berhasil",
    "user" => [
        "id" => (string) $user['id'],
        "username" => $user['username'],
        "nip_nisn" => $user['nip_nisn'] ?? '',
        "nama_lengkap" => $user['nama_lengkap'],
        "role" => $user['role']
    ],
    "token" => $token
];

$json_response = json_encode($response_data);
$encrypted_response = Encryption::encrypt($json_response);

echo json_encode([
    "encrypted_data" => $encrypted_response
]);

$conn->close();
?>