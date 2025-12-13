# =========================================================
# SKADUTA PRESENSI - API SECURITY TESTING SCRIPT
# Purpose : Defensive Security Testing (Authorized)
# Target  : PHP Backend API (ngrok)
# Standard: OWASP Top 10 + Best Practice
# =========================================================

import requests
import time
import re
from datetime import datetime

# ===================== CONFIG =====================
BASE_URL = "https://nonlitigious-alene-uninfinitely.ngrok-free.dev/backendapk/"
LOGIN_ENDPOINT = "login.php"
TIMEOUT = 10

API_KEY = "Skaduta2025!@#SecureAPIKey1234567890"

HEADERS_OK = {
    "Content-Type": "application/json",
    "X-App-Key": API_KEY,
    "ngrok-skip-browser-warning": "true"
}

HEADERS_BAD_KEY = {
    "Content-Type": "application/json",
    "X-App-Key": "invalid_key"
}

HEADERS_NO_KEY = {
    "Content-Type": "application/json"
}

# ===================== KNOWLEDGE BASE =====================
VULN_INFO = {
    "Proteksi file": {
        "impact": "File sensitif dapat diakses langsung dan membocorkan kredensial",
        "fix": "Pindahkan file sensitif ke luar web root dan blokir via server config"
    },
    "Login tanpa API Key": {
        "impact": "API dapat diakses tanpa autentikasi aplikasi",
        "fix": "Validasi API Key di awal setiap endpoint"
    },
    "Login API Key salah": {
        "impact": "API Key dapat ditebak atau dibypass",
        "fix": "Gunakan hash_equals() dan rotasi API Key"
    },
    "SQL Injection": {
        "impact": "Penyerang dapat login tanpa kredensial valid",
        "fix": "Gunakan prepared statement dan parameter binding"
    },
    "XSS": {
        "impact": "Script berbahaya dapat dieksekusi di sisi klien",
        "fix": "Escape output dan validasi input"
    },
    "Security Headers": {
        "impact": "Rentan clickjacking dan XSS",
        "fix": "Tambahkan CSP, X-Frame-Options, HSTS"
    },
    "CORS": {
        "impact": "API bisa diakses dari domain berbahaya",
        "fix": "Batasi origin yang diizinkan"
    },
    "Verbose Error": {
        "impact": "Struktur sistem bocor",
        "fix": "Gunakan pesan error generik"
    },
    "Bruteforce": {
        "impact": "Akun dapat ditebak",
        "fix": "Rate limit, delay, dan lock sementara"
    },
    "Mass Assignment": {
        "impact": "Field sensitif bisa dimanipulasi",
        "fix": "Whitelist field input"
    },
    "Token Predictability": {
        "impact": "Token dapat ditebak",
        "fix": "Gunakan random_bytes()"
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
    print("\n" + "=" * 90)
    print(color(title.center(90), "blue"))
    print("=" * 90)

def log_result(name, safe, detail=""):
    status = "AMAN" if safe else "RENTAN"
    c = "green" if safe else "red"
    print(f"{color(name.ljust(45), c)} {status}")
    if detail:
        print(f"   ↳ Detail : {detail}")
    if not safe and name in VULN_INFO:
        print(color("   ⚠ IMPACT : ", "yellow") + VULN_INFO[name]["impact"])
        print(color("   🔧 FIX    : ", "blue") + VULN_INFO[name]["fix"])
    print("-" * 90)

# ===================== TEST CASES =====================

def test_file_access():
    header("FILE ACCESS PROTECTION")
    for f in ["config.php", ".env", "database.sql"]:
        try:
            r = requests.get(BASE_URL + f, timeout=TIMEOUT)
            log_result(f"Proteksi file ({f})", r.status_code in [403, 404], f"HTTP {r.status_code}")
        except Exception as e:
            log_result(f"Proteksi file ({f})", False, str(e))

def test_api_key():
    header("API KEY VALIDATION")

    r1 = requests.post(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_NO_KEY,
                       json={"username": "a", "password": "b"})
    log_result("Login tanpa API Key", r1.status_code == 401, f"HTTP {r1.status_code}")

    r2 = requests.post(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_BAD_KEY,
                       json={"username": "a", "password": "b"})
    log_result("Login API Key salah", r2.status_code == 401, f"HTTP {r2.status_code}")

def test_sql_injection():
    header("SQL INJECTION TEST")
    payload = {"username": "' OR '1'='1", "password": "x"}
    r = requests.post(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_OK, json=payload)
    log_result("SQL Injection", r.status_code in [401, 403], f"HTTP {r.status_code}")

def test_xss():
    header("XSS TEST")
    payload = {"username": "<script>alert(1)</script>", "password": "x"}
    r = requests.post(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_OK, json=payload)
    log_result("XSS", "<script>" not in r.text)

def test_http_method():
    header("HTTP METHOD TAMPERING")
    r = requests.get(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_OK)
    log_result("Method Tampering", r.status_code in [403, 405], f"HTTP {r.status_code}")

def test_security_headers():
    header("SECURITY HEADERS")
    r = requests.get(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_OK)
    required = ["X-Frame-Options", "X-Content-Type-Options", "Content-Security-Policy"]
    missing = [h for h in required if h not in r.headers]
    log_result("Security Headers", len(missing) == 0, f"Missing: {missing}")

def test_cors():
    header("CORS CONFIGURATION")
    headers = HEADERS_OK.copy()
    headers["Origin"] = "https://evil.com"
    r = requests.post(BASE_URL + LOGIN_ENDPOINT, headers=headers,
                      json={"username": "a", "password": "b"})
    allow = r.headers.get("Access-Control-Allow-Origin", "")
    log_result("CORS", allow != "*", allow)

def test_verbose_error():
    header("VERBOSE ERROR")
    payload = {"username": None, "password": None}
    r = requests.post(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_OK, json=payload)
    leak = bool(re.search(r"(sql|mysqli|warning|error|line)", r.text.lower()))
    log_result("Verbose Error", not leak)

def test_bruteforce():
    header("BRUTE FORCE")
    start = time.time()
    for _ in range(5):
        requests.post(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_OK,
                      json={"username": "wrong", "password": "wrong"})
    duration = time.time() - start
    log_result("Bruteforce", duration > 1.0, f"{duration:.2f}s")

def test_mass_assignment():
    header("MASS ASSIGNMENT")
    payload = {
        "username": "test",
        "password": "test",
        "role": "admin",
        "is_admin": 1
    }
    r = requests.post(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_OK, json=payload)
    log_result("Mass Assignment", "admin" not in r.text.lower())

def test_token_randomness():
    header("TOKEN PREDICTABILITY")
    tokens = set()
    for _ in range(3):
        r = requests.post(BASE_URL + LOGIN_ENDPOINT, headers=HEADERS_OK,
                          json={"username": "wrong", "password": "wrong"})
        if "token" in r.text:
            tokens.add(r.json().get("token"))
    log_result("Token Predictability", len(tokens) == len(set(tokens)))

# ===================== RUN =====================
print(color("SKADUTA PRESENSI - API SECURITY TESTING", "blue"))
print(color(f"Tanggal : {datetime.now().strftime('%d/%m/%Y %H:%M:%S')}", "yellow"))
print(color(f"Target  : {BASE_URL}", "yellow"))
print("=" * 90)

test_file_access()
test_api_key()
test_sql_injection()
test_xss()
test_http_method()
test_security_headers()
test_cors()
test_verbose_error()
test_bruteforce()
test_mass_assignment()
test_token_randomness()

print(color("\nSCAN SELESAI - HASIL SIAP DIMASUKKAN KE LAPORAN TA 🔐", "green"))
