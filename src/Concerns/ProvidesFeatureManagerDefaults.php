<?php

namespace ParticleAcademy\Fms\Concerns;

/**
 * Default implementations for the methods `FeatureManagerInterface` gained in
 * 0.11.0, so an existing implementer or decorator keeps compiling.
 *
 * ## Why this exists
 *
 * Adding a method to a published interface breaks every implementer, and it
 * breaks them at AUTOLOAD rather than at a call site — the app and `artisan`
 * are both unbootable until someone writes the method. A consumer hit exactly
 * that upgrading 0.9.0 → 0.11.0: `package:discover` exited 255 mid-`composer
 * update`, before any of their code ran.
 *
 * The 0.11.0 changelog documented both new methods thoroughly — for CALLERS.
 * Implementers are a different audience with a different failure mode: they do
 * not choose whether to adopt the change, and nothing told them it landed on
 * the interface. That distinction is now part of this package's release notes.
 *
 * It bites decorators hardest, which is the uncomfortable part: **decorating
 * the manager is the pattern this package encourages** so that consumers do not
 * fork it. The recommended shape is the one an interface addition breaks first.
 *
 * ## The rule this establishes
 *
 * **A method added to `FeatureManagerInterface` ships with a default here, in
 * the same release.** If a sensible default cannot be written from methods
 * already on the interface, that is the signal the addition is a MAJOR change
 * rather than a minor one — not a signal to skip the default.
 *
 * ## What the defaults do
 *
 * Both are built only from methods that pre-date 0.11.0, so they hold for any
 * implementer regardless of how it stores anything, and they match what the
 * concrete `Fms` service does.
 */
trait ProvidesFeatureManagerDefaults
{
    /**
     * Entitlement, ignoring quota — an explicit alias for `canAccess`.
     *
     * The concrete service defines it exactly this way. `canAccess` answers
     * ENTITLEMENT on every branch; the separate name exists so a call site says
     * which of the two questions it is asking without anyone re-reading an
     * implementation to find out.
     */
    public function isEntitled(string $feature, mixed $subject = null, mixed $context = null): bool
    {
        return $this->canAccess($feature, $subject, $context);
    }

    /**
     * Entitled AND `$amount` fits under what is left.
     *
     * A READ, not a gate: between this answer and the write that follows,
     * another request can spend the last unit. Anything that must not
     * over-spend needs the storage-owning service's `tryIncrement()`.
     *
     * **`remaining()` returning null means UNLIMITED, not zero.** That reading
     * is load-bearing and has been got wrong before, in the direction that turns
     * the most generous configuration into the most restrictive outcome.
     */
    public function canConsume(
        string $feature,
        mixed $subject = null,
        int $amount = 1,
        mixed $context = null,
    ): bool {
        if (! $this->isEntitled($feature, $subject, $context)) {
            return false;
        }

        $remaining = $this->remaining($feature, $subject, $context);

        return $remaining === null || $remaining >= $amount;
    }
}
