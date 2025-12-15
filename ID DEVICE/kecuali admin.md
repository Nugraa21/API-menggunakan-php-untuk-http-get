```php
<?php
/**
 * SECURE LOGIN API (ANTI SQLi, XSS, BRUTE FORCE)
 * Project : SKADUTA Presensi
 * Notes   : Safe for TA / Production
 */

require_once "config.php";
// require_once "security_sql.php";

// ===================== SECURITY HEADERS =====================
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// ===================== BASIC PROTECTION =====================
randomDelay();            // Anti brute-force timing
validateApiKey();         // Mandatory API Key

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
    echo json_encode([
        "status" => false,
        "message" => "Username dan password wajib diisi"
    ]);
    exit;
}

// ===================== DATABASE QUERY (ANTI SQLi) =====================
$stmt = $conn->prepare(
    "SELECT id, username, password, nama_lengkap, role, device_id 
     FROM users 
     WHERE username = ? 
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Query preparation failed"
    ]);
    exit;
}

$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();

// ===================== AUTH VALIDATION =====================
if ($result->num_rows !== 1) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "message" => "Username atau password salah"
    ]);
    $stmt->close();
    exit;
}

$user = $result->fetch_assoc();
$stmt->close();

if (!password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "message" => "Username atau password salah"
    ]);
    exit;
}

// ===================== DEVICE BINDING CHECK (HANYA UNTUK ROLE 'user') =====================
if ($user['role'] === 'user' && $user['device_id'] !== null && $user['device_id'] !== $device_id) {
    http_response_code(401);
    echo json_encode([
        "status" => false,
        "message" => "Perangkat tidak diizinkan untuk akun ini. Gunakan perangkat yang sama saat registrasi."
    ]);
    exit;
}

// ===================== TOKEN GENERATION =====================
try {
    $token = bin2hex(random_bytes(32));
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => false,
        "message" => "Token generation failed"
    ]);
    exit;
}

$expires = date('Y-m-d H:i:s', strtotime('+30 days'));
$user_id = (int)$user['id'];

// ===================== TOKEN STORAGE =====================
$conn->query(
    "CREATE TABLE IF NOT EXISTS login_tokens (
        user_id INT PRIMARY KEY,
        token CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL
    )"
);

$stmt_token = $conn->prepare(
    "INSERT INTO login_tokens (user_id, token, expires_at)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE
        token = VALUES(token),
        expires_at = VALUES(expires_at)"
);

if ($stmt_token) {
    $stmt_token->bind_param("iss", $user_id, $token, $expires);
    $stmt_token->execute();
    $stmt_token->close();
}

// ===================== RESPONSE =====================
http_response_code(200);
echo json_encode([
    "status" => true,
    "message" => "Login berhasil",
    "user" => [
        "id" => (string)$user_id,
        "username" => htmlspecialchars($user['username'] ?? '', ENT_QUOTES, 'UTF-8'),
        "nama_lengkap" => htmlspecialchars($user['nama_lengkap'] ?? 'User', ENT_QUOTES, 'UTF-8'),
        "role" => htmlspecialchars($user['role'] ?? 'user', ENT_QUOTES, 'UTF-8')
    ],
    "token" => $token
]);

$conn->close();
?>
```

```php
<?php
include "config.php";
randomDelay();
validateApiKey();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data) $data = $_POST;

$username = sanitizeInput($data['username'] ?? '');
$nama = sanitizeInput($data['nama_lengkap'] ?? '');
$nip_nisn = sanitizeInput($data['nip_nisn'] ?? '');
$password_raw = $data['password'] ?? '';
$role = sanitizeInput($data['role'] ?? 'user');
$is_karyawan = !empty($data['is_karyawan']);
$device_id = sanitizeInput($data['device_id'] ?? '');

if (empty($username) || empty($nama) || empty($password_raw)) {
    echo json_encode(["status" => "error", "message" => "Data tidak lengkap"]);
    exit;
}

if (strlen($password_raw) < 6) {
    echo json_encode(["status" => "error", "message" => "Password minimal 6 karakter"]);
    exit;
}

if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
    echo json_encode(["status" => "error", "message" => "Username tidak valid"]);
    exit;
}

if (!$is_karyawan && empty($nip_nisn)) {
    echo json_encode(["status" => "error", "message" => "NIP/NISN wajib diisi"]);
    exit;
}

// ===================== DEVICE BINDING UNTUK ROLE 'user' SAJA =====================
$final_device_id = null;
if ($role === 'user') {
    if (empty($device_id)) {
        echo json_encode(["status" => "error", "message" => "ID perangkat wajib untuk user biasa"]);
        exit;
    }
    // Check if device_id already registered for another user
    $check_device = $conn->prepare("SELECT id FROM users WHERE device_id = ?");
    $check_device->bind_param("s", $device_id);
    $check_device->execute();
    $check_device->store_result();
    if ($check_device->num_rows > 0) {
        echo json_encode(["status" => "error", "message" => "Perangkat ini sudah terdaftar dengan akun lain. 1 perangkat hanya untuk 1 user."]);
        $check_device->close();
        exit;
    }
    $check_device->close();
    $final_device_id = $device_id;
} else {
    // Untuk admin/superadmin, abaikan device_id dan set ke NULL
    $final_device_id = null;
}

$password = password_hash($password_raw, PASSWORD_DEFAULT);

if (in_array($role, ['admin', 'superadmin'])) {
    $check = $conn->prepare("SELECT id FROM users WHERE role = ?");
    $check->bind_param("s", $role);
    $check->execute();
    $check->store_result();
    if ($check->num_rows > 0) {
        echo json_encode(["status" => "error", "message" => ucfirst($role) . " sudah ada"]);
        exit;
    }
    $check->close();
}

$stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role, device_id) VALUES (?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssss", $username, $nama, $nip_nisn, $password, $role, $final_device_id);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Akun berhasil dibuat" . ($role === 'user' ? " dan terikat ke perangkat ini" : "")]);
} else {
    echo json_encode(["status" => "error", "message" => "Gagal mendaftar"]);
}

$stmt->close();
$conn->close();
?>
```

```php
DROP TABLE IF EXISTS absensi;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS login_tokens;

CREATE TABLE users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(255) NOT NULL,
  nama_lengkap VARCHAR(255) NOT NULL,
  nip_nisn VARCHAR(255) DEFAULT NULL,  -- Optional for karyawan (validated in PHP/Flutter), required for guru
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin','superadmin') DEFAULT 'user',
  device_id VARCHAR(255) DEFAULT NULL,  -- Nullable untuk admin/superadmin; UNIQUE untuk user
  PRIMARY KEY (id),
  UNIQUE KEY unique_username (username),
  UNIQUE KEY unique_device (device_id)  -- Memungkinkan multiple NULL (untuk admin)
);

CREATE TABLE absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  jenis ENUM('Masuk','Pulang','Izin','Pulang Cepat','Penugasan_Masuk','Penugasan_Pulang','Penugasan_Full'),
  keterangan TEXT,
  informasi TEXT,  -- For Penugasan details (wajib)
  dokumen VARCHAR(255),  -- Path to uploaded dokumen (wajib for Penugasan)
  selfie VARCHAR(255),
  latitude VARCHAR(100),
  longitude VARCHAR(100),
  status ENUM('Pending','Disetujui','Ditolak') DEFAULT 'Pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ================== sementara aja
CREATE TABLE login_tokens (
    user_id INT PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```