# PayCrypto.Me for WooCommerce — Context for Agents

## Context and guides

- [docs/RELEASE.md](docs/RELEASE.md) — how to build a release and submit to WordPress.org (SVN or direct upload); SVN flow battle-tested against the real first push (2026-08-08), including recovery from a transient WP.org server-side commit error.
- [docs/TRANSLATION.md](docs/TRANSLATION.md) — translation commands and status (7 locales, 100%).
- [docs/ADD-NEW-GATEWAY.md](docs/ADD-NEW-GATEWAY.md) — checklist to implement a third gateway.
- [docs/SCHEMA-UPGRADE-AND-STATIC-RECORDS.md](docs/SCHEMA-UPGRADE-AND-STATIC-RECORDS.md) — **approved plan, not started.** Records fixed-address on-chain payments in the payments table, and hardens the schema-upgrade mechanism (what `dbDelta()` does and does not do — measured, not assumed — plus a MySQL-backed test trail). Read it before touching anything under `DbInstaller`, the `*GatewayActivate` classes or `DB_VERSION`.
- [docs/CRYPTO-DEPENDENCIES.md](docs/CRYPTO-DEPENDENCIES.md) — **approved plan, not started.** Why the two `lucas-rosa95/*` forks exist, why they no longer earn their keep (measured), and the move back to official `bitwasp/*` packages. Read it before touching the crypto dependencies in `src/trunk/composer.json`.
- [docs/PREMIUM-ADDON.md](docs/PREMIUM-ADDON.md) — approved implementation plan for the separate premium add-on plugin (not started yet). See "Premium add-on" section below for the base's own scope boundaries and extension points.

**Status:** v0.1.0 **live on WordPress.org** since 2026-08-08. Production-hardening and the WordPress.org review round are both complete and verified (334 tests, 7 locales at 100%, Plugin Check clean, manual smoke test passed). Premium features (webhook/fiat→sats) are reserved for the separate add-on above — see "Premium add-on" section below.

---

## What this project is

WordPress plugin (GPL-3.0-or-later) that adds Bitcoin payment gateways to WooCommerce. Non-custodial: the store owner controls the keys. Version: **0.1.0**. Author: PayCrypto.Me (contact@paycrypto.me).

**Two registered gateways, both fully functional:**
- `paycrypto_me` — Bitcoin On-Chain (HD derivation from xPub/ypub/zpub, mainnet + testnet).
- `paycrypto_me_lightning` — Bitcoin Lightning Network (BTCPay Server or lnd REST): invoice creation, resolution, persistence, order-details rendering.

Async webhook status updates and fiat→sats conversion are deliberately out of scope for this free plugin — see "Premium add-on" below.

---

## Directory layout

```
paycrypto-me-for-woocommerce/
├── CLAUDE.md                     ← this file
├── src/trunk/                    ← plugin root (everything that ships)
│   ├── paycrypto-me-for-woocommerce.php   ← entrypoint / plugin header
│   ├── includes/                 ← all PHP logic
│   │   ├── abstract-class-wc-gateway-paycrypto-me.php
│   │   ├── class-wc-gateway-paycrypto-me.php         (On-Chain gateway)
│   │   ├── class-wc-gateway-paycrypto-me-lightning.php (Lightning gateway)
│   │   ├── blocks/               ← WooCommerce Gutenberg block classes + JS sources
│   │   │   ├── js/paycrypto_me-blocks.js              ← JS SOURCE (edit here)
│   │   │   └── js/paycrypto_me_lightning-blocks.js    ← JS SOURCE (edit here)
│   │   ├── processors/           ← payment processor classes
│   │   ├── services/             ← BitcoinAddressService, QrCodeService, DBStatementsService, PaymentDisplayDataBuilder, invoice services
│   │   ├── strategies/           ← ProcessorStrategiesFactory (composition root: wires processors + their services via DI)
│   │   ├── validators/           ← LightningConfigValidator
│   │   └── utils/                 ← AssetManager, OrderGatewayMatcher, EnvironmentRequirements
│   ├── assets/                   ← compiled/static assets (do NOT edit JS/CSS here directly)
│   │   └── blocks/               ← webpack output from includes/blocks/js/
│   ├── templates/                ← WooCommerce PHP templates (checkout, order-details)
│   ├── exceptions/               ← PayCryptoMeException, PayCryptoMePaymentException
│   ├── tests/                    ← PHPUnit (unit-only, custom WP shims, no real WP)
│   ├── uninstall.php             ← deletes settings options (incl. secrets); deliberately KEEPS the 4 tables (payment records)
│   ├── package.json              ← npm scripts for JS build
│   ├── webpack.config.js
│   └── composer.json
├── scripts/                      ← shell scripts (build-translations, release, smoke-minimal-host, etc.)
├── docs/
└── docker-compose.yml            ← dev stack (`wordpress`+`wp_db`+`cron`) + ephemeral `release` build service (profile `release`)
```

**Critical rule:** Never edit files under `src/trunk/assets/blocks/` directly — they are webpack output. Edit the JS sources in `src/trunk/includes/blocks/js/` and run `npm run build`.

---

## Architecture

### PHP class hierarchy

```
WC_Payment_Gateway  (WooCommerce core)
  └── Abstract_WC_Gateway_PayCryptoMe
        ├── WC_Gateway_PayCryptoMe          (id = paycrypto_me)
        └── WC_Gateway_PayCryptoMe_Lightning (id = paycrypto_me_lightning)
```

Namespace: `PayCryptoMe\WooCommerce`. Autoloaded via Composer classmap from `includes/` and `exceptions/`.

### Payment flow (On-Chain)

1. `WC_Gateway_PayCryptoMe::process_payment($order_id)` → `PaymentProcessor::process_payment()`
2. `PaymentProcessor` validates order + gateway (via `PaymentOrderValidator`), fires hooks, calls `ProcessorStrategiesFactory::create($gateway)`
3. Factory maps `paycrypto_me` → `BitcoinProcessorStrategiesFactory`, which is the **composition root**: builds `new BitcoinPaymentProcessor($gateway, new BitcoinAddressService(), new PayCryptoMeDBStatementsService())`. The processor's constructor params are nullable with an internal `new Service()` fallback, so `new BitcoinPaymentProcessor($gateway)` still works — the factory is just where real wiring happens.
4. `BitcoinPaymentProcessor::process()` (split into `resolve_bitcoin_network()` → `resolve_derived_address()` → `build_payment_uri()`):
   - Static address in `network_identifier` → uses it directly
   - xPub/ypub/zpub → `BitcoinAddressService::generate_address_from_xPub()` with an auto-incremented derivation index
   - Index reservation uses `GET_LOCK` / `RELEASE_LOCK` for atomicity
   - Persists via `PayCryptoMeDBStatementsService`
5. `PaymentProcessor` saves `_paycrypto_me_*` order meta and sets status to `pending`

### Payment flow (Lightning)

1. `WC_Gateway_PayCryptoMe_Lightning::process_payment($order_id)` → `PaymentProcessor::process_payment()` → `ProcessorStrategiesFactory::create($gateway)`
2. Factory maps `paycrypto_me_lightning` → `LightningProcessorStrategiesFactory` (composition root), which routes by the `node_type` setting (`btcpay` or `lnd_rest`) and builds `new BtcpayLightningProcessor($gateway, new BtcpayInvoiceService($http, $gateway), $db)` or the lnd equivalent — both processors extend `AbstractLightningProcessor`. Same nullable-with-fallback constructor pattern as the Bitcoin side.
3. `AbstractLightningProcessor::process()` (template method, `final`):
   - First checks `$this->db->get_by_order_id()`: a still-valid (unexpired) existing invoice is reused as-is — no new invoice is created at the node. This mirrors the on-chain `resolve_derived_address()` reuse branch and matters because WooCommerce reuses the same order across checkout retries and the `order-pay` endpoint.
   - Otherwise builds invoice args (order_id, memo, expiry + `base_invoice_args($order)`), applies `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args`
   - Calls `$this->service->create_invoice($args)` — service is `BtcpayInvoiceService` or `LndRestInvoiceService`, both extending `AbstractLightningInvoiceService` (shared constructor + `parse_response()`) and implementing `LightningInvoiceServiceContract`
   - If `payment_request` comes back empty (BTCPay may generate the BOLT11 asynchronously), `resolve_payment_request()` retries a fixed 2 times with 750ms delay before giving up with `PayCryptoMePaymentException`
   - Persists the invoice via `PayCryptoMeLightningDBStatementsService::insert_invoice()` (new order) or `replace_invoice()` (order had an expired invoice) — the persistence result is always checked, raising `PayCryptoMePaymentException` on failure rather than diverging order meta from the DB row
4. `PaymentProcessor` saves order meta and sets status to `pending`, same as On-Chain

### Reporting failures honestly (validation, availability, environment)

Three rules exist because a valid mainnet `zpub` was once rejected with *"not valid for the
selected network"* on a host without the `gmp` extension — a host defect reported as the store
owner's mistake:

1. **`\Error` is never treated as invalid input.** `BitcoinAddressService::validate_extended_pubkey()`
   / `validate_bitcoin_address()` catch **`\Exception`** only (every genuine "this isn't a valid
   key/address" failure is one: `Base58ChecksumFailure`, `ParserOutOfRange`,
   `InvalidArgumentException`). A missing extension surfaces as `\Error` and propagates;
   `WC_Gateway_PayCryptoMe::validate_xpub_address()`/`validate_network_identifier()` convert it into
   a `PayCryptoMeException`, and `process_admin_options()` reports it as an internal error that is
   explicitly *not* the key's fault. Never widen those catches back to `\Throwable`.
2. **Environment is checked before validation, but only where it matters.**
   `process_admin_options()` skips validation and just saves when the submitted identifier needs
   the GMP math and the extension is absent (blocking the form would also lock the admin out of the
   title, the enable checkbox and everything else on the screen). The dependency is narrower than
   the whole gateway: `BitcoinAddressService::requires_gmp_math()` returns false for a bech32
   identifier, because `bitwasp/bech32` is pure PHP while xPubs and base58 addresses reach
   `Base58::decode()`/`gmp_init()`. So **a host without GMP still takes on-chain payments to a fixed
   bc1/tb1 address** — only xPub derivation is impossible there.
   `validate_bitcoin_address()` routes bech32 to `validate_segwit_address()` for exactly this
   reason: `AddressCreator::fromString()` tries base58 first and its `catch (\Exception)` cannot stop
   the `\Error` raised there. `WC_Gateway_PayCryptoMe::admin_options()` renders a warning on the
   settings screen pointing the merchant at that route.
3. **One source for "why is this gateway hidden".** Each gateway implements
   `unavailability_reasons(): array{environment: string[], configuration: string[]}`;
   `Abstract_WC_Gateway_PayCryptoMe::is_available()` derives from it and
   `render_unavailability_notice()` renders it, so the applied reason and the displayed reason
   cannot drift. Concrete gateways must not re-implement `is_available()`.
   The notice renders **only on that gateway's own settings section**
   (`on_own_settings_screen()`: screen `woocommerce_page_wc-settings` + `$_GET['section']` matching
   `$this->id` **exactly** — a prefix match would put the On-Chain notice back on the Lightning
   screen, since `paycrypto_me` is a prefix of `paycrypto_me_lightning`). It used to render on
   every WooCommerce screen, which meant both gateways posted their notice side by side on each
   other's settings page, on Orders and on Plugins — each one out of its own method's domain.
   A gateway that already prints its environment reasons inline from `admin_options()` returns true
   from `renders_environment_notice_inline()` (the On-Chain one does, via
   `render_missing_extension_notice()`) so the same host defect isn't stated twice on one screen.
   The `admin_notices` hook lives in `WC_PayCryptoMe::render_gateway_unavailability_notices()` — one
   registration that loops the loaded gateways — **never in the gateway constructor**: WooCommerce
   rebuilds every gateway after a settings save (`WC_Settings_Payment_Gateways::save()` →
   `WC_Payment_Gateways::init()`), and WordPress cannot dedupe two callbacks bound to two distinct
   objects, so a per-instance hook printed the warning twice on the screen just saved (and the
   first copy came from the pre-save instance, whose `$this->settings` snapshot was already stale).
   The `enabled` check is made when rendering, not when hooking, for the same reason.

The same principle applies to silent degradation: `QrCodeService` takes an optional `?callable
$logger` (forwarded by `PaymentDisplayDataBuilder::build()` from the gateway) so a QR that cannot be
drawn is reported instead of producing a blank order page, `HttpClientContract::ERROR_KEY` carries
the transport reason so a DNS/TLS failure isn't shown as "HTTP 0", and `LightningConfigValidator`
raises an error when `esc_url_raw()` empties a URL instead of silently saving nothing.

### Order-details rendering (shared between gateways)

`Abstract_WC_Gateway_PayCryptoMe` owns `render_admin_order_details_section()`/`render_checkout_order_details_section()`; each gateway only implements the abstract `build_order_display_args(\WC_Order $order): ?array` hook (guard-meta check, network label, crypto amount/currency, confirmations required — the parts that actually differ). The shared `PaymentDisplayDataBuilder` (constructor-injected with `QrCodeService`) turns those args into the final display array (QR code, formatted expiry, `crypto_label`) consumed by `templates/order-details/paycrypto-me-order-details.php`.

That template renders in two very different contexts: the customer's order page (no surrounding form) and the admin order screen, where it sits **inside WooCommerce's order `<form>`**. Every `<button>` there must therefore carry an explicit `type="button"` — the HTML default is `submit`, so the copy-address button used to save the order and answer "Order updated." on every click. `OrderDetailsTemplateMarkupTest` pins this, since `wc_get_template()` is stubbed in the unit suite and nothing else can see the markup.

### Custom DB tables (created on plugin activation)

All prefixed with `{$wpdb->prefix}`, created via `dbDelta()` in `PayCryptoMeBitcoinGatewayActivate`/`PayCryptoMeLightningGatewayActivate` — **no `IF NOT EXISTS`** (it breaks dbDelta's table-name extraction, turning every future schema change into a silent no-op) and **no `FOREIGN KEY`** (dbDelta doesn't manage FKs; composite PKs enforce integrity instead):
- `paycrypto_me_bitcoin_wallet_xpubkeys` — (id, xpub `VARCHAR(191)`, network)
- `paycrypto_me_bitcoin_derivation_indexes` — (derivation_index, wallet_xpubkeys_id) — composite PK
- `paycrypto_me_bitcoin_transactions_data` — (order_id, payment_address, derivation_index_id, wallet_xpubkeys_id)
- `paycrypto_me_lightning_invoices` — (order_id, node_type, invoice_id, payment_request, status, expires_at, amount_sats)

Schema lifecycle lives in `DbInstaller` (`services/class-db-installer.php`) — the single `register_activation_hook` target and also called from `plugins_loaded` via `DbInstaller::maybe_upgrade()`. It runs both `*GatewayActivate::activate()` calls when the code's `DbInstaller::DB_VERSION` differs from the recorded `paycrypto_me_db_version` (the only way a schema change reaches an already-installed site, since WordPress doesn't re-fire `register_activation_hook` on update). Each `dbDelta()` call is followed by a `$wpdb->last_error` check (dbDelta never checks its own error state); each activator **returns** the errors it recorded as well as accumulating them in `paycrypto_me_db_activation_errors`, and `DbInstaller::install()` records the new version **only when that list is empty** — recording it unconditionally used to leave a site with broken tables permanently claiming to be up to date. A failed attempt sets the `paycrypto_me_db_upgrade_retry` transient (1h) so the retry doesn't re-run `dbDelta` on every request, and `DbInstaller::render_activation_errors()` keeps showing the notice until a later successful `install()` clears the option (it used to delete the option after rendering once, so the warning vanished while the schema stayed broken). `uninstall.php` deletes both settings options (including secrets: `lnd_macaroon_hex`, `btcpay_api_key`, `lnd_certificate`) but **deliberately keeps the 4 custom tables and `paycrypto_me_db_version`** — those tables are the store's payment records (derived addresses, indexes, Lightning invoices), still needed for accounting/reconciliation of past orders after the plugin is removed.

### Key services

| Class | File | Does |
|-------|------|------|
| `BitcoinAddressService` | `services/class-bitcoin-address-service.php` | Generate/validate Bitcoin addresses (p2pkh, p2sh-p2wpkh, p2wpkh) from xpub/ypub/zpub using `bitwasp/bitcoin`; `requires_gmp_math()`/`validate_segwit_address()` keep the bech32 path usable on hosts without the GMP extension |
| `PayCryptoMeDBStatementsService` | `services/pay-crypto-me-db-statements-service.php` | CRUD on the 3 On-Chain custom tables; atomic index reservation via MySQL advisory lock; `release_derivation_index()` refunds a reserved index if derivation/persistence fails afterward, so a systemic failure (missing GMP, invalid xpub) can't burn through the wallet's BIP-44 gap limit |
| `PayCryptoMeLightningDBStatementsService` | `services/class-paycrypto-me-lightning-db-statements-service.php` | CRUD on `paycrypto_me_lightning_invoices` (insert/update status/lookup by order or by invoice id); `replace_invoice()` overwrites an expired row instead of the silent no-op `insert_invoice()` gives when a row already exists — used when `AbstractLightningProcessor::process()` finds and reuses/replaces an existing invoice for the order (checkout retries, `order-pay`) |
| `AbstractLightningInvoiceService` | `services/abstract-class-lightning-invoice-service.php` | Base for the two Lightning invoice services: shared constructor (`HttpClientContract`, `WC_Payment_Gateway`) + `parse_response()`, parameterized by `error_log_label()`/`payment_failed_message()` |
| `BtcpayInvoiceService` | `services/class-btcpay-invoice-service.php` | Creates/resolves/checks BTCPay Server invoices via REST |
| `LndRestInvoiceService` | `services/class-lnd-rest-invoice-service.php` | Creates/checks lnd invoices via its REST API (macaroon auth, optional TLS cert via `request_with_cert()`) |
| `LightningConnectionTester` | `services/class-lightning-connection-tester.php` | Backs the admin "Test connection" AJAX buttons for BTCPay/lnd (via `HttpClientContract`, never `wp_remote_get` directly) |
| `PaymentDisplayDataBuilder` | `services/class-payment-display-data-builder.php` | Turns a gateway's `build_order_display_args()` output into the final order-details display array (QR, formatted expiry, `is_expired`, `crypto_label`) — shared by both gateways' render methods on the abstract class. Expiry comes from `_paycrypto_me_payment_expires_ts` (absolute, written by the Lightning processor) when present; the legacy `_paycrypto_me_payment_expires_at` hours meta is only a fallback, because it is anchored to the order's creation date and a reused invoice's remaining hours don't start there |
| `LightningConfigValidator` | `validators/class-lightning-config-validator.php` | Pure/stateless validation logic for the Lightning gateway's 9 `validate_*_field()` settings + `is_lnd_rest_selected()` decision. The gateway keeps one-line public stubs delegating here (required for WooCommerce's `method_exists($this, 'validate_<key>_field')` dispatch) |
| `QrCodeService` | `services/class-qr-code-service.php` | Generate QR code as data URI (uses `endroid/qr-code`) |
| `AssetManager` | `utils/class-asset-manager.php` | Register WooCommerce Gutenberg block scripts/styles |
| `EnvironmentRequirements` | `utils/class-environment-requirements.php` | Which PHP extensions a capability needs (`gmp` on-chain; `gd`/`iconv`/`fileinfo` for QR) and which the host is missing, plus `describe()` for the user-facing message. Single source for the settings-save guard, `unavailability_reasons()` and `QrCodeService` — a missing extension must never be reported as bad user input |
| `DbInstaller` | `services/class-db-installer.php` | Activation/upgrade of the 4 custom tables + the failed-install admin notice; owns `DB_VERSION` |
| `OrderGatewayMatcher` | `utils/class-order-gateway-matcher.php` | Pure helper: does `$order->get_payment_method()` match a given gateway id (accepting the `{id}_express` block variant)? Shared by `PaymentOrderValidator` and both gateways' `build_order_display_args()` guards so the two accepted values never drift apart |
| `AvailablePaymentGatewaysFilter` | `class-available-payment-gateways-filter.php` | Hooks `woocommerce_available_payment_gateways` to hide the alternate PayCryptoMe gateway on "Pay for order" once the order already has payment meta from one of the two — prevents switching payment rails mid-flow (registered once in `WC_PayCryptoMe::__construct()`) |

**Gateway registration:** `WC_PayCryptoMe::add_gateway()` always registers both gateways. It used to read the On-Chain gateway's `hide_for_non_admin_users` and, when set, register *neither* — silently hiding Lightning too, ignoring Lightning's own setting. `Abstract_WC_Gateway_PayCryptoMe::is_available()` applies each gateway's own value, which is the only place that decision belongs.

### Public hooks

| Hook | Type | When |
|------|------|------|
| `paycryptome_before_payment` | action | Before processor runs |
| `paycryptome_after_payment` | action | After processor runs |
| `paycryptome_payment_amount` | filter | Modify order total before payment. Args: `($amount, $order_id, $gateway)` |
| `paycryptome_payment_data` | filter | Modify payment data array before processing. Args: `($payment_data, $order_id, $gateway)`. This is where a third party fills `crypto_amount` (fiat→crypto) — it flows into the on-chain BIP21 URI and is persisted as order meta |
| `paycryptome_for_woocommerce_gateway_loaded` | action | When a gateway instance is constructed |
| `paycryptome_order_display_args` | filter | Order-details render, **pre-build**: `($args, $order, $gateway)` — augment the gateway's display args before `PaymentDisplayDataBuilder::build()` (e.g. flip `show_expiry`, set `crypto_amount`) |
| `paycryptome_order_display_data` | filter | Order-details render, **post-build**: `($display_data, $order, $gateway)` — adjust already-computed display fields (QR, labels) |
| `paycryptome_bitcoin_payment_uri` | filter | On-chain BIP21 URI: `($uri, $order, $payment_address, $crypto_amount, $gateway)` |
| `paycryptome_bitcoin_payment_data` | filter | Final `$payment_data` returned by the Bitcoin processor: `($payment_data, $order, $gateway)` — on-chain analogue of `paycryptome_lightning_payment_data` (fires on both static-address and derived-address paths) |
| `paycryptome_lightning_invoice_memo` / `paycryptome_lightning_invoice_expiry` | filter | Customize the Lightning invoice memo/expiry before creation |
| `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args` | filter | Full invoice args array before `create_invoice()` (includes `amount`/`currency` already merged). `LndRestInvoiceService::create_invoice()` also honors an optional `value` key (sats) — free plugin never sets it; the premium fiat→sats add-on sets it here to enforce the invoice amount. |
| `paycryptome_lightning_payment_data` | filter | Final `$payment_data` returned by the Lightning processor |
| `paycryptome_lightning_btcpay_payment_method_id` / `paycryptome_lightning_btcpay_speed_policy` | filter | BTCPay protocol constants that don't flow through the args array |
| `paycryptome_lightning_status_changed` | action | Fired inside `PayCryptoMeLightningDBStatementsService::update_status($order_id, $old_status, $new_status)` after a successful, actual status change — premium add-on seam (webhook/polling consumers react here instead of monkey-patching) |
| `paycryptome_bitcoin_status_changed` | action | On-chain analogue: fired inside `PayCryptoMeDBStatementsService::update_transaction_confirmations($order_id, $old_confirmations, $new_confirmations)` when the confirmation count actually changes — premium add-on seam (confirmation poller consumers react here). No production caller in the free plugin. |

**Note:** before adding a new filter for Lightning, check whether the value already flows through `base_invoice_args()`/the `invoice_args_filter()` array — only add a dedicated filter for values hardcoded inside a service that never reach that array.

---

## Development workflow

### Build JS (run from `src/trunk/`)

```bash
npm install          # first time
npm run build        # production build → assets/blocks/
npm run dev          # watch mode
```

### Run tests (from `src/trunk/`)

```bash
composer install
./vendor/bin/phpunit
```

Tests use custom WP shims in `tests/_support/` — no real WordPress needed. Config in `phpunit.xml.dist`. Current suite: 334 tests, 709 assertions, 0 errors (3 skipped by design: they assert what a host *without* the GMP extension shows — exercise them with `docker run --rm -v $(pwd)/src/trunk:/plugin -w /plugin php:8.3-cli php ./vendor/bin/phpunit --filter OnchainWithoutGmpTest`).

### Smoke test for environment-dependent fatals

```bash
docker compose up -d wordpress   # if not already up
./scripts/smoke-minimal-host.sh
```

Runs against the real `wordpress` dev container (unlike PHPUnit, which needs no real WP) with specific PHP functions disabled via `-d disable_functions=...` to simulate a host missing `gmp`/`gd`/`iconv`/`fileinfo` — the class of bug that got past every other check because our dev image has every extension installed. Mandatory before cutting a release (see [docs/RELEASE.md](docs/RELEASE.md)).

### Translations

```bash
npm run translate        # .pot + .po + .mo
npm run translate:pot
npm run translate:mo
```

### Composer dependencies (important)

Two dependencies come from forked VCS repos, declared under `repositories` in
`src/trunk/composer.json`:
- `lucas-rosa95/bitcoin` — fork of `bitwasp/bitcoin-php`, the only one in `require`
- `lucas-rosa95/buffertools-php` — fork of `bitwasp/buffertools`; **not** in `require`, it enters
  only because the fork above requires it

Running `composer install` in a fresh environment requires access to these GitHub repos. That is
also why `minimum-stability: dev` and the two `config.audit.ignore` entries are there.

> **Retiring these is planned and approved** — see
> [docs/CRYPTO-DEPENDENCIES.md](docs/CRYPTO-DEPENDENCIES.md). Short version, all measured: the
> `bitcoin` fork carries no source fix of its own, is one method *behind* upstream (whose absence is
> a fatal on class load), and upstream `v1.1.0` passes the full suite and all 60 address vectors
> unchanged. The suppressed advisories are for `mdanter/ecc`, which is no longer in the tree.
> **Do not deepen the forks; do not add new patches to them.**

---

## Premium add-on: scope boundaries and extension points

Two capabilities are **intentionally absent from this free plugin and reserved for a separate premium add-on plugin** — deliberate product-scope decisions, not development gaps. Do not treat them as unfinished work or "fix" them into the free version. The approved implementation plan for that separate add-on (its own repo, not started yet) lives at [docs/PREMIUM-ADDON.md](docs/PREMIUM-ADDON.md).

- **Webhook REST endpoint + async status updates.** The Lightning settings UI references `rest_url('paycrypto-me/v1/webhook')`, but there is deliberately no `register_rest_route()` here. Automatic/async invoice-status confirmation (BTCPay webhook push; lnd polling via `wp_schedule_event`) is a premium-tier feature.
- **Fiat → sats conversion.** Invoices are created zero-amount on purpose. Converting the order's fiat total into an `amount_sats` is a premium-tier feature.

**Delivery model:** the premium features ship as a separate plugin that depends on this base and plugs in via hooks/filters — never as `if (is_premium())` gating inside this repo. The base exposes these extension points so the add-on is zero-core-edit:

| Extension point | How the add-on uses it |
|---|---|
| `PayCryptoMeLightningDBStatementsService::get_by_invoice_id()` | Look up an order when a webhook payload only carries the invoice id (`get_by_order_id()` covers the other case) |
| `paycryptome_lightning_status_changed` action (see "Public hooks") | React to a status change (e.g. call `$order->payment_complete()`) without monkey-patching |
| `paycryptome_lightning_btcpay_invoice_args` / `_lnd_invoice_args` filters | Already receive `$order` + `$gateway` — the add-on computes `amount` in sats here for fiat→sats. For lnd, set the `value` key (sats) to enforce the amount on the invoice (BTCPay converts fiat itself, so it needs no `value`). |
| `PayCryptoMeDBStatementsService::update_transaction_confirmations()` + `paycryptome_bitcoin_status_changed` action | On-chain confirmation poller persists confirmations/amount/tx via this method and reacts to the action (e.g. `$order->payment_complete()` once required confirmations are reached) — mirrors the Lightning `update_status()` seam |
| `woocommerce_settings_api_form_fields_paycrypto_me_lightning` (native WooCommerce filter) | Append settings fields (e.g. webhook secret) without touching `init_form_fields()` |
| Dependency guard (`class_exists()` + min-version check) | Add-on's own responsibility, not a base concern |

---

## Known follow-ups

Two low-value, low-risk cleanups are deliberately deferred (pure extract-method, no duplication reduction, zero test coverage as view/config code — not release blockers): `init_form_fields_items()` (Lightning gateway, long method — the shared `init_form_fields()` itself lives in the abstract class) and `enqueue_checkout_styles()` (abstract gateway, long method). The Lightning gateway's 3 HTML generator methods (`generate_node_type_html`/`generate_btcpay_test_button_html`/`generate_lnd_test_button_html`) could similarly move to a render helper if ever prioritized.

---

## Code style notes

- PHP namespace `PayCryptoMe\WooCommerce` everywhere
- All user-facing strings go through `__()` / `esc_html__()` with text domain `paycrypto-me-for-woocommerce`
- Sanitize all inputs at system boundaries; trust internal data
- No comments explaining WHAT code does; only WHY when non-obvious
