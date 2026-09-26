//! Encryption of what the local database and the waiting screenshots hold about a person's work (window titles,
//! application names, the queue of what is to be sent, the waiting pictures). AES-256-GCM with a random nonce per
//! value; the key is made on first run and kept in Windows Credential Manager next to the sign-in token, so a copy of
//! the database file alone (a backup, another user of the PC, a stolen disk without the Windows login) reads as noise.
//!
//! Text is stored as `enc1:<base64 of nonce and ciphertext>`, files as a small marker followed by nonce and ciphertext.
//! A value without the marker is a plain one from before this existed and is returned as it is, which is what lets an
//! old database be read and then encrypted in place (see `Db::encrypt_existing`).

use aes_gcm::aead::{Aead, AeadCore, KeyInit, OsRng};
use aes_gcm::{Aes256Gcm, Key, Nonce};
use base64::engine::general_purpose::STANDARD;
use base64::Engine;

const TEXT_PREFIX: &str = "enc1:";
const FILE_MARKER: &[u8] = b"TTENC1\0\0";
const NONCE_LEN: usize = 12;

const SERVICE: &str = "com.office.timetracker";
const ACCOUNT: &str = "db-key";

#[derive(Clone)]
pub struct Cipher {
    /// `None` when there is nowhere safe to keep a key (Credential Manager refused): nothing is encrypted then, and
    /// nothing is lost, rather than making data that could never be read again.
    inner: Option<Aes256Gcm>,
}

impl Cipher {
    pub fn from_key(key: [u8; 32]) -> Self {
        Self { inner: Some(Aes256Gcm::new(Key::<Aes256Gcm>::from_slice(&key))) }
    }

    /// No encryption at all (the key store is unavailable).
    pub fn disabled() -> Self {
        Self { inner: None }
    }

    /// The key from Credential Manager, made and saved on the first run. The flag says the key is new: when the
    /// database already holds encrypted values then, they belong to a key that is gone (see `Db::open`).
    pub fn load_or_create() -> (Self, bool) {
        let entry = match keyring::Entry::new(SERVICE, ACCOUNT) {
            Ok(entry) => entry,
            Err(error) => return Self::unavailable(&error.to_string()),
        };
        if let Ok(stored) = entry.get_password() {
            if let Some(key) = STANDARD.decode(stored.trim()).ok().and_then(|bytes| <[u8; 32]>::try_from(bytes).ok()) {
                return (Self::from_key(key), false);
            }
        }

        let key: [u8; 32] = Aes256Gcm::generate_key(OsRng).into();
        match entry.set_password(&STANDARD.encode(key)) {
            Ok(()) => (Self::from_key(key), true),
            Err(error) => Self::unavailable(&error.to_string()),
        }
    }

    fn unavailable(reason: &str) -> (Self, bool) {
        tracing::warn!(%reason, "the key store is not available: the local database stays unencrypted");
        (Self::disabled(), false)
    }

    pub fn enabled(&self) -> bool {
        self.inner.is_some()
    }

    pub fn is_sealed(value: &str) -> bool {
        value.starts_with(TEXT_PREFIX)
    }

    /// Text ready to store. Already-sealed text and everything while encryption is off comes back unchanged.
    pub fn seal(&self, plain: &str) -> String {
        match &self.inner {
            Some(cipher) if !Self::is_sealed(plain) => {
                let nonce = Aes256Gcm::generate_nonce(&mut OsRng);
                match cipher.encrypt(&nonce, plain.as_bytes()) {
                    Ok(encrypted) => {
                        let mut bytes = nonce.to_vec();
                        bytes.extend_from_slice(&encrypted);
                        format!("{TEXT_PREFIX}{}", STANDARD.encode(bytes))
                    }
                    Err(_) => plain.to_owned(),
                }
            }
            _ => plain.to_owned(),
        }
    }

    /// The text that was stored: plain values as they are, sealed ones decrypted, `None` when a sealed value cannot
    /// be read (another key, or altered).
    pub fn open(&self, stored: &str) -> Option<String> {
        let Some(encoded) = stored.strip_prefix(TEXT_PREFIX) else {
            return Some(stored.to_owned());
        };
        let bytes = STANDARD.decode(encoded).ok()?;
        self.decrypt(&bytes).and_then(|plain| String::from_utf8(plain).ok())
    }

    /// A file's bytes ready to write.
    pub fn seal_bytes(&self, plain: &[u8]) -> Vec<u8> {
        match &self.inner {
            Some(cipher) if !plain.starts_with(FILE_MARKER) => {
                let nonce = Aes256Gcm::generate_nonce(&mut OsRng);
                match cipher.encrypt(&nonce, plain) {
                    Ok(encrypted) => {
                        let mut out = FILE_MARKER.to_vec();
                        out.extend_from_slice(&nonce);
                        out.extend_from_slice(&encrypted);
                        out
                    }
                    Err(_) => plain.to_vec(),
                }
            }
            _ => plain.to_vec(),
        }
    }

    /// A file's bytes as they were: plain files as they are, sealed ones decrypted, `None` when unreadable.
    pub fn open_bytes(&self, stored: &[u8]) -> Option<Vec<u8>> {
        match stored.strip_prefix(FILE_MARKER) {
            None => Some(stored.to_vec()),
            Some(rest) => self.decrypt(rest),
        }
    }

    pub fn is_sealed_file(bytes: &[u8]) -> bool {
        bytes.starts_with(FILE_MARKER)
    }

    fn decrypt(&self, nonce_and_ciphertext: &[u8]) -> Option<Vec<u8>> {
        let cipher = self.inner.as_ref()?;
        if nonce_and_ciphertext.len() < NONCE_LEN {
            return None;
        }
        let (nonce, ciphertext) = nonce_and_ciphertext.split_at(NONCE_LEN);
        cipher.decrypt(Nonce::from_slice(nonce), ciphertext).ok()
    }
}

impl Cipher {
    /// A fixed key for the tests (`Db::open_in_memory_for_test`), so they need no Credential Manager.
    #[cfg_attr(not(test), allow(dead_code))]
    pub fn for_test() -> Self {
        Self::from_key([7u8; 32])
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn text_round_trips_and_is_not_readable_when_stored() {
        let cipher = Cipher::for_test();
        let sealed = cipher.seal("Budget 2027.xlsx - Excel");

        assert!(Cipher::is_sealed(&sealed));
        assert!(!sealed.contains("Budget"));
        assert_eq!(cipher.open(&sealed).as_deref(), Some("Budget 2027.xlsx - Excel"));
    }

    #[test]
    fn the_same_text_seals_differently_each_time() {
        let cipher = Cipher::for_test();
        assert_ne!(cipher.seal("same"), cipher.seal("same"));
    }

    #[test]
    fn plain_values_from_before_come_back_unchanged_and_sealing_twice_changes_nothing() {
        let cipher = Cipher::for_test();
        assert_eq!(cipher.open("Visual Studio Code").as_deref(), Some("Visual Studio Code"));
        let once = cipher.seal("x");
        assert_eq!(cipher.seal(&once), once);
    }

    #[test]
    fn a_changed_or_foreign_value_is_refused_not_misread() {
        let cipher = Cipher::for_test();
        let sealed = cipher.seal("secret");

        let mut altered = sealed.clone();
        altered.pop();
        altered.push(if sealed.ends_with('A') { 'B' } else { 'A' });
        assert_eq!(cipher.open(&altered), None);

        let other = Cipher::from_key([9u8; 32]);
        assert_eq!(other.open(&sealed), None);
        assert_eq!(cipher.open("enc1:not base64 at all!"), None);
    }

    #[test]
    fn files_round_trip_and_old_plain_files_are_read_as_they_are() {
        let cipher = Cipher::for_test();
        let jpeg = [0xFF, 0xD8, 0xFF, 0xE0, 1, 2, 3, 4];
        let sealed = cipher.seal_bytes(&jpeg);

        assert!(Cipher::is_sealed_file(&sealed));
        assert!(!sealed.windows(4).any(|w| w == [0xFF, 0xD8, 0xFF, 0xE0]));
        assert_eq!(cipher.open_bytes(&sealed).as_deref(), Some(&jpeg[..]));
        assert_eq!(cipher.open_bytes(&jpeg).as_deref(), Some(&jpeg[..]));
        assert_eq!(cipher.seal_bytes(&sealed), sealed);
    }

    #[test]
    fn with_no_key_store_nothing_is_encrypted_and_sealed_data_is_unreadable() {
        let off = Cipher::disabled();
        assert_eq!(off.seal("plain"), "plain");
        assert_eq!(off.seal_bytes(&[1, 2, 3]), vec![1, 2, 3]);
        assert!(!off.enabled());

        let sealed = Cipher::for_test().seal("x");
        assert_eq!(off.open(&sealed), None);
    }

    /// Needs Windows Credential Manager (an interactive Windows session); run it with the ignored tests: cargo test --lib key_store -- --ignored
    #[test]
    #[ignore = "uses the real Windows Credential Manager"]
    fn key_store_keeps_one_key_between_runs() {
        let (first, _) = Cipher::load_or_create();
        let (second, is_new) = Cipher::load_or_create();

        assert!(first.enabled() && second.enabled());
        assert!(!is_new, "the second load must find the key the first one saved");
        let sealed = first.seal("same key");
        assert_eq!(second.open(&sealed).as_deref(), Some("same key"));
    }
}
