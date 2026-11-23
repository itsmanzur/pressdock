# Pressdock PWA client

Offline-first web app for the Pressdock Connector API. No build step: plain ES modules, IndexedDB, a service worker.

## Run

Serve this folder over HTTP(S) (the service worker needs `localhost` or HTTPS):

```
php -S 127.0.0.1:8090 -t client
```

Open it, enter the site address, a WordPress username and an **Application Password** (Users → Profile on the site). The site API must be reachable over HTTPS (or allowed on a local host, see `docs/api-contract.md` section 1). The API answers cross-origin requests with WordPress's standard REST CORS headers.

## What it does

- Reads entities and schemas from `/handshake` and `/entities`; forms are built from the schema (strings, numbers, dates, enums, relations, lists, nested objects). Read-only fields are shown but never sent.
- Initial and incremental `/pull` into IndexedDB (cursor stored).
- Edits are saved on the device and queued as ops (`create`, `update` with `base_revision`, `delete`). Sync sends batches of up to 100 ops, creates first. An interrupted batch is re-sent with the same `batch_id` and `op_id`s.
- Conflicts show *Mine* next to *Server*: **Keep mine** re-sends on top of the server revision, **Use server** drops the local edit. A deleted-on-server record offers the same choice.
- Validation errors from the server are shown on the record; fix and save to re-queue.
- ⋯ → Sync history lists recent batches with **Undo** (administrators only, calls `/undo`).

## Notes

- The Application Password is kept in `localStorage` on this device. Sign out wipes it and the local data.
- Singleton entities (`kafela_receipt`, `kafela_settings`) hide the "new" button once a record exists.
- Not covered yet: media upload UI (media fields take an attachment id), push notifications, multi-site.
