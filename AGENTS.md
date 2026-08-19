# AGENTS.md — laravel-fms

Feature flags and metered-resource gating for Laravel. The PHP twin of
`@particle-academy/fancy-features` and `fancy-features` (Python).

This file describes **this repository's code**. Process rules — publishing,
versioning, backports, the support lifecycle — live in the envelope's
`AGENTS.md` and are deliberately not repeated here, because a copy in a repo
freezes at whatever the rule was when the branch was cut.

## Two services, and they are not interchangeable

- **`FeatureManager`** — registry, groups, config and Gate. Owns no storage.
  Resolves for any subject.
- **`Fms`** — the subscription-scoped half. Walks subscription → product →
  `product_feature_configs` pivot and meters against `feature_usages`.

Everything the two have in common goes through **`Quota`**, a static class of
pure functions held to the shared `shared/feature-entitlement` conformance
table. Put cross-runtime behaviour in a fixture row there, never in prose here.

## Entitlement is not quota

`canAccess()` / `can()` answer **entitlement**. `canConsume()` is the
quota-aware read. `Fms::tryIncrement()` is the only one safe to gate a write
with, because it takes the row lock.

They used to disagree: `FeatureManager::canAccess()` was quota-blind while
`Fms::can()` was not, so the same question got two answers depending on which
layer the plan was modelled in. Do not re-merge them — `Quota::entitled()` takes
`includedQuantity` and `used` and is required to ignore them, and conformance
rows `0002` and `0004` fail if it stops.

## Billable overage

`overage_limit` is a **ceiling** on consumption past `included_quantity`, and
**`null` means no overage**. That reading is load-bearing: the column was stored
and read by nothing until 0.11.0, so every pre-existing row is null, and
"unbounded" would have made each one an unlimited spending authority.

Overage is **recorded** in `feature_usages.overage_quantity`, never derived. A
mid-period plan upgrade raises the included quantity, and a derived figure would
erase overage that was genuinely incurred.

`Quota::overageDelta()` is **signed**, so `increment()` and `decrement()` share
it. Do not split it into two functions — that is how the two directions drift.

Recording stops at `FeatureOverageRecorded`. Pushing to Stripe needs a
subscription *item* id this package does not have and must not guess.

## Traps that have already cost something

- **Never write a table name as a literal.** `Fms` looked the pivot up through
  `where('product_features.id', …)` while `fms.tables.product_features` exists
  precisely so it can be renamed — and `laravel-catalog` writes to
  `catalog_product_features`. Every metering method threw for anyone using the
  documented configuration. Use `whereKey()` / `getQualifiedKeyName()`.

- **`subscription_id` is not necessarily a bigint.** Subscriptions belong to the
  host. `FeatureUsagesSchema` resolves the type; on SQLite detection can only
  tell an integer from a string, which is why `fms.subscription_key_type` is the
  documented route.

- **`usage` / `remaining` callbacks take `($user, $context)`.** They used to
  take the feature key first, so anyone following the docs bound `$user` to a
  string and metered nothing — the allowance never ran out. The arity dispatch
  in `callDefinitionCallback` supports both until 1.0. Do not "simplify" it.

- **`remaining()` returning `null` is UNLIMITED, not zero.** Denying on null
  turned the most generous configuration into the most restrictive outcome.

- **A closure in `config/fms.php` breaks `php artisan config:cache`**, at deploy
  time rather than in development. Use `[Class::class, 'method']`.

- **The "database" resolution step does not exist.** `checkDatabaseFeature()`
  and its two siblings return `false`, `null` and `0`. They are real *subclass*
  extension hooks and the call sites stay; what was removed in 0.11.0 is the
  config file's claim that the package resolves from the database itself. A test
  fails if the claim comes back.

## Parity

The Node and Python twins are peers, not ports — where they disagree, that is a
finding rather than a choice, and it goes in `fancy-conformance` as a row.

## Testing

`vendor/bin/pest`. No network. **A metered feature gets a test that would fail
if the allowance stopped running out**, and anything touching `Fms` goes through
`CommerceTestCase`, which builds the real product/subscription/pivot chain —
`Fms` had no end-to-end test at all until 0.11.0, and that is exactly why the two
worst bugs in its history lived there.

The conformance rows come from a checkout until `fancy-conformance` releases the
suite: `tests/Support/SharedSuites.php` resolves the root and **fails rather than
skips** when it cannot. Delete it and call `Conformance::runTable()` directly
once the installed package carries the suite.
