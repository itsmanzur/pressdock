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
   The Connector requires HTTPS; for local hosts enable *Allow HTTP on local development hosts* in Pressdock → Settings (or set `WP_ENVIRONMENT_TYPE` to `local`).

## Web app (https://app.thereadscope.com/)

The same app is published as a static web app at **`https://app.thereadscope.com/`** by
`.github/workflows/pages.yml` (GitHub Pages, on every push to `main` that touches `desktop/`).

**Why its own subdomain:** browser storage (localStorage, IndexedDB) is per origin. On
`<user>.github.io/<repo>/` every project of that GitHub user shares one origin, and under
`thereadscope.com/pressdock` every WordPress plugin script on that site would; either could read the
site databases and a remembered Application Password. `app.thereadscope.com` serves only this app.

* One-time setup:
  1. DNS for `thereadscope.com`: `CNAME app → itsmanzur.github.io`.
  2. GitHub → repository **Settings → Pages**: Source **GitHub Actions**, Custom domain
     `app.thereadscope.com`, **Enforce HTTPS**. (With an Actions deployment no `CNAME` file is needed.)
  The old `itsmanzur.github.io/pressdock/` address then redirects to the new domain.
* Build: `npx vite build --mode web --outDir dist-pages` (served at the root), then
  `npx vite preview --outDir dist-pages`. `--mode web` adds a strict **Content-Security-Policy** meta tag
  (see `vite.config.ts`); the Tauri and dev builds do not get it, because Tauri talks to its core over `ipc:`
  and Vite's dev client needs inline scripts. `frame-ancestors` cannot be set from a meta tag and GitHub Pages
  cannot send custom headers, so clickjacking protection would need a host that can (e.g. a CDN in front).
* `PRESSDOCK_BASE=/sub/path/` builds a copy for a sub-path, if ever needed.
* Storage: each site database is a SQLite snapshot in IndexedDB; settings in localStorage. Passwords stay in
  sessionStorage (this tab) unless the user ticks **Remember the password on this device** (then localStorage).
* Only one tab may run the app at a time (Web Locks), so two tabs never overwrite each other's snapshot.
* `public/sw.js` caches the app shell for offline start; it never intercepts requests to WordPress sites.
* The WordPress dashboard's **Open web app** button links to `#connect?url=…&user=…`; the fragment never reaches
  the server, and the app removes it from the address bar after reading it.
* An HTTPS-hosted page cannot call an `http://` site (mixed content): use the site's `https://` address, or the
  desktop app / a local build for local development sites.

## Auto-update

`tauri.conf.json` contains the updater public key and endpoint template
(`https://thereadscope.com/pressdock/updates/{{target}}/{{arch}}/{{current_version}}`). To ship updates:

1. Keep the private key (`~/.pressdock/updater.key` was generated for this project) **secret and outside the repo**.
2. Set `bundle.createUpdaterArtifacts` to `true`, then build with `TAURI_SIGNING_PRIVATE_KEY_PATH` set.
3. Publish the `.sig`-signed bundles and a `latest.json` at the endpoint.

## Import / export / bulk edit

* **Import** (grid toolbar → Import): CSV or XLSX (SheetJS) → column mapping (auto-match by key or label, Bengali
  headers and digits supported, mapping templates saved per site + entity) → mode (create / update / upsert, matched by
  remote ID, UUID or a chosen field) → preview with per-row validation → rows become local records + outbox ops.
  Nothing reaches the site until the next sync. Relation columns match by ID, UUID, name or a chosen field; ambiguous
  matches are errors. Parsing and validation run in a Web Worker (main-thread fallback) and are chunked, so 10k+ rows
  do not freeze the UI.
* **Export**: current grid view (filters, sort, visible columns), all records, or an import template
  (headers, example row and, in XLSX, an Instructions sheet). CSV is UTF-8 with BOM; "ID"/"UUID" columns make an
  export re-importable as an update.
* **Bulk edit** (select rows): set a value, find & replace in text fields, or clear a field, with a before/after
  preview; bulk delete shows the exact count first.

## Layout of local data

* Settings and the site list: `pressdock-settings.json` (plugin-store; **no secrets**).
* One database per site: `site-<id>.db` in the app config directory
  (`entities`, `records`, `outbox`, `sync_log`, plus `media_pending` for files chosen offline).
* Application Passwords: OS keychain, service `com.thereadscope.pressdock`, account `site:<id>`.

## Known limitations

* Relation cells show `#id`; the form picker shows record labels from local data.
* Media files chosen offline are stored in `media_pending` and uploaded by the sync engine.
* Import/export file dialogs (`plugin-dialog` + `plugin-fs`) and the worker bundle are verified in the browser build only, not in a Tauri window.
* XLSX reading is in-memory: very large workbooks (hundreds of thousands of rows) may need a lot of RAM.
