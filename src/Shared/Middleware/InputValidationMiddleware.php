<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class InputValidationMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip validation for health check endpoints
        if ($this->isHealthCheckEndpoint($request)) {
            return $next($request);
        }

        // Validate request size
        if (!$this->validateRequestSize($request)) {
            return $this->requestTooLargeResponse();
        }

        // Sanitize input data
        $this->sanitizeInput($request);

        // Validate content type
        if (!$this->validateContentType($request)) {
            return $this->unsupportedMediaTypeResponse();
        }

        // Check for malicious patterns
        if ($this->containsMaliciousPatterns($request)) {
            Log::warning('Malicious input detected', [
                'ip' => $request->ip(),
                'user_agent' => $request->header('User-Agent'),
                'path' => $request->path(),
                'method' => $request->method()
            ]);
            
            return $this->badRequestResponse('Invalid input detected');
        }

        return $next($request);
    }

    /**
     * Validate request size
     */
    private function validateRequestSize(Request $request): bool
    {
        $maxSize = config('app.max_request_size', 10485760); // 10MB default
        $contentLength = $request->header('Content-Length', 0);
        
        return (int) $contentLength <= $maxSize;
    }

    /**
     * Sanitize input data
     */
    private function sanitizeInput(Request $request): void
    {
        $input = $request->all();
        $sanitized = $this->recursiveSanitize($input);
        
        // Replace request data with sanitized version
        $request->replace($sanitized);
    }

    /**
     * Recursively sanitize input data
     */
    private function recursiveSanitize(mixed $data): mixed
    {
        if (is_array($data)) {
            return array_map([$this, 'recursiveSanitize'], $data);
        }
        
        if (is_string($data)) {
            return $this->sanitizeString($data);
        }
        
        return $data;
    }

    /**
     * Sanitize string input
     */
    private function sanitizeString(string $input): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // Trim whitespace
        $input = trim($input);
        
        // Remove control characters except newlines and tabs
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);
        
        return $input;
    }

    /**
     * Validate content type
     */
    private function validateContentType(Request $request): bool
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return true;
        }

        $contentType = $request->header('Content-Type', '');
        $allowedTypes = [
            'application/json',
            'application/x-www-form-urlencoded',
            'multipart/form-data'
        ];

        foreach ($allowedTypes as $allowedType) {
            if (str_starts_with($contentType, $allowedType)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for malicious patterns
     */
    private function containsMaliciousPatterns(Request $request): bool
    {
        $input = json_encode($request->all());
        
        // Skip validation for authentication endpoints with common patterns
        if ($this->isAuthenticationEndpoint($request)) {
            return false;
        }
        
        $maliciousPatterns = [
            // SQL Injection patterns (more specific)
            '/(\b(SELECT|INSERT|UPDATE|DELETE|DROP|CREATE|ALTER|EXEC|UNION|SCRIPT)\s+.*\bFROM\b)/i',
            '/(\b(OR|AND)\s+\d+\s*=\s*\d+\s*--)/i',
            '/(\b(OR|AND)\s+[\'"]?\w+[\'"]?\s*=\s*[\'"]?\w+[\'"]?\s*--)/i',
            
            // XSS patterns (more specific)
            '/<script[^>]*>.*?<\/script>/i',
            '/javascript:\s*alert/i',
            '/on\w+\s*=\s*[\'"]/i',
            '/<iframe[^>]*>.*?<\/iframe>/i',
            
            // Command injection patterns (more specific)
            '/[;&|`$(){}[\]]\s*cat\s+\//i',
            '/[;&|`$(){}[\]]\s*ls\s+\//i',
            '/[;&|`$(){}[\]]\s*rm\s+-rf/i',
            
            // Path traversal patterns (more specific)
            '/\.\.\/\.\.\//',
            '/\.\.\\\\\.\.\\\\/',
            
            // LDAP injection patterns (more specific)
            '/[()=*!&|]\s*admin/i',
            '/[()=*!&|]\s*user/i',
            
            // NoSQL injection patterns (more specific)
            '/\$where\s*:\s*function/i',
            '/\$ne\s*:\s*null/i',
            '/\$gt\s*:\s*0/i',
            '/\$lt\s*:\s*0/i'
        ];

        foreach ($maliciousPatterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Request too large response
     */
    private function requestTooLargeResponse(): Response
    {
        return response()->json([
            'status' => 413,
            'error' => [
                'code' => 'REQUEST_TOO_LARGE',
                'message' => 'Request entity too large',
                'type' => 'client_error'
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'request_id' => request()->header('X-Request-ID', (string) \Illuminate\Support\Str::uuid()),
                'max_size' => config('app.max_request_size', 10485760)
            ]
        ], 413);
    }

    /**
     * Unsupported media type response
     */
    private function unsupportedMediaTypeResponse(): Response
    {
        return response()->json([
            'status' => 415,
            'error' => [
                'code' => 'UNSUPPORTED_MEDIA_TYPE',
                'message' => 'Unsupported media type',
                'type' => 'client_error'
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'request_id' => request()->header('X-Request-ID', (string) \Illuminate\Support\Str::uuid()),
                'supported_types' => [
                    'application/json',
                    'application/x-www-form-urlencoded',
                    'multipart/form-data'
                ]
            ]
        ], 415);
    }

    /**
     * Bad request response
     */
    private function badRequestResponse(string $message): Response
    {
        return response()->json([
            'status' => 400,
            'error' => [
                'code' => 'BAD_REQUEST',
                'message' => $message,
                'type' => 'client_error'
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'request_id' => request()->header('X-Request-ID', (string) \Illuminate\Support\Str::uuid())
            ]
        ], 400);
    }

    /**
     * Check if request is for health check endpoint
     */
    private function isHealthCheckEndpoint(Request $request): bool
    {
        $path = $request->path();
        $healthPaths = ['health', 'up', 'ready', 'live'];
        
        foreach ($healthPaths as $healthPath) {
            if (str_contains($path, $healthPath)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if request is for authentication endpoint
     */
    private function isAuthenticationEndpoint(Request $request): bool
    {
        $path = $request->path();
        $authPaths = ['auth', 'login', 'register', 'oauth', 'api-key'];
        
        foreach ($authPaths as $authPath) {
            if (str_contains($path, $authPath)) {
                return true;
            }
        }
        
        return false;
    }
}
