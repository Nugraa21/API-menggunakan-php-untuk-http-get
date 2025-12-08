```sql
DROP TABLE IF EXISTS absensi;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS rate_logs;  -- NEW: For rate limiting and flood protection (optional, but used in PHP)

CREATE TABLE users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(255) NOT NULL,
  nama_lengkap VARCHAR(255) NOT NULL,
  nip_nisn VARCHAR(255) DEFAULT NULL,  -- Optional for karyawan (validated in PHP/Flutter), required for guru
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin','superadmin') DEFAULT 'user',
  device_id VARCHAR(255) DEFAULT NULL,  -- NEW: For device ID limit (updated on login)
  PRIMARY KEY (id)
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

-- NEW TABLE: For logging requests per IP (rate limiting, flood protection)
CREATE TABLE rate_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(45) NOT NULL,
  endpoint VARCHAR(255) NOT NULL,
  timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ip_time (ip, timestamp)
);
```

```php
<?php
// config.php (UPDATED: Added encryption functions, rate limit/flood protection, device helpers, validation helpers)
// WARNING: This config is included in all files - security features applied globally where possible

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");  // UPDATED: Explicit charset for JSON

$host = "localhost";
$user = "root";
$pass = "081328nugra";
// $db   = "database_smk_2";
$db   = "database_smk_4";
// $db   = "skaduta_presensi";

$conn = mysqli_connect($host, $user, $pass, $db);
mysqli_set_charset($conn, "utf8mb4");  // UPDATED: Prevent charset issues

if (!$conn) {
    $error_data = ["status" => "error", "message" => "Gagal koneksi database"];
    echo encrypt_json($error_data);
    exit;
}

// ================================
// SECURITY FUNCTIONS
// ================================

// AES Encryption for JSON responses (end-to-end, Flutter will decrypt with key 'nugra21')
function encrypt_json($data, $key = 'nugra21') {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $method = 'AES-256-CBC';
    $iv_length = openssl_cipher_iv_length($method);
    $iv = openssl_random_pseudo_bytes($iv_length);
    $encrypted = openssl_encrypt($json, $method, $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
        return json_encode(["status" => "error", "message" => "Encryption failed"]);
    }
    return base64_encode($iv . $encrypted);
}

// Input Validation Helper (strict: length, safe chars, etc.)
function validate_input($input, $type = 'string', $min_len = 1, $max_len = 255, $allowed_chars = null) {
    if (empty($input)) return false;
    $input = trim($input);
    if (strlen($input) < $min_len || strlen($input) > $max_len) return false;
    if ($type === 'username') {
        return preg_match('/^[a-zA-Z0-9_]{3,50}$/', $input);  // Alphanumeric + underscore
    } elseif ($type === 'nama') {
        return preg_match('/^[a-zA-Z\s]{2,100}$/', $input);  // Letters + spaces
    } elseif ($type === 'nip_nisn') {
        return preg_match('/^[0-9]{5,20}$/', $input);  // Numeric only
    } elseif ($type === 'password') {
        return strlen($input) >= 6 && strlen($input) <= 255;  // Min length 6
    } elseif ($type === 'email') {  // If needed in future
        return filter_var($input, FILTER_VALIDATE_EMAIL);
    } elseif ($allowed_chars) {
        return preg_match("/^[$allowed_chars]+$/", $input);
    }
    return true;  // Generic string
}

// Rate Limiting / Flood Protection (using DB table for persistence)
function check_rate_limit($ip, $endpoint, $max_requests = 10, $time_window_minutes = 1) {
    global $conn;
    $time_window = $time_window_minutes * 60;
    $now = date('Y-m-d H:i:s');
    $cutoff = date('Y-m-d H:i:s', strtotime("-$time_window seconds"));

    // Count recent requests
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM rate_logs WHERE ip = ? AND endpoint = ? AND timestamp > ?");
    $stmt->bind_param("sss", $ip, $endpoint, $cutoff);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $count = $row['count'];

    if ($count >= $max_requests) {
        return false;  // Rate limited
    }

    // Log this request
    $log_stmt = $conn->prepare("INSERT INTO rate_logs (ip, endpoint) VALUES (?, ?)");
    $log_stmt->bind_param("ss", $ip, $endpoint);
    $log_stmt->execute();

    return true;
}

// Delay Request (anti-brute force)
function add_delay() {
    $delay_micro = rand(100000, 500000);  // 0.1 to 0.5 seconds random
    usleep($delay_micro);
}

// Random Fake Error (5% chance, anti-bot probing)
function maybe_fake_error() {
    if (rand(1, 100) <= 5) {
        $fake_errors = [
            ["status" => false, "message" => "Server timeout, try again later"],
            ["status" => false, "message" => "Database connection lost"],
            ["status" => false, "message" => "Invalid session"]
        ];
        $fake = $fake_errors[array_rand($fake_errors)];
        echo encrypt_json($fake);
        exit;
    }
}

// POST Only Check (for sensitive endpoints - call in write operations)
function enforce_post_only() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo encrypt_json(["status" => false, "message" => "POST method required"]);
        exit;
    }
}

// Device ID Validation / Update (for login/register - limits to last device)
function validate_and_update_device($user_id, $device_id, $action = 'login') {
    global $conn;
    if (empty($device_id) || !validate_input($device_id, 'string', 10, 100, '[a-zA-Z0-9\-_]+')) {
        return ["valid" => false, "message" => "Invalid device ID"];
    }
    // Check current device
    $stmt = $conn->prepare("SELECT device_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $current_device = $row['device_id'] ?? null;

    if ($action === 'login' && $current_device && $current_device !== $device_id) {
        // For simplicity: Allow but log/warn; strict limit could reject here
        // return ["valid" => false, "message" => "Device not recognized. Contact admin."];
        // Instead, just update to new device (last device wins)
    }

    // Update device
    $update_stmt = $conn->prepare("UPDATE users SET device_id = ? WHERE id = ?");
    $update_stmt->bind_param("si", $device_id, $user_id);
    $update_stmt->execute();

    return ["valid" => true];
}

// ================================
// USAGE NOTES:
// - In each PHP file: Call add_delay(); maybe_fake_error(); at top.
// - For rate limit: $ip = $_SERVER['REMOTE_ADDR']; if (!check_rate_limit($ip, __FILE__, 20, 1)) { echo encrypt_json(["status"=>false,"message"=>"Too many requests"]); exit; }
// - For POST: Call enforce_post_only() in write endpoints.
// - For inputs: Use validate_input($var, $type) before processing.
// - All responses: Use encrypt_json($data) instead of json_encode.
// - Device: In login/register, expect 'device_id' in input, call validate_and_update_device($user_id, $device_id);
// ================================

?>
```

```php
<?php
// absen_admin_list.php (UPDATED: Prepared statements, security features, encryption)
include "config.php";

add_delay();
maybe_fake_error();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 50, 5)) {  // Higher limit for lists
    echo encrypt_json(["status" => false, "message" => "Too many requests. Try again later."]);
    exit;
}

// Read endpoint - allow GET/POST
$stmt = $conn->prepare("SELECT a.*, u.nama_lengkap
                        FROM absensi a
                        JOIN users u ON u.id = a.user_id
                        ORDER BY a.id DESC");
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo encrypt_json(["status" => true, "data" => $data]);
?>
```

```php
<?php
// absen_approve.php (UPDATED: POST only, prepared, validation, security, encryption)
include "config.php";

add_delay();
maybe_fake_error();

enforce_post_only();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 10, 1)) {
    echo encrypt_json(["status" => false, "message" => "Too many requests."]);
    exit;
}

$id = $_POST['id'] ?? '';
$status = trim($_POST['status'] ?? '');

if (!validate_input($id, 'string', 1, 10, '[0-9]+') || !in_array($status, ['Disetujui', 'Ditolak'])) {
    echo encrypt_json(["status" => false, "message" => "Invalid input data."]);
    exit;
}

$stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $id);
if ($stmt->execute()) {
    echo encrypt_json(["status" => true, "message" => "Status diperbarui"]);
} else {
    echo encrypt_json(["status" => false, "message" => "Update failed."]);
}
?>
```

```php
<?php
// absen_history.php (UPDATED: Allow GET, prepared, security, encryption)
include "config.php";

add_delay();
maybe_fake_error();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 30, 2)) {
    echo encrypt_json(["status" => false, "message" => "Too many requests."]);
    exit;
}

$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? '';  // Allow GET or POST

if (!validate_input($user_id, 'string', 1, 10, '[0-9]+')) {
    echo encrypt_json(["status" => false, "message" => "Invalid user ID."]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo encrypt_json(["status" => true, "data" => $data]);
?>
```

```php
<?php
// absen.php (UPDATED: POST only, prepared statements, validation, file upload secure, security features, encryption)
// FINAL VERSION – 100% SESUAI DENGAN FLUTTER PRESENSI TERBARU (Izin tanpa lokasi, Penugasan wajib dokumen, status otomatis)

include "config.php";

add_delay();
maybe_fake_error();

enforce_post_only();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 5, 1)) {  // Strict for submissions
    echo encrypt_json(["status" => false, "message" => "Too many submissions. Wait."]);
    exit;
}

// ================================
// KOORDINAT SEKOLAH & RADIUS (WAJIB SAMA DENGAN FLUTTER!)
$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 200; // meter (sama persis dengan Flutter)

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000; // meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

// Secure input handling (JSON or POST)
$raw = file_get_contents('php://input');
error_log("RAW INPUT: " . $raw);  // Keep for debug

$input = json_decode($raw, true);
if (!$input || json_last_error() !== JSON_ERROR_NONE) {
    $input = $_POST;
}

$userId     = $input['userId'] ?? $input['user_id'] ?? '';
$jenis      = trim($input['jenis'] ?? '');
$keterangan = trim($input['keterangan'] ?? '');
$informasi  = trim($input['informasi'] ?? '');
$dokumen64  = $input['dokumenBase64'] ?? '';
$lat        = floatval($input['latitude'] ?? 0);
$lng        = floatval($input['longitude'] ?? 0);
$selfie64   = $input['base64Image'] ?? '';

// Validate core inputs
if (!validate_input($userId, 'string', 1, 10, '[0-9]+') || !validate_input($jenis, 'string', 1, 50)) {
    echo encrypt_json(["status" => false, "message" => "Invalid user ID or jenis."]);
    exit;
}
if (!in_array($jenis, ['Masuk','Pulang','Izin','Pulang Cepat','Penugasan_Masuk','Penugasan_Pulang','Penugasan_Full'])) {
    echo encrypt_json(["status" => false, "message" => "Jenis presensi tidak valid."]);
    exit;
}

// Validate keterangan for specific types
if (in_array($jenis, ['Izin', 'Pulang Cepat']) && !validate_input($keterangan, 'string', 5, 500)) {
    echo encrypt_json(["status" => false, "message" => "Keterangan wajib dan valid untuk $jenis!"]);
    exit;
}

// Validate for Penugasan
if (strpos($jenis, 'Penugasan') === 0) {
    if (!validate_input($informasi, 'string', 5, 500)) {
        echo encrypt_json(["status" => false, "message" => "Informasi penugasan wajib dan valid!"]);
        exit;
    }
    if (empty($dokumen64) || strlen($dokumen64) < 100) {  // Rough check for base64
        echo encrypt_json(["status" => false, "message" => "Dokumen penugasan wajib dan valid!"]);
        exit;
    }
}

// Location check ONLY for Masuk & Pulang
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    if ($lat == 0 || $lng == 0) {
        echo encrypt_json(["status" => false, "message" => "Lokasi tidak terdeteksi! Nyalakan GPS."]);
        exit;
    }
    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
    if ($jarak > $max_distance) {
        echo encrypt_json(["status" => false, "message" => "Di luar radius sekolah! Jarak: " . round($jarak, 1) . "m"]);
        exit;
    }
}

// Duplicate check (prepared)
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT jenis FROM absensi WHERE user_id = ? AND DATE(created_at) = ? AND jenis IN ('Masuk', 'Pulang')");
    $stmt->bind_param("is", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $absen_hari_ini = [];
    while ($row = $result->fetch_assoc()) {
        $absen_hari_ini[] = $row['jenis'];
    }
    if ($jenis == 'Masuk' && in_array('Masuk', $absen_hari_ini)) {
        echo encrypt_json(["status" => false, "message" => "Kamu sudah absen Masuk hari ini!"]);
        exit;
    }
    if ($jenis == 'Pulang' && in_array('Pulang', $absen_hari_ini)) {
        echo encrypt_json(["status" => false, "message" => "Kamu sudah absen Pulang hari ini!"]);
        exit;
    }
}

// Auto status
$status = (in_array($jenis, ['Masuk', 'Pulang'])) ? 'Disetujui' : 'Pending';

// Secure upload selfie (optional, validate base64 roughly)
$selfie_name = '';
if (!empty($selfie64) && strlen($selfie64) > 100) {
    $selfie_dir = "selfie/";
    if (!is_dir($selfie_dir)) mkdir($selfie_dir, 0777, true);
    $selfie_name = "selfie_" . $userId . "_" . time() . ".jpg";
    $selfie_path = $selfie_dir . $selfie_name;
    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $selfie64));
    if ($decoded && file_put_contents($selfie_path, $decoded) !== false) {
        error_log("Selfie berhasil disimpan: $selfie_path");
    } else {
        $selfie_name = '';
        error_log("Gagal upload selfie");
    }
}

// Secure upload dokumen (required for Penugasan, validate)
$dokumen_name = '';
if (!empty($dokumen64)) {
    $dokumen_dir = "dokumen/";
    if (!is_dir($dokumen_dir)) mkdir($dokumen_dir, 0777, true);
    $ext = (strpos($dokumen64, 'data:image') === 0) ? 'jpg' : 'pdf';
    $dokumen_name = "dokumen_" . $userId . "_" . time() . "." . $ext;
    $dokumen_path = $dokumen_dir . $dokumen_name;
    $decoded = base64_decode(preg_replace('#^data:\w+/\w+;base64,#i', '', $dokumen64));
    if ($decoded && file_put_contents($dokumen_path, $decoded) !== false) {
        error_log("Dokumen berhasil disimpan: $dokumen_path");
    } else {
        $dokumen_name = '';
        echo encrypt_json(["status" => false, "message" => "Gagal upload dokumen!"]);
        exit;
    }
}

// Insert with prepared (escape strings)
$stmt = $conn->prepare("INSERT INTO absensi (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("issssssds", $userId, $jenis, $keterangan, $informasi, $dokumen_name, $selfie_name, $lat, $lng, $status);

if ($stmt->execute()) {
    $id = $conn->insert_id;
    $jarak_resp = (in_array($jenis, ['Masuk', 'Pulang'])) ? round(calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng), 1) . "m" : null;
    echo encrypt_json([
        "status" => true,
        "message" => "Presensi $jenis berhasil dikirim!",
        "data" => [
            "id" => $id,
            "jenis" => $jenis,
            "status" => $status,
            "jarak" => $jarak_resp
        ]
    ]);
} else {
    // Cleanup files on fail
    if ($selfie_name && file_exists("selfie/$selfie_name")) unlink("selfie/$selfie_name");
    if ($dokumen_name && file_exists("dokumen/$dokumen_name")) unlink("dokumen/$dokumen_name");
    
    echo encrypt_json(["status" => false, "message" => "Gagal simpan data: " . $conn->error]);
}

$conn->close();
?>
```

```php
<?php
// delete_user.php (UPDATED: POST only, prepared, validation, device check not needed here, security, encryption)
include "config.php";

add_delay();
maybe_fake_error();

enforce_post_only();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 5, 1)) {
    echo encrypt_json(["status" => "error", "message" => "Too many requests."]);
    exit;
}

$id = $_POST["id"] ?? '';

if (!validate_input($id, 'string', 1, 10, '[0-9]+')) {
    echo encrypt_json(["status" => "error", "message" => "Invalid ID"]);
    exit;
}

$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    echo encrypt_json(["status" => "error", "message" => "User tidak ditemukan"]);
    exit;
}
$user = $result->fetch_assoc();

if ($user["role"] == "superadmin") {
    echo encrypt_json(["status" => "error", "message" => "Tidak boleh hapus superadmin"]);
    exit;
}

$del_stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
$del_stmt->bind_param("i", $id);
if ($del_stmt->execute()) {
    echo encrypt_json(["status" => "success", "message" => "User dihapus berhasil"]);
} else {
    echo encrypt_json(["status" => "error", "message" => $conn->error]);
}
?>
```

```php
<?php
// get_users.php (UPDATED: Allow GET/POST, prepared, security, encryption)
include "config.php";

add_delay();
maybe_fake_error();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 50, 5)) {
    echo encrypt_json(["status" => "error", "message" => "Too many requests."]);
    exit;
}

// Read endpoint - no POST enforce
$stmt = $conn->prepare("SELECT id, username, nama_lengkap, nip_nisn, role, device_id FROM users ORDER BY id DESC");  // Include device if needed
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo encrypt_json([
    "status" => "success",
    "message" => "Data user berhasil dimuat",
    "data" => $data
]);
?>
```

```php
<?php
// login.php (UPDATED: POST only, prepared, validation, device handling, security, encryption)
include "config.php";

add_delay();
maybe_fake_error();

enforce_post_only();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 5, 1)) {  // Strict for login
    echo encrypt_json(["status" => "error", "message" => "Too many login attempts."]);
    exit;
}

$input = $_POST["input"] ?? '';
$password = $_POST["password"] ?? '';
$device_id = $_POST["device_id"] ?? '';  // NEW: Expect from Flutter

if (!validate_input($input, 'username', 3, 50) || !validate_input($password, 'password')) {
    echo encrypt_json(["status" => "error", "message" => "Invalid credentials format."]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM users WHERE username = ? OR nip_nisn = ?");
$stmt->bind_param("ss", $input, $input);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows == 0) {
    echo encrypt_json(["status" => "error", "message" => "Akun tidak ditemukan"]);
    exit;
}
$user = $result->fetch_assoc();

if (!password_verify($password, $user["password"])) {
    echo encrypt_json(["status" => "error", "message" => "Password salah"]);
    exit;
}

// Handle device
if (!empty($device_id)) {
    $device_check = validate_and_update_device($user["id"], $device_id, 'login');
    if (!$device_check["valid"]) {
        echo encrypt_json(["status" => "error", "message" => $device_check["message"]]);
        exit;
    }
}

echo encrypt_json([
    "status" => "success",
    "message" => "Login berhasil",
    "data" => [
        "id" => $user["id"],
        "username" => $user["username"],
        "nama_lengkap" => $user["nama_lengkap"],
        "nip_nisn" => $user["nip_nisn"],
        "role" => $user["role"],
        "device_id" => $user["device_id"]  // Return current for Flutter sync
    ]
]);
?>
```

```php
<?php
// presensi_add.php (DEPRECATED? - Similar to absen.php, but kept if needed; UPDATED with security)
include 'config.php';

add_delay();
maybe_fake_error();

enforce_post_only();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 5, 1)) {
    echo encrypt_json(["success" => false, "message" => "Too many requests."]);
    exit;
}

$user_id      = $_POST['user_id'] ?? '';
$status       = trim($_POST['status'] ?? '');  // Note: 'status' here is jenis in new schema
$latitude     = floatval($_POST['latitude'] ?? 0);
$longitude   = floatval($_POST['longitude'] ?? 0);
$keterangan   = trim($_POST['keterangan'] ?? '');

if (!validate_input($user_id, 'string', 1, 10, '[0-9]+') || empty($status)) {
    echo encrypt_json(["success" => false, "message" => "Invalid input."]);
    exit;
}

// Location check (old coords, update if needed)
$school_lat = -7.791415;
$school_lng = 110.374817;
$jarak = distance($latitude, $longitude, $school_lat, $school_lng);  // Assume distance func defined elsewhere or copy from absen.php

if ($jarak > 150) { 
    echo encrypt_json(["success" => false, "message" => "Kamu berada di luar area sekolah!"]);
    exit();
}

// Secure file upload (basic, add base64 if needed)
$foto_name = "";
if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    // Validate file type/size here if needed
    $foto_name = time() . "_" . basename($_FILES['foto']['name']);
    $upload_dir = "uploads/absen/";
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    if (move_uploaded_file($_FILES['foto']['tmp_name'], $upload_dir . $foto_name)) {
        // Success
    } else {
        $foto_name = "";
    }
}

$tanggal = date("Y-m-d");
$jam = date("H:i:s");

// Insert prepared (note: schema mismatch? Use absensi table)
$stmt = $conn->prepare("INSERT INTO absensi (user_id, jenis, latitude, longitude, keterangan, selfie, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");  // Adjusted
$stmt->bind_param("isssds", $user_id, $status, $latitude, $longitude, $keterangan, $foto_name);  // selfie as foto

if ($stmt->execute()) {
    echo encrypt_json(["success" => true, "message" => "Presensi berhasil, menunggu persetujuan admin."]);
} else {
    echo encrypt_json(["success" => false, "message" => "Gagal menyimpan presensi: " . $conn->error]);
}
?>
```

```php
<?php
// presensi_approve.php (UPDATED: Similar to absen_approve, POST only, prepared, etc.)
include 'config.php';

add_delay();
maybe_fake_error();

enforce_post_only();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 10, 1)) {
    echo encrypt_json(["status" => false, "message" => "Too many requests."]);
    exit;
}

$id = trim($_POST['id'] ?? '');
$status = trim($_POST['status'] ?? '');

if (!validate_input($id, 'string', 1, 10, '[0-9]+') || !in_array($status, ['Disetujui', 'Ditolak'])) {
    echo encrypt_json(["status" => false, "message" => "Invalid ID or status."]);
    exit;
}

$stmt = $conn->prepare("UPDATE absensi SET status = ? WHERE id = ?");
$stmt->bind_param("si", $status, $id);
if ($stmt->execute()) {
    echo encrypt_json(["status" => true, "message" => "Status berhasil diupdate ke '$status'"]);
} else {
    echo encrypt_json(["status" => false, "message" => "Update gagal: " . $conn->error]);
}
?>
```

```php
<?php
// presensi_pending.php (UPDATED: Prepared, security, encryption)
include 'config.php';

add_delay();
maybe_fake_error();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 50, 5)) {
    echo encrypt_json(["status" => false, "message" => "Too many requests."]);
    exit;
}

$stmt = $conn->prepare("SELECT a.*, u.nama_lengkap
                        FROM absensi a
                        JOIN users u ON a.user_id = u.id
                        WHERE a.status = 'Pending'");
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = $row['status'] ?? 'Pending';
    $data[] = $row;
}
echo encrypt_json(["status" => true, "data" => $data]);
?>
```

```php
<?php
// presensi_rekap.php (UPDATED: Prepared, security, encryption)
include 'config.php';

add_delay();
maybe_fake_error();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 50, 5)) {
    echo encrypt_json(["status" => false, "message" => "Too many requests."]);
    exit;
}

$stmt = $conn->prepare("SELECT a.*, u.nama_lengkap, u.username
                        FROM absensi a
                        JOIN users u ON a.user_id = u.id
                        ORDER BY a.created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = $row['status'] ?? 'Pending';
    $data[] = $row;
}
echo encrypt_json(["status" => true, "data" => $data]);
?>
```

```php
<?php
// presensi_user_history.php (DEPRECATED - use absen_history.php; UPDATED if kept)
include 'config.php';

add_delay();
maybe_fake_error();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 30, 2)) {
    echo encrypt_json(["status" => false, "message" => "Too many requests."]);
    exit;
}

$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? '';

if (!validate_input($user_id, 'string', 1, 10, '[0-9]+')) {
    echo encrypt_json(["status" => false, "message" => "Invalid user ID."]);
    exit;
}

$stmt = $conn->prepare("SELECT * FROM absensi WHERE user_id = ? ORDER BY id DESC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$data = [];
while ($row = $result->fetch_assoc()) {
    $row['status'] = $row['status'] ?? 'Pending';
    $data[] = $row;
}
echo encrypt_json(["status" => true, "data" => $data]);
?>
```

```php
<?php
// register.php (UPDATED: POST only, prepared, validation incl. NIP for non-karyawan, device, security, encryption)
include "config.php";

add_delay();
maybe_fake_error();

enforce_post_only();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 3, 5)) {  // Very strict for register
    echo encrypt_json(["status" => "error", "message" => "Too many registrations."]);
    exit;
}

$username = trim($_POST["username"] ?? '');
$nama = trim($_POST["nama_lengkap"] ?? '');
$nip_nisn = trim($_POST["nip_nisn"] ?? '');
$password_raw = $_POST["password"] ?? '';
$role = trim($_POST["role"] ?? 'user');
$is_karyawan = filter_var($_POST["is_karyawan"] ?? false, FILTER_VALIDATE_BOOLEAN);
$device_id = $_POST["device_id"] ?? '';  // NEW

if (!validate_input($username, 'username') || !validate_input($nama, 'nama') || !validate_input($password_raw, 'password')) {
    echo encrypt_json(["status" => "error", "message" => "Invalid username, name, or password format."]);
    exit;
}

if (!$is_karyawan && empty($nip_nisn) || !validate_input($nip_nisn, 'nip_nisn')) {
    echo encrypt_json(["status" => "error", "message" => "NIP/NISN wajib dan valid untuk guru!"]);
    exit;
}

if (!in_array($role, ['user', 'admin', 'superadmin'])) {
    echo encrypt_json(["status" => "error", "message" => "Invalid role."]);
    exit;
}

$password = password_hash($password_raw, PASSWORD_DEFAULT);

// Check admin/superadmin limit (prepared)
if ($role == "admin") {
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'admin'");
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo encrypt_json(["status" => "error", "message" => "Admin sudah ada"]);
        exit;
    }
}
if ($role == "superadmin") {
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'superadmin'");
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        echo encrypt_json(["status" => "error", "message" => "Superadmin sudah ada"]);
        exit;
    }
}

// Check duplicate username/nip
$check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR nip_nisn = ?");
$check_stmt->bind_param("ss", $username, $nip_nisn);
$check_stmt->execute();
if ($check_stmt->get_result()->num_rows > 0) {
    echo encrypt_json(["status" => "error", "message" => "Username or NIP/NISN already exists."]);
    exit;
}

// Insert prepared
$stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role, device_id) VALUES (?, ?, ?, ?, ?, ?)");
$device_to_insert = !empty($device_id) ? $device_id : null;
$stmt->bind_param("ssssss", $username, $nama, $nip_nisn, $password, $role, $device_to_insert);

if ($stmt->execute()) {
    $new_id = $conn->insert_id;
    // Update device if provided
    if (!empty($device_id)) {
        validate_and_update_device($new_id, $device_id, 'register');  // Just updates
    }
    echo encrypt_json(["status" => "success", "message" => "Akun berhasil dibuat"]);
} else {
    echo encrypt_json(["status" => "error", "message" => "Gagal daftar: " . $conn->error]);
}
?>
```

```php
<?php
// update_user.php (UPDATED: POST only, prepared, validation, security, encryption)
// Note: There were two versions; this combines (password optional)
include "config.php";

add_delay();
maybe_fake_error();

enforce_post_only();

$ip = $_SERVER['REMOTE_ADDR'];
$endpoint = basename(__FILE__);
if (!check_rate_limit($ip, $endpoint, 10, 2)) {
    echo encrypt_json(["status" => "error", "message" => "Too many requests."]);
    exit;
}

$id = $_POST["id"] ?? '';
$nama = trim($_POST["nama_lengkap"] ?? '');
$username = trim($_POST["username"] ?? '');
$password = $_POST["password"] ?? '';  // Optional

if (!validate_input($id, 'string', 1, 10, '[0-9]+') || !validate_input($username, 'username') || !validate_input($nama, 'nama')) {
    echo encrypt_json(["status" => "error", "message" => "Invalid ID, username, or name."]);
    exit;
}

if (!empty($password) && !validate_input($password, 'password')) {
    echo encrypt_json(["status" => "error", "message" => "Invalid password format."]);
    exit;
}

// Check duplicate username
$dup_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
$dup_stmt->bind_param("si", $username, $id);
$dup_stmt->execute();
if ($dup_stmt->get_result()->num_rows > 0) {
    echo encrypt_json(["status" => "error", "message" => "Username already taken."]);
    exit;
}

$sql = "UPDATE users SET username = ?, nama_lengkap = ?";
$params = [$username, $nama];
$types = "ss";

if (!empty($password)) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $sql .= ", password = ?";
    $params[] = $hashed;
    $types .= "s";
}

$sql .= " WHERE id = ?";
$params[] = $id;
$types .= "i";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo encrypt_json(["status" => "success", "message" => "User diperbarui"]);
} else {
    echo encrypt_json(["status" => "error", "message" => $conn->error]);
}
?>
```

Di atas adalah code php yang fix jangan di ubah kamu cukup perbaiki pada dart nya dan kalau ada perbuahan di php ketikan juga btw ketikan semua code yang berubah yang g berubah g usah 

```dart
// lib/main.dart 
import 'package:flutter/material.dart';
import 'package:device_info_plus/device_info_plus.dart'; // NEW: For device ID (used in services)
import 'api/api_service.dart'; // NEW: Import to init device_id early

import 'pages/login_page.dart';
import 'pages/register_page.dart';
import 'pages/dashboard_page.dart';
import 'pages/user_management_page.dart';
import 'pages/presensi_page.dart';
import 'pages/history_page.dart';
import 'pages/admin_presensi_page.dart';
import 'pages/admin_user_list_page.dart';
import 'pages/admin_user_detail_page.dart';
import 'pages/rekap_page.dart';
import 'models/user_model.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await ApiService.initDeviceId(); // NEW: Init device ID early to avoid delays
  runApp(const SkadutaApp());
}

class SkadutaApp extends StatelessWidget {
  const SkadutaApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Skaduta Presensi',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorSchemeSeed: Colors.blueGrey,
        useMaterial3: true,
        scaffoldBackgroundColor: Colors.grey.shade50,
        textTheme: const TextTheme(bodyLarge: TextStyle(fontSize: 18)),
        cardTheme: const CardThemeData(elevation: 6), // FIXED: CardThemeData
        appBarTheme: const AppBarTheme(
          centerTitle: true,
          backgroundColor: Colors.blueGrey,
          foregroundColor: Colors.white,
        ),
      ),
      initialRoute: '/login',
      routes: {
        '/login': (_) => const LoginPage(),
        '/register': (_) => const RegisterPage(),
        '/admin-presensi': (_) => const AdminPresensiPage(),
        '/rekap': (_) => const RekapPage(),
      },
      onGenerateRoute: (settings) {
        if (settings.name == '/dashboard') {
          final user = settings.arguments as UserModel;
          return MaterialPageRoute(builder: (_) => DashboardPage(user: user));
        }
        if (settings.name == '/user-management') {
          return MaterialPageRoute(builder: (_) => const UserManagementPage());
        }
        if (settings.name == '/presensi') {
          final args = settings.arguments as Map<String, dynamic>;
          return MaterialPageRoute(
            builder: (_) =>
                PresensiPage(user: args['user'], initialJenis: args['jenis']),
          );
        }
        if (settings.name == '/history') {
          final user = settings.arguments as UserModel;
          return MaterialPageRoute(builder: (_) => HistoryPage(user: user));
        }
        if (settings.name == '/admin-user-list') {
          return MaterialPageRoute(builder: (_) => const AdminUserListPage());
        }
        return null;
      },
    );
  }
}

```

```dart
// config.dart
const String baseUrl =
    "https://nonlitigious-alene-uninfinitely.ngrok-free.dev/backendapk/";

```

```dart
// lib/api/api_service.dart 
import 'dart:convert';
import 'dart:io'; // For Platform
import 'package:http/http.dart' as http;
import 'package:encrypt/encrypt.dart' as encrypt; // For AES decryption
import 'package:device_info_plus/device_info_plus.dart'; // For device ID
import 'package:shared_preferences/shared_preferences.dart'; // Store device_id

class ApiService {
  static const String baseUrl =
      "https://nonlitigious-alene-uninfinitely.ngrok-free.dev/backendapk/";
  static const String _encryptionKey = 'nugra21'; // Matches backend
  // REMOVED: static const int _method = encrypt.KeyMode.aes; // Invalid; use AESMode.cbc directly
  static SharedPreferences? _prefs;
  static String? _deviceId;

  // NEW: Initialize and get device ID (call once in main or login)
  static Future<void> initDeviceId() async {
    if (_deviceId != null) return;
    _prefs = await SharedPreferences.getInstance();
    _deviceId = _prefs?.getString('device_id');
    if (_deviceId == null) {
      final deviceInfo = DeviceInfoPlugin();
      String id;
      if (Platform.isAndroid) {
        final androidInfo = await deviceInfo.androidInfo;
        id = androidInfo.id;
      } else if (Platform.isIOS) {
        final iosInfo = await deviceInfo.iosInfo;
        id = iosInfo.identifierForVendor ?? '';
      } else {
        id = DateTime.now().millisecondsSinceEpoch.toString(); // Fallback
      }
      _deviceId = id;
      await _prefs?.setString('device_id', id);
    }
  }

  static String get deviceId => _deviceId ?? '';

  // NEW: Helper to decrypt response body (all API responses are encrypted)
  static Map<String, dynamic> _decryptResponse(String encryptedBody) {
    try {
      final decoded = base64Decode(encryptedBody);
      if (decoded.length < 16) {
        throw Exception('Invalid encrypted data');
      }
      final iv = encrypt.IV(
        decoded.sublist(0, 16),
      ); // First 16 bytes IV for AES-256-CBC
      final ciphertext = encrypt.Encrypted(decoded.sublist(16));
      final key = encrypt.Key.fromUtf8(
        _encryptionKey.padRight(32, '0'),
      ); // Pad to 32 bytes
      final encrypter = encrypt.Encrypter(
        encrypt.AES(key, mode: encrypt.AESMode.cbc),
      ); // FIXED: Direct AESMode.cbc
      final decrypted = encrypter.decrypt(ciphertext, iv: iv);
      return jsonDecode(decrypted) as Map<String, dynamic>;
    } catch (e) {
      print('DECRYPT ERROR: $e');
      throw Exception('Decryption failed: $e');
    }
  }

  // NEW: Input validation helpers (strict, matches backend)
  static bool _validateUsername(String input) =>
      RegExp(r'^[a-zA-Z0-9_]{3,50}$').hasMatch(input);
  static bool _validateNama(String input) =>
      RegExp(r'^[a-zA-Z\s]{2,100}$').hasMatch(input);
  static bool _validateNipNisn(String input) =>
      RegExp(r'^[0-9]{5,20}$').hasMatch(input);
  static bool _validatePassword(String input) =>
      input.length >= 6 && input.length <= 255;
  static bool _validateId(String id) => RegExp(r'^[0-9]{1,10}$').hasMatch(id);
  static bool _validateJenis(String jenis) => [
    'Masuk',
    'Pulang',
    'Izin',
    'Pulang Cepat',
    'Penugasan_Masuk',
    'Penugasan_Pulang',
    'Penugasan_Full',
  ].contains(jenis);
  static bool _validateKeterangan(String keterangan) =>
      keterangan.trim().length >= 5 && keterangan.trim().length <= 500;

  // LOGIN (UPDATED: Add device_id; validation; decrypt)
  static Future<Map<String, dynamic>> login({
    required String input,
    required String password,
  }) async {
    await initDeviceId();
    if (!_validateUsername(input) || !_validatePassword(password)) {
      throw Exception('Invalid input format');
    }
    final res = await http.post(
      Uri.parse("$baseUrl/login.php"),
      body: {"input": input, "password": password, "device_id": deviceId},
    );
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    return _decryptResponse(res.body);
  }

  // REGISTER (UPDATED: device_id; validation; decrypt)
  static Future<Map<String, dynamic>> register({
    required String username,
    required String namaLengkap,
    required String nipNisn,
    required String password,
    required String role,
    required bool isKaryawan,
  }) async {
    await initDeviceId();
    if (!_validateUsername(username) ||
        !_validateNama(namaLengkap) ||
        !_validatePassword(password) ||
        !['user', 'admin', 'superadmin'].contains(role)) {
      throw Exception('Invalid input format');
    }
    if (!isKaryawan && (!_validateNipNisn(nipNisn) || nipNisn.isEmpty)) {
      throw Exception('NIP/NISN required for non-karyawan');
    }
    final res = await http.post(
      Uri.parse("$baseUrl/register.php"),
      body: {
        "username": username,
        "nama_lengkap": namaLengkap,
        "nip_nisn": nipNisn,
        "password": password,
        "role": role,
        "is_karyawan": isKaryawan ? '1' : '0',
        "device_id": deviceId, // NEW
      },
    );
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    return _decryptResponse(res.body);
  }

  // GET ALL USERS (UPDATED: decrypt; validation not needed for GET)
  static Future<List<dynamic>> getUsers() async {
    final res = await http.get(Uri.parse("$baseUrl/get_users.php"));
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    final data = _decryptResponse(res.body);
    if (data["status"] == "success") {
      return data["data"] as List<dynamic>;
    }
    return [];
  }

  // DELETE USER (UPDATED: validation; decrypt)
  static Future<Map<String, dynamic>> deleteUser(String id) async {
    if (!_validateId(id)) {
      throw Exception('Invalid ID format');
    }
    final res = await http.post(
      Uri.parse("$baseUrl/delete_user.php"),
      body: {"id": id},
    );
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    return _decryptResponse(res.body);
  }

  // UPDATE USER (UPDATED: validation; decrypt)
  static Future<Map<String, dynamic>> updateUser({
    required String id,
    required String username,
    required String namaLengkap,
    String? password,
  }) async {
    if (!_validateId(id) ||
        !_validateUsername(username) ||
        !_validateNama(namaLengkap)) {
      throw Exception('Invalid input format');
    }
    if (password != null && !_validatePassword(password)) {
      throw Exception('Invalid password format');
    }
    final body = {"id": id, "username": username, "nama_lengkap": namaLengkap};
    if (password != null && password.isNotEmpty) {
      body["password"] = password;
    }
    final res = await http.post(
      Uri.parse("$baseUrl/update_user.php"),
      body: body,
    );
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    return _decryptResponse(res.body);
  }

  // PRESENSI SUBMIT (UPDATED: validation; decrypt; debug prints)
  static Future<Map<String, dynamic>> submitPresensi({
    required String userId,
    required String jenis,
    required String keterangan,
    required String informasi,
    required String dokumenBase64,
    required String latitude,
    required String longitude,
    required String base64Image,
  }) async {
    if (!_validateId(userId) ||
        !_validateJenis(jenis) ||
        !_validateKeterangan(keterangan) ||
        double.tryParse(latitude) == null ||
        double.tryParse(longitude) == null) {
      throw Exception('Invalid input format');
    }
    // For Penugasan: check informasi and dokumen
    if (jenis.startsWith('Penugasan') &&
        (informasi.trim().length < 5 || dokumenBase64.length < 100)) {
      throw Exception('Informasi and dokumen required for Penugasan');
    }
    final body = {
      "userId": userId,
      "jenis": jenis,
      "keterangan": keterangan,
      "informasi": informasi,
      "dokumenBase64": dokumenBase64,
      "latitude": latitude,
      "longitude": longitude,
      "base64Image": base64Image,
    };
    print('DEBUG API: Request body: ${jsonEncode(body)}');
    final res = await http.post(
      Uri.parse("$baseUrl/absen.php"),
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: body,
    );
    print('DEBUG API: Status code: ${res.statusCode}');
    print(
      'DEBUG API: Encrypted response body: ${res.body.substring(0, 100)}...',
    );
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    return _decryptResponse(res.body);
  }

  // GET USER HISTORY (UPDATED: decrypt)
  static Future<List<dynamic>> getUserHistory(String userId) async {
    if (!_validateId(userId)) {
      throw Exception('Invalid user ID');
    }
    final res = await http.get(
      Uri.parse("$baseUrl/absen_history.php?user_id=$userId"),
    );
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    final data = _decryptResponse(res.body);
    if (data["status"] == true) {
      return data["data"] as List<dynamic>;
    }
    return [];
  }

  // GET ALL PRESENSI (ADMIN) (UPDATED: decrypt; better error handling)
  static Future<List<dynamic>> getAllPresensi() async {
    final res = await http.get(Uri.parse("$baseUrl/absen_admin_list.php"));
    print('DEBUG API: Presensi status: ${res.statusCode}');
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    try {
      final data = _decryptResponse(res.body);
      if (data["status"] == true) {
        return data["data"] as List<dynamic>;
      }
      return [];
    } catch (e) {
      print('DEBUG API: Parse Error: $e');
      throw Exception('Invalid response: $e');
    }
  }

  // NEW: Get Pending Presensi for Admin (using presensi_pending.php)
  static Future<List<dynamic>> getPendingPresensi() async {
    final res = await http.get(Uri.parse("$baseUrl/presensi_pending.php"));
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    final data = _decryptResponse(res.body);
    if (data["status"] == true) {
      return data["data"] as List<dynamic>;
    }
    return [];
  }

  // GET REKAP (UPDATED: decrypt; params if needed - backend not updated yet)
  static Future<List<dynamic>> getRekap({String? month, String? year}) async {
    var url = "$baseUrl/presensi_rekap.php";
    if (month != null && year != null) {
      url += "?month=$month&year=$year";
    }
    final res = await http.get(Uri.parse(url));
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    final data = _decryptResponse(res.body);
    if (data["status"] == true) {
      return data["data"] as List<dynamic>;
    }
    return [];
  }

  // UPDATE PRESENSI STATUS (UPDATED: validation; decrypt; debug)
  static Future<Map<String, dynamic>> updatePresensiStatus({
    required String id,
    required String status,
  }) async {
    if (!_validateId(id) || !['Disetujui', 'Ditolak'].contains(status)) {
      throw Exception('Invalid ID or status');
    }
    final body = {"id": id, "status": status};
    print('DEBUG API UPDATE: Body: ${jsonEncode(body)}');
    final res = await http.post(
      Uri.parse("$baseUrl/presensi_approve.php"),
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: body,
    );
    print('DEBUG API UPDATE: Status: ${res.statusCode}');
    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${res.body}');
    }
    try {
      final data = _decryptResponse(res.body);
      print('DEBUG API UPDATE: Parsed: ${jsonEncode(data)}');
      return data;
    } catch (e) {
      print('DEBUG API UPDATE: Parse Error: $e');
      throw Exception('Invalid response: $e');
    }
  }
}
// eror 
```

```dart
// models/user_model.dart 
class UserModel {
  final String id;
  final String username;
  final String namaLengkap;
  final String nipNisn;
  final String role;
  final String? deviceId; // NEW: Optional, from login/register response

  UserModel({
    required this.id,
    required this.username,
    required this.namaLengkap,
    required this.nipNisn,
    required this.role,
    this.deviceId,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) {
    return UserModel(
      id: json['id'].toString(),
      username: json['username'] ?? '',
      namaLengkap: json['nama_lengkap'] ?? '',
      nipNisn: json['nip_nisn'] ?? '',
      role: json['role'] ?? 'user',
      deviceId: json['device_id'], // NEW: Handle device_id from API
    );
  }
}

```

```dart
// pages/admin_history_page.dart
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../api/api_service.dart';

class AdminHistoryPage extends StatefulWidget {
  const AdminHistoryPage({super.key});

  @override
  State<AdminHistoryPage> createState() => _AdminHistoryPageState();
}

class _AdminHistoryPageState extends State<AdminHistoryPage> {
  List<dynamic> _history = [];
  bool _loading = false;
  DateTime? _startDate;
  DateTime? _endDate;

  @override
  void initState() {
    super.initState();
    _loadAllHistory();
  }

  Future<void> _loadAllHistory() async {
    setState(() => _loading = true);
    try {
      final data = await ApiService.getAllPresensi();
      setState(() {
        _history = data ?? [];
        _history.sort(
          (a, b) =>
              DateTime.parse(
                b['created_at'] ?? DateTime.now().toIso8601String(),
              ).compareTo(
                DateTime.parse(
                  a['created_at'] ?? DateTime.now().toIso8601String(),
                ),
              ),
        );
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Gagal load history: $e'),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
    } finally {
      setState(() => _loading = false);
    }
  }

  List<dynamic> get _filteredHistory {
    var filtered = _history;
    if (_startDate != null) {
      filtered = filtered.where((h) {
        final created = DateTime.parse(
          h['created_at'] ?? DateTime.now().toIso8601String(),
        );
        return !created.isBefore(_startDate!);
      }).toList();
    }
    if (_endDate != null) {
      filtered = filtered.where((h) {
        final created = DateTime.parse(
          h['created_at'] ?? DateTime.now().toIso8601String(),
        );
        return !created.isAfter(_endDate!);
      }).toList();
    }
    return filtered;
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          "Rekap Presensi Semua User",
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded, size: 28),
            onPressed: _loadAllHistory,
          ),
          PopupMenuButton<String>(
            onSelected: (value) {
              if (value == 'filter') {
                _showDateFilter();
              } else if (value == 'clear') {
                setState(() {
                  _startDate = null;
                  _endDate = null;
                });
              }
            },
            itemBuilder: (context) => [
              const PopupMenuItem(
                value: 'filter',
                child: Row(
                  children: [
                    Icon(Icons.date_range),
                    SizedBox(width: 8),
                    Text('Filter Tanggal'),
                  ],
                ),
              ),
              const PopupMenuItem(
                value: 'clear',
                child: Row(
                  children: [
                    Icon(Icons.clear_all),
                    SizedBox(width: 8),
                    Text('Clear Filter'),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(width: 8),
        ],
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                cs.primary.withOpacity(0.9),
                cs.primary.withOpacity(0.6),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.05), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: _loading
            ? const Center(
                child: CircularProgressIndicator(
                  strokeWidth: 4,
                  color: Colors.blue,
                ),
              )
            : RefreshIndicator(
                onRefresh: _loadAllHistory,
                child: _filteredHistory.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              Icons.history_toggle_off_rounded,
                              size: 80,
                              color: Colors.grey.shade300,
                            ),
                            const SizedBox(height: 16),
                            Text(
                              _startDate != null || _endDate != null
                                  ? 'Tidak ada data di periode ini'
                                  : 'Belum ada history presensi',
                              style: TextStyle(
                                fontSize: 18,
                                color: Colors.grey.shade500,
                                fontWeight: FontWeight.w500,
                              ),
                            ),
                          ],
                        ),
                      )
                    : CustomScrollView(
                        slivers: [
                          SliverPadding(
                            padding: const EdgeInsets.fromLTRB(16, 100, 16, 20),
                            sliver: SliverList(
                              delegate: SliverChildBuilderDelegate((
                                context,
                                index,
                              ) {
                                final h = _filteredHistory[index];
                                final created = DateTime.parse(
                                  h['created_at'] ??
                                      DateTime.now().toIso8601String(),
                                );
                                final formattedDate = DateFormat(
                                  'dd MMM yyyy HH:mm',
                                ).format(created);
                                final status = h['status'] ?? 'Pending';
                                final statusColor = status == 'Disetujui'
                                    ? Colors.green
                                    : status == 'Ditolak'
                                    ? Colors.red
                                    : Colors.orange;
                                final jenisIcon = _getJenisIcon(
                                  h['jenis'] ?? '',
                                );

                                return Card(
                                  elevation: 4,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(16),
                                  ),
                                  margin: const EdgeInsets.symmetric(
                                    vertical: 8,
                                  ),
                                  child: ListTile(
                                    leading: CircleAvatar(
                                      backgroundColor: cs.primary.withOpacity(
                                        0.1,
                                      ),
                                      child:
                                          jenisIcon ??
                                          Text(
                                            (h['nama_lengkap'] ?? '?')[0]
                                                .toUpperCase(),
                                            style: TextStyle(
                                              color: cs.primary,
                                              fontWeight: FontWeight.bold,
                                            ),
                                          ),
                                    ),
                                    title: Text(
                                      h['nama_lengkap'] ?? 'Unknown',
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w600,
                                        fontSize: 16,
                                      ),
                                    ),
                                    subtitle: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          "Jenis: ${h['jenis'] ?? ''}",
                                          style: TextStyle(
                                            color: Colors.grey.shade600,
                                          ),
                                        ),
                                        Text(
                                          "Tanggal: $formattedDate",
                                          style: TextStyle(
                                            color: Colors.grey.shade600,
                                          ),
                                        ),
                                        Text(
                                          "Ket: ${h['keterangan'] ?? '-'}",
                                          style: TextStyle(
                                            color: Colors.grey.shade600,
                                          ),
                                        ),
                                        // NEW: Show informasi for Penugasan
                                        if ((h['jenis'] ?? '').startsWith(
                                              'Penugasan',
                                            ) &&
                                            (h['informasi'] ?? '').isNotEmpty)
                                          Text(
                                            "Info: ${(h['informasi'] ?? '').substring(0, 50)}${(h['informasi'] ?? '').length > 50 ? '...' : ''}",
                                            style: TextStyle(
                                              color: Colors.grey.shade600,
                                              fontStyle: FontStyle.italic,
                                            ),
                                          ),
                                        // NEW: Show dokumen if exists
                                        if ((h['dokumen'] ?? '').isNotEmpty)
                                          Text(
                                            "Dok: ${h['dokumen']}",
                                            style: TextStyle(
                                              color: Colors.blue.shade600,
                                              fontStyle: FontStyle.italic,
                                            ),
                                          ),
                                        // NEW: Show location if available (for Masuk/Pulang)
                                        if ([
                                              'Masuk',
                                              'Pulang',
                                            ].contains(h['jenis']) &&
                                            h['latitude'] != null &&
                                            h['longitude'] != null)
                                          Text(
                                            "Lok: ${h['latitude']}, ${h['longitude']}",
                                            style: TextStyle(
                                              color: Colors.grey.shade600,
                                              fontSize: 12,
                                            ),
                                          ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(
                                            horizontal: 8,
                                            vertical: 4,
                                          ),
                                          decoration: BoxDecoration(
                                            color: statusColor.withOpacity(0.1),
                                            borderRadius: BorderRadius.circular(
                                              12,
                                            ),
                                          ),
                                          child: Text(
                                            "Status: $status",
                                            style: TextStyle(
                                              color: statusColor,
                                              fontWeight: FontWeight.w600,
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                    trailing: Icon(
                                      status == 'Disetujui'
                                          ? Icons.check_circle
                                          : status == 'Ditolak'
                                          ? Icons.cancel
                                          : Icons.pending,
                                      color: statusColor,
                                    ),
                                  ),
                                );
                              }, childCount: _filteredHistory.length),
                            ),
                          ),
                        ],
                      ),
              ),
      ),
    );
  }

  Icon? _getJenisIcon(String jenis) {
    return switch (jenis) {
      'Masuk' => Icon(Icons.login_rounded, color: Colors.green, size: 24),
      'Pulang' => Icon(Icons.logout_rounded, color: Colors.orange, size: 24),
      'Izin' => Icon(Icons.sick_rounded, color: Colors.red, size: 24),
      'Pulang Cepat' => Icon(
        Icons.fast_forward_rounded,
        color: Colors.amber,
        size: 24,
      ),
      'Penugasan_Masuk' || 'Penugasan_Pulang' || 'Penugasan_Full' => Icon(
        Icons.assignment_rounded,
        color: Colors.purple,
        size: 24,
      ),
      _ => null,
    };
  }

  void _showDateFilter() {
    showDialog(
      context: context,
      builder: (context) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Row(
          children: [
            Icon(Icons.date_range_rounded),
            SizedBox(width: 8),
            Text('Filter Tanggal'),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            ElevatedButton.icon(
              onPressed: () async {
                final date = await showDatePicker(
                  context: context,
                  initialDate: DateTime.now(),
                  firstDate: DateTime(2020),
                  lastDate: DateTime.now(),
                );
                if (date != null && mounted) setState(() => _startDate = date);
                if (mounted) Navigator.pop(context);
              },
              icon: const Icon(Icons.date_range),
              label: Text(
                _startDate == null
                    ? 'Pilih Tanggal Mulai'
                    : DateFormat('dd MMM yyyy').format(_startDate!),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.blue.shade50,
              ),
            ),
            const SizedBox(height: 12),
            ElevatedButton.icon(
              onPressed: () async {
                final date = await showDatePicker(
                  context: context,
                  initialDate: DateTime.now(),
                  firstDate: DateTime(2020),
                  lastDate: DateTime.now(),
                );
                if (date != null && mounted) setState(() => _endDate = date);
                if (mounted) Navigator.pop(context);
              },
              icon: const Icon(Icons.date_range),
              label: Text(
                _endDate == null
                    ? 'Pilih Tanggal Selesai'
                    : DateFormat('dd MMM yyyy').format(_endDate!),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.blue.shade50,
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () {
              setState(() {
                _startDate = null;
                _endDate = null;
              });
              Navigator.pop(context);
            },
            child: const Text('Clear'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Apply'),
          ),
        ],
      ),
    );
  }
}

```

```dart
// pages/admin_presensi_page.dart
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import '../api/api_service.dart';

class AdminPresensiPage extends StatefulWidget {
  const AdminPresensiPage({super.key});

  @override
  State<AdminPresensiPage> createState() => _AdminPresensiPageState();
}

class _AdminPresensiPageState extends State<AdminPresensiPage> {
  bool _loading = false;
  List<dynamic> _items = [];
  String _filterStatus = 'All';

  @override
  void initState() {
    super.initState();
    _loadPresensi();
  }

  Future<void> _loadPresensi() async {
    setState(() => _loading = true);
    try {
      final data = await ApiService.getAllPresensi();
      setState(() {
        _items = data ?? [];
        _items.sort(
          (a, b) =>
              DateTime.parse(
                b['created_at'] ?? DateTime.now().toIso8601String(),
              ).compareTo(
                DateTime.parse(
                  a['created_at'] ?? DateTime.now().toIso8601String(),
                ),
              ),
        );
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Gagal ambil data presensi: $e'),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  List<dynamic> get _filteredItems {
    if (_filterStatus == 'All') return _items;
    return _items
        .where((item) => (item['status'] ?? '') == _filterStatus)
        .toList();
  }

  String _shortenText(String text, {int maxLength = 50}) {
    if (text.isEmpty) return '';
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength.clamp(0, text.length)) + '...';
  }

  Future<void> _showDetailDialog(dynamic item) async {
    final status = item['status'] ?? 'Pending';
    final baseUrl = ApiService.baseUrl;
    final selfie = item['selfie'];
    final dokumen = item['dokumen'];
    final fotoUrl = selfie != null && selfie.toString().isNotEmpty
        ? '$baseUrl/selfie/$selfie'
        : null;
    final dokumenUrl = dokumen != null && dokumen.toString().isNotEmpty
        ? '$baseUrl/dokumen/$dokumen'
        : null;
    final created = DateTime.parse(
      item['created_at'] ?? DateTime.now().toIso8601String(),
    );
    final formattedDate = DateFormat('dd MMM yyyy HH:mm').format(created);

    showDialog(
      context: context,
      builder: (context) => Dialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        child: Container(
          constraints: const BoxConstraints(maxHeight: 600, maxWidth: 500),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.primary,
                  borderRadius: const BorderRadius.only(
                    topLeft: Radius.circular(16),
                    topRight: Radius.circular(16),
                  ),
                ),
                child: Row(
                  children: [
                    Icon(
                      status == 'Disetujui'
                          ? Icons.check_circle
                          : status == 'Ditolak'
                          ? Icons.cancel
                          : Icons.pending,
                      color: status == 'Disetujui'
                          ? Colors.green
                          : status == 'Ditolak'
                          ? Colors.red
                          : Colors.orange,
                      size: 32,
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        '${item['nama_lengkap']} - ${item['jenis']}',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close, color: Colors.white),
                      onPressed: () => Navigator.pop(context),
                    ),
                  ],
                ),
              ),
              Flexible(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Tanggal: $formattedDate',
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'Keterangan: ${item['keterangan'] ?? '-'}',
                        style: const TextStyle(fontSize: 16),
                      ),
                      if (item['informasi'] != null &&
                          item['informasi'].toString().isNotEmpty) ...[
                        const SizedBox(height: 8),
                        Text(
                          'Informasi Penugasan: ${item['informasi']}',
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                      // NEW: Show location if available (for Masuk/Pulang)
                      if (['Masuk', 'Pulang'].contains(item['jenis']) &&
                          item['latitude'] != null &&
                          item['longitude'] != null) ...[
                        const SizedBox(height: 8),
                        Text(
                          'Lokasi: ${item['latitude']}, ${item['longitude']}',
                          style: TextStyle(
                            fontSize: 14,
                            color: Colors.grey.shade600,
                          ),
                        ),
                      ],
                      if (fotoUrl != null) ...[
                        const SizedBox(height: 12),
                        const Text(
                          'Foto Presensi:',
                          style: TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 8),
                        GestureDetector(
                          onTap: () => _showFullPhoto(fotoUrl),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: Image.network(
                              fotoUrl,
                              height: 200,
                              width: double.infinity,
                              fit: BoxFit.cover,
                              loadingBuilder:
                                  (context, child, loadingProgress) {
                                    if (loadingProgress == null) return child;
                                    return const Center(
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                      ),
                                    );
                                  },
                              errorBuilder: (context, error, stackTrace) =>
                                  Container(
                                    height: 200,
                                    decoration: BoxDecoration(
                                      color: Colors.grey[100],
                                      borderRadius: BorderRadius.circular(8),
                                    ),
                                    child: const Icon(
                                      Icons.error,
                                      size: 50,
                                      color: Colors.grey,
                                    ),
                                  ),
                            ),
                          ),
                        ),
                      ] else ...[
                        const SizedBox(height: 12),
                        Container(
                          padding: const EdgeInsets.all(16),
                          decoration: BoxDecoration(
                            color: Colors.grey[100],
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Row(
                            children: [
                              const Icon(
                                Icons.image_not_supported,
                                color: Colors.grey,
                                size: 32,
                              ),
                              const SizedBox(width: 8),
                              const Text(
                                'Tidak ada foto',
                                style: TextStyle(
                                  color: Colors.grey,
                                  fontSize: 16,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                      if (dokumenUrl != null) ...[
                        const SizedBox(height: 12),
                        const Text(
                          'Dokumen Penugasan:',
                          style: TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 8),
                        GestureDetector(
                          onTap: () => _showFullDokumen(dokumenUrl),
                          child: Container(
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(
                              color: Colors.blue[50],
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: Colors.blue),
                            ),
                            child: Row(
                              children: [
                                const Icon(
                                  Icons.attachment,
                                  color: Colors.blue,
                                  size: 24,
                                ),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    'Lihat Dokumen (${item['dokumen']})',
                                    style: const TextStyle(
                                      fontSize: 16,
                                      fontWeight: FontWeight.w500,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                      const SizedBox(height: 12),
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color:
                              (status == 'Disetujui'
                                      ? Colors.green
                                      : status == 'Ditolak'
                                      ? Colors.red
                                      : Colors.orange)
                                  .withOpacity(0.1),
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Row(
                          children: [
                            Icon(
                              (status == 'Disetujui'
                                  ? Icons.check_circle
                                  : status == 'Ditolak'
                                  ? Icons.cancel
                                  : Icons.pending),
                              color: (status == 'Disetujui'
                                  ? Colors.green
                                  : status == 'Ditolak'
                                  ? Colors.red
                                  : Colors.orange),
                            ),
                            const SizedBox(width: 8),
                            Text(
                              'Status Saat Ini: $status',
                              style: TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                                color: (status == 'Disetujui'
                                    ? Colors.green
                                    : status == 'Ditolak'
                                    ? Colors.red
                                    : Colors.orange),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
              if (status == 'Pending')
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: const BoxDecoration(
                    border: Border(top: BorderSide(color: Colors.grey)),
                    color: Colors.white,
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                    children: [
                      ElevatedButton.icon(
                        onPressed: () {
                          Navigator.pop(context);
                          _updateStatus(item['id'].toString(), 'Disetujui');
                        },
                        icon: const Icon(Icons.check, color: Colors.white),
                        label: const Text('Setujui'),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.green,
                        ),
                      ),
                      ElevatedButton.icon(
                        onPressed: () {
                          Navigator.pop(context);
                          _updateStatus(item['id'].toString(), 'Ditolak');
                        },
                        icon: const Icon(Icons.close, color: Colors.white),
                        label: const Text('Tolak'),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.red,
                        ),
                      ),
                    ],
                  ),
                )
              else
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: const BoxDecoration(
                    border: Border(top: BorderSide(color: Colors.grey)),
                    color: Colors.white,
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.end,
                    children: [
                      ElevatedButton(
                        onPressed: () => Navigator.pop(context),
                        child: const Text('Tutup'),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }

  void _showFullPhoto(String url) {
    showDialog(
      context: context,
      builder: (context) => Dialog(
        backgroundColor: Colors.black,
        insetPadding: const EdgeInsets.all(16),
        child: Stack(
          children: [
            Center(
              child: InteractiveViewer(
                child: Image.network(url, fit: BoxFit.contain),
              ),
            ),
            Positioned(
              top: 16,
              right: 16,
              child: IconButton(
                icon: const Icon(Icons.close, color: Colors.white),
                onPressed: () => Navigator.pop(context),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _launchInBrowser(String url) async {
    final uri = Uri.parse(url);
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      if (mounted)
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Tidak dapat membuka dokumen')),
        );
    }
  }

  void _showFullDokumen(String url) {
    // Detect if PDF or image based on extension (simple check)
    final isPdf = url.toLowerCase().endsWith('.pdf');
    showDialog(
      context: context,
      builder: (context) => Dialog(
        insetPadding: const EdgeInsets.all(16),
        child: SizedBox(
          width: MediaQuery.of(context).size.width * 0.9,
          height: MediaQuery.of(context).size.height * 0.7,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 16,
                  vertical: 8,
                ),
                color: Theme.of(context).colorScheme.primary,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Text(
                      'Dokumen',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    Row(
                      children: [
                        IconButton(
                          icon: const Icon(
                            Icons.open_in_browser,
                            color: Colors.white,
                          ),
                          onPressed: () => _launchInBrowser(url),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close, color: Colors.white),
                          onPressed: () => Navigator.pop(context),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
              Expanded(
                child: isPdf
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(
                              Icons.insert_drive_file,
                              size: 64,
                              color: Colors.blue,
                            ),
                            const SizedBox(height: 16),
                            const Text(
                              'Dokumen PDF tidak dapat ditampilkan di sini.',
                              textAlign: TextAlign.center,
                            ),
                            const SizedBox(height: 8),
                            ElevatedButton.icon(
                              onPressed: () => _launchInBrowser(url),
                              icon: const Icon(Icons.open_in_browser),
                              label: const Text('Buka di Browser'),
                            ),
                          ],
                        ),
                      )
                    : Center(
                        child: InteractiveViewer(
                          child: Image.network(
                            url,
                            fit: BoxFit.contain,
                            errorBuilder: (context, error, stackTrace) => Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                const Icon(Icons.insert_drive_file, size: 64),
                                const SizedBox(height: 16),
                                const Text(
                                  'Dokumen tidak dapat ditampilkan di sini.',
                                  textAlign: TextAlign.center,
                                ),
                                const SizedBox(height: 8),
                                ElevatedButton.icon(
                                  onPressed: () => _launchInBrowser(url),
                                  icon: const Icon(Icons.open_in_browser),
                                  label: Text('Buka di Browser'),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _updateStatus(String id, String status) async {
    try {
      final res = await ApiService.updatePresensiStatus(id: id, status: status);
      if (res['status'] == true) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Status diperbarui'),
            backgroundColor: Colors.green.shade600,
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Gagal update status'),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
      _loadPresensi();
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Error: $e'),
          backgroundColor: Colors.red.shade600,
        ),
      );
    }
  }

  Icon? _getJenisIcon(String jenis) {
    return switch (jenis) {
      'Masuk' => Icon(Icons.login_rounded, color: Colors.green, size: 24),
      'Pulang' => Icon(Icons.logout_rounded, color: Colors.orange, size: 24),
      'Izin' => Icon(Icons.sick_rounded, color: Colors.red, size: 24),
      'Pulang Cepat' => Icon(
        Icons.fast_forward_rounded,
        color: Colors.amber,
        size: 24,
      ),
      'Penugasan_Masuk' || 'Penugasan_Pulang' || 'Penugasan_Full' => Icon(
        Icons.assignment_rounded,
        color: Colors.purple,
        size: 24,
      ),
      _ => null,
    };
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          'Persetujuan Presensi',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        actions: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.2),
              borderRadius: BorderRadius.circular(20),
            ),
            child: DropdownButton<String>(
              value: _filterStatus,
              style: const TextStyle(color: Colors.white, fontSize: 16),
              underline: const SizedBox(),
              dropdownColor: cs.primary, // Dark background for dropdown menu
              items: ['All', 'Pending', 'Disetujui', 'Ditolak']
                  .map(
                    (s) => DropdownMenuItem(
                      value: s,
                      child: Text(
                        s,
                        style: const TextStyle(
                          color: Colors.white,
                        ), // White text for visibility
                      ),
                    ),
                  )
                  .toList(),
              onChanged: (v) => setState(() => _filterStatus = v ?? 'All'),
            ),
          ),
          const SizedBox(width: 8),
        ],
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                cs.primary.withOpacity(0.9),
                cs.primary.withOpacity(0.6),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.05), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 100, 16, 16),
              child: Card(
                elevation: 2,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Text(
                    'Total: ${_filteredItems.length}',
                    style: const TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 18,
                    ),
                  ),
                ),
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(
                      child: CircularProgressIndicator(
                        strokeWidth: 4,
                        color: Colors.blue,
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _loadPresensi,
                      child: _filteredItems.isEmpty
                          ? Center(
                              child: Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(
                                    Icons.pending_actions,
                                    size: 80,
                                    color: Colors.grey.shade300,
                                  ),
                                  const SizedBox(height: 16),
                                  Text(
                                    'Tidak ada presensi ${_filterStatus.toLowerCase()}',
                                    style: TextStyle(
                                      fontSize: 18,
                                      color: Colors.grey.shade500,
                                      fontWeight: FontWeight.w500,
                                    ),
                                  ),
                                ],
                              ),
                            )
                          : ListView.builder(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 16,
                              ),
                              itemCount: _filteredItems.length,
                              itemBuilder: (ctx, i) {
                                final item = _filteredItems[i];
                                final status = item['status'] ?? 'Pending';
                                final statusColor = status == 'Disetujui'
                                    ? Colors.green
                                    : status == 'Ditolak'
                                    ? Colors.red
                                    : Colors.orange;
                                final created = DateTime.parse(
                                  item['created_at'] ??
                                      DateTime.now().toIso8601String(),
                                );
                                final formattedDate = DateFormat(
                                  'dd MMM',
                                ).format(created);
                                final baseUrl = ApiService.baseUrl;
                                final selfie = item['selfie'];
                                final dokumen = item['dokumen'];
                                final fotoUrl =
                                    selfie != null &&
                                        selfie.toString().isNotEmpty
                                    ? '$baseUrl/selfie/$selfie'
                                    : null;
                                final informasi =
                                    item['informasi']?.toString() ?? '';

                                return Card(
                                  elevation: 4,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(16),
                                  ),
                                  margin: const EdgeInsets.symmetric(
                                    vertical: 8,
                                  ),
                                  child: ListTile(
                                    onTap: () => _showDetailDialog(item),
                                    leading: CircleAvatar(
                                      backgroundColor: cs.primary.withOpacity(
                                        0.1,
                                      ),
                                      child:
                                          _getJenisIcon(item['jenis'] ?? '') ??
                                          Text(
                                            (item['nama_lengkap'] ?? '?')[0]
                                                .toUpperCase(),
                                            style: TextStyle(
                                              color: cs.primary,
                                              fontWeight: FontWeight.bold,
                                            ),
                                          ),
                                    ),
                                    title: Text(
                                      '${item['nama_lengkap'] ?? ''} - ${item['jenis'] ?? ''}',
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w600,
                                        fontSize: 16,
                                      ),
                                    ),
                                    subtitle: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          'Tgl: $formattedDate',
                                          style: TextStyle(
                                            fontSize: 14,
                                            color: Colors.grey.shade600,
                                          ),
                                        ),
                                        Text(
                                          'Ket: ${item['keterangan'] ?? '-'}',
                                          style: TextStyle(
                                            fontSize: 14,
                                            color: Colors.grey.shade600,
                                          ),
                                        ),
                                        if (informasi.isNotEmpty)
                                          Text(
                                            'Info: ${_shortenText(informasi)}',
                                            style: TextStyle(
                                              fontSize: 14,
                                              color: Colors.grey.shade600,
                                            ),
                                          ),
                                        // NEW: Show attachment icon if dokumen exists
                                        if (dokumen != null &&
                                            dokumen.toString().isNotEmpty) ...[
                                          const SizedBox(height: 4),
                                          Row(
                                            children: [
                                              const Icon(
                                                Icons.attachment,
                                                size: 16,
                                                color: Colors.blue,
                                              ),
                                              const SizedBox(width: 4),
                                              Text(
                                                'Dokumen tersedia',
                                                style: TextStyle(
                                                  fontSize: 12,
                                                  color: Colors.blue.shade600,
                                                ),
                                              ),
                                            ],
                                          ),
                                        ],
                                        Container(
                                          padding: const EdgeInsets.symmetric(
                                            horizontal: 8,
                                            vertical: 2,
                                          ),
                                          decoration: BoxDecoration(
                                            color: statusColor.withOpacity(0.1),
                                            borderRadius: BorderRadius.circular(
                                              12,
                                            ),
                                          ),
                                          child: Text(
                                            'Status: $status',
                                            style: TextStyle(
                                              fontSize: 14,
                                              fontWeight: FontWeight.w600,
                                              color: statusColor,
                                            ),
                                          ),
                                        ),
                                        if (fotoUrl != null) ...[
                                          const SizedBox(height: 4),
                                          GestureDetector(
                                            onTap: () =>
                                                _showFullPhoto(fotoUrl),
                                            child: ClipRRect(
                                              borderRadius:
                                                  BorderRadius.circular(8),
                                              child: Image.network(
                                                fotoUrl,
                                                height: 50,
                                                width: 50,
                                                fit: BoxFit.cover,
                                                loadingBuilder:
                                                    (
                                                      context,
                                                      child,
                                                      loadingProgress,
                                                    ) {
                                                      if (loadingProgress ==
                                                          null)
                                                        return child;
                                                      return Container(
                                                        height: 50,
                                                        width: 50,
                                                        color: Colors.grey[200],
                                                        child: const Center(
                                                          child:
                                                              CircularProgressIndicator(
                                                                strokeWidth: 2,
                                                              ),
                                                        ),
                                                      );
                                                    },
                                                errorBuilder:
                                                    (
                                                      context,
                                                      error,
                                                      stackTrace,
                                                    ) => Container(
                                                      height: 50,
                                                      width: 50,
                                                      decoration: BoxDecoration(
                                                        color: Colors.grey[200],
                                                        borderRadius:
                                                            BorderRadius.circular(
                                                              8,
                                                            ),
                                                      ),
                                                      child: const Icon(
                                                        Icons
                                                            .image_not_supported,
                                                        size: 25,
                                                        color: Colors.grey,
                                                      ),
                                                    ),
                                              ),
                                            ),
                                          ),
                                        ],
                                      ],
                                    ),
                                    trailing: status == 'Pending'
                                        ? const Icon(
                                            Icons.arrow_forward_ios_rounded,
                                            size: 20,
                                            color: Colors.orange,
                                          )
                                        : Icon(
                                            status == 'Disetujui'
                                                ? Icons.check_circle
                                                : Icons.cancel,
                                            color: statusColor,
                                            size: 28,
                                          ),
                                  ),
                                );
                              },
                            ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

```

```dart
// lib/pages/admin_user_detail_page.dart
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart'; // NEW: For launching dokumen (PDF/browser)
import '../api/api_service.dart';

class AdminUserDetailPage extends StatefulWidget {
  const AdminUserDetailPage({
    super.key,
    required this.userId,
    required this.userName,
  });

  final String userId;
  final String userName;

  @override
  State<AdminUserDetailPage> createState() => _AdminUserDetailPageState();
}

class _AdminUserDetailPageState extends State<AdminUserDetailPage>
    with SingleTickerProviderStateMixin {
  late final TabController _tabController;

  bool _loading = true;
  List<dynamic> _history = [];
  List<dynamic> _pendingPresensi = [];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _loadData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    if (!mounted) return;
    setState(() => _loading = true);

    try {
      // 1. Riwayat user
      final historyData = await ApiService.getUserHistory(widget.userId);
      if (mounted)
        setState(() {
          _history = historyData ?? [];
          _history.sort(
            (a, b) =>
                DateTime.parse(
                  b['created_at'] ?? DateTime.now().toIso8601String(),
                ).compareTo(
                  DateTime.parse(
                    a['created_at'] ?? DateTime.now().toIso8601String(),
                  ),
                ),
          );
        });

      // 2. Cari presensi yang masih pending
      final allPresensi = await ApiService.getAllPresensi();
      final pending = allPresensi
          .where(
            (p) =>
                p['user_id'].toString() == widget.userId &&
                (p['status'] ?? '').toString() == 'Pending',
          )
          .toList();

      if (mounted) setState(() => _pendingPresensi = pending);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Gagal memuat data: $e'),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _updateStatus(String id, String status) async {
    try {
      final res = await ApiService.updatePresensiStatus(id: id, status: status);

      if (!mounted) return;

      final message = res['message'] ?? 'Status diperbarui';
      final isSuccess = res['status'] == true;

      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(message),
          backgroundColor: isSuccess
              ? Colors.green.shade600
              : Colors.red.shade600,
        ),
      );

      if (isSuccess) _loadData(); // Refresh hanya jika sukses
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Error: $e'),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
    }
  }

  // Foto fullscreen
  void _showFullPhoto(String? url) {
    if (url == null || url.isEmpty) return;
    showDialog(
      context: context,
      builder: (_) => Dialog(
        backgroundColor: Colors.black,
        child: Stack(
          children: [
            Center(
              child: InteractiveViewer(
                child: Image.network(url, fit: BoxFit.contain),
              ),
            ),
            Positioned(
              top: 20,
              right: 20,
              child: IconButton(
                icon: const Icon(Icons.close, color: Colors.white, size: 36),
                onPressed: () => Navigator.pop(context),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // Dokumen fullscreen (UPDATED: Detect PDF vs image, launch for PDF)
  Future<void> _showFullDokumen(String? url) async {
    if (url == null || url.isEmpty) return;
    final isPdf = url.toLowerCase().endsWith('.pdf');
    if (isPdf) {
      // Launch PDF in browser/external
      final uri = Uri.parse(url);
      if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Tidak dapat membuka dokumen')),
          );
        }
      }
    } else {
      // Show image
      showDialog(
        context: context,
        builder: (_) => Dialog(
          child: Stack(
            children: [
              Center(
                child: InteractiveViewer(
                  child: Image.network(
                    url,
                    fit: BoxFit.contain,
                    loadingBuilder: (context, child, progress) =>
                        progress == null
                        ? child
                        : const Center(child: CircularProgressIndicator()),
                    errorBuilder: (context, error, stackTrace) => const Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.error, color: Colors.red, size: 60),
                          SizedBox(height: 16),
                          Text(
                            'Gagal memuat dokumen',
                            style: TextStyle(color: Colors.white),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
              Positioned(
                top: 20,
                right: 20,
                child: IconButton(
                  icon: const Icon(Icons.close, color: Colors.white, size: 36),
                  onPressed: () => Navigator.pop(context),
                ),
              ),
            ],
          ),
        ),
      );
    }
  }

  Icon? _getJenisIcon(String jenis) {
    return switch (jenis) {
      'Masuk' => Icon(Icons.login_rounded, color: Colors.green, size: 24),
      'Pulang' => Icon(Icons.logout_rounded, color: Colors.orange, size: 24),
      'Izin' => Icon(Icons.sick_rounded, color: Colors.red, size: 24),
      'Pulang Cepat' => Icon(
        Icons.fast_forward_rounded,
        color: Colors.amber,
        size: 24,
      ),
      'Penugasan_Masuk' || 'Penugasan_Pulang' || 'Penugasan_Full' => Icon(
        Icons.assignment_rounded,
        color: Colors.purple,
        size: 24,
      ),
      _ => null,
    };
  }

  Widget _buildHistoryTab() {
    if (_loading)
      return const Center(
        child: CircularProgressIndicator(strokeWidth: 4, color: Colors.blue),
      );
    if (_history.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(
              Icons.history_toggle_off_rounded,
              size: 80,
              color: Colors.grey[300],
            ),
            SizedBox(height: 16),
            Text(
              'Belum ada riwayat presensi',
              style: TextStyle(
                fontSize: 18,
                color: Colors.grey[500],
                fontWeight: FontWeight.w500,
              ),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _loadData,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _history.length,
        itemBuilder: (_, i) {
          final item = _history[i];
          final status = item['status'] ?? 'Pending';
          final Color statusColor = status == 'Disetujui'
              ? Colors.green
              : status == 'Ditolak'
              ? Colors.red
              : Colors.orange;

          final created = DateTime.parse(
            item['created_at'] ?? DateTime.now().toIso8601String(),
          );
          final formattedDate = DateFormat('dd MMM yyyy HH:mm').format(created);

          final baseUrl = ApiService.baseUrl;
          final fotoUrl = item['selfie']?.toString().isNotEmpty == true
              ? '$baseUrl/selfie/${item['selfie']}'
              : null;
          final dokumenUrl = item['dokumen']?.toString().isNotEmpty == true
              ? '$baseUrl/dokumen/${item['dokumen']}'
              : null;

          return Card(
            elevation: 4,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(16),
            ),
            margin: const EdgeInsets.symmetric(vertical: 6),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      if (fotoUrl != null)
                        GestureDetector(
                          onTap: () => _showFullPhoto(fotoUrl),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: Image.network(
                              fotoUrl,
                              width: 70,
                              height: 70,
                              fit: BoxFit.cover,
                              errorBuilder: (_, __, ___) => Container(
                                width: 70,
                                height: 70,
                                color: Colors.grey[200],
                                child: const Icon(
                                  Icons.broken_image,
                                  color: Colors.grey,
                                ),
                              ),
                            ),
                          ),
                        ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                if (_getJenisIcon(item['jenis'] ?? '') != null)
                                  _getJenisIcon(item['jenis'] ?? '')!,
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    item['jenis'] ?? 'Tidak ada jenis',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 18,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                            Text(
                              'Tanggal: $formattedDate',
                              style: TextStyle(color: Colors.grey[600]),
                            ),
                            Text(
                              'Keterangan: ${item['keterangan'] ?? '-'}',
                              style: TextStyle(color: Colors.grey[600]),
                            ),
                            // NEW: Show informasi for Penugasan
                            if (item['informasi']?.toString().isNotEmpty ==
                                true)
                              Text(
                                'Info: ${item['informasi']}',
                                style: TextStyle(color: Colors.grey[600]),
                              ),
                            // NEW: Show location for Masuk/Pulang
                            if (['Masuk', 'Pulang'].contains(item['jenis']) &&
                                item['latitude'] != null &&
                                item['longitude'] != null)
                              Text(
                                'Lokasi: ${item['latitude']}, ${item['longitude']}',
                                style: TextStyle(
                                  color: Colors.grey[600],
                                  fontSize: 12,
                                ),
                              ),
                          ],
                        ),
                      ),
                      if (dokumenUrl != null) ...[
                        GestureDetector(
                          onTap: () => _showFullDokumen(dokumenUrl),
                          child: Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 8,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.blue[50],
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: Colors.blue.shade200!),
                            ),
                            child: const Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(
                                  Icons.attachment,
                                  color: Colors.blue,
                                  size: 20,
                                ),
                                SizedBox(width: 6),
                                Text(
                                  'Dokumen',
                                  style: TextStyle(
                                    color: Colors.blue,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 4,
                    ),
                    decoration: BoxDecoration(
                      color: statusColor.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(
                          status == 'Disetujui'
                              ? Icons.check_circle
                              : status == 'Ditolak'
                              ? Icons.cancel
                              : Icons.pending,
                          color: statusColor,
                          size: 20,
                        ),
                        const SizedBox(width: 4),
                        Text(
                          'Status: $status',
                          style: TextStyle(
                            color: statusColor,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildPendingTab() {
    if (_loading)
      return const Center(
        child: CircularProgressIndicator(strokeWidth: 4, color: Colors.blue),
      );
    if (_pendingPresensi.isEmpty) {
      return Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(Icons.pending_actions, size: 80, color: Colors.grey[300]),
            SizedBox(height: 16),
            Text(
              'Tidak ada presensi pending',
              style: TextStyle(
                fontSize: 18,
                color: Colors.grey[500],
                fontWeight: FontWeight.w500,
              ),
            ),
          ],
        ),
      );
    }

    return RefreshIndicator(
      onRefresh: _loadData,
      child: ListView.builder(
        padding: const EdgeInsets.all(16),
        itemCount: _pendingPresensi.length,
        itemBuilder: (_, i) {
          final item = _pendingPresensi[i];
          final created = DateTime.parse(
            item['created_at'] ?? DateTime.now().toIso8601String(),
          );
          final formattedDate = DateFormat('dd MMM yyyy HH:mm').format(created);

          final baseUrl = ApiService.baseUrl;
          final fotoUrl = item['selfie']?.toString().isNotEmpty == true
              ? '$baseUrl/selfie/${item['selfie']}'
              : null;
          final dokumenUrl = item['dokumen']?.toString().isNotEmpty == true
              ? '$baseUrl/dokumen/${item['dokumen']}'
              : null;

          return Card(
            elevation: 4,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(16),
            ),
            margin: const EdgeInsets.symmetric(vertical: 6),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      if (fotoUrl != null)
                        GestureDetector(
                          onTap: () => _showFullPhoto(fotoUrl),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: Image.network(
                              fotoUrl,
                              width: 70,
                              height: 70,
                              fit: BoxFit.cover,
                            ),
                          ),
                        ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                if (_getJenisIcon(item['jenis'] ?? '') != null)
                                  _getJenisIcon(item['jenis'] ?? '')!,
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Text(
                                    item['jenis'] ?? 'Tidak ada jenis',
                                    style: const TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 18,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                            Text(
                              'Tanggal: $formattedDate',
                              style: TextStyle(color: Colors.grey[600]),
                            ),
                            Text(
                              'Keterangan: ${item['keterangan'] ?? '-'}',
                              style: TextStyle(color: Colors.grey[600]),
                            ),
                            // NEW: Show informasi for Penugasan
                            if (item['informasi']?.toString().isNotEmpty ==
                                true)
                              Text(
                                'Info: ${item['informasi']}',
                                style: TextStyle(color: Colors.grey[600]),
                              ),
                            // NEW: Show location for Masuk/Pulang
                            if (['Masuk', 'Pulang'].contains(item['jenis']) &&
                                item['latitude'] != null &&
                                item['longitude'] != null)
                              Text(
                                'Lokasi: ${item['latitude']}, ${item['longitude']}',
                                style: TextStyle(
                                  color: Colors.grey[600],
                                  fontSize: 12,
                                ),
                              ),
                          ],
                        ),
                      ),
                      if (dokumenUrl != null) ...[
                        GestureDetector(
                          onTap: () => _showFullDokumen(dokumenUrl),
                          child: Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 8,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.blue[50],
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: Colors.blue.shade200!),
                            ),
                            child: const Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.attachment, color: Colors.blue),
                                SizedBox(width: 6),
                                Text(
                                  'Dokumen',
                                  style: TextStyle(
                                    color: Colors.blue,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 8,
                      vertical: 4,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.orange.withOpacity(0.1),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.pending, color: Colors.orange, size: 20),
                        SizedBox(width: 4),
                        Text(
                          'Status: Pending',
                          style: TextStyle(
                            color: Colors.orange,
                            fontWeight: FontWeight.w600,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                    children: [
                      ElevatedButton.icon(
                        onPressed: () =>
                            _updateStatus(item['id'].toString(), 'Disetujui'),
                        icon: const Icon(Icons.check, color: Colors.white),
                        label: const Text('Setujui'),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.green,
                        ),
                      ),
                      ElevatedButton.icon(
                        onPressed: () =>
                            _updateStatus(item['id'].toString(), 'Ditolak'),
                        icon: const Icon(Icons.close, color: Colors.white),
                        label: const Text('Tolak'),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: Colors.red,
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: Text(
          widget.userName,
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 20),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded, size: 28),
            onPressed: _loadData,
          ),
        ],
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                cs.primary.withOpacity(0.9),
                cs.primary.withOpacity(0.6),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        ),
        bottom: TabBar(
          controller: _tabController,
          indicatorColor: Colors.white,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          tabs: const [
            Tab(icon: Icon(Icons.history_rounded), text: 'Riwayat'),
            Tab(icon: Icon(Icons.pending_actions_rounded), text: 'Pending'),
          ],
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.05), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: _loading && _history.isEmpty && _pendingPresensi.isEmpty
            ? const Center(
                child: CircularProgressIndicator(
                  strokeWidth: 4,
                  color: Colors.blue,
                ),
              )
            : Padding(
                padding: EdgeInsets.fromLTRB(
                  16,
                  MediaQuery.of(context).padding.top + 130,
                  16,
                  16,
                ),
                child: TabBarView(
                  controller: _tabController,
                  children: [_buildHistoryTab(), _buildPendingTab()],
                ),
              ),
      ),
    );
  }
}

```

```dart
// lib/pages/admin_user_list_page.dart
import 'package:flutter/material.dart';
import '../api/api_service.dart';
import 'admin_user_detail_page.dart';

class AdminUserListPage extends StatefulWidget {
  const AdminUserListPage({super.key});

  @override
  State<AdminUserListPage> createState() => _AdminUserListPageState();
}

class _AdminUserListPageState extends State<AdminUserListPage> {
  bool _loading = true;
  List<dynamic> _users = [];
  List<dynamic> _filteredUsers = [];
  final TextEditingController _searchC = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadUsers();
    _searchC.addListener(_filterUsers);
  }

  @override
  void dispose() {
    _searchC.removeListener(_filterUsers);
    _searchC.dispose();
    super.dispose();
  }

  Future<void> _loadUsers() async {
    setState(() => _loading = true);
    try {
      final data = await ApiService.getUsers();
      final filtered = (data as List)
          .where(
            (u) =>
                (u['role']?.toString().toLowerCase() ?? '') == 'user' &&
                (u['id']?.toString().isNotEmpty ?? false),
          )
          .toList();

      setState(() {
        _users = filtered;
        _filteredUsers = filtered
          ..sort(
            (a, b) => (a['nama_lengkap'] ?? '').toString().compareTo(
              (b['nama_lengkap'] ?? ''),
            ),
          );
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Gagal memuat user: $e'),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _filterUsers() {
    final query = _searchC.text.toLowerCase().trim();
    setState(() {
      _filteredUsers =
          query.isEmpty
                ? _users
                : _users.where((u) {
                    final nama = (u['nama_lengkap'] ?? u['nama'] ?? '')
                        .toString()
                        .toLowerCase();
                    final username = (u['username'] ?? '')
                        .toString()
                        .toLowerCase();
                    final nip = (u['nip_nisn'] ?? '').toString().toLowerCase();
                    return nama.contains(query) ||
                        username.contains(query) ||
                        nip.contains(query);
                  }).toList()
            ..sort(
              (a, b) => (a['nama_lengkap'] ?? '').toString().compareTo(
                (b['nama_lengkap'] ?? ''),
              ),
            );
    });
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          'Kelola User Presensi',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded, size: 28),
            onPressed: _loadUsers,
          ),
          const SizedBox(width: 8),
        ],
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                cs.primary.withOpacity(0.9),
                cs.primary.withOpacity(0.6),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.05), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: Column(
          children: [
            Padding(
              padding: EdgeInsets.fromLTRB(
                16,
                MediaQuery.of(context).padding.top + 100,
                16,
                16,
              ),
              child: Card(
                elevation: 2,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.all(16),
                      child: TextField(
                        controller: _searchC,
                        decoration: InputDecoration(
                          hintText: 'Cari nama, username, atau NIP/NISN...',
                          prefixIcon: Icon(
                            Icons.search_rounded,
                            color: cs.primary,
                          ),
                          suffixIcon: _searchC.text.isNotEmpty
                              ? IconButton(
                                  icon: const Icon(Icons.clear_rounded),
                                  onPressed: _searchC.clear,
                                )
                              : null,
                          border: InputBorder.none,
                          filled: true,
                          fillColor: Colors.transparent,
                        ),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                      child: Text(
                        'Total: ${_filteredUsers.length}',
                        style: const TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 18,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(
                      child: CircularProgressIndicator(
                        strokeWidth: 4,
                        color: Colors.blue,
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _loadUsers,
                      child: _filteredUsers.isEmpty
                          ? Center(
                              child: Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(
                                    Icons.people_outline_rounded,
                                    size: 80,
                                    color: Colors.grey[400],
                                  ),
                                  const SizedBox(height: 16),
                                  Text(
                                    _searchC.text.isNotEmpty
                                        ? 'Tidak ditemukan'
                                        : 'Belum ada user',
                                    style: TextStyle(
                                      fontSize: 18,
                                      color: Colors.grey[600],
                                      fontWeight: FontWeight.w500,
                                    ),
                                  ),
                                ],
                              ),
                            )
                          : ListView.builder(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 16,
                              ),
                              itemCount: _filteredUsers.length,
                              itemBuilder: (ctx, i) {
                                final u = _filteredUsers[i];
                                final nama =
                                    u['nama_lengkap'] ?? u['nama'] ?? 'Unknown';
                                final username = u['username'] ?? '';
                                final nip = u['nip_nisn']?.toString() ?? '';
                                final deviceId =
                                    u['device_id'] ??
                                    ''; // NEW: From updated schema
                                final userId = u['id'].toString();

                                return Card(
                                  elevation: 4,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(16),
                                  ),
                                  margin: const EdgeInsets.symmetric(
                                    vertical: 8,
                                  ),
                                  child: InkWell(
                                    borderRadius: BorderRadius.circular(16),
                                    onTap: () => Navigator.push(
                                      context,
                                      MaterialPageRoute(
                                        builder: (_) => AdminUserDetailPage(
                                          userId: userId,
                                          userName: nama,
                                        ),
                                      ),
                                    ),
                                    child: Padding(
                                      padding: const EdgeInsets.all(16),
                                      child: Row(
                                        children: [
                                          CircleAvatar(
                                            radius: 30,
                                            backgroundColor: cs.primary
                                                .withOpacity(0.1),
                                            child: Text(
                                              username.isNotEmpty
                                                  ? username[0].toUpperCase()
                                                  : 'U',
                                              style: TextStyle(
                                                fontSize: 24,
                                                fontWeight: FontWeight.bold,
                                                color: cs.primary,
                                              ),
                                            ),
                                          ),
                                          const SizedBox(width: 16),
                                          Expanded(
                                            child: Column(
                                              crossAxisAlignment:
                                                  CrossAxisAlignment.start,
                                              children: [
                                                Text(
                                                  nama,
                                                  style: const TextStyle(
                                                    fontSize: 18,
                                                    fontWeight: FontWeight.bold,
                                                  ),
                                                ),
                                                const SizedBox(height: 4),
                                                Text(
                                                  'Username: $username',
                                                  style: TextStyle(
                                                    color: Colors.grey[600],
                                                  ),
                                                ),
                                                if (nip.isNotEmpty)
                                                  Text(
                                                    'NIP/NISN: $nip',
                                                    style: TextStyle(
                                                      color: Colors.grey[600],
                                                    ),
                                                  ),
                                                // NEW: Show device ID if available
                                                if (deviceId.isNotEmpty)
                                                  Text(
                                                    'Device: ${deviceId.substring(0, 20)}${deviceId.length > 20 ? '...' : ''}',
                                                    style: TextStyle(
                                                      color: Colors.grey[600],
                                                      fontSize: 12,
                                                    ),
                                                  ),
                                              ],
                                            ),
                                          ),
                                          const Icon(
                                            Icons.arrow_forward_ios_rounded,
                                            color: Colors.grey,
                                          ),
                                        ],
                                      ),
                                    ),
                                  ),
                                );
                              },
                            ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

```

```dart
// pages/dashboard_page.dart 
import 'package:flutter/material.dart';
import '../models/user_model.dart';

class DashboardPage extends StatefulWidget {
  // CHANGED: To Stateful for potential future state (e.g., device sync)
  final UserModel user;
  const DashboardPage({super.key, required this.user});

  @override
  State<DashboardPage> createState() => _DashboardPageState();
}

class _DashboardPageState extends State<DashboardPage> {
  @override
  void initState() {
    super.initState();
    // Optional: Sync device ID on dashboard load (if needed for security checks)
    // ApiService.initDeviceId();  // Already called in login
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final deviceId =
        widget.user.deviceId ?? 'Not set'; // NEW: Use updated user model
    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          'Dashboard',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        actions: [
          Center(
            child: Padding(
              padding: const EdgeInsets.only(right: 8.0),
              child: Chip(
                label: Text(
                  widget.user.role.toUpperCase(),
                  style: const TextStyle(
                    fontSize: 14,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                backgroundColor: cs.primary.withOpacity(0.15),
                side: BorderSide(color: cs.primary, width: 1.5),
                avatar: Icon(Icons.shield, size: 16, color: cs.primary),
              ),
            ),
          ),
          IconButton(
            icon: const Icon(Icons.logout_rounded, size: 28),
            onPressed: () {
              Navigator.pushNamedAndRemoveUntil(
                context,
                '/login',
                (route) => false,
              );
            },
          ),
          const SizedBox(width: 4),
        ],
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                cs.primary.withOpacity(0.9),
                cs.primary.withOpacity(0.6),
              ],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
          ),
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.05), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: CustomScrollView(
          slivers: [
            SliverToBoxAdapter(
              child: Padding(
                padding: EdgeInsets.fromLTRB(
                  20,
                  MediaQuery.of(context).padding.top + 100,
                  20,
                  20,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Halo, ${widget.user.namaLengkap}',
                      style: TextStyle(
                        fontSize: 28,
                        fontWeight: FontWeight.bold,
                        color: cs.primary,
                      ),
                    ),
                    const SizedBox(height: 6),
                    Text(
                      'Selamat datang di sistem presensi Skaduta',
                      style: TextStyle(color: Colors.grey[600], fontSize: 18),
                    ),
                    // NEW: Optional device info for user (hidden for non-admin, or always small)
                    if (widget.user.role != 'user' &&
                        deviceId != 'Not set') ...[
                      const SizedBox(height: 10),
                      Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: Colors.grey[100],
                          borderRadius: BorderRadius.circular(8),
                        ),
                        child: Text(
                          'Device ID: ${deviceId.substring(0, 20)}${deviceId.length > 20 ? '...' : ''}',
                          style: TextStyle(
                            fontSize: 12,
                            color: Colors.grey[700],
                          ),
                        ),
                      ),
                    ],
                    const SizedBox(height: 30),
                  ],
                ),
              ),
            ),
            SliverToBoxAdapter(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (widget.user.role == 'user') _buildUserSection(context),
                    if (widget.user.role == 'admin')
                      _buildAdminSection(context),
                    if (widget.user.role == 'superadmin')
                      _buildSuperAdminSection(context),
                    const SizedBox(height: 20),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _card({
    required IconData icon,
    required String title,
    required String subtitle,
    required VoidCallback onTap,
    Color? color,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      child: Material(
        elevation: 4,
        borderRadius: BorderRadius.circular(16),
        child: InkWell(
          borderRadius: BorderRadius.circular(16),
          onTap: onTap,
          splashColor: color?.withOpacity(0.1) ?? Colors.blue.withOpacity(0.1),
          child: Padding(
            padding: const EdgeInsets.all(20),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: (color ?? Colors.blue).withOpacity(0.1),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(icon, size: 40, color: color ?? Colors.blue),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        subtitle,
                        style: TextStyle(fontSize: 16, color: Colors.grey[600]),
                      ),
                    ],
                  ),
                ),
                Icon(
                  Icons.arrow_forward_ios_rounded,
                  size: 24,
                  color: Colors.grey[600],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildUserSection(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        // UPDATED: 4 biasa buttons + 1 Penugasan
        _card(
          icon: Icons.login_rounded,
          title: 'Absen Masuk Biasa',
          subtitle: 'Absen masuk harian (otomatis disetujui)',
          onTap: () => _navigateToPresensi(context, 'Masuk'),
          color: Colors.green,
        ),
        _card(
          icon: Icons.logout_rounded,
          title: 'Absen Pulang Biasa',
          subtitle: 'Absen pulang harian (otomatis disetujui)',
          onTap: () => _navigateToPresensi(context, 'Pulang'),
          color: Colors.orange,
        ),
        _card(
          icon: Icons.fast_forward_rounded,
          title: 'Pulang Cepat Biasa',
          subtitle: 'Pulang lebih awal (otomatis disetujui)',
          onTap: () => _navigateToPresensi(context, 'Pulang Cepat'),
          color: Colors.blue,
        ),
        _card(
          icon: Icons.block_rounded,
          title: 'Izin Tidak Masuk',
          subtitle: 'Ajukan izin (perlu persetujuan admin)',
          onTap: () => _navigateToPresensi(context, 'Izin'),
          color: Colors.red,
        ),
        _card(
          icon: Icons.assignment_rounded,
          title: 'Penugasan',
          subtitle: 'Ajukan penugasan khusus (perlu persetujuan admin)',
          onTap: () => _showPenugasanSheet(context), // NEW: Show sub-options
          color: Colors.purple,
        ),
        _card(
          icon: Icons.history_rounded,
          title: 'Riwayat Presensi',
          subtitle: 'Lihat riwayat presensi kamu',
          onTap: () {
            Navigator.pushNamed(context, '/history', arguments: widget.user);
          },
          color: Colors.indigo,
        ),
      ],
    );
  }

  // NEW: Navigate helper
  void _navigateToPresensi(BuildContext context, String jenis) {
    Navigator.pushNamed(
      context,
      '/presensi',
      arguments: {'user': widget.user, 'jenis': jenis},
    );
  }

  // NEW: Bottom sheet for Penugasan sub-options
  void _showPenugasanSheet(BuildContext context) {
    showModalBottomSheet(
      context: context,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Pilih Jenis Penugasan',
              style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 20),
            _subCard(
              icon: Icons.login_rounded,
              title: 'Absen Masuk Penugasan',
              onTap: () {
                Navigator.pop(ctx);
                _navigateToPresensi(ctx, 'Penugasan_Masuk');
              },
              color: Colors.green,
            ),
            _subCard(
              icon: Icons.logout_rounded,
              title: 'Absen Pulang Penugasan',
              onTap: () {
                Navigator.pop(ctx);
                _navigateToPresensi(ctx, 'Penugasan_Pulang');
              },
              color: Colors.orange,
            ),
            _subCard(
              icon: Icons.assignment_turned_in_rounded,
              title: 'Penugasan Full Day',
              onTap: () {
                Navigator.pop(ctx);
                _navigateToPresensi(ctx, 'Penugasan_Full');
              },
              color: Colors.purple,
            ),
          ],
        ),
      ),
    );
  }

  Widget _subCard({
    required IconData icon,
    required String title,
    required VoidCallback onTap,
    required Color color,
  }) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: color.withOpacity(0.1),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(icon, color: color, size: 32),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Text(title, style: const TextStyle(fontSize: 18)),
                ),
                Icon(
                  Icons.arrow_forward_ios_rounded,
                  size: 20,
                  color: Colors.grey[600],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildAdminSection(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _card(
          icon: Icons.list_alt_rounded,
          title: 'Kelola User Presensi',
          subtitle: 'Lihat list user, histori per user, dan konfirmasi absensi',
          onTap: () {
            Navigator.pushNamed(context, '/admin-user-list');
          },
          color: Colors.blue,
        ),
        _card(
          icon: Icons.verified_user_rounded,
          title: 'Konfirmasi Absensi',
          subtitle: 'Setujui / tolak presensi user secara global',
          onTap: () {
            Navigator.pushNamed(context, '/admin-presensi');
          },
          color: Colors.green,
        ),
        _card(
          icon: Icons.table_chart_rounded,
          title: 'Rekap Absensi',
          subtitle: 'Lihat rekap presensi semua user',
          onTap: () {
            Navigator.pushNamed(context, '/rekap');
          },
          color: Colors.indigo,
        ),
      ],
    );
  }

  Widget _buildSuperAdminSection(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        _card(
          icon: Icons.supervisor_account_rounded,
          title: 'Kelola User & Admin',
          subtitle: 'CRUD akun user dan admin, edit info, ganti password',
          onTap: () {
            Navigator.pushNamed(context, '/user-management');
          },
          color: Colors.purple,
        ),
        _card(
          icon: Icons.list_alt_rounded,
          title: 'Kelola User Presensi',
          subtitle: 'Lihat list user, histori per user, dan konfirmasi absensi',
          onTap: () {
            Navigator.pushNamed(context, '/admin-user-list');
          },
          color: Colors.blue,
        ),
        _card(
          icon: Icons.verified_user_rounded,
          title: 'Konfirmasi Absensi',
          subtitle: 'Setujui / tolak presensi user secara global',
          onTap: () {
            Navigator.pushNamed(context, '/admin-presensi');
          },
          color: Colors.green,
        ),
        _card(
          icon: Icons.table_chart_rounded,
          title: 'Rekap Absensi',
          subtitle: 'Lihat rekap presensi semua user',
          onTap: () {
            Navigator.pushNamed(context, '/rekap');
          },
          color: Colors.indigo,
        ),
      ],
    );
  }
}

```

```dart
// pages/history_page.dart
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart'; // NEW: For launching PDF dokumen
import '../api/api_service.dart';
import '../models/user_model.dart';

class HistoryPage extends StatefulWidget {
  final UserModel user;
  const HistoryPage({super.key, required this.user});

  @override
  State<HistoryPage> createState() => _HistoryPageState();
}

class _HistoryPageState extends State<HistoryPage> {
  bool _loading = false;
  List<dynamic> _items = [];
  String _filterJenis =
      'All'; // All, Masuk, Pulang, Izin, Pulang Cepat, Penugasan_*

  @override
  void initState() {
    super.initState();
    _loadHistory();
  }

  Future<void> _loadHistory() async {
    setState(() => _loading = true);
    try {
      final data = await ApiService.getUserHistory(widget.user.id);
      setState(() {
        _items = data ?? [];
        _items.sort(
          (a, b) =>
              DateTime.parse(
                b['created_at'] ?? DateTime.now().toIso8601String(),
              ).compareTo(
                DateTime.parse(
                  a['created_at'] ?? DateTime.now().toIso8601String(),
                ),
              ),
        );
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              'Gagal ambil histori: $e',
              style: const TextStyle(fontSize: 18),
            ),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  List<dynamic> get _filteredItems {
    if (_filterJenis == 'All') return _items;
    return _items
        .where((item) => (item['jenis'] ?? '') == _filterJenis)
        .toList();
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          'Riwayat Presensi',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        actions: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 8),
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.2),
              borderRadius: BorderRadius.circular(20),
            ),
            child: DropdownButton<String>(
              value: _filterJenis,
              style: const TextStyle(color: Colors.white, fontSize: 16),
              underline: const SizedBox(),
              dropdownColor: cs.primary, // Dark background for dropdown menu
              items:
                  [
                        'All',
                        'Masuk',
                        'Pulang',
                        'Izin',
                        'Pulang Cepat',
                        'Penugasan_Masuk',
                        'Penugasan_Pulang',
                        'Penugasan_Full',
                      ]
                      .map(
                        (j) => DropdownMenuItem(
                          value: j,
                          child: Text(
                            j,
                            style: const TextStyle(
                              color: Colors.white,
                            ), // White text for visibility
                          ),
                        ),
                      )
                      .toList(),
              onChanged: (v) => setState(() => _filterJenis = v ?? 'All'),
            ),
          ),
          const SizedBox(width: 8),
        ],
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                cs.primary.withOpacity(0.9),
                cs.primary.withOpacity(0.6),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.05), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: _loading
            ? const Center(
                child: CircularProgressIndicator(
                  strokeWidth: 4,
                  color: Colors.blue,
                ),
              )
            : RefreshIndicator(
                onRefresh: _loadHistory,
                child: _buildContentList(),
              ),
      ),
    );
  }

  /// List utama di dalam RefreshIndicator
  Widget _buildContentList() {
    if (_filteredItems.isEmpty) {
      // Tetap pakai ListView supaya pull-to-refresh tetap bisa
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: EdgeInsets.fromLTRB(
          16,
          MediaQuery.of(context).padding.top + 100,
          16,
          20,
        ),
        children: [
          Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: Text(
              'Total: 0',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 20),
            ),
          ),
          _buildEmptyView(),
        ],
      );
    }

    return ListView.builder(
      padding: EdgeInsets.fromLTRB(
        16,
        MediaQuery.of(context).padding.top + 100,
        16,
        20,
      ),
      itemCount: _filteredItems.length + 1, // +1 buat header "Total"
      itemBuilder: (ctx, index) {
        if (index == 0) {
          // Header total di paling atas
          return Padding(
            padding: const EdgeInsets.only(bottom: 16),
            child: Card(
              elevation: 2,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  'Total: ${_filteredItems.length}',
                  style: const TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 20,
                  ),
                ),
              ),
            ),
          );
        }

        final item = _filteredItems[index - 1];

        final status = (item['status'] ?? 'Pending').toString();
        Color statusColor = Colors.orange;
        if (status == 'Disetujui') statusColor = Colors.green;
        if (status == 'Ditolak') statusColor = Colors.red;

        final created = DateTime.parse(
          item['created_at'] ?? DateTime.now().toIso8601String(),
        );
        final formattedDate = DateFormat('dd MMM yyyy HH:mm').format(created);

        final baseUrl = ApiService.baseUrl;

        final selfie = item['selfie'];
        final String? fotoUrl = (selfie != null && selfie.toString().isNotEmpty)
            ? '$baseUrl/selfie/$selfie'
            : null;

        final dokumen = item['dokumen'];
        final String? dokumenUrl =
            (dokumen != null && dokumen.toString().isNotEmpty)
            ? '$baseUrl/dokumen/$dokumen'
            : null;

        final informasi = item['informasi']?.toString() ?? '';

        return Card(
          elevation: 4,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          margin: const EdgeInsets.symmetric(vertical: 8),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: _getColorForJenis(
                          item['jenis']?.toString() ?? '',
                        ).withOpacity(0.1),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(
                        _getIconForJenis(item['jenis']?.toString() ?? ''),
                        color: _getColorForJenis(
                          item['jenis']?.toString() ?? '',
                        ),
                        size: 28,
                      ),
                    ),
                    const SizedBox(width: 16),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            item['jenis']?.toString() ?? '',
                            style: const TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 18,
                            ),
                          ),
                          Text(
                            'Tanggal: $formattedDate',
                            style: TextStyle(color: Colors.grey[600]),
                          ),
                        ],
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: 8,
                        vertical: 4,
                      ),
                      decoration: BoxDecoration(
                        color: statusColor.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            status == 'Disetujui'
                                ? Icons.check_circle
                                : status == 'Ditolak'
                                ? Icons.cancel
                                : Icons.pending,
                            color: statusColor,
                            size: 20,
                          ),
                          const SizedBox(width: 4),
                          Text(
                            status,
                            style: TextStyle(
                              color: statusColor,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Text(
                  'Keterangan: ${item['keterangan'] ?? '-'}',
                  style: TextStyle(color: Colors.grey[600]),
                ),
                if (informasi.isNotEmpty) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Info: $informasi',
                    style: TextStyle(color: Colors.grey[600]),
                  ),
                ],
                // NEW: Show location for Masuk/Pulang
                if (['Masuk', 'Pulang'].contains(item['jenis']) &&
                    item['latitude'] != null &&
                    item['longitude'] != null) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Lokasi: ${item['latitude']}, ${item['longitude']}',
                    style: TextStyle(color: Colors.grey[600], fontSize: 12),
                  ),
                ],
                const SizedBox(height: 12),
                if (dokumenUrl != null || fotoUrl != null)
                  Row(
                    children: [
                      if (dokumenUrl != null) ...[
                        GestureDetector(
                          onTap: () => _showDokumen(dokumenUrl),
                          child: Container(
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: Colors.blue[50],
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(
                                  Icons.attachment_rounded,
                                  size: 20,
                                  color: Colors.blue[600],
                                ),
                                const SizedBox(width: 4),
                                Text(
                                  'Dokumen',
                                  style: TextStyle(color: Colors.blue[600]),
                                ),
                              ],
                            ),
                          ),
                        ),
                        const SizedBox(width: 12),
                      ],
                      if (fotoUrl != null)
                        GestureDetector(
                          onTap: () => _showFullPhoto(fotoUrl),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: Image.network(
                              fotoUrl,
                              height: 60,
                              width: 60,
                              fit: BoxFit.cover,
                              loadingBuilder:
                                  (context, child, loadingProgress) {
                                    if (loadingProgress == null) return child;
                                    return Container(
                                      height: 60,
                                      width: 60,
                                      color: Colors.grey[200],
                                      child: const Center(
                                        child: CircularProgressIndicator(
                                          strokeWidth: 2,
                                        ),
                                      ),
                                    );
                                  },
                              errorBuilder: (context, error, stackTrace) =>
                                  Container(
                                    height: 60,
                                    width: 60,
                                    decoration: BoxDecoration(
                                      color: Colors.grey[200],
                                      borderRadius: BorderRadius.circular(8),
                                    ),
                                    child: const Icon(
                                      Icons.image_not_supported,
                                      size: 30,
                                      color: Colors.grey,
                                    ),
                                  ),
                            ),
                          ),
                        ),
                    ],
                  ),
              ],
            ),
          ),
        );
      },
    );
  }

  // Tampilan kalau kosong
  Widget _buildEmptyView() {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            Icons.history_toggle_off_rounded,
            size: 80,
            color: Colors.grey[400],
          ),
          const SizedBox(height: 16),
          Text(
            'Belum ada riwayat presensi',
            style: TextStyle(
              fontSize: 18,
              color: Colors.grey[600],
              fontWeight: FontWeight.w500,
            ),
          ),
        ],
      ),
    );
  }

  // Full screen photo viewer
  void _showFullPhoto(String url) {
    showDialog(
      context: context,
      builder: (context) => Dialog(
        backgroundColor: Colors.black,
        insetPadding: const EdgeInsets.all(16),
        child: Stack(
          children: [
            Center(
              child: InteractiveViewer(
                child: Image.network(url, fit: BoxFit.contain),
              ),
            ),
            Positioned(
              top: 40,
              right: 20,
              child: IconButton(
                icon: const Icon(Icons.close, color: Colors.white, size: 36),
                onPressed: () => Navigator.pop(context),
              ),
            ),
          ],
        ),
      ),
    );
  }

  // Simple dokumen viewer (UPDATED: Detect PDF and launch in browser/external)
  Future<void> _showDokumen(String url) async {
    final isPdf = url.toLowerCase().endsWith('.pdf');
    if (isPdf) {
      // Launch PDF in external app/browser
      final uri = Uri.parse(url);
      if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Tidak dapat membuka dokumen')),
          );
        }
      }
    } else {
      // Show image
      showDialog(
        context: context,
        builder: (context) => Dialog(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          child: Container(
            padding: const EdgeInsets.all(16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Text(
                  'Dokumen',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 16),
                SizedBox(
                  width: 300,
                  height: 300,
                  child: Image.network(
                    url,
                    fit: BoxFit.contain,
                    errorBuilder: (context, error, stackTrace) => const Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(
                            Icons.insert_drive_file,
                            size: 64,
                            color: Colors.grey,
                          ),
                          SizedBox(height: 16),
                          Text('Tidak dapat menampilkan dokumen'),
                        ],
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 16),
                ElevatedButton(
                  onPressed: () => Navigator.pop(context),
                  child: const Text('Tutup'),
                ),
              ],
            ),
          ),
        ),
      );
    }
  }

  IconData _getIconForJenis(String jenis) {
    return switch (jenis) {
      'Masuk' || 'Penugasan_Masuk' => Icons.login_rounded,
      'Pulang' || 'Penugasan_Pulang' => Icons.logout_rounded,
      'Izin' => Icons.block_rounded,
      'Pulang Cepat' => Icons.fast_forward_rounded,
      'Penugasan_Full' => Icons.assignment_turned_in_rounded,
      _ => Icons.schedule_rounded,
    };
  }

  Color _getColorForJenis(String jenis) {
    return switch (jenis) {
      'Masuk' || 'Penugasan_Masuk' => Colors.green,
      'Pulang' || 'Penugasan_Pulang' => Colors.orange,
      'Izin' => Colors.red,
      'Pulang Cepat' => Colors.blue,
      'Penugasan_Full' => Colors.purple,
      _ => Colors.grey,
    };
  }
}

```

```dart
// lib/pages/login_page.dart
import 'package:flutter/material.dart';
import '../api/api_service.dart';
import '../models/user_model.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final _formKey = GlobalKey<FormState>();
  final _inputC = TextEditingController();
  final _passC = TextEditingController();
  bool _loading = false;
  bool _obscure = true;

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _loading = true);
    try {
      // NEW: Init device ID before login (handled in ApiService, but ensure)
      await ApiService.initDeviceId();
      final res = await ApiService.login(
        input: _inputC.text.trim(),
        password: _passC.text.trim(),
      );
      if (res['status'] == 'success') {
        final user = UserModel.fromJson(res['data']);
        if (!mounted) return;
        // NEW: Store user locally if needed (e.g., SharedPreferences)
        // For now, just navigate
        Navigator.pushReplacementNamed(context, '/dashboard', arguments: user);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'Login gagal')),
        );
      }
    } catch (e) {
      // UPDATED: Better error for decryption/network issues
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Error: $e'),
          backgroundColor: Colors.red.shade600,
        ),
      );
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  void dispose() {
    _inputC.dispose();
    _passC.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;

    return Scaffold(
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [cs.primary, cs.primary.withOpacity(0.8)],
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Column(
                  children: [
                    const Text(
                      'Skaduta Presensi',
                      style: TextStyle(
                        fontSize: 36,
                        fontWeight: FontWeight.bold,
                        color: Colors.white,
                      ),
                    ),
                    const SizedBox(height: 8),
                    const Text(
                      'Silakan login untuk melanjutkan',
                      style: TextStyle(fontSize: 18, color: Colors.white),
                    ),
                    const SizedBox(height: 40),
                    Card(
                      elevation: 12,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(20),
                      ),
                      child: Padding(
                        padding: const EdgeInsets.all(24),
                        child: Form(
                          key: _formKey,
                          child: Column(
                            children: [
                              TextFormField(
                                controller: _inputC,
                                decoration: InputDecoration(
                                  labelText: 'Username / NIP / NISN',
                                  prefixIcon: const Icon(Icons.person_outline),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  filled: true,
                                ),
                                // UPDATED: Align with backend validation (min length, etc.)
                                validator: (v) {
                                  if (v == null || v.trim().isEmpty)
                                    return 'Wajib diisi';
                                  if (v.trim().length < 3)
                                    return 'Minimal 3 karakter';
                                  if (!RegExp(
                                    r'^[a-zA-Z0-9_]+$',
                                  ).hasMatch(v.trim())) {
                                    return 'Hanya huruf, angka, dan underscore';
                                  }
                                  return null;
                                },
                              ),
                              const SizedBox(height: 16),
                              TextFormField(
                                controller: _passC,
                                obscureText: _obscure,
                                decoration: InputDecoration(
                                  labelText: 'Password',
                                  prefixIcon: const Icon(Icons.lock_outline),
                                  suffixIcon: IconButton(
                                    icon: Icon(
                                      _obscure
                                          ? Icons.visibility_off
                                          : Icons.visibility,
                                    ),
                                    onPressed: () =>
                                        setState(() => _obscure = !_obscure),
                                  ),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  filled: true,
                                ),
                                // UPDATED: Align with backend (min 6 chars)
                                validator: (v) {
                                  if (v == null || v.isEmpty)
                                    return 'Wajib diisi';
                                  if (v.length < 6) return 'Minimal 6 karakter';
                                  return null;
                                },
                              ),
                              const SizedBox(height: 24),
                              SizedBox(
                                width: double.infinity,
                                height: 56,
                                child: ElevatedButton(
                                  onPressed: _loading ? null : _login,
                                  child: _loading
                                      ? const CircularProgressIndicator(
                                          color: Colors.white,
                                        )
                                      : const Text(
                                          'Masuk Sekarang',
                                          style: TextStyle(
                                            fontSize: 18,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                ),
                              ),
                              const SizedBox(height: 12),
                              TextButton(
                                onPressed: () =>
                                    Navigator.pushNamed(context, '/register'),
                                child: const Text(
                                  'Belum punya akun? Daftar di sini',
                                  style: TextStyle(fontSize: 16),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                    const SizedBox(height: 24),
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.white.withOpacity(0.1),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: RichText(
                        // Hapus const
                        text: const TextSpan(
                          style: TextStyle(color: Colors.white, fontSize: 16),
                          children: [
                            TextSpan(
                              text:
                                  '• Login bisa pakai Username / NIP / NISN\n',
                            ),
                            TextSpan(
                              text: '• Pastikan koneksi internet stabil',
                            ),
                          ],
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

```

```dart
// pages/presensi_page.dart

import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:image_picker/image_picker.dart';
import 'package:camera/camera.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart' as lat_lng;
import 'package:intl/intl.dart';

import '../api/api_service.dart';
import '../models/user_model.dart';

List<CameraDescription> cameras = [];

class PresensiPage extends StatefulWidget {
  final UserModel user;
  final String initialJenis;

  const PresensiPage({
    super.key,
    required this.user,
    required this.initialJenis,
  });

  @override
  State<PresensiPage> createState() => _PresensiPageState();
}

class _PresensiPageState extends State<PresensiPage>
    with TickerProviderStateMixin {
  Position? _position;
  late String _jenis;
  final TextEditingController _ketC = TextEditingController();
  final TextEditingController _infoC = TextEditingController();
  File? _selfieFile;
  File? _dokumenFile;
  bool _loading = false;
  final ImagePicker _picker = ImagePicker();

  static const double sekolahLat = -7.7771639173358516;
  static const double sekolahLng = 110.36716347232226;
  static const double maxRadius = 200;

  late AnimationController _pulseController;
  late Animation<double> _pulseAnimation;

  @override
  void initState() {
    super.initState();
    _jenis = widget.initialJenis;
    _pulseController = AnimationController(
      vsync: this,
      duration: const Duration(seconds: 2),
    );
    _pulseAnimation = Tween<double>(
      begin: 0.0,
      end: 1.0,
    ).animate(CurvedAnimation(parent: _pulseController, curve: Curves.easeOut));
    _pulseController.repeat();
    if (_isMapNeeded) _initLocation();
  }

  @override
  void dispose() {
    _pulseController.dispose();
    _ketC.dispose();
    _infoC.dispose();
    super.dispose();
  }

  bool get _isMapNeeded => _jenis == 'Masuk' || _jenis == 'Pulang';
  bool get _isPenugasan => _jenis.startsWith('Penugasan');
  bool get _isIzin => _jenis == 'Izin';
  bool get _isPulangCepat => _jenis == 'Pulang Cepat';
  bool get _wajibSelfie =>
      _jenis == 'Masuk' || _jenis == 'Pulang' || _isPulangCepat;

  // NEW: Input validation helpers (align with ApiService/backend)
  bool _validateKeterangan(String text) =>
      text.trim().length >= 5 && text.trim().length <= 500;
  bool _validateInformasi(String text) =>
      text.trim().length >= 5 && text.trim().length <= 500;

  Future<void> _initLocation() async {
    bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled)
      return _showSnack('Location service mati, nyalakan dulu ya');

    LocationPermission perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) {
      perm = await Geolocator.requestPermission();
      if (perm == LocationPermission.denied)
        return _showSnack('Izin lokasi ditolak');
    }
    if (perm == LocationPermission.deniedForever)
      return _showSnack('Izin lokasi ditolak permanen');

    try {
      final pos = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );
      if (mounted) setState(() => _position = pos);
    } catch (e) {
      _showSnack('Gagal ambil lokasi');
    }
  }

  double _distanceToSchool() {
    if (_position == null) return 999999;
    return Geolocator.distanceBetween(
      _position!.latitude,
      _position!.longitude,
      sekolahLat,
      sekolahLng,
    );
  }

  // SELFIE FULLSCREEN
  Future<void> _openCameraSelfie() async {
    if (cameras.isEmpty) cameras = await availableCameras();
    if (cameras.isEmpty) {
      _showSnack('Kamera tidak tersedia');
      return;
    }
    final result = await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => CameraSelfieScreen(initialCamera: cameras.first),
      ),
    );
    if (result is File) setState(() => _selfieFile = result);
  }

  Future<void> _pickDokumen() async {
    final doc = await _picker.pickImage(
      source: ImageSource.gallery,
      imageQuality: 80,
    );
    if (doc != null) setState(() => _dokumenFile = File(doc.path));
  }

  Future<void> _submitPresensi() async {
    // NEW: Client-side validation (complements ApiService)
    if (_isMapNeeded) {
      if (_position == null) return _showSnack('Lokasi belum terdeteksi');
      final jarak = _distanceToSchool();
      if (jarak > maxRadius)
        return _showSnack(
          'Di luar radius sekolah (±${jarak.toStringAsFixed(1)}m)',
        );
    }

    if (_wajibSelfie && _selfieFile == null)
      return _showSnack('Selfie wajib diambil!');
    if ((_isIzin || _isPulangCepat) && !_validateKeterangan(_ketC.text))
      return _showSnack('Keterangan wajib dan valid (5-500 char)!');
    if (_isPenugasan) {
      if (!_validateInformasi(_infoC.text))
        return _showSnack('Informasi penugasan wajib dan valid (5-500 char)!');
      if (_dokumenFile == null)
        return _showSnack('Dokumen penugasan wajib diunggah!');
    }

    setState(() => _loading = true);
    try {
      // ApiService handles device_id, encryption, further validation
      final res = await ApiService.submitPresensi(
        userId: widget.user.id,
        jenis: _jenis,
        keterangan: _ketC.text.trim(),
        informasi: _infoC.text.trim(),
        dokumenBase64: _dokumenFile != null
            ? base64Encode(await _dokumenFile!.readAsBytes())
            : '',
        latitude: _position?.latitude.toString() ?? '0',
        longitude: _position?.longitude.toString() ?? '0',
        base64Image: _selfieFile != null
            ? base64Encode(await _selfieFile!.readAsBytes())
            : '',
      );

      // UPDATED: res is decrypted Map from ApiService
      if (res['status'] == true)
        _showSuccessDialog();
      else
        _showSnack(res['message'] ?? 'Gagal mengirim');
    } catch (e) {
      // UPDATED: Better error handling for decryption/network
      _showSnack('Error: $e');
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _showSuccessDialog() {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (_) => WillPopScope(
        onWillPop: () async => false,
        child: AlertDialog(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(30),
          ),
          backgroundColor: Colors.white,
          contentPadding: const EdgeInsets.all(30),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                padding: const EdgeInsets.all(24),
                decoration: const BoxDecoration(
                  color: Colors.green,
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.check, size: 70, color: Colors.white),
              ),
              const SizedBox(height: 24),
              const Text(
                "SUKSES!",
                style: TextStyle(
                  fontSize: 32,
                  fontWeight: FontWeight.bold,
                  color: Colors.green,
                ),
              ),
              const SizedBox(height: 12),
              Text("Presensi $_jenis berhasil!", textAlign: TextAlign.center),
              const SizedBox(height: 8),
              const Text(
                "Terima kasih!",
                style: TextStyle(
                  fontSize: 20,
                  fontWeight: FontWeight.bold,
                  color: Colors.green,
                ),
              ),
            ],
          ),
        ),
      ),
    );

    Future.delayed(const Duration(milliseconds: 2200), () {
      if (mounted)
        Navigator.of(context)
          ..pop()
          ..pop();
    });
  }

  void _showSnack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(msg, style: const TextStyle(fontWeight: FontWeight.w600)),
        backgroundColor: msg.contains('SUKSES') || msg.contains('berhasil')
            ? Colors.green[600]
            : Colors.red[600],
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        margin: const EdgeInsets.all(16),
      ),
    );
  }

  Widget _buildMap() {
    final jarak = _distanceToSchool();
    final inRadius = jarak <= maxRadius;

    return Container(
      height: 380,
      margin: const EdgeInsets.symmetric(horizontal: 6),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(32),
        boxShadow: [
          BoxShadow(
            color: Colors.black26,
            blurRadius: 20,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(32),
        child: Stack(
          children: [
            FlutterMap(
              options: MapOptions(
                initialCenter: _position != null
                    ? lat_lng.LatLng(_position!.latitude, _position!.longitude)
                    : lat_lng.LatLng(sekolahLat, sekolahLng),
                initialZoom: 17.8,
              ),
              children: [
                TileLayer(
                  urlTemplate:
                      'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
                  subdomains: const ['a', 'b', 'c'],
                ),
                AnimatedBuilder(
                  animation: _pulseAnimation,
                  builder: (_, __) => CircleLayer(
                    circles: [
                      CircleMarker(
                        point: lat_lng.LatLng(sekolahLat, sekolahLng),
                        radius: maxRadius + (_pulseAnimation.value * 35),
                        useRadiusInMeter: true,
                        color: Colors.transparent,
                        borderColor: inRadius
                            ? Colors.green.withOpacity(0.5)
                            : Colors.red.withOpacity(0.5),
                        borderStrokeWidth: 10,
                      ),
                    ],
                  ),
                ),
                CircleLayer(
                  circles: [
                    CircleMarker(
                      point: lat_lng.LatLng(sekolahLat, sekolahLng),
                      radius: maxRadius,
                      useRadiusInMeter: true,
                      color: inRadius
                          ? Colors.green.withOpacity(0.22)
                          : Colors.red.withOpacity(0.22),
                      borderColor: inRadius ? Colors.green : Colors.redAccent,
                      borderStrokeWidth: 6,
                    ),
                  ],
                ),
                MarkerLayer(
                  markers: [
                    Marker(
                      point: lat_lng.LatLng(sekolahLat, sekolahLng),
                      width: 100,
                      height: 100,
                      child: Column(
                        children: [
                          Container(
                            padding: const EdgeInsets.all(12),
                            decoration: const BoxDecoration(
                              color: Colors.white,
                              shape: BoxShape.circle,
                              boxShadow: [
                                BoxShadow(
                                  color: Colors.black38,
                                  blurRadius: 10,
                                ),
                              ],
                            ),
                            child: Icon(
                              Icons.school_rounded,
                              size: 40,
                              color: Colors.red[700],
                            ),
                          ),
                          const Text(
                            "Sekolah",
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 14,
                              color: Colors.white,
                              shadows: [
                                Shadow(color: Colors.black, blurRadius: 8),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    if (_position != null)
                      Marker(
                        point: lat_lng.LatLng(
                          _position!.latitude,
                          _position!.longitude,
                        ),
                        width: 90,
                        height: 90,
                        child: Column(
                          children: [
                            Container(
                              padding: const EdgeInsets.all(10),
                              decoration: const BoxDecoration(
                                color: Colors.white,
                                shape: BoxShape.circle,
                                boxShadow: [
                                  BoxShadow(
                                    color: Colors.black38,
                                    blurRadius: 10,
                                  ),
                                ],
                              ),
                              child: Icon(
                                Icons.my_location,
                                size: 36,
                                color: Colors.blue[700],
                              ),
                            ),
                            const Text(
                              "Kamu",
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 14,
                                color: Colors.white,
                                shadows: [
                                  Shadow(color: Colors.black, blurRadius: 8),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ],
            ),
            Positioned(
              top: 16,
              left: 16,
              right: 16,
              child: Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: inRadius
                        ? [Colors.green[600]!, Colors.green[500]!]
                        : [Colors.red[600]!, Colors.red[500]!],
                  ),
                  borderRadius: BorderRadius.circular(24),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black38,
                      blurRadius: 12,
                      offset: const Offset(0, 6),
                    ),
                  ],
                ),
                child: Row(
                  children: [
                    Icon(
                      inRadius
                          ? Icons.check_circle
                          : Icons.warning_amber_rounded,
                      color: Colors.white,
                      size: 38,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            inRadius ? "Di Dalam Area!" : "Di Luar Area",
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 18,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          Text(
                            "${jarak.toStringAsFixed(1)} m dari sekolah",
                            style: const TextStyle(
                              color: Colors.white70,
                              fontSize: 15,
                            ),
                          ),
                        ],
                      ),
                    ),
                    if (inRadius)
                      const Text(
                        "SIAP!",
                        style: TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.bold,
                          fontSize: 18,
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: Text(
          _jenis.replaceAll('_', ' '),
          style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 22),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                cs.primary.withOpacity(0.95),
                cs.primary.withOpacity(0.7),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.06), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: SafeArea(
          top: false,
          child: Stack(
            children: [
              // KONTEN SCROLLABLE
              SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(20, 110, 20, 120),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      _jenis == 'Masuk'
                          ? 'Selamat Datang!'
                          : _jenis == 'Pulang'
                          ? 'Selamat Pulang!'
                          : 'Presensi $_jenis',
                      style: const TextStyle(
                        fontSize: 28,
                        fontWeight: FontWeight.bold,
                      ),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 8),
                    Text(
                      _isMapNeeded
                          ? 'Pastikan kamu berada di area sekolah'
                          : 'Lengkapi data di bawah ini',
                      style: TextStyle(color: Colors.grey[700], fontSize: 15),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 30),

                    if (_isMapNeeded) ...[
                      _buildMap(),
                      const SizedBox(height: 30),
                    ],

                    if (_isIzin || _isPulangCepat)
                      _buildTextField(
                        _ketC,
                        'Keterangan / Alasan',
                        'Contoh: Sakit, ada keperluan...',
                        Icons.note_alt_rounded,
                        cs,
                      ),
                    if (_isIzin || _isPulangCepat) const SizedBox(height: 20),

                    if (_isPenugasan)
                      _buildTextField(
                        _infoC,
                        'Informasi Penugasan',
                        'Jelaskan tugas yang diberikan',
                        Icons.assignment_rounded,
                        cs,
                        maxLines: 5,
                      ),
                    if (_isPenugasan) const SizedBox(height: 20),

                    if (_wajibSelfie)
                      Container(
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(24),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withOpacity(0.07),
                              blurRadius: 16,
                              offset: const Offset(0, 6),
                            ),
                          ],
                        ),
                        child: Column(
                          children: [
                            ListTile(
                              leading: Icon(
                                Icons.camera_alt_rounded,
                                color: Colors.red[600],
                                size: 32,
                              ),
                              title: const Text(
                                'Ambil Selfie (Wajib)',
                                style: TextStyle(
                                  fontWeight: FontWeight.w600,
                                  color: Colors.red,
                                  fontSize: 16,
                                ),
                              ),
                              trailing: const Icon(Icons.chevron_right),
                              onTap: _openCameraSelfie,
                            ),
                            if (_selfieFile != null)
                              Padding(
                                padding: const EdgeInsets.all(16),
                                child: ClipRRect(
                                  borderRadius: BorderRadius.circular(20),
                                  child: Image.file(
                                    _selfieFile!,
                                    height: 240,
                                    width: double.infinity,
                                    fit: BoxFit.cover,
                                  ),
                                ),
                              ),
                          ],
                        ),
                      ),
                    if (_wajibSelfie) const SizedBox(height: 20),

                    if (_isIzin ||
                        _isPenugasan) // UPDATED: For Izin, dokumen optional; for Penugasan required
                      Container(
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(24),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withOpacity(0.07),
                              blurRadius: 16,
                              offset: const Offset(0, 6),
                            ),
                          ],
                        ),
                        child: Column(
                          children: [
                            ListTile(
                              leading: Icon(
                                Icons.file_present_rounded,
                                color: _isPenugasan
                                    ? Colors.red[600]
                                    : Colors.grey[600],
                                size: 32,
                              ),
                              title: Text(
                                _isIzin
                                    ? 'Unggah Bukti Izin (Opsional)'
                                    : 'Unggah Dokumen Tugas (Wajib)',
                                style: TextStyle(
                                  fontWeight: FontWeight.w600,
                                  color: _isPenugasan
                                      ? Colors.red
                                      : Colors.grey[700],
                                  fontSize: 16,
                                ),
                              ),
                              trailing: const Icon(Icons.chevron_right),
                              onTap: _pickDokumen,
                            ),
                            if (_dokumenFile != null)
                              Padding(
                                padding: const EdgeInsets.all(16),
                                child: ClipRRect(
                                  borderRadius: BorderRadius.circular(20),
                                  child: Image.file(
                                    _dokumenFile!,
                                    height: 240,
                                    width: double.infinity,
                                    fit: BoxFit.cover,
                                  ),
                                ),
                              ),
                          ],
                        ),
                      ),

                    const SizedBox(height: 100),
                  ],
                ),
              ),

              // TOMBOL KIRIM SELALU KELIHATAN
              Positioned(
                left: 20,
                right: 20,
                bottom: 20,
                child: Container(
                  height: 68,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(34),
                    boxShadow: [
                      BoxShadow(
                        color: cs.primary.withOpacity(0.4),
                        blurRadius: 20,
                        offset: const Offset(0, 8),
                      ),
                    ],
                  ),
                  child: ElevatedButton.icon(
                    onPressed: _loading ? null : _submitPresensi,
                    icon: _loading
                        ? const SizedBox(
                            width: 28,
                            height: 28,
                            child: CircularProgressIndicator(
                              color: Colors.white,
                              strokeWidth: 3,
                            ),
                          )
                        : const Icon(Icons.send_rounded, size: 32),
                    label: Text(
                      _loading ? 'Mengirim Presensi...' : 'Kirim Presensi',
                      style: const TextStyle(
                        fontSize: 21,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: cs.primary,
                      foregroundColor: Colors.white,
                      elevation: 0,
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(34),
                      ),
                      padding: const EdgeInsets.symmetric(vertical: 18),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildTextField(
    TextEditingController c,
    String label,
    String hint,
    IconData icon,
    ColorScheme cs, {
    int maxLines = 3,
  }) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(24),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.07),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: TextField(
        controller: c,
        maxLines: maxLines,
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          prefixIcon: Icon(icon, color: cs.primary, size: 28),
          border: InputBorder.none,
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 20,
            vertical: 20,
          ),
        ),
      ),
    );
  }
}

// HALAMAN KAMERA SELFIE
class CameraSelfieScreen extends StatefulWidget {
  final CameraDescription initialCamera;
  const CameraSelfieScreen({super.key, required this.initialCamera});

  @override
  State<CameraSelfieScreen> createState() => _CameraSelfieScreenState();
}

class _CameraSelfieScreenState extends State<CameraSelfieScreen> {
  late CameraController _controller;
  late Future<void> _initializeControllerFuture;
  bool _isRearCamera = false;

  @override
  void initState() {
    super.initState();
    _controller = CameraController(
      _isRearCamera ? cameras.last : cameras.first,
      ResolutionPreset.high,
    );
    _initializeControllerFuture = _controller.initialize();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _switchCamera() async {
    _isRearCamera = !_isRearCamera;
    _controller = CameraController(
      _isRearCamera ? cameras.last : cameras.first,
      ResolutionPreset.high,
    );
    await _controller.initialize();
    setState(() {});
  }

  Future<void> _takePicture() async {
    try {
      await _initializeControllerFuture;
      final image = await _controller.takePicture();
      Navigator.pop(context, File(image.path));
    } catch (e) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Gagal mengambil foto')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: FutureBuilder<void>(
        future: _initializeControllerFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.done) {
            return Stack(
              children: [
                Center(child: CameraPreview(_controller)),
                Align(
                  alignment: Alignment.bottomCenter,
                  child: Container(
                    padding: const EdgeInsets.all(30),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                      children: [
                        IconButton(
                          icon: const Icon(
                            Icons.flip_camera_ios,
                            size: 40,
                            color: Colors.white,
                          ),
                          onPressed: _switchCamera,
                        ),
                        GestureDetector(
                          onTap: _takePicture,
                          child: Container(
                            width: 80,
                            height: 80,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              border: Border.all(color: Colors.white, width: 6),
                              color: Colors.white.withOpacity(0.3),
                            ),
                            child: const Icon(
                              Icons.camera,
                              size: 50,
                              color: Colors.white,
                            ),
                          ),
                        ),
                        IconButton(
                          icon: const Icon(
                            Icons.close,
                            size: 40,
                            color: Colors.white,
                          ),
                          onPressed: () => Navigator.pop(context),
                        ),
                      ],
                    ),
                  ),
                ),
                const SafeArea(
                  child: Align(
                    alignment: Alignment.topCenter,
                    child: Text(
                      'Ambil Selfie',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 20,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ),
              ],
            );
          } else {
            return const Center(
              child: CircularProgressIndicator(color: Colors.white),
            );
          }
        },
      ),
    );
  }
}

```

```dart
// pages/register_page.dart

import 'package:flutter/material.dart';
import '../api/api_service.dart';

class RegisterPage extends StatefulWidget {
  const RegisterPage({super.key});
  @override
  State<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends State<RegisterPage> {
  final _formKey = GlobalKey<FormState>();
  final _usernameC = TextEditingController();
  final _namaC = TextEditingController();
  final _nipNisnC = TextEditingController();
  final _passwordC = TextEditingController();

  String _role = 'user';
  bool _isKaryawan = false;
  bool _isLoading = false;
  bool _obscure = true;

  // NEW: Validation helpers (match backend/ApiService)
  bool _validateUsername(String input) =>
      RegExp(r'^[a-zA-Z0-9_]{3,50}$').hasMatch(input);
  bool _validateNama(String input) =>
      RegExp(r'^[a-zA-Z\s]{2,100}$').hasMatch(input);
  bool _validateNipNisn(String input) =>
      RegExp(r'^[0-9]{5,20}$').hasMatch(input);
  bool _validatePassword(String input) =>
      input.length >= 6 && input.length <= 255;

  Future<void> _handleRegister() async {
    // NEW: Client-side validation before API call
    if (!_validateUsername(_usernameC.text.trim())) {
      return _showSnack('Username: 3-50 char, huruf/angka/underscore saja');
    }
    if (!_validateNama(_namaC.text.trim())) {
      return _showSnack('Nama: 2-100 char, huruf dan spasi saja');
    }
    if (!_isKaryawan &&
        (!_validateNipNisn(_nipNisnC.text.trim()) ||
            _nipNisnC.text.trim().isEmpty)) {
      return _showSnack('NIP/NISN: 5-20 angka, wajib untuk guru');
    }
    if (!_validatePassword(_passwordC.text)) {
      return _showSnack('Password: 6-255 char minimal');
    }
    if (!['user', 'admin', 'superadmin'].contains(_role)) {
      return _showSnack('Role tidak valid');
    }

    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);
    try {
      // NEW: Init device ID before register
      await ApiService.initDeviceId();
      final res = await ApiService.register(
        username: _usernameC.text.trim(),
        namaLengkap: _namaC.text.trim(),
        nipNisn: _isKaryawan ? '' : _nipNisnC.text.trim(),
        password: _passwordC.text.trim(),
        role: _role,
        isKaryawan: _isKaryawan,
      );

      if (!mounted) return;

      // UPDATED: res is decrypted Map from ApiService
      if (res['status'] == 'success') {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Registrasi berhasil! Silakan login',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            backgroundColor: Colors.green,
          ),
        );
        Navigator.pop(context);
      } else {
        _showSnack(res['message'] ?? 'Gagal mendaftar');
      }
    } catch (e) {
      // UPDATED: Better error for decryption/network
      _showSnack('Terjadi kesalahan: $e');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _showSnack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          msg,
          style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
        ),
        backgroundColor: Colors.red.shade600,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    );
  }

  @override
  void dispose() {
    _usernameC.dispose();
    _namaC.dispose();
    _nipNisnC.dispose();
    _passwordC.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          'Daftar Akun Baru',
          style: TextStyle(fontSize: 24, fontWeight: FontWeight.bold),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [cs.primary, cs.primary.withOpacity(0.85)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
          ),
        ),
      ),
      body: Container(
        width: double.infinity,
        height: double.infinity,
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.1), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: SafeArea(
          child: SingleChildScrollView(
            padding: const EdgeInsets.fromLTRB(24, 20, 24, 40),
            child: Column(
              children: [
                // Header
                Card(
                  elevation: 12,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(24),
                  ),
                  child: Container(
                    padding: const EdgeInsets.all(32),
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        colors: [cs.primary, cs.primary.withOpacity(0.8)],
                      ),
                      borderRadius: BorderRadius.circular(24),
                    ),
                    child: const Column(
                      children: [
                        Icon(
                          Icons.school_rounded,
                          size: 80,
                          color: Colors.white,
                        ),
                        SizedBox(height: 16),
                        Text(
                          'Skaduta Presensi',
                          style: TextStyle(
                            fontSize: 32,
                            fontWeight: FontWeight.bold,
                            color: Colors.white,
                          ),
                        ),
                        Text(
                          'Sistem Absensi Digital Sekolah',
                          style: TextStyle(fontSize: 18, color: Colors.white70),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 32),

                // Form Card
                Card(
                  elevation: 10,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(24),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(28),
                    child: Form(
                      key: _formKey,
                      child: Column(
                        children: [
                          // Username
                          TextFormField(
                            controller: _usernameC,
                            textInputAction: TextInputAction.next,
                            decoration: _inputDecoration(
                              'Username',
                              Icons.person_outline,
                              cs,
                            ),
                            style: const TextStyle(fontSize: 18),
                            validator: (v) => v?.trim().isEmpty == true
                                ? 'Username wajib diisi'
                                : null,
                          ),
                          const SizedBox(height: 20),

                          // Nama Lengkap
                          TextFormField(
                            controller: _namaC,
                            textInputAction: TextInputAction.next,
                            decoration: _inputDecoration(
                              'Nama Lengkap',
                              Icons.badge_outlined,
                              cs,
                            ),
                            style: const TextStyle(fontSize: 18),
                            validator: (v) => v?.trim().isEmpty == true
                                ? 'Nama wajib diisi'
                                : null,
                          ),
                          const SizedBox(height: 20),

                          // Checkbox Karyawan
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 8,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.grey.shade50,
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(
                                color: cs.primary.withOpacity(0.3),
                              ),
                            ),
                            child: CheckboxListTile(
                              value: _isKaryawan,
                              onChanged: (val) =>
                                  setState(() => _isKaryawan = val ?? false),
                              title: const Text(
                                'Saya Karyawan / Guru',
                                style: TextStyle(
                                  fontSize: 18,
                                  fontWeight: FontWeight.w600,
                                ),
                              ),
                              subtitle: const Text(
                                'NIP/NISN tidak wajib diisi',
                                style: TextStyle(fontSize: 15),
                              ),
                              controlAffinity: ListTileControlAffinity.leading,
                              activeColor: cs.primary,
                            ),
                          ),
                          const SizedBox(height: 20),

                          // NIP/NISN (hanya muncul jika bukan karyawan)
                          if (!_isKaryawan)
                            TextFormField(
                              controller: _nipNisnC,
                              keyboardType: TextInputType.number,
                              decoration: _inputDecoration(
                                'NIP / NISN',
                                Icons.credit_card_outlined,
                                cs,
                              ),
                              style: const TextStyle(fontSize: 18),
                              validator: (_) => _isKaryawan
                                  ? null
                                  : (_nipNisnC.text.trim().isEmpty
                                        ? 'NIP/NISN wajib diisi'
                                        : null),
                            ),
                          if (!_isKaryawan) const SizedBox(height: 20),

                          // Password
                          TextFormField(
                            controller: _passwordC,
                            obscureText: _obscure,
                            decoration:
                                _inputDecoration(
                                  'Password',
                                  Icons.lock_outline,
                                  cs,
                                ).copyWith(
                                  suffixIcon: IconButton(
                                    icon: Icon(
                                      _obscure
                                          ? Icons.visibility_off
                                          : Icons.visibility,
                                      color: cs.primary,
                                    ),
                                    onPressed: () =>
                                        setState(() => _obscure = !_obscure),
                                  ),
                                ),
                            style: const TextStyle(fontSize: 18),
                            validator: (v) {
                              if (v?.isEmpty == true)
                                return 'Password wajib diisi';
                              if (v!.length < 6)
                                return 'Minimal 6 karakter'; // UPDATED: Match backend min 6
                              return null;
                            },
                          ),
                          const SizedBox(height: 24),

                          // Role Dropdown – DIPINDAH KE LUAR TextFormField BIAR GA BUG!
                          Container(
                            width: double.infinity,
                            padding: const EdgeInsets.symmetric(horizontal: 16),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(
                                color: cs.primary.withOpacity(0.4),
                              ),
                            ),
                            child: DropdownButtonHideUnderline(
                              child: DropdownButton<String>(
                                value: _role,
                                isExpanded: true,
                                icon: Icon(
                                  Icons.arrow_drop_down,
                                  color: cs.primary,
                                  size: 32,
                                ),
                                style: const TextStyle(
                                  fontSize: 18,
                                  color: Colors.black87,
                                ),
                                dropdownColor: Colors.white,
                                borderRadius: BorderRadius.circular(16),
                                items: const [
                                  DropdownMenuItem(
                                    value: 'user',
                                    child: Text('User (Siswa / Guru)'),
                                  ),
                                  DropdownMenuItem(
                                    value: 'admin',
                                    child: Text('Admin'),
                                  ),
                                  DropdownMenuItem(
                                    value: 'superadmin',
                                    child: Text('Super Admin'),
                                  ),
                                ],
                                onChanged: (val) =>
                                    setState(() => _role = val!),
                              ),
                            ),
                          ),
                          const SizedBox(height: 32),

                          // Tombol Daftar
                          SizedBox(
                            width: double.infinity,
                            height: 60,
                            child: ElevatedButton(
                              onPressed: _isLoading ? null : _handleRegister,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: cs.primary,
                                foregroundColor: Colors.white,
                                elevation: 8,
                                shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(20),
                                ),
                                shadowColor: cs.primary.withOpacity(0.5),
                              ),
                              child: _isLoading
                                  ? const SizedBox(
                                      width: 28,
                                      height: 28,
                                      child: CircularProgressIndicator(
                                        color: Colors.white,
                                        strokeWidth: 3,
                                      ),
                                    )
                                  : const Text(
                                      'DAFTAR SEKARANG',
                                      style: TextStyle(
                                        fontSize: 20,
                                        fontWeight: FontWeight.bold,
                                        letterSpacing: 1.2,
                                      ),
                                    ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  // Helper untuk Input Decoration
  InputDecoration _inputDecoration(
    String label,
    IconData icon,
    ColorScheme cs,
  ) {
    return InputDecoration(
      labelText: label,
      prefixIcon: Icon(icon, color: cs.primary, size: 28),
      filled: true,
      fillColor: Colors.grey.shade50,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(16),
        borderSide: BorderSide(color: cs.primary.withOpacity(0.3)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(16),
        borderSide: BorderSide(color: cs.primary.withOpacity(0.3)),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(16),
        borderSide: BorderSide(color: cs.primary, width: 2),
      ),
      labelStyle: TextStyle(fontSize: 18, color: cs.primary),
      contentPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 20),
    );
  }
}

```

```dart
// pages/rekap_page.dart 
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:excel/excel.dart' as xls;
import 'package:path_provider/path_provider.dart';
import 'package:permission_handler/permission_handler.dart';
import 'package:open_file/open_file.dart';
import 'package:intl/intl.dart';
import '../api/api_service.dart';

class RekapPage extends StatefulWidget {
  const RekapPage({super.key});
  @override
  State<RekapPage> createState() => _RekapPageState();
}

class _RekapPageState extends State<RekapPage> with TickerProviderStateMixin {
  bool _loading = false;
  List<dynamic> _data = [];
  String _month = DateTime.now().month.toString().padLeft(2, '0');
  String _year = DateTime.now().year.toString();

  Map<String, List<Map<String, dynamic>>> _perUser = {};
  Map<String, Map<String, String>> _pivot = {};
  List<String> _dates = [];

  late AnimationController _animController;
  late Animation<double> _fadeAnimation;
  late Animation<Offset> _slideAnimation;

  @override
  void initState() {
    super.initState();
    _animController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1000),
    );
    _fadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _animController, curve: Curves.easeInOut),
    );
    _slideAnimation =
        Tween<Offset>(begin: const Offset(0, 0.3), end: Offset.zero).animate(
          CurvedAnimation(parent: _animController, curve: Curves.easeOutBack),
        );
    _loadRekap();
  }

  @override
  void dispose() {
    _animController.dispose();
    super.dispose();
  }

  // NEW: Validation for month/year (1-12, 1900-current)
  bool _validateMonthYear(String month, String year) {
    final m = int.tryParse(month);
    final y = int.tryParse(year);
    return m != null &&
        m >= 1 &&
        m <= 12 &&
        y != null &&
        y >= 1900 &&
        y <= DateTime.now().year;
  }

  Future<void> _loadRekap() async {
    if (!_validateMonthYear(_month, _year)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Bulan/Tahun tidak valid!'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    setState(() => _loading = true);
    try {
      // UPDATED: ApiService.getRekap returns decrypted List<dynamic>
      final data = await ApiService.getRekap(month: _month, year: _year);
      if (mounted) {
        setState(() => _data = data ?? []);
        _processData();
        _animController.forward(from: 0);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Gagal load data: $e'),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _processData() {
    _perUser.clear();
    _pivot.clear();
    _dates.clear();

    for (var item in _data) {
      final nama = item['nama_lengkap'] ?? 'Tanpa Nama';
      final rawDate = item['created_at'] ?? '';
      final tgl = rawDate.length >= 10 ? rawDate.substring(0, 10) : '';
      final jenis = item['jenis'] ?? '-';
      final status = item['status'] ?? 'Pending';
      final ket = item['keterangan'] ?? '-';
      final deviceId = item['device_id'] ?? ''; // NEW: If available from data

      _perUser.putIfAbsent(nama, () => []);
      _perUser[nama]!.add({
        'tgl': tgl,
        'jenis': jenis,
        'status': status,
        'ket': ket,
        'device_id': deviceId, // NEW: For optional display
      });

      _pivot.putIfAbsent(nama, () => {});
      _pivot[nama]![tgl] = jenis;

      if (tgl.isNotEmpty && !_dates.contains(tgl)) _dates.add(tgl);
    }
    _dates.sort();
  }

  // EXPORT TO EXCEL – SUDAH SESUAI excel ^4.0.6+
  Future<void> _exportToExcel() async {
    if (_data.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Data kosong!'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    // Izin penyimpanan
    if (Platform.isAndroid) {
      var status = await Permission.manageExternalStorage.request();
      if (!status.isGranted) status = await Permission.storage.request();
      if (!status.isGranted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Izin penyimpanan ditolak!')),
        );
        return;
      }
    }

    var excel = xls.Excel.createExcel();
    xls.Sheet mainSheet = excel['Rekap Lengkap'];

    // Header untuk Rekap Lengkap
    mainSheet.appendRow([
      xls.TextCellValue("No"),
      xls.TextCellValue("Nama"),
      xls.TextCellValue("Tanggal"),
      xls.TextCellValue("Jenis"),
      xls.TextCellValue("Status"),
      xls.TextCellValue("Keterangan"),
      // NEW: Add device_id if present
      xls.TextCellValue("Device ID"),
    ]);

    // Styling Header
    xls.CellStyle headerStyle = xls.CellStyle(
      bold: true,
      // backgroundColorHex: "#1565C0",
      // fontColorHex: "#FFFFFF",
      horizontalAlign: xls.HorizontalAlign.Center,
      verticalAlign: xls.VerticalAlign.Center,
    );

    for (var i = 0; i < 7; i++) {
      // UPDATED: 7 columns now
      var cell = mainSheet.cell(
        xls.CellIndex.indexByColumnRow(columnIndex: i, rowIndex: 0),
      );
      cell.cellStyle = headerStyle;
    }

    // Isi data Rekap Lengkap
    int no = 1;
    for (var item in _data) {
      final nama = item['nama_lengkap'] ?? 'Unknown';
      final tgl = item['created_at']?.toString().substring(0, 10) ?? '-';
      final jenis = item['jenis'] ?? '-';
      final status = item['status'] ?? 'Pending';
      final ket = item['keterangan'] ?? '-';
      final deviceId = item['device_id'] ?? ''; // NEW

      mainSheet.appendRow([
        xls.TextCellValue(no.toString()),
        xls.TextCellValue(nama),
        xls.TextCellValue(tgl),
        xls.TextCellValue(jenis),
        xls.TextCellValue(status),
        xls.TextCellValue(ket),
        xls.TextCellValue(deviceId), // NEW
      ]);
      no++;
    }

    // Sheet Ringkasan Harian (Pivot)
    if (_dates.isNotEmpty && _pivot.isNotEmpty) {
      xls.Sheet pivotSheet = excel['Ringkasan Harian'];

      // Header Pivot: Nama + dates
      List<xls.CellValue?> pivotHeader = [xls.TextCellValue("Nama")];
      for (var d in _dates) {
        pivotHeader.add(xls.TextCellValue(d)); // Full date YYYY-MM-DD
      }
      pivotSheet.appendRow(pivotHeader);

      // Style header pivot
      int pivotColCount = 1 + _dates.length;
      for (var i = 0; i < pivotColCount; i++) {
        var cell = pivotSheet.cell(
          xls.CellIndex.indexByColumnRow(columnIndex: i, rowIndex: 0),
        );
        cell.cellStyle = headerStyle;
      }

      // Isi data Pivot (sorted names)
      List<String> sortedNames = _pivot.keys.toList()..sort();
      for (var nama in sortedNames) {
        List<xls.CellValue?> pivotRow = [xls.TextCellValue(nama)];
        for (var d in _dates) {
          final val = _pivot[nama]![d] ?? '-';
          pivotRow.add(xls.TextCellValue(val));
        }
        pivotSheet.appendRow(pivotRow);
      }
    }

    // Simpan ke folder Downloads
    final directory = Directory('/storage/emulated/0/Download');
    if (!await directory.exists()) await directory.create(recursive: true);

    final fileName = "Rekap_Absensi_$_month-$_year.xlsx";
    final path = "${directory.path}/$fileName";

    final fileBytes = excel.encode()!;
    await File(path).writeAsBytes(fileBytes);

    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text(
            "Berhasil disimpan di folder Downloads! (2 sheets: Lengkap & Harian)",
          ),
          backgroundColor: Colors.green.shade600,
          duration: const Duration(seconds: 6),
          action: SnackBarAction(
            label: "BUKA",
            textColor: Colors.yellow,
            onPressed: () => OpenFile.open(path),
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final screenWidth = MediaQuery.of(context).size.width;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          "Rekap Absensi",
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        actions: [
          // NEW: Month/Year selector (simple dropdowns for validation)
          Container(
            margin: const EdgeInsets.only(right: 8),
            padding: const EdgeInsets.symmetric(horizontal: 8),
            decoration: BoxDecoration(
              color: Colors.white.withOpacity(0.2),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.calendar_today, color: Colors.white, size: 20),
                const SizedBox(width: 4),
                DropdownButton<String>(
                  value: _month,
                  items: List.generate(
                    12,
                    (i) => DropdownMenuItem(
                      value: (i + 1).toString().padLeft(2, '0'),
                      child: Text((i + 1).toString().padLeft(2, '0')),
                    ),
                  ),
                  onChanged: (v) => setState(() => _month = v ?? _month),
                  style: const TextStyle(color: Colors.white),
                  underline: const SizedBox(),
                  dropdownColor: cs.primary,
                ),
                const Text('/', style: TextStyle(color: Colors.white)),
                DropdownButton<String>(
                  value: _year,
                  items: List.generate(
                    126,
                    (i) => DropdownMenuItem(
                      // 1900 to current +5
                      value: (1900 + i).toString(),
                      child: Text((1900 + i).toString()),
                    ),
                  ),
                  onChanged: (v) => setState(() => _year = v ?? _year),
                  style: const TextStyle(color: Colors.white),
                  underline: const SizedBox(),
                  dropdownColor: cs.primary,
                ),
                IconButton(
                  icon: const Icon(
                    Icons.refresh,
                    color: Colors.white,
                    size: 20,
                  ),
                  onPressed: _loadRekap,
                ),
              ],
            ),
          ),
          IconButton(
            icon: const Icon(Icons.download_rounded, size: 28),
            onPressed: _exportToExcel,
            tooltip: "Export ke Excel",
          ),
          SizedBox(width: screenWidth > 600 ? 16 : 8),
        ],
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                cs.primary.withOpacity(0.9),
                cs.primary.withOpacity(0.6),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.05), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: _loading
            ? const Center(
                child: CircularProgressIndicator(
                  strokeWidth: 4,
                  color: Colors.blue,
                ),
              )
            : _data.isEmpty
            ? Center(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(
                      Icons.event_busy,
                      size: 64,
                      color: Colors.grey.shade300,
                    ),
                    const SizedBox(height: 12),
                    Text(
                      "Belum ada data absensi",
                      style: TextStyle(
                        fontSize: 18,
                        color: Colors.grey.shade500,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],
                ),
              )
            : CustomScrollView(
                slivers: [
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: EdgeInsets.fromLTRB(
                        16,
                        MediaQuery.of(context).padding.top + 80,
                        16,
                        16,
                      ),
                      child: Column(
                        children: [
                          // Info Cards – Lebih compact
                          FadeTransition(
                            opacity: _fadeAnimation,
                            child: SlideTransition(
                              position: _slideAnimation,
                              child: _buildInfoCards(cs),
                            ),
                          ),
                          const SizedBox(height: 24),
                          // Per User Section
                          _buildSectionHeader("Detail Per User", Icons.people),
                          const SizedBox(height: 12),
                          FadeTransition(
                            opacity: _fadeAnimation,
                            child: SlideTransition(
                              position: _slideAnimation,
                              child: _buildPerUserList(),
                            ),
                          ),
                          const SizedBox(height: 32),
                          // Pivot Table Section
                          _buildSectionHeader(
                            "Ringkasan Harian",
                            Icons.calendar_view_day,
                          ),
                          const SizedBox(height: 12),
                          FadeTransition(
                            opacity: _fadeAnimation,
                            child: SlideTransition(
                              position: _slideAnimation,
                              child: _buildPivotTable(cs),
                            ),
                          ),
                          const SizedBox(height: 32),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
      ),
    );
  }

  Widget _buildInfoCards(ColorScheme cs) {
    return Card(
      elevation: 4,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(16),
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.1), cs.primary.withOpacity(0.05)],
          ),
        ),
        child: Row(
          children: [
            Expanded(
              child: _infoCard(
                _data.length.toString(),
                "Total Absen",
                Icons.bar_chart_rounded,
                cs.primary,
              ),
            ),
            const SizedBox(width: 24),
            Expanded(
              child: _infoCard(
                DateFormat(
                  'MMM yyyy',
                ).format(DateTime(int.parse(_year), int.parse(_month))),
                "Periode",
                Icons.calendar_today_rounded,
                cs.secondary,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _infoCard(String value, String title, IconData icon, Color color) {
    return Column(
      children: [
        Icon(icon, size: 32, color: color),
        const SizedBox(height: 8),
        Text(
          value,
          style: TextStyle(
            fontSize: 20,
            fontWeight: FontWeight.bold,
            color: color,
          ),
        ),
        Text(
          title,
          style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
          textAlign: TextAlign.center,
        ),
      ],
    );
  }

  Widget _buildSectionHeader(String title, IconData icon) {
    return Row(
      children: [
        Icon(icon, size: 24, color: Theme.of(context).colorScheme.primary),
        const SizedBox(width: 8),
        Text(
          title,
          style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
        ),
      ],
    );
  }

  Widget _buildPerUserList() {
    return ListView.separated(
      shrinkWrap: true,
      physics: const NeverScrollableScrollPhysics(),
      itemCount: _perUser.length,
      separatorBuilder: (ctx, i) => const SizedBox(height: 8),
      itemBuilder: (ctx, i) {
        final nama = _perUser.keys.elementAt(i);
        final items = _perUser[nama]!;
        return Card(
          elevation: 2,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          child: ExpansionTile(
            leading: CircleAvatar(
              radius: 20,
              backgroundColor: Theme.of(
                context,
              ).colorScheme.primary.withOpacity(0.1),
              child: Text(
                nama.isNotEmpty ? nama[0].toUpperCase() : '?',
                style: TextStyle(
                  color: Theme.of(context).colorScheme.primary,
                  fontWeight: FontWeight.bold,
                  fontSize: 16,
                ),
              ),
            ),
            title: Text(
              nama,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 16),
            ),
            subtitle: Text(
              "${items.length} entri",
              style: TextStyle(color: Colors.grey.shade500, fontSize: 14),
            ),
            childrenPadding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            children: items.map((e) => _buildUserItem(e)).toList(),
          ),
        );
      },
    );
  }

  Widget _buildUserItem(Map<String, dynamic> e) {
    final tglFormatted = DateFormat(
      'dd MMM yyyy',
    ).format(DateTime.parse(e['tgl'])); // UPDATED: Better date format
    final deviceId = e['device_id'] ?? ''; // NEW
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _jenisIcon(e['jenis']),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                RichText(
                  text: TextSpan(
                    style: const TextStyle(fontSize: 14, color: Colors.black87),
                    children: [
                      TextSpan(text: tglFormatted), // UPDATED
                      const TextSpan(
                        text: ' • ',
                        style: TextStyle(fontSize: 14),
                      ),
                      TextSpan(
                        text: e['jenis'],
                        style: TextStyle(fontWeight: FontWeight.w500),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  e['ket'].isNotEmpty ? e['ket'] : 'Status: ${e['status']}',
                  style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                ),
                // NEW: Show device ID if available
                if (deviceId.isNotEmpty)
                  Text(
                    'Device: ${deviceId.substring(0, 20)}${deviceId.length > 20 ? '...' : ''}',
                    style: TextStyle(fontSize: 10, color: Colors.grey.shade500),
                  ),
              ],
            ),
          ),
          _statusBadge(e['status']),
        ],
      ),
    );
  }

  Widget _jenisIcon(String jenis) {
    IconData icon = Icons.help_outline_rounded;
    Color color = Colors.grey;
    if (jenis == 'Masuk' || jenis == 'Penugasan_Masuk') {
      // UPDATED: Handle Penugasan
      icon = Icons.login_rounded;
      color = Colors.green;
    } else if (jenis == 'Pulang' || jenis == 'Penugasan_Pulang') {
      icon = Icons.logout_rounded;
      color = Colors.orange;
    } else if (jenis.contains('Izin')) {
      icon = Icons.sick_rounded;
      color = Colors.red;
    } else if (jenis.contains('Penugasan_Full')) {
      icon = Icons.assignment_turned_in_rounded;
      color = Colors.purple;
    } else if (jenis == 'Pulang Cepat') {
      icon = Icons.fast_forward_rounded;
      color = Colors.blue;
    }
    return Container(
      padding: const EdgeInsets.all(6),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        shape: BoxShape.circle,
      ),
      child: Icon(icon, size: 20, color: color),
    );
  }

  Widget _statusBadge(String status) {
    Color color = Colors.grey;
    if (status == 'Disetujui')
      color = Colors.green;
    else if (status == 'Pending')
      color = Colors.orange;
    else
      color = Colors.red;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Text(
        status,
        style: TextStyle(
          color: color,
          fontSize: 12,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }

  Widget _buildPivotTable(ColorScheme cs) {
    return Card(
      elevation: 4,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              '${_dates.length} hari dalam periode',
              style: TextStyle(fontSize: 12, color: Colors.grey.shade500),
            ),
            const SizedBox(height: 12),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: DataTable(
                headingRowHeight: 48,
                dataRowHeight: 56,
                headingRowColor: MaterialStateProperty.all(
                  cs.primary.withOpacity(0.1),
                ),
                border: TableBorder.all(color: Colors.grey.shade200, width: 1),
                columns: [
                  const DataColumn(
                    label: Padding(
                      padding: EdgeInsets.all(8),
                      child: Text(
                        'Nama',
                        style: TextStyle(
                          fontWeight: FontWeight.w600,
                          fontSize: 14,
                        ),
                      ),
                    ),
                  ),
                  ..._dates.map(
                    (d) => DataColumn(
                      label: Padding(
                        padding: const EdgeInsets.all(4),
                        child: Text(
                          DateFormat('dd MMM').format(
                            DateTime.parse(d),
                          ), // UPDATED: Better date display
                          style: const TextStyle(
                            fontWeight: FontWeight.w600,
                            fontSize: 12,
                          ),
                          textAlign: TextAlign.center,
                        ),
                      ),
                    ),
                  ),
                ],
                rows: _pivot.keys.map((nama) {
                  return DataRow(
                    cells: [
                      DataCell(
                        Padding(
                          padding: const EdgeInsets.all(8),
                          child: Text(
                            nama,
                            style: const TextStyle(fontWeight: FontWeight.w500),
                          ),
                        ),
                      ),
                      ..._dates.map((d) {
                        final val = _pivot[nama]![d] ?? '';
                        return DataCell(
                          Center(
                            child: val.isEmpty
                                ? const Icon(
                                    Icons.close,
                                    size: 16,
                                    color: Colors.grey,
                                  )
                                : Container(
                                    padding: const EdgeInsets.all(4),
                                    decoration: BoxDecoration(
                                      color: val == 'Masuk'
                                          ? Colors.green.withOpacity(0.2)
                                          : Colors.orange.withOpacity(0.2),
                                      shape: BoxShape.circle,
                                    ),
                                    child: Icon(
                                      val == 'Masuk'
                                          ? Icons.login
                                          : Icons.logout,
                                      size: 16,
                                      color: val == 'Masuk'
                                          ? Colors.green
                                          : Colors.orange,
                                    ),
                                  ),
                          ),
                        );
                      }),
                    ],
                  );
                }).toList(),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

```

```dart
// lib/pages/user_management_page.dart 

import 'package:flutter/material.dart';
import '../api/api_service.dart';

class UserManagementPage extends StatefulWidget {
  const UserManagementPage({super.key});

  @override
  State<UserManagementPage> createState() => _UserManagementPageState();
}

class _UserManagementPageState extends State<UserManagementPage> {
  bool _loading = true;
  List<dynamic> _users = [];
  List<dynamic> _filteredUsers = [];
  final TextEditingController _searchC = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadUsers();
    _searchC.addListener(_filterUsers);
  }

  @override
  void dispose() {
    _searchC.removeListener(_filterUsers);
    _searchC.dispose();
    super.dispose();
  }

  Future<void> _loadUsers() async {
    setState(() => _loading = true);
    try {
      // UPDATED: ApiService.getUsers returns decrypted List<dynamic>
      final data = await ApiService.getUsers();
      final filtered = (data as List)
          .where(
            (u) =>
                (u['role']?.toString().toLowerCase() ?? '') == 'user' ||
                (u['role']?.toString().toLowerCase() ?? '') == 'admin' ||
                (u['role']?.toString().toLowerCase() ?? '') == 'superadmin',
          )
          .toList();

      if (mounted) {
        setState(() {
          _users = filtered;
          _filteredUsers = filtered
            ..sort(
              (a, b) => (a['nama_lengkap'] ?? '').toString().compareTo(
                (b['nama_lengkap'] ?? ''),
              ),
            );
        });
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Gagal memuat user: $e'),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _filterUsers() {
    final query = _searchC.text.toLowerCase().trim();
    setState(() {
      _filteredUsers =
          query.isEmpty
                ? _users
                : _users.where((u) {
                    final nama = (u['nama_lengkap'] ?? u['nama'] ?? '')
                        .toString()
                        .toLowerCase();
                    final username = (u['username'] ?? '')
                        .toString()
                        .toLowerCase();
                    final nip = (u['nip_nisn'] ?? '')
                        .toString()
                        .toLowerCase(); // NEW: Include NIP in search
                    return nama.contains(query) ||
                        username.contains(query) ||
                        nip.contains(query);
                  }).toList()
            ..sort(
              (a, b) => (a['nama_lengkap'] ?? '').toString().compareTo(
                (b['nama_lengkap'] ?? ''),
              ),
            );
    });
  }

  Future<void> _deleteUser(String id, String role) async {
    if (role.toLowerCase() == 'superadmin') {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Tidak boleh hapus superadmin'),
          backgroundColor: Color(0xFFF44336),
        ),
      );
      return;
    }
    final confirm = await showDialog<bool>(
      context: context,
      builder: (_) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            const Icon(Icons.delete_forever_rounded, color: Color(0xFFF44336)),
            const SizedBox(width: 8),
            const Text('Hapus User'),
          ],
        ),
        content: const Text(
          'Yakin ingin menghapus user ini? Aksi ini tidak bisa dibatalkan.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Batal'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            style: FilledButton.styleFrom(
              backgroundColor: const Color(0xFFF44336),
            ),
            child: const Text('Hapus'),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    try {
      // UPDATED: ApiService.deleteUser returns decrypted Map
      final res = await ApiService.deleteUser(id);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'User dihapus'),
            backgroundColor: Colors.green.shade600,
          ),
        );
        _loadUsers();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Error: $e'),
            backgroundColor: Colors.red.shade600,
          ),
        );
      }
    }
  }

  Future<void> _editUser(Map<String, dynamic> user) async {
    final usernameC = TextEditingController(text: user['username'] ?? '');
    final namaC = TextEditingController(text: user['nama_lengkap'] ?? '');
    final passC = TextEditingController();

    // NEW: Validation in edit (similar to register)
    bool _validateEditUsername(String input) =>
        RegExp(r'^[a-zA-Z0-9_]{3,50}$').hasMatch(input);
    bool _validateEditNama(String input) =>
        RegExp(r'^[a-zA-Z\s]{2,100}$').hasMatch(input);
    bool _validateEditPassword(String input) =>
        input.isEmpty || (input.length >= 6 && input.length <= 255);

    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => StatefulBuilder(
        // NEW: Stateful for validation
        builder: (ctx, setStateModal) => Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 16,
          ),
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(20),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: Theme.of(context).colorScheme.primary,
                    borderRadius: const BorderRadius.only(
                      topLeft: Radius.circular(20),
                      topRight: Radius.circular(20),
                    ),
                  ),
                  child: Row(
                    children: [
                      const Icon(
                        Icons.edit_rounded,
                        color: Colors.white,
                        size: 28,
                      ),
                      const SizedBox(width: 12),
                      const Text(
                        'Edit User',
                        style: TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                          color: Colors.white,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),
                TextField(
                  controller: usernameC,
                  decoration: InputDecoration(
                    labelText: 'Username',
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                    prefixIcon: const Icon(Icons.person_rounded),
                  ),
                  onChanged: (v) => setStateModal(() {}), // Trigger validation
                ),
                if (!_validateEditUsername(usernameC.text)) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Username: 3-50 char, huruf/angka/underscore saja',
                    style: TextStyle(color: Colors.red.shade600, fontSize: 12),
                  ),
                ],
                const SizedBox(height: 16),
                TextField(
                  controller: namaC,
                  decoration: InputDecoration(
                    labelText: 'Nama Lengkap',
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                    prefixIcon: const Icon(Icons.account_circle_rounded),
                  ),
                  onChanged: (v) => setStateModal(() {}),
                ),
                if (!_validateEditNama(namaC.text)) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Nama: 2-100 char, huruf dan spasi saja',
                    style: TextStyle(color: Colors.red.shade600, fontSize: 12),
                  ),
                ],
                const SizedBox(height: 16),
                TextField(
                  controller: passC,
                  obscureText: true,
                  decoration: InputDecoration(
                    labelText: 'Password Baru (kosongkan jika tidak ganti)',
                    border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(12),
                    ),
                    prefixIcon: const Icon(Icons.lock_rounded),
                  ),
                  onChanged: (v) => setStateModal(() {}),
                ),
                if (!_validateEditPassword(passC.text)) ...[
                  const SizedBox(height: 4),
                  Text(
                    'Password: 6-255 char minimal (atau kosong)',
                    style: TextStyle(color: Colors.red.shade600, fontSize: 12),
                  ),
                ],
                const SizedBox(height: 24),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    OutlinedButton(
                      onPressed: () => Navigator.pop(ctx, false),
                      child: const Text('Batal'),
                    ),
                    FilledButton(
                      onPressed:
                          (_validateEditUsername(usernameC.text) &&
                              _validateEditNama(namaC.text) &&
                              _validateEditPassword(passC.text))
                          ? () async {
                              try {
                                // UPDATED: ApiService.updateUser returns decrypted Map
                                final res = await ApiService.updateUser(
                                  id: user['id'].toString(),
                                  username: usernameC.text.trim(),
                                  namaLengkap: namaC.text.trim(),
                                  password: passC.text.isEmpty
                                      ? null
                                      : passC.text.trim(),
                                );
                                Navigator.pop(ctx, res['status'] == 'success');
                                if (res['status'] == 'success' && mounted) {
                                  _loadUsers();
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    const SnackBar(
                                      content: Text('User diperbarui'),
                                      backgroundColor: Color(0xFF4CAF50),
                                    ),
                                  );
                                }
                              } catch (e) {
                                if (mounted) {
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(
                                      content: Text('Error: $e'),
                                      backgroundColor: Colors.red.shade600,
                                    ),
                                  );
                                }
                              }
                            }
                          : null,
                      child: const Text('Simpan'),
                    ),
                  ],
                ),
                const SizedBox(height: 20),
              ],
            ),
          ),
        ),
      ),
    );

    if (saved == true) {
      _loadUsers();
    }
  }

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;

    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          'Kelola User & Admin',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 20),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded, size: 28),
            onPressed: _loadUsers,
          ),
          const SizedBox(width: 8),
        ],
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                cs.primary.withOpacity(0.9),
                cs.primary.withOpacity(0.6),
              ],
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
            ),
          ),
        ),
      ),
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: [cs.primary.withOpacity(0.05), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: Column(
          children: [
            Padding(
              padding: EdgeInsets.fromLTRB(
                16,
                MediaQuery.of(context).padding.top + 100,
                16,
                16,
              ),
              child: Card(
                elevation: 2,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.all(16),
                      child: TextField(
                        controller: _searchC,
                        decoration: InputDecoration(
                          hintText: 'Cari nama, username, atau NIP/NISN...',
                          prefixIcon: Icon(
                            Icons.search_rounded,
                            color: cs.primary,
                          ),
                          suffixIcon: _searchC.text.isNotEmpty
                              ? IconButton(
                                  icon: const Icon(Icons.clear_rounded),
                                  onPressed: _searchC.clear,
                                )
                              : null,
                          border: InputBorder.none,
                          filled: true,
                          fillColor: Colors.transparent,
                        ),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                      child: Text(
                        'Total: ${_filteredUsers.length}',
                        style: const TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 18,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
            Expanded(
              child: _loading
                  ? const Center(
                      child: CircularProgressIndicator(
                        strokeWidth: 4,
                        color: Colors.blue,
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _loadUsers,
                      child: _filteredUsers.isEmpty
                          ? Center(
                              child: Column(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(
                                    Icons.people_outline_rounded,
                                    size: 80,
                                    color: Colors.grey[400],
                                  ),
                                  const SizedBox(height: 16),
                                  Text(
                                    _searchC.text.isNotEmpty
                                        ? 'Tidak ditemukan'
                                        : 'Belum ada user',
                                    style: TextStyle(
                                      fontSize: 18,
                                      color: Colors.grey[600],
                                      fontWeight: FontWeight.w500,
                                    ),
                                  ),
                                ],
                              ),
                            )
                          : ListView.builder(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 16,
                              ),
                              itemCount: _filteredUsers.length,
                              itemBuilder: (ctx, i) {
                                final u = _filteredUsers[i];
                                final role = (u['role'] ?? 'user')
                                    .toString()
                                    .toLowerCase();
                                final badgeColor = role == 'superadmin'
                                    ? Colors.red
                                    : role == 'admin'
                                    ? Colors.blue
                                    : Colors.green;
                                final label = role == 'superadmin'
                                    ? 'SUPER'
                                    : role == 'admin'
                                    ? 'ADMIN'
                                    : 'USER';
                                final nip = u['nip_nisn'] ?? '';
                                final deviceId =
                                    u['device_id'] ?? ''; // NEW: From schema

                                return Card(
                                  elevation: 4,
                                  shape: RoundedRectangleBorder(
                                    borderRadius: BorderRadius.circular(16),
                                  ),
                                  margin: const EdgeInsets.symmetric(
                                    vertical: 8,
                                  ),
                                  child: ListTile(
                                    leading: CircleAvatar(
                                      backgroundColor: badgeColor.withOpacity(
                                        0.2,
                                      ),
                                      child: Text(
                                        (u['username'] ?? '?')[0].toUpperCase(),
                                        style: TextStyle(
                                          color: badgeColor,
                                          fontWeight: FontWeight.bold,
                                        ),
                                      ),
                                    ),
                                    title: Text(
                                      u['nama_lengkap'] ?? 'Tanpa Nama',
                                      style: const TextStyle(
                                        fontWeight: FontWeight.bold,
                                        fontSize: 16,
                                      ),
                                    ),
                                    subtitle: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          u['username'] ?? '',
                                          style: TextStyle(
                                            color: Colors.grey[600],
                                          ),
                                        ),
                                        if (nip.isNotEmpty) ...[
                                          Text(
                                            'NIP/NISN: $nip',
                                            style: TextStyle(
                                              color: Colors.grey[600],
                                              fontSize: 12,
                                            ),
                                          ),
                                        ],
                                        // NEW: Show device ID if available
                                        if (deviceId.isNotEmpty) ...[
                                          Text(
                                            'Device: ${deviceId.substring(0, 20)}${deviceId.length > 20 ? '...' : ''}',
                                            style: TextStyle(
                                              color: Colors.grey[600],
                                              fontSize: 12,
                                            ),
                                          ),
                                        ],
                                        Chip(
                                          label: Text(label),
                                          backgroundColor: badgeColor
                                              .withOpacity(0.2),
                                          labelStyle: TextStyle(
                                            color: badgeColor,
                                          ),
                                        ),
                                      ],
                                    ),
                                    trailing: PopupMenuButton<String>(
                                      onSelected: (value) {
                                        if (value == 'edit') {
                                          _editUser(u);
                                        } else if (value == 'delete') {
                                          _deleteUser(u['id'].toString(), role);
                                        }
                                      },
                                      itemBuilder: (context) => [
                                        const PopupMenuItem(
                                          value: 'edit',
                                          child: Row(
                                            children: [
                                              Icon(
                                                Icons.edit_rounded,
                                                color: Colors.blue,
                                              ),
                                              SizedBox(width: 8),
                                              Text('Edit'),
                                            ],
                                          ),
                                        ),
                                        PopupMenuItem(
                                          value: 'delete',
                                          child: Row(
                                            children: [
                                              Icon(
                                                Icons.delete_rounded,
                                                color: Colors.red,
                                              ),
                                              SizedBox(width: 8),
                                              Text('Hapus'),
                                            ],
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                );
                              },
                            ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

```

Ini ada berberapa eror g tau saat aku mau register bisa tapi g bisa login invailet device id gitu padahal baru aja login dan kadang eror pada enkripsi dan dekripsi nya kamu bisa sesuwaikan h code ini sama code php nya btw code phpnya jangan fi ubah kamu cukup perbaiki bagian dart nya saja 

sekali lagi ketikan semua baris code yang di ubah kalau ada code yang g di ubah tidak usah di ketikan 