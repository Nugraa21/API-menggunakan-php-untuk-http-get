# API Security Testing Script (Defensive / Authorized Testing)
# Target: Backend API (PHP) via ngrok
# Purpose: Security validation + impact & mitigation report (NON-DESTRUCTIVE)

import requests
import time
from datetime import datetime

# ===================== CONFIG =====================
BASE_URL = "https://nonlitigious-alene-uninfinitely.ngrok-free.dev/"
API_KEY = "Skaduta2025!@#SecureAPIKey1234567890"
TIMEOUT = 10

HEADERS_OK = {
    "Content-Type": "application/json",
    "X-App-Key": API_KEY,
    "ngrok-skip-browser-warning": "true"
}

HEADERS_BAD_KEY = {
    "Content-Type": "application/json",
    "X-App-Key": "invalid_key",
    "ngrok-skip-browser-warning": "true"
}

HEADERS_NO_KEY = {
    "Content-Type": "application/json",
    "ngrok-skip-browser-warning": "true"
}

# ===================== KNOWLEDGE BASE (Impact & Fix) =====================
VULN_INFO = {
    "Proteksi file": {
        "impact": "File sensitif dapat dibaca langsung (konfigurasi, kredensial, struktur DB)",
        "fix": "Blokir akses via .htaccess / Nginx rule dan simpan file sensitif di luar web root"
    },
    "Login tanpa API Key": {
        "impact": "API bisa diakses tanpa autentikasi aplikasi",
        "fix": "Validasi header X-App-Key di semua endpoint sebelum proses apapun"
    },
    "Login API Key salah": {
        "impact": "Penyerang bisa menebak atau mem-bypass API Key",
        "fix": "Gunakan perbandingan konstan (hash_equals) dan rotasi API Key"
    },
    "SQL Injection (Login)": {
        "impact": "Penyerang dapat login tanpa password atau membaca data pengguna",
        "fix": "Gunakan prepared statement (PDO/mysqli) dan validasi input"
    },
    "XSS Payload Handling": {
        "impact": "Script berbahaya bisa dieksekusi di sisi klien",
        "fix": "Sanitasi input dan escape output (htmlspecialchars)"
    },
    "HTTP Method Tampering": {
        "impact": "Endpoint dipanggil dengan metode yang tidak semestinya",
        "fix": "Batasi metode HTTP dan kembalikan 405 jika tidak sesuai"
    },
    "Bruteforce Delay": {
        "impact": "Akun dapat ditebak dengan percobaan berulang",
        "fix": "Tambahkan rate limit, delay acak, dan lock sementara"
    }
}

# ===================== UTIL =====================
def color(text, c):
    colors = {
        "green": "\033[92m",
        "red": "\033[91m",
        "yellow": "\033[93m",
        "blue": "\033[94m",
        "reset": "\033[0m"
    }
    return f"{colors.get(c,'')}{text}{colors['reset']}"


def header(title):
    print("\n" + "=" * 80)
    print(color(title.center(80), "blue"))
    print("=" * 80)


def result(name, ok, detail=""):
    icon = "" if ok else ""
    status = "AMAN" if ok else "RENTAN"
    c = "green" if ok else "red"
    print(f"{icon} {color(name.ljust(45), c)} {status}")
    if detail:
        print(f"    ↳ Detail : {detail}")
    if not ok:
        key = name.split(" (")[0]
        info = VULN_INFO.get(name) or VULN_INFO.get(key)
        if info:
            print(color("    ⚠️ IMPACT : ", "yellow") + info["impact"])
            print(color("    🔧 FIX    : ", "blue") + info["fix"])
    print("-" * 80)

# ===================== SAFE TEST CASES =====================
# NOTE: Semua test bersifat non-destruktif & hanya validasi respon server

def test_file_block(filename):
    try:
        r = requests.get(BASE_URL + filename, timeout=TIMEOUT)
        ok = r.status_code in [403, 404]
        result(f"Proteksi file {filename}", ok, f"HTTP {r.status_code}")
    except Exception as e:
        result(f"Proteksi file {filename}", False, str(e))


def test_no_api_key():
    r = requests.post(BASE_URL + "login.php", headers=HEADERS_NO_KEY,
                      json={"username": "test", "password": "test"})
    result("Login tanpa API Key", r.status_code == 401, f"HTTP {r.status_code}")


def test_wrong_api_key():
    r = requests.post(BASE_URL + "login.php", headers=HEADERS_BAD_KEY,
                      json={"username": "test", "password": "test"})
    result("Login API Key salah", r.status_code == 401, f"HTTP {r.status_code}")


def test_sql_injection():
    # Payload aman (tidak destruktif) hanya untuk cek validasi
    payload = {"username": "' OR '1'='1", "password": "x"}
    r = requests.post(BASE_URL + "login.php", headers=HEADERS_OK, json=payload)
    safe = r.status_code in [401, 403]
    result("SQL Injection (Login)", safe, f"HTTP {r.status_code}")


def test_xss_payload():
    payload = {"username": "<script>alert(1)</script>", "password": "x"}
    r = requests.post(BASE_URL + "login.php", headers=HEADERS_OK, json=payload)
    safe = "<script>" not in r.text
    result("XSS Payload Handling", safe)


def test_method_tampering():
    r = requests.get(BASE_URL + "login.php", headers=HEADERS_OK)
    result("HTTP Method Tampering", r.status_code in [403, 405], f"HTTP {r.status_code}")


def test_rate_limit():
    start = time.time()
    for _ in range(5):
        requests.post(BASE_URL + "login.php", headers=HEADERS_OK,
                      json={"username": "wrong", "password": "wrong"})
    duration = time.time() - start
    result("Bruteforce Delay", duration > 1.0, f"Total {duration:.2f}s")

# ===================== RUN =====================
print(color("SKADUTA PRESENSI - API SECURITY TEST (DEFENSIVE)", "blue"))
print(color(f"Tanggal : {datetime.now().strftime('%d/%m/%Y %H:%M:%S')}", "yellow"))
print(color(f"Target  : {BASE_URL}", "yellow"))
print("=" * 80)

header("FILE ACCESS PROTECTION")
test_file_block("config.php")
test_file_block(".env")
test_file_block("database.sql")

header("AUTHENTICATION SECURITY")
test_no_api_key()
test_wrong_api_key()
test_sql_injection()
test_xss_payload()
test_method_tampering()

header("ANTI BRUTE FORCE")
test_rate_limit()

print(color("\nSCAN SELESAI - LAPORAN KEAMANAN TERSEDIA", "green"))