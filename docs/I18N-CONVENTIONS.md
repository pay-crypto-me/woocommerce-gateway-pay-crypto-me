# i18n Rewrite & Conventions — PayCrypto.Me for WooCommerce

This plan is persisted in `docs/` and linked from `CLAUDE.md`'s "Context and guides" list, matching how other approved-but-not-yet-executed plans in this repo are kept discoverable (see `docs/SCHEMA-UPGRADE-AND-STATIC-RECORDS.md` for the precedent). It is written to be executable by a fresh agent or human with only this file and the codebase — no other context is assumed. It has been through one independent, code-verified review pass (2026-08-27) that found and corrected real drift between the original draft and the actual code; the corrections are folded in below, not left as separate errata. **No part of Phase 1–6 below has been executed yet** — this document is the plan, not a record of completed work.

**Status: approved plan, not started.**

**A note on line numbers in this document:** every `~LNNN` cited below was measured against the tree on 2026-08-27, before any part of this plan has executed. Executing Phase 1 will shift most of them. Treat the file path + method/class/field name as the authoritative locator; use the line number only as a "you should find this nearby" sanity check, and re-locate by searching for the quoted code/string if it doesn't match. `scripts/check-docs-drift.sh` greps specifically for the tight `[A-Za-z0-9_./-]+\.php:[0-9]{1,4}` pattern and will flag any citation in that exact form once line numbers drift — this document deliberately avoids that exact form throughout (spaced `file.php` ... `~LNNN` style instead), verified clean as of persisting; re-verify with `grep -noE '[A-Za-z0-9_./-]+\.php:[0-9]{1,4}' docs/I18N-CONVENTIONS.md` if you add new citations while executing this plan.

---

## Context

The companion "Pro" add-on plugin was renamed from "Premium" to "Pro" on 2026-08-25. Fixing the base plugin's own references to match cost ~30 hand-edited strings across settings UI, CSS classes, a PHP method name, and manual fuzzy-match repair across all 7 locale `.po` files (pt_BR, es_ES, de_DE, fr_FR, it_IT, ru_RU, zh_CN) — entirely because the add-on's name was typed literally inline inside full English sentences passed to `__()`. That specific case is now fixed (see git history, commit `chore: rename Premium add-on to Pro throughout the base`), but the underlying anti-pattern — proper nouns baked into translatable strings, near-duplicate sentences maintained as separate strings, sentences built by concatenating multiple `__()` calls — is still present throughout the rest of the codebase. A full audit found **138 translation call sites (136 PHP + 2 JS)** against **116 msgids** in the current `.pot`, ~15 proper-noun-embedding occurrences, 4 sentence-concatenation anti-pattern sites, and roughly 14 near-duplicate string clusters (lettered A–O in the audit; see Phase 1's "Clusters assessed and deliberately left unchanged" for the full disposition of every letter).

This plan:
1. Establishes a written convention (**Part 1**) for how a translatable string should be authored in this codebase going forward, grounded in this project's own history and examples — not generic advice.
2. Retrofits the existing catalog to comply with it, removing the categories of risk that caused the Premium→Pro pain (**Part 2, Phase 1**). Note: this is **not** primarily a raw string-count reduction exercise — templating a 3-way duplicate into 1 template + several short reusable fragments is roughly count-neutral (Phase 1's net effect across the whole retrofit is a handful of strings either way, not a large drop). The real win is eliminating brand-name duplication and rename risk, which is where the Premium→Pro pain actually came from.
3. Closes a real, separate gap found during the audit: the plugin's block-editor JS strings (`@wordpress/i18n`'s `__()` in the two block source files) are never extracted into any translation catalog at all, because the build script passes `--skip-js` to `wp i18n make-pot` (**Part 2, Phase 2**).
4. Adds an automated regression check, in the project's existing style (a custom bash script wired into `release.sh`, matching `scripts/check-docs-drift.sh` — no PHPCS/WPCS/CI is introduced; the project deliberately has none today) (**Part 2, Phase 4**).

Three scope decisions were made explicitly during planning and must not be revisited without re-opening this plan:
- **All flagged proper nouns get a single source of truth, but by two different mechanisms depending on rename risk** — see Part 1, rule (b), "Class A vs Class B". `PayCrypto.Me`/`Pro` (a name this team controls and has already renamed once) become raw PHP constants outside the translation catalog; `BTCPay Server`/`lnd`/`Lightning Network`/`Bitcoin`/`WooCommerce`/`GitHub` (stable third-party/generic terms with zero rename risk) stay inside the catalog as translated strings.
- **The JS extraction gap is fixed**, not documented as an accepted exception.
- **Enforcement extends the project's existing bash-script pattern**, not PHPCS/WPCS.

A note on notation: this codebase has **no `$td` shorthand variable anywhere**. All 136 PHP call sites pass the text domain as the literal string `'paycrypto-me-for-woocommerce'`. Every code snippet below uses that literal, spelled out, exactly as it must appear in the real file — do not introduce a `$td` variable as a "convenience," and do not search-and-replace `$td` against the real files (it does not exist there).

---

## Part 1 — The Convention

*Applies to every new translatable string written in this codebase from the moment this plan is accepted, independent of whether Part 2's retrofit has run yet.*

This document covers **how** an in-scope string should be authored. **Whether** a string is in scope for translation at all — the customer-facing / admin-settings / vs. admin-error-log-order-note split — is decided in [docs/TRANSLATION.md](TRANSLATION.md) → "O que entra (e o que NÃO entra) no catálogo". Read that first; this document does not repeat it, and nothing below overrides it.

### (a) No proper noun that this team controls goes directly inside a `msgid` — placeholder + constant

**Why:** the Premium→Pro rename. A constant makes a future rename touch one PHP line and zero `.po` files, instead of ~30 strings across 7 locales.

- Non-compliant (the exact pattern that caused the pain, now fixed but illustrative):
  ```php
  __('Reserved for the Pro add-on. Not used by the free version.', 'paycrypto-me-for-woocommerce')
  ```
- Compliant:
  ```php
  sprintf(
      /* translators: %s: add-on name (not translated, product name). */
      __('Reserved for the %s add-on. Not used by the free version.', 'paycrypto-me-for-woocommerce'),
      WC_PayCryptoMe::NAME_PRO_ADDON_SHORT
  )
  ```

### (b) Brand tokens reused across ≥2 strings get one source of truth — mechanism depends on rename risk (Class A vs. Class B)

- **Class A — this project's own product/marketing name** (`PayCrypto.Me`, `PayCrypto.Me Pro`, `Pro`): a **raw PHP constant**, referenced via `sprintf('%s', ...)`, the constant itself **never** wrapped in `__()`. It has a proven rename history in this exact codebase. Constants (added to `WC_PayCryptoMe` in `src/trunk/paycrypto-me-for-woocommerce.php`, next to the existing `URL_SUPPORT`/`URL_PRO`/`URL_GITHUB`):
  ```php
  public const NAME_BRAND           = 'PayCrypto.Me';
  public const NAME_PRO_ADDON       = 'PayCrypto.Me Pro';  // full form, longer sentences
  public const NAME_PRO_ADDON_SHORT = 'Pro';                // short form, tight UI (badge, action link)
  ```
  Home rationale: `WC_PayCryptoMe` is already the project's constants holder, and every gateway file already depends on it loading first (`includes()` runs from `WC_PayCryptoMe::__construct()`, only reachable via `plugins_loaded` → `WC_PayCryptoMe::instance()`, before any gateway file executes). No dedicated constants file — that would fragment an existing, working convention.

- **Class B — stable third-party/generic brand terms** (`BTCPay Server`, `lnd`, `Lightning Network`, `Bitcoin`, `WooCommerce`, `GitHub`): **stay inside `__()`**, passed as a `sprintf` argument when reused, e.g. `sprintf(__('Payment via %s failed. Please try again.', 'paycrypto-me-for-woocommerce'), __('BTCPay Server', 'paycrypto-me-for-woocommerce'))`. No rename risk exists for these, and hardcoding them as raw constants would silently remove a translator's ability to adapt spelling/spacing/transliteration for a locale that wants it — a real cost with no corresponding benefit.

  **Correction from the review pass:** the phrase "already correctly deduplicated as shared `__()` strings" (an earlier draft of this document) overstated what exists today. Checking the actual `.pot`: standalone single-word/short-phrase msgids exist today only for `"Lightning Network"`, `"On-Chain"`, `"GitHub"`, `"Bitcoin Payments"` (the last one is retired by Phase 1 item 5, see below). There is **no** existing standalone `"BTCPay Server"`, `"lnd"`, `"Bitcoin"`, or `"WooCommerce"` msgid — those words today only ever appear as part of longer, already-distinct field-label phrases like `"BTCPay Server URL"` (reused 5×) or `"lnd REST URL"` (reused 5×), which are correct and untouched by this plan. Phase 1 items 11 and 13 below **create two new** standalone Class-B msgids (`"BTCPay Server"`, `"Lightning node"`) as part of fixing embedded-literal sites — this is new catalog content, not reuse of something pre-existing. "One source of truth" going forward means: once a Class B term is extracted as its own msgid anywhere, later code reuses that same msgid rather than retyping the phrase — not that this already existed everywhere before this plan.

- **Class C — technical/protocol tokens, never translated, not a brand** (`BTC-LN`): a raw PHP constant, same treatment as Class A, because it is a technical identifier, not prose. Add to `WC_PayCryptoMe`:
  ```php
  public const BTCPAY_DEFAULT_PAYMENT_METHOD_ID = 'BTC-LN';
  ```

**Explicitly NOT extracted, by design** (Class B terms that are the entire semantic content of an already-short, already-deduped label, not an embedded fragment of a longer sentence): `"Pay with Bitcoin"`, `"On-Chain"`, `"Lightning Network"` as their own standalone labels — these remain untouched by this plan. (`"Bitcoin Payments"` is **not** in this list — see Phase 1 item 5: it is retired and replaced by the `bitcoin_payments_title()` template, because the plugin only ever used it as the fixed first half of a concatenated title, never standalone.) Templating "Bitcoin" out of `"Pay with Bitcoin"` (e.g. `sprintf(__('Pay with %s', 'paycrypto-me-for-woocommerce'), 'Bitcoin')`) would add catalog complexity with zero dedup or rename-safety benefit — the string never repeats with a different noun, and "Bitcoin" will not be renamed. Do not "fix" these.

### (c) Never build one grammatical sentence by concatenating translated strings, or a translated string with a raw variable — use `sprintf` with placeholders

**Why:** word order and spacing are not universal across languages, and concatenated fragments can't be reordered per locale the way `%1$s`/`%2$s` can.

- Non-compliant (`src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php`, `init_form_fields()`, the `enabled` field's `label`, ~L249):
  ```php
  'label' => __('Enable', 'paycrypto-me-for-woocommerce') . ' ' . $this->method_title,
  ```
- Compliant:
  ```php
  'label' => sprintf(
      /* translators: %s: gateway title, e.g. "Bitcoin Payments (On-Chain)". */
      __('Enable %s', 'paycrypto-me-for-woocommerce'),
      $this->method_title
  ),
  ```
- Applies equally to two label+full-sentence splices, **both actually in `src/trunk/includes/class-wc-gateway-paycrypto-me.php`, not the abstract class** (corrected during review — an earlier draft misattributed the Danger Area splice to `abstract-class-wc-gateway-paycrypto-me.php`; that file's own line ~311 is a different, unrelated string): the Danger Area "Warning:" line (~L311 in `class-wc-gateway-paycrypto-me.php`) and the donate-box "Enjoying the plugin?" line (~L328, in `abstract-class-wc-gateway-paycrypto-me.php` — this one *is* in the abstract class; only the Danger Area one is not). Exact fixes in Part 2, Phase 1, item 7.
- **Not an anti-pattern** (documented so it is never "fixed" by mistake): `$this->pro_soon_badge() . '<br>' . __(...)` — this joins two *independently rendered* fragments (an HTML badge component and a separate sentence) with a line break, not word-order-dependent text concatenation. Leave these as-is; only the string *inside* the trailing `__(...)` call is in scope for other rules.

### (d) Multiple placeholders are always numbered (`%1$s`, `%2$d`, …), never bare `%s %s`; one canonical `translators:` comment format

**Why:** numbered placeholders let a translator reorder them for target-language grammar; bare `%s %s` locks English word order into every translation.

**Canonical format, all new code from this plan forward — when there is more than one placeholder:**
```php
/* translators: %1$s: description of first placeholder, %2$d: description of second placeholder. */
```
Reference the actual placeholder token (`%1$s`, `%2$d`, …), not a bare position number like `1:`/`2:`. **A single placeholder may stay as plain `%s`** with a plain `/* translators: %s: description. */` comment — numbering is only mandatory once there are two or more.

**The comment goes on the source line immediately above the translation function call itself** (the `__(`/`_e(`/`esc_html__(` line), not above an outer `sprintf(` wrapper line if they differ — this matters mechanically: the enforcement check added in Phase 4 looks at exactly one line above the call.

**Do not retrofit** the two existing "grandfathered" examples — they are correct under the old style and are explicitly out of scope (see Part 2's "do not touch" list):
- `src/trunk/includes/processors/class-bitcoin-payment-processor.php` (~L176-177): `/* translators: 1: payment address, 2: order reference number. */`
- `src/trunk/templates/order-details/paycrypto-me-order-details.php` (~L35-41): `/* translators: %d: number of confirmations required */`

### (e) When to template near-duplicate sentences vs. leave them separate

Template when the varying part is a small, closed set of nouns/adjectives or **whole self-contained clauses** plugged into an otherwise-identical, ≥3-times-repeated skeleton that carries a Class A/B brand token (rule a/b) — worked example: the "ships in the upcoming … add-on" cluster, Part 2 Phase 1 item 8.

**Do NOT template** when the varying part is a single word or short noun/adjective phrase that must grammatically *agree* (gender, case, number) with fixed surrounding text, since slotting it into a bare `%s` risks breaking that agreement in languages where it matters (a documented WordPress i18n handbook caution). Two worked "leave separate" examples from this codebase, to calibrate future judgment calls:
- `abstract-class-wc-gateway-paycrypto-me.php` — `"Payment method name displayed on Checkout page."` / `"Payment method description displayed on Checkout page."` (~L256, L262): leave as 2 full independent strings. "name" and "description" have different grammatical gender in German/French/Portuguese; forcing them into `sprintf(__('Payment method %s displayed on Checkout page.', 'paycrypto-me-for-woocommerce'), ...)` would break agreement with surrounding articles in those languages.
- Four "we could not X, please Y" customer-facing error strings, spread across `abstract-class-wc-gateway-paycrypto-me.php` (~L140), `abstract-class-lightning-processor.php` (~L85), `exceptions/PayCryptoMePaymentException.php` (~L19), and `templates/order-details/paycrypto-me-order-details.php` (~L102): leave as 4 independent strings. Each has a different failure context; rigid templating here is the textbook case the handbook warns against.
- `class-payment-processor.php` "Selected payment method cannot be processed." / "is not supported." (~L164, L176), tail "Please try choosing another one.": leave as 2 independent strings, same reasoning.

**Why item 8 (Phase 1) is templating and not violating this rule, even though it slots in variable content:** the "ships in the upcoming … add-on" cluster's placeholders hold *whole independent clauses* ("Automatic order expiry after the timeout", "payments are verified manually") that are grammatically self-contained sentences/phrases in their own right — not single agreement-sensitive nouns that must inflect to match a surrounding frame. Gluing complete clauses together with a fixed connective ("X ships in the upcoming Y add-on. In the free version, Z.") is structurally closer to sentence-level composition (already how rule (c)'s `sprintf('<strong>%s</strong> %s', ...)` fix works) than to the word-level slotting rule (e) warns against. If a future case is unsure which side of this line it falls on, prefer leaving strings separate — the cost of an extra msgid is much lower than the cost of broken grammar in a shipped locale.

### (f) `_x()`/context comments for short or ambiguous strings

**Why:** `esc_html__('Copy', 'paycrypto-me-for-woocommerce')` (`abstract-class-wc-gateway-paycrypto-me.php` ~L331) has zero disambiguation, and this codebase has zero `_x()`/`esc_html_x()` calls anywhere today — this convention introduces the first one.

- Compliant:
  ```php
  esc_html_x('Copy', 'button label: copy the Bitcoin donation address to the clipboard', 'paycrypto-me-for-woocommerce')
  ```

### (g) Don't bake HTML markup or a literal technical/example value into a `msgid`

- HTML markup: split it out, apply it in PHP around two plain-text calls via `sprintf('<strong>%s</strong> %s', ...)` (rule c) — no placeholder-inside-msgid, no translators comment needed for this shape since neither `%s` sits inside a translatable string, they're arguments to a non-translated formatting `sprintf`.
- Literal technical value embedded in prose (e.g. the constant `"BTC-LN"` typed directly into an English sentence): replace with a placeholder sourced from the Class C constant — and check for the **other production copies of the same literal** before assuming there's only one (see Phase 1 item 11's expanded scope).
- Literal default-value example embedded in prose (e.g. `'... the default label will be used \'Buy with\''`): replace the quoted literal with a placeholder referencing the actual `__('Buy with', 'paycrypto-me-for-woocommerce')` shared string, so the description can never drift from the real default.
- **Accepted, not worth fixing:** the icon-position example text (`"₿ Pay with" (left)` / `"Pay with ₿" (right)`, `abstract-class-wc-gateway-paycrypto-me.php` ~L296) — illustrative UI examples referencing the English default, no brand token, no rename risk. Document as a known limitation, do not engineer a fix.

### (h) The admin-error/log/order-note exclusion policy is authoritative and unchanged

Nothing in this document touches [docs/TRANSLATION.md](TRANSLATION.md)'s rule that admin errors, warnings, logs, diagnostic-button feedback, and order notes stay literal English with no `__()` and no `translators:` comment — including the "a translated field-label interpolated into an otherwise-untranslated admin error message stays translated" exception (canonical example: `class-lightning-config-validator.php`, `sprintf('%s must use HTTPS.', esc_html__('BTCPay Server URL', 'paycrypto-me-for-woocommerce'))`). These sites (validator and connection-tester `sprintf()` calls building admin error/test messages, corresponding to audit clusters K/L/M) are **out of scope for this plan** — do not add `__()` around their outer format strings, do not add `translators:` comments to them.

### (i) JS-side i18n via `@wordpress/i18n` + `wp_set_script_translations()` is first-class, not skipped

PHP gettext (`.po`/`.mo`) and the WordPress script-translation JSON format are **two separate translation surfaces sharing a text domain but not a file format**. A string that legitimately appears on both sides (here: `"Pay with Bitcoin"`, used identically in PHP at `abstract-class-wc-gateway-paycrypto-me.php` (~L257) and in JS at `includes/blocks/js/paycrypto_me-blocks.js` (~L9) / `paycrypto_me_lightning-blocks.js` (~L9)) must be translated **twice** — once in the `.po` entry (feeds `.mo`, used by PHP `__()`), once in the equivalent entry that lands in the per-script `.json` file (feeds `wp_set_script_translations()`, used by JS `__()`). Nothing enforces that they match; a translator/agent filling in one must fill in the same target text in the other. This is a manual-parity requirement to remember, not a bug to fix.

---

## Part 2 — Rewrite Plan

*One-time execution to bring the existing 116-msgid catalog + 2 unextracted JS strings into compliance with Part 1.*

### Phase 0 — Preconditions (already satisfied by this plan's own approval)

Scope decisions are locked (see Context section above). Do not re-litigate them mid-execution; if something turns out to be wrong once you're in the code, **stop and flag it rather than silently deciding differently** — this plan has already been through one review pass specifically to minimize how often that should happen, but it is not infallible.

### Phase 1 — Code changes (no `.pot`/`.po`/`.mo` touched yet)

**1. Add constants** to `WC_PayCryptoMe` in `src/trunk/paycrypto-me-for-woocommerce.php`, next to the existing `URL_SUPPORT`/`URL_PRO`/`URL_GITHUB` (~L71-73):
```php
public const NAME_BRAND                       = 'PayCrypto.Me';
public const NAME_PRO_ADDON                   = 'PayCrypto.Me Pro';
public const NAME_PRO_ADDON_SHORT             = 'Pro';
public const BTCPAY_DEFAULT_PAYMENT_METHOD_ID = 'BTC-LN';
```

**2. Add three protected helper methods** to `src/trunk/includes/abstract-class-wc-gateway-paycrypto-me.php`, near the existing `pro_soon_badge()` (~L562-567):
```php
protected function bitcoin_payments_title(string $network_label): string
{
    return sprintf(
        /* translators: %s: payment network name, e.g. "On-Chain" or "Lightning Network". */
        __('Bitcoin Payments (%s)', 'paycrypto-me-for-woocommerce'),
        $network_label
    );
}

protected function bitcoin_payments_description(string $mode_clause, string $network_label): string
{
    return sprintf(
        /* translators: %1$s: custody/hosting mode clause, e.g. "Non-custodial" or "self-hosted", %2$s: payment network name, %3$s: brand name (not translated, product name). */
        __('Accept Bitcoin payments %1$s via %2$s (Provided by %3$s).', 'paycrypto-me-for-woocommerce'),
        $mode_clause,
        $network_label,
        WC_PayCryptoMe::NAME_BRAND
    );
}

protected function pro_feature_notice(string $feature_clause, string $free_version_clause): string
{
    return sprintf(
        /* translators: %1$s: feature description, %2$s: add-on name (not translated, product name), %3$s: free-version behavior description. */
        __('%1$s ships in the upcoming %2$s add-on. In the free version, %3$s.', 'paycrypto-me-for-woocommerce'),
        $feature_clause,
        WC_PayCryptoMe::NAME_PRO_ADDON,
        $free_version_clause
    );
}
```
(Comment format corrected during review — an earlier draft used bare `1:`/`2:` position numbers in `bitcoin_payments_description()`, violating rule (d) itself; fixed to `%1$s:`/`%2$s:`/`%3$s:` above.)

**3. Update `pro_soon_badge()`** (same file, currently 3 lines, ~L564-566):
```php
return '<span class="paycrypto-pro-badge">'
    . esc_html__('Pro · Coming soon', 'paycrypto-me-for-woocommerce')
    . '</span>';
```
Replace with:
```php
return sprintf(
    '<span class="paycrypto-pro-badge">%s</span>',
    esc_html(sprintf(
        /* translators: %s: short add-on name (not translated, product name), e.g. "Pro". */
        __('%s · Coming soon', 'paycrypto-me-for-woocommerce'),
        WC_PayCryptoMe::NAME_PRO_ADDON_SHORT
    ))
);
```

**4. `src/trunk/paycrypto-me-for-woocommerce.php`** (~L182). This value is consumed as the second `%s` of an already-escaped `sprintf('<a href="%s" ...>%s</a>', ...)` at ~L179-183 — write the final, correctly-escaped code directly rather than leaving the escaping to be worked out later. Replace:
```php
esc_html__('Get Pro', 'paycrypto-me-for-woocommerce')
```
with:
```php
esc_html(sprintf(
    /* translators: %s: short add-on name (not translated, product name), e.g. "Pro". */
    __('Get %s', 'paycrypto-me-for-woocommerce'),
    WC_PayCryptoMe::NAME_PRO_ADDON_SHORT
))
```

**5. Cluster A — gateway title/description, fixes the concatenation anti-pattern and the "PayCrypto.Me" embedding together.**
`src/trunk/includes/class-wc-gateway-paycrypto-me.php` (~L35-36), replace:
```php
$this->method_title = __('Bitcoin Payments', 'paycrypto-me-for-woocommerce') . ' (' . __('On-Chain', 'paycrypto-me-for-woocommerce') . ')';
$this->method_description = __('Accept Bitcoin payments Non-custodial via On-Chain', 'paycrypto-me-for-woocommerce') . ' (' . __('Provided by PayCrypto.Me', 'paycrypto-me-for-woocommerce') . ').';
```
with:
```php
$this->method_title = $this->bitcoin_payments_title(__('On-Chain', 'paycrypto-me-for-woocommerce'));
$this->method_description = $this->bitcoin_payments_description(
    __('Non-custodial', 'paycrypto-me-for-woocommerce'),
    __('On-Chain', 'paycrypto-me-for-woocommerce')
);
```
`src/trunk/includes/class-wc-gateway-paycrypto-me-lightning.php` (~L28-29), same pattern:
```php
$this->method_title = $this->bitcoin_payments_title(__('Lightning Network', 'paycrypto-me-for-woocommerce'));
$this->method_description = $this->bitcoin_payments_description(
    __('self-hosted', 'paycrypto-me-for-woocommerce'),
    __('Lightning Network', 'paycrypto-me-for-woocommerce')
);
```
`__('On-Chain', ...)` and `__('Lightning Network', ...)` remain their own shared msgids (already used standalone at `class-wc-gateway-paycrypto-me.php` ~L359 and `class-wc-gateway-paycrypto-me-lightning.php` ~L364 — do not touch those two sites). `"Bitcoin Payments"` and `"Provided by PayCrypto.Me"` are retired as standalone msgids (folded into the new templates).

**6. Cluster B — pure copy-edit, no templating.** `class-wc-gateway-paycrypto-me.php` (~L39), replace:
```php
__('Use directly your Bitcoin wallet to pay. Place the order to view the QR code and payment instructions.', 'paycrypto-me-for-woocommerce')
```
with the exact string already used at `abstract-class-wc-gateway-paycrypto-me.php` (~L263) and `class-wc-gateway-paycrypto-me-lightning.php` (~L35):
```php
__('Pay directly from your Bitcoin wallet. Place your order to view the QR code and payment instructions.', 'paycrypto-me-for-woocommerce')
```
Removes one full msgid from the catalog.

**7. Rule (c) concatenation fixes:**
- `abstract-class-wc-gateway-paycrypto-me.php`, `init_form_fields()`, `enabled` field `label` (~L249): replace `__('Enable', 'paycrypto-me-for-woocommerce') . ' ' . $this->method_title` with `sprintf(__('Enable %s', 'paycrypto-me-for-woocommerce'), $this->method_title)` — with a `/* translators: %s: gateway title, e.g. "Bitcoin Payments (On-Chain)". */` comment immediately above the `sprintf`'s inner `__(` call (i.e. directly above the line reading `__('Enable %s', 'paycrypto-me-for-woocommerce'),` if you format it multi-line, or directly above the whole statement if kept on one line).
- **`class-wc-gateway-paycrypto-me.php`** (~L311 — corrected location; this is *not* in the abstract class), the Danger Area warning: replace `esc_html__('Warning:', 'paycrypto-me-for-woocommerce') . '</strong> ' . __('Resetting the payment derivation index will lead to the reuse of addresses and loss of past data. Proceed with caution and ensure you understand the implications.', 'paycrypto-me-for-woocommerce')` with `sprintf('<strong>%s</strong> %s', esc_html__('Warning:', 'paycrypto-me-for-woocommerce'), __('Resetting the payment derivation index will lead to the reuse of addresses and loss of past data. Proceed with caution and ensure you understand the implications.', 'paycrypto-me-for-woocommerce'))` — two independent strings joined without raw concatenation; no placeholder-inside-msgid, no translators comment needed.
- `abstract-class-wc-gateway-paycrypto-me.php` (~L328), the donate box: replace `__('<strong>Enjoying the plugin?</strong> Send some BTC to support:', 'paycrypto-me-for-woocommerce')` with `sprintf('<strong>%s</strong> %s', esc_html__('Enjoying the plugin?', 'paycrypto-me-for-woocommerce'), __('Send some BTC to support:', 'paycrypto-me-for-woocommerce'))`.

**8. Cluster F — the "ships in the upcoming … Pro add-on" template, 3 sites.** Worked example, `class-wc-gateway-paycrypto-me.php` (~L293), replace:
```php
'description' => $this->pro_soon_badge() . '<br>' . __('Automatic order expiry after the timeout ships in the upcoming PayCrypto.Me Pro add-on. In the free version, on-chain addresses stay valid until paid.', 'paycrypto-me-for-woocommerce'),
```
with:
```php
'description' => $this->pro_soon_badge() . '<br>' . $this->pro_feature_notice(
    __('Automatic order expiry after the timeout', 'paycrypto-me-for-woocommerce'),
    __('on-chain addresses stay valid until paid', 'paycrypto-me-for-woocommerce')
),
```
Apply the identical transformation to:
- `class-wc-gateway-paycrypto-me.php` (~L301): feature clause `__('Automatic on-chain confirmation tracking', 'paycrypto-me-for-woocommerce')`, free clause `__('payments are verified manually', 'paycrypto-me-for-woocommerce')`.
- `class-wc-gateway-paycrypto-me-lightning.php` (~L169): feature clause `__('Automatic payment confirmation via webhooks (BTCPay push / lnd polling)', 'paycrypto-me-for-woocommerce')`, free clause `__('Lightning payments are confirmed manually — the settings below are a preview and are not editable yet', 'paycrypto-me-for-woocommerce')`. (This feature clause still contains "BTCPay"/"lnd"/"Lightning" as Class-B prose — that is correct, no further extraction needed there.)

**9. Cluster G — short "Pro"-gated field copy**, `class-wc-gateway-paycrypto-me-lightning.php` (~L174-175). Replace:
```php
'description' => __('Reserved for the Pro add-on. Not used by the free version.', 'paycrypto-me-for-woocommerce'),
'placeholder' => __('Available in the Pro add-on', 'paycrypto-me-for-woocommerce'),
```
with:
```php
'description' => sprintf(
    /* translators: %s: short add-on name (not translated, product name), e.g. "Pro". */
    __('Reserved for the %s add-on. Not used by the free version.', 'paycrypto-me-for-woocommerce'),
    WC_PayCryptoMe::NAME_PRO_ADDON_SHORT
),
'placeholder' => sprintf(
    /* translators: %s: short add-on name (not translated, product name), e.g. "Pro". */
    __('Available in the %s add-on', 'paycrypto-me-for-woocommerce'),
    WC_PayCryptoMe::NAME_PRO_ADDON_SHORT
),
```
Kept as two short independent strings (rule e) — only the "Pro" token routes through the constant.

**10. `_x()` conversion**, `abstract-class-wc-gateway-paycrypto-me.php` (~L331). Replace:
```php
esc_html__('Copy', 'paycrypto-me-for-woocommerce')
```
with:
```php
esc_html_x('Copy', 'button label: copy the Bitcoin donation address to the clipboard', 'paycrypto-me-for-woocommerce')
```

**11. Technical/example literal extraction — `BTC-LN`, expanded scope.** Grep the whole tree first to confirm current sites before editing: `grep -rn "BTC-LN" src/trunk/`. As of the 2026-08-27 review pass, this literal appears in **4 production sites**, not just the one originally flagged — fix all 4 to reference `WC_PayCryptoMe::BTCPAY_DEFAULT_PAYMENT_METHOD_ID` instead of retyping the literal:
- `class-wc-gateway-paycrypto-me-lightning.php` (~L155), the description sentence. Replace:
  ```php
  __('Only change this if your BTCPay Server version reports a different Lightning payment method identifier than the default. Leave as "BTC-LN" unless instructed otherwise.', 'paycrypto-me-for-woocommerce')
  ```
  with:
  ```php
  sprintf(
      /* translators: %1$s: BTCPay Server brand name (not translated), %2$s: default payment-method identifier, not translated (technical value). */
      __('Only change this if your %1$s version reports a different Lightning payment method identifier than the default. Leave as "%2$s" unless instructed otherwise.', 'paycrypto-me-for-woocommerce'),
      __('BTCPay Server', 'paycrypto-me-for-woocommerce'),
      WC_PayCryptoMe::BTCPAY_DEFAULT_PAYMENT_METHOD_ID
  )
  ```
- `class-wc-gateway-paycrypto-me-lightning.php` (~L156-157), the same field's `'placeholder'` and `'default'` array values (currently the bare literal `'BTC-LN'`): change both to `WC_PayCryptoMe::BTCPAY_DEFAULT_PAYMENT_METHOD_ID`. These are not translatable strings, just replace the literal with the constant for single-source-of-truth.
- `src/trunk/includes/services/class-btcpay-invoice-service.php` (~L111): `get_option('btcpay_payment_method_id', 'BTC-LN')` — change the fallback default to `WC_PayCryptoMe::BTCPAY_DEFAULT_PAYMENT_METHOD_ID`.
- `src/trunk/includes/validators/class-lightning-config-validator.php` (~L83): same fallback pattern — change to the constant.
- **Test impact**: at least 3 test files assert the literal `'BTC-LN'` (find them with `grep -rn "BTC-LN" src/trunk/tests/`). These assertions do not need to change in *substance* (the constant's value is still `'BTC-LN'`), but review each one — if a test asserts against `WC_PayCryptoMe::BTCPAY_DEFAULT_PAYMENT_METHOD_ID` after this change it stays coupled to the real source of truth; if it asserts the bare literal `'BTC-LN'` that still passes today but is slightly less robust to a future value change — either is acceptable, use judgment, do not block on this.

**12. Default-value literal extraction**, `abstract-class-wc-gateway-paycrypto-me.php` (~L279). Replace:
```php
__('Text displayed on the Express Payment button. If empty, the default label will be used \'Buy with\'', 'paycrypto-me-for-woocommerce')
```
with:
```php
sprintf(
    /* translators: %s: the actual default button label text (already translated elsewhere), shown quoted. */
    __('Text displayed on the Express Payment button. If empty, the default label will be used \'%s\'', 'paycrypto-me-for-woocommerce'),
    __('Buy with', 'paycrypto-me-for-woocommerce')
)
```

**13. Cluster I — "Payment via X failed" template**, two files. Both sites share the same `msgid` (`'Payment via %s failed. Please try again.'`) so gettext merges them into one `.pot` entry — put the **same** `translators:` comment above **both** call sites (not just the first), since which occurrence's comment survives extraction should not be left to chance:
```php
// src/trunk/includes/services/class-btcpay-invoice-service.php (~L143)
sprintf(
    /* translators: %s: payment integration name. */
    __('Payment via %s failed. Please try again.', 'paycrypto-me-for-woocommerce'),
    __('BTCPay Server', 'paycrypto-me-for-woocommerce')
)

// src/trunk/includes/services/class-lnd-rest-invoice-service.php (~L126)
sprintf(
    /* translators: %s: payment integration name. */
    __('Payment via %s failed. Please try again.', 'paycrypto-me-for-woocommerce'),
    __('Lightning node', 'paycrypto-me-for-woocommerce')
)
```
Keep the existing generic wording "Lightning node" for the lnd service — do not force it to literal "lnd".

**14. Clusters assessed and deliberately left unchanged** (accounting for every audit cluster letter not already covered by items 5–13 above, so nothing is silently dropped):
- **Cluster C** (`"Pay with Bitcoin"`) and **Cluster D** (`"Buy with"`): already single, correctly-deduped shared msgids with multiple `#:` references. No action.
- **Cluster E** (`"Payment method name/description displayed on Checkout page."` pair): deliberately not templated — see rule (e)'s worked example. No action.
- **Cluster H** (the four "we could not X, please Y" strings): deliberately not templated — see rule (e)'s worked example. Two of the four embed "Lightning" as Class-B prose (no extraction needed per rule b). No action.
- **Cluster J** (`class-payment-processor.php` "Selected payment method …" pair): deliberately not templated — see rule (e). No brand token embedded. No action.
- **Clusters K/L/M** (the `\WC_Admin_Settings::add_error(sprintf(...))` admin-error wrapper pairs in `class-lightning-config-validator.php` and `class-lightning-connection-tester.php`): out of scope per rule (h) — the outer format strings are deliberately untranslated by existing project policy; only the field-label arguments they interpolate are translated, and those are already correct, existing shared msgids. No action.
- **Cluster N** (`"🔌 Test connection"`) and **Cluster O** (the multi-reference BTCPay/lnd field-label clusters — `"BTCPay Server URL"`, `"lnd REST URL"`, `"BTCPay API Key"`, `"lnd Macaroon (hex)"`, `"BTCPay Store ID"`): already single, correctly-deduped shared msgids. No action — items 11/13 above *reference* some of these labels as `sprintf` arguments but do not change the labels themselves.

**15. Mandatory per-site safety step — run before AND after each edit above:**
```bash
grep -rn "<old string fragment>" src/trunk/tests/phpunit/
```
Update any matching test assertion in the same commit as the string change. One concrete pre-existing hit was found during planning (`tests/phpunit/unit/LightningConnectionTesterTest.php` (~L91) asserting `'BTCPay Server URL is required for test.'`) but that specific site is out of scope for this plan (rule h) — treat this as an example of the check's shape, not an exhaustive list; run the grep for every site you actually touch, since new hits may exist that weren't caught by the planning-time spot check.

**16. Add a `### Changed` subsection under the existing `## Unreleased` heading** in `src/trunk/CHANGELOG.md` (the `## Unreleased` heading already exists, with an existing `### Planned` subsection — do not duplicate the `## Unreleased` heading itself, just add a sibling `### Changed` subsection under it) describing the string-authoring cleanup. No version bump — that is `release.sh`'s job, not this plan's.

### Phase 2 — JS extraction pipeline fix

**Corrections verified directly against the code during the review pass — treat these as required, not optional:**
1. **No PHP change needed for `wp_set_script_translations()`** — it is already called, correctly, in `src/trunk/includes/utils/class-asset-manager.php` (`register_block_assets()`, ~L50-52):
   ```php
   if (function_exists('wp_set_script_translations')) {
       wp_set_script_translations($handle, 'paycrypto-me-for-woocommerce', self::get_plugin_abspath() . '/languages');
   }
   ```
   for both block handles (`paycrypto_me-blocks`, `paycrypto_me_lightning-blocks`). Do not add a second call anywhere.
2. **`webpack.config.js`** (`src/trunk/webpack.config.js`) confirms the compiled bundle output path is `assets/blocks/` (`output.path: path.resolve(process.cwd(), 'assets/blocks/')`), a *different* directory than the already-excluded `assets/js` (hand-written admin/order-details JS with no `@wordpress/i18n` usage — specifically `assets/js/paycrypto-me-order-details.js`; note `assets/paycrypto-me-admin.js` lives one level up, outside `assets/js/`, and also has no i18n calls, so it needs no exclusion either).

**The core problem this phase must solve, stated precisely:** `wp_set_script_translations($handle, $domain, $path)` makes WordPress core, at runtime, compute an expected JSON filename from the **script's actually-registered `src` URL** (which resolves to `assets/blocks/{slug}-blocks.js`, per `class-asset-manager.php` ~L44-48's `wp_register_script()` call — this is the *compiled, shipped* path, not the ES6 source). `wp i18n make-json` names its *output* JSON file(s) based on the **source-file path recorded in the `.po`'s `#:` reference comments** for each string — which, if `wp i18n make-pot` scans `includes/blocks/js/*.js` (the human-readable source, which is what we want for translators editing `.po` in an editor), will read `includes/blocks/js/paycrypto_me-blocks.js`, **not** `assets/blocks/paycrypto_me-blocks.js`. These two paths hash differently, so the generated JSON file will not have the filename WordPress actually requests, and the JS strings will silently stay untranslated even though everything else in the pipeline "worked."

**Steps:**
1. In `scripts/build-translations.sh`, `generate_pot_wp_cli()` (~L72-87), the current text is (verified verbatim against the file):
   ```bash
       if docker_exec "wp i18n make-pot . \"$POT_FILE\" \
           --domain=\"$TEXT_DOMAIN\" \
           --package-name=\"PayCrypto.Me for WooCommerce\" \
           --headers='{\"Report-Msgid-Bugs-To\":\"https://github.com/paycrypto-me/paycrypto-me-for-woocommerce/issues\",\"Language-Team\":\"PayCrypto.Me Team <contact@paycrypto.me>\"}' \
           --exclude=\"node_modules,vendor,.git,assets/js,webpack.config.js\" \
           --skip-js" 2>/dev/null; then
   ```
   Change it to (drop `--skip-js` entirely; add `assets/blocks` to `--exclude` so the minified bundle is never scanned):
   ```bash
       if docker_exec "wp i18n make-pot . \"$POT_FILE\" \
           --domain=\"$TEXT_DOMAIN\" \
           --package-name=\"PayCrypto.Me for WooCommerce\" \
           --headers='{\"Report-Msgid-Bugs-To\":\"https://github.com/paycrypto-me/paycrypto-me-for-woocommerce/issues\",\"Language-Team\":\"PayCrypto.Me Team <contact@paycrypto.me>\"}' \
           --exclude=\"node_modules,vendor,.git,assets/js,assets/blocks,webpack.config.js\"" 2>/dev/null; then
   ```
2. Add a `wp i18n make-json` step that runs **after** each locale's `.po` is fully translated (not right after the `.pot` merge — see Phase 3, which sequences this correctly):
   ```bash
   docker_exec "wp i18n make-json \"$LANGUAGES_DIR/$PLUGIN_SLUG-$locale.po\" \"$LANGUAGES_DIR\" --no-purge"
   ```
   `--no-purge` keeps the JS-sourced strings in the shared `.po`/`.mo` too (rule i's "shared domain, two catalog formats" design). Add a `json <locale>` subcommand to `build-translations.sh`'s CLI surface, mirroring the existing `mo <locale>` subcommand, so this can be invoked independently once a locale's translation is done.
3. **Resolve the filename mismatch by measuring, not by assuming a hash formula.** Do not hardcode WordPress core's filename-hashing algorithm from memory or documentation — it is version-dependent and this project explicitly values "measured, not assumed" (see `docs/LEAN-VENDOR-TREE.md`'s own stated philosophy). Instead:
   a. Run step 2 for one locale (recommend `pt_BR`, once its `.po` is translated per Phase 3). This produces a JSON file named `paycrypto-me-for-woocommerce-pt_BR-<hash-of-includes-blocks-js-path>.json`.
   b. Bring up the dev stack (`docker compose up -d wordpress` / `docker-compose up -d wordpress`), set the site language to `pt_BR` (Settings → General → Site Language, or `wp site switch-language pt_BR` in the container), load a page that enqueues one of the two block scripts (the WooCommerce checkout block editor is the most direct path), open the browser DevTools Network tab, and read the **exact** `.json` filename WordPress requests for that script handle — it will show as a 404 if the file doesn't exist yet at that name, which is fine; the 404'd URL still tells you the expected filename.
   c. Copy (not move — the originally-generated file is still a legitimate artifact) the file from step a to the exact filename observed in step b. Repeat for the second block handle if it hashes to a different filename. Since the hash is deterministic per script (same registered `src` every time), record this filename mapping once — e.g. as a small associative array in `build-translations.sh`, applied as a copy step immediately after every `make-json` call — so it does not need to be re-discovered by hand on every future run.
   d. **If step b shows no translation-JSON request being made at all** for either block script, stop before inventing a rename — that would indicate `wp_set_script_translations()` isn't triggering for these handles at runtime for a different reason (e.g. a handle-name mismatch between what's registered and what was passed to `wp_set_script_translations()`), which is a different problem than a filename mismatch and needs its own diagnosis, not a blind file copy.
4. `quick-translate.sh` needs **no separate change** — confirmed during the review pass it is a pure wrapper (`chmod +x` + invoke `build-translations.sh` with no arguments, then print PoEdit/Loco Translate instructions); it inherits this fix automatically since it just calls the full pipeline.

### Phase 3 — Regenerate `.pot`/`.po`/`.mo`/`.json` and re-translate all 7 locales

1. `docker compose up -d wordpress` (or `docker-compose up -d wordpress` — detect which form is available on the host the same way `build-translations.sh`/`release.sh` already do; do not hardcode one form) from the repo root, per `docs/RELEASE.md`'s documented requirement that `build-translations.sh` needs the dev stack up.
2. Run `./scripts/build-translations.sh` from the repo root (not from `src/trunk/` — `npm run translate` is documented-broken in `docs/TRANSLATION.md`, always invoke the script directly). This regenerates `.pot`, merges all 7 `.po`, and compiles all 7 `.mo` — but **not** `.json` yet (that step needs translated content, see step 4 below).
3. **Be explicit about what this diff will look like, and do not treat it like the Premium→Pro rename.** That rename was a pure word-swap inside otherwise-unchanged sentences, so `msgmerge`'s fuzzy matcher carried old translations forward correctly and only needed spot-fixing. Phase 1 of this plan restructures sentences (concatenation → `sprintf`, cluster templating, HTML/literal extraction) — msgid text changes enough that fuzzy-matching will either miss entirely (new strings land as empty `msgstr ""`) or fuzzy-match to a now-semantically-stale old translation flagged `#, fuzzy`. **Treat every touched msgid as needing genuine re-translation, not fuzzy-patch-and-accept.**
4. **Scope the re-translation work**: fully translate and verify **one locale first** (recommend `pt_BR`, since the team can self-verify Portuguese) — check every new/changed/fuzzy string against the current English source and its surrounding UI context, run `msgfmt --check <po-file>` before moving on, then run Phase 2 step 2's `json pt_BR` generation + Phase 2 step 3's filename-mapping discovery using this now-translated `pt_BR.po`. Then translate the remaining 6 locales (`es_ES`, `de_DE`, `fr_FR`, `it_IT`, `ru_RU`, `zh_CN`) independently from the English source (do not mechanically mirror `pt_BR`'s sentence structure into other languages — translate each from source) — either in parallel (one agent per locale) or sequentially if parallel dispatch isn't available. Validate every `.po` with `msgfmt --check` before recompiling.
5. For **every** locale, once its `.po` is fully translated and validated: recompile `.mo` (`./scripts/build-translations.sh mo "$locale"`) **and** regenerate + rename `.json` per Phase 2 steps 2-3 (the filename mapping discovered once in step 4 above applies to all 7 locales, since it depends only on the script's registered path, not the locale).
6. Verify completeness per locale using the *same* compose-form detection as step 1 (do not hardcode `docker-compose` specifically):
   ```bash
   <detected-compose-command> exec -T wordpress msgfmt --statistics -o /dev/null "/var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce/languages/paycrypto-me-for-woocommerce-<locale>.po"
   ```
   for each of the 7 — expect "N translated messages", 0 fuzzy, 0 untranslated.
7. **Only after all 7 locales are fully re-verified**, update every "100%"/count claim that references the catalog:
   - `docs/TRANSLATION.md` §"Status Atual"
   - `CLAUDE.md`'s `docs/TRANSLATION.md` link line ("7 locales, 100%")
   - `CLAUDE.md`'s "Status" paragraph ("371 tests, 7 locales at 100%") — also update the "371 tests" figure if Phase 1 item 15's test-assertion updates changed the total test count.
   Do not update these mid-flight — a doc claiming "100%" while strings are mid-rewrite would itself be a drift `scripts/check-docs-drift.sh` (Phase 4/5) should catch as contradicting the tree.

### Phase 4 — Enforcement script

Add a **new sibling script**, `scripts/check-i18n-conventions.sh`, rather than extending `scripts/check-docs-drift.sh` — that script's own stated purpose is "canonical docs vs. codebase" drift, a different concern from "does new PHP violate the string-authoring convention." Follow its exact structural idiom: `#!/usr/bin/env bash`, `set -euo pipefail`, the same color/log helpers, `REPO_ROOT`/`TRUNK` path resolution, a `finding()` helper incrementing a `$FINDINGS` counter, numbered `# --- N. <title> ---` sections, no Docker/network dependency, `exit 1` if `$FINDINGS > 0`.

Scan scope for every check below: `$TRUNK/includes`, `$TRUNK/templates`, `$TRUNK/exceptions`, and `$TRUNK`'s top-level `*.php` files (the entrypoint and `uninstall.php`) — the earlier draft of this plan only scanned `includes` + the top-level files and missed `templates`/`exceptions`, both of which contain real translation calls (confirmed by the original audit).

Function names to match in every check below: `__`, `_e`, `_x`, `_n`, `esc_html__`, `esc_attr__`, `esc_html_e`, `esc_attr_e`, `esc_html_x` — the earlier draft only matched `__`/`_e`/`esc_html__` and would have missed, among other things, this very plan's own new `esc_html_x()` call from item 10.

**Check 1 — concatenation anti-pattern.** Anchor on the *exact, literal* end-of-call sequence this codebase always uses (`, 'paycrypto-me-for-woocommerce')`, since every one of the 136+ call sites passes the domain as this exact literal, never a variable) immediately followed by a concatenation dot. This avoids the false positive an earlier, looser draft of this check had — a naive `\)\s*\.\s*'` pattern also matches text *inside* a msgid that happens to contain `).`, e.g. `__('Full URL to your BTCPay Server (HTTPS required).', 'paycrypto-me-for-woocommerce')` (no concatenation at all) would wrongly trip a check that doesn't anchor on the real call-closing sequence:
```bash
grep -rnE "'paycrypto-me-for-woocommerce'\)\s*\.\s*" "$TRUNK/includes" "$TRUNK/templates" "$TRUNK/exceptions" "$TRUNK"/*.php
```
Run this against the tree **after Phase 1 completes** and confirm it returns zero hits (Phase 1's own fixes, items 5 and 7, are designed to eliminate every current instance without introducing new ones — the `pro_soon_badge() . '<br>' . ...` shape and the new `sprintf('<strong>%s</strong> %s', ...)` shapes do not match this pattern, since neither ends a translation call directly in `'paycrypto-me-for-woocommerce') .`). If any genuine hit remains, either it's leftover anti-pattern to fix, or a newly-discovered legitimate HTML-joiner exception — if the latter, add it to an inline allowlist array in the script (matching `check-docs-drift.sh`'s own `PLANNED_PATHS`/`EXTERNAL_PATHS` idiom) with a one-line comment explaining why it's exempt. Do not assume zero allowlist entries will be needed without actually running the check against the post-Phase-1 tree.

**Check 2 — raw Class-A brand token inside a msgid.** The volatile add-on name/brand must only ever appear via the constant, never typed literally inside any translation call's argument — anywhere in the call, not just as the entire string content (the earlier draft's regex required the token to be the *whole* quoted string, which meant it could never fire on an embedded occurrence like `"...Provided by PayCrypto.Me..."` — fixed below to match the token anywhere inside the call's parentheses). Includes the retired name permanently, so it can never silently reappear:
```bash
grep -rnE "(__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e|esc_html_x)\([^)]*(Premium|PayCrypto\.Me|\bPro\b)" \
    "$TRUNK/includes" "$TRUNK/templates" "$TRUNK/exceptions" "$TRUNK"/*.php
```
`\bPro\b` requires "Pro" as a whole word (won't false-positive on "Process", "Provided", "Property", etc.) and is case-sensitive (won't flag the `NAME_PRO_ADDON_SHORT` constant name, which is all-caps `PRO`). Verify this against the pre-Phase-1 tree first — it should return multiple hits (the sites items 4/5/8/9 fix); then verify it returns zero hits after Phase 1.

**Check 3 — `%` placeholder without an immediately-preceding `translators:` comment.** For every translation call whose string literal contains a `%` placeholder token, verify the previous non-blank source line contains `translators:` (this is exactly rule (d)'s "comment goes on the line immediately above the call" requirement, made mechanical):
```bash
for file in $(find "$TRUNK/includes" "$TRUNK/templates" "$TRUNK/exceptions" "$TRUNK" -maxdepth 1 -name '*.php' 2>/dev/null) $(find "$TRUNK/includes" "$TRUNK/templates" "$TRUNK/exceptions" -name '*.php'); do
    grep -nE "(__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e|esc_html_x)\([^)]*%[0-9]?\\\$?[sd][^)]*['\"]" "$file" | while IFS=: read -r n rest; do
        prev=$(sed -n "$((n-1))p" "$file")
        [[ "$prev" == *'translators:'* ]] || finding "$file:$n has a % placeholder with no preceding translators: comment"
    done
done
```
(Adjust the `find` invocation to avoid double-scanning if you fold it into a single `find "$TRUNK" \( -path "*/vendor/*" -prune \) -o -name '*.php' -print` style — the exact shell plumbing is less important than the logic: every file under `includes/`, `templates/`, `exceptions/`, plus the top-level `*.php` files, gets checked.) Verified against the current (pre-Phase-1) tree during the review pass: **zero findings** — the two existing placeholder sites (`class-bitcoin-payment-processor.php`, `paycrypto-me-order-details.php`) both already have correctly-placed comments, so this check is a safe, working gate today and should stay clean through Phase 1 as long as every new `sprintf(...)` in Phase 1's items follows the placement rule stated in rule (d).

**Wiring into `scripts/release.sh`:** the existing "Docs drift audit" phase sits inside a larger `if [[ $DO_TESTS -eq 1 ]]; then ... fi` conditional block (confirm the exact boundaries by reading the file — do not assume a specific line number is still accurate by the time you implement this, since Phase 1 doesn't touch `release.sh` but other work might have in the meantime). Add the new i18n-conventions check as a phase **inside that same conditional**, immediately after the docs-drift phase, following the identical `if [[ $DRY_RUN -eq 0 ]] ... else step "[dry-run] ..." fi` structure already used for both the docs-drift and platform-pin phases — this ensures the new check shares the same `--no-tests` skip behavior as its neighbor, which is almost certainly the intended behavior (a docs/convention check, not a functional test, but grouped with the other audit-style phases).

Note, out of scope for this plan: the 7-locale list is hardcoded in two places (`scripts/build-translations.sh`'s `LANGUAGES=(...)` array and `scripts/check-docs-drift.sh`'s locale-count check) — this plan adds/removes no locale, so this pre-existing dual-source-of-truth is not touched, just flagged as a fact for whoever eventually adds an 8th locale.

### Phase 5 — Docs

**Steps 1–4 below were already completed on 2026-08-27, as part of persisting this plan itself** (pulled forward ahead of Phase 1–4's code execution, at the requester's explicit direction — this plan was reviewed for self-sufficiency, fixed, then saved to the repo without executing any of its code changes). They are kept here, marked done, so a future executor knows this ground is already covered and doesn't redo it — and so the *shape* of the work is documented in case any of it ever needs to be redone (e.g. if the doc is regenerated from a future revision of this plan).

1. **Done.** Final pass over the whole document to remove/reformat every tight `file.php:NNN` citation (file name, colon, number, with no space — the exact pattern `scripts/check-docs-drift.sh` greps for via `[A-Za-z0-9_./-]+\.php:[0-9]{1,4}`) into the spaced `file.php` ... `~LNNN` style used throughout, so citations don't get flagged as false drift once Phase 1's edits shift real line numbers. Verified with `grep -noE '[A-Za-z0-9_./-]+\.php:[0-9]{1,4}' docs/I18N-CONVENTIONS.md` returning no matches.
2. **Done.** A new bullet was added to `CLAUDE.md`'s "Context and guides" list, immediately after the existing `docs/TRANSLATION.md` line, in the list's exact established format (a markdown link to this doc's own path, labeled the same, then an em dash and a one-sentence description), tagged `**approved plan, not started.**` — accurate as of persisting, since Phase 1–4's code changes have not run. **Whoever executes Phase 1–6 and reaches this point again: update that tag to `**done.**` once Phase 6 passes — do not leave it saying "not started" after it's finished.**
3. **Done.** `CLAUDE.md`'s "Code style notes" section (the sentence pointing to `docs/TRANSLATION.md` for the catalog-scope rule) gained one added sentence pointing to this document for string-authoring rules.
4. **Done, and re-run after every future edit to this file:** `./scripts/check-docs-drift.sh` — confirmed clean after steps 1–3 above (two rounds of fixes were needed: an earlier draft of step 2's own instructions here used a generic-example doc path as a template placeholder, which the script's path-citation sweep correctly flagged as a nonexistent cited path since it scans raw text, not just markdown link syntax — reworded to describe the format without spelling out a fake path literally. A second finding, `scripts/check-i18n-conventions.sh` not existing yet, was resolved correctly per the script's own design — added to `check-docs-drift.sh`'s `PLANNED_PATHS` allowlist, the exact mechanism that array exists for, rather than worked around any other way).

### Phase 6 — Verification (definition of done)

All of the following must pass before this plan's status flips to "done":
1. `./scripts/check-docs-drift.sh` — clean, no findings.
2. `./scripts/check-i18n-conventions.sh` (new, Phase 4) — clean, no findings.
3. `<detected-compose-command> exec -T wordpress bash -c "cd /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce && ./vendor/bin/phpunit"` — all tests pass (371 baseline + any new/updated assertions from Phase 1 item 15's grep step), 0 failures.
4. `msgfmt --statistics` on all 7 `.po` files — 100% translated, 0 fuzzy, 0 untranslated, matching Phase 3 step 6.
5. Manual/browser check, procedure (not just an assertion): bring up the dev stack, set the site language to `pt_BR` (the locale fully verified first in Phase 3), load the WooCommerce checkout block editor (Gutenberg / Site Editor, wherever the payment block is placed) with no custom gateway title configured (so the JS fallback label is actually exercised), and confirm the "Pay with Bitcoin" fallback label renders as its `pt_BR` translation, not the English default — this validates Phase 2's JS pipeline fix actually works end-to-end, not just that files were generated. No `npm run build` is needed first — this plan does not change any JS *source* file, only the translation-extraction pipeline around the already-built `assets/blocks/*.js`.
6. `<detected-compose-command> exec -T wordpress wp --allow-root plugin check paycrypto-me-for-woocommerce --format=csv` (the exact command documented in `CLAUDE.md`'s Plugin Check workflow — not a bare `wp plugin check`, which assumes a shell already inside the container) — no new `ERROR`/`WARNING` introduced by this plan's changes.
7. Grep the full diff one more time for any leftover literal `'Premium'` or un-constant-ized `'Pro'`/`'PayCrypto.Me'` inside a translation call, using the **same corrected regex as Phase 4 check 2** (not a naive whole-string-match version):
   ```bash
   grep -rnE "(__|_e|_x|_n|esc_html__|esc_attr__|esc_html_e|esc_attr_e|esc_html_x)\([^)]*(Premium|PayCrypto\.Me|\bPro\b)" \
       src/trunk/includes src/trunk/templates src/trunk/exceptions src/trunk/*.php
   ```
   should return zero hits outside the new helper methods themselves (which correctly reference the constants, not literals).
