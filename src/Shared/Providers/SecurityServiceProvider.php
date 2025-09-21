<?php

namespace Shared\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use Shared\Middleware\CsrfProtectionMiddleware;
use Shared\Middleware\EnhancedRateLimitMiddleware;
use Shared\Middleware\SecurityHeadersMiddleware;
use Shared\Middleware\InputValidationMiddleware;
use Shared\Middleware\JsonResponseMiddleware;
use Shared\Middleware\PayloadSizeLimitMiddleware;
use Shared\Services\AuditLogService;

class SecurityServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register security configuration
        $this->app->configure('security');

        // Register audit log service as singleton
        $this->app->singleton(AuditLogService::class, function ($app) {
            return new AuditLogService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register middleware
        $this->registerMiddleware();

        // Register security routes
        $this->registerSecurityRoutes();

        // Register security commands
        $this->registerSecurityCommands();
    }

    /**
     * Register security middleware
     */
    private function registerMiddleware(): void
    {
        // Register global middleware
        $this->app->middleware([
            SecurityHeadersMiddleware::class,
        ]);

        // Register route middleware
        $this->app->alias(CsrfProtectionMiddleware::class, 'csrf.protection');
        $this->app->alias(EnhancedRateLimitMiddleware::class, 'rate.limit');
        $this->app->alias(InputValidationMiddleware::class, 'input.validation');
        $this->app->alias(JsonResponseMiddleware::class, 'json.response');
        $this->app->alias(PayloadSizeLimitMiddleware::class, 'payload.size.limit');

        // Register middleware groups
        $this->app->middlewareGroup('api', [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            JsonResponseMiddleware::class,
            PayloadSizeLimitMiddleware::class,
            EnhancedRateLimitMiddleware::class,
            InputValidationMiddleware::class,
        ]);

        $this->app->middlewareGroup('web', [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            CsrfProtectionMiddleware::class,
            InputValidationMiddleware::class,
        ]);

        $this->app->middlewareGroup('secure', [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            EnhancedRateLimitMiddleware::class,
            CsrfProtectionMiddleware::class,
            InputValidationMiddleware::class,
            SecurityHeadersMiddleware::class,
        ]);
    }

    /**
     * Register security routes
     */
    private function registerSecurityRoutes(): void
    {
        Route::group([
            'prefix' => 'api/v1/security',
            'middleware' => ['api', 'auth:sanctum'],
        ], function () {
            // CSRF token routes
            Route::get('/csrf-token', function () {
                return response()->json(CsrfProtectionMiddleware::getTokenForResponse());
            });

            // Rate limit status
            Route::get('/rate-limit-status', function () {
                $identifier = request()->header('X-API-Key') ?: request()->ip();
                return response()->json(EnhancedRateLimitMiddleware::getRateLimitStatus($identifier));
            });

            // Security health check
            Route::get('/health', function () {
                return response()->json([
                    'status' => 'healthy',
                    'timestamp' => now()->toISOString(),
                    'features' => [
                        'csrf_protection' => config('security.csrf.enabled'),
                        'rate_limiting' => config('security.rate_limiting.enabled'),
                        'input_validation' => config('security.input_validation.enabled'),
                        'security_headers' => config('security.security_headers.enabled'),
                        'audit_logging' => config('security.audit_logging.enabled'),
                    ],
                ]);
            });
        });
    }

    /**
     * Register security commands
     */
    private function registerSecurityCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Shared\Commands\GenerateSecureKeysCommand::class,
                \Shared\Commands\RotateApiKeysCommand::class,
                \Shared\Commands\AuditLogExportCommand::class,
            ]);
        }
    }
}
