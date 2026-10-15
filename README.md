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

Cart snapshots expose a boolean `customer_is_guest` derived from native session
identity, not billing email or customer-supplied fields. Session keys remain
private and cart references remain unchanged across guest-to-account migration.
Update Cloud's identity projection before installing this connector change;
older snapshots remain conservatively classified as guests until refreshed.

## Checkout evidence (1.0.11-rc.1 validation candidate)

This prerelease is for controlled validation, not the default stable download.
The stable release remains v1.0.10 until the complete real-cart, email and order
journeys have been verified. Keep a backup before installing this candidate.

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

The current installable connector is **v1.0.10**:

- [Download the installable ZIP](https://github.com/pisob/neurocheckout-connector-woocommerce/releases/download/v1.0.10/neurocheckout-connector-woocommerce-1.0.10.zip).
- [Official release and verification files](https://github.com/pisob/neurocheckout-connector-woocommerce/releases/tag/v1.0.10).

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
