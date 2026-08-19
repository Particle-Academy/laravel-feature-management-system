<?php

declare(strict_types=1);

/**
 * A published package cannot reference a consumer's application classes.
 *
 * `resolveSubscriptionScope()` hard-coded `\App\Models\BillingSubscription` and
 * `\App\Models\User`, with comments instructing the reader to "replace with your
 * model class" — an instruction nobody can follow, because by then the file is
 * in `vendor/`. For any app without those exact classes the `instanceof` checks
 * are simply false, so no subscription resolves and `can()` denies everything.
 *
 * The models are configuration, like `product_feature_model` already is.
 */
it('does not reference the consuming application namespace', function () {
    $source = file_get_contents(__DIR__.'/../../src/Services/Fms.php');

    expect(str_contains($source, 'App\Models'))
        ->toBeFalse('src/Services/Fms.php still hard-codes a consumer application class');
});

it('offers configuration for both models it needs', function () {
    $config = require __DIR__.'/../../config/fms.php';

    expect($config)->toHaveKey('subscription_model');
    expect($config)->toHaveKey('user_model');
});
