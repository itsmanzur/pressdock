# Pressdock Desktop — architecture

## Folder tree

```
desktop/
├─ src-tauri/                  Rust shell (tiny). Official plugins + 3 keychain commands
│  ├─ src/lib.rs               sql, http, store, opener, process, updater plugins; secret_set/get/delete
│  ├─ capabilities/default.json   permissions (http scope, sql, store, updater…)
│  └─ tauri.conf.json          id com.thereadscope.pressdock, updater pubkey/endpoints
└─ src/
   ├─ platform/                ONE seam between the UI and the OS
   │  ├─ tauri.ts              plugin-sql, plugin-http, plugin-store, keyring, opener
   │  ├─ web.ts                browser/test fallback (sql.js = real SQLite WASM)
   │  └─ index.ts              picks one at start-up
   ├─ db/
   │  ├─ migrations.ts         versioned migrations, PRAGMA user_version, 1 tx each
   │  ├─ inline.ts             single-script transaction builder (see "Transactions")
   │  └─ repo.ts               SiteRepo: ALL reads/writes of records + outbox
   ├─ api/client.ts            PressdockApi: /handshake, /entities (+ typed errors)
   ├─ sites/siteStore.ts       site list (plugin-store, no secrets) + password in keychain + per-site DB
   ├─ schema/                  types.ts (field types) · validate.ts (required/types/enums)
   ├─ sync/                    ← NEXT STEP. Today: interface only (index.ts)
   ├─ io/                      parse (CSV/XLSX) · mapping · coerce · plan (validate + match, pure) ·
   │                           export · bulk (pure planners) · worker + run (Web Worker w/ fallback) · save
   ├─ state/app.tsx            React context: sites, current repo, entities, online, pending N
   ├─ components/              Shell (sidebar/top bar) · SitesView · EntityView · DataGrid
   │                           RecordForm · fields/* (RichEditor, RelationPicker, MediaPicker…)
   ├─ i18n/                    en.json, bn.json (react-i18next)
   └─ lib/                     html sanitizer, record label helpers
```

## Data flow

```
 UI (grid / form / bulk edit)
        │  create / update / delete(uuids)
        ▼
  SiteRepo  ──── ONE SQLite transaction ───►  records  (status new|dirty|deleted…)
        │                                     outbox   (op_id, op, payload, base_revision)
        │ subscribe() → counts, "Pending changes: N", grid reload
        ▼
  site-<id>.db   (one SQLite file per site, migrated on open)

 Test connection / Refresh schema ──► PressdockApi (/handshake, /entities) ──► entities table (schema_json)
                                      (HTTP via plugin-http, Basic auth, password read from OS keychain)

 NEXT STEP  src/sync/ ─ reads outbox ─► POST /push ─► applies results to records/sync_log
                       └ GET /pull   ─► upserts records, updates entities.cursor
 The UI never calls the network for record data, so it works fully offline.
```

## Rules implemented in `SiteRepo`

| Action | `records` | `outbox` |
|---|---|---|
| create | row `status=new`, UUID v4 | `create` op (read-only fields stripped) |
| edit a **new** record | data merged | edit folded into the pending `create` (server must see one create) |
| edit a synced/dirty record | `status=dirty` | `update` op with only the changed writable fields + `base_revision` |
| delete a **new** record | row removed | its ops removed (server never knew it) |
| delete a synced record | `status=deleted` (soft) | pending `update` ops dropped, `delete` op with `base_revision` |

## Transactions

`tauri-plugin-sql` exposes a connection **pool**; separate `BEGIN` / `COMMIT` calls could run on different connections.
`SqlDb.batch()` therefore sends `BEGIN IMMEDIATE; …; COMMIT;` as **one** `execute` call (parameters are inlined as
escaped SQLite literals by `db/inline.ts`, unit-tested against real SQLite). On failure it issues `ROLLBACK`.
If this ever proves fragile on a platform, the fallback is a small Rust command that owns one `rusqlite` connection.

## Security

* Application Passwords: OS keychain only (`keyring` crate: Windows Credential Manager, macOS Keychain, Secret Service). Never in SQLite, the plugin-store file or logs.
* HTML from the site is sanitized before entering the editor; app CSP forbids inline scripts.
* HTTP permission scope is wide (`http(s)://*`) because users add arbitrary WordPress sites.

## Import / export

`io/` is pure (no DB access): `parseFile` → `autoMap`/`applyTemplate` → `buildPlan` (per-row coercion, matching,
relation resolution, validation; yields every 500 rows) → `SiteRepo.importItems` (500-row transactions; updates go through
the same `buildUpdate` as the form, so edits fold into pending creates and frozen ops are never rewritten).
Bulk edit uses `planBulk` for the preview and `SiteRepo.updateEach` to apply. Update imports send only changed
writable fields; `create_only` and read-only fields are never patched.
