<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Shared\Services\TracingService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Distributed Tracing Middleware
 * 
 * Implements OpenTelemetry-compatible distributed tracing
 * for request tracking across microservices.
 */
class DistributedTracingMiddleware
{
    protected TracingService $tracingService;

    public function __construct(TracingService $tracingService)
    {
        $this->tracingService = $tracingService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Extract trace context from request headers
        $traceContext = $this->tracingService->extractTraceContext($request);
        
        // Start root span for this request
        $span = $this->tracingService->startSpan(
            operationName: $this->getOperationName($request),
            attributes: $this->getRequestAttributes($request),
            parentTraceId: $traceContext['trace_id'],
            parentSpanId: $traceContext['parent_span_id']
        );

        // Add trace context to request
        $request->merge([
            'trace_id' => $span['trace_id'],
            'span_id' => $span['span_id'],
            'tracing_context' => $traceContext,
        ]);

        // Add tracing headers to request for downstream services
        $this->addTracingHeaders($request, $span);

        $startTime = microtime(true);
        $exception = null;

        try {
            $response = $next($request);
            
            // Record successful completion
            $this->tracingService->recordEvent($span, 'request.completed', [
                'status_code' => $response->getStatusCode(),
                'response_size' => strlen($response->getContent()),
            ]);

            return $response;

        } catch (\Exception $e) {
            $exception = $e;
            
            // Record error event
            $this->tracingService->recordEvent($span, 'request.error', [
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
            ]);

            throw $e;

        } finally {
            // End the span
            $this->tracingService->endSpan($span, [
                'request_method' => $request->method(),
                'request_path' => $request->path(),
                'request_size' => strlen($request->getContent()),
                'execution_time_ms' => (microtime(true) - $startTime) * 1000,
                'memory_usage' => memory_get_usage(true),
                'peak_memory' => memory_get_peak_usage(true),
            ], $exception);

            // Add tracing headers to response
            if (isset($response)) {
                $this->addTracingHeadersToResponse($response, $span);
            }
        }
    }

    /**
     * Get operation name from request
     */
    protected function getOperationName(Request $request): string
    {
        $method = $request->method();
        $path = $request->path();
        
        // Convert path to operation name
        $operationName = strtoupper($method) . ' ' . $path;
        
        // Replace common patterns
        $operationName = preg_replace('/\/\d+/', '/{id}', $operationName);
        $operationName = preg_replace('/api\/v\d+\//', '', $operationName);
        
        return $operationName;
    }

    /**
     * Get request attributes for tracing
     */
    protected function getRequestAttributes(Request $request): array
    {
        return [
            'http.method' => $request->method(),
            'http.url' => $request->url(),
            'http.path' => $request->path(),
            'http.user_agent' => $request->userAgent(),
            'http.request.size' => strlen($request->getContent()),
            'http.scheme' => $request->getScheme(),
            'http.host' => $request->getHost(),
            'http.port' => $request->getPort(),
            'client.ip' => $request->ip(),
            'tenant.id' => $request->get('tenant_id') ?? $request->header('HRMS-Client-ID'),
            'user.id' => $request->get('user_id') ?? $request->header('X-User-ID'),
        ];
    }

    /**
     * Add tracing headers to request
     */
    protected function addTracingHeaders(Request $request, array $span): void
    {
        $headers = $this->tracingService->injectTraceContext([
            'trace_id' => $span['trace_id'],
            'span_id' => $span['span_id'],
            'parent_span_id' => $span['parent_span_id'] ?? null,
            'baggage' => $span['attributes'] ?? [],
        ]);

        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }
    }

    /**
     * Add tracing headers to response
     */
    protected function addTracingHeadersToResponse(Response $response, array $span): void
    {
        $response->headers->set('X-Trace-Id', $span['trace_id']);
        $response->headers->set('X-Span-Id', $span['span_id']);
        $response->headers->set('X-Request-ID', $span['trace_id']);
    }

    /**
     * Create trace context for external service call
     */
    public function createTraceContextForExternalCall(string $serviceName, string $operationName, array $attributes = []): array
    {
        return $this->tracingService->createTraceContextForExternalCall($serviceName, $operationName, $attributes);
    }

    /**
     * Record a custom event
     */
    public function recordEvent(string $eventName, array $attributes = []): void
    {
        $span = [
            'trace_id' => $this->tracingService->getTraceId(),
            'span_id' => $this->tracingService->getSpanId(),
        ];

        $this->tracingService->recordEvent($span, $eventName, $attributes);
    }

    /**
     * Record a custom metric
     */
    public function recordMetric(string $name, float $value, array $attributes = []): void
    {
        $this->tracingService->recordMetric($name, $value, $attributes);
    }
}
