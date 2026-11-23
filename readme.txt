=== Pressdock Connector – Offline Data Sync ===
Contributors: itsmanzur
Tags: sync, rest-api, offline, desktop app, application passwords
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A secure REST sync API for the Pressdock desktop app: pull, create, edit and delete plugin data offline, then sync it with conflict detection and undo.

== Description ==

Pressdock Connector turns supported WordPress data into a sync-ready REST API. The Pressdock desktop app downloads your data, lets you work offline, and pushes your changes back safely.

**How it works**

* Plugins register "entities" (a post type, a custom table, a settings group) through an adapter.
* Every change, including edits made in the normal WordPress admin, is written to a change log. The app asks for everything that changed since its last cursor.
* Every record has a revision number. If a record changed on the server while you were offline, the push is not applied. You get a conflict with the current server version instead of silent data loss.
* Every batch is idempotent. A retried upload never applies twice.
* A snapshot is taken before each update or delete, so an administrator can undo a whole batch from Pressdock → Devices & activity.

**Built for safety**

* Authentication uses WordPress Application Passwords. The API refuses plain HTTP, except on local development hosts when you switch that on.
* Every request is checked against the normal WordPress capabilities of the signed-in user.
* Side effects such as notification emails can be muted while a sync runs (`Pressdock\Context::is_syncing()`).
* No external requests. No tracking. No ads. No locked features.

**Included**

* A demo adapter for core posts (title, content, status).
* Base classes for post-type entities and custom-table entities, so other plugins can add an adapter in a few lines.

**For developers**

Register entities with the `pdock_register_entities` filter:

`add_filter( 'pdock_register_entities', function ( $adapters ) { $adapters[] = new My_Adapter(); return $adapters; } );`

An adapter implements `Pressdock\Contracts\EntityAdapter` or extends `Pressdock\Adapters\AbstractPostAdapter` / `Pressdock\Adapters\AbstractTableAdapter`. Call `Pressdock\Tracker::touch( $entity, $id, $action )` after your own writes. The full REST contract is in `docs/api-contract.md` in the source repository.

== Installation ==

1. Upload the plugin and activate it.
2. Go to Users → Profile and create an Application Password named "Pressdock".
3. Open the Pressdock desktop app (https://thereadscope.com/pressdock) and enter your site URL, your username and the Application Password.
4. Review which entities are shared under Pressdock → Data sources.

== Frequently Asked Questions ==

= Does it send my data anywhere? =

No. The plugin makes no outgoing requests. Only a client you authenticate with an Application Password can read or write data.

= Why does it say my site needs HTTPS? =

Application Passwords travel in an HTTP header, so the API refuses unencrypted connections. On a local development site you can enable "Allow HTTP on local development hosts" under Pressdock → Settings.

= What happens when two people edit the same record? =

The second push is not applied. The app receives a conflict that contains the current server version, so you can decide which one to keep.

= Can I undo a sync? =

Yes. Administrators can undo a whole batch from Pressdock → Devices & activity while its snapshots are kept (14 days by default).

= What is removed when I delete the plugin? =

Nothing, unless you tick "Delete all Pressdock tables and settings when the plugin is deleted" first.

== Changelog ==

= 1.0.0 =
* First release: adapter registry, change log, pull, push with conflict detection, idempotent batches, undo, media upload, admin page, demo post adapter.

== Upgrade Notice ==

= 1.0.0 =
First release.
