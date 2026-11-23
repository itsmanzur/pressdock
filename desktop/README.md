# Pressdock (desktop)

**Pressdock by thereadscope** — offline-first desktop app (Windows first, macOS + Linux) that manages WordPress plugin
data through the [Pressdock Connector](../readme.txt) REST API (`pressdock/v1`, see [`../docs/api-contract.md`](../docs/api-contract.md)).

Stack: Tauri 2 · React 19 · TypeScript · Vite · Tailwind 4 · TanStack Table + Virtual · react-i18next (English + বাংলা).
App identifier: `com.thereadscope.pressdock`. Architecture and data flow: [ARCHITECTURE.md](ARCHITECTURE.md).

**Status (step 1):** sites, per-site SQLite, schema-driven grid/form UI, local create/edit/delete with outbox.
**Not yet:** the sync engine (`src/sync/` is an interface stub) — records are only local for now.

## Prerequisites

* Node 20+ and npm
* Rust (stable) — https://rustup.rs
* Windows: *Visual Studio Build Tools* with **Desktop development with C++**, and WebView2 (preinstalled on Win 11)
* macOS: Xcode command line tools · Linux: `webkit2gtk-4.1`, `libsecret-1-dev`, `librsvg2-dev`, `libssl-dev`, `build-essential` (see Tauri prerequisites)

## Develop

```powershell
cd desktop
npm install
npm run tauri dev      # desktop app (SQLite, keychain, HTTP plugin)
npm run dev            # UI only, in a browser at http://127.0.0.1:1420
```

The browser mode uses `sql.js` (SQLite in WASM) and keeps data in `localStorage` and the password in `sessionStorage`.
It exists for UI work/tests only; the real app stores the password in the OS keychain. In dev builds the entity toolbar
has a **+50k demo** button to test grid virtualization.

## Test & build

```powershell
npm test               # migrations, outbox/transaction rules, SQL escaping, validation (vitest, real SQLite)
npm run build          # type-check + production web bundle in dist/
npm run tauri build    # installers (msi/nsis, dmg, deb/AppImage) in src-tauri/target/release/bundle
cd src-tauri; cargo check
```

### Connect to a test WordPress

1. Install and activate the Pressdock Connector (and, for the bundled adapters, Hejazer Kafela).
2. WordPress → Users → Profile → **Application Passwords** → create one.
3. In Pressdock: **Add site** → URL, username, Application Password → **Test connection**.
   The Connector requires HTTPS; for local hosts enable *Allow HTTP on local development hosts* in Tools → Pressdock (or `WP_DEBUG`).

## Auto-update

`tauri.conf.json` contains the updater public key and endpoint template
(`https://thereadscope.com/pressdock/updates/{{target}}/{{arch}}/{{current_version}}`). To ship updates:

1. Keep the private key (`~/.pressdock/updater.key` was generated for this project) **secret and outside the repo**.
2. Set `bundle.createUpdaterArtifacts` to `true`, then build with `TAURI_SIGNING_PRIVATE_KEY_PATH` set.
3. Publish the `.sig`-signed bundles and a `latest.json` at the endpoint.

## Layout of local data

* Settings and the site list: `pressdock-settings.json` (plugin-store; **no secrets**).
* One database per site: `site-<id>.db` in the app config directory
  (`entities`, `records`, `outbox`, `sync_log`, plus `media_pending` for files chosen offline).
* Application Passwords: OS keychain, service `com.thereadscope.pressdock`, account `site:<id>`.

## Known limitations

* No sync yet. Removing a site wipes its tables but the (empty) `.db` file remains (no filesystem plugin).
* Relation cells show `#id`; the form picker shows record labels from local data.
* Media files chosen offline are stored in `media_pending` and uploaded by the future sync engine.
