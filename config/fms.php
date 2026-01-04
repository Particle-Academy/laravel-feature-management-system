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
    |        'usage' => fn($user) => $user->getUsageCount()
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
    | Default Strategy
    |--------------------------------------------------------------------------
    |
    | The order in which feature access checks are performed:
    | 1. Gate/Policy checks (if defined)
    | 2. Registry definitions (from FmsFeatureRegistry)
    | 3. Config file definitions (this file)
    | 4. Database lookups (if FeatureUsage model is available)
    |
    */
    'default_strategy' => 'config', // 'config', 'database', 'gate', 'registry'

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
];

