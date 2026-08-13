=== CatCode Order Sync with KeepinCRM for WooCommerce ===
Contributors: catcodestudio
Tags: woocommerce, crm, orders, sync, ukraine
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends every WooCommerce order to KeepinCRM as an agreement: at checkout or on a status change, with de-duplication, retries and an event log.

== Description ==

The plugin connects WooCommerce to KeepinCRM through the public KeepinCRM REST API. Every new order in your shop is created in KeepinCRM as an agreement with a lead client, the line items and a comment carrying the delivery address, the payment method and the paid/unpaid mark.

= Features =

* Sends the order right after checkout (classic checkout and Checkout Blocks) or when it enters one of the statuses you choose
* De-duplication: the KeepinCRM agreement id is stored in the order meta, and a database-level lock keeps two parallel hooks from creating the order twice
* The customer is created as a lead (full name, e-mail, phone); line items become agreement jobs
* Optional routing: funnel, stage, source and responsible-user ids
* Payment mark: for orders paid online a "✅ Paid online" line is added to the agreement comment (optional)
* Automatic retries on failure: 3 attempts with 5 min / 30 min / 2 h pauses (WP-Cron)
* Metabox on the order screen: sync status, KeepinCRM ID, "Resend" button (an order already in the CRM is updated with PATCH instead of duplicated)
* "Test connection" button on the settings page
* Event log with the last sync attempts, their HTTP status and error text
* The API token is encrypted at rest (libsodium, with an HMAC fallback)
* Buyer phone numbers are normalised to E.164 (+380…)
* HPOS-compatible (custom order tables) — order data is read and written through the WooCommerce CRUD only
* `cc_keepincrm_order_payload` filter and `cc_keepincrm_order_sent` action for site-specific tweaks

= Requirements =

* WooCommerce 6.0 or newer
* PHP 7.4 or newer
* A KeepinCRM API token. Note that KeepinCRM only exposes its API on a paid plan — on the free plan every request comes back as `402 Недоступно на безкоштовному тарифі`.

= How it works =

1. A customer places an order in your shop.
2. The plugin builds the agreement (client, line items, total, comment) and sends `POST /agreements` to `https://api.keepincrm.com/v1` with your token in the `X-Auth-Token` header.
3. KeepinCRM answers `201` with `{"id":N}` — the id is stored in the order meta as `_cc_keepincrm_agreement_id`.
4. If KeepinCRM is unreachable the attempt is repeated automatically (up to 3 times).
5. Your manager sees the status and the id in the order metabox and in the log.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip from Plugins → Add New → Upload.
2. Activate the plugin.
3. Go to WooCommerce → KeepinCRM Sync and paste your API token (KeepinCRM account → Settings → Integrations → API).
4. Press "Test connection", pick the trigger statuses and save.

== Frequently Asked Questions ==

= Where do I get the API token? =

In your KeepinCRM account: Settings → Integrations → API. Only the account owner can create a token. The token is kept in the WordPress database in encrypted form.

= Why does the connection test say "Недоступно на безкоштовному тарифі"? =

That answer (HTTP 402) comes from KeepinCRM, not from the plugin: the API is a paid-plan feature. Upgrade the plan or start a trial in your KeepinCRM account and test again.

= Can the same order end up in the CRM twice? =

The plugin stores the KeepinCRM agreement id in the order meta and takes a database lock before sending, so parallel hooks cannot both create it. The one case it cannot rule out is a request that never gets an answer (timeout): KeepinCRM has no external-id field on agreements, so the plugin cannot ask whether the order landed. Such an attempt is marked in the order metabox and in the log as "no_response", so you can check the CRM before resending.

= What does the "Resend" button do? =

If the order is not in KeepinCRM yet, it creates the agreement (`POST /agreements`). If it is already there, it updates it (`PATCH /agreements/{id}`) with the current title, total, currency and comment — the line items and the client are not resent, because KeepinCRM would attach them a second time.

= What happens when I delete the plugin? =

Deleting (not just deactivating) removes the plugin options and the log table. The KeepinCRM ids stored on the orders are kept on purpose: they are what stops a reinstall from creating a second copy of an old order in the CRM.

== External services ==

This plugin connects to the KeepinCRM API (`https://api.keepincrm.com/v1`), a third-party CRM service. The connection is the whole purpose of the plugin: it creates and updates your WooCommerce orders inside your own KeepinCRM account.

What is sent and when:

* `POST /agreements` — when a customer places an order, or when the order enters one of the statuses you selected as a trigger. Retried up to 3 times if KeepinCRM is unavailable.
* `PATCH /agreements/{id}` — when you press "Resend" in the order metabox for an order that already exists in KeepinCRM.
* `GET /clients/statuses` — only when you press "Test connection" on the settings page.

Every request carries your KeepinCRM API token in the `X-Auth-Token` header. The order data sent is: the WooCommerce order number and the shop host (as the agreement title), the order total and currency, the line items (name, SKU, price, quantity), the shipping method and its cost, the delivery city and address, the payment method title and whether the order is paid, the customer note, and the buyer's full name, e-mail address and phone number as entered at checkout.

No data is sent to any other service, and nothing about your visitors, site or administrators is sent beyond the order data listed above. Nothing is sent until you enter your KeepinCRM API token: without a token the plugin makes no external requests.

This service is provided by KeepinCRM: [contract offer](https://keepincrm.com/contract-offer), [privacy policy](https://keepincrm.com/privacy).

== Screenshots ==

1. Settings: API token, trigger statuses, CRM routing
2. Sync log with the last attempts
3. KeepinCRM metabox on the WooCommerce order with the resend button

== Changelog ==

= 0.2.0 =
* Renamed the plugin to "CatCode Order Sync with KeepinCRM for WooCommerce" (new slug and text domain). Options, order meta and the log table keep their names, so nothing has to be migrated.
* Fixed: the guard against a double send was a transient, i.e. a read-then-write — two parallel hooks (checkout + status change + payment callback) could both pass it and create the order in KeepinCRM twice. It is now a database-level lock, and the order is re-read past the HPOS order cache before the final check.
* Fixed: uninstalling the plugin left the sync log table in the database forever. It is dropped now; the per-order KeepinCRM ids are kept on purpose as the anti-duplicate guard.
* Added: an attempt that gets no HTTP response at all (timeout) is recorded as `no_response` and flagged in the order metabox — KeepinCRM has no external id on agreements, so a duplicate cannot be ruled out automatically.
* Admin styles are now enqueued through a registered handle instead of raw `<style>` output.
* Interface strings are in English with a Ukrainian translation shipped in `/languages`.
* Documentation fixes: the resend button uses PATCH (not PUT), the shipping option describes what it actually sends, and the requirements say WooCommerce 6.0 (the plugin never needed 7.0).

= 0.1.0 =
* First release: orders exported as agreements (`POST /agreements`), retries, metabox, log, connection test.

== Upgrade Notice ==

= 0.2.0 =
Fixes a race that could create the same order in KeepinCRM twice, and stops leaving the log table behind on uninstall.
