## code PHP
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

// ===================== DEVICE BINDING CHECK & BIND (HANYA UNTUK ROLE 'user') =====================
// Cek kalau role user dan device_id dikirim
if ($user['role'] === 'user' && $device_id !== '') {
    // Kalau udah ada device_id di DB tapi gak match
    if ($user['device_id'] !== null && $user['device_id'] !== '' && $user['device_id'] !== $device_id) {
        http_response_code(403);  // Ganti ke 403 biar beda dari 401 (auth failure)
        echo json_encode([
            "status" => false,
            "message" => "Device ID tidak valid. Akun ini terikat ke perangkat lain. Hubungi admin untuk unbind."
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    // Kalau device OK atau belum ada (kosong), bind otomatis
    if ($user['device_id'] === null || $user['device_id'] === '') {
        $update_stmt = $conn->prepare("UPDATE users SET device_id = ? WHERE id = ?");
        if ($update_stmt) {
            $update_stmt->bind_param("si", $device_id, (int)$user['id']);
            $update_stmt->execute();
            $update_stmt->close();
            error_log("Device bound: User ID {$user['id']} to device $device_id");  // Log buat debug
        }
    }
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
$status = sanitizeInput($data['status'] ?? 'Karyawan'); // Default Karyawan
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

// Validasi NIP: wajib jika status bukan Karyawan
if ($status !== 'Karyawan' && empty($nip_nisn)) {
    echo json_encode(["status" => "error", "message" => "NIP/NISN wajib diisi untuk $status"]);
    exit;
}

// Validasi status hanya boleh 3 pilihan ini
if (!in_array($status, ['Karyawan', 'Guru', 'Staff Lain'])) {
    echo json_encode(["status" => "error", "message" => "Status pegawai tidak valid"]);
    exit;
}

// Device binding logic tetap sama
$final_device_id = null;
if ($role === 'user') {
    if ($device_id !== '') {
        $check_device = $conn->prepare("SELECT id FROM users WHERE device_id = ?");
        $check_device->bind_param("s", $device_id);
        $check_device->execute();
        $check_device->store_result();
        if ($check_device->num_rows > 0) {
            echo json_encode(["status" => "error", "message" => "Perangkat ini sudah terdaftar dengan akun lain."]);
            $check_device->close();
            exit;
        }
        $check_device->close();
        $final_device_id = $device_id;
    }
}

$password = password_hash($password_raw, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (username, nama_lengkap, nip_nisn, password, role, status, device_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssss", $username, $nama, $nip_nisn, $password, $role, $status, $final_device_id);

if ($stmt->execute()) {
    $msg = "Akun berhasil dibuat sebagai $status";
    if ($role === 'user' && $device_id !== '') {
        $msg .= " dan terikat ke perangkat ini";
    }
    echo json_encode(["status" => "success", "message" => $msg]);
} else {
    echo json_encode(["status" => "error", "message" => "Gagal mendaftar"]);
}

$stmt->close();
$conn->close();
?>
```

## code PHP
```sql
DROP TABLE IF EXISTS absensi;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS login_tokens;

CREATE TABLE users (
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(255) NOT NULL,
  nama_lengkap VARCHAR(255) NOT NULL,
  nip_nik VARCHAR(255) DEFAULT NULL,  -- Optional untuk karyawan (validated di PHP/Flutter), required untuk guru; UNIQUE untuk login via NIP
  type ENUM('karyawan', 'guru') NOT NULL,  -- Membedakan jenis user (karyawan atau guru), wajib diisi saat insert
  password VARCHAR(255) NOT NULL,
  role ENUM('user','admin','superadmin') DEFAULT 'user',
  device_id VARCHAR(255) DEFAULT NULL,  -- Nullable untuk admin/superadmin; UNIQUE untuk user
  PRIMARY KEY (id),
  UNIQUE KEY unique_username (username),
  UNIQUE KEY unique_nip_nik (nip_nik),  -- UNIQUE untuk NIP/NIK agar unik dan mudah query login (multiple NULL allowed)
  UNIQUE KEY unique_device (device_id),  -- Memungkinkan multiple NULL (untuk admin)
  INDEX idx_type_nip (type, nip_nik)  -- Index opsional untuk query cepat berdasarkan type dan NIP
);

CREATE TABLE absensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  jenis ENUM('Masuk','Pulang','Izin','Pulang Cepat','Penugasan_Masuk','Penugasan_Pulang','Penugasan_Full') NOT NULL,
  waktu_absen TIME,  -- Waktu spesifik absen (e.g., '08:00'), wajib untuk Masuk/Pulang/Pulang Cepat; opsional untuk Izin/Penugasan (validasi di app)
  tanggal DATE DEFAULT (CURDATE()),  -- Tanggal absen, default hari ini untuk grouping rekap harian/bulanan
  keterangan TEXT,  -- Keterangan umum (e.g., 'sakit' untuk Izin, 'di setujui' untuk approval)
  informasi TEXT,  -- Detail Penugasan (wajib untuk Penugasan_* types, berisi jam dari surat tugas)
  dokumen VARCHAR(255),  -- Path to uploaded dokumen (wajib untuk Penugasan_* dan Izin jika ada bukti)
  selfie VARCHAR(255),  -- Path to selfie photo (opsional, untuk verifikasi)
  latitude DECIMAL(10, 8),  -- Latitude lokasi (presisi lebih baik daripada VARCHAR)
  longitude DECIMAL(11, 8), -- Longitude lokasi (presisi lebih baik daripada VARCHAR)
  status ENUM('waiting','Disetujui','Ditolak') DEFAULT 'waiting',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_user_date (user_id, tanggal),  -- Index untuk rekap cepat per user dan tanggal/bulan
  INDEX idx_jenis_status (jenis, status)  -- Index untuk filter jenis dan status (e.g., query approval)
  -- NOTE: Hapus CHECK constraint untuk kompatibilitas MySQL lama; validasi waktu_absen di PHP/Flutter
);

-- ================== sementara aja
CREATE TABLE login_tokens (
    user_id INT PRIMARY KEY,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```


## Catatan perbaruan 
- untuk informasi user ada (username,nama langkap,nip/mik (karyawan tidak perlu ini),guru/karyawa,password,device id)
- btw users bisa login dengan nik-nip dan username
- untuk username sama password itu defauld jadi menu register di hapus jadi username sama password itu admin yang memasukan 
- untuk device id otomatis masuk saat user login dengan username password awal jadi awal login di hp langsung device id nya keditek gitu masukin ke database    

## Code Dart flutter 
```dart
// lib/main.dart
import 'package:flutter/material.dart';
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
import 'api/api_service.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  runApp(const SkadutaApp());
}

class SkadutaApp extends StatefulWidget {
  const SkadutaApp({super.key});

  @override
  State<SkadutaApp> createState() => _SkadutaAppState();
}

class _SkadutaAppState extends State<SkadutaApp> {
  Widget _initialPage = const Scaffold(
    body: Center(child: CircularProgressIndicator()),
  );

  @override
  void initState() {
    super.initState();
    _checkLoginStatus();
  }

  Future<void> _checkLoginStatus() async {
    final userInfo = await ApiService.getCurrentUser();
    if (userInfo != null) {
      final user = UserModel(
        id: userInfo['id']!,
        username: '',
        namaLengkap: userInfo['nama_lengkap']!,
        nipNisn: '',
        role: userInfo['role']!,
      );
      if (mounted) {
        setState(() {
          _initialPage = DashboardPage(user: user);
        });
      }
    } else {
      if (mounted) {
        setState(() {
          _initialPage = const LoginPage();
        });
      }
    }
  }

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
        cardTheme: const CardThemeData(elevation: 6),
        appBarTheme: const AppBarTheme(
          centerTitle: true,
          backgroundColor: Colors.blueGrey,
          foregroundColor: Colors.white,
        ),
      ),
      home: _initialPage,
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
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'dart:io' show Platform, SocketException;
import '../utils/encryption.dart'; // Asumsi lo punya file ini untuk ApiEncryption.decrypt

class ApiService {
  // Ganti dengan URL ngrok kamu yang aktif
  static const String baseUrl =
      "https://nonlitigious-alene-uninfinitely.ngrok-free.dev/backendapk/";

  // API Key harus sama persis dengan yang di config.php
  static const String _apiKey = 'Skaduta2025!@#SecureAPIKey1234567890';

  /// Get device ID for binding (skip for Windows desktop)
  static Future<String> getDeviceId() async {
    try {
      if (Platform.isWindows) {
        return ''; // Skip device ID for Windows desktop
      }
      final deviceInfo = DeviceInfoPlugin();
      if (Platform.isAndroid) {
        final androidInfo = await deviceInfo.androidInfo;
        return androidInfo.id; // Unique device ID for Android
      } else if (Platform.isIOS) {
        final iosInfo = await deviceInfo.iosInfo;
        return iosInfo.identifierForVendor ?? ''; // Unique for iOS app installs
      }
      return ''; // Fallback
    } catch (e) {
      print('Error getting device ID: $e');
      return '';
    }
  }

  /// Header umum untuk semua request
  static Future<Map<String, String>> _getHeaders({
    bool withToken = true,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('auth_token');

    return {
      'Content-Type': 'application/json',
      'X-App-Key': _apiKey,
      'ngrok-skip-browser-warning': 'true', // Bypass halaman warning ngrok free
      if (withToken && token != null) 'Authorization': 'Bearer $token',
    };
  }

  /// Dekripsi aman + debug print
  static Map<String, dynamic> _safeDecrypt(http.Response response) {
    try {
      print("=== RESPONSE DEBUG ===");
      print("STATUS CODE: ${response.statusCode}");
      print("BODY LENGTH: ${response.body.length}");
      print("RAW BODY: '${response.body}'");
      print("======================");

      if (response.body.isEmpty) {
        return {"status": false, "message": "Server mengirim response kosong"};
      }

      final body = jsonDecode(response.body);
      if (body['encrypted_data'] != null) {
        final decryptedJson = ApiEncryption.decrypt(body['encrypted_data']);
        return jsonDecode(decryptedJson);
      }
      return body as Map<String, dynamic>;
    } catch (e) {
      print("GAGAL PARSE JSON: $e");
      return {"status": false, "message": "Gagal membaca respons dari server"};
    }
  }

  // ================== SAFE REQUEST WRAPPER ==================
  /// Wrapper aman untuk semua request HTTP
  /// Menangani error jaringan/offline dengan pesan ramah ke user
  static Future<Map<String, dynamic>> _safeRequest(
    Future<http.Response> Function() request,
  ) async {
    try {
      final res = await request().timeout(
        const Duration(seconds: 20),
        onTimeout: () {
          throw SocketException('Connection timed out');
        },
      );
      // Handle status code spesifik
      if (res.statusCode == 401) {
        return {
          "status": false,
          "message": "Periksa password dan username anda.",
        };
      } else if (res.statusCode == 403) {
        return {
          "status": false,
          "message": "Akses ditolak. Periksa device ID atau hubungi admin.",
        };
      } else if (res.statusCode != 200) {
        return {
          "status": false,
          "message": "Server error (${res.statusCode}). Coba lagi nanti.",
        };
      }
      return _safeDecrypt(res);
    } on SocketException catch (_) {
      return {
        "status": false,
        "message": "Kamu sedang offline. Periksa koneksi internetmu.",
      };
    } on http.ClientException catch (_) {
      return {
        "status": false,
        "message": "Tidak dapat terhubung ke server. Pastikan kamu online.",
      };
    } catch (e) {
      print("UNEXPECTED API ERROR: $e");
      return {
        "status": false,
        "message": "Terjadi kesalahan. Coba lagi nanti.",
      };
    }
  }

  // ================== GET DATA (ENKRIPSI) ==================
  static Future<List<dynamic>> getUsers() async {
    final headers = await _getHeaders();
    final result = await _safeRequest(
      () => http.get(Uri.parse("$baseUrl/get_users.php"), headers: headers),
    );
    if (result['status'] == false) return []; // Handle offline/server errors
    return List<dynamic>.from(result['data'] ?? []);
  }

  static Future<List<dynamic>> getUserHistory(String userId) async {
    final headers = await _getHeaders();
    final result = await _safeRequest(
      () => http.get(
        Uri.parse("$baseUrl/absen_history.php?user_id=$userId"),
        headers: headers,
      ),
    );
    if (result['status'] == false) return []; // Handle offline/server errors
    return List<dynamic>.from(result['data'] ?? []);
  }

  static Future<List<dynamic>> getAllPresensi() async {
    final headers = await _getHeaders();
    final result = await _safeRequest(
      () => http.get(
        Uri.parse("$baseUrl/absen_admin_list.php"),
        headers: headers,
      ),
    );
    if (result['status'] == false) return []; // Handle offline/server errors
    return List<dynamic>.from(result['data'] ?? []);
  }

  static Future<List<dynamic>> getRekap({String? month, String? year}) async {
    final headers = await _getHeaders();
    var url = "$baseUrl/presensi_rekap.php";
    if (month != null && year != null) url += "?month=$month&year=$year";
    final result = await _safeRequest(
      () => http.get(Uri.parse(url), headers: headers),
    );
    if (result['status'] == false) return []; // Handle offline/server errors
    return List<dynamic>.from(result['data'] ?? []);
  }

  // ================== LOGIN ==================
  static Future<Map<String, dynamic>> login({
    required String input,
    required String password,
  }) async {
    final deviceId = await getDeviceId();

    final headers = await _getHeaders(withToken: false);
    final result = await _safeRequest(
      () => http.post(
        Uri.parse("$baseUrl/login.php"),
        headers: headers,
        body: jsonEncode({
          "username": input,
          "password": password,
          "device_id": deviceId,
        }),
      ),
    );

    // Simpan token & user info kalau login berhasil
    if (result['status'] == true && result['token'] != null) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('auth_token', result['token']);
      await prefs.setString('user_id', result['user']['id'].toString());
      await prefs.setString('user_name', result['user']['nama_lengkap']);
      await prefs.setString('user_role', result['user']['role']);
      await prefs.setString(
        'device_id',
        deviceId,
      ); // Optional: store for future checks
    }
    return result;
  }

  // ================== LOGOUT ==================
  static Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.clear();
  }

  // ================== CEK LOGIN STATUS ==================
  static Future<bool> isLoggedIn() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('auth_token') != null;
  }

  // ================== GET USER SAAT INI ==================
  static Future<Map<String, String>?> getCurrentUser() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('auth_token');
    if (token == null) return null;
    return {
      'id': prefs.getString('user_id') ?? '',
      'nama_lengkap': prefs.getString('user_name') ?? '',
      'role': prefs.getString('user_role') ?? 'user',
    };
  }

  // ================== REGISTER ==================
  static Future<Map<String, dynamic>> register({
    required String username,
    required String namaLengkap,
    required String nipNisn,
    required String password,
    required String role,
    required String status, // <--- BARU: status pegawai
  }) async {
    final deviceId = await getDeviceId();

    final headers = await _getHeaders(withToken: false);
    final result = await _safeRequest(
      () => http.post(
        Uri.parse("$baseUrl/register.php"),
        headers: headers,
        body: jsonEncode({
          "username": username,
          "nama_lengkap": namaLengkap,
          "nip_nisn": nipNisn,
          "password": password,
          "role": role,
          "status": status, // <--- KIRIM STATUS
          "device_id": deviceId,
        }),
      ),
    );
    return result;
  }

  // ================== SUBMIT PRESENSI ==================
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
    final headers = await _getHeaders();
    final result = await _safeRequest(
      () => http.post(
        Uri.parse("$baseUrl/absen.php"),
        headers: headers,
        body: jsonEncode({
          "userId": userId,
          "jenis": jenis,
          "keterangan": keterangan,
          "informasi": informasi,
          "dokumenBase64": dokumenBase64,
          "latitude": latitude,
          "longitude": longitude,
          "base64Image": base64Image,
        }),
      ),
    );
    return result;
  }

  // ================== APPROVE PRESENSI ==================
  static Future<Map<String, dynamic>> updatePresensiStatus({
    required String id,
    required String status,
  }) async {
    final headers = await _getHeaders();
    final result = await _safeRequest(
      () => http.post(
        Uri.parse("$baseUrl/presensi_approve.php"),
        headers: headers,
        body: jsonEncode({
          "id": id.trim(),
          "status": status, // "Disetujui" atau "Ditolak"
        }),
      ),
    );
    return result;
  }

  // ================== DELETE USER ==================
  static Future<Map<String, dynamic>> deleteUser(String id) async {
    final headers = await _getHeaders();
    final result = await _safeRequest(
      () => http.post(
        Uri.parse("$baseUrl/delete_user.php"),
        headers: headers,
        body: jsonEncode({"id": id}),
      ),
    );
    return result;
  }

  // ================== UPDATE USER ==================
  static Future<Map<String, dynamic>> updateUser({
    required String id,
    required String username,
    required String namaLengkap,
    String? nipNisn,
    String? role,
    String? password,
  }) async {
    final headers = await _getHeaders();
    final body = {
      "id": id,
      "username": username,
      "nama_lengkap": namaLengkap,
      if (nipNisn != null && nipNisn.isNotEmpty) "nip_nisn": nipNisn,
      if (role != null) "role": role,
      if (password != null && password.isNotEmpty) "password": password,
    };

    final result = await _safeRequest(
      () => http.post(
        Uri.parse("$baseUrl/update_user.php"),
        headers: headers,
        body: jsonEncode(body),
      ),
    );
    return result;
  }
}


```
```dart
// lib/pages/login_page.dart (ENHANCED: Modern UI with subtle gradients, neumorphic card, hero animations, smooth input transitions, consistent styling for immersive login experience)
import 'package:flutter/material.dart';
import 'package:intl/intl.dart'; // Not used but kept for consistency
import '../api/api_service.dart';
import '../models/user_model.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({super.key});

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> with TickerProviderStateMixin {
  final _formKey = GlobalKey<FormState>();
  final _inputC = TextEditingController();
  final _passC = TextEditingController();
  bool _loading = false;
  bool _obscure = true;

  late AnimationController _fadeController;
  late Animation<double> _fadeAnimation;
  late AnimationController _slideController;
  late Animation<Offset> _slideAnimation;

  @override
  void initState() {
    super.initState();
    _fadeController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 800),
    );
    _fadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _fadeController, curve: Curves.easeInOut),
    );
    _slideController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 600),
    );
    _slideAnimation =
        Tween<Offset>(begin: const Offset(0, 0.3), end: Offset.zero).animate(
          CurvedAnimation(parent: _slideController, curve: Curves.easeOutBack),
        );
    _fadeController.forward();
    _slideController.forward();
  }

  @override
  void dispose() {
    _fadeController.dispose();
    _slideController.dispose();
    _inputC.dispose();
    _passC.dispose();
    super.dispose();
  }

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _loading = true);
    try {
      final res = await ApiService.login(
        input: _inputC.text.trim(),
        password: _passC.text.trim(),
      );
      if (res['status'] == true) {
        final userData = res['user'];
        final user = UserModel(
          id: userData['id'].toString(),
          username: userData['username'] ?? '',
          namaLengkap: userData['nama_lengkap'],
          nipNisn: '',
          role: userData['role'],
        );
        if (!mounted) return;
        Navigator.pushReplacementNamed(context, '/dashboard', arguments: user);
      } else {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(res['message'] ?? 'Login gagal'),
              backgroundColor: const Color(0xFFEF4444),
              behavior: SnackBarBehavior.floating,
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
              ),
            ),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Error: $e'),
            backgroundColor: const Color(0xFFEF4444),
            behavior: SnackBarBehavior.floating,
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(12),
            ),
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
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
            colors: [
              const Color(0xFF3B82F6),
              const Color(0xFF3B82F6).withOpacity(0.8),
              const Color(0xFF1E40AF).withOpacity(0.6),
            ],
          ),
        ),
        child: SafeArea(
          child: FadeTransition(
            opacity: _fadeAnimation,
            child: SlideTransition(
              position: _slideAnimation,
              child: Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(24),
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 420),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        // Logo/Icon
                        Hero(
                          tag: 'app_logo',
                          child: Container(
                            width: 100,
                            height: 100,
                            decoration: BoxDecoration(
                              gradient: LinearGradient(
                                colors: [
                                  Colors.white.withOpacity(0.2),
                                  Colors.white.withOpacity(0.1),
                                ],
                              ),
                              shape: BoxShape.circle,
                              boxShadow: [
                                BoxShadow(
                                  color: Colors.black.withOpacity(0.1),
                                  blurRadius: 20,
                                  offset: const Offset(0, 10),
                                ),
                              ],
                            ),
                            child: const Icon(
                              Icons.school_rounded,
                              size: 60,
                              color: Colors.white,
                            ),
                          ),
                        ),
                        const SizedBox(height: 32),
                        const Text(
                          'Skaduta Presensi',
                          style: TextStyle(
                            fontSize: 32,
                            fontWeight: FontWeight.w700,
                            color: Colors.white,
                            letterSpacing: 1.2,
                          ),
                        ),
                        const SizedBox(height: 8),
                        Text(
                          'Silakan login untuk melanjutkan',
                          style: TextStyle(
                            fontSize: 16,
                            color: Colors.white.withOpacity(0.9),
                            fontWeight: FontWeight.w500,
                          ),
                        ),
                        const SizedBox(height: 48),
                        Container(
                          decoration: BoxDecoration(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(24),
                            boxShadow: [
                              BoxShadow(
                                color: Colors.black.withOpacity(0.1),
                                blurRadius: 30,
                                offset: const Offset(0, 15),
                              ),
                            ],
                          ),
                          child: Padding(
                            padding: const EdgeInsets.all(32),
                            child: Form(
                              key: _formKey,
                              child: Column(
                                children: [
                                  // Input Field 1
                                  Container(
                                    decoration: BoxDecoration(
                                      color: Colors.grey[50],
                                      borderRadius: BorderRadius.circular(16),
                                      border: Border.all(
                                        color: Colors.grey.withOpacity(0.2),
                                        width: 1,
                                      ),
                                    ),
                                    child: TextFormField(
                                      controller: _inputC,
                                      decoration: InputDecoration(
                                        labelText: 'Username',
                                        labelStyle: TextStyle(
                                          color: const Color(0xFF6B7280),
                                        ),
                                        prefixIcon: const Icon(
                                          Icons.person_outline_rounded,
                                          color: Color(0xFF6B7280),
                                        ),
                                        border: InputBorder.none,
                                        contentPadding:
                                            const EdgeInsets.symmetric(
                                              horizontal: 20,
                                              vertical: 16,
                                            ),
                                      ),
                                      style: const TextStyle(fontSize: 16),
                                      validator: (v) => v!.trim().isEmpty
                                          ? 'Wajib diisi'
                                          : null,
                                    ),
                                  ),
                                  const SizedBox(height: 20),
                                  // Input Field 2
                                  Container(
                                    decoration: BoxDecoration(
                                      color: Colors.grey[50],
                                      borderRadius: BorderRadius.circular(16),
                                      border: Border.all(
                                        color: Colors.grey.withOpacity(0.2),
                                        width: 1,
                                      ),
                                    ),
                                    child: TextFormField(
                                      controller: _passC,
                                      obscureText: _obscure,
                                      decoration: InputDecoration(
                                        labelText: 'Password',
                                        labelStyle: TextStyle(
                                          color: const Color(0xFF6B7280),
                                        ),
                                        prefixIcon: const Icon(
                                          Icons.lock_outline_rounded,
                                          color: Color(0xFF6B7280),
                                        ),
                                        suffixIcon: IconButton(
                                          icon: Icon(
                                            _obscure
                                                ? Icons.visibility_off_outlined
                                                : Icons.visibility_outlined,
                                            color: const Color(0xFF6B7280),
                                          ),
                                          onPressed: () => setState(
                                            () => _obscure = !_obscure,
                                          ),
                                        ),
                                        border: InputBorder.none,
                                        contentPadding:
                                            const EdgeInsets.symmetric(
                                              horizontal: 20,
                                              vertical: 16,
                                            ),
                                      ),
                                      style: const TextStyle(fontSize: 16),
                                      validator: (v) =>
                                          v!.isEmpty ? 'Wajib diisi' : null,
                                    ),
                                  ),
                                  const SizedBox(height: 32),
                                  // Login Button
                                  SizedBox(
                                    width: double.infinity,
                                    height: 56,
                                    child: ElevatedButton(
                                      onPressed: _loading ? null : _login,
                                      style: ElevatedButton.styleFrom(
                                        backgroundColor: const Color(
                                          0xFF3B82F6,
                                        ),
                                        foregroundColor: Colors.white,
                                        shape: RoundedRectangleBorder(
                                          borderRadius: BorderRadius.circular(
                                            16,
                                          ),
                                        ),
                                        elevation: 5,
                                        shadowColor: const Color(
                                          0xFF3B82F6,
                                        ).withOpacity(0.3),
                                      ),
                                      child: _loading
                                          ? const SizedBox(
                                              width: 24,
                                              height: 24,
                                              child: CircularProgressIndicator(
                                                color: Colors.white,
                                                strokeWidth: 2,
                                              ),
                                            )
                                          : const Text(
                                              'Masuk Sekarang',
                                              style: TextStyle(
                                                fontSize: 18,
                                                fontWeight: FontWeight.w600,
                                              ),
                                            ),
                                    ),
                                  ),
                                  const SizedBox(height: 20),
                                  // Register Link
                                  TextButton(
                                    onPressed: () => Navigator.pushNamed(
                                      context,
                                      '/register',
                                    ),
                                    child: RichText(
                                      text: TextSpan(
                                        style: TextStyle(
                                          color: const Color(0xFF3B82F6),
                                          fontSize: 16,
                                        ),
                                        children: const [
                                          TextSpan(text: 'Belum punya akun? '),
                                          TextSpan(
                                            text: 'Daftar di sini',
                                            style: TextStyle(
                                              fontWeight: FontWeight.w600,
                                            ),
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
                        const SizedBox(height: 40),
                        // Tips Container
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(20),
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              colors: [
                                Colors.white.withOpacity(0.2),
                                Colors.white.withOpacity(0.1),
                              ],
                            ),
                            borderRadius: BorderRadius.circular(16),
                            boxShadow: [
                              BoxShadow(
                                color: Colors.black.withOpacity(0.1),
                                blurRadius: 10,
                                offset: const Offset(0, 5),
                              ),
                            ],
                          ),
                          child: Column(
                            children: [
                              const Icon(
                                Icons.info_outline_rounded,
                                size: 24,
                                color: Colors.white,
                              ),
                              const SizedBox(height: 12),
                              const Text(
                                'Tips Login:\n• Gunakan Username\n• Pastikan koneksi internet stabil',
                                style: TextStyle(
                                  color: Colors.white,
                                  fontSize: 14,
                                  height: 1.4,
                                ),
                                textAlign: TextAlign.center,
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
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
// pages/register_page.dart
import 'package:flutter/material.dart';
import '../api/api_service.dart';

class RegisterPage extends StatefulWidget {
  const RegisterPage({super.key});
  @override
  State<RegisterPage> createState() => _RegisterPageState();
}

class _RegisterPageState extends State<RegisterPage>
    with TickerProviderStateMixin {
  final _formKey = GlobalKey<FormState>();
  final _usernameC = TextEditingController();
  final _namaC = TextEditingController();
  final _nipNisnC = TextEditingController();
  final _passwordC = TextEditingController();

  String _selectedStatus = 'Karyawan'; // Default: Karyawan
  bool _isLoading = false;
  bool _obscure = true;

  late AnimationController _fadeController;
  late Animation<double> _fadeAnimation;
  late AnimationController _slideController;
  late Animation<Offset> _slideAnimation;

  @override
  void initState() {
    super.initState();
    _fadeController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 800),
    );
    _fadeAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _fadeController, curve: Curves.easeInOut),
    );
    _slideController = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 600),
    );
    _slideAnimation =
        Tween<Offset>(begin: const Offset(0, 0.3), end: Offset.zero).animate(
          CurvedAnimation(parent: _slideController, curve: Curves.easeOutBack),
        );
    _fadeController.forward();
    _slideController.forward();
  }

  @override
  void dispose() {
    _fadeController.dispose();
    _slideController.dispose();
    _usernameC.dispose();
    _namaC.dispose();
    _nipNisnC.dispose();
    _passwordC.dispose();
    super.dispose();
  }

  bool get _isKaryawan => _selectedStatus == 'Karyawan';

  Future<void> _handleRegister() async {
    if (!_formKey.currentState!.validate()) return;

    // Validasi tambahan: NIP wajib jika bukan Karyawan
    if (!_isKaryawan && _nipNisnC.text.trim().isEmpty) {
      _showSnack('NIP/NISN wajib diisi untuk Guru atau Staff Lain');
      return;
    }

    setState(() => _isLoading = true);
    try {
      final res = await ApiService.register(
        username: _usernameC.text.trim(),
        namaLengkap: _namaC.text.trim(),
        nipNisn: _isKaryawan ? '' : _nipNisnC.text.trim(),
        password: _passwordC.text.trim(),
        role: 'user', // tetap user, karena register biasa hanya untuk user
        status: _selectedStatus,
      );

      if (!mounted) return;

      if (res['status'] == 'success') {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Registrasi berhasil! Silakan login',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600),
            ),
            backgroundColor: Color(0xFF10B981),
            behavior: SnackBarBehavior.floating,
          ),
        );
        Navigator.pop(context);
      } else {
        _showSnack(res['message'] ?? 'Gagal mendaftar');
      }
    } catch (e) {
      _showSnack('Terjadi kesalahan: $e');
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  void _showSnack(String msg) {
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            msg,
            style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w600),
          ),
          backgroundColor: const Color(0xFFEF4444),
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        title: const Text(
          'Daftar Akun Baru',
          style: TextStyle(fontSize: 24, fontWeight: FontWeight.w600),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                const Color(0xFF3B82F6),
                const Color(0xFF3B82F6).withOpacity(0.8),
              ],
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
            colors: [const Color(0xFF3B82F6).withOpacity(0.1), Colors.white],
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
          ),
        ),
        child: SafeArea(
          child: FadeTransition(
            opacity: _fadeAnimation,
            child: SlideTransition(
              position: _slideAnimation,
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(24, 20, 24, 40),
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 420),
                  child: Column(
                    children: [
                      Hero(
                        tag: 'register_logo',
                        child: Container(
                          width: 80,
                          height: 80,
                          decoration: BoxDecoration(
                            gradient: LinearGradient(
                              colors: [
                                Colors.white.withOpacity(0.2),
                                Colors.white.withOpacity(0.1),
                              ],
                            ),
                            shape: BoxShape.circle,
                            boxShadow: [
                              BoxShadow(
                                color: Colors.black.withOpacity(0.1),
                                blurRadius: 20,
                                offset: const Offset(0, 10),
                              ),
                            ],
                          ),
                          child: const Icon(
                            Icons.school_rounded,
                            size: 50,
                            color: Colors.white,
                          ),
                        ),
                      ),
                      const SizedBox(height: 24),
                      const Text(
                        'Skaduta Presensi',
                        style: TextStyle(
                          fontSize: 28,
                          fontWeight: FontWeight.w700,
                          color: Color(0xFF1F2937),
                          letterSpacing: 1.0,
                        ),
                      ),
                      const Text(
                        'Buat akun untuk mulai presensi',
                        style: TextStyle(
                          fontSize: 16,
                          color: Color(0xFF6B7280),
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                      const SizedBox(height: 40),

                      // Form Card
                      Container(
                        width: double.infinity,
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(24),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withOpacity(0.05),
                              blurRadius: 30,
                              offset: const Offset(0, 15),
                            ),
                          ],
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(28),
                          child: Form(
                            key: _formKey,
                            child: Column(
                              children: [
                                _buildInputField(
                                  controller: _usernameC,
                                  label: 'Username',
                                  icon: Icons.person_outline_rounded,
                                  validator: (v) => v?.trim().isEmpty == true
                                      ? 'Username wajib diisi'
                                      : null,
                                ),
                                const SizedBox(height: 20),

                                _buildInputField(
                                  controller: _namaC,
                                  label: 'Nama Lengkap',
                                  icon: Icons.account_circle_outlined,
                                  validator: (v) => v?.trim().isEmpty == true
                                      ? 'Nama wajib diisi'
                                      : null,
                                ),
                                const SizedBox(height: 20),

                                // Dropdown Status Pegawai
                                // Ganti bagian DropdownButtonFormField ini
                                Container(
                                  width: double.infinity,
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 16,
                                    vertical: 8,
                                  ),
                                  decoration: BoxDecoration(
                                    color: Colors.grey[50],
                                    borderRadius: BorderRadius.circular(16),
                                    border: Border.all(
                                      color: const Color(
                                        0xFF3B82F6,
                                      ).withOpacity(0.2),
                                      width: 1,
                                    ),
                                  ),
                                  child: DropdownButtonFormField<String>(
                                    value: _selectedStatus,
                                    decoration: const InputDecoration(
                                      labelText: 'Status Pegawai',
                                      labelStyle: TextStyle(
                                        fontSize: 18,
                                        color: Color(0xFF6B7280),
                                      ),
                                      prefixIcon: Icon(
                                        Icons.work_outline,
                                        color: Color(0xFF3B82F6),
                                      ),
                                      border: InputBorder.none,
                                      contentPadding:
                                          EdgeInsets.zero, // agar lebih rapi
                                    ),
                                    items: const [
                                      DropdownMenuItem(
                                        value: 'Karyawan',
                                        child: Text(
                                          'Karyawan',
                                          overflow: TextOverflow
                                              .visible, // penting agar bisa wrap
                                          softWrap: true,
                                        ),
                                      ),
                                      DropdownMenuItem(
                                        value: 'Guru',
                                        child: Text(
                                          'Guru (NIP wajib)',
                                          overflow: TextOverflow.visible,
                                          softWrap: true,
                                        ),
                                      ),
                                      DropdownMenuItem(
                                        value: 'Staff Lain',
                                        child: Text(
                                          'Staff Lain',
                                          overflow: TextOverflow.visible,
                                          softWrap: true,
                                        ),
                                      ),
                                    ],
                                    onChanged: (val) =>
                                        setState(() => _selectedStatus = val!),
                                    style: const TextStyle(
                                      fontSize: 18,
                                      color: Colors.black87,
                                    ),
                                    isExpanded:
                                        true, // PENTING: buat dropdown memenuhi lebar container
                                    dropdownColor: Colors.white,
                                  ),
                                ),
                                const SizedBox(height: 20),

                                // NIP/NISN – hanya muncul jika bukan Karyawan
                                if (!_isKaryawan)
                                  _buildInputField(
                                    controller: _nipNisnC,
                                    label: 'NIP / NISN (wajib)',
                                    icon: Icons.credit_card_outlined,
                                    keyboardType: TextInputType.number,
                                    validator: (v) => v?.trim().isEmpty == true
                                        ? 'NIP/NISN wajib diisi'
                                        : null,
                                  ),
                                if (!_isKaryawan) const SizedBox(height: 20),

                                // Password
                                _buildInputField(
                                  controller: _passwordC,
                                  label: 'Password',
                                  icon: Icons.lock_outline_rounded,
                                  obscureText: _obscure,
                                  validator: (v) {
                                    if (v?.isEmpty == true)
                                      return 'Password wajib diisi';
                                    if (v!.length < 6)
                                      return 'Minimal 6 karakter';
                                    return null;
                                  },
                                  suffixIcon: IconButton(
                                    icon: Icon(
                                      _obscure
                                          ? Icons.visibility_off_outlined
                                          : Icons.visibility_outlined,
                                      color: const Color(0xFF6B7280),
                                    ),
                                    onPressed: () =>
                                        setState(() => _obscure = !_obscure),
                                  ),
                                ),
                                const SizedBox(height: 32),

                                // Register Button
                                SizedBox(
                                  width: double.infinity,
                                  height: 60,
                                  child: ElevatedButton(
                                    onPressed: _isLoading
                                        ? null
                                        : _handleRegister,
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: const Color(0xFF3B82F6),
                                      foregroundColor: Colors.white,
                                      elevation: 8,
                                      shadowColor: const Color(
                                        0xFF3B82F6,
                                      ).withOpacity(0.3),
                                      shape: RoundedRectangleBorder(
                                        borderRadius: BorderRadius.circular(20),
                                      ),
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
                                              fontWeight: FontWeight.w700,
                                              letterSpacing: 1.0,
                                            ),
                                          ),
                                  ),
                                ),
                                const SizedBox(height: 20),

                                TextButton(
                                  onPressed: () => Navigator.pop(context),
                                  child: RichText(
                                    text: const TextSpan(
                                      style: TextStyle(
                                        fontSize: 16,
                                        color: Color(0xFF3B82F6),
                                      ),
                                      children: [
                                        TextSpan(text: 'Sudah punya akun? '),
                                        TextSpan(
                                          text: 'Login di sini',
                                          style: TextStyle(
                                            fontWeight: FontWeight.w600,
                                          ),
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
                      const SizedBox(height: 40),
                      // Tips
                      Container(
                        padding: const EdgeInsets.all(20),
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            colors: [
                              const Color(0xFF3B82F6).withOpacity(0.1),
                              Colors.white.withOpacity(0.8),
                            ],
                          ),
                          borderRadius: BorderRadius.circular(16),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withOpacity(0.05),
                              blurRadius: 10,
                              offset: const Offset(0, 5),
                            ),
                          ],
                        ),
                        child: const Column(
                          children: [
                            Icon(
                              Icons.info_outline_rounded,
                              size: 24,
                              color: Color(0xFF3B82F6),
                            ),
                            SizedBox(height: 12),
                            Text(
                              'Tips Registrasi:\n• Username unik & mudah diingat\n• Password minimal 6 karakter\n• Pilih status dengan benar',
                              style: TextStyle(
                                color: Color(0xFF1F2937),
                                fontSize: 14,
                                height: 1.4,
                              ),
                              textAlign: TextAlign.center,
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildInputField({
    required TextEditingController controller,
    required String label,
    required IconData icon,
    TextInputType? keyboardType,
    bool obscureText = false,
    Widget? suffixIcon,
    String? Function(String?)? validator,
  }) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.grey[50],
        borderRadius: BorderRadius.circular(16),
        border: Border.all(
          color: const Color(0xFF3B82F6).withOpacity(0.2),
          width: 1,
        ),
      ),
      child: TextFormField(
        controller: controller,
        keyboardType: keyboardType,
        obscureText: obscureText,
        decoration: InputDecoration(
          labelText: label,
          labelStyle: const TextStyle(fontSize: 18, color: Color(0xFF6B7280)),
          prefixIcon: Icon(icon, color: const Color(0xFF3B82F6)),
          suffixIcon: suffixIcon,
          border: InputBorder.none,
          contentPadding: const EdgeInsets.symmetric(
            horizontal: 20,
            vertical: 20,
          ),
        ),
        style: const TextStyle(fontSize: 18),
        validator: validator,
      ),
    );
  }
}


```
aku buth bantuan kamu unu code sql nya jangan di ubah biar seperti itu aja kamu bisa edit agar saat user awal login langsung detek id device jadi nanti pas awal login liat di atabase untuk users ini udag ada id device g kalau belum masukin id device nya untuk awal login kalau sudah ada berarti g bisa soalnya 1 akun buat 1 device jadi g bisa login di device lain 

kamu bisa langsung ketikan semua codenya untuk php saa flutternya semua codenya langsung dan kasih tau ini bagian code yang mana aja 

jadi untuk register hapus aja g usah di pakai 