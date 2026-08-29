use openssl::hash::MessageDigest;
use openssl::pkey::PKey;
use openssl::sign::Signer;
use std::time::{SystemTime, UNIX_EPOCH};

const B32_ALPHABET: &[u8] = b"ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";

pub fn new_secret() -> Result<String, String> {
    let mut bytes = [0u8; 20];
    let mut f = std::fs::File::open("/dev/urandom")
        .map_err(|e| format!("could not open /dev/urandom: {e}"))?;
    use std::io::Read;
    f.read_exact(&mut bytes)
        .map_err(|e| format!("could not read urandom: {e}"))?;
    Ok(base32_encode(&bytes))
}

pub fn base32_encode(data: &[u8]) -> String {
    let mut out = String::new();
    let mut buffer: u32 = 0;
    let mut bits: u32 = 0;
    for &b in data {
        buffer = (buffer << 8) | b as u32;
        bits += 8;
        while bits >= 5 {
            out.push(B32_ALPHABET[((buffer >> (bits - 5)) & 0x1F) as usize] as char);
            bits -= 5;
        }
    }
    if bits > 0 {
        out.push(B32_ALPHABET[((buffer << (5 - bits)) & 0x1F) as usize] as char);
    }
    out
}

pub fn base32_decode(s: &str) -> Result<Vec<u8>, String> {
    let mut buffer: u32 = 0;
    let mut bits: u32 = 0;
    let mut out = Vec::new();
    for c in s.chars() {
        let v = match c {
            'a'..='z' => c as u32 - 'a' as u32 + 26,
            'A'..='Z' => c as u32 - 'A' as u32,
            '2'..='7' => c as u32 - '2' as u32 + 26,
            '=' => break,
            _ => 0,
        };
        buffer = (buffer << 5) | v;
        bits += 5;
        if bits >= 8 {
            out.push(((buffer >> (bits - 8)) & 0xFF) as u8);
            bits -= 8;
        }
    }
    Ok(out)
}

pub fn totp(secret: &[u8], step: u64) -> String {
    let mut msg = [0u8; 8];
    for (i, b) in msg.iter_mut().enumerate() {
        *b = ((step >> (8 * (7 - i))) & 0xFF) as u8;
    }
    let key = PKey::hmac(secret).expect("hmac key");
    let digest = Signer::new(MessageDigest::sha1(), &key)
        .and_then(|mut s| {
            s.update(&msg)?;
            s.sign_to_vec()
        })
        .expect("hmac sign");
    let offset = (digest[digest.len() - 1] & 0x0F) as usize;
    let bin_code = ((digest[offset] as u32 & 0x7F) << 24)
        | ((digest[offset + 1] as u32) << 16)
        | ((digest[offset + 2] as u32) << 8)
        | digest[offset + 3] as u32;
    let code = bin_code % 1_000_000u32;
    format!("{:06}", code)
}

pub fn current_step() -> u64 {
    let now = SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_default();
    now.as_secs() / 30
}

pub fn verify_code(secret_b32: &str, code: &str, window: u64) -> bool {
    let secret = match base32_decode(&secret_b32.trim().to_uppercase()) {
        Ok(s) => s,
        Err(_) => return false,
    };
    let step = current_step();
    for w in 0..=window {
        let s = if w == 0 { step } else { step - w };
        if totp(&secret, s) == code.trim() {
            return true;
        }
        if totp(&secret, step + w) == code.trim() {
            return true;
        }
    }
    false
}

pub fn provisioning_uri(secret_b32: &str, label: &str, issuer: &str) -> String {
    let encoded: String = standard_encode(label);
    let issuer_enc: String = standard_encode(issuer);
    format!(
        "otpauth://totp/{encoded}?secret={secret_b32}&issuer={issuer_enc}&period=30&digits=6&algorithm=SHA1"
    )
}

fn standard_encode(s: &str) -> String {
    s.chars()
        .map(|c| match c {
            ' ' | '/' | ':' | ';' | '=' | '?' | '&' => format!("%{:02X}", c as u32),
            _ => c.to_string(),
        })
        .collect()
}