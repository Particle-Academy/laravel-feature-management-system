## Laravel Feature Management System (FMS)

This package provides flexible feature access control and management for Laravel applications. FMS supports multiple access control strategies: Gates/Policies, config-based, registry-based, and database lookups. It supports both boolean (on/off) and resource (metered) features.

### Features

- **Multiple Access Control Strategies**: Gates/Policies, config files, feature registry, or database lookups
- **Boolean & Resource Features**: Support for simple on/off features and metered resource features
- **Middleware Protection**: Protect routes based on feature access
- **Facade & Helpers**: Clean API via facade and global helper functions
- **Standalone Package**: Zero dependencies on other packages

### File Structure

- `src/Services/FeatureManager.php` - Core feature access checking logic
- `src/Services/FmsFeatureRegistry.php` - Registry for programmatically registered features
- `src/Facades/FMS.php` - Facade for accessing FMS functionality
- `src/Http/Middleware/RequireFeature.php` - Middleware for route protection
- `src/Models/FeatureUsage.php` - Model for database-based feature tracking
- `src/helpers.php` - Global helper functions
- `config/fms.php` - Configuration file for feature definitions

### Configuration

Features are defined in `config/fms.php`. Publish the config file:

@verbatim
<code-snippet name="Publish FMS config" lang="bash">
php artisan vendor:publish --tag=fms-config
</code-snippet>
@endverbatim

Define features in the config:

@verbatim
<code-snippet name="FMS Feature Configuration" lang="php">
return [
    'features' => [
        // Boolean feature
        'use-mcp' => [
            'name' => 'Use MCP',
            'description' => 'Access to MCP-powered assistants and tools.',
            'type' => 'boolean',
            'enabled' => true, // or callable: fn($user) => $user->isPremium()
        ],

        // Resource feature with limit
        'ai-tokens' => [
            'name' => 'AI Tokens',
            'description' => 'Metered AI token usage per billing period.',
            'type' => 'resource',
            'limit' => 10000, // or callable
            'usage' => fn($user) => $user->getTokenUsage(), // optional
        ],
    ],
];
</code-snippet>
@endverbatim

### Using the Facade

@verbatim
<code-snippet name="FMS Facade Usage" lang="php">
use ParticleAcademy\Fms\Facades\FMS;

// Check if feature is accessible
if (FMS::canAccess('use-mcp')) {
    // Feature is enabled
}

// Check if user has feature
if (FMS::hasFeature('use-mcp', $user)) {
    // User has access
}

// Get remaining quantity for resource features
$remaining = FMS::remaining('ai-tokens', $user);
if ($remaining > 0) {
    // Allow action
}

// Get all enabled features
$enabled = FMS::enabled($user);
</code-snippet>
@endverbatim

### Using Helper Functions

@verbatim
<code-snippet name="FMS Helper Functions" lang="php">
// Get feature manager or check feature
if (feature('use-mcp')) {
    // Feature is enabled
}

// Check feature access
if (can_access_feature('use-mcp', $user)) {
    // User has access
}

// Get remaining quantity
$remaining = feature_remaining('ai-tokens', $user);

// Get all enabled features
$enabled = enabled_features($user);
</code-snippet>
@endverbatim

### Using Middleware

Protect routes with feature requirements:

@verbatim
<code-snippet name="FMS Middleware Protection" lang="php">
use ParticleAcademy\Fms\Http\Middleware\RequireFeature;

Route::middleware(['auth', RequireFeature::class . ':use-mcp'])->group(function () {
    Route::get('/mcp', [McpController::class, 'index']);
});

// Multiple features (OR logic - user needs at least one)
Route::middleware(['auth', RequireFeature::class . ':feature1,feature2'])->group(function () {
    // Route protected by feature1 OR feature2
});
</code-snippet>
@endverbatim

### Using Gates/Policies

FMS automatically checks Laravel Gates if they exist:

@verbatim
<code-snippet name="FMS Gate Integration" lang="php">
// In AuthServiceProvider
Gate::define('use-mcp', function ($user) {
    return $user->subscription->plan === 'pro';
});

// FMS will automatically use this gate
if (FMS::canAccess('use-mcp')) {
    // Gate check passed
}
</code-snippet>
@endverbatim

### Feature Registry

Register features programmatically:

@verbatim
<code-snippet name="FMS Feature Registry" lang="php">
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;

app(FmsFeatureRegistry::class)->register('custom-feature', [
    'name' => 'Custom Feature',
    'type' => 'boolean',
    'enabled' => fn($user) => $user->hasPermission('custom'),
]);
</code-snippet>
@endverbatim

### Access Control Strategy Order

FMS checks features in this order:

1. **Gates/Policies** - If a Gate exists with the feature name, it's checked first
2. **Feature Registry** - Checks registered features via `FmsFeatureRegistry`
3. **Config File** - Checks `config/fms.features.{feature}`
4. **Database** - If `FeatureUsage` model exists, checks database (extensible)

### Resource Features

Resource features support metered usage:

@verbatim
<code-snippet name="FMS Resource Features" lang="php">
'api-calls' => [
    'type' => 'resource',
    'limit' => 1000,
    'usage' => fn($user) => $user->apiCalls()->thisMonth()->count(),
    'remaining' => fn($user) => 1000 - $user->apiCalls()->thisMonth()->count(), // optional
],
</code-snippet>
@endverbatim

### Best Practices

- Always check feature access before allowing actions that require features
- Use middleware for route-level protection
- Use Gates/Policies for complex authorization logic
- Use resource features for metered/usage-based features
- Register features programmatically when they need to be dynamic
- Keep feature definitions in config for static features
