# Pressdock Connector — REST API contract

Namespace: `pressdock/v1` · API version: `1.0` · Base URL: `https://<site>/wp-json/pressdock/v1`

## 1. Conventions

**Authentication.** WordPress Application Passwords (HTTP Basic: `username:application-password`). Cookie sessions also work but need the usual REST nonce. All routes require a signed-in user.

**Transport.** The API refuses non-HTTPS requests with `403 pdock_insecure_transport`, unless one of these holds: `WP_DEBUG` is on, or *Allow HTTP on local development hosts* is enabled in Tools → Pressdock and the site host is `localhost`, `127.0.0.1`, `::1`, `*.localhost`, `*.local` or `*.test`.

**Content type.** Requests with a body are JSON (`Content-Type: application/json`). Exception: `POST /media` is `multipart/form-data`.

**Time.** Server timestamps are ISO-8601 UTC (`2026-11-22T10:15:00Z`). Datetime fields in record data are exchanged as `Y-m-d H:i:s` in **GMT**; ISO-8601 with an offset is accepted on input and converted.

**Identity.** Every record has three identifiers:

| Name | Meaning |
|---|---|
| `uuid` | Client-generated UUID, assigned at creation, stable forever. Server-created records get one automatically. |
| `remote_id` | WordPress object id (post ID, table primary key). |
| `revision` | Integer counter, +1 on every change to that record. Used for conflict detection. |

**Record data.** `data` is an object keyed by the entity's schema field names. Unknown fields are ignored on write. Field types: `string, int, float, bool, date (Y-m-d, or empty), datetime, enum, text, html, relation, media, list, object`.

* `list` is an array of strings (`max_items`, `item_max_length` in the schema). `object` is a nested object whose keys are described by `properties` (a sub-schema); errors name nested fields as `parent.child`.
* `string` and `text` fields may carry `max_length`; longer values are truncated, as WordPress sanitizers do.
* A field with `"readonly": true` is computed or system-owned. **Writing one is rejected** with `pdock_readonly_field` (`error.field`) and the op is not applied. On update a read-only field is tolerated when it carries the value the record already has, so a client may send a whole record back. A field with `"create_only": true` may be sent on create and never changed afterwards (same error on update).
* A `relation` value may be the target's `remote_id` (int) **or** its `uuid` (string). A uuid is resolved on the server.
* **Ordering.** Within one `/push` batch the server runs an op after the `create` it references through a relation uuid, whatever their order in `ops`. `results` always keep the request order. Circular references fall back to request order.

**Errors (HTTP level)** use the standard WordPress shape:

```json
{ "code": "pdock_not_authenticated", "message": "Authentication is required. Use an Application Password.", "data": { "status": 401 } }
```

| Status | Code | When |
|---|---|---|
| 401 | `pdock_not_authenticated` | no valid credentials |
| 403 | `pdock_insecure_transport` | not HTTPS and no exception applies |
| 403 | `pdock_forbidden` | missing capability (route-level) |
| 404 | `pdock_unknown_entity` | unknown or disabled entity |
| 400 | `pdock_bad_batch_id`, `pdock_no_ops`, `pdock_no_file`, `pdock_upload_failed` | malformed request |
| 413 | `pdock_too_many_ops` | more than 100 ops in a push |
| 404 | `pdock_not_found`, `pdock_nothing_to_undo` | media id / batch not found |

Per-operation errors in `/push` are **not** HTTP errors; see §5.

---

## 2. `GET /handshake`

Connection check and capability discovery. Call this first.

```
GET /wp-json/pressdock/v1/handshake
Authorization: Basic base64(user:app-password)
```

**200**

```json
{
  "site": {
    "name": "Hejazer Travel",
    "url": "https://example.com",
    "rest_url": "https://example.com/wp-json/pressdock/v1",
    "wp_version": "6.7.1",
    "plugin_version": "1.0.0",
    "api_version": "1.0",
    "timezone": "Asia/Dhaka"
  },
  "user": { "id": 2, "login": "manzur", "display_name": "Manzur" },
  "entities": [
    {
      "key": "post",
      "label": "Posts",
      "group": "WordPress core",
      "actions": { "read": true, "create": true, "update": true, "delete": true }
    }
  ],
  "cursor": 418,
  "limits": { "max_ops": 100, "pull_default": 200, "pull_max": 500 },
  "https": true,
  "server_time": "2026-11-22T10:15:00+00:00"
}
```

* `entities` lists only enabled entities on which the user has **at least one** action.
* `cursor` is the newest change id on the server right now.

---

## 3. `GET /entities`

Entity list with schema. Same visibility rules as the handshake.

**200**

```json
{
  "entities": [
    {
      "key": "post",
      "label": "Posts",
      "group": "WordPress core",
      "actions": { "read": true, "create": true, "update": true, "delete": true },
      "schema": {
        "title":   { "type": "string", "label": "Title",   "required": true,  "readonly": false },
        "content": { "type": "html",   "label": "Content", "required": false, "readonly": false },
        "status":  { "type": "enum",   "label": "Status",  "required": false, "readonly": false,
                     "options": ["draft", "pending", "private", "publish"] }
      }
    }
  ]
}
```

A `relation` field additionally carries `"target": "<entity key>"`.

---

## 4. `GET /pull`

Records changed after a cursor.

| Param | Type | Default | Notes |
|---|---|---|---|
| `entity` | string | all readable entities | filter to one entity |
| `since` | int ≥ 0 | `0` | exclusive cursor. `0` = initial sync |
| `limit` | int 1–500 | `200` | max change rows per page |

```
GET /wp-json/pressdock/v1/pull?entity=post&since=0&limit=2
```

**200**

```json
{
  "items": [
    {
      "entity": "post",
      "remote_id": 12,
      "uuid": "0f8fad5b-d9cb-469f-a165-70867728950e",
      "revision": 3,
      "deleted": false,
      "data": { "title": "Welcome", "content": "<p>Hi</p>", "status": "publish" },
      "action": "upsert",
      "changed_at": "2026-11-22T09:58:12Z",
      "source": "admin",
      "cursor": 405
    },
    {
      "entity": "post",
      "remote_id": 15,
      "uuid": "6c84fb90-12c4-11e1-840d-7b25c5ee775a",
      "revision": 2,
      "deleted": true,
      "data": null,
      "action": "delete",
      "changed_at": "2026-11-22T10:01:40Z",
      "source": "sync",
      "cursor": 409
    }
  ],
  "next_cursor": 409,
  "has_more": true,
  "baseline_pending": false,
  "server_time": "2026-11-22T10:15:00+00:00"
}
```

Behaviour:

* **Loop** until `has_more` is `false`, passing `next_cursor` as `since` each time. Store the final `next_cursor`.
* Several changes to one record inside a page are collapsed to its **latest** state. `revision` and `data` always describe the current server state.
* A **tombstone** has `deleted: true` and `data: null`. Trashing a post is a tombstone; restoring it from trash returns it as an upsert again.
* `source` is `admin` (WordPress admin edit), `sync` (applied through `/push`) or `other` (code, cron, REST, baseline). The client can ignore items it just pushed by comparing `uuid` + `revision`.
* **Baseline.** Records that existed before the plugin was installed have no change row. The first pull for an entity walks them in resumable chunks. While that is unfinished, `baseline_pending` is `true` and `has_more` stays `true` even if `items` is empty; keep pulling.
* Errors: `404 pdock_unknown_entity`, `403 pdock_forbidden` (no read capability for a requested entity). Without `entity`, entities the user cannot read are silently skipped. Individual records the user may not read (per-object capability) are skipped but the cursor still advances.

---

## 5. `POST /push`

Apply a batch of client operations. Operations run **in array order**, one at a time. A failing operation never aborts the others.

**Request**

```json
{
  "batch_id": "b6a0c2e4-7f35-4a67-9e0c-2a8f6f0c9d11",
  "ops": [
    {
      "op_id": "1c0e4c10-8b5d-4d4e-b2a1-5d4a6a7a0001",
      "op": "create",
      "entity": "post",
      "uuid": "0f8fad5b-d9cb-469f-a165-70867728950e",
      "data": { "title": "Written offline", "content": "<p>Body</p>", "status": "draft" }
    },
    {
      "op_id": "1c0e4c10-8b5d-4d4e-b2a1-5d4a6a7a0002",
      "op": "update",
      "entity": "post",
      "remote_id": 12,
      "base_revision": 3,
      "data": { "title": "New title" }
    },
    {
      "op_id": "1c0e4c10-8b5d-4d4e-b2a1-5d4a6a7a0003",
      "op": "delete",
      "entity": "post",
      "uuid": "6c84fb90-12c4-11e1-840d-7b25c5ee775a",
      "base_revision": 2,
      "force": false
    }
  ]
}
```

| Field | Rules |
|---|---|
| `batch_id` | required, 8–36 chars `[A-Za-z0-9-]`. Groups the batch for undo. |
| `ops` | required, 1–100 items |
| `op_id` | required per op, 8–36 chars `[A-Za-z0-9-]`, unique per operation. Idempotency key. |
| `op` | `create` \| `update` \| `delete` |
| `entity` | registered, enabled entity key |
| `uuid` | **create:** required, valid UUID, becomes the record's permanent uuid. **update/delete:** alternative to `remote_id`. |
| `remote_id` | update/delete: WordPress object id. Alternative to `uuid`. |
| `base_revision` | **required** for update and delete: the revision the client last saw |
| `data` | create/update: object of field values. Update is a **partial** update (send changed fields only). |
| `force` | delete only. `true` = delete permanently, `false`/absent = trash where the entity supports it |

**200** (the HTTP status is 200 even when individual ops fail)

```json
{
  "batch_id": "b6a0c2e4-7f35-4a67-9e0c-2a8f6f0c9d11",
  "results": [
    { "op_id": "1c0e4c10-8b5d-4d4e-b2a1-5d4a6a7a0001", "status": "applied", "remote_id": 31, "revision": 1 },
    {
      "op_id": "1c0e4c10-8b5d-4d4e-b2a1-5d4a6a7a0002",
      "status": "conflict",
      "remote_id": 12,
      "revision": 5,
      "server": {
        "entity": "post",
        "remote_id": 12,
        "uuid": "e2d3b1a0-8d3b-4f43-8c33-0a9d5b8f7a12",
        "revision": 5,
        "deleted": false,
        "data": { "title": "Edited in wp-admin", "content": "<p>Hi</p>", "status": "publish" }
      }
    },
    {
      "op_id": "1c0e4c10-8b5d-4d4e-b2a1-5d4a6a7a0003",
      "status": "error",
      "error": { "code": "pdock_forbidden", "message": "You are not allowed to do this." }
    }
  ],
  "cursor": 431,
  "server_time": "2026-11-22T10:15:00+00:00"
}
```

### Per-op `status`

| status | Meaning | Client action |
|---|---|---|
| `applied` | Done. `remote_id` and the new `revision` are returned. | Store them against the uuid. |
| `conflict` | **Not applied.** `base_revision` ≠ current revision, or the record was deleted on the server. `server` holds the current record (`deleted: true`, `data: null` if it was deleted). `revision` is the current server revision. | Let the user resolve, then re-push with `base_revision` = `revision` and a **new** `op_id`. |
| `conflict` with `"reason": "unique"` | **Not applied.** The value of a unique field (`field`) already belongs to another record (a second create of a singleton, a package id in use, a duplicate uuid). `remote_id`, `revision` and `server` describe the record that owns it. | Bind your local record to that `remote_id` (or change the value), then re-push with a **new** `op_id`. |
| `error` | Not applied. `error.code`, `error.message`, optionally `error.field`. | Fix the data or permissions. A retry with the **same** `op_id` is allowed (errors are not stored). |

Retries: `applied` and `conflict` results are stored by `op_id` for 30 days. Re-sending the same `op_id` returns the stored result with `"replayed": true` and does **not** run the operation again. Re-sending a `create` with an already-bound `uuid` (new `op_id`) returns `applied` with the existing `remote_id` instead of creating a duplicate. A `delete` of an already-deleted record with the matching `base_revision` returns `applied`.

### Per-op error codes

| code | Meaning |
|---|---|
| `pdock_bad_op_id` | `op_id` missing or malformed |
| `pdock_bad_op` | `op` not create/update/delete |
| `pdock_unknown_entity` | unknown or disabled entity |
| `pdock_forbidden` | missing capability for the action, or on this specific record |
| `pdock_bad_uuid` | create without a valid `uuid` |
| `pdock_uuid_in_use` | `uuid` belongs to another entity or to a deleted record |
| `pdock_not_found` | no record for `remote_id` / `uuid` |
| `pdock_base_revision_required` | update/delete without `base_revision` |
| `pdock_missing_data` | `data` missing or not an object |
| `pdock_required` | required field missing/empty (`error.field`) |
| `pdock_invalid_field` | value failed the field type check (`error.field`) |
| `pdock_readonly_field` | a read-only field (or, on update, a create-only field) was written (`error.field`) |
| `pdock_unresolved_relation` | relation uuid not known on the server (`error.field`) |
| `pdock_forbidden_status` | e.g. user may not publish |
| `pdock_adapter_error` | the entity adapter refused the write (message included) |
| `pdock_internal_error` | unexpected exception |

Adapters may return their own codes from `validate()`. A `WP_Error` whose data is `array( 'unique' => true, 'field' => ..., 'existing_id' => int )` is reported as a `conflict` with `reason: "unique"`.

### Server-side guarantees

* Capability check per op (`EntityAdapter::capability()`), plus a per-record check where the adapter offers one (for posts: `edit_post` / `delete_post`).
* A **snapshot** of the record is stored before every update/delete, and after every create, under `batch_id`.
* While an op is applied, `Pressdock\Context::is_syncing()` is `true` and the hooks `pdock_sync_started` / `pdock_sync_finished` fire around it. Adapters and other plugins use this to suppress side effects such as customer emails.
* Changes made by sync are written to the change log with `source: "sync"`, so the pushing device will see them again on its next pull (match by `uuid` + `revision` to ignore).

---

## 6. `POST /undo`

Restore the snapshots of a batch. **Administrators only** (`manage_options`).

```json
{ "batch_id": "b6a0c2e4-7f35-4a67-9e0c-2a8f6f0c9d11" }
```

**200**

```json
{ "restored": 3, "errors": [], "batch_id": "b6a0c2e4-7f35-4a67-9e0c-2a8f6f0c9d11", "cursor": 440 }
```

* Snapshots are applied newest first. A created record is deleted permanently; an updated record gets its previous values back; a deleted record is restored **in place** when the adapter supports it (core posts do: restored from trash), otherwise it is re-created and the uuid is re-bound to the new `remote_id`.
* Undo writes normal change-log rows (`source: "sync"`), so devices pick the reversal up on their next pull.
* Each restored snapshot is removed; undoing the same batch again returns `404 pdock_nothing_to_undo`.
* `errors` holds `{ "snapshot": <id>, "message": "…" }` for snapshots that could not be restored; the rest still run.
* Undo does not check revisions: it overwrites newer server edits made after the batch.
* Snapshots are pruned after the retention period (default 14 days), idempotency rows after 30 days (daily cron `pdock_daily_prune`).

---

## 7. `/media`

For fields of type `media`, the value is a WordPress attachment id.

### `POST /media` — upload

Capability: `upload_files`. `multipart/form-data` with the file in field **`file`**. Validation, MIME checks and image sizes are WordPress core's (`media_handle_upload`).

```
POST /wp-json/pressdock/v1/media
Content-Type: multipart/form-data; boundary=...
file=@logo.png
```

**201**

```json
{
  "id": 77,
  "url": "https://example.com/wp-content/uploads/2026/11/logo.png",
  "mime": "image/png",
  "title": "logo",
  "filesize": 48211,
  "alt": ""
}
```

Use the returned `id` as the value of the `media` field in a later `/push`. Errors: `400 pdock_no_file`, `400 pdock_upload_failed`.

### `GET /media?id=77` — download metadata

Requires permission to read/edit that attachment. Returns the same object as above; download the file itself from `url`.

Errors: `404 pdock_not_found`, `403 pdock_forbidden`.

---

## 8. Recommended client flow

1. `GET /handshake` → verify `api_version`, read allowed entities, note `limits`.
2. `GET /entities` → cache schemas; rebuild forms from them.
3. Initial sync: for each entity (or all at once) `GET /pull?since=0` and follow `next_cursor` while `has_more`. Persist `next_cursor`.
4. Work offline. Keep per record: `uuid`, `remote_id`, `revision`, local changes, a pending-op queue with a stable `op_id` per operation.
5. Online: `POST /push` (≤ 100 ops, parents before children). For each result: `applied` → store `remote_id` + `revision`; `conflict` → ask the user; `error` → show it.
6. `GET /pull?since=<stored cursor>` to receive other people's changes, and your own (skip by `uuid` + `revision`).
7. Network failure mid-push? Re-send the **same** `batch_id` and `op_id`s — safe by design.

## 9. Extension points (PHP)

| Hook | Purpose |
|---|---|
| `apply_filters( 'pdock_register_entities', array $adapters )` | add `Pressdock\Contracts\EntityAdapter` instances |
| `Pressdock\Tracker::touch( $entity, $id, 'upsert'\|'delete', $source = null )` | record a change made outside the sync API |
| `Pressdock\Context::is_syncing()` | true while a sync op is being applied |
| `do_action( 'pdock_sync_started' )` / `'pdock_sync_finished'` | wrap a sync operation |
| `do_action( 'pdock_tracked', $entity, $id, $action, $revision, $source )` | after each change-log row |
| `Pressdock\Contracts\RestorableAdapter` | optional: restore deleted records in place on undo |
| Optional adapter methods (detected with `method_exists`) | `can( $action )` own permission model, replaces the capability check · `normalize( array $data, $id )` canonicalize raw input before validation · `preferred_uuid( $id )` reuse a uuid the record already has · `register_hooks()` called at `init` 99 to hook the owning plugin · `refresh()` called before each `GET /pull` so a plugin that fires no hooks can report outside changes through `Tracker::touch()` |
| `Pressdock\Context::current_uuid()` | uuid of the record being created, valid while that op is validated and created |
| `Pressdock\Contracts\EnumerableAdapter` | optional: list all ids so pre-existing records appear in the initial pull (both bundled base classes implement it) |

## 10. Database

| Table | Purpose |
|---|---|
| `{prefix}pdock_changes` | append-only change log; `id` is the sync cursor |
| `{prefix}pdock_records` | `uuid` ↔ `object_id` map, `revision`, `deleted` tombstone flag |
| `{prefix}pdock_ops` | idempotency log (`op_id` PK), also records batch id, entity, status |
| `{prefix}pdock_snapshots` | pre-change snapshots for undo |

Options: `pdock_settings`, `pdock_db_version`, `pdock_devices`, `pdock_baseline` (all autoload off).
