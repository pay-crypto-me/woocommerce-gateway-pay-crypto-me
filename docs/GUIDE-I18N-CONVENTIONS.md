# [GUIDE] i18n String-Authoring Conventions

This guide governs how strings already in translation scope are authored. Whether a string belongs
in the catalog is decided first by [GUIDE-TRANSLATION.md](GUIDE-TRANSLATION.md): customer-facing and
settings copy is translated; admin diagnostics, logs, diagnostic feedback and order notes remain
literal English.

## Product and technical names

- Names controlled by this project never appear literally inside a `msgid`. Use a placeholder and
  `WC_PayCryptoMe::NAME_BRAND`, `NAME_PRO_ADDON`, or `NAME_PRO_ADDON_SHORT`.
- Stable third-party/generic terms such as BTCPay Server, Lightning Network, Bitcoin, WooCommerce
  and GitHub remain translatable. When reused as an argument, translate the standalone term.
- Technical identifiers such as `BTC-LN` are not translated; use
  `WC_PayCryptoMe::BTCPAY_DEFAULT_PAYMENT_METHOD_ID`.

## Complete, reorderable messages

- Never construct one grammatical sentence by concatenating translated fragments. Use one complete
  format string with `sprintf()`.
- Number every placeholder when a message has more than one (`%1$s`, `%2$d`). A single placeholder
  may remain `%s`.
- Put a `/* translators: ... */` comment immediately above every translation call whose `msgid`
  contains a placeholder. Name the exact placeholder tokens in the comment.
- Do not template agreement-sensitive nouns or adjectives merely to reduce catalog entries. Keep
  independent full sentences when gender, case or number may change surrounding grammar.
- A template may accept complete self-contained clauses when those clauses do not grammatically
  depend on the surrounding sentence.

## Context, markup and examples

- Use `_x()`/`esc_html_x()` for short or ambiguous labels, with specific context.
- Do not embed HTML in a `msgid`. Translate the plain-text components and apply markup outside the
  catalog.
- Do not embed a literal default/example value when the real value already has a shared constant or
  translated string; insert that source through a placeholder.
- Joining independently rendered components—such as a badge, a line break and a separate sentence—
  is not grammatical-string concatenation.

## JavaScript translations

- Block strings use `@wordpress/i18n` and share the PHP text domain, but require WordPress JSON
  catalogs in addition to PO/MO files.
- Run `./scripts/build-translations.sh` after source-string changes, translate and validate each PO,
  then run `./scripts/build-translations.sh json <locale>`.
- There are two registered block bundles, so the shipped result is exactly two runtime JSON files
  per locale (14 for seven locales). Source-path JSON names are temporary and are renamed to the
  runtime hashes measured from WordPress; they are not retained as redundant artifacts.
- When a msgid appears in PHP and JS, keep its translated value manually consistent across both
  surfaces.

## Enforcement

Run `./scripts/check-i18n-conventions.sh`. It rejects direct translated-string concatenation outside
the narrow HTML-component allowlist, volatile product names inside translation calls, and
placeholder-bearing msgids without an immediately preceding translator comment. The release script
runs this audit automatically when tests are enabled.

The completed retrofit and its measured decisions are recorded in
`docs/archive/DONE-I18N-CONVENTIONS.md`. That archive is gitignored and may be absent from a fresh
checkout; Git history remains the source for the execution record.
