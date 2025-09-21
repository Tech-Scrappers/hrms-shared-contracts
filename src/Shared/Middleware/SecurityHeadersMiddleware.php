<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeadersMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Add security headers
        $this->addSecurityHeaders($response);

        return $response;
    }

    /**
     * Add comprehensive security headers
     */
    private function addSecurityHeaders(Response $response): void
    {
        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'DENY');

        // XSS Protection (legacy but still useful for older browsers)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Strict Transport Security (HTTPS only)
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

        // Content Security Policy
        $this->addContentSecurityPolicy($response);

        // Referrer Policy
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions Policy
        $this->addPermissionsPolicy($response);

        // Cache Control for sensitive endpoints
        if ($this->isSensitiveEndpoint($response)) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        // Remove server information
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');
    }

    /**
     * Add Content Security Policy header
     */
    private function addContentSecurityPolicy(Response $response): void
    {
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "font-src 'self' https://fonts.gstatic.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com",
            "img-src 'self' data: https: blob:",
            "connect-src 'self' https: wss:",
            "media-src 'self' https: blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            'upgrade-insecure-requests',
            'block-all-mixed-content',
        ];

        $response->headers->set('Content-Security-Policy', implode('; ', $csp));
    }

    /**
     * Add Permissions Policy header
     */
    private function addPermissionsPolicy(Response $response): void
    {
        $permissions = [
            'accelerometer' => '()',
            'ambient-light-sensor' => '()',
            'autoplay' => '()',
            'battery' => '()',
            'camera' => '()',
            'cross-origin-isolated' => '()',
            'display-capture' => '()',
            'document-domain' => '()',
            'encrypted-media' => '()',
            'fullscreen' => '()',
            'geolocation' => '()',
            'gyroscope' => '()',
            'magnetometer' => '()',
            'microphone' => '()',
            'midi' => '()',
            'navigation-override' => '()',
            'payment' => '()',
            'picture-in-picture' => '()',
            'publickey-credentials-get' => '()',
            'screen-wake-lock' => '()',
            'sync-xhr' => '()',
            'usb' => '()',
            'web-share' => '()',
            'xr-spatial-tracking' => '()',
        ];

        $permissionsPolicy = [];
        foreach ($permissions as $feature => $allowlist) {
            $permissionsPolicy[] = $feature.'='.$allowlist;
        }

        $response->headers->set('Permissions-Policy', implode(', ', $permissionsPolicy));
    }

    /**
     * Check if response is for a sensitive endpoint
     */
    private function isSensitiveEndpoint(Response $response): bool
    {
        $sensitivePaths = [
            '/api/v1/auth/',
            '/api/v1/users/',
            '/api/v1/tenants/',
            '/api/v1/api-keys/',
        ];

        $request = request();
        foreach ($sensitivePaths as $path) {
            if (str_starts_with($request->path(), trim($path, '/'))) {
                return true;
            }
        }

        return false;
    }
}
