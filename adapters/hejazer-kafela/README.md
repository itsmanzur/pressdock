# Hejazer Kafela adapters

Bundled Pressdock adapters for **Hejazer Kafela** (`POTHIK_VERSION`, namespace `Pothik\`). They register only while that plugin is active (the check runs inside the `pdock_register_entities` filter). **No file of Hejazer Kafela is modified.** Source of truth for every field: `hejazer-kafela/docs/data-map.md`.

| Entity key | Kafela data | Storage | Object id (`remote_id`) | Base |
|---|---|---|---|---|
| `kafela_package` | Umrah/Hajj packages | `wp_options` row `pothik_packages` (list of arrays) | integer from a map (`pdock_kafela_package_ids`), never reused | implements the contract directly (option storage fits neither base class) |
| `kafela_inquiry` | Inquiries / leads | table `{prefix}pothik_inquiries` | table `id` | `AbstractTableAdapter` |
| `kafela_receipt` | The single receipt slip | `wp_options` row `pothik_receipt` | always `1` | `SingletonAdapter` |
| `kafela_settings` | Agency profile + behaviour | `wp_options` row `pothik_settings` | always `1` | `SingletonAdapter` |

| `kafela_ledger` (Pro, **read only**) | What each pilgrim owes in a group | table `{prefix}pothik_ledger` (string key) | integer from a map (`pdock_kafela_ledger_ids`) | contract directly |
| `kafela_payment` (Pro) | Payments and receipts | table `{prefix}pothik_payments` | table `id` | contract directly |

The two Pro entities register only while **Hejazer Kafela Pro** is active too (`POTHIK_PRO_VERSION`, `PothikPro\Payments`, `PothikPro\Repo\Ledger`). Other Pro tables (rooms, installments, agents, commissions, leads, documents, messages, activity, counters) are **not** synced. See "Pro entities" below.

## Rules that apply to every entity

* **Readonly = computed or system-owned.** Writing one returns `error` `pdock_readonly_field` with `error.field`; nothing is written. Sending the unchanged current value back on update is tolerated.
* **Permissions** use Kafela's own `Access::can( area, 'view'|'edit' )` (area `packages`, `inquiries`, `receipt`, `settings`), so roles granted through the `pothik_can` filter work. `read` maps to `view`; `create`/`update`/`delete` map to `edit`. Fallback `manage_options`.
* **No e-mail during sync.** (1) The adapters never call Kafela's public form handler, the only place that mails (`InquiryStore::send_notification_email`, private). (2) `pothik_notify_email` returns `false` and `pre_wp_mail` short-circuits while `Context::is_syncing()`, so even an add-on that mails from a re-fired hook cannot send. Mail outside a sync request is untouched.
* **Kafela hooks re-fired for sync writes** (so Pro's activity log and lead cleanup keep working): `pothik_package_created|saved|deleted`, `pothik_inquiry_reply_toggled|deleted`, `pothik_receipt_saved`, `pothik_settings_saved`. **Not** fired by default: `pothik_inquiry_created` (add-ons use it as "a visitor just submitted the form"). Per-event switch: `apply_filters( 'pdock_kafela_fire_hooks', bool $fire, string $entity, string $event )`.
* **Changes made in Kafela's own screens** are tracked through those same hooks (`register_hooks()`): package create/save/status/delete, inquiry created/reply/delete, bulk reply/delete (ids are read from `$_POST` at `admin_post_pothik_bulk_inquiries` priority 1 because the bulk hook carries none), receipt save, settings save, demo data loaded/removed (tombstones for rows that vanished).
* **First pull** lists records Kafela already holds (`EnumerableAdapter`).
* **Undo** restores deleted packages (same position, same id), inquiries (same table id and uuid) and receipts in place.
* **Filter `pdock_kafela_adapter_args( array $args, string $entity )`**: `label`, `group`, `enabled` (set `false` to skip a bundled adapter, so Kafela can ship its own under the same key), `can` (optional `callable( $action ): bool` replacing the permission check). Kafela can later register the same classes itself and turn the bundled copy off with this filter.

---

## `kafela_package`

Every field is a key of the package array inside option `pothik_packages`. Writes use the same sanitizer limits as `PackageStore::from_post()` / `ready()`; the option is written like `PackageStore::put()` (re-indexed list, autoload off) and Kafela's in-memory copy is dropped afterwards.

| Schema field | Type | Storage key | Rule |
|---|---|---|---|
| `package_id` | string, **create_only** | `id` | `sanitize_key`, 1–50 of `a-z 0-9 _ -`, never `sample` (its row is wiped by "Remove demo"). Generated like `fresh_id()` (8 chars) when omitted. Duplicate → `conflict` `reason: unique`, `field: package_id`, `remote_id` of the owner. |
| `position` | int, **readonly** | array index + 1 | Order matters in Kafela (first row is "the" package). New rows are appended. |
| `status` | enum `draft`\|`published` | `status` | default `draft` |
| `title` | string, **required** | `title` | 120 |
| `kicker`, `route`, `group_note` | string | same | 80 |
| `days`, `nights_makkah`, `nights_madinah` | int 0–365 | same | rejected outside the range (Kafela clamps silently) |
| `departure` | string | `departure` | `YYYY-MM` or empty |
| `is_ramadan` | bool | `is_ramadan` | |
| `makkah_hotel`, `madinah_hotel` | string | same | 120 |
| `makkah_similar`, `madinah_similar`, `makkah_stars`, `madinah_stars` | string | same | 40 |
| `makkah_meters`, `madinah_meters` | int ≥ 0 | same | |
| `prices` | object of 7 ints | `prices.quad/triple/double/single/child_bed/child_nobed/infant` | ≥ 0; on update only the sent tiers change, missing tiers on create are 0 |
| `food_note` | string | `food_note` | 160 |
| `inclusions`, `exclusions`, `itinerary` | list | same | ≤ 45 items, each ≤ 180 |
| `docs` | string | `docs` | 200 |
| `cancel` | text | `cancel` | 400 |
| `agency`, `phone`, `whatsapp` | string | same | 80 / 40 / 20 (per-package override of the settings) |
| `demo` | bool, **readonly** | `demo` | always `false` for synced rows |
| `bn` | object | `bn.<key>` | Bangla overlay for `agency kicker title route group_note makkah_* madinah_* food_note docs cancel` (strings) and `inclusions exclusions itinerary` (lists). Written explicitly (never through `handle_save`, which depends on the request language). An empty value removes the key, same as Kafela's fallback to English. |

## `kafela_inquiry`

Created through `InquiryRepository::insert()`, deleted through `InquiryRepository::delete_by_id()`; never through the public handler (no nonce, honeypot, time trap, rate limit, mail, redirect). `uuid` of the row = the client uuid of the op.

| Schema field | Type | Column | Rule |
|---|---|---|---|
| `uuid` | string, **readonly** | `uuid` | equals the Pressdock uuid for rows created by sync; rows made by Kafela keep their own (when it is a valid uuid). Uniqueness is enforced here (Kafela's index is not unique): duplicate → `conflict` `reason: unique`, `field: uuid`. |
| `created_gmt` | datetime, **create_only** | `created_gmt` | UTC. Defaults to now. |
| `name` | string, **required** | `name` | 80 |
| `phone` | string, **required** | `phone` | 40 |
| `whatsapp`, `city` | string | same | 40 |
| `share` | enum `quad`\|`triple`\|`double`\|`single` | `share` | Bangla labels accepted (`I18n::canonical`). |
| `adults` / `children` / `infants` | int | same | 1–30 / 0–20 / 0–10 (rejected outside, Kafela clamps) |
| `trip` | enum `group`\|`private` | `trip` | |
| `note` | text | `note` | 300 |
| `package_id` | **relation** → `kafela_package` | `package_id` (Kafela string id) | remote id or uuid of a package; `0` clears. The package must exist. Pull returns the package's object id (0 when the stored string maps to nothing). Ops in a batch are ordered so the package is created first. |
| `package_slug` | string, **readonly** | `package_id` | raw Kafela package string, lossless even when dangling |
| `package_title` | string, **readonly** | `package_title` | snapshot taken from the package at create, refreshed only when `package_id` changes |
| `status` | enum `new`\|`waiting`\|`replied`, **readonly** | `status` | mirror of `replied`, written together with it like `set_replied()` |
| `replied` | bool | `replied` | the authoritative flag |
| `demo` | bool, **readonly** | `demo` | |
| `ref` | string | `ref` | upper-cased, must match `Referral::sanitize` (2–10 `A-Z0-9`) or be empty (stored as NULL) |

`status`'s `new` is only ever set on insert; the table `id` is never written.

## `kafela_receipt`

One record, id `1`. Same sanitizers as `ReceiptStore::handle_save()`; `demo` forced `false` on every write. **Create** when a receipt exists → `conflict` `reason: unique` with the existing record. **Delete** removes the option. There is no receipt number or counter in the free plugin.

| Schema field | Type | Storage key | Rule |
|---|---|---|---|
| `name` | string | `name` | 80 |
| `amount` | string of digits | `amount` | `^\d{1,15}$`. `"40,000"` is **rejected** (Kafela's `absint` would store 40). A JSON number is accepted and stored as its digit string. |
| `channel` | enum `bkash`\|`nagad`\|`rocket`\|`bank`\|`cash` | `channel` | Bangla labels accepted |
| `trx` | string | `trx` | 40 |
| `date` | string | `date` | 20, format not checked (as in Kafela; the admin screen writes `Y-m-d`) |
| `note` | text | `note` | 240 |
| `demo` | bool, **readonly** | `demo` | |

## `kafela_settings`

One record, id `1`, **always present** (Kafela merges defaults). `create` → `conflict` `reason: unique`; `delete` is not allowed (`pdock_forbidden`). Kafela's `Settings::sanitize()` rebuilds every key from its input and resets omitted ones, so an update merges the change into the **complete current settings** and passes that whole object to `Settings::sanitize()`.

| Schema field | Type | Storage key | Rule |
|---|---|---|---|
| `agency`, `agency_bn`, `phone`, `whatsapp`, `license` | string | same | text |
| `logo_id` | media | `logo_id` | `0` or an existing attachment id |
| `logo_url` | string | `logo_url` | valid URL or empty |
| `notification_emails` | string | `notification_emails` | each address must be valid; Kafela normalizes the list to `a, b` |
| `country_code` | string | `country_code` | 1–5 digits (Kafela would strip `+` silently, the adapter refuses it) |
| `rate_limit` | int | `rate_limit` | 1–100 |
| `delete_on_uninstall` | bool, **readonly** | `delete_on_uninstall` | the "delete everything on uninstall" switch is never writable by sync; unrelated updates preserve its current value |

## Known limits

* Package integer ids come from `pdock_kafela_package_ids`. If that option is deleted, devices holding old `remote_id`s must re-sync from scratch.
* Package edits are a read-modify-write of one option, the same race Kafela itself has.
* Pro hooks that listen to the re-fired events run on sync writes; switch a hook off with `pdock_kafela_fire_hooks` if that is unwanted.
* Public-page packages, the `pothik_page_id` page and per-user UI options are intentionally not syncable.

---

## Pro entities

Permissions use Pro's access matrix (`PothikPro\Access`, area `ledger`). Staff limited to some groups are **refused**: a pull cannot be filtered per group, so letting them in would show every group. Pro fires **no hooks** for ledger or payment changes, so both adapters implement the optional `refresh()` (called by `GET /pull`): a `TableScanner` keeps a hash per row and reports rows added, changed or removed in Pro's own screens (at most every 10 s; the first run only stores hashes, existing rows come through the baseline). Writes made through sync are marked so they are not reported twice.

### `kafela_ledger` (read only)

`can()` is false for create/update/delete, so `/push` answers `pdock_forbidden`. Every field is readonly: `ledger_code` (`id`, string like `l-12-ab3dEf`), `departure_id`, `pilgrim_id`, `name`, `due`, `paid` (derived from payments by Pro), `channel`, `trx`, `note`, `usd_note`, `sar_note`, `sort_order`. Table `pothik_ledger`.

### `kafela_payment`

A payment is an append-only money record in Pro. Rules kept by the adapter:

* **create** goes through `PothikPro\Payments::add()`: the **receipt number is allocated on the server** (`ReceiptNo::next()`, `PREFIX-YEAR-0001`), the ledger `paid` is recalculated, commission is evaluated and the activity log is written, as in Pro's screen. A client can never send `receipt_no`.
* **update** can only void. Everything writable at create is `create_only`, so editing amount, channel, date, note … is refused (`pdock_readonly_field`).
* **delete** is not offered (`pdock_forbidden`). Undo of a create answers with an error and leaves the payment alone; a receipt number is never given back.
* Pre-checks replicate `Payments::add()` with proper codes: ledger must exist (`pdock_unresolved_relation`), amount ≥ 1, date not more than a day ahead, amount above the balance → `pdock_over_balance` unless `allow_overpay` is true (Pro's `confirm`).

| Schema field | Type | Column | Rule |
|---|---|---|---|
| `ledger_id` | relation → `kafela_ledger`, **required**, create_only | `ledger_id` (string) | remote id or uuid of the ledger row |
| `departure_id`, `pilgrim_id` | int, readonly | same | taken from the ledger row by Pro |
| `paid_on` | date, create_only | `paid_on` | defaults to today |
| `amount` | int ≥ 1, **required**, create_only | `amount` | BDT |
| `channel` | enum `bkash`\|`nagad`\|`rocket`\|`bank`\|`cash`, **required**, create_only | `channel` | |
| `trx` | string 40, create_only | `trx` | upper-cased by Pro |
| `note` | string 255, create_only | `note` | |
| `allow_overpay` | bool, create_only | not stored | Pro's `confirm`; always false when read |
| `receipt_no` | string, readonly | `receipt_no` | server allocated, unique |
| `is_opening`, `received_by`, `received_by_name`, `created_at` | readonly | same | set by Pro (`received_by` = the user behind the app password) |
| `voided` | bool | derived from `voided_at` | send `true` on update to void; cannot be reverted |
| `void_reason` | string 255 | `void_reason` | ≥ 5 characters, only with `voided = true` |
| `voided_at`, `voided_by` | readonly | same | set by Pro |

Voiding needs `Payments::can_void()` (administrators, Pro managers and accountants, or the `pothik_pro_can_void` filter); an opening-balance payment only by `manage_options`. Extra filter arguments: `can_void` (`callable( int $id ): bool`) in `pdock_kafela_adapter_args` for `kafela_payment`.

### `kafela_departure` and `kafela_pilgrim` (read only)

Same base (`ReadOnlyTableAdapter`): integer table id as object id, every field readonly, create/update/delete answer `pdock_forbidden`, changes made in Pro's screens detected before each pull, group-limited staff refused.

| Entity | Table | Pro area | Relations |
|---|---|---|---|
| `kafela_departure` | `pothik_departures` | `groups` | `package_id` → `kafela_package` (Pro stores the Kafela string; the record shows the package object id and keeps the raw string in `package_slug`) |
| `kafela_pilgrim` | `pothik_pilgrims` | `pilgrims` | `departure_id` → `kafela_departure` |

Fields are the table columns (see `Schema.php`); `bn` (JSON text in Pro) is returned as an object. Dates are the strings Pro stores (`YYYY-MM-DD`). `kafela_pilgrim` includes `passport`, `dob`, `expires`: only staff with Pro's `pilgrims` view access and all-group scope get them. `kafela_ledger.departure_id/pilgrim_id` and `kafela_payment.departure_id/pilgrim_id` are relations to these two entities (same integer ids).
