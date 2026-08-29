=== PayCrypto.Me for WooCommerce ===
Contributors: paycryptome, lucasrosa95
Tags: woocommerce, payments, crypto, bitcoin, lightning-network
Donate link: https://paycrypto.me/
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Requires Plugins: woocommerce
WC requires at least: 6.5
WC tested up to: 10.9
Stable tag: 0.1.2
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Self-hosted, non-custodial Bitcoin payments for WooCommerce — On-Chain (HD wallet) and Lightning Network (BTCPay Server or lnd), no middleman.

== Description ==

PayCrypto.Me for WooCommerce lets your store accept Bitcoin directly into wallets and nodes you control — no custodial processor, no third-party API holding your funds, no percentage cut on every sale.

Two independent, fully self-hosted payment methods, both included:

**Bitcoin On-Chain**
Give the plugin an xPub, yPub or zPub (or a single address) and it derives a fresh, never-reused receiving address for every order via standard HD derivation — your wallet software does the rest. Works on mainnet or testnet, so you can rehearse the full checkout flow with worthless test coins before going live.

**Bitcoin Lightning Network**
Connect the plugin straight to your own BTCPay Server instance or lnd node (REST API, with macaroon authentication and optional TLS certificate pinning). Invoices are created and shown to the customer in seconds — ideal for instant, low-fee payments. A built-in "Test connection" button in the settings screen confirms your node is reachable before you enable the method.

= Built for a modern WooCommerce store =

- **Checkout, your way** — works with both the WooCommerce Blocks (Cart & Checkout) experience and the classic shortcode checkout.
- **High-Performance Order Storage (HPOS)** — declared and tested compatible with WooCommerce's Custom Order Tables.
- **Express Payment button** — an optional one-click "Buy with Bitcoin" button on the checkout page, with a configurable icon position.
- **QR code everywhere it matters** — a scannable payment QR (with copy-to-clipboard and "Open in wallet" deep link) on the Thank You page, in My Account → Orders, and on the admin order screen, so both you and the customer always see the same payment details.
- **Non-custodial by design** — the plugin never takes possession of funds; it only derives addresses and requests invoices from infrastructure you own.
- **Debug logging** — every payment attempt is logged through the native WooCommerce logger (WooCommerce → Status → Logs, source `paycrypto_me`), so troubleshooting a stuck order doesn't require guesswork.
- **Translation-ready** — text domain `paycrypto-me-for-woocommerce`, with complete translations included for Portuguese (Brazil), Spanish, French, German, Italian, Russian and Chinese (Simplified).
- **Developer-friendly** — before/after payment hooks, filters over the invoice arguments sent to BTCPay/lnd, and a filter over the final payment data, so custom logic can hook in without touching plugin code.

= What this plugin intentionally does not do =

To keep the free plugin simple and auditable, two things are left out on purpose and reserved for an upcoming official Pro add-on that plugs into this same base via hooks — no fork, no code duplication:

- **Automatic payment confirmation.** Today, order status is moved forward manually once you've verified the payment yourself (e.g. in your node or block explorer). Automatic confirmation via BTCPay webhooks / lnd polling is planned for the Pro add-on.
- **Fiat → sats conversion.** Lightning invoices are currently created as zero-amount (the wallet reads the amount from the invoice itself once the add-on populates it); automatic conversion of the order's fiat total into an exact BTC/sats amount is also planned for the Pro add-on.

Bitcoin is currently the only supported cryptocurrency (on-chain and Lightning) — this keeps the codebase small and well-tested rather than spreading support thin across many chains.

== Installation ==

1. Make sure WooCommerce is installed and active — the plugin will show an admin notice and stay inactive otherwise.
2. Upload the plugin folder to `/wp-content/plugins/` (or install it via your usual deployment workflow) and activate it from the WordPress Plugins screen.
3. Go to **WooCommerce → Settings → Payments** and you'll see two new methods: "Bitcoin" (On-Chain) and "Bitcoin Lightning". Enable whichever one (or both) you want to accept.
4. **On-Chain:** open its settings and paste your xPub/yPub/zPub (recommended) or a single receiving address, choose mainnet or testnet, and adjust the payment timeout and number of confirmations required to your risk tolerance.
5. **Lightning:** open its settings, choose your node type (BTCPay Server or lnd REST), fill in the connection details (URL, API key/macaroon, optional TLS certificate), and use the "Test connection" button to confirm the plugin can reach it before enabling the method.
6. Testing: switch the On-Chain gateway to testnet, place a test order and confirm the full flow end to end before going live with mainnet.

Notes:
- For troubleshooting, enable debug logging in the gateway settings and check WooCommerce → Status → Logs (source `paycrypto_me`).
- An xPub/yPub/zPub cannot spend your funds, but it can reveal the addresses and activity of that
  wallet account. Treat it as privacy-sensitive financial data and protect access to your WordPress
  database and backups. BTCPay API keys and lnd macaroons are authentication secrets with stronger
  privileges: use least-privilege credentials and protect them accordingly. Never enter a wallet
  seed or private key into this plugin.
- Only Bitcoin is currently supported (on-chain and Lightning). Support for additional networks may be considered in future updates.
- Payment confirmation is currently a manual, admin-driven step — see "What this plugin intentionally does not do" above.
- **PHP extensions:** deriving addresses from an xPub/yPub/zPub requires the `gmp` extension. Without it, that route is unavailable (with an admin notice explaining it), but you can still accept On-Chain payments by configuring a single fixed bech32 address (`bc1…`/`tb1…`), which needs no such extension — every order is then paid to that same address, which is worse for privacy but works. Lightning is unaffected either way. The payment QR code requires `gd`; if it's missing, the order-details page still shows the address/invoice and a copy button, just without the QR image.

== Screenshots ==

1. Checkout page with the Bitcoin On-Chain and Lightning payment options
2. Thank you / order-received page showing the payment QR code and address
3. Admin order screen showing the same payment details for support and reconciliation
4. WooCommerce → Settings → Payments listing both PayCrypto.Me gateways
5. Bitcoin On-Chain gateway settings (network, xPub, timeout, confirmations)
6. Bitcoin Lightning gateway settings (node type, connection details, test connection button)

== Frequently Asked Questions ==

= Which cryptocurrencies are supported? =
Bitcoin only, through two independent methods: On-Chain (mainnet/testnet, address derived from your xPub/yPub/zPub) and Lightning Network (via your own BTCPay Server or lnd node).

= Does the plugin take custody of my funds at any point? =
No. On-Chain payments go straight to addresses derived from your own extended public key; Lightning invoices are created and settled directly by your own BTCPay Server or lnd node. The plugin never holds keys or funds.

= Can I run both On-Chain and Lightning at the same time? =
Yes — enable both gateways and customers choose whichever they prefer at checkout.

= Do I need to run my own Lightning node? =
Yes, you need access to a BTCPay Server instance or an lnd node with its REST API reachable from your WordPress host. The plugin is a client to infrastructure you already run or control.

= Does it work with WooCommerce Blocks checkout? =
Yes, both gateways support the Blocks-based Cart & Checkout as well as the classic shortcode checkout, and both declare compatibility with High-Performance Order Storage (HPOS).

= How do I test payments safely before going live? =
Switch the On-Chain gateway's network to testnet and place a test order, or use your Lightning node's own testing/regtest setup if it supports one — the plugin itself only exposes mainnet/testnet for On-Chain.

= Where are payment logs stored? =
Through the native WooCommerce logger, source `paycrypto_me`. Access them via WooCommerce → Status → Logs.

= Does the order status update automatically once the customer pays? =
Not in the free plugin — see "What this plugin intentionally does not do" in the description. Automatic confirmation is planned for a future Pro add-on.

= Does the plugin store my wallet's private keys? =
No. The plugin never asks for or stores a wallet seed or private key. For HD address derivation it
stores the extended public key (xPub/yPub/zPub) you provide. An extended public key cannot authorize
spending, but it can derive the account's public addresses and reveal its transaction history, so it
should still be treated as privacy-sensitive financial data.

== Privacy ==

This plugin stores the following data needed to process Bitcoin payments:

- Your wallet's extended public key (xPub/yPub/zPub) or single receiving address, and every address
  derived from it, in dedicated database tables (`{prefix}paycrypto_me_bitcoin_wallet_xpubkeys`,
  `{prefix}paycrypto_me_bitcoin_derivation_indexes`, `{prefix}paycrypto_me_bitcoin_transactions_data`).
  The extended public key is stored in readable form because the plugin needs it to derive addresses
  and reconnect the same wallet to its previous derivation indexes. It cannot authorize spending,
  but it is privacy-sensitive because it can reveal that wallet account's addresses and activity.
- Lightning node connection details you provide (BTCPay Server or lnd URL, API key/macaroon, optional TLS certificate) in the gateway settings (`wp_options`), and every created invoice in `{prefix}paycrypto_me_lightning_invoices`.
- No customer personal data beyond what WooCommerce already stores with the order — the plugin only attaches the payment address/invoice details as order meta.

None of this data leaves your WordPress installation: the plugin only talks to your own wallet-derivation logic and to the BTCPay Server/lnd node you configure, never to a third-party API.

**On uninstall, both gateways' settings are deleted** — including the Lightning node credentials (API key, macaroon, TLS certificate), so those secrets are not left behind in your database.

**The payment record tables are deliberately kept** (`{prefix}paycrypto_me_bitcoin_wallet_xpubkeys`,
`{prefix}paycrypto_me_bitcoin_derivation_indexes`, `{prefix}paycrypto_me_bitcoin_transactions_data`,
`{prefix}paycrypto_me_lightning_invoices`), so the payment history of past orders stays intact for
accounting and reconciliation after the plugin is removed. Keeping the wallet and derivation-index
records also prevents a later reinstall from starting the same wallet at an old index and reusing
payment addresses. Because this retains the privacy-sensitive extended public key, protect database
backups and drop these tables manually only when you no longer need the history and do not intend to
resume derivation from that wallet through this plugin.

== Changelog ==

= 0.1.2 =
* Fixed: saving the Bitcoin On-Chain settings works again on hosts that display PHP errors (typically a staging site with WP_DEBUG on), where notices from the Bitcoin library printed on the screen and broke the redirect after saving with "headers already sent". The payment page is quiet the same way.
* Changed: the bundled libraries were updated to the versions built for PHP 8.1, which this plugin has always required. They were being installed as if the site ran PHP 7.4, so the download carried an encryption compatibility layer a full version behind and one package that never ran at all. Both are gone and the plugin ships smaller. This is routine maintenance, not a security fix — no advisory applied to the previous versions — and payments, invoices and QR codes work exactly as before.
* Changed: the Bitcoin libraries now come from their official source instead of a personal copy. The same addresses are derived, verified against all 60 address test vectors.
* Fixed: on a site running a PHP older than 8.1, the plugin now stops loading and explains why in the admin instead of taking the whole site down. WordPress already prevents activating or updating it below 8.1, so this only affects a site whose PHP was downgraded after the plugin was already active.

= 0.1.1 =
* On-chain payments now work on hosts without the PHP GMP extension, as long as a single fixed bech32 address (bc1…/tb1…) is configured. On those hosts a perfectly valid xPub used to be rejected as "not valid for the selected network" — a host limitation reported as your mistake.
* A gateway that is enabled but cannot take payments no longer vanishes from checkout without explanation: its settings screen now lists exactly what is missing (a PHP extension, the network/xPub, or the BTCPay/lnd credentials for the selected node type).
* Fixed: a BTCPay Server or lnd URL that could not be stored was saved empty while the page reported "settings saved".
* Fixed: the connection tester now shows the real transport error (DNS, TLS, timeout) instead of "Request failed (HTTP 0)", and says so when a TLS certificate could not be written to a temporary file.
* Fixed: an expired Lightning invoice is shown as "Expired" instead of "Awaiting Payment", without a QR code no wallet would accept. An invoice reused on a checkout retry is no longer shown as expired while the node would still settle it.
* Fixed: copying the payment address on the admin order screen no longer submits the order form.
* Fixed: the On-Chain "Hide for Non-Admin Users" setting no longer hides the Lightning gateway as well.
* Fixed: a database table that fails to install is retried instead of being recorded as up to date, and the warning stays visible until the problem is actually solved.
* Fixed: an order-details panel that fails to render now says so instead of showing an empty page, and a QR code that cannot be generated is logged.
* Changed: admin errors, warnings and logs are always in English now. Everything your customers read stays translated (7 locales, 100%).

= 0.1.0 =
* Initial public release.
* Bitcoin On-Chain gateway: HD address derivation from xPub/yPub/zPub, mainnet and testnet, configurable payment timeout and required confirmations.
* Bitcoin Lightning gateway: BTCPay Server and lnd REST support, with an in-admin connection tester for each.
* WooCommerce Blocks (Cart & Checkout) and classic shortcode checkout support, including an optional Express Payment ("Buy with Bitcoin") button.
* High-Performance Order Storage (Custom Order Tables) compatibility.
* Payment QR code with copy-to-clipboard and "open in wallet" link on the Thank You page, My Account and admin order screens.
* Debug logging via the WooCommerce logger.
* Initial translations for pt_BR, es_ES, fr_FR, de_DE, it_IT, ru_RU and zh_CN.
* Developer extension points reserved for the upcoming Pro add-on, with no effect on the free plugin: amount-enforced lnd invoices, an on-chain confirmation-tracking hook, order-details display filters, and dedicated on-chain payment filters.

== Upgrade Notice ==

= 0.1.2 =
Fixes saving the On-Chain settings on hosts that display PHP errors. Bundled libraries updated to the versions built for the PHP 8.1 this plugin already requires (maintenance, not a security fix), and a site below PHP 8.1 now gets an explanation instead of a fatal error.

= 0.1.1 =
Admin errors, warnings and logs are now always in English; customer-facing text stays translated. A gateway that cannot take payments explains why instead of vanishing from checkout, and on-chain works without the GMP extension when a fixed bech32 address is configured.

= 0.1.0 =
Initial release.

== Support ==

For support, visit https://paycrypto.me/ or open an issue on the plugin's GitHub repository.

== Credits ==

Developed by PayCrypto.Me — https://paycrypto.me/

Built with the open-source `bitwasp/bitcoin` library for HD key derivation and `endroid/qr-code` for QR code generation.
