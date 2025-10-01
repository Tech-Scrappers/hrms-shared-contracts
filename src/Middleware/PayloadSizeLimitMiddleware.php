<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PayloadSizeLimitMiddleware
{
    /**
     * Maximum payload size in bytes (default: 1MB)
     */
    protected $maxPayloadSize = 1048576; // 1MB

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        try {
            // Check Content-Length header
            $contentLength = $request->header('Content-Length');
            if ($contentLength && $contentLength > $this->maxPayloadSize) {
                return $this->payloadTooLargeResponse($contentLength);
            }

            // Check actual content size for POST/PUT/PATCH requests
            if (in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
                $content = $request->getContent();
                if (strlen($content) > $this->maxPayloadSize) {
                    return $this->payloadTooLargeResponse(strlen($content));
                }
            }

            return $next($request);
        } catch (\Exception $e) {
            Log::error('PayloadSizeLimitMiddleware error: '.$e->getMessage(), [
                'exception' => $e,
                'url' => $request->fullUrl(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Request processing failed',
                'error_code' => 'REQUEST_ERROR',
            ], 500);
        }
    }

    /**
     * Return payload too large response
     */
    protected function payloadTooLargeResponse(int $actualSize): JsonResponse
    {
        $maxSizeMB = round($this->maxPayloadSize / 1048576, 2);
        $actualSizeMB = round($actualSize / 1048576, 2);

        Log::warning('Payload size limit exceeded', [
            'max_size' => $this->maxPayloadSize,
            'actual_size' => $actualSize,
            'max_size_mb' => $maxSizeMB,
            'actual_size_mb' => $actualSizeMB,
        ]);

        return response()->json([
            'success' => false,
            'message' => "Payload too large. Maximum size allowed: {$maxSizeMB}MB, received: {$actualSizeMB}MB",
            'error_code' => 'PAYLOAD_TOO_LARGE',
            'details' => [
                'max_size_bytes' => $this->maxPayloadSize,
                'actual_size_bytes' => $actualSize,
                'max_size_mb' => $maxSizeMB,
                'actual_size_mb' => $actualSizeMB,
            ],
        ], 413);
    }

    /**
     * Set maximum payload size
     */
    public function setMaxPayloadSize(int $size): void
    {
        $this->maxPayloadSize = $size;
    }

    /**
     * Get maximum payload size
     */
    public function getMaxPayloadSize(): int
    {
        return $this->maxPayloadSize;
    }
}
