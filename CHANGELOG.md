# Changelog

All notable changes to this project are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0:** breaking changes land in MINOR releases. Until 1.0 the minor
> number is not a compatibility promise — read the entry, not the version.

> This file starts at 0.8.0. Earlier releases predate it; `git log` is the
> record for those.

## [Unreleased]

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
