# Laravel Catalog Integration Guide

This document describes how to integrate the Feature Management System (FMS) package with [Laravel Catalog](https://github.com/particle-academy/laravel-catalog) while maintaining complete independence.

**Note**: Both packages work independently. FMS does not require Catalog, and Catalog does not require FMS. Integration is optional and happens at the application level.

## Design Principles

### Independence
- **FMS has zero dependencies on Laravel Catalog** - verified by codebase analysis
- **Catalog can optionally use FMS** - FMS is not required for Catalog to function
- **Integration happens at the application level** - not at the package level

### Integration Points

#### 1. Service Provider Auto-Discovery
FMS registers itself via Laravel's package discovery system. When both packages are installed:
- FMS service provider loads automatically
- FMS facade (`FMS`) becomes available globally
- Helper functions are loaded automatically

#### 2. Feature Access Control in Catalog Context
Catalog can use FMS to control access to features like:
- Product creation limits
- Price management permissions
- Admin UI access
- Stripe sync capabilities

#### 3. No Direct Package Coupling
- Catalog does not require FMS in its `composer.json`
- FMS does not reference Catalog classes
- Integration is achieved through Laravel's service container and facades

## Integration Examples

### Example 1: Protecting Catalog Routes with FMS

```php
// routes/web.php
use ParticleAcademy\Fms\Http\Middleware\RequireFeature;

Route::prefix('admin')->middleware(['auth', RequireFeature::class . ':manage-products'])->group(function () {
    Route::get('/products', [ProductController::class, 'index']);
    Route::post('/products', [ProductController::class, 'store']);
});
```

### Example 2: Checking Feature Access in Catalog Controllers

```php
// app/Http/Controllers/ProductController.php
use ParticleAcademy\Fms\Facades\FMS;

class ProductController extends Controller
{
    public function store(Request $request)
    {
        if (!FMS::canAccess('create-products')) {
            abort(403, 'You do not have access to create products.');
        }

        // Create product logic...
    }
}
```

### Example 3: Using Helper Functions in Blade Views

```blade
{{-- resources/views/products/index.blade.php --}}
@if(has_feature('advanced-product-editing'))
    <a href="{{ route('products.advanced.edit', $product) }}">Advanced Edit</a>
@endif

@if(feature_enabled('bulk-operations'))
    <button type="button" class="bulk-action-btn">Bulk Actions</button>
@endif
```

### Example 4: Resource-Based Features (Usage Tracking)

```php
// Check remaining quantity
$remaining = FMS::remaining('product-creations', auth()->user());

if ($remaining > 0) {
    // Allow product creation
    Product::create([...]);
    // Track usage (if using database integration)
} else {
    return redirect()->back()->with('error', 'Product creation limit reached.');
}
```

### Example 5: Configuring Features for Catalog Context

```php
// config/fms.php
use LaravelCatalog\Models\Product;

return [
    'features' => [
        'manage-products' => [
            'name' => 'Manage Products',
            'description' => 'Create, edit, and delete products',
            'type' => 'boolean',
            'enabled' => fn($user) => $user->hasRole('admin'),
        ],
        
        'product-creations' => [
            'name' => 'Product Creations',
            'description' => 'Monthly product creation limit',
            'type' => 'resource',
            'limit' => 100,
            'usage' => fn($user) => Product::where('created_by', $user->id)
                ->whereMonth('created_at', now()->month)
                ->count(),
        ],
        
        'sync-to-stripe' => [
            'name' => 'Sync to Stripe',
            'description' => 'Ability to sync products to Stripe',
            'type' => 'boolean',
            'enabled' => fn($user) => $user->hasPermission('stripe.sync'),
        ],
    ],
];
```

### Example 6: Using Product Features with FMS

When Catalog is installed, products can have features attached. Use FMS to check access:

```php
use LaravelCatalog\Models\Product;
use ParticleAcademy\Fms\Facades\FMS;

$product = Product::with('productFeatures')->find($productId);
$user = auth()->user();

// Check if user has access to all product features
foreach ($product->productFeatures as $feature) {
    if (!FMS::canAccess($feature->key, $user)) {
        abort(403, "You don't have access to {$feature->name}");
    }
}

// Get remaining quantity for resource features
$feature = $product->productFeatures()->where('key', 'api-calls')->first();
if ($feature) {
    $remaining = FMS::remaining($feature->key, $user);
    if ($remaining <= 0) {
        return redirect()->back()->with('error', 'API call limit reached.');
    }
}
```

### Example 7: Subscription-Based Feature Access

Check feature access based on user's active subscription:

```php
use LaravelCatalog\Models\Product;
use ParticleAcademy\Fms\Facades\FMS;

$user = auth()->user();
$subscription = $user->subscriptions()->active()->first();

if ($subscription) {
    $product = $subscription->product();
    
    // Check all features attached to the subscription's product
    foreach ($product->productFeatures as $feature) {
        $hasAccess = FMS::canAccess($feature->key, $user);
        $remaining = FMS::remaining($feature->key, $user);
        
        // Use feature access for your application logic
        if ($hasAccess) {
            // Feature is available
        }
    }
}
```

### Example 8: Feature-Gated Catalog Actions

```php
use LaravelCatalog\Facades\Catalog;
use ParticleAcademy\Fms\Facades\FMS;

class ProductController extends Controller
{
    public function sync(Product $product)
    {
        // Check feature access before syncing
        if (!FMS::canAccess('sync-to-stripe', auth()->user())) {
            abort(403, 'You do not have permission to sync products.');
        }
        
        Catalog::syncProductAndPrices($product);
        
        return redirect()->back()->with('success', 'Product synced.');
    }
    
    public function store(Request $request)
    {
        // Check remaining product creations
        $remaining = FMS::remaining('product-creations', auth()->user());
        
        if ($remaining <= 0) {
            return redirect()->back()
                ->with('error', 'Product creation limit reached for this month.');
        }
        
        $product = Product::create($request->validated());
        
        // Create at least one price (required)
        Price::create([
            'product_id' => $product->id,
            'unit_amount' => $request->input('unit_amount'),
            'currency' => 'USD',
            'type' => Price::TYPE_RECURRING,
            'recurring_interval' => 'month',
        ]);
        
        return redirect()->route('products.index')
            ->with('success', 'Product created.');
    }
}
```

## Verification: No Circular Dependencies

### FMS Package Analysis
- ✅ No references to `LaravelCatalog` namespace
- ✅ No references to `laravel-catalog` package
- ✅ No imports from Catalog classes
- ✅ Standalone service provider registration

### Catalog Package Analysis
- ✅ Catalog does not require FMS in `composer.json`
- ✅ Catalog can function without FMS installed
- ✅ FMS integration is optional at application level

## Testing Integration

### Test Scenario 1: Both Packages Installed
```bash
composer require particle-academy/laravel-catalog
composer require particle-academy/laravel-fms
php artisan package:discover
```

Both packages should load independently without conflicts.

### Test Scenario 2: Catalog Without FMS
```bash
composer require particle-academy/laravel-catalog
# FMS not installed
```

Catalog should function normally without FMS.

### Test Scenario 3: FMS Without Catalog
```bash
composer require particle-academy/laravel-fms
# Catalog not installed
```

FMS should function normally without Catalog.

## Best Practices

1. **Feature Configuration**: Define Catalog-related features in the application's `config/fms.php`, not in Catalog package
2. **Middleware Usage**: Use FMS middleware in application routes, not in Catalog package routes
3. **Service Injection**: Inject `FeatureManagerInterface` in application services, not Catalog services
4. **Testing**: Test integration scenarios in the test application, not in package tests

## Catalog-Specific Integration Patterns

### Pattern 1: Feature-Based Product Visibility

Show/hide products based on feature access:

```php
use LaravelCatalog\Models\Product;
use ParticleAcademy\Fms\Facades\FMS;

// Get products user has access to
$allProducts = Product::with('productFeatures')->get();
$accessibleProducts = $allProducts->filter(function ($product) use ($user) {
    foreach ($product->productFeatures as $feature) {
        if (!FMS::canAccess($feature->key, $user)) {
            return false;
        }
    }
    return true;
});
```

### Pattern 2: Resource Feature Usage Tracking

Track usage of resource features tied to products:

```php
use ParticleAcademy\Fms\Facades\FMS;

// Before allowing an action, check remaining quantity
$featureKey = 'api-calls';
$remaining = FMS::remaining($featureKey, $user);

if ($remaining > 0) {
    // Perform action
    // ... make API call ...
    
    // Track usage (if using database integration)
    // This would be handled by your usage tracking system
} else {
    return response()->json([
        'error' => 'API call limit reached. Upgrade your plan for more calls.'
    ], 403);
}
```

### Pattern 3: Plan Feature Comparison

Compare features across different plans:

```php
use LaravelCatalog\Models\Product;
use ParticleAcademy\Fms\Facades\FMS;

$plans = Product::whereHas('prices', function ($q) {
    $q->where('type', Price::TYPE_RECURRING);
})->with('productFeatures')->get();

foreach ($plans as $plan) {
    $features = [];
    foreach ($plan->productFeatures as $feature) {
        $features[] = [
            'key' => $feature->key,
            'name' => $feature->name,
            'type' => $feature->type,
            'included' => $feature->pivot->enabled ?? false,
            'quantity' => $feature->pivot->included_quantity ?? null,
        ];
    }
    // Display plan with features
}
```

## Summary

FMS and Catalog maintain complete independence while allowing seamless integration at the application level. This design ensures:
- Packages can be used independently
- No circular dependencies
- Flexible integration patterns
- Easy testing and maintenance

### Key Integration Points

1. **Product Features**: Catalog's `ProductFeature` model works with FMS for feature-based access control
2. **Subscription Features**: Check feature access based on user subscriptions
3. **Route Protection**: Use FMS middleware to protect catalog admin routes
4. **Resource Tracking**: Track usage of resource features tied to products
5. **Feature Configuration**: Define catalog-related features in `config/fms.php`

For more information about Laravel Catalog, see the [Catalog README](https://github.com/particle-academy/laravel-catalog/blob/main/README.md).

