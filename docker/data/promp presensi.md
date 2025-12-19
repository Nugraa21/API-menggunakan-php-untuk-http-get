ini ada perubahan bisa kamu sesuwaikan untuk databasenya sql nya sama kayak yang itu 

## Aturan Sistem Absensi
- **Penutupan absen masuk**: Maksimal telat jam 09:00.
- **Pembukaan absen pulang**: Minimal jam 01:00.
- **Pulang cepat**: Tidak menghiraukan jam (konfirmasi di setujui / tidak).
- **Izin**: Ditangani secara khusus berdasarkan inputan form (misalnya, sakit).
- **Penugasan**: Jam pulang/masuk tidak perlu diisi karena jamnya ada di dalam informasi surat tugasnya. Khusus penugasan full, tidak ada kolom jam yang diisi.


```php
<?php
include "config.php";
randomDelay();
validateApiKey();

$sekolah_lat = -7.7771639173358516;
$sekolah_lng = 110.36716347232226;
$max_distance = 120;

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input) $input = $_POST;

$userId     = sanitizeInput($input['userId'] ?? $input['user_id'] ?? '');
$jenis      = sanitizeInput($input['jenis'] ?? '');
$keterangan = sanitizeInput($input['keterangan'] ?? '');
$informasi  = sanitizeInput($input['informasi'] ?? '');
$dokumen64  = $input['dokumenBase64'] ?? '';
$lat        = floatval($input['latitude'] ?? 0);
$lng        = floatval($input['longitude'] ?? 0);
$selfie64   = $input['base64Image'] ?? '';

// Validasi dasar
if (empty($userId) || empty($jenis)) {
    echo json_encode(["status" => false, "message" => "User ID atau jenis presensi kosong!"]);
    exit;
}

// Prepared statements
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
$stmt->bind_param("s", $userId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    echo json_encode(["status" => false, "message" => "User tidak ditemukan"]);
    exit;
}
$stmt->close();

// Validasi khusus
if (in_array($jenis, ['Izin', 'Pulang Cepat']) && empty($keterangan)) {
    echo json_encode(["status" => false, "message" => "Keterangan wajib diisi untuk $jenis!"]);
    exit;
}
if (strpos($jenis, 'Penugasan') === 0) {
    if (empty($informasi)) {
        echo json_encode(["status" => false, "message" => "Informasi penugasan wajib diisi!"]);
        exit;
    }
    if (empty($dokumen64)) {
        echo json_encode(["status" => false, "message" => "Dokumen penugasan wajib diunggah!"]);
        exit;
    }
}

// Cek jarak hanya untuk Masuk & Pulang
if (in_array($jenis, ['Masuk', 'Pulang'])) {
    if ($lat == 0 || $lng == 0) {
        echo json_encode(["status" => false, "message" => "Lokasi tidak terdeteksi!"]);
        exit;
    }
    $jarak = calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng);
    if ($jarak > $max_distance) {
        echo json_encode(["status" => false, "message" => "Di luar radius sekolah! Jarak: " . round($jarak, 1) . "m"]);
        exit;
    }

    // Cek absen ganda hari ini
    $today = date('Y-m-d');
    $stmt = $conn->prepare("SELECT jenis FROM absensi WHERE user_id = ? AND DATE(created_at) = ? AND jenis IN ('Masuk', 'Pulang')");
    $stmt->bind_param("ss", $userId, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $absen_hari_ini = [];
    while ($row = $result->fetch_assoc()) {
        $absen_hari_ini[] = $row['jenis'];
    }
    $stmt->close();

    if ($jenis == 'Masuk' && in_array('Masuk', $absen_hari_ini)) {
        echo json_encode(["status" => false, "message" => "Kamu sudah absen Masuk hari ini!"]);
        exit;
    }
    if ($jenis == 'Pulang' && in_array('Pulang', $absen_hari_ini)) {
        echo json_encode(["status" => false, "message" => "Kamu sudah absen Pulang hari ini!"]);
        exit;
    }
}

$status = in_array($jenis, ['Masuk', 'Pulang']) ? 'Disetujui' : 'Waiting';

// Upload selfie & dokumen (sama seperti sebelumnya, tapi lebih aman)
$selfie_name = '';
if (!empty($selfie64)) {
    $selfie_dir = "selfie/";
    if (!is_dir($selfie_dir)) mkdir($selfie_dir, 0777, true);
    $selfie_name = "selfie_" . $userId . "_" . time() . ".jpg";
    $selfie_path = $selfie_dir . $selfie_name;
    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $selfie64));
    if ($decoded) file_put_contents($selfie_path, $decoded);
}

$dokumen_name = '';
if (!empty($dokumen64)) {
    $dokumen_dir = "dokumen/";
    if (!is_dir($dokumen_dir)) mkdir($dokumen_dir, 0777, true);
    $ext = strpos($dokumen64, 'data:image') === 0 ? 'jpg' : 'pdf';
    $dokumen_name = "dokumen_" . $userId . "_" . time() . "." . $ext;
    $dokumen_path = $dokumen_dir . $dokumen_name;
    $decoded = base64_decode(preg_replace('#^data:\w+/\w+;base64,#i', '', $dokumen64));
    if (!$decoded || !file_put_contents($dokumen_path, $decoded)) {
        echo json_encode(["status" => false, "message" => "Gagal upload dokumen!"]);
        exit;
    }
}

// Insert dengan prepared statement
$stmt = $conn->prepare("INSERT INTO absensi (user_id, jenis, keterangan, informasi, dokumen, selfie, latitude, longitude, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
$stmt->bind_param("ssssssdss", $userId, $jenis, $keterangan, $informasi, $dokumen_name, $selfie_name, $lat, $lng, $status);

if ($stmt->execute()) {
    $id = $stmt->insert_id;
    $jarak_str = (in_array($jenis, ['Masuk', 'Pulang'])) ? round(calculateDistance($sekolah_lat, $sekolah_lng, $lat, $lng), 1) . "m" : null;
    echo json_encode([
        "status" => true,
        "message" => "Presensi $jenis berhasil!",
        "data" => ["id" => $id, "jenis" => $jenis, "status" => $status, "jarak" => $jarak_str]
    ]);
} else {
    // Hapus file jika gagal
    if ($selfie_name && file_exists("selfie/$selfie_name")) unlink("selfie/$selfie_name");
    if ($dokumen_name && file_exists("dokumen/$dokumen_name")) unlink("dokumen/$dokumen_name");
    echo json_encode(["status" => false, "message" => "Gagal simpan: " . $stmt->error]);
}
$stmt->close();
$conn->close();
?>
```

ini code absen nya dan kamu bisa sesuwaikan g dan edit di bagian flutter dartnya juga 

```dart
// pages/presensi_page.dart
// VERSI FINAL – SELFIE KAMERA FULLSCREEN + TOMBOL KIRIM SELALU KELIHATAN (ENHANCED: Modern Professional UI – Subtle gradients, clean typography, immersive map for seamless UX)

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
  static const double maxRadius = 110;

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
    _pulseAnimation = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _pulseController, curve: Curves.easeInOut),
    );
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

  Future<void> _initLocation() async {
    bool serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled)
      return _showSnack('Aktifkan layanan lokasi untuk melanjutkan');

    LocationPermission perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) {
      perm = await Geolocator.requestPermission();
      if (perm == LocationPermission.denied)
        return _showSnack('Izin lokasi diperlukan untuk presensi');
    }
    if (perm == LocationPermission.deniedForever)
      return _showSnack(
        'Izin lokasi ditolak permanen. Buka pengaturan aplikasi.',
      );

    try {
      final pos = await Geolocator.getCurrentPosition(
        desiredAccuracy: LocationAccuracy.high,
      );
      if (mounted) setState(() => _position = pos);
    } catch (e) {
      _showSnack('Gagal mendeteksi lokasi. Coba lagi.');
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
    if (_isMapNeeded) {
      if (_position == null) return _showSnack('Menunggu deteksi lokasi');
      final jarak = _distanceToSchool();
      if (jarak > maxRadius)
        return _showSnack(
          'Lokasi saat ini di luar radius sekolah (${jarak.toStringAsFixed(0)} m)',
        );
    }

    if (_wajibSelfie && _selfieFile == null)
      return _showSnack('Silakan ambil foto selfie');
    if (_isIzin) {
      if (_dokumenFile == null) return _showSnack('Unggah bukti izin');
      if (_ketC.text.trim().isEmpty) return _showSnack('Isi keterangan izin');
    }
    if (_isPenugasan) {
      if (_infoC.text.trim().isEmpty)
        return _showSnack('Isi informasi penugasan');
      if (_dokumenFile == null) return _showSnack('Unggah dokumen penugasan');
    }

    setState(() => _loading = true);
    try {
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

      if (res['status'] == true)
        _showSuccessDialog();
      else
        _showSnack(res['message'] ?? 'Gagal mengirim presensi');
    } catch (e) {
      _showSnack('Terjadi kesalahan: $e');
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
            borderRadius: BorderRadius.circular(20),
          ),
          backgroundColor: Colors.white,
          contentPadding: const EdgeInsets.all(32),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(
                  color: const Color(0xFF10B981),
                  shape: BoxShape.circle,
                ),
                child: const Icon(Icons.check, size: 60, color: Colors.white),
              ),
              const SizedBox(height: 20),
              const Text(
                "Presensi Berhasil",
                style: TextStyle(
                  fontSize: 24,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF10B981),
                ),
              ),
              const SizedBox(height: 8),
              Text(
                "Presensi $_jenis telah tercatat.",
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 16, color: const Color(0xFF6B7280)),
              ),
              const SizedBox(height: 24),
              const Text(
                "Terima kasih",
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w500,
                  color: Color(0xFF10B981),
                ),
              ),
            ],
          ),
        ),
      ),
    );

    Future.delayed(const Duration(milliseconds: 2500), () {
      if (mounted)
        Navigator.of(context)
          ..pop()
          ..pop();
    });
  }

  void _showSnack(String msg) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          msg,
          style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 14),
        ),
        backgroundColor: msg.contains('Berhasil') || msg.contains('tercatat')
            ? const Color(0xFF10B981)
            : const Color(0xFFEF4444),
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        margin: const EdgeInsets.all(16),
        duration: const Duration(seconds: 3),
      ),
    );
  }

  Widget _buildMap() {
    final jarak = _distanceToSchool();
    final inRadius = jarak <= maxRadius;

    return Container(
      height: 420,
      margin: const EdgeInsets.symmetric(horizontal: 0),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(20),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.08),
            blurRadius: 20,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(20),
        child: Stack(
          children: [
            FlutterMap(
              options: MapOptions(
                initialCenter: _position != null
                    ? lat_lng.LatLng(_position!.latitude, _position!.longitude)
                    : lat_lng.LatLng(sekolahLat, sekolahLng),
                initialZoom: 17.0,
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
                        radius: maxRadius + (_pulseAnimation.value * 20),
                        useRadiusInMeter: true,
                        color: Colors.transparent,
                        borderColor: inRadius
                            ? const Color(0xFF10B981).withOpacity(0.4)
                            : const Color(0xFFEF4444).withOpacity(0.4),
                        borderStrokeWidth: 3,
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
                          ? const Color(0xFF10B981).withOpacity(0.15)
                          : const Color(0xFFEF4444).withOpacity(0.15),
                      borderColor: inRadius
                          ? const Color(0xFF10B981)
                          : const Color(0xFFEF4444),
                      borderStrokeWidth: 2,
                    ),
                  ],
                ),
                MarkerLayer(
                  markers: [
                    Marker(
                      point: lat_lng.LatLng(sekolahLat, sekolahLng),
                      width: 80,
                      height: 80,
                      child: Column(
                        children: [
                          Container(
                            padding: const EdgeInsets.all(8),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              shape: BoxShape.circle,
                              boxShadow: [
                                BoxShadow(
                                  color: Colors.black.withOpacity(0.1),
                                  blurRadius: 8,
                                ),
                              ],
                            ),
                            child: const Icon(
                              Icons.school,
                              size: 32,
                              color: Color(0xFF374151),
                            ),
                          ),
                          const SizedBox(height: 4),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 6,
                              vertical: 2,
                            ),
                            decoration: BoxDecoration(
                              color: const Color(0xFF374151),
                              borderRadius: BorderRadius.circular(8),
                            ),
                            child: const Text(
                              "Sekolah",
                              style: TextStyle(
                                fontWeight: FontWeight.w500,
                                fontSize: 12,
                                color: Colors.white,
                              ),
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
                        height: 80,
                        child: Column(
                          children: [
                            Container(
                              padding: const EdgeInsets.all(7),
                              decoration: BoxDecoration(
                                color: Colors.white,
                                shape: BoxShape.circle,
                                boxShadow: [
                                  BoxShadow(
                                    color: Colors.black.withOpacity(0.1),
                                    blurRadius: 8,
                                  ),
                                ],
                              ),
                              child: const Icon(
                                Icons.location_on,
                                size: 28,
                                color: Color(0xFF3B82F6),
                              ),
                            ),
                            const SizedBox(height: 4),
                            SizedBox(
                              width: 70,
                              child: Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 4,
                                  vertical: 2,
                                ),
                                decoration: BoxDecoration(
                                  color: const Color(0xFF3B82F6),
                                  borderRadius: BorderRadius.circular(8),
                                ),
                                child: const Text(
                                  "Anda",
                                  textAlign: TextAlign.center,
                                  style: TextStyle(
                                    fontWeight: FontWeight.w500,
                                    fontSize: 11,
                                    color: Colors.white,
                                  ),
                                ),
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
              top: 12,
              left: 12,
              right: 12,
              child: Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: inRadius
                        ? [
                            const Color(0xFF10B981).withOpacity(0.9),
                            const Color(0xFF10B981).withOpacity(0.7),
                          ]
                        : [
                            const Color(0xFFEF4444).withOpacity(0.9),
                            const Color(0xFFEF4444).withOpacity(0.7),
                          ],
                  ),
                  borderRadius: BorderRadius.circular(16),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withOpacity(0.1),
                      blurRadius: 10,
                      offset: const Offset(0, 4),
                    ),
                  ],
                ),
                child: Row(
                  children: [
                    Icon(
                      inRadius
                          ? Icons.check_circle_outline
                          : Icons.error_outline,
                      color: Colors.white,
                      size: 24,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            inRadius
                                ? "Dalam Wilayah Sekolah"
                                : "Di Luar Wilayah",
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 16,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          Text(
                            "${jarak.toStringAsFixed(0)} m dari lokasi sekolah",
                            style: TextStyle(
                              color: Colors.white.withOpacity(0.9),
                              fontSize: 14,
                            ),
                          ),
                        ],
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
          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 20),
        ),
        backgroundColor: Colors.transparent,
        elevation: 0,
        foregroundColor: Colors.white,
        flexibleSpace: Container(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              colors: [
                const Color(0xFF3B82F6).withOpacity(0.9),
                const Color(0xFF3B82F6).withOpacity(0.6),
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
            colors: [const Color(0xFF3B82F6).withOpacity(0.03), Colors.white],
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
                padding: const EdgeInsets.fromLTRB(16, 100, 16, 100),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Text(
                      _jenis == 'Masuk'
                          ? 'Selamat Datang'
                          : _jenis == 'Pulang'
                          ? 'Selamat Pulang'
                          : 'Presensi $_jenis',
                      style: const TextStyle(
                        fontSize: 24,
                        fontWeight: FontWeight.w600,
                      ),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      _isMapNeeded
                          ? 'Pastikan posisi Anda di wilayah sekolah'
                          : 'Lengkapi informasi berikut',
                      style: TextStyle(
                        color: const Color(0xFF6B7280),
                        fontSize: 14,
                      ),
                      textAlign: TextAlign.center,
                    ),
                    const SizedBox(height: 24),

                    if (_isMapNeeded) ...[
                      _buildMap(),
                      const SizedBox(height: 24),
                    ],

                    if (_isIzin || _isPulangCepat)
                      _buildTextField(
                        _ketC,
                        'Keterangan / Alasan',
                        'Misalnya: Sakit, urusan keluarga...',
                        Icons.description_outlined,
                        cs,
                      ),
                    if (_isIzin || _isPulangCepat) const SizedBox(height: 16),

                    if (_isPenugasan)
                      _buildTextField(
                        _infoC,
                        'Informasi Penugasan',
                        'Deskripsikan tugas yang diberikan',
                        Icons.task_outlined,
                        cs,
                        maxLines: 4,
                      ),
                    if (_isPenugasan) const SizedBox(height: 16),

                    if (_wajibSelfie)
                      Container(
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(16),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withOpacity(0.05),
                              blurRadius: 12,
                              offset: const Offset(0, 4),
                            ),
                          ],
                        ),
                        child: Column(
                          children: [
                            ListTile(
                              leading: Container(
                                padding: const EdgeInsets.all(8),
                                decoration: BoxDecoration(
                                  color: const Color(
                                    0xFF3B82F6,
                                  ).withOpacity(0.1),
                                  shape: BoxShape.circle,
                                ),
                                child: const Icon(
                                  Icons.camera_alt_outlined,
                                  color: Color(0xFF3B82F6),
                                  size: 24,
                                ),
                              ),
                              title: const Text(
                                'Foto Selfie',
                                style: TextStyle(
                                  fontWeight: FontWeight.w500,
                                  fontSize: 16,
                                ),
                              ),
                              subtitle: Text(
                                'Diperlukan untuk verifikasi',
                                style: TextStyle(
                                  color: const Color(0xFF9CA3AF),
                                  fontSize: 12,
                                ),
                              ),
                              trailing: const Icon(
                                Icons.arrow_forward_ios,
                                size: 16,
                              ),
                              onTap: _openCameraSelfie,
                            ),
                            if (_selfieFile != null)
                              Padding(
                                padding: const EdgeInsets.all(12),
                                child: ClipRRect(
                                  borderRadius: BorderRadius.circular(12),
                                  child: Image.file(
                                    _selfieFile!,
                                    height: 200,
                                    width: double.infinity,
                                    fit: BoxFit.cover,
                                  ),
                                ),
                              ),
                          ],
                        ),
                      ),
                    if (_wajibSelfie) const SizedBox(height: 16),

                    if (_isIzin || _isPenugasan)
                      Container(
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(16),
                          boxShadow: [
                            BoxShadow(
                              color: Colors.black.withOpacity(0.05),
                              blurRadius: 12,
                              offset: const Offset(0, 4),
                            ),
                          ],
                        ),
                        child: Column(
                          children: [
                            ListTile(
                              leading: Container(
                                padding: const EdgeInsets.all(8),
                                decoration: BoxDecoration(
                                  color: const Color(
                                    0xFFF59E0B,
                                  ).withOpacity(0.1),
                                  shape: BoxShape.circle,
                                ),
                                child: const Icon(
                                  Icons.attachment_outlined,
                                  color: Color(0xFFF59E0B),
                                  size: 24,
                                ),
                              ),
                              title: Text(
                                _isIzin ? 'Bukti Izin' : 'Dokumen Penugasan',
                                style: const TextStyle(
                                  fontWeight: FontWeight.w500,
                                  fontSize: 16,
                                ),
                              ),
                              subtitle: Text(
                                'Pilih dari galeri',
                                style: TextStyle(
                                  color: const Color(0xFF9CA3AF),
                                  fontSize: 12,
                                ),
                              ),
                              trailing: const Icon(
                                Icons.arrow_forward_ios,
                                size: 16,
                              ),
                              onTap: _pickDokumen,
                            ),
                            if (_dokumenFile != null)
                              Padding(
                                padding: const EdgeInsets.all(12),
                                child: ClipRRect(
                                  borderRadius: BorderRadius.circular(12),
                                  child: Image.file(
                                    _dokumenFile!,
                                    height: 200,
                                    width: double.infinity,
                                    fit: BoxFit.cover,
                                  ),
                                ),
                              ),
                          ],
                        ),
                      ),

                    const SizedBox(height: 80),
                  ],
                ),
              ),

              // TOMBOL KIRIM – Clean floating style
              Positioned(
                left: 16,
                right: 16,
                bottom: 16,
                child: SafeArea(
                  child: Container(
                    height: 56,
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular(12),
                      boxShadow: [
                        BoxShadow(
                          color: const Color(0xFF3B82F6).withOpacity(0.2),
                          blurRadius: 12,
                          offset: const Offset(0, 4),
                        ),
                      ],
                    ),
                    child: ElevatedButton.icon(
                      onPressed: _loading ? null : _submitPresensi,
                      icon: _loading
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(
                                color: Colors.white,
                                strokeWidth: 2,
                              ),
                            )
                          : const Icon(Icons.arrow_forward, size: 20),
                      label: Text(
                        _loading ? 'Mengirim...' : 'Kirim Presensi',
                        style: const TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: const Color(0xFF3B82F6),
                        foregroundColor: Colors.white,
                        elevation: 0,
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                        ),
                        padding: const EdgeInsets.symmetric(horizontal: 20),
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
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.05),
            blurRadius: 12,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: TextField(
        controller: c,
        maxLines: maxLines,
        style: const TextStyle(fontSize: 16),
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          prefixIcon: const Icon(
            Icons.description_outlined,
            color: Color(0xFF6B7280),
            size: 24,
          ),
          border: InputBorder.none,
          contentPadding: const EdgeInsets.all(16),
          labelStyle: TextStyle(color: const Color(0xFF9CA3AF)),
          hintStyle: TextStyle(color: const Color(0xFFD1D5DB)),
        ),
      ),
    );
  }
}

// HALAMAN KAMERA SELFIE – Minimalist clean design
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
      ResolutionPreset.medium,
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
      ResolutionPreset.medium,
    );
    await _controller.initialize();
    if (mounted) setState(() {});
  }

  Future<void> _takePicture() async {
    try {
      await _initializeControllerFuture;
      final image = await _controller.takePicture();
      Navigator.pop(context, File(image.path));
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('Gagal mengambil foto')));
      }
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
                // Top bar
                SafeArea(
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 16,
                      vertical: 12,
                    ),
                    margin: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: Colors.black.withOpacity(0.5),
                      borderRadius: BorderRadius.circular(12),
                    ),
                    child: const Text(
                      'Arahkan kamera ke wajah Anda',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w500,
                      ),
                      textAlign: TextAlign.center,
                    ),
                  ),
                ),
                // Bottom controls
                Align(
                  alignment: Alignment.bottomCenter,
                  child: Container(
                    padding: const EdgeInsets.fromLTRB(16, 0, 16, 32),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                      children: [
                        IconButton(
                          icon: const Icon(
                            Icons.flip_camera_ios_outlined,
                            size: 32,
                            color: Colors.white,
                          ),
                          onPressed: _switchCamera,
                        ),
                        GestureDetector(
                          onTap: _takePicture,
                          child: Container(
                            width: 70,
                            height: 70,
                            decoration: BoxDecoration(
                              shape: BoxShape.circle,
                              border: Border.all(color: Colors.white, width: 4),
                              color: Colors.white.withOpacity(0.2),
                            ),
                            child: const Icon(
                              Icons.circle,
                              size: 40,
                              color: Colors.white,
                            ),
                          ),
                        ),
                        IconButton(
                          icon: const Icon(
                            Icons.close_outlined,
                            size: 32,
                            color: Colors.white,
                          ),
                          onPressed: () => Navigator.pop(context),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            );
          } else {
            return const Center(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  CircularProgressIndicator(color: Colors.white),
                  SizedBox(height: 16),
                  Text(
                    'Memuat kamera...',
                    style: TextStyle(color: Colors.white70, fontSize: 16),
                  ),
                ],
              ),
            );
          }
        },
      ),
    );
  }
}

```

untuk ui ux tetap sama tugas kamu hanya update perbaruan itu aja agar sama kayak oerintahnya soalnya itu refisi heheh dari dosen aku 

