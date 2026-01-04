# Laravel Feature Management System (FMS)

A standalone Laravel package for flexible feature access control and management. FMS provides simple, intuitive ways to control feature access using multiple strategies: Gates/Policies, config-based, registry-based, and database lookups.

## Features

- **Multiple Access Control Strategies**: Gates/Policies, config files, feature registry, or database
- **Boolean & Resource Features**: Support for simple on/off features and metered resource features
- **Middleware Protection**: Protect routes based on feature access
- **Facade & Helpers**: Clean API via facade and global helper functions
- **Standalone Package**: Zero dependencies on other packages
- **Laravel 12 Compatible**: Built for Laravel 11+ and 12+

## Installation

```bash
composer require particle-academy/laravel-fms
```

The package will auto-discover and register its service provider.

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=fms-config
```

Define your features in `config/fms.php`:

```php
return [
    'features' => [
        // Simple boolean feature
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
```

## Usage

### Using the Facade

```php
use ParticleAcademy\Fms\Facades\FMS;

// Check if feature is accessible
if (FMS::canAccess('use-mcp')) {
    // Feature is enabled
}

// Check if feature is enabled (alias)
if (FMS::isEnabled('use-mcp')) {
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
```

### Using Helper Functions

```php
// Get feature manager or check feature
if (feature('use-mcp')) {
    // Feature is enabled
}

// Check feature access
if (can_access_feature('use-mcp', $user)) {
    // User has access
}

// Check if feature is enabled
if (feature_enabled('use-mcp')) {
    // Feature is enabled
}

// Get remaining quantity
$remaining = feature_remaining('ai-tokens', $user);

// Get all enabled features
$enabled = enabled_features($user);
```

### Using Middleware

Protect routes with feature requirements:

```php
use ParticleAcademy\Fms\Http\Middleware\RequireFeature;

Route::middleware(['auth', RequireFeature::class . ':use-mcp'])->group(function () {
    Route::get('/mcp', [McpController::class, 'index']);
});

// Multiple features (OR logic - user needs at least one)
Route::middleware(['auth', RequireFeature::class . ':feature1,feature2'])->group(function () {
    // Route protected by feature1 OR feature2
});
```

### Using Gates/Policies

FMS automatically checks Laravel Gates if they exist:

```php
// In AuthServiceProvider
Gate::define('use-mcp', function ($user) {
    return $user->subscription->plan === 'pro';
});

// FMS will automatically use this gate
if (FMS::canAccess('use-mcp')) {
    // Gate check passed
}
```

### Feature Registry

Register features programmatically:

```php
use ParticleAcademy\Fms\Services\FmsFeatureRegistry;

app(FmsFeatureRegistry::class)->register('custom-feature', [
    'name' => 'Custom Feature',
    'type' => 'boolean',
    'enabled' => fn($user) => $user->hasPermission('custom'),
]);
```

## Access Control Strategies

FMS checks features in this order:

1. **Gates/Policies** - If a Gate exists with the feature name, it's checked first
2. **Feature Registry** - Checks registered features via `FmsFeatureRegistry`
3. **Config File** - Checks `config/fms.features.{feature}`
4. **Database** - If `FeatureUsage` model exists, checks database (extensible)

## Resource Features

Resource features support metered usage:

```php
'api-calls' => [
    'type' => 'resource',
    'limit' => 1000,
    'usage' => fn($user) => $user->apiCalls()->thisMonth()->count(),
    'remaining' => fn($user) => 1000 - $user->apiCalls()->thisMonth()->count(), // optional
],
```

## Requirements

- PHP 8.2+
- Laravel 11+ or 12+

## Testing

Run tests using Pest:

```bash
pkg laravel-fms php vendor/bin/pest
```

## Integration with Laravel Catalog

FMS integrates seamlessly with [Laravel Catalog](https://github.com/particle-academy/laravel-catalog) for feature-based product management. When both packages are installed, Catalog automatically configures FMS to use Catalog's `ProductFeature` model.

### Quick Integration Setup

1. **Install both packages**:
```bash
composer require particle-academy/laravel-fms
composer require particle-academy/laravel-catalog
```

2. **Configure FMS features** in `config/fms.php`:
```php
return [
    'features' => [
        'manage-products' => [
            'name' => 'Manage Products',
            'type' => 'boolean',
            'enabled' => fn($user) => $user->hasRole('admin'),
        ],
    ],
];
```

3. **Use FMS in your Catalog controllers**:
```php
use ParticleAcademy\Fms\Facades\FMS;
use LaravelCatalog\Models\Product;

class ProductController extends Controller
{
    public function store(Request $request)
    {
        if (!FMS::canAccess('manage-products')) {
            abort(403);
        }
        
        $product = Product::create($request->validated());
        // ...
    }
}
```

### Product Features Integration

Catalog's `ProductFeature` model works with FMS to provide feature-based access control:

```php
use LaravelCatalog\Models\Product;
use LaravelCatalog\Models\ProductFeature;

// Attach features to products
$product = Product::find($productId);
$feature = ProductFeature::where('key', 'advanced-editing')->first();

$product->productFeatures()->attach($feature->id, [
    'enabled' => true,
    'included_quantity' => 100,
]);

// Check feature access for user's subscription
if (FMS::canAccess('advanced-editing', $user)) {
    // User has access via their subscription
}
```

### Subscription-Based Feature Access

When integrated with Catalog, you can check feature access based on user subscriptions:

```php
use ParticleAcademy\Fms\Facades\FMS;

// Check if user's subscription includes a feature
$user = auth()->user();
$subscription = $user->subscriptions()->active()->first();

if ($subscription) {
    $product = $subscription->product();
    
    // Check if product has feature and user has access
    foreach ($product->productFeatures as $feature) {
        if (FMS::canAccess($feature->key, $user)) {
            // Feature is available
        }
    }
}
```

### Example: Feature-Gated Product Actions

```php
use ParticleAcademy\Fms\Facades\FMS;
use LaravelCatalog\Facades\Catalog;

class ProductController extends Controller
{
    public function sync(Product $product)
    {
        // Check if user can sync products
        if (!FMS::canAccess('sync-products', auth()->user())) {
            abort(403, 'You do not have permission to sync products.');
        }
        
        Catalog::syncProductAndPrices($product);
        
        return redirect()->back()->with('success', 'Product synced.');
    }
    
    public function create()
    {
        // Check remaining product creations
        $remaining = FMS::remaining('product-creations', auth()->user());
        
        if ($remaining <= 0) {
            return redirect()->back()
                ->with('error', 'Product creation limit reached.');
        }
        
        return view('admin.products.create');
    }
}
```

### Protecting Catalog Routes

Use FMS middleware to protect catalog admin routes:

```php
use ParticleAcademy\Fms\Http\Middleware\RequireFeature;

Route::prefix('admin')->middleware([
    'auth',
    RequireFeature::class . ':manage-products'
])->group(function () {
    Route::resource('products', ProductController::class);
});
```

For more detailed integration examples and patterns, see [INTEGRATION.md](INTEGRATION.md).

## License

MIT
