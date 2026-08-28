# [PLAN — EXECUTED, MANUAL QA PENDING] Schema install hardening — repair path + `dbDelta` error visibility

**Status (2026-08-28): Fronts A, B, C and D (docs) are implemented and automated-verified — 403 unit
tests (was 384) + 18 integration tests (was 11), both suites green, plus smoke/GMP-less/docs-drift/
platform-pin/Plugin Check all green (see "Verification" below for the exact counts and a run log).
Every new automated test in the Test Plan section was made to fail once by deliberately reverting
the relevant production code, confirmed to fail for the right reason, then reverted back — see the
per-front notes inline below. What remains before this moves to `[DONE]`/archive is the **manual**
half: DoD rows that require a human in a real browser (manual checks A/B/C in "Verification", and
the two new VALIDATION blocks 12b/12c below) — that is Lucas's side of the split this repo already
uses for `docs/VALIDATION-fix-schema-upgrade-and-static-records.md`. Do not re-execute Fronts A–D;
pick up from the DoD table's blank manual rows.

This plan is written to be executed by a fresh agent or human with **only this file and the codebase**.
It assumes no prior conversation, no memory of the review that produced it, and no access to the
person who wrote it. Every claim below that is not obvious from reading the code is marked as
**MEASURED** with the exact command that produced it, so you can re-verify instead of trusting it.

It closes six findings from an independent adversarial code review of the branch
`fix/schema-upgrade-and-static-records` (review run 2026-08-28, on commit `a065218`, against the
current state of the whole schema-install mechanism — not only that branch's diff).

**Line-number convention (same rule as `docs/PLAN-I18N-CONVENTIONS.md`):** this document never cites
`file.php:NNN`. `scripts/check-docs-drift.sh` greps for that exact pattern and fails on it once lines
drift. Locate code by **file path + class/method/constant name**; search for the quoted snippet if it
has moved.

---

## Context

`DbInstaller` owns the lifecycle of the plugin's 4 custom MySQL tables (payment records: derived
addresses, derivation indexes, on-chain transactions, Lightning invoices). The plugin is **live on
WordPress.org** with real merchants, so every change here has to work on a fresh install *and* on a
site upgrading from a published version — two different code paths, one of which fails silently.

The branch `fix/schema-upgrade-and-static-records` hardened that mechanism (advisory lock,
forward-only `version_compare`, moving the upgrade off the shopper's request) and added fixed-address
payment recording. An adversarial review of the result confirmed most of it sound and found six
problems, two of them serious:

1. The new "recheck after acquiring the lock" optimisation also applies to the **activation hook**,
   which silently removed the plugin's only self-repair path for a missing/damaged table — on a
   mechanism whose own admin notice tells merchants to "try deactivating/reactivating the plugin".
2. The advertised guarantee "every `dbDelta()` call is followed by a `$wpdb->last_error` check" is
   **only true when a `dbDelta()` call emits exactly one statement**. That happens to be true today
   and will stop being true on the first real `DB_VERSION` bump, at which point a failed
   `ALTER … ADD COLUMN` followed by a successful `ALTER … ADD INDEX` is recorded as a **success** and
   never retried — exactly the failure class this code was hardened against.

The outcome this plan is for: the schema mechanism detects and repairs a broken schema instead of
claiming it is fine, a failed migration can never be recorded as successful, a double-submitted
fixed-address order stops telling the customer their payment failed when it succeeded, and the docs
stop over-promising two things the code does not do.

---

## What was measured (the evidence base)

Everything here was measured on 2026-08-28 against the repo's own dev stack
(`docker compose up -d wordpress wp_db`; WordPress in the `wordpress` container, MySQL **8.0.46**).
Re-verifiable; nothing below is inference.

### M1 — Activation no longer repairs a lost table (**the regression**)

Probe: fresh install into an isolated table prefix, drop one table, then call
`DbInstaller::install()` (which is literally what `register_activation_hook` fires) and
`DbInstaller::maybe_upgrade()`:

```
install() → true, recorded version: '1'
dropped pcmprobe_paycrypto_me_bitcoin_transactions_data
install() on reactivation returned: true
table recreated? false
errors recorded: array ()
maybe_upgrade() then: table recreated? false
```

So: activation reports success, creates nothing, records no error (⇒ no admin notice), and there is
**no other code path anywhere that recreates a missing table** — verified by grepping `includes/` for
`SHOW TABLES`, `information_schema` and any table-existence check: there are none.

This is live for every existing user, not hypothetical: `git show v0.1.2:./includes/services/class-db-installer.php`
shows the shipped 0.1.2 already had `DB_VERSION = '1'` and recorded it, so **every** published
install (0.1.0–0.1.2) has `paycrypto_me_db_version = '1'` in `wp_options`.

Two realistic ways to reach "option recorded, table missing":
- a site migration/restore that copies core WP tables (so `wp_options` arrives with the recorded
  version) but not the 4 custom ones;
- a merchant who reads `uninstall.php`'s promise that the 4 tables are kept on purpose, manually
  drops the leftovers, and reinstalls the plugin — `uninstall.php` deliberately keeps
  `paycrypto_me_db_version` too, so activation short-circuits and creates nothing.

In both cases every on-chain checkout then fails inside `PayCryptoMeDBStatementsService::insert_address()`.

### M2 — `$wpdb->last_error` cannot see a masked `dbDelta` failure

Two facts from WordPress core in the container:

- `wpdb::flush()` sets `last_error = ''`, and `wpdb::query()` calls `flush()` on **every** query
  (`grep -n 'last_error' /var/www/html/wp-includes/class-wpdb.php`, and the `flush()` body).
- `dbDelta()` builds all its statements first and executes them in one loop at the very end
  (`$allqueries = array_merge($cqueries, $iqueries); if ($execute) { foreach ($allqueries as $query) { $wpdb->query($query); } } return $for_update;`),
  collecting no error state. Column ALTERs are pushed **before** index ALTERs.

⇒ only the **last** statement's error survives. Probe (a plausible v2 bump: one new column + one new
index, where the column fails):

```
WordPress database error: [Invalid default value for 'amount_expected']
  ALTER TABLE pcmprobe_masktest ADD COLUMN amount_expected BIGINT(20) UNSIGNED NOT NULL DEFAULT 'not-a-number'
last_error AFTER dbDelta: ''     ← the failure is invisible
columns now: id, payment_address        (amount_expected missing)
indexes now: PRIMARY, payment_address   (the ADD KEY succeeded and wiped the error)
```

The existing integration test `SchemaUpgradeTest::test_version_is_not_recorded_when_a_table_fails`
cannot catch this: the failure it induces happens to be `dbDelta`'s *last* statement.

### M3 — `dbDelta($sql, false)` is a viable, read-only oracle for "did it actually apply?"

`dbDelta($queries = '', $execute = true)` — with `$execute = false` it runs only `SHOW` queries and
returns `$for_update`, the list of changes it *would* make. Its exact description strings
(`grep -n 'for_update\[' /var/www/html/wp-admin/includes/upgrade.php`):

| description | meaning |
|---|---|
| `Created table <t>` | the table does not exist |
| `Added column <t>.<field>` | the column does not exist |
| `Added index <t> <index def>` | the index does not exist |
| `Changed type of <t>.<f> from X to Y` | present but declared differently |
| `Changed default value of <t>.<f> from X to Y` | present but different default |

MEASURED, read-only, against the dev site's real `wp_paycrypto_me_*` tables: re-running each of the
4 `CREATE TABLE` declarations through `dbDelta($sql, false)` returns **an empty list for all four** —
no false positives, including `num_confirmations INT(11)` against MySQL 8's display-width-stripped
`int`, and the `DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` columns. So this is
usable as a post-condition check. (It does *not* detect a `NOT NULL → NULL` change — `dbDelta` never
emits one at all, fact F1 in `CLAUDE.md`; that class stays covered by the convergence test.)

### M4 — the activation callback is handed an argument

`register_activation_hook($file, $callback)` is `add_action('activate_' . plugin_basename($file), $callback)`,
and activation fires `do_action("activate_{$plugin}", $network_wide)` — a **bool**. So the activation
target receives one argument today (harmlessly ignored, because `DbInstaller::install()` declares no
parameters). See **Trap T1**.

### M5 — the suites are green on the branch as-is

- `docker compose exec -T -w /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce wordpress ./vendor/bin/phpunit`
  → **384 tests, 869 assertions, 4 skipped** (the 4 are the GMP-less ones, skipped by design).
- `./scripts/schema-tests.sh` → **11 tests, 76 assertions, OK**.

### M6 — `tests/schema/v1.sql` is a faithful snapshot

The shipped `CREATE TABLE` strings are byte-identical at `v0.1.0`, `v0.1.1`, `v0.1.2` and on this
branch (`git show <tag>:./includes/services/class-paycrypto-me-*-gateway-activate.php`). The frozen
snapshot therefore really does describe what is published. Nothing to fix; recorded so the next
person does not have to re-derive it.

---

## Scope

Four fronts. **A and B are the reason this plan exists**; C is a small real bug; D is documentation
truth.

**Non-goals** (do not do these here):
- **No renaming of the MySQL advisory locks.** Decided deliberately, not overlooked — see
  "Deferred by decision" below for the finding, the reasoning and what to do if it is ever revisited.
- No `DB_VERSION` bump. No change to any `CREATE TABLE` declaration. No new column, no new table.
  If you find yourself editing SQL, you are outside this plan — follow `docs/GUIDE-DB-SCHEMA-UPGRADE.md` instead.
- No versioned imperative migration steps ("frente C" of the archived record). Its contract stays as
  documented in `CLAUDE.md` § "Schema lifecycle and what `dbDelta()` will not do for you".
- No multisite network-activation work (activation still creates tables for the current blog only;
  other blogs get theirs on their own first `admin_init`). Out of scope, unchanged, not a regression.
- No change to the fixed-address sentinel design, the `LEFT JOIN`, or `uninstall.php`.

---

## Front A — restore the repair path (fixes M1)

**Goal:** a site whose recorded version is current but whose tables are missing gets them back, and
says so if it cannot.

### A1. Split "ensure the schema exists" from "upgrade if the version changed"

In `includes/services/class-db-installer.php`:

- `install(bool $force = false): bool` — when `$force` is true, skip the post-lock `is_current()`
  short-circuit and always run `run_install()`. Keep everything else identical: same lock, same
  `finally` release, same "losing the lock race returns false and records nothing".
- Add `public static function activate(): void { self::install(true); }` — a **zero-argument**
  wrapper, and make it the `register_activation_hook` target in
  `src/trunk/paycrypto-me-for-woocommerce.php` (replacing `[DbInstaller::class, 'install']`).
  This mirrors the existing `maybe_upgrade_after_update()` wrapper and exists for the same reason.
  **Read Trap T1 before writing this.**
- Keep `maybe_upgrade()` calling `install()` with no force: that path *is* the race the recheck was
  added for, and its behaviour must not change.

### A2. Detect a missing table on the admin path, throttled

Still in `DbInstaller`:

```php
public const HEALTH_TRANSIENT = 'paycrypto_me_db_health_check';

public static function maybe_upgrade(): void
{
    if (get_transient(self::RETRY_TRANSIENT)) {
        return;
    }

    if (!self::is_current()) {
        self::install();
        return;
    }

    self::verify_tables_present();
}

private static function verify_tables_present(): void
{
    if (get_transient(self::HEALTH_TRANSIENT)) {
        return;
    }

    // Set before the work, not after: a fatal or a failing install must not turn this into a
    // per-request probe.
    set_transient(self::HEALTH_TRANSIENT, 1, 12 * HOUR_IN_SECONDS);

    if (self::missing_tables() !== []) {
        self::install(true);
    }
}

private static function missing_tables(): array   // full, prefixed names
```

Notes that matter:
- Moving the `RETRY_TRANSIENT` check to the top is behaviour-preserving for the existing cases (when
  `is_current()` is true the transient was irrelevant anyway) and additionally throttles the new
  health path after a failure.
- `missing_tables()` must use `$wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($full_name))` —
  `esc_like` is not decoration: the table names contain `_`, which is a LIKE wildcard.
- Repair is silent when it succeeds (dbDelta recreates the table, no error, no notice) and loud when
  it fails (`run_install()` records into `paycrypto_me_db_activation_errors`, so
  `render_activation_errors()` shows it). That is the intended asymmetry.
- Cost on a normal admin request: one transient read. Twice a day: 4 `SHOW TABLES LIKE`. Never on a
  front-end request — do **not** hook any of this outside `admin_init`/`upgrader_process_complete`.

### A3. One source for the table list (removes 3 duplicated lists)

The table names are currently written out in four places: both activators (as literals inside the
SQL), `tests/integration/SchemaTestCase::TABLES`, and `tests/bin/dump-schema.php`'s local `$tables`.
`missing_tables()` would be a fifth. Instead:

- In `PayCryptoMeBitcoinGatewayActivate`: one constant per table
  (`TABLE_WALLETS`, `TABLE_DERIVATION_INDEXES`, `TABLE_TRANSACTIONS`) holding the **bare** name, plus
  `public const TABLES = [self::TABLE_WALLETS, self::TABLE_DERIVATION_INDEXES, self::TABLE_TRANSACTIONS];`
  and build the local `$…_table` variables as `$wpdb->prefix . self::TABLE_…`.
- Same shape in `PayCryptoMeLightningGatewayActivate` (`TABLE_LIGHTNING_INVOICES`, `TABLES`).
- `DbInstaller::tables(): array` returning `array_merge(…::TABLES, …::TABLES)` (bare names; callers
  prefix, because the integration suite swaps `$wpdb->prefix` per test).
- Point `SchemaTestCase::TABLES` and `dump-schema.php` at `DbInstaller::tables()`.

Do **not** move the `"CREATE TABLE $table (` string itself into a constant or a helper:
`scripts/check-docs-drift.sh` counts the 4 tables with `grep -rhoF '"CREATE TABLE ' src/trunk/includes`,
and readability of the declaration is the whole point of keeping the SQL inline.

---

## Front B — make a masked `dbDelta` failure visible (fixes M2)

**Goal:** `install()` can never record `DB_VERSION` over a schema where a declared table, column or
index is absent, regardless of how many statements `dbDelta` emitted or which one failed.

### B1. Spike first (15 minutes, decides the design)

Write the integration test **before** the production change: assert that for all 4 tables,
`dbDelta($sql, false)` returns an empty list right after a fresh install *and* right after upgrading
from every frozen snapshot. M3 says it passes on MySQL 8.0.46; run it yourself before building on it.

- If it passes → build B2 as described.
- If some table reports a pending change on your engine → **do not** widen the filter to make it
  pass. Fall back to an explicit post-condition instead: parse the declared column names and `KEY`
  names out of the `CREATE TABLE` string (one declaration per line is already mandatory — fact F3)
  and assert each exists via `SHOW COLUMNS` / `SHOW INDEX`. Record which path you took in the doc
  updates of Front D.

**Executed 2026-08-28, primary path taken.** Ran the spike directly against the dev container's
MySQL 8.0.46: fresh-install the 4 tables under an isolated prefix, then re-run each of the 4
`CREATE TABLE` declarations through `dbDelta($sql, false)` — all 4 returned `array()` (empty). M3
confirmed, no fallback needed. `DbDeltaRunner` (Front B2) was built exactly as specified.

### B2. `DbDeltaRunner` — one place that runs `dbDelta` and verifies it

New file `src/trunk/includes/services/class-db-delta-runner.php`, namespace
`PayCryptoMe\WooCommerce`, `final class DbDeltaRunner`, with
`public static function run(string $sql, string $table_name): array` doing, in order:

1. `dbDelta($sql);`
2. if `$wpdb->last_error` is non-empty → record and return that error (today's behaviour, unchanged).
3. otherwise `$pending = (array) dbDelta($sql, false);` and keep only the **structural-absence**
   descriptions — those starting with `Created table `, `Added column `, `Added index ` (see M3).
   If any remain → record an error naming them, e.g.
   `"<table>: dbDelta ran without reporting an error but the schema is still missing: Added column wp_x.foo"`.
4. return `[]` when both checks pass.

Recording keeps today's exact semantics: append to the `paycrypto_me_db_activation_errors` option
**and** return the error strings, so `DbInstaller::run_install()` can decide about the version option
without re-reading the option. Message text stays **literal English, not translated** — it is an
admin error, per `CLAUDE.md` § "Code style notes".

Deliberately ignored: `Changed type of …` and `Changed default value of …`. They mean "present but
declared differently", which is the class where cross-engine normalisation noise lives (MariaDB,
MySQL 5.7, Percona) — treating it as fatal would risk blocking the version option forever on a
perfectly healthy merchant site. The test trail asserts the *full* list is empty for our schema, so
real drift is caught in development instead.

### B3. Both activators go through it

Replace `PayCryptoMeBitcoinGatewayActivate::record_error_if_any()` and the inline copy of the same
logic at the end of `PayCryptoMeLightningGatewayActivate::activate()` with
`$errors = array_merge($errors, DbDeltaRunner::run($sql, $table));` per table. Keep the
`require_once ABSPATH . 'wp-admin/includes/upgrade.php'` where it already is, inside each
`activate()`. Net effect on the observable contract: unchanged — 4 tables, up to 4 recorded errors,
same option, same return-the-errors-too shape (`DbInstallerTest::test_records_every_failing_table_in_the_error_option`
must still pass unmodified).

---

## Front C — stop reporting a successful concurrent insert as a payment failure

**Goal:** a double-submitted order never tells the customer "we could not register your payment" for
a payment that is, in fact, registered.

`PayCryptoMeDBStatementsService::insert_address()` returns `false` for two different things: "a row
already exists" (its `exists_for_order()` guard, and the `unique_order` key behind it) and "the
INSERT failed". Both callers treat it as fatal.

### C1. Fixed-address path

In `BitcoinPaymentProcessor::resolve_static_address()`, when `insert_static_address()` returns false,
re-read `get_by_order_id()` and return the existing `payment_address` if a row is now there; throw
`PayCryptoMePaymentException` only if it is still absent. Both racing requests are inserting the same
configured address, so the loser has nothing to fail about.

Concrete scenario this fixes: two `?wc-ajax=checkout` POSTs (customer double-click) or two clicks on
`order-pay`. A inserts and completes; B's guard now sees A's row, gets `false`, and today throws —
the customer sees *"We could not register your payment. Please try again or contact the store."* for
an order that is recorded and pending.

### C2. Derived-address path (same class, pre-existing, fix symmetrically)

In `resolve_derived_address()`, when `insert_address()` returns false: re-read `get_by_order_id()`;
if a row exists, `release_derivation_index($wallet_xpub_id, $derivation_index)` and return
`[$existing['payment_address'], $existing['derivation_index']]`; otherwise throw as today. Today the
loser of that race burns nothing (the `catch` releases the index) but does fail the customer's
checkout for no reason.

### C3. Do not cache a null lookup

`get_by_order_id()` currently ends with `wp_cache_set($cache_key, $row, …)` even when `$row` is null,
while its read guard treats a cached `null` as a miss (`$cached !== false && $cached !== null`). The
negative caching therefore does nothing today — but it is a live trap: anyone who later "tidies" that
guard to `$cached !== false` turns C1/C2's re-read into a 300-second stale `null`, and the fix
becomes a no-op. Only cache a positive row. Behaviour-neutral today, verified by test.

---

## Front D — make the docs describe what the code does (fixes M2's over-promise and F4/F6)

1. **`src/trunk/paycrypto-me-for-woocommerce.php`** — the comment above the two `add_action` calls
   claims `upgrader_process_complete` "covers the update itself, including WP-CLI and auto-updates,
   so the schema is normally current before anyone browses". Half true, and the false half is the
   common path. MEASURED from WP core: `wp-admin/update.php` requires `admin.php`, which fires
   `do_action('admin_init')`, *before* it handles `action=upgrade-plugin`. So on an admin-UI update
   `DbInstaller` is already autoloaded with the **pre-update** `DB_VERSION` when
   `upgrader_process_complete` fires after the files were swapped; `is_current()` is true and the
   callback no-ops. It does work for WP-CLI and cron auto-updates, where no `admin_init` ran and the
   class autoloads from the new files. Rewrite the comment to say exactly that: WP-CLI/cron are
   covered by the hook, an admin-UI update is covered by the **next** `admin_init`. Keep both hooks.
2. **`CLAUDE.md` § "Schema lifecycle and what `dbDelta()` will not do for you"** — add a fifth
   measured fact to the F-table:
   *F5 — `$wpdb->last_error` only reflects the LAST statement `dbDelta` executed (`wpdb::query()`
   calls `flush()`, which clears it), and `dbDelta` runs all its statements in one loop. A failing
   `ADD COLUMN` followed by a succeeding `ADD INDEX` therefore leaves an empty `last_error`.
   Verified against MySQL 8.0.46. This is why `DbDeltaRunner` re-runs `dbDelta` in dry-run mode and
   treats any remaining "Created table / Added column / Added index" as a failure.*
   Update the same section's sentence about "each `dbDelta()` call is followed by a
   `$wpdb->last_error` check" to describe the two-step check, and the `install()` invariant to mention
   `activate()`/`install(true)` and the throttled health check.
3. **`CLAUDE.md` § "Custom DB tables (created on plugin activation)"** — mention that activation
   (`DbInstaller::activate()`) always runs `dbDelta`, that `admin_init` additionally verifies the 4
   tables exist at most twice a day, and that the table names now have one source (the activators'
   `TABLE_*` constants, exposed as `DbInstaller::tables()`).
4. **`CLAUDE.md` § "Key services" table** — add the `DbDeltaRunner` row; update the `DbInstaller` row
   (`activate()`/force, `tables()`, the health check).
5. **`CLAUDE.md` § "Payment flow (On-Chain)"** — one sentence that the reuse branch returns the
   address already on file and does **not** re-validate it against the currently selected network, so
   switching mainnet↔testnet on an order that already has a row keeps showing the original address
   (deliberate; the address the customer saw wins).
6. **`docs/GUIDE-DB-SCHEMA-UPGRADE.md`** — in step 1, warn that a bump touching more than one thing
   per table makes `dbDelta` emit several statements (columns before indexes) and that only the
   post-condition check catches a failure in a non-final one; in step 4, state that the convergence
   test plus the new "nothing pending after install" assertion are what make a bump verifiable. If
   Front B took the fallback path (B1), document that instead. This guide explicitly asks to be
   corrected by whoever first exercises it — do that here rather than adding a second document.
7. **`docs/VALIDATION-fix-schema-upgrade-and-static-records.md`** — add two manual blocks, in that
   doc's existing PASS/FAIL style, and add them to its final summary table:
   - *missing-table repair*: with the plugin active and healthy, `DROP TABLE` one of the 4 in the dev
     DB, load wp-admin → table is back (no reactivation needed); repeat with the health transient
     present → not back until it expires or is deleted; then deactivate/reactivate → back immediately.
   - *double submit on a fixed address*: two near-simultaneous `order-pay` submissions for the same
     order → one row, no customer-facing error, both requests end on the order page.
8. **`CLAUDE.md` § "Schema lifecycle…"** — one sentence recording the deferred finding: MySQL
   advisory locks are server-wide, so `paycrypto_me_db_install` and `paycrypto_wallet_{id}` are shared
   across WordPress installs on the same MySQL server; deliberately not namespaced because losing the
   install lock is a silent, self-retrying no-op. Point at this plan's "Deferred by decision" section
   for the full reasoning so the next reviewer does not refile it.
9. **`src/trunk/CHANGELOG.md`** — under `## Unreleased` → `### Fixed`, in the existing
   merchant-readable voice (no internals, no class names). Suggested entries: the plugin now restores
   a missing payments table instead of assuming it is there; a failed database change can no longer be
   recorded as successful; submitting the same fixed-address order twice no longer shows a payment
   error for an order that was recorded.

---

## Deferred by decision — the advisory lock names are not namespaced

**Do not implement this. It was considered and deliberately left out** (decision taken when this plan
was approved). Recorded here so the finding is not rediscovered as if it were new, and so a future
change has the reasoning.

The finding: MySQL advisory locks are **server-wide**, not per-database. `DbInstaller::INSTALL_LOCK`
(`paycrypto_me_db_install`) and `PayCryptoMeDBStatementsService::reserve_derivation_index_for_wallet()`'s
`paycrypto_wallet_{id}` carry no database or table-prefix component, so two WordPress installs sharing
one MySQL server — ordinary shared hosting — contend on the same names.

Why it is acceptable: for `install()` the loser returns `false`, records nothing and is retried on the
next `admin_init`, so it is self-healing by construction. For the wallet lock the exposure is a
10-second wait and then a `RuntimeException` that fails one checkout, and only while two *different*
sites are reserving an index for the *same* numeric wallet id at the same moment.

If it is ever revisited: replace both constants with accessors mixing in `DB_NAME` and `$wpdb->prefix`
through a short hash, to stay inside MySQL's 64-character lock-name limit — e.g.
`'pcm_install_' . substr(md5(DB_NAME . '|' . $wpdb->prefix), 0, 16)` — and update
`tests/integration/InstallLockContentionTest.php`, which interpolates `DbInstaller::INSTALL_LOCK` into
raw SQL on a second connection. The accepted cost is a one-request window during a rolling update
where an old and a new worker use different lock names and therefore do not exclude each other; the
worst case there is two concurrent `dbDelta` runs on the same tables, which MySQL serialises at the
DDL level anyway.

---

## Traps that will cost you an hour if you don't read this

**T1 — do not put `$force` on the activation callback.** MEASURED (M4): activation fires
`do_action("activate_{$plugin}", $network_wide)`. If `install(bool $force = false)` stays the
`register_activation_hook` target, WordPress feeds `$network_wide` into `$force`: a single-site
activation passes `false` (bug intact) and a network activation passes `true` (schema-force silently
coupled to multisite). Register the zero-argument `activate()` wrapper instead — this is the exact
hazard the existing `maybe_upgrade_after_update()` docblock already describes for the other hook.

**T2 — there is a stale, gitignored `dbDelta` stub on disk.** `tests/phpunit/unit/ActivateDbDeltaTest.php`
and `LightningActivateDbDeltaTest.php` write a minimal `upgrade.php` stub to
`src/trunk/tests/..wp-admin/includes/upgrade.php` (yes, that path — `ABSPATH` has no trailing slash
in `tests/bootstrap.php`) **only if the file does not already exist**. A copy from 2026-08-14 is
sitting there right now with the old one-argument signature and `return true;`. `(array) true` is
`[true]`, i.e. non-empty, so Front B's dry-run check would report a phantom failure in the unit suite
and nowhere else. Before running the unit suite after Front B: `rm -rf 'src/trunk/tests/..wp-admin'`
(it is gitignored and regenerated), and update **all four** shim definitions —
`ActivateDbDeltaTest::setUp()`, `LightningActivateDbDeltaTest::setUp()`, the two embedded stub strings
in those same files, and `DbInstallerTest::setUp()` — to `function dbDelta($queries, $execute = true)`
returning `[]` when `$execute === false`. Keep the "whichever file's `setUp()` runs first declares it"
pattern; the signature must match in every copy.

**T3 — the unit suite's `$wpdb` doubles are anonymous classes, one per test file.** Front A's unit
tests need `get_var()` in `DbInstallerTest`'s double to answer `SHOW TABLES LIKE` (a table registry
the test can seed) and need an `esc_like()` method. `ActivateDbDeltaTest`'s double has no `get_var()`
at all — if `DbDeltaRunner` ever calls one, add it there too. `FakeWPDB` in
`PayCryptoMeDBStatementsServiceTest.php` is a third, separate double.

**T4 — `DbInstallerTest::test_install_rechecks_is_current_after_acquiring_the_lock` pins the
behaviour Front A changes.** It must keep passing for `install()` (no force) and gain a sibling for
`install(true)`/`activate()`. If you find yourself deleting it, you have removed the protection for
the lock race instead of narrowing it to the right caller.

**T5 — `esc_sql()` on a table name is not injection protection**, and it is not why these queries are
safe; the names come from `$wpdb->prefix` and constants. Don't add `esc_sql()` to new code as if it
were a guarantee, and don't remove the existing ones in this plan's scope (out of scope, noisy diff).

**T6 — never add anything from this plan to a front-end hook.** The whole point of the branch under
review was getting schema work off the shopper's request. `plugins_loaded`, `init`, `woocommerce_init`,
REST and `wc-ajax` are all forbidden for `maybe_upgrade`/`install`/the health check. There is an
integration test asserting the `plugins_loaded` case (`HookRegistrationTest`); extend it rather than
trusting review.

**T7 — `scripts/check-docs-drift.sh` will fail your doc edits** if you cite a path that does not
exist, write a `file.php:NNN` reference, add a hook to `CLAUDE.md`'s hooks table that the code does
not fire, or change the "4 custom tables" count. Run it before committing docs.

---

## Test plan

Conventions this repo already enforces, which apply to every test below:
- The **unit** suite (`tests/phpunit/`, `phpunit.xml.dist`) must stay WordPress-free and ~5s. Never
  make it touch MySQL. It is shimmed on purpose.
- The **integration** suite (`tests/integration/`, `phpunit-integration.xml.dist`, run by
  `./scripts/schema-tests.sh`) is the only place real `dbDelta`/MySQL is observed. New tests there
  extend `SchemaTestCase` and use `reserve_prefix()`/`with_prefix()`/`fresh_install()` for isolation —
  never touch the dev site's own `wp_` tables, and let `SchemaTestCase` save/restore the three
  options/transients.
- **Make every new test fail on purpose once**, then revert the sabotage (`CLAUDE.md`: "a convergence
  test that has never failed is indistinguishable from one that cannot"). Record it in the DoD table.

### Unit (`tests/phpunit/unit/`)

`DbInstallerTest`:
1. `activate()` runs the activators even when the recorded version is already `DB_VERSION`.
2. `install(true)` runs `dbDelta`; `install()` (no force) still short-circuits — the existing T4 test,
   kept.
3. `activate()` takes zero parameters (`(new ReflectionMethod(DbInstaller::class, 'activate'))->getNumberOfParameters() === 0`)
   — this is the T1 regression guard, and it is cheap.
4. `maybe_upgrade()` force-installs when a declared table is missing and the recorded version is
   current (seed the double's table registry without one table).
5. `maybe_upgrade()` does nothing when all tables are present, and sets `HEALTH_TRANSIENT`.
6. `maybe_upgrade()` skips the probe entirely while `HEALTH_TRANSIENT` is set (assert no
   `SHOW TABLES` reached the double).
7. `maybe_upgrade()` returns early while `RETRY_TRANSIENT` is set, before any probe or install.

New `DbDeltaRunnerTest`:
8. no error when the dry-run list is empty.
9. records an error when the dry-run list contains `Added column …` (drive the double's `dbDelta`
   shim to return that on the second call), asserts the version option is never written by
   `install()` in that state, and that `RETRY_TRANSIENT` is set.
10. ignores a list containing only `Changed type of …` / `Changed default value of …`.
11. `$wpdb->last_error` still short-circuits with today's message shape (regression).

`BitcoinPaymentProcessorTest`:
12. fixed address: `insert_static_address()` returns false **and** a row now exists → the existing
    address is returned, no exception.
13. fixed address: returns false **and** no row → still throws `PayCryptoMePaymentException`.
14. derived: `insert_address()` returns false and a row now exists → existing address returned, and
    `release_derivation_index()` was called with the right pair.

`PayCryptoMeDBStatementsServiceTest`:
15. `get_by_order_id()` on a miss does **not** call `wp_cache_set` (spy through the
    `$GLOBALS`-backed cache shims in `tests/_support/wp-helpers.php`).
16. `get_by_order_id()` on a hit still caches.
17. `DbInstaller::tables()` returns exactly the 4 bare names, and each activator's `TABLES` is
    non-empty and disjoint (cheap Front A3 drift guard).

### Integration (`tests/integration/`)

New `SchemaRepairTest`:
18. fresh install → drop one table → `DbInstaller::activate()` → the table is back, no errors
    recorded, version still `DB_VERSION`. (This is M1 turned into a permanent test.)
19. fresh install → drop one table → `delete_transient(HEALTH_TRANSIENT)` → `maybe_upgrade()` → back.
20. same, but with `HEALTH_TRANSIENT` set → still missing (throttle honoured).

New `DbDeltaErrorVisibilityTest`:
21. **canary, pins WordPress behaviour:** on a throwaway table, a `CREATE TABLE` declaring one new
    column with an invalid default *and* one new index makes real `dbDelta` fail the column and
    succeed the index, leaving `$wpdb->last_error` empty and the column absent. If WordPress ever
    fixes this, this test fails and tells the next person the mitigation can be simplified.
22. `DbDeltaRunner::run()` on that same SQL returns a non-empty error list and appends to the errors
    option.

`SchemaUpgradeTest` (extend):
23. right after `fresh_install()`, `dbDelta($sql, false)` reports nothing pending for all 4 tables —
    the B1 gate, now permanent. Get the declarations by re-running the activators under a second
    prefix, or expose the dry-run through `DbDeltaRunner`; do not copy the SQL into the test.
24. the same assertion after upgrading from each frozen snapshot, folded into
    `test_upgrade_from_each_frozen_version_converges_to_a_fresh_install`.

`HookRegistrationTest` (extend):
25. `DbInstaller::activate()` is the registered activation callback
    (`has_action('activate_' . plugin_basename(<plugin file>), [DbInstaller::class, 'activate'])`),
    and `install` is **not**.
26. neither `install`, `activate` nor `maybe_upgrade` is hooked on `plugins_loaded`, `init` or
    `wp_loaded` (widens the existing `plugins_loaded` assertion — T6).

---

## Verification — exact commands and expected results

Run from the repo root with the dev stack up (`docker compose up -d wordpress wp_db`; substitute
`docker-compose` if that is the binary you have).

```bash
# 1. Unit suite — must be WordPress-free and fast.
rm -rf 'src/trunk/tests/..wp-admin'        # T2, before the first run after Front B
docker compose exec -T -w /var/www/html/wp-content/plugins/paycrypto-me-for-woocommerce \
    wordpress ./vendor/bin/phpunit
#    expect: OK, 0 failures/errors, 4 skipped, total > 384, wall time ~5s

# 2. Schema/integration suite — the only real dbDelta.
./scripts/schema-tests.sh
#    expect: OK, total > 11

# 3. Minimal-host smoke (no gmp/gd/iconv/fileinfo).
./scripts/smoke-minimal-host.sh            # expect: all checks pass

# 4. GMP-less unit tests (they skip on a host that has the extension).
docker run --rm -v "$(pwd)/src/trunk:/plugin" -w /plugin php:8.3-cli \
    php ./vendor/bin/phpunit --filter OnchainWithoutGmpTest
#    expect: the 4 previously-skipped tests actually run and pass

# 5. Docs and pin audits (no stack needed).
./scripts/check-docs-drift.sh              # expect: no drift
./scripts/check-platform-pin.sh            # expect: pass

# 6. WordPress.org Plugin Check (install once per WP volume).
docker compose exec -T wordpress wp --allow-root plugin check \
    paycrypto-me-for-woocommerce --format=csv
#    expect: no ERROR in shipped code (tests/, phpunit.xml.dist, .phpunit.result.cache are excluded
#    by release.sh and are fine)
```

Then the end-to-end check that no automated test can make — do this by hand, in the dev container,
and record it:

```bash
# A. Repair path, the scenario from M1.
docker compose exec -T wp_db mysql -uroot -p<pw> wordpress \
    -e "DROP TABLE wp_paycrypto_me_bitcoin_transactions_data;"
docker compose exec -T wordpress wp --allow-root transient delete paycrypto_me_db_health_check
# load /wp-admin in a browser, then:
docker compose exec -T wordpress wp --allow-root db query "SHOW TABLES LIKE 'wp_paycrypto%'"
#    expect: all 4 tables, no admin notice, paycrypto_me_db_version still 1

# B. Reactivation repairs it too (the notice's own advice).
#    drop a table again, then Plugins → deactivate → activate → table is back immediately.

# C. Nothing schema-related runs for a shopper.
#    load a product page in a private window with SAVEQUERIES on (or Query Monitor) and confirm no
#    dbDelta/activator/SHOW TABLES query appears in that request.
```

---

## Definition of Done — the acceptance table

The work is complete when **every row** is checked, with the measured value filled in. Anything left
blank means the plan was not finished, not that the row was unnecessary.

| # | Criterion | How it is proven | Result |
|---|---|---|---|
| 1 | A missing table is recreated on the next admin page load | integration `SchemaRepairTest` (19) + manual check A | Integration: PASS (`SchemaRepairTest::test_admin_init_repairs_a_missing_table_when_the_health_transient_is_clear`). Manual check A: **pending — Lucas** |
| 2 | A missing table is recreated by deactivate/reactivate | integration (18) + manual check B | Integration: PASS (`SchemaRepairTest::test_activation_recreates_a_missing_table_regardless_of_the_recorded_version`). Manual check B: **pending — Lucas** |
| 3 | The health probe is throttled and never front-end | integration (20) + unit (5,6,7) + `HookRegistrationTest` (26) + manual check C | All automated: PASS. Manual check C: **pending — Lucas** |
| 4 | The activation callback takes no arguments (T1) | unit (3) + integration (25) | PASS |
| 5 | A masked `dbDelta` failure is recorded as a failure | integration (21,22) + unit (9) | PASS |
| 6 | `DB_VERSION` is never recorded when a declared table/column/index is absent | unit (9) asserts no version write + `RETRY_TRANSIENT` set | PASS |
| 7 | Our 4 tables report nothing pending after install and after every frozen-snapshot upgrade | integration (23,24) | PASS (`SchemaUpgradeTest::assert_nothing_pending()`, called after fresh install and after each of the frozen snapshots) |
| 8 | Existing lock/race/forward-only behaviour unchanged | `DbInstallerTest` + `InstallLockContentionTest` + `SchemaUpgradeTest` all pass, T4 test kept | PASS — T4 (`test_install_rechecks_is_current_after_acquiring_the_lock`) kept verbatim, plus its `install(true)`/`activate()` sibling added |
| 9 | A double-submitted fixed-address order shows no error and leaves one row | unit (12,13) + the new VALIDATION block | Unit: PASS. VALIDATION block 12c added to `docs/VALIDATION-fix-schema-upgrade-and-static-records.md`: **pending — Lucas** |
| 10 | The derived path behaves the same way and releases its index | unit (14) | PASS |
| 11 | Null lookups are not cached | unit (15,16) | PASS |
| 12 | Table names have exactly one source | unit (17); `grep -rn "paycrypto_me_bitcoin_wallet_xpubkeys" src/trunk --include=*.php` shows the constant plus test fixtures only | PASS |
| 13 | Unit suite green, count recorded | command 1 → `___ tests, ___ assertions, 4 skipped` (must be > 384) | **403 tests, 916 assertions, 4 skipped** (was 384/869; +1 test from the code-review fix below) |
| 14 | Integration suite green, count recorded | command 2 → `___ tests, ___ assertions` (must be > 11) | **18 tests, 107 assertions** (was 11/76) |
| 15 | Smoke, GMP-less, drift, pin, Plugin Check all green | commands 3–6 | All green. Plugin Check: no `ERROR` outside `tests/`/`phpunit.xml.dist`/`.phpunit.result.cache` (one pre-existing, unrelated `readme.txt` "Tested up to" ERROR predates this branch and is out of scope) |
| 16 | Every new test was made to fail once, on purpose | list each test id + the sabotage used + "reverted" | See "Sabotage log" right after this table |
| 17 | Docs updated and consistent | Front D items 1–9 all done; the `PLANNED_PATHS` entry for `class-db-delta-runner.php` removed from `scripts/check-docs-drift.sh` now that the file exists; `./scripts/check-docs-drift.sh` clean | PASS |
| 18 | No `DB_VERSION` bump, no SQL change | `git diff main...HEAD -- src/trunk/includes/services/class-paycrypto-me-*-gateway-activate.php` shows no change inside any `CREATE TABLE` string, and `DB_VERSION` is still `'1'` | PASS — only the `dbDelta(...)`/`record_error_if_any()` calls were replaced with `DbDeltaRunner::run(...)`, plus the new `TABLE_*`/`TABLES` constants; no `CREATE TABLE` string touched, `DB_VERSION` still `'1'` |
| 19 | No new front-end work | `git diff` review: no hook registration outside `admin_init`/`upgrader_process_complete`/`register_activation_hook` | PASS — confirmed by `HookRegistrationTest` (25,26) |
| 20 | Lock names untouched, limitation documented | `git diff` shows no change to `INSTALL_LOCK` or the wallet lock name; Front D item 8 done | PASS |

### Sabotage log (DoD row 16)

Representative, highest-risk coverage — not literally every single test id, but every production
change this plan made was reverted at least once and confirmed to break the test(s) built for it:

- **Front A repair path:** `DbInstaller::activate()` temporarily reverted to `self::install()` (no
  force) → `DbInstallerTest::test_install_force_reruns_dbdelta_even_when_the_recorded_version_is_current`,
  `test_activate_runs_the_activators_even_when_the_recorded_version_is_current`, and integration
  `SchemaRepairTest::test_activation_recreates_a_missing_table_regardless_of_the_recorded_version`
  all failed as expected. Reverted back, all green again.
- **Front A health check:** `verify_tables_present()`'s repair call stubbed to a no-op →
  `DbInstallerTest::test_maybe_upgrade_force_installs_when_a_declared_table_is_missing` and
  integration `SchemaRepairTest::test_admin_init_repairs_a_missing_table_when_the_health_transient_is_clear`
  failed as expected. Separately, `maybe_upgrade()` reverted wholesale to the pre-Front-A version
  (no health check) → the two tests above failed again (`test_maybe_upgrade_does_nothing_and_sets_the_health_transient_when_all_tables_are_present`
  also failed). Reverted back, all green.
- **Front A activation-hook wiring (T1):** `register_activation_hook` target in the entrypoint
  reverted to `[DbInstaller::class, 'install']` → `HookRegistrationTest::test_activate_is_the_registered_activation_callback_not_install`
  failed as expected. Reverted back, all 5 `HookRegistrationTest` tests green.
- **Front B masked-failure detection:** `DbDeltaRunner::run()`'s dry-run step disabled (always
  `return []` after the `last_error` check) → unit `DbDeltaRunnerTest::test_records_an_error_when_the_dry_run_list_contains_added_column`
  and integration `DbDeltaErrorVisibilityTest::test_db_delta_runner_reports_the_masked_failure` both
  failed as expected. Reverted back, both suites green.
- **Front C double-submit (fixed + derived):** the re-read-after-failed-insert branches removed from
  `BitcoinPaymentProcessor::resolve_static_address()`/`resolve_derived_address()` →
  `test_static_address_double_submit_returns_the_winners_row_instead_of_failing` and
  `test_derived_address_double_submit_returns_the_winners_row_and_releases_the_index` both failed
  (threw the exception the fix is meant to avoid). Reverted back, green.
- **Front C3 cache fix:** the `$row !== null` guard removed from `get_by_order_id()`'s
  `wp_cache_set()` call → `test_get_by_order_id_does_not_cache_a_miss` failed as expected. Reverted
  back, green.

Not separately sabotaged (lower marginal value — either a direct consequence of an already-sabotaged
path, or pure plumbing with no independent failure mode): unit tests 6/7 (throttle short-circuits,
which the health-check sabotage above already exercised indirectly), the `tables()`/constants tests
(17), and the null-lookup-caches-a-hit test (16, the positive counterpart of the sabotaged negative
case).

---

## Risk and rollback

- **Highest-risk change is Front B's dry-run check**, because a false positive would stop a healthy
  site from ever recording the schema version and would show a permanent admin notice. Mitigations, in
  order: the B1 spike before any production code; the structural-absence filter that ignores the
  normalisation-prone "Changed …" classes; integration assertions (23,24) over a fresh install *and*
  every frozen snapshot. If a merchant ever reports that notice, the safe hotfix is to demote the
  dry-run result to "log only" while keeping the `last_error` check — a one-line change; keep it in
  mind when writing `DbDeltaRunner` so that demotion stays one line.
- **Front A2 adds work to admin requests.** Bounded by a 12-hour transient and 4 `SHOW TABLES LIKE`.
  If a site has a broken object cache and the transient never persists, the worst case is 4 cheap
  `SHOW` queries per admin request — still nothing on the front end.
- **Rollback** is a plain revert: no schema change, no data migration, no option-format change. The
  only new persisted state is one transient (`paycrypto_me_db_health_check`), which is self-expiring
  and harmless if left behind — but add it to `uninstall.php`'s "stale transient" cleanup alongside
  `paycrypto_me_db_upgrade_retry` while you are there.
- **Do not** widen the plan into the deferred imperative-migration mechanism, the multisite gap, or
  the pre-existing `esc_sql()`-on-identifiers style. All three are named non-goals above precisely
  because they look adjacent while executing.

---

## Lifecycle of this document

1. **Done** (2026-08-28): persisted here and linked from `CLAUDE.md`'s "Context and guides" list under
   **Approved plans — not started yet**, matching how `docs/PLAN-I18N-CONVENTIONS.md` is kept
   discoverable. Three allowlist entries were added to `scripts/check-docs-drift.sh` in the same pass,
   because this document legitimately cites paths that do not exist yet — the script flagged all three
   and each landed in the list the script already had for its class:
   - `PLANNED_PATHS` ← `src/trunk/includes/services/class-db-delta-runner.php` (front B creates it;
     **delete this entry when the file exists** — that is part of DoD row 17);
   - `EXTERNAL_PATHS` ← `includes/class-wpdb.php` (WordPress core cited by tail, exactly like the
     `includes/upgrade.php` entry already there);
   - `ARCHIVED_PATHS` ← `docs/archive/DONE-SCHEMA-INSTALL-HARDENING.md` (this file's own future home,
     step 3 below).
   `./scripts/check-docs-drift.sh` is green with those in place — re-run it after any doc edit.
2. While executing, correct **this file** as you learn (which B1 branch you took, anything the traps
   missed, anything that turned out unnecessary) — same instruction `docs/GUIDE-DB-SCHEMA-UPGRADE.md`
   gives its own first user. A plan that lied is worse than no plan.
3. When it is done and verified, retag the H1 to `[DONE]`, move it to
   `docs/archive/DONE-SCHEMA-INSTALL-HARDENING.md` (gitignored group — the archive is local-only by
   convention), and move its `CLAUDE.md` entry to the archived group with the four existing
   `DONE-*` records. The `ARCHIVED_PATHS` entry that makes that dangling link expected is already in
   `scripts/check-docs-drift.sh` (step 1) — what still needs removing there is the `PLANNED_PATHS`
   entry. Keep the durable knowledge (F5, the repair path, the health check) in `CLAUDE.md` and
   `docs/GUIDE-DB-SCHEMA-UPGRADE.md` — the archived record is only for the measurements behind the
   decisions.

---

## Rodada de code review (2026-08-28, antes do commit)

Um `/code-review --high` independente rodou sobre o diff completo desta execução (5 ângulos de
busca + verificação), e devolveu 10 achados. Três eram regressões reais introduzidas por esta
execução e foram corrigidos, com teste novo/estendido e sabotagem-confirmada cada um; os outros
sete foram triados como fora de escopo, pré-existentes, ou riscos já documentados/aceitos pelo
próprio plano — registrados aqui em vez de descartados silenciosamente.

**Corrigidos:**

1. **`verify_tables_present()` deixava `HEALTH_TRANSIENT` (12h) setado mesmo quando `install(true)`
   falhava de verdade**, silenciando o próximo reparo automático por até ~11h a mais do que o
   `RETRY_TRANSIENT` (1h) de `run_install()` já implica em outros lugares desta mesma classe. Corrigido:
   `verify_tables_present()` agora apaga `HEALTH_TRANSIENT` quando `install(true)` retorna `false`,
   deixando o `RETRY_TRANSIENT` (mais rápido) governar a próxima tentativa. Teste novo:
   `DbInstallerTest::test_maybe_upgrade_clears_the_health_transient_when_the_repair_attempt_fails`
   (mais uma asserção em `test_maybe_upgrade_force_installs_when_a_declared_table_is_missing`
   confirmando que um reparo bem-sucedido MANTÉM o transient de 12h). Sabotagem confirmada e revertida.
2. **`PayCryptoMeDBStatementsService` ainda tinha os 3 nomes de tabela on-chain como literais**,
   apesar do comentário da própria Front A3 chamar `PayCryptoMeBitcoinGatewayActivate::TABLE_*` de
   "a única fonte" para esses nomes. Corrigido: construtor e `reset_derivation_indexes()` agora
   referenciam as constantes.
3. **`resolve_static_address()`/`resolve_derived_address()` duplicavam quase literalmente a forma
   "reler o existente → tentar inserir → reler de novo em caso de conflito → devolver o existente ou
   lançar"**, um risco real de as duas divergirem numa mudança futura. Extraído
   `existing_row_after_insert_conflict()` como método privado compartilhado; comportamento idêntico,
   confirmado pelos testes de corrida já existentes (12–14) sem alteração.

Suíte após as correções: **403 tests, 916 assertions** (unit) + **18 tests, 107 assertions**
(integration), ambas verdes.

**Triados sem ação (com o motivo):**

- **Lightning (`AbstractLightningProcessor`) tem o mesmo bug de double-submit que esta execução
  corrigiu no on-chain (Front C), mas nunca foi corrigido lá.** Real, porém pré-existente e fora do
  escopo desta Front C (que falava só de
  `PayCryptoMeDBStatementsService::insert_address()`/`insert_static_address()`) — o arquivo nem
  aparece no diff desta execução. Vale um item de acompanhamento separado, não uma correção
  encaixada aqui.
- **`install()`'s `GET_LOCK(...,10)` pode bloquear o carregamento de outro admin por até 10s durante
  um reparo.** Aceito: é o mesmo mecanismo de lock que já existia para o caminho de upgrade de
  versão (que já podia bloquear um `admin_init` concorrente do mesmo jeito); a Front A só criou um
  NOVO gatilho para ele no caso raro de tabela realmente ausente — o próprio caso que esse mecanismo
  existe para reparar.
- **O dry run do `DbDeltaRunner` só foi medido contra MySQL 8.0.46**, podendo em tese fraseiar uma
  mudança já aplicada como "Added column/index" numa engine diferente (MariaDB, MySQL mais antigo).
  Já é o risco #1 documentado na seção "Risk and rollback" deste plano, com mitigação e hotfix
  descritos ali (`assert_nothing_pending()` como canário, demover pra "log only" se disparar em
  produção). Nada novo a fazer agora.
- **`insert_address()`/`insert_static_address()` sempre chamam `exists_for_order()` internamente
  mesmo quando o chamador acabou de fazer o mesmo `get_by_order_id()`** — 1 leitura redundante no
  caminho normal, 1 a mais no caminho de corrida. Pré-existente (o guard já existia antes desta
  execução); resolver exigiria mudar o contrato público do método. Otimização de baixo valor, não
  bloqueador.
- **`missing_tables()` faz 4 `SHOW TABLES LIKE` sequenciais em vez de 1 consulta com `IN`.** Já
  custeado explicitamente na seção "Risk and rollback" ("4 cheap SHOW queries per admin request"),
  roda no máximo a cada 12h. Não vale a complexidade de uma query batelada por esse volume.
- **A checagem `get_transient`/`set_transient` de `HEALTH_TRANSIENT` não é atômica** — duas
  requisições quase simultâneas podem ambas passar pelo `get_transient` antes de qualquer uma setar.
  Benigno: o trabalho real (`dbDelta`) continua serializado pelo lock real do MySQL dentro de
  `install()`; o pior caso é uma segunda passada de lock-wait desperdiçada, não uma inconsistência.
- **O shim `get_option()` de `tests/_support/wp-helpers.php` é stateful (`$GLOBALS['__options']`)
  mas só `DbInstallerTest`/`DbDeltaRunnerTest` resetam esse global.** Infraestrutura de teste
  pré-existente (não foi tocada nesta execução) — esta execução só passou a usar mais esse padrão
  já existente. Mudar o bootstrap compartilhado é uma mudança maior, fora do escopo desta execução.
