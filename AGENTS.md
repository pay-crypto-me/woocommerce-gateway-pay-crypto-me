# PayCrypto.Me for WooCommerce — Context for Agents

## Context and guides

Grouped by status, and every doc carries the same tag as its own H1 title — so a plan that has
already been executed is never mistaken for one still pending, or vice versa. **[GUIDE]** docs have
no "done" state (living operational reference, always current); **[PLAN]** docs are inert until
someone implements them — nothing in a `[PLAN — NOT STARTED]` doc is in the code yet; **[VALIDATION]**
docs are a working checklist for a specific branch's manual QA — code already exists, the doc tracks
confidence in it, and it moves to `docs/archive/` as `[DONE]` once that branch merges.

**Operational guides**
- **[GUIDE]** [docs/GUIDE-RELEASE.md](docs/GUIDE-RELEASE.md) — how to build a release and submit to WordPress.org (SVN or direct upload); SVN flow battle-tested against the real first push (2026-08-08), including recovery from a transient WP.org server-side commit error.
- **[GUIDE]** [docs/GUIDE-TRANSLATION.md](docs/GUIDE-TRANSLATION.md) — translation commands and status (7 locales, 100%).
- **[GUIDE]** [docs/GUIDE-I18N-CONVENTIONS.md](docs/GUIDE-I18N-CONVENTIONS.md) — permanent string-authoring rules: product-name constants, reorderable placeholders, translator comments, context, markup boundaries and JS catalogs.
- **[GUIDE]** [docs/GUIDE-ADD-NEW-GATEWAY.md](docs/GUIDE-ADD-NEW-GATEWAY.md) — checklist to implement a third gateway.
- **[GUIDE]** [docs/GUIDE-DB-SCHEMA-UPGRADE.md](docs/GUIDE-DB-SCHEMA-UPGRADE.md) — checklist for bumping `DbInstaller::DB_VERSION`: editing the `CREATE TABLE` SQL, freezing a new `tests/schema/v<N>.sql`, running `schema-tests.sh`. Written 2026-08-27 alongside the mechanism itself, before any real bump exercised it — flagged in the doc as open to correction from the first real use.

**Executed and verified — archived, may be absent from your checkout**

These records did their job (they got their change built, verified and merged) and are now closed.
They live under `docs/archive/`, which is **gitignored** — kept locally as a convenience, not
committed going forward. A fresh clone of this repo from this point on will **not** have them; if a
link below 404s, that is expected, not drift. `scripts/check-docs-drift.sh` knows about the four
paths below and does not flag them as missing. Do not resurrect a file here just to satisfy a link —
if you need the history, `git log` on the commit that last had it under `docs/` still has the content.
- **[DONE]** [docs/archive/DONE-CRYPTO-DEPENDENCIES.md](docs/archive/DONE-CRYPTO-DEPENDENCIES.md) — the record of why the two `lucas-rosa95/*` forks existed and how they were retired in favor of the official `bitwasp/*` packages (measured). Read it before touching the crypto dependencies in `src/trunk/composer.json`, if present in your checkout.
- **[DONE]** [docs/archive/DONE-CRYPTO-DEPENDENCIES-AUDIT.md](docs/archive/DONE-CRYPTO-DEPENDENCIES-AUDIT.md) — independent review of that dependency swap: what was re-measured and passed, the 5 record/documentation corrections it found (all applied), and the list of things that look wrong but are deliberate. Read it with the doc above, not instead of it.
- **[DONE]** [docs/archive/DONE-LEAN-VENDOR-TREE.md](docs/archive/DONE-LEAN-VENDOR-TREE.md) — `config.platform.php = 7.4` resolved the *whole* tree as if on PHP 7.4, not just the one package it existed for, so the plugin shipped `paragonie/sodium_compat` a major behind and `paragonie/random_compat` — a PHP 5 polyfill that never executed. The pin now states the real floor (8.1), `murmurhash` is dropped via `replace`, and the record holds what was measured before and after (including two predictions the execution corrected, plus the two findings of the 2026-08-18 pre-merge audit: the generated `platform_check.php` moving to `>= 80100`, and the pin audit that could pass without its probe running). Read it before touching `config.platform`, `replace`, `composer.lock` or `scripts/check-platform-pin.sh`, if present in your checkout.
- **[DONE]** [docs/archive/DONE-SCHEMA-UPGRADE-AND-STATIC-RECORDS.md](docs/archive/DONE-SCHEMA-UPGRADE-AND-STATIC-RECORDS.md) — records fixed-address on-chain payments (sentinel `WALLET_ID_STATIC_ADDRESS`, no schema change) and hardens the schema-upgrade mechanism, plus the real-MySQL test trail that now backs both. Executed 2026-08-27; everything it specified is in the code and described below under "Schema lifecycle and what `dbDelta()` will not do for you" — read that section rather than the record. Its **frente C** (versioned imperative migration steps) was deliberately deferred and its contract lives in that same section. Read the record only for the measurements behind those decisions.
- **[DONE]** [docs/archive/DONE-CRYPTO-DEPRECATION-CONTINGENCY.md](docs/archive/DONE-CRYPTO-DEPRECATION-CONTINGENCY.md) — the `bitwasp/buffertools` `E_DEPRECATED` notices ("Use of parent in callables") that printed during the On-Chain settings save and broke its post-save redirect, contained via a scoped `error_reporting` mask at the `BitcoinAddressService` boundary (no vendor edits, never swallows an `\Error`). Shipped in 0.1.2 (`src/trunk/CHANGELOG.md` → `### Fixed`); the browser acceptance test (section C) has passed. Read it before touching deprecation/error-reporting handling around the crypto lib.
- **[DONE]** [docs/archive/DONE-SCHEMA-INSTALL-HARDENING.md](docs/archive/DONE-SCHEMA-INSTALL-HARDENING.md) — closes the repair-path, masked-`dbDelta`-error and double-submit findings from the 2026-08-28 adversarial review. Implemented and verified with 407 unit + 18 integration tests and the manual repair/race checks completed 2026-08-29; durable behavior lives in the schema lifecycle section below.
- **[DONE]** [docs/archive/DONE-SCHEMA-UPGRADE-AND-STATIC-RECORDS-VALIDATION.md](docs/archive/DONE-SCHEMA-UPGRADE-AND-STATIC-RECORDS-VALIDATION.md) — browser/database validation record for upgrade, fixed/derived flows, failures, GMP-less operation, Lightning races, fresh install, rollback, uninstall and repair. Completed 2026-08-29 with the version bump approved; one translation-only visual check was explicitly waived as non-blocking.
- **[DONE]** [docs/archive/DONE-I18N-CONVENTIONS.md](docs/archive/DONE-I18N-CONVENTIONS.md) — execution record for the i18n retrofit: brand constants, safe templates, JS catalog extraction, 7 locales at 100%, the runtime-only 14-file JSON strategy, and an automated release audit. Completed and verified 2026-08-30; archived/gitignored and therefore potentially absent from a fresh checkout. Durable rules live in `docs/GUIDE-I18N-CONVENTIONS.md`.
- **[DONE]** [docs/archive/DONE-PUBLIC-PAYMENT-STATUS-PROJECTION-VALIDATION.md](docs/archive/DONE-PUBLIC-PAYMENT-STATUS-PROJECTION-VALIDATION.md) — browser acceptance record for the Base projection contract: candidate, published fallback, checkout, coexistence and upgrade paths all passed on 2026-09-07; archived before release preparation, while the formal Pro cross-repo acceptance remains open.

**Approved plans — not started yet**
- **[PLAN — NOT STARTED]** [`docs/PREMIUM-ADDON.md`](https://github.com/paycrypto-me/paycrypto-me-pro/blob/main/docs/PREMIUM-ADDON.md) — approved implementation plan for the separate Pro add-on plugin (renamed from "Premium" to "Pro" 2026-08-25). Lives in that add-on's own repo; see "Pro add-on" below for the base's own scope boundaries and extension points.

**Proposed RFCs — not implemented**
- **[PLAN — NOT STARTED]** [docs/rfcs/RFC-PUBLIC-PAYMENT-PRESENTATION-DATA.md](docs/rfcs/RFC-PUBLIC-PAYMENT-PRESENTATION-DATA.md) — proposal committed in `043187e`; no runtime/API implementation exists in this branch.

**Pre-release preparation**
- **[PLAN — NOT STARTED]** [docs/PRE-RELEASE-0.3.0.md](docs/PRE-RELEASE-0.3.0.md) — release gates and editorial notes prepared for 0.3.0; no release command, tag, version bump or upload has been started.

**Status:** **Live on WordPress.org** since 2026-08-08 (first published as 0.1.0); current version **0.2.2** (this number and the one below are bumped by `release.sh`, not by hand). Current branch includes the public payment-status projection contract for the Pro add-on (420 unit tests + 23 MySQL-backed integration tests). Pro features (webhook/fiat→sats) remain reserved for the separate add-on — see "Pro add-on" section below.

---

## What this project is

WordPress plugin (GPL-3.0-or-later) that adds Bitcoin payment gateways to WooCommerce. Non-custodial: the store owner controls the keys. Version: **0.2.2**. Author: PayCrypto.Me (contact@paycrypto.me).

**Two registered gateways, both fully functional:**
- `paycrypto_me` — Bitcoin On-Chain (HD derivation from xPub/ypub/zpub, mainnet + testnet).
- `paycrypto_me_lightning` — Bitcoin Lightning Network (BTCPay Server or lnd REST): invoice creation, resolution, persistence, order-details rendering.

Async webhook status updates and fiat→sats conversion are deliberately out of scope for this free plugin — see "Pro add-on" below.

---

## Directory layout

```
paycrypto-me-for-woocommerce/
├── AGENTS.md                     ← this file
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
   - An order that already has a row (checkout retry, `order-pay`) reuses the address on file
     as-is and does **not** re-validate it against the currently selected network — switching
     mainnet↔testnet after the order already has a row keeps showing the original address
     (deliberate: the address the customer already saw wins). A concurrent insert that loses the
     race (`insert_address()`/`insert_static_address()` returning false because another request
     just recorded the same order) re-reads and returns that row instead of failing the checkout —
     see `PayCryptoMeDBStatementsService::insert_address()`'s docblock and
     `BitcoinPaymentProcessor::resolve_static_address()`/`resolve_derived_address()`.
5. `PaymentProcessor` saves `_paycrypto_me_*` order meta and sets status to `pending`

### Payment flow (Lightning)

1. `WC_Gateway_PayCryptoMe_Lightning::process_payment($order_id)` → `PaymentProcessor::process_payment()` → `ProcessorStrategiesFactory::create($gateway)`
2. Factory maps `paycrypto_me_lightning` → `LightningProcessorStrategiesFactory` (composition root), which routes by the `node_type` setting (`btcpay` or `lnd_rest`) and builds `new BtcpayLightningProcessor($gateway, new BtcpayInvoiceService($http, $gateway), $db)` or the lnd equivalent — both processors extend `AbstractLightningProcessor`. Same nullable-with-fallback constructor pattern as the Bitcoin side.
3. `AbstractLightningProcessor::process()` (template method, `final`):
   - First checks `$this->db->get_by_order_id()`: a still-valid (unexpired) existing invoice is reused as-is — no new invoice is created at the node. This mirrors the on-chain `resolve_derived_address()` reuse branch and matters because WooCommerce reuses the same order across checkout retries and the `order-pay` endpoint.
   - Otherwise builds invoice args (order_id, memo, expiry + `base_invoice_args($order)`), applies `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args`
   - Calls `$this->service->create_invoice($args)` — service is `BtcpayInvoiceService` or `LndRestInvoiceService`, both extending `AbstractLightningInvoiceService` (shared constructor + `parse_response()`) and implementing `LightningInvoiceServiceContract`
   - If `payment_request` comes back empty (BTCPay may generate the BOLT11 asynchronously), `resolve_payment_request()` retries a fixed 2 times with 750ms delay before giving up with `PayCryptoMePaymentException`
   - Persists the invoice via `PayCryptoMeLightningDBStatementsService::insert_invoice()` (new order) or compare-and-swap `replace_invoice()` (order had an expired invoice). If either write loses a double-submit race, the processor re-reads and returns the winner's stored invoice so order meta and webhook lookup stay aligned; a genuine persistence failure with no stored row still raises `PayCryptoMePaymentException`.
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

The plugin's own load path follows the same rule. The bundled `vendor/` is resolved for the PHP
floor in `Requires PHP:`, so Composer's generated `platform_check.php` throws from
`vendor/autoload.php` on anything older — a site-wide fatal that never names the plugin. The
entrypoint checks `PHP_VERSION_ID` **before** that require and returns with an admin notice instead,
exactly like the neighbouring guard for a missing `vendor/`. WordPress already blocks activation and
updates below the header floor, so what this catches is a site whose PHP was downgraded afterwards.
That floor is written in four places — plugin header, `readme.txt`, `config.platform.php` and this
guard — and `PhpFloorConsistencyTest` fails if any of them drifts, because they only mean anything
together (see "Composer dependencies" below for what the pin drifting costs).

The same principle applies to silent degradation: `QrCodeService` takes an optional `?callable
$logger` (forwarded by `PaymentDisplayDataBuilder::build()` from the gateway) so a QR that cannot be
drawn is reported instead of producing a blank order page, `HttpClientContract::ERROR_KEY` carries
the transport reason so a DNS/TLS failure isn't shown as "HTTP 0", and `LightningConfigValidator`
raises an error when `esc_url_raw()` empties a URL instead of silently saving nothing.

The inverse case — silencing *accepted third-party* noise — is scoped just as tightly.
`BitcoinAddressService::suppress_vendor_deprecations()` masks **only** `E_DEPRECATED`, and only
around the `bitwasp` calls, because those libraries emit `Use of "parent" in callables` (and
tentative return-type) notices mid-request that printed during the On-Chain settings save and broke
its post-save redirect ("headers already sent"). It restores `error_reporting()` in a `finally` and
**never catches** — a missing-extension `\Error` still propagates under rule 1, and the eventual
PHP 9 fatal (a thrown `Error`, not a diagnostic) is not hidden. It is not a general tool: never
widen it to silence our own deprecations. See
[docs/archive/DONE-CRYPTO-DEPRECATION-CONTINGENCY.md](docs/archive/DONE-CRYPTO-DEPRECATION-CONTINGENCY.md)
(archived, may be absent — see "Context and guides" above).

### Order-details rendering (shared between gateways)

`Abstract_WC_Gateway_PayCryptoMe` owns `render_admin_order_details_section()`/`render_checkout_order_details_section()`; each gateway only implements the abstract `build_order_display_args(\WC_Order $order): ?array` hook (guard-meta check, network label, crypto amount/currency, confirmations required — the parts that actually differ). The shared `PaymentDisplayDataBuilder` (constructor-injected with `QrCodeService`) turns those args into the final display array (QR code, formatted expiry, `crypto_label`) consumed by `templates/order-details/paycrypto-me-order-details.php`.

That template renders in two very different contexts: the customer's order page (no surrounding form) and the admin order screen, where it sits **inside WooCommerce's order `<form>`**. Every `<button>` there must therefore carry an explicit `type="button"` — the HTML default is `submit`, so the copy-address button used to save the order and answer "Order updated." on every click. `OrderDetailsTemplateMarkupTest` pins this, since `wc_get_template()` is stubbed in the unit suite and nothing else can see the markup.

### Custom DB tables (created on plugin activation)

All prefixed with `{$wpdb->prefix}`, created via `dbDelta()` in `PayCryptoMeBitcoinGatewayActivate`/`PayCryptoMeLightningGatewayActivate` — **no `IF NOT EXISTS`** (it breaks dbDelta's table-name extraction, turning every future schema change into a silent no-op) and **no `FOREIGN KEY`** (dbDelta doesn't manage FKs; composite PKs enforce integrity instead):
- `paycrypto_me_bitcoin_wallet_xpubkeys` — (id, xpub `VARCHAR(191)`, network)
- `paycrypto_me_bitcoin_derivation_indexes` — (derivation_index, wallet_xpubkeys_id) — composite PK
- `paycrypto_me_bitcoin_transactions_data` — (order_id, payment_address, derivation_index_id, wallet_xpubkeys_id). Both derivation columns hold `PayCryptoMeDBStatementsService::WALLET_ID_STATIC_ADDRESS` (`0`) for a payment to a **fixed address**, where there is no extended key and no index. `0` can never collide — `wallet_xpubkeys.id` is `AUTO_INCREMENT` from 1 — so `WHERE wallet_xpubkeys_id = 0` selects exactly the fixed-address payments. A sentinel rather than nullable columns because of F1 below. This is also why `get_by_order_id()` joins with `LEFT JOIN`: an `INNER JOIN` drops those rows entirely, which is how they went unrecorded before. Legacy confirmation columns (`num_confirmations`, `amount_received`, `tx_hash`) were removed by schema version 2; confirmation tracking belongs to the Pro add-on.
- `paycrypto_me_lightning_invoices` — (order_id, node_type, invoice_id, payment_request, status, expires_at, amount_sats)

Schema lifecycle lives in `DbInstaller` (`services/class-db-installer.php`) — `DbInstaller::activate()` (a zero-argument wrapper around `install(true)`) is the `register_activation_hook` target, plus `DbInstaller::maybe_upgrade()` on `admin_init` and `DbInstaller::maybe_upgrade_after_update()` on `upgrader_process_complete`. `activate()` always runs both `*GatewayActivate::activate()` calls, regardless of the recorded version — this is what lets it recreate a table that went missing (a restored migration, a merchant who manually dropped a table `uninstall.php` deliberately kept) even on a site whose `paycrypto_me_db_version` was already current. `maybe_upgrade()` (no force) runs them only when the code's `DbInstaller::DB_VERSION` is newer than the recorded version (the only way a schema change reaches an already-installed site otherwise, since WordPress doesn't re-fire `register_activation_hook` on update) — and additionally, when the version IS current, throttled-probes (`SHOW TABLES LIKE`, at most twice a day via the `paycrypto_me_db_health_check` transient) that the 4 tables still exist, force-repairing via `install(true)` if one is missing. The table names have one source: each activator's `TABLE_*` constants (bare names), exposed as `DbInstaller::tables()`. Each `dbDelta()` call goes through `DbDeltaRunner::run()`, which checks `$wpdb->last_error` **and then** a `dbDelta($sql, false)` dry run for anything still structurally missing (see F5 below) — see `DbDeltaRunner`'s own docblock for exactly what "unchanged" means here; each activator **returns** the errors it recorded as well as accumulating them in `paycrypto_me_db_activation_errors`, and `DbInstaller::install()` records the new version **only when that list is empty** — recording it unconditionally used to leave a site with broken tables permanently claiming to be up to date. A failed attempt sets the `paycrypto_me_db_upgrade_retry` transient (1h) so the retry doesn't re-run `dbDelta` on every request, and `DbInstaller::render_activation_errors()` keeps showing the notice until a later successful `install()` clears the option (it used to delete the option after rendering once, so the warning vanished while the schema stayed broken). `uninstall.php` deletes both settings options (including secrets: `lnd_macaroon_hex`, `btcpay_api_key`, `lnd_certificate`) but **deliberately keeps the 4 custom tables and `paycrypto_me_db_version`** — those tables are the store's payment records (derived addresses, indexes, Lightning invoices), still needed for accounting/reconciliation of past orders after the plugin is removed.

### Schema lifecycle and what `dbDelta()` will not do for you

The plugin is live on WordPress.org, so every schema change has to work on a **fresh install and on
a site upgrading from a published version**. Those two are not the same code path, and the second
one fails silently. Read this before touching `DbInstaller`, either `*GatewayActivate` class,
`DB_VERSION`, or any `CREATE TABLE` string.

**What `dbDelta()` actually does** — measured against real MySQL 8 in the dev container, not
inferred from the docs:

| # | Fact |
|---|---|
| F1 | **It does not apply a nullability change.** `NOT NULL` → `NULL` produces no `ALTER`, no error, and an **empty `$wpdb->last_error`**. The declaration is simply ignored. |
| F2 | It *does* apply a **new column** and a **type change**. |
| F3 | It parses **line by line**: two column definitions on one line means the second is dropped, silently. |
| F4 | It **never removes** a column or an index. |
| F5 | `$wpdb->last_error` only reflects the **LAST** statement `dbDelta()` executed — `wpdb::query()` calls `flush()` (which clears `last_error`) on every query, and `dbDelta()` builds every statement up front and executes them all in one loop, column `ALTER`s before index `ALTER`s. A failing `ADD COLUMN` followed by a succeeding `ADD INDEX` therefore leaves `last_error` **empty**, even though the column never landed. Verified against MySQL 8.0.46 (`tests/integration/DbDeltaErrorVisibilityTest.php`'s canary). This is why `DbDeltaRunner` re-runs `dbDelta($sql, false)` (a read-only dry run) after a clean `last_error` and treats any remaining `Created table `/`Added column `/`Added index ` description as a failure — `Changed type of …`/`Changed default value of …` are deliberately ignored as cross-engine normalisation noise. |

F1 is the reason the fixed-address record uses a sentinel instead of nullable columns: the "make
them nullable and bump `DB_VERSION`" design passes every unit test, works on a fresh install, and
does nothing at all on every site that already had the plugin.

**Invariants:**

- **Forward-only and additive.** No down-migrations, no dropping a column that holds payment data —
  the 4 tables are the store's financial history, which is also why `uninstall.php` keeps them. The
  real "go back" case is a merchant installing an older plugin, and what protects against that is
  the additive invariant plus `maybe_upgrade()`'s `version_compare(..., '>=')`, which refuses to
  rewrite a **newer** recorded version backwards.
- **No payment path may depend on a column or table introduced by a schema version newer than the
  recorded one.** `maybe_upgrade()` no longer runs on `plugins_loaded` (that put an `ALTER` on a
  growing table inside a shopper's page load), so between a plugin update and the next admin page a
  site can legitimately run new code over an old schema. Code that needs a new column must consult
  **`DbInstaller::is_current()`** and degrade — never assume.
- **`install()` is serialized** on the `paycrypto_me_db_install` advisory lock (same
  `GET_LOCK`/`RELEASE_LOCK`-in-a-`finally` pattern as
  `PayCryptoMeDBStatementsService::reserve_derivation_index_for_wallet()`). Losing the race returns
  `false` **without** recording an error: another request is doing the same work, and the admin
  notice must not fire for something that resolves itself. `install(bool $force = false)`'s
  `$force` skips the post-lock `is_current()` short-circuit so `activate()` (`install(true)`) always
  rebuilds regardless of the recorded version — the self-repair path; `maybe_upgrade()`'s own
  throttled health check (above) is what calls `install(true)` outside of activation. Neither the
  install lock nor the wallet lock (`PayCryptoMeDBStatementsService`) is namespaced per
  database/site — deliberate, not overlooked; see
  [docs/archive/DONE-SCHEMA-INSTALL-HARDENING.md](docs/archive/DONE-SCHEMA-INSTALL-HARDENING.md) § "Deferred by
  decision" for the finding, why it's acceptable, and what to do if it's ever revisited.
- **Every `DB_VERSION` bump ships a new `src/trunk/tests/schema/v<N>.sql`**, generated by
  `tests/bin/dump-schema.php` *while that version is what's published*. The convergence test globs
  `tests/schema/v*.sql`, so each historical version stays covered automatically — and a version
  with no snapshot is a version nothing verifies.

**How a schema change gets verified.** The unit suite structurally cannot help here: it shims `wpdb`
and `ActivateDbDeltaTest` defines its own fake `dbDelta()`. That is deliberate (it keeps the suite
at ~5s with no WordPress) and it must stay that way. The check lives in the separate, opt-in
`./scripts/schema-tests.sh` instead — real WordPress, real MySQL, real `dbDelta`, isolated by
swapping `$wpdb->prefix` per test. Its headline test replays each frozen snapshot, runs the upgrade
over it, and asserts the result is **indistinguishable from a fresh install** (compared by an
order-insensitive fingerprint from `SHOW COLUMNS` + `SHOW INDEX`, never raw `SHOW CREATE TABLE` —
`dbDelta` appends a new column at the end while a fresh install puts it in its declared position).
There is no CI in this repo, so the only enforcement point is the release checklist in
[docs/GUIDE-RELEASE.md](docs/GUIDE-RELEASE.md).

**Versioned imperative migration steps are implemented.** The first real migration removes the
legacy on-chain confirmation columns in schema version 2. The contract for future steps is:

- `dbDelta()` stays the declarative baseline and runs first.
- What `dbDelta` cannot do (F1, F4, backfill, rename) becomes an imperative step, ordered by target
  version, **idempotent** (check `information_schema` before acting) and with a **post-condition
  check** that reports into the same `paycrypto_me_db_activation_errors`.
- `install()` = `dbDelta` → pending steps → record the version **only if everything verified**.
- Additive and forward-only. Never drop a column holding payment data.
- Every step ships its `tests/schema/v<N>.sql` and is covered by the convergence test.

### Key services

| Class | File | Does |
|-------|------|------|
| `BitcoinAddressService` | `services/class-bitcoin-address-service.php` | Generate/validate Bitcoin addresses (p2pkh, p2sh-p2wpkh, p2wpkh) from xpub/ypub/zpub using `bitwasp/bitcoin`; `requires_gmp_math()`/`validate_segwit_address()` keep the bech32 path usable on hosts without the GMP extension |
| `PayCryptoMeDBStatementsService` | `services/pay-crypto-me-db-statements-service.php` | CRUD on the 3 On-Chain custom tables; atomic index reservation via MySQL advisory lock; `release_derivation_index()` refunds a reserved index if derivation/persistence fails afterward, so a systemic failure (missing GMP, invalid xpub) can't burn through the wallet's BIP-44 gap limit; `insert_static_address()` records a fixed-address payment through the same `insert_address()` INSERT and `exists_for_order()` guard, using the `WALLET_ID_STATIC_ADDRESS` sentinel |
| `PayCryptoMeLightningDBStatementsService` | `services/class-paycrypto-me-lightning-db-statements-service.php` | CRUD on `paycrypto_me_lightning_invoices`; `transition_status()` compare-and-swaps `order_id` + invoice identity + expected status and returns a typed outcome, so concurrent write-backs emit at most one transition and a delayed webhook cannot settle a replacement invoice; `update_status()` remains as a deprecated compatibility wrapper |
| `PaymentStatusProjectionCapabilities` | `contracts/class-payment-status-projection-capabilities.php` | Versioned discovery API for add-ons: Lightning invoice CAS is v1 and on-chain confirmation projection is explicitly absent (`0`) |
| `LightningStatusTransitionResult` | `invoices/class-lightning-status-transition-result.php` | Immutable result of a Lightning projection attempt: `applied`, `already_applied`, `conflict`, `not_found` or `error`, with invoice/current-state diagnostics |
| `AbstractLightningInvoiceService` | `services/abstract-class-lightning-invoice-service.php` | Base for the two Lightning invoice services: shared constructor (`HttpClientContract`, `WC_Payment_Gateway`) + `parse_response()`, parameterized by `error_log_label()`/`payment_failed_message()` |
| `BtcpayInvoiceService` | `services/class-btcpay-invoice-service.php` | Creates/resolves/checks BTCPay Server invoices via REST |
| `LndRestInvoiceService` | `services/class-lnd-rest-invoice-service.php` | Creates/checks lnd invoices via its REST API (macaroon auth, optional TLS cert via `request_with_cert()`) |
| `LightningConnectionTester` | `services/class-lightning-connection-tester.php` | Backs the admin "Test connection" AJAX buttons for BTCPay/lnd (via `HttpClientContract`, never `wp_remote_get` directly) |
| `PaymentDisplayDataBuilder` | `services/class-payment-display-data-builder.php` | Turns a gateway's `build_order_display_args()` output into the final order-details display array (QR, formatted expiry, `is_expired`, `crypto_label`) — shared by both gateways' render methods on the abstract class. Expiry comes from `_paycrypto_me_payment_expires_ts` (absolute, written by the Lightning processor) when present; the legacy `_paycrypto_me_payment_expires_at` hours meta is only a fallback, because it is anchored to the order's creation date and a reused invoice's remaining hours don't start there |
| `LightningConfigValidator` | `validators/class-lightning-config-validator.php` | Pure/stateless validation logic for the Lightning gateway's 9 `validate_*_field()` settings + `is_lnd_rest_selected()` decision. The gateway keeps one-line public stubs delegating here (required for WooCommerce's `method_exists($this, 'validate_<key>_field')` dispatch) |
| `QrCodeService` | `services/class-qr-code-service.php` | Generate QR code as data URI (uses `endroid/qr-code`) |
| `AssetManager` | `utils/class-asset-manager.php` | Register WooCommerce Gutenberg block scripts/styles |
| `EnvironmentRequirements` | `utils/class-environment-requirements.php` | Which PHP extensions a capability needs (`gmp` on-chain; `gd`/`iconv`/`fileinfo` for QR) and which the host is missing, plus `describe()` for the user-facing message. Single source for the settings-save guard, `unavailability_reasons()` and `QrCodeService` — a missing extension must never be reported as bad user input |
| `DbInstaller` | `services/class-db-installer.php` | Activation/upgrade of the 4 custom tables + the failed-install admin notice; owns `DB_VERSION`. `activate()` (zero-argument, the activation hook target) force-repairs regardless of the recorded version; `install(bool $force = false)` is serialized on the `paycrypto_me_db_install` advisory lock; `maybe_upgrade()` is forward-only (`version_compare`), hooked on `admin_init`/`upgrader_process_complete` (never the front-end path), and throttled-probes (`paycrypto_me_db_health_check`, 12h) that the 4 tables still exist even when the version is current; `is_current()` is what other code asks before depending on a newer schema; `tables()` is the one source for the 4 bare table names |
| `DbDeltaRunner` | `services/class-db-delta-runner.php` | Runs one table's `dbDelta()` and verifies it actually applied: `$wpdb->last_error` first, then a `dbDelta($sql, false)` dry run for anything still structurally missing (F5 above) — `$wpdb->last_error` alone misses a failing non-final statement. Used by both `*GatewayActivate::activate()` methods, one call per table |
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
| `paycryptome_lightning_btcpay_invoice_args` / `paycryptome_lightning_lnd_invoice_args` | filter | Full invoice args array before `create_invoice()` (includes `amount`/`currency` already merged). `LndRestInvoiceService::create_invoice()` also honors an optional `value` key (sats) — free plugin never sets it; the Pro fiat→sats add-on sets it here to enforce the invoice amount. |
| `paycryptome_lightning_payment_data` | filter | Final `$payment_data` returned by the Lightning processor |
| `paycryptome_lightning_btcpay_payment_method_id` / `paycryptome_lightning_btcpay_speed_policy` | filter | BTCPay protocol constants that don't flow through the args array |
| `paycryptome_lightning_status_changed` | action | `($order_id, $old_status, $new_status, $invoice_id)`, fired only when `PayCryptoMeLightningDBStatementsService::transition_status()` applies its atomic CAS. At most one action is emitted per applied transition; it is a post-write notification, not durable delivery or a distributed lock. Existing 3-argument listeners remain compatible. |

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

Tests use custom WP shims in `tests/_support/` — no real WordPress needed. Config in `phpunit.xml.dist`, which scans `./tests/phpunit` only, so the MySQL-backed suite under `tests/integration/` is never pulled into this run. Current suite: 420 tests, 979 assertions, 0 errors (4 skipped by design: they assert what a host *without* the GMP extension shows, so they only run on a GMP-less host — e.g. `docker run --rm -v $(pwd)/src/trunk:/plugin -w /plugin php:8.3-cli php ./vendor/bin/phpunit --filter OnchainWithoutGmpTest`).

### Integration tests against real MySQL (run from the repo root)

```bash
docker compose up -d wordpress wp_db   # if not already up
./scripts/schema-tests.sh
```

Runs `tests/integration/` inside the `wordpress` container (`phpunit-integration.xml.dist`), against
real `$wpdb`/`dbDelta`. It covers schema behaviour and database concurrency contracts — see
"Schema lifecycle and what `dbDelta()` will not do for you" above for why the unit suite cannot do
it. Mandatory before cutting a release. When adding a test here, make it fail on purpose once: a
convergence test that has never failed is indistinguishable from one that cannot.

### Smoke test for environment-dependent fatals

```bash
docker compose up -d wordpress   # if not already up
./scripts/smoke-minimal-host.sh
```

Runs against the real `wordpress` dev container (unlike PHPUnit, which needs no real WP) with specific PHP functions disabled via `-d disable_functions=...` to simulate a host missing `gmp`/`gd`/`iconv`/`fileinfo` — the class of bug that got past every other check because our dev image has every extension installed. Mandatory before cutting a release (see [docs/GUIDE-RELEASE.md](docs/GUIDE-RELEASE.md)).

### Docs drift audit

```bash
./scripts/check-docs-drift.sh
```

Compares the canonical docs (`AGENTS.md` + `docs/*.md`) with the tree: every cited path exists, every
`file.php:NNN` still lands on code, the *Public hooks* table below matches what the code actually
fires, and the counts stated in prose (7 locales, 9 `validate_*_field`, 3 `generate_*_html`, 4 tables,
60 vectors) are real. Runs automatically in `release.sh`'s *Docs drift audit* phase; no Docker needed.

Deliberately not a PHPUnit test: the suite's world is `src/trunk`, and these files live above it, so a
test would skip exactly where the suite normally runs. **Cite symbols, not line numbers** — a
`Class::method()` reference survives an edit above it, `file.php:412` does not (line numbers are
reserved for `vendor/`, which `composer.lock` pins). And when a number appears in prose in more than
one doc, only the `AGENTS.md` one is "current"; the rest must say which measurement and when.

### Platform pin audit

```bash
./scripts/check-platform-pin.sh
```

Audits the `config.platform.php` pin (see "Composer dependencies" below for why it exists and what it costs). No dev stack needed — uses the ephemeral `release` service, or a host `composer`. Runs automatically inside `release.sh`; run it by hand after any change to `src/trunk/composer.json` or the lock.

### Plugin Check

```bash
./start  # provisions and activates plugin-check when needed
docker compose exec -T wordpress wp --allow-root plugin check paycrypto-me-for-woocommerce --format=csv
```

The `start` script provisions and activates `plugin-check` once per WP volume; without it, the check command fails with *"'check' is not a registered subcommand of 'plugin'"*. Expected result: **no `ERROR` in shipped code** (`ERROR`s in `tests/`, `phpunit.xml.dist` and `.phpunit.result.cache` are fine — `release.sh` excludes those paths).

`WARNING`s in shipped code are not free either: the expected result is **zero warnings**. When an operation is intentional and has no sound WordPress API alternative, use the narrowest inline `phpcs:ignore`/`phpcs:disable` with a reviewer-visible reason instead of accepting a noisy report. Current examples are the bundled-locale `load_plugin_textdomain()` call, the live advisory-lock/schema checks in `DbInstaller`, and the deliberate `error_reporting()` calls in `BitcoinAddressService`. The latter must name **both** sniffs that flag them (`WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting` **and** Plugin Check's own `PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall`) because the second fires independently of WPCS.

### Compose form

The docs use `docker compose` (the v2 plugin). On a host that only has the standalone `docker-compose` binary, substitute it in hand-pasted commands — `release.sh`, `smoke-minimal-host.sh` and `build-translations.sh` all detect either form on their own.

### Translations

```bash
npm run translate        # .pot + .po + .mo
npm run translate:pot
npm run translate:mo
```

### Composer dependencies (important)

The crypto dependencies are the official upstream packages, resolved from Packagist — no forks and
no VCS `repositories` block. `bitwasp/bitcoin` `^1.1` is the only crypto package in `require`; it
pulls in `bitwasp/buffertools`, `bitwasp/bech32` and `paragonie/ecc` transitively — the hardened
fork that ships `ConstantTimeMath`, though the adapter actually loaded on the derivation path is
`GmpMath` (measured). That is consistent with E4 in the doc below: constant-time math protects
operations on a *secret* scalar, and this plugin only derives public keys from an xPub. Do not cite
`ConstantTimeMath` as if it were on our hot path. A fresh `composer install` needs **no private repo and no token**,
and no `minimum-stability: dev`.

It is not GitHub-free, though: Packagist serves the metadata, but the dist zips still come from
`codeload.github.com` (true of most Packagist packages). Anonymous downloads are rate-limited — a
clean install can fail with `HTTP/2 429 … Source fallback is disabled`, measured. Two ways out: a
repo-root `auth.json` (`release.sh` forwards it via `COMPOSER_AUTH`, which is why that plumbing is
still there), or `--prefer-source`, which clones instead of downloading zips.

`config.platform.php` is pinned to **`8.1`** — the plugin's real PHP floor, the same value as
`Requires PHP:` in the header — and `composer.json` carries
`"replace": {"lastguest/murmurhash": "2.0.0"}`. The two go together and neither works alone:
`bitwasp/bitcoin v1.1.0` fixes `murmurhash` to the **exact** version `v2.0.0`, which declares
`php: ^7`, so an honest PHP 8 resolution refuses to install it; `replace` drops it from the tree so
the pin no longer has to lie about the platform. Raising the version from our own `require` is
impossible — the upstream constraint is an exact version, so anything else conflicts.

The pin used to be `7.4`, and that was **not** a narrow exemption for one package: it resolved the
whole tree as if on 7.4, so the plugin shipped `paragonie/sodium_compat` a major behind (v1 instead
of v2) and `paragonie/random_compat`, a PHP 5 polyfill measured at **0 files loaded**. Both are gone;
the crypto chain (`bitwasp/*`, `paragonie/ecc`) is byte-identical across the change. Full record and
measurements: [docs/archive/DONE-LEAN-VENDOR-TREE.md](docs/archive/DONE-LEAN-VENDOR-TREE.md) (archived, may be absent — see "Context and guides" above).

What `replace` costs, explicitly: `murmurhash` goes from *installed and never executed* to *not
installed*, so `BitWasp\Bitcoin\Crypto\Hash::murmur3()` — reachable only from
`Bloom/BloomFilter.php:250` — would now fatal instead of no-op. The mitigation is
`VendorReplaceGuardTest`, which greps every hand-written PHP file that ships — `includes/`,
`exceptions/`, `templates/`, the entrypoint and `uninstall.php` — for `lastguest\Murmur`, `murmur3`
and `BloomFilter`, and fails in development rather than in a store. Granularity is the
**method**, not the class: `Hash::sha256()` and the other seven statics are unaffected and safe to
use, so never widen that guard to ban `Crypto\Hash` wholesale.

**`./scripts/check-platform-pin.sh`** audits the pin via `composer why-not --locked php <floor>` (the
floor read from the plugin header, so bumping it moves the check; `--locked` audits the lock, so it
also works with no `vendor/` installed), and distinguishes two regimes:
**pin >= floor** is a *declaration* — it hides nothing and only makes resolution reproducible, so
**any** package blocking the floor fails the script; **pin < floor** is a *suppression* and gets
audited against `ALLOWED_OFFENDERS`, which is now **empty** and should stay that way. Widening that
allowlist, or lowering the pin to make the script pass, is never the fix. It runs automatically in
`release.sh`'s *Platform pin audit* phase. Background: [docs/archive/DONE-CRYPTO-DEPENDENCIES.md](docs/archive/DONE-CRYPTO-DEPENDENCIES.md) → E7/E7.1/E7.2 (archived, may be absent — see "Context and guides" above).

Upstream fix, still unsent and still one line: `lastguest/murmurhash: v2.0.0` → `^2.0` in
`bitwasp/bitcoin` — `2.1.1` already declares `php: ^7||^8.0`. It is the strictly better outcome
because it removes the need for `replace` (and therefore for the guard test) while keeping
`murmur3()` functional. When it lands, drop the `replace` and the guard, and keep the pin as the
declaration it now is.

> **The two `lucas-rosa95/*` forks were retired** — see
> [docs/archive/DONE-CRYPTO-DEPENDENCIES.md](docs/archive/DONE-CRYPTO-DEPENDENCIES.md) (archived, may be
> absent). The `bitcoin` fork carried no source
> fix of its own, was one method *behind* upstream (its absence a fatal on class load), and upstream
> `v1.1.0` passes the full suite and all 60 address vectors unchanged. The side-channel advisories
> that used to sit in `config.audit.ignore` were for `mdanter/ecc`, no longer in the tree, so
> `composer audit` is now clean with no ignore list.

---

## Pro add-on: scope boundaries and extension points

> **Naming note (2026-08-25, settings UI swept 2026-08-27):** the separate add-on was renamed from
> "Premium" to "Pro". This section uses the new name throughout, and as of 2026-08-27 so does this
> plugin's own settings UI: the `paycrypto-pro-field`/`paycrypto-pro-badge` CSS classes, the
> `Abstract_WC_Gateway_PayCryptoMe::pro_soon_badge()` method, the "Pro · Coming soon" badge text and
> the "ships in the upcoming PayCrypto.Me Pro add-on" field descriptions (both gateways) all say
> "Pro" now — translations for the 7 locales were regenerated and recompiled alongside the rename,
> so none of it is fuzzy or stale. Only genuinely frozen history is exempt: past `CHANGELOG.md`
> entries for already-released versions (0.1.0–0.1.2) still say "premium add-on" because that is
> what those releases actually shipped saying, and `docs/archive/DONE-CRYPTO-DEPENDENCIES-AUDIT.md`'s
> mentions of `PREMIUM-ADDON.md` record an actual filename at an actual point in git history —
> neither should be rewritten to match current naming.

Three capabilities are **intentionally absent from this free plugin and reserved for a separate Pro add-on plugin** — deliberate product-scope decisions, not development gaps. Do not treat them as unfinished work or "fix" them into the free version. The approved implementation plan for that separate add-on lives in its own repo, at [`docs/PREMIUM-ADDON.md`](https://github.com/paycrypto-me/paycrypto-me-pro/blob/main/docs/PREMIUM-ADDON.md) — the plan doc itself kept its original filename/history (the repo was renamed from `paycrypto-me-premium` alongside the product rename), see its own naming note.

- **Webhook REST endpoint + async status updates.** The Lightning settings UI references `rest_url('paycrypto-me/v1/webhook')`, but there is deliberately no `register_rest_route()` here. Automatic/async invoice-status confirmation (BTCPay webhook push; lnd polling via `wp_schedule_event`) is a Pro-tier feature.
- **On-chain confirmation tracking.** The base records the receiving address and derivation context only. The Pro add-on owns confirmation polling, transaction history and confirmation-specific persistence; it must not add those fields back to the base table.
- **Fiat → sats conversion.** Invoices are created zero-amount on purpose. Converting the order's fiat total into an `amount_sats` is a Pro-tier feature.

**Delivery model:** the Pro features ship as a separate plugin that depends on this base and plugs in via hooks/filters — never as `if (is_premium())` gating inside this repo. The base exposes these extension points so the add-on is zero-core-edit:

| Extension point | How the add-on uses it |
|---|---|
| `PaymentStatusProjectionCapabilities::all()` | Detect `lightning_invoice_status_cas = 1` before projecting status; absent/zero degrades without fatal. `onchain_confirmation_progress = 0` is deliberate: that state remains only in Pro. |
| `PayCryptoMeLightningDBStatementsService::transition_status()` | Project a Pro decision into the exact Base invoice using atomic compare-and-swap; inspect the typed result instead of guessing from a boolean. |
| `paycryptome_lightning_status_changed` action (see "Public hooks") | Optional post-write notification. Never use it as the only durable trigger for `$order->payment_complete()`; Pro retries from its own persisted decision. |
| `paycryptome_lightning_btcpay_invoice_args` / `_lnd_invoice_args` filters | Already receive `$order` + `$gateway` — the add-on computes `amount` in sats here for fiat→sats. For lnd, set the `value` key (sats) to enforce the amount on the invoice (BTCPay converts fiat itself, so it needs no `value`). |
| `woocommerce_settings_api_form_fields_paycrypto_me_lightning` (native WooCommerce filter) | Append settings fields (e.g. webhook secret) without touching `init_form_fields()` |
| Dependency guard (`class_exists()` + min-version check) | Add-on's own responsibility, not a base concern |

The base payment-details template currently renders only the base payment data. A future official
rendering slot may let Pro append its own transaction section; until that contract exists, Pro must
not replace the base template or assume that arbitrary keys added through the display-data filters
will be rendered.

**The base owns the stable integration boundary.** Pro functionality remains a separate plugin and
must use documented hooks, filters and read-only base services rather than editing base internals or
sharing its schema. New base extension points, such as an official order-details rendering slot,
must be added deliberately and documented here before the Pro add-on depends on them. Licensing SDK
remains an add-on concern (that is why the Freemius SDK lives only there). See §2 and §8.1 of
[`docs/PREMIUM-ADDON.md`](https://github.com/paycrypto-me/paycrypto-me-pro/blob/main/docs/PREMIUM-ADDON.md)
in the add-on's repo.

---

## Known follow-ups

Two low-value, low-risk cleanups are deliberately deferred (pure extract-method, no duplication reduction, zero test coverage as view/config code — not release blockers): `init_form_fields_items()` (Lightning gateway, long method — the shared `init_form_fields()` itself lives in the abstract class) and `enqueue_checkout_styles()` (abstract gateway, long method). The Lightning gateway's 3 HTML generator methods (`generate_node_type_html`/`generate_btcpay_test_button_html`/`generate_lnd_test_button_html`) could similarly move to a render helper if ever prioritized.

---

## Code style notes

- PHP namespace `PayCryptoMe\WooCommerce` everywhere
- Customer-facing strings and admin **settings** strings (field titles, descriptions, labels, buttons) go through `__()` / `esc_html__()` with text domain `paycrypto-me-for-woocommerce`. Admin **errors, warnings, logs**, diagnostic-button feedback and order notes are deliberately **literal English** — no `__()`, no `/* translators: */`. A label interpolated into such a message stays translated (it is a settings string). Full rule and rationale in [docs/GUIDE-TRANSLATION.md](docs/GUIDE-TRANSLATION.md) → "O que entra (e o que NÃO entra) no catálogo"; check it before wrapping a new string. String-authoring rules for anything already in scope (placeholders, brand constants, when to template near-duplicates) are in [docs/GUIDE-I18N-CONVENTIONS.md](docs/GUIDE-I18N-CONVENTIONS.md).
- Sanitize all inputs at system boundaries; trust internal data
- No comments explaining WHAT code does; only WHY when non-obvious
