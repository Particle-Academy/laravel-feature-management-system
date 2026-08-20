# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0:** breaking changes land in MINOR releases. Until 1.0 the minor
> number is not a compatibility promise — read the entry, not the version.

> This file starts at 0.8.0. Earlier releases predate it; `git log` is the
> record for those.

## [Unreleased]

## 0.12.0 - 2026-08-20

### Added

- **`ProvidesFeatureManagerDefaults` — a trait that unbreaks anyone who
  implements or decorates `FeatureManagerInterface`.**

  0.11.0 added `isEntitled()` and `canConsume()` to the interface. That breaks
  every implementer, and it breaks them at AUTOLOAD rather than at a call site:

  ```
  Class App\Services\Fms\MoicFmsManager contains 2 abstract methods and must
  therefore be declared abstract or implement the remaining methods
  Script @php artisan package:discover returned with error code 255
  ```

  The app — and `artisan` itself — is unbootable until someone writes the
  methods, and it happens mid-`composer update`, before any of their code runs.

  **What to do if this hit you:** add one line to your implementation.

  ```php
  use ParticleAcademy\Fms\Concerns\ProvidesFeatureManagerDefaults;

  class MyFeatureManager implements FeatureManagerInterface
  {
      use ProvidesFeatureManagerDefaults;   // <- both methods, for free
  }
  ```

  Both defaults are built only from methods that pre-date 0.11.0, so they hold
  whatever your implementation stores, and they behave the way the concrete
  `Fms` service does: `isEntitled()` is `canAccess()`, and `canConsume()` is
  entitled AND the amount fits what `remaining()` reports — with **null meaning
  unlimited, never zero.**

### Changed

- **Release notes now separate breaking-for-CALLERS from
  breaking-for-IMPLEMENTERS.** 0.11.0's notes were thorough about the first and
  silent about the second, which is why this was found by a fatal rather than
  by reading. They are different audiences with different failure modes: a
  caller chooses when to adopt a change, an implementer does not, and finds out
  when their app stops booting.

  This bites decorators hardest, and that is the uncomfortable part —
  **decorating the manager is the pattern this package recommends** so consumers
  need not fork it. The shape we encourage is the one an interface addition
  breaks first.

- **A method added to `FeatureManagerInterface` now ships with a default in
  `ProvidesFeatureManagerDefaults`, in the same release.** If no sensible
  default can be written from methods already on the interface, that is the
  signal the addition is a MAJOR change — not a signal to skip the default.

  Reported by a consumer whose 0.9.0 → 0.11.0 upgrade fatalled during
  `composer update`.


## 0.11.0 - 2026-08-19

Three rulings from the owner, plus two things that were found dead while
implementing them. **Read the first entry before upgrading** — it is the one
that can change behaviour without anything warning you.

The full argument for each decision, and the operator procedure for the
migrations, is in `.ai/plans/fancy-commerce-gating-rulings.md`.

### Changed

- **BREAKING (silently): `Fms::can()` now answers ENTITLEMENT, not quota.**

  A resource feature whose allowance is exhausted is now `can() === true`. It
  used to be `false`.

  **What to do:** if you used `can()` to guard a consumption, change it:

  | You wrote | Write instead |
  |---|---|
  | `if ($fms->can('ai-tokens', $sub)) { $fms->increment(...); }` | `if ($fms->tryIncrement('ai-tokens', 1, $sub)) { ... }` |
  | `if ($fms->can('ai-tokens', $sub)) { /* show something */ }` | `if ($fms->canConsume('ai-tokens', 1, $sub)) { ... }` |
  | `if ($fms->can('use-mcp', $sub))` (boolean feature) | nothing — unchanged |

  **Why this direction.** `canAccess()` on `FeatureManager` has always meant
  "is this granted", while `Fms::can()` meant "is this granted AND is there
  quota left" — the same question with two answers, decided by which layer the
  plan happened to be modelled in. And a quota check inside an entitlement
  check was never a safe gate anyway: it reads, the consumption writes, and
  another request can spend the last unit in between. That race is what
  `tryIncrement()` exists for. A gate that is *nearly* right is worse than one
  that obviously is not, because it stops people reaching for the one that is.

  Entitlement is also the more useful answer where it is used most: a plan or
  settings page that hides a metered feature at zero remaining hides it at the
  exact moment the customer is spending the most on it.

  **No deprecation shim is possible**, and that is worth stating rather than
  leaving you to wonder. The only signal available would fire on *every*
  `can()` call for a resource feature, including the majority that are correct
  — a log flood, and a log flood is ignored. The named alternative is the tool.

  `isEntitled()` is a new explicit alias, so a call site that means entitlement
  can say so and never has to be re-read.

- **BREAKING: a resource feature now honours the pivot's `enabled` flag.**

  `can()` used to ignore `enabled` entirely for `type = 'resource'` and look
  only at remaining quota, so a `product_feature_configs` row with
  `enabled = false` and an `included_quantity` was treated as ON. The Node and
  Python twins have always required `enabled` on a resource grant, so this is
  PHP joining them rather than a new rule.

  **What to do:** check whether any row relies on the old leniency, before you
  upgrade:

  ```sql
  SELECT * FROM product_feature_configs c
    JOIN product_features f ON f.id = c.product_feature_id
   WHERE f.type = 'resource' AND c.enabled = 0 AND c.included_quantity IS NOT NULL;
  ```

  Any row this returns is one a customer can use today and will not be able to
  after upgrading. Set `enabled = 1` on the ones that should stay on. It is
  deliberately a query and not a migration: which of those rows were meant to
  be live is a decision about your product, not one this package can make.

### Added

- **`overage_limit` does something.** It was a migration column, a `withPivot`
  entry and a contract field in three runtimes, and **no code in any of them
  read it.** From 0.11.0 it is a **ceiling on billable consumption past the
  included quantity**.

  | `overage_limit` | Meaning |
  |---|---|
  | `null` | **No overage.** Consumption stops at `included_quantity` — today's behaviour. |
  | `0` | The same, stated explicitly. |
  | `n > 0` | Up to `n` billable units past `included_quantity`; refused beyond. |

  *Consumer action: none, unless you want it.* Every row written before 0.11.0
  is either null or a number somebody typed hoping it would work, and `null`
  means no overage precisely so that none of them changes behaviour. Reading
  null as "unbounded" would have turned every untouched row into an unlimited
  spending authority on upgrade.

  It is a *ceiling*, not a soft alert: a field named `*_limit` that does not
  limit is the same defect in a new costume, and unbounded overage is unbounded
  liability on both sides — a runaway loop against a token feature bills
  without end. A ceiling can always be raised with an `UPDATE`; a bill that
  already went out cannot be unsent. "Included N then unbounded" is a *pricing*
  shape and belongs on the Stripe Price as a graduated tier, which
  `laravel-catalog` 0.13.0 unblocks by making `unit_amount` nullable.

- **`feature_usages.overage_quantity`** records the billable units, alongside
  `used_quantity` and in the same locked write. Recorded rather than derived:
  `max(0, used - included)` at read time is one column cheaper and quietly
  wrong, because a mid-period plan upgrade raises the included quantity and
  erases overage that was genuinely incurred — possibly after it was reported
  to a billing provider. It resets with the period for free, being a column on
  the per-period row.

  `increment()` records overage too, not only `tryIncrement()`. `increment` is
  documented as not *enforcing* the quota; recording is not enforcing, and an
  invoice built from a column only some code paths maintain is worse than no
  column.

- **`ParticleAcademy\Fms\Events\FeatureOverageRecorded`**, carrying the
  subscription, the feature key, the units from this consumption, the running
  total for the period, and the period bounds.

  **Recording is in scope here; invoicing is not**, and the line is deliberate:
  reporting metered usage to Stripe needs the *subscription item* id, which
  this package does not have, should not look up, and cannot know for an app
  metering something Stripe never bills for. Listen for the event and do the
  last hop.

- **`Fms::canConsume()`, `Fms::isEntitled()`, `Fms::overage()`** and
  **`FeatureManager::canConsume()` / `isEntitled()`**.

  *If you implement `FeatureManagerInterface` yourself*, it gained
  `isEntitled()` and `canConsume()`. Extending `FeatureManager` needs no change.

- **`ParticleAcademy\Fms\Quota`** — the arithmetic as pure static functions
  (`entitled`, `consumptionCeiling`, `allowsConsumption`, `overageDelta`,
  `canConsume`), held to the shared `shared/feature-entitlement` conformance
  table alongside the Node and Python twins. Cross-runtime behaviour belongs in
  a fixture row, not in three sets of prose that agree today.

- **`fms.subscription_key_type` / `fms.subscription_key`.**

### Fixed

- **`feature_usages.subscription_id` assumed your subscriptions are
  auto-incrementing.** It was `foreignId()` — a bigint — while
  `particle-academy/laravel-catalog` is ULIDs throughout, so the two halves of
  one feature disagreed about the type of the key joining them. In a ULID-native
  app the create migration did not degrade, it **failed**: a bigint foreign key
  cannot reference a `char(26)` primary key.

  The type is now resolved: `fms.subscription_key_type` first
  (`bigint` | `ulid` | `uuid` | `string`), then detection from the referenced
  table, then bigint.

  **What to do:**

  1. **Back up `feature_usages` and `prices` before migrating** — this release
     and `laravel-catalog` 0.13.0 both alter billing tables.
  2. Run `php artisan migrate`.
  3. Set `fms.subscription_key_type` explicitly if you can. SQLite reports every
     string column as a bare `varchar` with no length, so detection there can
     only tell an integer from a string.

  **Existing installs are a no-op.** If your table exists with a bigint
  `subscription_id`, your subscriptions table is bigint too — it has to be, or
  the original foreign key would never have been created — so detection agrees
  and nothing is rebuilt.

  **If the reconcile migration throws**, that is the guard, not a bug: your
  table holds metering history keyed by a type that no longer matches. It will
  not convert them, because only your application knows how the old ids map to
  the new ones and guessing would corrupt data that bills people. The exception
  prints the procedure; the full version is in
  `.ai/plans/fancy-commerce-gating-rulings.md` §3.3.

- **`Fms` looked up the pivot through a hard-coded `product_features` table
  name**, in a package whose `fms.tables.product_features` setting exists so you
  can rename or prefix it — and `laravel-catalog` writes to
  `catalog_product_features`. The literal names a table that is not in the
  query, so the lookup failed with *"no such column"* rather than degrading:
  **every `can()`, `remaining()`, `usage()`, `increment()` and `tryIncrement()`
  threw** for anyone using the documented configuration. It is now
  `whereKey($feature->getKey())`.

  This was found by writing the first end-to-end test of `Fms` (below), which is
  the whole argument for having written it.

- **`FeatureUsage::subscription()` still hard-coded
  `App\Models\BillingSubscription`.** 0.10.0 fixed the two references in `Fms`
  and missed this one, so the relationship named a class that does not exist in
  any other application and threw on first access. It reads
  `fms.subscription_model` now, like everything else.

### Removed

- **`default_strategy`, and the "Database lookups" resolution step it belonged
  to.** `config/fms.php` documented a five-step chain ending in a database
  lookup, and `FeatureManager`'s three database methods return `false`, `null`
  and `0`. `default_strategy` was read by no code in the package at all, so
  setting it to `'database'` did nothing — which is worse than not offering it.

  *Consumer action: none.* Removing a key nothing reads changes nothing at
  runtime, and a stale key in a config file you published is equally harmless.

  **The three methods are NOT removed.** They are `protected` subclass
  extension hooks and each is the terminal `return` of its branch, so a subclass
  overriding `checkDatabaseFeature()` gets a working last-resort source. Taking
  the call sites out would break that silently, for the people who read the
  source instead of the docs. What was deleted is the *claim*. Implementing a
  real database step instead would have changed resolution order for every
  existing app to serve nobody who asked, and the extension point that does
  something already exists twice over (`registerPreStrategy()` here,
  `FeatureSource` in the twins).

### Tests

- **`Fms` had no end-to-end test at all**, through a real product →
  subscription → pivot. Every existing test drove `FeatureManager` — a different
  class, with no storage — or a static helper. That is not a coincidental gap:
  the one path with no test through it is the path that shipped hard-coding
  `App\Models\BillingSubscription` and returned `false` for every consumer of the
  package until 0.10.0.

  `tests/Feature/FmsSubscriptionScopeTest.php` builds the fixtures the service
  actually needs — a subscription model with `active()`, `product()` and
  `featureUsages()`, a product with `productFeatures()`, a `ProductFeature`, and
  a pivot row — and drives entitlement, quota, overage, refunds, scope resolution
  from a bare user, and `lastError()`. The subscriptions are ULID-keyed on
  purpose. It found the hard-coded table name above on its first run.

## 0.10.0 - 2026-08-18

### Fixed

- **An unlimited allowance denied everything.** `included_quantity = null` is
  documented as *unlimited*, and `remaining()` correctly answers `null` for it -
  but `can()` treated that null as a refusal. The most generous configuration
  produced the most restrictive outcome, and the Node twin has always read the
  same row as unlimited, so the two runtimes disagreed about identical data.

  The decision is now `Fms::allowsConsumption()`, which names the case. Note that
  `remaining()` overloads `null` to mean both "unlimited" and "no pivot config";
  `can()` rejects a missing config before it asks, so the only null reaching the
  check is the unlimited one.

- **The package hard-coded the consuming application's own model classes.**
  `resolveSubscriptionScope()` referenced the app's `BillingSubscription` and
  `User` classes directly, with comments instructing the reader to replace them -
  an instruction nobody can follow, because by then the file is in `vendor/`. In
  any application without those exact classes neither `instanceof` matched, so no
  subscription ever resolved and **every `can()` returned false**.

  Both are now configuration, beside the `product_feature_model` that already was.

  **What to do:** set `fms.subscription_model` and `fms.user_model` in
  `config/fms.php`. Left unset, scope resolution returns null rather than
  guessing - the same answer you were already getting, now for a stated reason.


## [0.9.0] — 2026-08-07

### Changed

- **BREAKING — PHP 8.2 is no longer supported.** `require.php` moves from `^8.2` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.2, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- **BREAKING — Laravel 11 and 12 are no longer supported.** The framework requirement narrows from `^11.0|^12.0|^13.0` to `^13.0`.

  **What you must do:** on Laravel 13, nothing. On 11 or 12, stay on the previous release until you upgrade the framework.

- CI now tests PHP 8.4 with Laravel 13 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.


### Documentation

- **Documented that a closure in `config/fms.php` breaks `php artisan config:cache`.**
  Laravel serialises cached config with `var_export`, which cannot export a
  `Closure`, so one closure anywhere in the file fails the whole command:

  ```
  LogicException: Your configuration files could not be serialized because the
  value at "fms.features.ai-tokens.usage" is non-serializable.
    Error: Call to undefined method Closure::__set_state()
  ```

  Every documented pattern — README examples and the commented examples in the
  published config stub — used closures, and `config:cache` is part of
  `optimize` and standard in production deploys. So an app following the docs
  worked in development and failed at deploy, with an error naming Laravel's
  config layer rather than anything recognisable as FMS.

  Nothing in the package changes. The new "Callables and `config:cache`" section
  documents the two forms that survive caching: a `[Class::class, 'method']`
  callable (serialisable *and* callable), or runtime registration through
  `FmsFeatureRegistry`, where closures are fine because nothing is serialised.
  The config stub now carries the same warning at the point of copy-paste.

  Also corrected `'usage' => fn($user) => …` in the stub's examples to
  `fn($user, $context)`, matching the signature documented directly above it and
  fixed in 0.8.0.

## [0.8.0] — 2026-07-27

### Fixed

- **`usage` and `remaining` callbacks received the feature key first, which the
  docs never said.** Every other definition callback — `check`, `enabled`,
  `limit` — takes `($user, $context)`, and the README documented that shape for
  all of them. These two were invoked as `($feature, $user, $context)`.

  So anyone following the documentation bound `$user` to the feature-key
  **string** and metered the wrong thing. The reported symptom is the bad one:
  **an allowance that never runs out.** Found by GuardCard.net, whose own
  allowance-exhaustion test caught it after a debugging round.

  Both now receive `($user, $context)`, matching the rest of the class and the
  docs. `$feature` was redundant anyway — the callback is defined inside
  `features.<key>`, so the key is already known where it is written.

  **What you have to do:**

  - **Wrote `fn($user) => …` or `fn() => …` (what the docs show)?** Nothing. It
    was broken and now works.
  - **Wrote `fn($feature, $user, $context) => …`** — i.e. you read the source
    rather than the docs? It keeps working and now raises an `E_USER_DEPRECATED`
    naming the feature. Move it to `($user, $context)`; **the old order is
    removed at 1.0.**

  A three-parameter callback is detected by arity rather than broken, because
  passing it two arguments would not fail — it would shift every argument by one
  and keep returning plausible numbers. A silent wrong answer is the failure
  this fix exists to end, so it is not the way to end it.

### Added

- **Tests that assert what a callback actually receives.** There were none, and
  that is precisely how this survived: every existing test declared its callback
  as `fn() => 30` — zero parameters — and an argument-less closure passes under
  any signature at all.
- **This changelog.** The package had none.
