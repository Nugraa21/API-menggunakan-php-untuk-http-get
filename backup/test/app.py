import base64
import json
import time
import requests

from Crypto.Cipher import AES
from Crypto.Util.Padding import unpad
from colorama import Fore, Style, init
from requests.exceptions import RequestException, ConnectionError, Timeout

# ================= INIT =================
init(autoreset=True)

# ================= CONFIG =================
BASE_URL = "https://nonlitigious-alene-uninfinitely.ngrok-free.dev"
LOGIN_URL = f"{BASE_URL}/login.php"

API_KEY = "Skaduta2025!@#SecureAPIKey1234567890"
AES_KEY = "SkadutaPresensi2025SecureKey1234"  # TEST ONLY

HEADERS = {
    "Content-Type": "application/json",
    "X-App-Key": API_KEY
}

TIMEOUT = 10  # detik

# ================= STORAGE HASIL =================
results = {
    "test_root_endpoint": {},
    "test_login_success": {},
    "test_login_fail": {},
    "test_bruteforce": []
}

# ================= UTIL =================
def decrypt_aes(encrypted_base64: str, key: str) -> dict:
    raw = base64.b64decode(encrypted_base64)
    iv = raw[:16]
    cipher_text = raw[16:]

    cipher = AES.new(key.encode(), AES.MODE_CBC, iv)
    decrypted = unpad(cipher.decrypt(cipher_text), AES.block_size)

    return json.loads(decrypted.decode("utf-8"))

# ================= TEST 1 =================
def test_root_endpoint():
    print(Fore.CYAN + "\n[1] TEST ROOT ENDPOINT")

    try:
        r = requests.get(BASE_URL, headers=HEADERS, timeout=TIMEOUT)
        results["test_root_endpoint"]["status_code"] = r.status_code
        results["test_root_endpoint"]["content_type"] = r.headers.get("Content-Type")
        results["test_root_endpoint"]["response_preview"] = r.text[:200]

        print("Status Code :", r.status_code)
        print("Content-Type:", r.headers.get("Content-Type"))

        if "application/json" not in r.headers.get("Content-Type", ""):
            print(Fore.YELLOW + "⚠ Endpoint root bukan JSON API (NORMAL)")
            print("Preview Response:")
            print(r.text[:200])
        else:
            data = r.json()
            results["test_root_endpoint"]["json_data"] = data
            print(json.dumps(data, indent=2, ensure_ascii=False))

    except RequestException as e:
        error = str(e)
        print(Fore.RED + "❌ Gagal akses root endpoint")
        print(error)
        results["test_root_endpoint"]["error"] = error

# ================= TEST 2 =================
def test_login_success():
    print(Fore.CYAN + "\n[2] TEST LOGIN SUCCESS")

    payload = {
        "username": "nugra",
        "password": "081328"
    }

    try:
        r = requests.post(LOGIN_URL, headers=HEADERS, json=payload, timeout=TIMEOUT)
        try:
            data = r.json()
            results["test_login_success"]["response"] = data

            if data.get("status") is True:
                print(Fore.GREEN + "✔ LOGIN BERHASIL")
                print("User :", data.get("user"))
                print("Token:", data.get("token"))
                results["test_login_success"]["success"] = True
                results["test_login_success"]["user"] = data.get("user")
                results["test_login_success"]["token"] = data.get("token")
            else:
                print(Fore.RED + "❌ LOGIN GAGAL")
                print(data)
                results["test_login_success"]["success"] = False
        except:
            results["test_login_success"]["raw_response"] = r.text
            print(Fore.RED + "Response bukan JSON valid")

    except RequestException as e:
        error = str(e)
        print(Fore.RED + "❌ Error saat login success test")
        print(error)
        results["test_login_success"]["error"] = error

# ================= TEST 3 =================
def test_login_fail():
    print(Fore.CYAN + "\n[3] TEST LOGIN GAGAL")

    payload = {
        "username": "admin",
        "password": "password_salah"
    }

    try:
        r = requests.post(LOGIN_URL, headers=HEADERS, json=payload, timeout=TIMEOUT)
        try:
            data = r.json()
            results["test_login_fail"]["response"] = data
            print(Fore.YELLOW + json.dumps(data, indent=2, ensure_ascii=False))
        except:
            results["test_login_fail"]["raw_response"] = r.text
            print(Fore.YELLOW + "Response bukan JSON")

    except RequestException as e:
        error = str(e)
        print(Fore.RED + "❌ Error saat login gagal test")
        print(error)
        results["test_login_fail"]["error"] = error

# ================= TEST 4 =================
def test_bruteforce():
    print(Fore.CYAN + "\n[4] TEST BRUTE FORCE (SAFE MODE)")

    for i in range(1, 6):
        payload = {
            "username": "nugra",
            "password": f"salah_{i}"
        }

        attempt = {"attempt_number": i}

        try:
            r = requests.post(LOGIN_URL, headers=HEADERS, json=payload, timeout=TIMEOUT)
            try:
                resp_data = r.json()
                attempt["response"] = resp_data
                print(Fore.YELLOW + f"Attempt {i} → {resp_data}")
            except:
                attempt["raw_response"] = r.text
                print(Fore.YELLOW + f"Attempt {i} → Non-JSON response")

        except (ConnectionError, Timeout) as e:
            error = str(e)
            print(Fore.RED + f"🚫 Attempt {i} → CONNECTION BLOCKED (Rate limit aktif)")
            attempt["blocked"] = True
            attempt["error"] = error

        except RequestException as e:
            error = str(e)
            print(Fore.RED + f"❌ Attempt {i} → Error lain")
            print(error)
            attempt["error"] = error

        results["test_bruteforce"].append(attempt)
        time.sleep(1.5)

# ================= MAIN =================
if __name__ == "__main__":
    print(Style.BRIGHT + Fore.MAGENTA + "\nSKADUTA PRESENSI")
    print(Style.BRIGHT + Fore.MAGENTA + "API SECURITY & LOGIN TESTING")

    test_root_endpoint()
    test_login_success()
    test_login_fail()
    test_bruteforce()

    # Simpan hasil ke file JSON
    with open("test_results.json", "w", encoding="utf-8") as f:
        json.dump(results, f, indent=4, ensure_ascii=False)

    print(Fore.GREEN + "\n\nSEMUA HASIL TELAH DISIMPAN KE FILE: test_results.json")
    print(Fore.CYAN + "Isi file JSON:")
    print(json.dumps(results, indent=4, ensure_ascii=False))