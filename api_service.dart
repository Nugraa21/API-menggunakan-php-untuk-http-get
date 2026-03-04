import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:android_id/android_id.dart'; 
import 'package:uuid/uuid.dart'; 
import 'dart:io' show Platform, SocketException;
import 'package:flutter/services.dart'
    show MissingPluginException, PlatformException;
import 'package:encrypt/encrypt.dart' as encrypt_pkg;

// --- Helper Encryption Class (Embedded) ---
class ApiEncryption {
  static const String _keyString = "SkadutaPresensi2025SecureKey1234"; // Must match PHP

  static String encrypt(String plainText) {
    try {
      final key = encrypt_pkg.Key.fromUtf8(_keyString);
      final iv = encrypt_pkg.IV.fromLength(16); // Random IV
      final encrypter = encrypt_pkg.Encrypter(
        encrypt_pkg.AES(key, mode: encrypt_pkg.AESMode.cbc, padding: 'PKCS7'),
      );

      final encrypted = encrypter.encrypt(plainText, iv: iv);
      // Format: IV (16 bytes) + Ciphertext
      final combined = iv.bytes + encrypted.bytes;
      return base64Encode(combined);
    } catch (e) {
      print("Encryption Error: $e");
      return "";
    }
  }

  static String decrypt(String encryptedBase64) {
    try {
      final key = encrypt_pkg.Key.fromUtf8(_keyString);
      final decoded = base64Decode(encryptedBase64);

      if (decoded.length < 16) return ""; // Invalid length

      final ivBytes = decoded.sublist(0, 16);
      final cipherBytes = decoded.sublist(16);

      final iv = encrypt_pkg.IV(ivBytes);
      final encrypter = encrypt_pkg.Encrypter(
        encrypt_pkg.AES(key, mode: encrypt_pkg.AESMode.cbc, padding: 'PKCS7'),
      );

      final encrypted = encrypt_pkg.Encrypted(cipherBytes);
      return encrypter.decrypt(encrypted, iv: iv);
    } catch (e) {
      print("Decryption Error: $e");
      return "";
    }
  }
}

class ApiService {
  // Ganti dengan URL ngrok atau production kamu
  static const String baseUrl =
      // "https://103.210.35.189:3001/";
      "http://10.10.68.208/backendapk/";

  // API Key harus sama persis dengan yang di config.php / proteksi.php
  static const String _apiKey = 'Skaduta2025!@#SecureAPIKey1234567890';

  /// Get device ID untuk binding (skip untuk Windows/desktop) - HYBRID VERSION (Improved)
  static Future<String> getDeviceId() async {
    try {
      if (Platform.isWindows) {
        return '';
      }
      if (Platform.isAndroid) {
        String? androidId;
        try {
          const androidIdPlugin = AndroidId();
          androidId = await androidIdPlugin.getId();
        } on MissingPluginException {
          androidId = null;
        } on PlatformException catch (e) {
          print('Platform error getting Android ID: $e'); 
          androidId = null;
        }

        if (androidId != null && androidId.isNotEmpty) {
          return androidId;
        }

        final prefs = await SharedPreferences.getInstance();
        String? savedId = prefs.getString('custom_device_id');
        if (savedId == null || savedId.isEmpty) {
          savedId = const Uuid().v4();
          await prefs.setString('custom_device_id', savedId);
        }
        return savedId;
      } else if (Platform.isIOS) {
        final deviceInfo = DeviceInfoPlugin();
        final iosInfo = await deviceInfo.iosInfo;
        return iosInfo.identifierForVendor ?? '';
      }
      return '';
    } catch (e) {
      print('Error getting device ID: $e');
      return const Uuid().v4();
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
      'ngrok-skip-browser-warning': 'true',
      if (withToken && token != null) 'Authorization': 'Bearer $token',
    };
  }

  // --- Core Methods for Encryption Handlers ---

  static Future<Map<String, dynamic>> _sendEncryptedRequest(
    String endpoint,
    Map<String, dynamic> bodyData, {
    bool withToken = true,
  }) async {
    try {
      final headers = await _getHeaders(withToken: withToken);
      
      // 1. Encrypt Request Body
      final jsonString = jsonEncode(bodyData);
      final encryptedBody = ApiEncryption.encrypt(jsonString);
      final payload = jsonEncode({"encrypted_data": encryptedBody});

      // 2. Send POST
      final res = await http.post(
        Uri.parse("$baseUrl/$endpoint"),
        headers: headers,
        body: payload,
      ).timeout(
        const Duration(seconds: 20),
        onTimeout: () => throw SocketException('Connection timed out'),
      );

      // 3. Handle Response
      return _processResponse(res);
    } catch (e) {
      return _handleException(e);
    }
  }

  // Generic processing for responses (GET or POST)
  static Map<String, dynamic> _processResponse(http.Response res) {
    if (res.statusCode == 401) {
      return {"status": false, "message": "Unauthorized / Invalid Credentials"};
    } else if (res.statusCode == 403) {
      return {"status": false, "message": "Forbidden / Device Mismatch"};
    } else if (res.statusCode == 404) {
      return {"status": false, "message": "Endpoint not found (404)"};
    } else if (res.statusCode != 200) {
      return {"status": false, "message": "Server Error: ${res.statusCode}"};
    }

    try {
      final body = jsonDecode(res.body);
      if (body['encrypted_data'] != null) {
        final decryptedJson = ApiEncryption.decrypt(body['encrypted_data']);
        if (decryptedJson.isEmpty) {
            return {"status": false, "message": "Gagal dekripsi response server"};
        }
        return jsonDecode(decryptedJson);
      }
      // Fallback for unencrypted responses (should verify strict mode request)
      return body; 
    } catch (e) {
      return {"status": false, "message": "Error parsing response: $e"};
    }
  }

  static Future<Map<String, dynamic>> _safeGetRequest(String endpoint) async {
      try {
          final headers = await _getHeaders();
          final res = await http.get(Uri.parse("$baseUrl/$endpoint"), headers: headers)
              .timeout(const Duration(seconds: 20), onTimeout: () => throw SocketException('Timeout'));
          return _processResponse(res);
      } catch (e) {
          return _handleException(e);
      }
  }

  static Map<String, dynamic> _handleException(dynamic e) {
    if (e is SocketException) {
      return {"status": false, "message": "Koneksi internet bermasalah/offline"};
    }
    return {"status": false, "message": "Terjadi kesalahan: $e"};
  }

  // ================== ENDPOINTS ==================

  // 1. GET USERS (Output Encryption Only)
  static Future<List<dynamic>> getUsers() async {
    final result = await _safeGetRequest("get_users.php");
    if (result['status'] == false) return [];
    return List<dynamic>.from(result['data'] ?? []);
  }

  // 2. GET USER HISTORY (Converted to Encrypted POST)
  // Backend absen_history.php support POST with encryption now.
  static Future<List<dynamic>> getUserHistory(String userId) async {
    final result = await _sendEncryptedRequest("absen_history.php", {"user_id": userId});
    if (result['status'] == false) return [];
    return List<dynamic>.from(result['data'] ?? []);
  }

  // 3. GET ALL PRESENSI (Output Encryption Only via GET)
  // absen_admin_list.php handles standard GET for list, returning encrypted data.
  static Future<List<dynamic>> getAllPresensi() async {
    final result = await _safeGetRequest("absen_admin_list.php");
    if (result['status'] == false) return [];
    return List<dynamic>.from(result['data'] ?? []);
  }

  // 4. GET REKAP (Converted to Encrypted POST)
  static Future<List<dynamic>> getRekap({String? month, String? year}) async {
    final body = {
        if (month != null) "month": month,
        if (year != null) "year": year
    };
    // Use POST to send parameters securely
    final result = await _sendEncryptedRequest("presensi_rekap.php", body);
    if (result['status'] == false) return [];
    return List<dynamic>.from(result['data'] ?? []);
  }

  // 5. LOGIN
  static Future<Map<String, dynamic>> login({
    required String input,
    required String password,
  }) async {
    final deviceId = await getDeviceId();
    final body = {
      "username": input,
      "password": password,
      "device_id": deviceId,
    };
    
    final result = await _sendEncryptedRequest("login.php", body, withToken: false);

    if (result['status'] == true && result['token'] != null) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('auth_token', result['token']);
      await prefs.setString('user_id', result['user']['id'].toString());
      await prefs.setString('user_name', result['user']['nama_lengkap']);
      await prefs.setString('user_role', result['user']['role']);
      await prefs.setString('device_id', deviceId); 
    }
    return result;
  }

  // 6. ADD USER
  static Future<Map<String, dynamic>> addUser({
    required String username,
    required String namaLengkap,
    required String password,
    String? nipNisn,
    String role = 'user',
    String status = 'Karyawan',
  }) async {
    final body = {
      "username": username,
      "nama_lengkap": namaLengkap,
      "password": password,
      "nip_nisn": nipNisn ?? '',
      "role": role,
      "status": status,
    };
    return await _sendEncryptedRequest("update_user.php", body);
  }

  // 7. RESET DEVICE ID
  static Future<Map<String, dynamic>> resetDeviceId(String userId) async {
    final body = {"id": userId, "reset_device": true};
    return await _sendEncryptedRequest("update_user.php", body);
  }

  // 8. LOGOUT
  static Future<void> logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.clear();
  }

  // 9. CHECK LOGIN STATUS
  static Future<bool> isLoggedIn() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('auth_token') != null;
  }

  // 10. GET CURRENT USER (Local)
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

  // 11. SUBMIT PRESENSI
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
    // Note: presensi_add.php now supports JSON input via encrypted_data
    // Map data to match what PHP expects (from $_POST or JSON)
    final body = {
      "user_id": userId, // PHP uses user_id or userId depending on file, presensi_add.php uses user_id
      "userId": userId,  // Send both to be safe or check file. absen.php checks both.
      "jenis": jenis,
      "status": jenis, // presensi_add.php uses 'status' for jenis
      "keterangan": keterangan,
      "informasi": informasi,
      "dokumenBase64": dokumenBase64,
      "latitude": latitude,
      "longitude": longitude,
      "base64Image": base64Image,
      "foto": base64Image, // presensi_add.php uses 'foto'
    };

    // We can target specific endpoint depending on logic.
    // If using 'absen.php' (Main Logic):
    return await _sendEncryptedRequest("absen.php", body);
  }

  // 12. APPROVE PRESENSI
  static Future<Map<String, dynamic>> updatePresensiStatus({
    required String id,
    required String status,
  }) async {
    final body = {"id": id.trim(), "status": status};
    return await _sendEncryptedRequest("presensi_approve.php", body);
  }

  // 13. DELETE USER
  static Future<Map<String, dynamic>> deleteUser(String id) async {
    final body = {"id": id, "action": "delete"}; // Added action 'delete' for absen_admin_list.php if needed, or delete_user.php
    // Original used delete_user.php
    return await _sendEncryptedRequest("delete_user.php", body);
  }

  // 14. UPDATE USER
  static Future<Map<String, dynamic>> updateUser({
    required String id,
    required String username,
    required String namaLengkap,
    String? nipNisn,
    String? role,
    String? password,
  }) async {
    final body = {
      "id": id,
      "username": username,
      "nama_lengkap": namaLengkap,
      if (nipNisn != null) "nip_nisn": nipNisn,
      if (role != null) "role": role,
      if (password != null) "password": password,
    };
    return await _sendEncryptedRequest("update_user.php", body);
  }
}
