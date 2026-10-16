# NeuroCheckout Connector for WooCommerce

## Product details in Community emails

Community's read-only snapshot requires PHP `pdo_mysql` (in addition to
WordPress's `mysqli`). Enable it in the web PHP runtime, not only in CLI.
The connector reports a missing driver explicitly and shows an admin warning;
an API connectivity test does not prove that local snapshots can be read.

Variable products use WooCommerce's native multiple `_price` rows. Snapshots
retain the first scalar value in `native_meta._price` for compatibility and
preserve all values, in native order and precision, in `native_price_values`.
They never change catalog prices or treat a parent price as a cart-line total.

Product snapshots include the native WordPress product permalink and thumbnail
URL, including configured media filters/CDNs. The thumbnail attachment ID is
resolved separately from the product ID. Missing thumbnails remain explicitly
absent; the connector never guesses an image URL. Update the plugin in place
and let Community synchronize before testing a new email. Previously sent
emails are unchanged.

## Customer identity in Community

### Recommendation consent (candidate, not released)

Customers can explicitly allow or withdraw product-recommendation emails in
**My account → Account details**. The preference is optional and initially
unchecked; installing/upgrading never opts in existing customers. The native
account form and a separate user/store/email-bound nonce protect the change.
Consent is per site, not network-wide. Changing an account email requires a new
choice on a fresh form; billing emails do not inherit permission. Existing
third-party newsletter preferences are not automatically imported.

Signed local snapshots include the current account identity and an explicit
granted/denied/unknown preference. Unknown or unavailable is not permission.
Posts and HPOS orders can still identify their account after the cart session
expires, without changing payment evidence, cart references or billing data.
Cloud must recheck native consent, unsubscribe/suppression and all sending rules
before delivery. This change alone does not activate recommendation campaigns.
Product snapshots also export the actual creation date for novelty ranking.

Validation: `php tests/marketing_consent_test.php`, the full isolated validator
and disposable MariaDB source/recovery tests. These tests do not certify a real
recommendation delivery. No live customer preference is changed by tests.

### Complete account history (candidate, not released)

With matching Community/Cloud support, the existing signed source route can
read a registered account's native order history independently of surviving
cart sessions. Only the authoritative posts or HPOS store is read; guest orders
sharing an email and orders from another site/account are not borrowed. The
native account ID is included in the encrypted-local account identity, separately
from billing data. Older snapshots without that ID cannot authorize this read.

Each read uses its own repeatable-read, READ ONLY transaction, verifies InnoDB
storage under metadata locks, and includes the current site-specific consent
without changing it. Order totals, paid dates and native refunds remain separate
facts; an order status is not proof of paid revenue. Native decimal precision,
product/variation IDs and original/refund line relationships are preserved.
The result stays transient. Existing HMAC, TLS, nonce and rate protections apply;
the response binds the exact request and must be fresh. Unsupported storage,
ambiguous data or more than 128 orders, 128 refunds, 512 lines or 64,000 bytes
returns unavailable, never a truncated successful history. SQL statements are
limited to one second and the complete native read to three cooperative seconds.

Checks: `php tests/customer_history_protocol_test.php` (65 assertions) and
`php tests/customer_history_sql_test.php /tmp/nc-source-mariadb.XXXX/mariadb.sock`
(58 assertions), plus existing source/recovery tests. A read-only candidate bridge
was compared with native WooCommerce 10.7.0 order services and the Community
transport. It did not install the candidate, change customer consent, send an
email or test the live HTTPS route. This capability is not an autonomous campaign
sender; profile aggregation and final sending checks are still required.

Cart snapshots expose a boolean `customer_is_guest` derived from native session
identity, not billing email or customer-supplied fields. Session keys remain
private and cart references remain unchanged across guest-to-account migration.
Update Cloud's identity projection before installing this connector change;
older snapshots remain conservatively classified as guests until refreshed.

## Checkout evidence (1.0.11)

This release preserves restored Community cart references using a
server-only, shop-scoped proof, carried from the native recovery session to
order metadata in both posts and HPOS storage. A cart ID prefix or browser
cookie alone is not trusted. Old orders are not rewritten. Native display
precision is exported separately from stored amounts for matching email prices
(requires the coordinated Cloud update).

The reviewed candidate completed a real cart-to-email-to-native-checkout journey,
including Gmail logo/product rendering, an offline unpaid order, Cloud attribution,
and encrypted Community synchronization. This validates the connector recovery
path, not payment collection or independent product-recommendation campaigns.
Recommendation engine improvements are a separate Cloud change. Keep a backup
before upgrading; previous signed releases remain available for rollback.

Checkout placement time is recorded separately from the order draft's creation
time. Repeated checkout hooks preserve both the original cart link and placement
timestamp, including when the session was cleared or now contains another cart.
Older orders without placement metadata keep an explicitly labelled creation-time
fallback. A checkout/order event is attribution evidence, not proof of payment.

Regression checks include the native WordPress 6.8.3 / WooCommerce 10.7.0
bootstrap with synthetic orders in both posts and HPOS storage. These isolated
hook tests do not replace a browser checkout and real mailbox verification.

This is the official open-source WooCommerce connector for NeuroCheckout. It
sends authenticated store events to NeuroCheckout Cloud and supports signed,
read-only product and cart snapshots for the encrypted local vault in
NeuroCheckout Community.

## Availability

The current installable connector is **v1.0.11**:

- [Download the installable ZIP](https://github.com/pisob/neurocheckout-connector-woocommerce/releases/download/v1.0.11/neurocheckout-connector-woocommerce-1.0.11.zip).
- [Official release and verification files](https://github.com/pisob/neurocheckout-connector-woocommerce/releases/tag/v1.0.11).

Do not use **Code → Download ZIP** as an installation package. Official packages
include version information, checksums and signatures needed to verify their
origin and integrity.

## Install an official package

1. Back up the WordPress files and database.
2. Download the connector package and all verification files from the same
   official release.
3. Verify the documented signing-key fingerprint, detached signature and
   SHA-256 checksum.
4. In WordPress, open **Plugins → Add New → Upload Plugin**.
5. Upload the official connector ZIP without extracting it, then activate the
   plugin.
6. Open the connector settings and enter the API endpoint, store-specific
   connector key and external store ID displayed in your NeuroCheckout account.
7. Save the configuration and run the API connection test.
8. Keep NeuroCheckout Community online when using encrypted local product and
   cart storage.
9. Open **Execution** and verify the background scheduler. Automatic mode uses
   WordPress cron: it needs site traffic, working loopback requests, and either
   enabled WP-Cron or a server task that regularly invokes WordPress cron.
   If your host disables WP-Cron or the store has little traffic, ask the host to
   configure a reliable server schedule. Alternatively select the connector's
   server mode and configure the command shown there for the WordPress system
   user. The script reads the existing configuration locally; do not put an API
   key in the crontab. Never enable two connector runners at once.
10. Add a test cart and visit a product. After the configured synchronization
    interval, verify **Monitoring** for successful processing and check the cart
    and journey signals in Community. **Test API** verifies connectivity, not
    ongoing background processing. Investigate missing/overdue task warnings
    before relying on recovery emails. The store, scheduler and network must
    remain available; no plugin can run scheduled work while the host is offline.

Never publish connector keys, customer records, cart contents or configuration
exports in an issue or pull request. Back up the store before uninstalling or
upgrading the plugin.

## Updates

The plugin checks its version through the existing authenticated Cloud
connection at most once every 24 hours when an authorized administrator uses
WordPress. When an update is available, WordPress displays the exact official
GitHub release. The Cloud cannot download or install code on the store.

Back up WordPress, download the official plugin ZIP, then use **Plugins → Add
New → Upload Plugin** and approve replacement of the installed plugin. Do not
delete or uninstall the existing plugin first. WordPress replaces its files
while retaining NeuroCheckout options and database tables.

## Development

The WordPress plugin source is located in `neurocheckout-connector/`.

```bash
python3 tools/validate.py
```

For SQL integration, use a disposable MariaDB fixture socket (never your store
database) and run `php tests/recovery_sql_test.php /tmp/nc-source-mariadb.XXXX/mariadb.sock`
and `php neurocheckout-connector/tests/community_source_snapshot_test.php /tmp/nc-source-mariadb.XXXX/mariadb.sock`.
Recovery tests cover failed inserts, lost/expired claims, ordering before login
and cart mutation, and rejection of unsigned additions to older signed links.

Automated checks use synthetic data and do not replace platform-level tests for
installation, upgrades, checkout events, key rotation and uninstallation.

## Contributions and releases

Submit changes through pull requests. Protected branches require automated
validation and maintainer review. External contributions cannot publish official
releases or access NeuroCheckout credentials.

Official releases are created from reviewed commits and include a signed tag,
SHA-256 checksums and a detached signature. See
[CONTRIBUTING.md](CONTRIBUTING.md), [RELEASING.md](RELEASING.md) and
[SECURITY.md](SECURITY.md).

## License and trademark

The connector source is licensed under GPL-2.0-or-later. The NeuroCheckout name
and logos remain protected. Modified distributions must not claim to be official
NeuroCheckout releases.
