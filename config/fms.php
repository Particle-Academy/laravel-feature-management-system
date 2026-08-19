<?php

return [
    /*
    |--------------------------------------------------------------------------
    | FMS Feature Definitions
    |--------------------------------------------------------------------------
    |
    | Code-defined feature metadata for the Feature Management System (FMS).
    | Features can be defined here or registered via the FmsFeatureRegistry.
    |
    | Feature Configuration Options:
    |
    | - 'enabled': boolean|callable - Simple boolean flag or closure that returns bool
    | - 'type': 'boolean'|'resource' - Feature type (boolean = on/off, resource = metered)
    | - 'limit': int|callable - For resource features, the maximum allowed quantity
    | - 'check': callable - Custom access check function(user, context) => bool
    | - 'usage': callable - For resource features, get current usage(user, context) => int
    | - 'remaining': callable - For resource features, get remaining(user, context) => int|null
    |
    | !! A CLOSURE HERE BREAKS `php artisan config:cache` !!
    |
    | Laravel serialises cached config with var_export, which cannot export a
    | Closure -- one closure anywhere in this file fails the whole command, and
    | config:cache is part of `optimize` and standard in production deploys.
    | So it breaks at deploy time, not in development.
    |
    | Use a [Class::class, 'method'] callable instead -- serialisable AND
    | callable:
    |
    |    'usage' => [\App\Features\TokenUsage::class, 'count'],
    |
    | Or register the feature at runtime via FmsFeatureRegistry, where closures
    | are fine because nothing is serialised. See the README.
    |
    | The closures below are illustrative shorthand; they are safe only if you
    | never cache config.
    |
    | Access Control Strategies:
    |
    | 1. Simple Boolean:
    |    'feature-name' => ['enabled' => true]
    |
    | 2. Callable Check:
    |    'feature-name' => ['check' => fn($user, $context) => $user->isPremium()]
    |
    | 3. Resource Feature:
    |    'feature-name' => [
    |        'type' => 'resource',
    |        'limit' => 1000,
    |        'usage' => fn($user, $context) => $user->getUsageCount()
    |    ]
    |
    | 4. Gate/Policy:
    |    Define a Gate or Policy with the feature name as the ability name.
    |    The FeatureManager will automatically check it.
    |
    */

    'features' => [
        // Example: Simple boolean feature
        'use-mcp' => [
            'name' => 'Use MCP',
            'description' => 'Access to MCP-powered assistants and tools.',
            'type' => 'boolean',
            'enabled' => true, // Can be boolean or callable
        ],

        // Example: Resource feature with limit
        'ai-tokens' => [
            'name' => 'AI Tokens',
            'description' => 'Metered AI token usage per billing period.',
            'type' => 'resource',
            'limit' => 10000, // Can be int or callable
            // 'usage' => fn($user) => $user->getTokenUsage(), // Optional: custom usage getter
            // 'remaining' => fn($user) => $user->getRemainingTokens(), // Optional: custom remaining getter
        ],

        // Example: Resource feature
        'seats' => [
            'name' => 'Seats',
            'description' => 'Number of organization members allowed per billing period.',
            'type' => 'resource',
            'limit' => 10,
        ],

        // Example: Resource feature
        'mcp-calls' => [
            'name' => 'MCP Calls',
            'description' => 'Number of MCP API calls allowed per billing period.',
            'type' => 'resource',
            'limit' => 1000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | FMS Feature Groups
    |--------------------------------------------------------------------------
    |
    | A feature group bundles a set of features under a single key. Subjects
    | (User, Team, Org, Product, anything implementing HasFeatureGroups)
    | get assigned to groups via the polymorphic feature_group_assignments
    | pivot. When a feature is checked, every enabled group containing
    | that feature OR's into the result. Resource limits supplied by groups
    | take MAX across all enabled groups.
    |
    | Group Configuration Options:
    |
    | - 'name': string - Human-readable label
    | - 'description': string - Free-form description for admin/devtools
    | - 'features': array<string> - Feature keys this group enables
    | - 'extends': array<string> - Other group keys whose features merge in
    |                              (one level deep — no transitive expansion)
    | - 'overrides': array<string, array> - Per-feature overrides keyed by
    |                                       feature key. Today supports `limit`.
    | - 'enabled': bool|callable - Optional gate. If truthy, the group is
    |                              considered enabled regardless of pivot
    |                              assignment. Use for cohort/plan-callable
    |                              groups; omit for explicit-assignment groups.
    |
    | Examples:
    |
    |   'pro-plan' => [
    |       'name' => 'Pro Plan',
    |       'features' => ['use-mcp', 'ai-tokens'],
    |       'overrides' => ['ai-tokens' => ['limit' => 50000]],
    |   ],
    |
    |   'enterprise' => [
    |       'name' => 'Enterprise',
    |       'extends' => ['pro-plan'],
    |       'features' => ['sso', 'audit-log'],
    |       'overrides' => ['ai-tokens' => ['limit' => 250000]],
    |   ],
    |
    |   'ai-beta' => [
    |       'name' => 'AI Beta cohort',
    |       'features' => ['experimental-llm'],
    |       'enabled' => fn($user) => $user?->is_in_ai_beta ?? false,
    |   ],
    |
    */

    'groups' => [
        // Define your feature groups here. Empty array is a valid default.
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolution Order
    |--------------------------------------------------------------------------
    |
    | The order in which feature access checks are performed:
    | 1. Pre-strategies registered with FeatureManager::registerPreStrategy()
    | 2. Gate/Policy checks (if defined)
    | 3. Registry definitions (from FmsFeatureRegistry)
    | 4. Feature Group resolution (any enabled group containing the feature)
    | 5. Config file definitions (this file)
    |
    | That is the whole chain. Until 0.11.0 a sixth step was advertised here -- a
    | database resolution strategy -- along with a `default_strategy` key that
    | could be set to `'database'`. Neither existed:
    | FeatureManager's three database methods return false, null and 0, and
    | `default_strategy` was read by no code in this package. Setting it did
    | nothing, which is worse than it not being offered.
    |
    | The three methods survive as `protected` SUBCLASS EXTENSION HOOKS -- if you
    | extend FeatureManager and override checkDatabaseFeature(), it is called and
    | its answer is used. What was deleted is the claim that the package resolves
    | from the database by itself.
    |
    | For a real integration, reach for one of the two extension points that do
    | something: registerPreStrategy() (authoritative, runs first), or the
    | catalog bridge in particle-academy/laravel-catalog.
    |
    | Removing `default_strategy` changes nothing at runtime. If you published
    | this config file, the stale key in your copy is equally harmless.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Product Feature Model
    |--------------------------------------------------------------------------
    |
    | The ProductFeature model class to use for syncing feature definitions
    | from the registry to the database. This allows FMS to work with any
    | product/billing system by configuring the appropriate model class.
    |
    | Example: \LaravelCatalog\Models\ProductFeature::class
    |
    */
    'product_feature_model' => null, // Set to your ProductFeature model class

    /*
     * The subscription and user models this package resolves scope against.
     * Both were hard-coded to the consuming application's own classes until
     * 0.9.0, which meant the package only worked in one application.
     */
    'subscription_model' => null, // Set to your billing subscription model class
    'user_model' => null,         // Set to your user model class

    /*
    |--------------------------------------------------------------------------
    | Subscription Key
    |--------------------------------------------------------------------------
    |
    | The type and name of your subscriptions table's primary key, which
    | `feature_usages.subscription_id` has to match.
    |
    | This package declared a bigint until 0.11.0, while the package it is paired
    | with (particle-academy/laravel-catalog) is ULIDs throughout -- so the two
    | halves of one feature disagreed about the type of the key that joins them.
    | In a ULID-native app the create migration did not degrade, it FAILED: a
    | bigint foreign key cannot reference a char(26) primary key. Subscriptions
    | belong to your application, so the package stops assuming.
    |
    | `subscription_key_type`: 'bigint', 'ulid', 'uuid' or 'string'. Leave null
    | to detect it from the referenced table -- but SET IT EXPLICITLY if you can.
    | SQLite reports every string column as a bare `varchar` with no length, so
    | detection there can only tell an integer from a string; MySQL, which is the
    | driver that rejects a mismatched foreign key, reports enough.
    |
    | Existing installs need to change nothing: if your feature_usages table
    | exists with a bigint subscription_id, your subscriptions table is bigint
    | too -- it has to be, or the original foreign key would never have been
    | created -- so detection agrees and the reconcile migration is a no-op.
    |
    */
    'subscription_key_type' => null, // null = detect; 'bigint' | 'ulid' | 'uuid' | 'string'
    'subscription_key' => 'id',      // the referenced column on the subscriptions table

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | FMS's own `feature_usages` table plus the two tables its foreign keys
    | point at. Override these when your schema differs — e.g. you prefix
    | tables (`fms_feature_usages`), use a non-standard subscription table,
    | or run particle-academy/laravel-catalog (which writes to
    | `catalog_product_features`, not `product_features`).
    |
    | Both the FeatureUsage model and the create-table migration read these
    | values, so a single config change keeps the model and schema in sync
    | without forking the package or hand-editing a published migration.
    |
    | The create migration also self-skips (no error) when either FK target
    | table is absent at apply time, so it can sit harmlessly in early
    | migration order while you build the real table later in your own
    | migration if you prefer.
    |
    */
    'tables' => [
        'feature_usages' => 'feature_usages',
        'subscriptions' => 'subscriptions',
        'product_features' => 'product_features',
    ],
];

