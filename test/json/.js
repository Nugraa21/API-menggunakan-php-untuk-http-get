var s = document.createElement("script");
s.src = "https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js";
document.head.appendChild(s);

typeof CryptoJS

function decryptAES(encryptedBase64, key) {
    const raw = CryptoJS.enc.Base64.parse(encryptedBase64);

    // IV = 16 byte
    const iv = CryptoJS.lib.WordArray.create(raw.words.slice(0, 4), 16);

    // Cipher text
    const cipherText = CryptoJS.lib.WordArray.create(
        raw.words.slice(4),
        raw.sigBytes - 16
    );

    const decrypted = CryptoJS.AES.decrypt(
        { ciphertext: cipherText },
        CryptoJS.enc.Utf8.parse(key),
        {
            iv: iv,
            mode: CryptoJS.mode.CBC,
            padding: CryptoJS.pad.Pkcs7
        }
    );

    return decrypted.toString(CryptoJS.enc.Utf8);
}

typeof decryptAES

fetch("https://nonlitigious-alene-uninfinitely.ngrok-free.dev", {
    headers: {
        "X-App-Key": "Skaduta2025!@#SecureAPIKey1234567890"
    }
})
.then(r => r.json())
.then(d => {
    console.log("Encrypted:", d.encrypted_data);

    const key = "SkadutaPresensi2025SecureKey1234"; // TEST ONLY
    const decrypted = decryptAES(d.encrypted_data, key);

    console.log("Decrypted STRING:", decrypted);

    const json = JSON.parse(decrypted);
    console.log("Decrypted JSON:", json);
})
.catch(err => console.error("ERROR:", err));