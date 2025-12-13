**SELESAI 100% BRO!**  
Berikut semua file PHP kamu yang **SUDAH DI-ENKRIPSI FULL** dengan **AES-256-GCM** (paling aman & cocok buat tugas akhir).  
Semua API yang mengandung data sensitif (list absen, history, user, rekap, dll) sekarang **hanya mengirim `encrypted_data`**, dan Flutter bisa dekripsi dengan mudah.

**KUNCI RAHASIA (HARUS SAMA DI PHP & FLUTTER):**
```dart
SMKN2Yogyakarta2025PresensiApp!
```

### 1. File Baru: `encryption.php` (WAJIB ADA DI FOLDER SAMA DENGAN SEMUA API)
```php
<?php
// encryption.php
class Encryption {
    private static $secret_key = "SMKN2Yogyakarta2025PresensiApp!"; // 32 karakter = 256 bit
    private static $method = 'AES-256-GCM';

    public static function encrypt($data) {
        $key = hash('sha256', self::$secret_key, true);
        $iv = random_bytes(12);
        $tag = "";

        $encrypted = openssl_encrypt($data, self::$method, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $encrypted);
    }

    public static function decrypt($encrypted_base64) {
        $key = hash('sha256', self::$secret_key, true);
        $data = base64_decode($encrypted_base64);

        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ciphertext = substr($data, 28);

        return openssl_decrypt($ciphertext, self::$method, $key, OPENSSL_RAW_DATA, $iv, $tag);
    }
}
?>
```

### 2. `config.php` (TANPA PERUBAHAN, TAPI DITAMBAH HEADER)
```php
<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$host = "localhost";
$user = "root";
$pass = "081328nugra";
$db   = "database_smk_4";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Gagal koneksi database"]);
    exit;
}
?>
```

### SEMUA FILE PHP YANG SUDAH DI-ENKRIPSI:

#### `absen_admin_list.php`
```php
<?php
include "config.php";
include "encryption.php";

$q = $conn->query("SELECT absensi.*, users.nama_lengkap
                   FROM absensi
                   JOIN users ON users.id = absensi.user_id
                   ORDER BY absensi.id DESC");

$data = [];
while ($r = $q->fetch_assoc()) {
    unset($r['latitude'], $r['longitude']); // sembunyikan koordinat sensitif
    $data[] = $r;
}

$response = json_encode(["status" => true, "data" => $data], JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($response);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

#### `absen_history.php`
```php
<?php
include "config.php";
include "encryption.php";

$user_id = mysqli_real_escape_string($conn, $_GET['user_id'] ?? '');
if (empty($user_id)) {
    echo json_encode(["status" => false, "message" => "user_id required"]);
    exit;
}

$q = $conn->query("SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC");
$data = [];
while ($r = $q->fetch_assoc()) $data[] = $r;

$response = json_encode(["status" => true, "data" => $data], JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($response);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

#### `get_users.php`
```php
<?php
include "config.php";
include "encryption.php";

$sql = "SELECT id, username, nama_lengkap, nip_nisn, role FROM users ORDER BY id DESC";
$run = mysqli_query($conn, $sql);
$data = [];
while ($row = mysqli_fetch_assoc($run)) {
    $data[] = $row;
}

$response = json_encode(["status" => "success", "data" => $data], JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($response);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

#### `presensi_pending.php`
```php
<?php
include "config.php";
include "encryption.php";

$sql = "SELECT p.*, u.nama_lengkap
        FROM absensi p
        JOIN users u ON p.user_id = u.id
        WHERE p.status='Pending'";
$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$response = json_encode(["status" => true, "data" => $data], JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($response);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

#### `presensi_rekap.php`
```php
<?php
include "config.php";
include "encryption.php";

$sql = "SELECT p.*, u.nama_lengkap, u.username
        FROM absensi p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.created_at DESC";
$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$response = json_encode(["status" => true, "data" => $data], JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($response);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

#### `presensi_user_history.php`
```php
<?php
include "config.php";
include "encryption.php";

$user_id = mysqli_real_escape_string($conn, $_GET['user_id'] ?? '');
$sql = "SELECT * FROM absensi WHERE user_id='$user_id' ORDER BY id DESC";
$result = $conn->query($sql);
$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

$response = json_encode(["status" => true, "data" => $data], JSON_UNESCAPED_UNICODE);
$encrypted = Encryption::encrypt($response);

echo json_encode(["encrypted_data" => $encrypted]);
?>
```

### FILE YANG **TIDAK DIENKRIPSI** (karena hanya pesan singkat):
- `absen.php` → tetap plain (sudah aman)
- `absen_approve.php` → tetap plain
- `login.php` → tetap plain
- `register.php` → tetap plain
- `delete_user.php` → tetap plain
- `update_user.php` → tetap plain

**SELESAI! Tinggal upload semua file ini ke server kamu.**

Nanti kalau Flutter-nya sudah kamu kirim, aku langsung bantu bikin fungsi dekripsi otomatis di semua API call biar tinggal `getData()` → langsung jadi list tanpa ribet.

**SEKARANG TINGGAL TUNGGU CODE FLUTTER-NYA BRO!**  
Kirim aja semua file `.dart` yang handle HTTP request (misal `api_service.dart`, `absen_provider.dart`, dll), aku bikin satu fungsi dekripsi universal.

A+ sudah di tangan, tinggal sidang! Gaspol!