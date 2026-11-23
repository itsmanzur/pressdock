//! Pressdock desktop shell. Intentionally tiny: SQLite, HTTP, key/value store,
//! updater and opener come from official Tauri plugins. The only custom code is
//! the OS-keychain bridge (Windows Credential Manager / macOS Keychain /
//! Secret Service on Linux) used for WordPress Application Passwords.

use keyring::Entry;

const SERVICE: &str = "com.thereadscope.pressdock";

fn entry(account: &str) -> Result<Entry, String> {
    Entry::new(SERVICE, account).map_err(|e| e.to_string())
}

#[tauri::command]
fn secret_set(account: String, secret: String) -> Result<(), String> {
    entry(&account)?.set_password(&secret).map_err(|e| e.to_string())
}

/// Returns `None` when no secret is stored for the account.
#[tauri::command]
fn secret_get(account: String) -> Result<Option<String>, String> {
    match entry(&account)?.get_password() {
        Ok(s) => Ok(Some(s)),
        Err(keyring::Error::NoEntry) => Ok(None),
        Err(e) => Err(e.to_string()),
    }
}

/// Deleting a missing secret is not an error.
#[tauri::command]
fn secret_delete(account: String) -> Result<(), String> {
    match entry(&account)?.delete_credential() {
        Ok(()) | Err(keyring::Error::NoEntry) => Ok(()),
        Err(e) => Err(e.to_string()),
    }
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    let builder = tauri::Builder::default()
        .plugin(tauri_plugin_sql::Builder::default().build())
        .plugin(tauri_plugin_http::init())
        .plugin(tauri_plugin_store::Builder::new().build())
        .plugin(tauri_plugin_opener::init())
        .plugin(tauri_plugin_process::init())
        .invoke_handler(tauri::generate_handler![secret_set, secret_get, secret_delete]);

    #[cfg(desktop)]
    let builder = builder.plugin(tauri_plugin_updater::Builder::new().build());

    builder
        .run(tauri::generate_context!())
        .expect("error while running Pressdock");
}
