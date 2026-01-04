<?php

namespace ParticleAcademy\Fms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use ParticleAcademy\Fms\Contracts\FeatureManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Require Feature Middleware
 * Why: Protects routes based on feature access, allowing easy feature-gating
 * of application functionality.
 */
class RequireFeature
{
    public function __construct(
        protected FeatureManagerInterface $featureManager
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        // If no features specified, allow access
        if (empty($features)) {
            return $next($request);
        }

        // Check if user has access to any of the required features (OR logic)
        // For AND logic, use multiple middleware instances
        $hasAccess = false;
        foreach ($features as $feature) {
            if ($this->featureManager->canAccess($feature)) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            return $this->denyAccess($request, $features);
        }

        return $next($request);
    }

    /**
     * Deny access and return appropriate response.
     */
    protected function denyAccess(Request $request, array $features): Response
    {
        // If expecting JSON, return JSON response
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'message' => 'You do not have access to the required feature(s): ' . implode(', ', $features),
                'features' => $features,
            ], 403);
        }

        // Check if redirect URL is configured
        $redirectUrl = config('fms.redirect_on_denied', null);
        if ($redirectUrl) {
            return redirect($redirectUrl)->with('error', 'You do not have access to this feature.');
        }

        // Default: abort with 403
        abort(403, 'You do not have access to the required feature(s): ' . implode(', ', $features));
    }
}

