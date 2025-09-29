<?php

namespace Shared\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Distributed Tracing Service
 * 
 * Implements OpenTelemetry-compatible distributed tracing
 * for microservices request tracking and performance monitoring.
 */
class TracingService
{
    protected string $traceId;
    protected string $spanId;
    protected array $baggage;
    protected array $attributes;

    public function __construct()
    {
        $this->traceId = $this->generateTraceId();
        $this->spanId = $this->generateSpanId();
        $this->baggage = [];
        $this->attributes = [];
    }

    /**
     * Start a new trace
     */
    public function startTrace(string $operationName, array $attributes = []): array
    {
        $traceId = $this->generateTraceId();
        $spanId = $this->generateSpanId();
        
        $this->attributes = array_merge($this->attributes, $attributes);
        
        Log::info('Trace started', [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'operation_name' => $operationName,
            'attributes' => $this->attributes,
            'timestamp' => now()->toISOString(),
        ]);

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'operation_name' => $operationName,
            'start_time' => microtime(true),
            'attributes' => $this->attributes,
        ];
    }

    /**
     * Start a new span within an existing trace
     */
    public function startSpan(string $operationName, array $attributes = [], ?string $parentTraceId = null, ?string $parentSpanId = null): array
    {
        $spanId = $this->generateSpanId();
        $traceId = $parentTraceId ?? $this->traceId;
        
        $spanAttributes = array_merge($this->attributes, $attributes);
        
        Log::info('Span started', [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'operation_name' => $operationName,
            'attributes' => $spanAttributes,
            'timestamp' => now()->toISOString(),
        ]);

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'operation_name' => $operationName,
            'start_time' => microtime(true),
            'attributes' => $spanAttributes,
        ];
    }

    /**
     * End a span
     */
    public function endSpan(array $span, array $attributes = [], ?\Exception $exception = null): array
    {
        $endTime = microtime(true);
        $duration = ($endTime - $span['start_time']) * 1000; // Convert to milliseconds
        
        $spanData = array_merge($span, [
            'end_time' => $endTime,
            'duration_ms' => $duration,
            'status' => $exception ? 'error' : 'success',
            'attributes' => array_merge($span['attributes'], $attributes),
        ]);

        if ($exception) {
            $spanData['error'] = [
                'message' => $exception->getMessage(),
                'code' => $exception->getCode(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        Log::info('Span ended', $spanData);

        return $spanData;
    }

    /**
     * Add attributes to current trace
     */
    public function addAttributes(array $attributes): void
    {
        $this->attributes = array_merge($this->attributes, $attributes);
    }

    /**
     * Add baggage to trace context
     */
    public function addBaggage(string $key, string $value): void
    {
        $this->baggage[$key] = $value;
    }

    /**
     * Get baggage from trace context
     */
    public function getBaggage(string $key): ?string
    {
        return $this->baggage[$key] ?? null;
    }

    /**
     * Extract trace context from request headers
     */
    public function extractTraceContext(Request $request): array
    {
        $traceId = $request->header('X-Trace-Id') ?? $this->generateTraceId();
        $spanId = $request->header('X-Span-Id') ?? $this->generateSpanId();
        $parentSpanId = $request->header('X-Parent-Span-Id');
        
        // Extract baggage from headers
        $baggage = [];
        foreach ($request->headers as $key => $value) {
            if (str_starts_with($key, 'X-Baggage-')) {
                $baggageKey = str_replace('X-Baggage-', '', $key);
                $baggage[$baggageKey] = $value[0];
            }
        }

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'baggage' => $baggage,
        ];
    }

    /**
     * Inject trace context into request headers
     */
    public function injectTraceContext(array $traceContext, array $headers = []): array
    {
        $headers['X-Trace-Id'] = $traceContext['trace_id'];
        $headers['X-Span-Id'] = $traceContext['span_id'];
        
        if (isset($traceContext['parent_span_id'])) {
            $headers['X-Parent-Span-Id'] = $traceContext['parent_span_id'];
        }

        // Inject baggage
        foreach ($traceContext['baggage'] ?? [] as $key => $value) {
            $headers["X-Baggage-{$key}"] = $value;
        }

        return $headers;
    }

    /**
     * Create trace context for external service call
     */
    public function createTraceContextForExternalCall(string $serviceName, string $operationName, array $attributes = []): array
    {
        $traceId = $this->generateTraceId();
        $spanId = $this->generateSpanId();
        
        $traceContext = [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'service_name' => $serviceName,
            'operation_name' => $operationName,
            'attributes' => array_merge($this->attributes, $attributes),
            'baggage' => $this->baggage,
        ];

        Log::info('External service trace context created', $traceContext);

        return $traceContext;
    }

    /**
     * Record an event within a span
     */
    public function recordEvent(array $span, string $eventName, array $attributes = []): void
    {
        $event = [
            'trace_id' => $span['trace_id'],
            'span_id' => $span['span_id'],
            'event_name' => $eventName,
            'timestamp' => now()->toISOString(),
            'attributes' => $attributes,
        ];

        Log::info('Span event recorded', $event);
    }

    /**
     * Record a metric
     */
    public function recordMetric(string $name, float $value, array $attributes = []): void
    {
        $metric = [
            'name' => $name,
            'value' => $value,
            'attributes' => array_merge($this->attributes, $attributes),
            'timestamp' => now()->toISOString(),
        ];

        Log::info('Metric recorded', $metric);
    }

    /**
     * Generate a trace ID (32 character hex string)
     */
    protected function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate a span ID (16 character hex string)
     */
    protected function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Get current trace ID
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * Get current span ID
     */
    public function getSpanId(): string
    {
        return $this->spanId;
    }

    /**
     * Get all baggage
     */
    public function getAllBaggage(): array
    {
        return $this->baggage;
    }

    /**
     * Get all attributes
     */
    public function getAllAttributes(): array
    {
        return $this->attributes;
    }
}
