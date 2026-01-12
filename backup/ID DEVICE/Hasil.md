```dart
import 'package:flutter/material.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'dart:io' show Platform;
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
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'Login gagal')),
        );
      }
    } catch (e) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('Error: $e')));
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
                                  labelText: 'Username / NIP / NIK',
                                  prefixIcon: const Icon(Icons.person_outline),
                                  border: OutlineInputBorder(
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  filled: true,
                                ),
                                validator: (v) =>
                                    v!.trim().isEmpty ? 'Wajib diisi' : null,
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
                                validator: (v) =>
                                    v!.isEmpty ? 'Wajib diisi' : null,
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
                      child: const Text(
                        '• Login bisa pakai Username / NIP / NIK\n• Pastikan koneksi internet stabil',
                        style: TextStyle(color: Colors.white, fontSize: 16),
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
// pages/register_page.dart
// VERSI FINAL – FIX DROPDOWN BUG + TAMPILAN SUPER PREMIUM & RESPONSIVE

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

  Future<void> _handleRegister() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);
    try {
      final res = await ApiService.register(
        username: _usernameC.text.trim(),
        namaLengkap: _namaC.text.trim(),
        nipNisn: _isKaryawan ? '' : _nipNisnC.text.trim(),
        password: _passwordC.text.trim(),
        role: _role,
        isKaryawan: _isKaryawan,
      );

      if (!mounted) return;

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
                                'NIP/NIK tidak wajib diisi',
                                style: TextStyle(fontSize: 15),
                              ),
                              controlAffinity: ListTileControlAffinity.leading,
                              activeColor: cs.primary,
                            ),
                          ),
                          const SizedBox(height: 20),

                          // NIP/NIK (hanya muncul jika bukan karyawan)
                          if (!_isKaryawan)
                            TextFormField(
                              controller: _nipNisnC,
                              keyboardType: TextInputType.number,
                              decoration: _inputDecoration(
                                'NIP / NIK',
                                Icons.credit_card_outlined,
                                cs,
                              ),
                              style: const TextStyle(fontSize: 18),
                              validator: (_) => _isKaryawan
                                  ? null
                                  : (_nipNisnC.text.trim().isEmpty
                                        ? 'NIP/NIK wajib diisi'
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
                              if (v!.length < 4) return 'Minimal 4 karakter';
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
                                    child: Text('User (Karyawan / Guru)'),
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
class UserModel {
  final String id;
  final String username;
  final String namaLengkap;
  final String nipNisn;
  final String role;

  UserModel({
    required this.id,
    required this.username,
    required this.namaLengkap,
    required this.nipNisn,
    required this.role,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) {
    return UserModel(
      id: json['id'].toString(),
      username: json['username'] ?? '',
      namaLengkap: json['nama_lengkap'] ?? '',
      nipNisn: json['nip_nisn'] ?? '',
      role: json['role'] ?? 'user',
    );
  }
}
```

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'dart:io' show Platform;
import '../utils/encryption.dart';

class ApiService {
  // Ganti dengan URL ngrok kamu yang aktif
  static const String baseUrl =
      "https://nonlitigious-alene-uninfinitely.ngrok-free.dev/backendapk/";

  // API Key harus sama persis dengan yang di config.php
  static const String _apiKey = 'Skaduta2025!@#SecureAPIKey1234567890';

  /// Get device ID for binding
  static Future<String> getDeviceId() async {
    try {
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

  // ================== GET DATA (ENKRIPSI) ==================
  static Future<List<dynamic>> getUsers() async {
    final headers = await _getHeaders();
    final res = await http.get(
      Uri.parse("$baseUrl/get_users.php"),
      headers: headers,
    );
    final data = _safeDecrypt(res);
    return List<dynamic>.from(data['data'] ?? []);
  }

  static Future<List<dynamic>> getUserHistory(String userId) async {
    final headers = await _getHeaders();
    final res = await http.get(
      Uri.parse("$baseUrl/absen_history.php?user_id=$userId"),
      headers: headers,
    );
    final data = _safeDecrypt(res);
    return List<dynamic>.from(data['data'] ?? []);
  }

  static Future<List<dynamic>> getAllPresensi() async {
    final headers = await _getHeaders();
    final res = await http.get(
      Uri.parse("$baseUrl/absen_admin_list.php"),
      headers: headers,
    );
    final data = _safeDecrypt(res);
    return List<dynamic>.from(data['data'] ?? []);
  }

  static Future<List<dynamic>> getRekap({String? month, String? year}) async {
    final headers = await _getHeaders();
    var url = "$baseUrl/presensi_rekap.php";
    if (month != null && year != null) url += "?month=$month&year=$year";
    final res = await http.get(Uri.parse(url), headers: headers);
    final data = _safeDecrypt(res);
    return List<dynamic>.from(data['data'] ?? []);
  }

  // ================== LOGIN ==================
  static Future<Map<String, dynamic>> login({
    required String input,
    required String password,
  }) async {
    final deviceId = await getDeviceId();
    if (deviceId.isEmpty) {
      return {"status": false, "message": "Gagal mendapatkan ID perangkat"};
    }

    final headers = await _getHeaders(withToken: false);
    final res = await http.post(
      Uri.parse("$baseUrl/login.php"),
      headers: headers,
      body: jsonEncode({
        "username": input,
        "password": password,
        "device_id": deviceId,
      }),
    );

    final result = _safeDecrypt(res);

    // Simpan token & user info kalau login berhasil
    if (result['status'] == true && result['token'] != null) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('auth_token', result['token']);
      await prefs.setString('user_id', result['user']['id'].toString());
      await prefs.setString('user_name', result['user']['nama_lengkap']);
      await prefs.setString('user_role', result['user']['role']);
      await prefs.setString('device_id', deviceId); // Optional: store for future checks
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
    required bool isKaryawan,
  }) async {
    final deviceId = await getDeviceId();
    if (deviceId.isEmpty) {
      return {"status": "error", "message": "Gagal mendapatkan ID perangkat"};
    }

    final headers = await _getHeaders(withToken: false);
    final res = await http.post(
      Uri.parse("$baseUrl/register.php"),
      headers: headers,
      body: jsonEncode({
        "username": username,
        "nama_lengkap": namaLengkap,
        "nip_nisn": nipNisn,
        "password": password,
        "role": role,
        "is_karyawan": isKaryawan,
        "device_id": deviceId,
      }),
    );
    return _safeDecrypt(res);
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
    final res = await http.post(
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
    );
    return _safeDecrypt(res);
  }

  // ================== APPROVE PRESENSI ==================
  static Future<Map<String, dynamic>> updatePresensiStatus({
    required String id,
    required String status,
  }) async {
    final headers = await _getHeaders();
    final res = await http.post(
      Uri.parse("$baseUrl/presensi_approve.php"),
      headers: headers,
      body: jsonEncode({
        "id": id.trim(),
        "status": status, // "Disetujui" atau "Ditolak"
      }),
    );
    return _safeDecrypt(res);
  }

  // ================== DELETE USER ==================
  static Future<Map<String, dynamic>> deleteUser(String id) async {
    final headers = await _getHeaders();
    final res = await http.post(
      Uri.parse("$baseUrl/delete_user.php"),
      headers: headers,
      body: jsonEncode({"id": id}),
    );
    return _safeDecrypt(res);
  }

  // ================== UPDATE USER ==================
  static Future<Map<String, dynamic>> updateUser({
    required String id,
    required String username,
    required String namaLengkap,
    String? password,
  }) async {
    final headers = await _getHeaders();
    final body = {
      "id": id,
      "username": username,
      "nama_lengkap": namaLengkap,
      if (password != null && password.isNotEmpty) "password": password,
    };
    final res = await http.post(
      Uri.parse("$baseUrl/update_user.php"),
      headers: headers,
      body: jsonEncode(body),
    );
    return _safeDecrypt(res);
  }
}
```

```yaml
  device_info_plus: ^10.1.0
```

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

if ($username === '' || $password === '' || $device_id === '') {
    http_response_code(400);
    echo json_encode([
        "status" => false,
        "message" => "Username, password, dan ID perangkat wajib diisi"
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

// ===================== DEVICE BINDING CHECK =====================
if ($user['device_id'] !== $device_id) {
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

if (empty($username) || empty($nama) || empty($password_raw) || empty($device_id)) {
    echo json_encode(["status" => "error", "message" => "Data tidak lengkap, termasuk ID perangkat"]);
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

// ===================== DEVICE BINDING CHECK =====================
// Check if device_id already registered
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
$stmt->bind_param("ssssss", $username, $nama, $nip_nisn, $password, $role, $device_id);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Akun berhasil dibuat dan terikat ke perangkat ini"]);
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
  device_id VARCHAR(255) UNIQUE NOT NULL,  -- Binding: 1 device = 1 user
  PRIMARY KEY (id),
  UNIQUE KEY unique_username (username)
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