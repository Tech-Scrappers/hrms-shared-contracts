<?php

namespace Shared\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Shared\Enums\ApiErrorCode;

/**
 * Brute Force Protection Middleware
 * 
 * Protects authentication endpoints from brute force attacks with:
 * - Progressive rate limiting
 * - Account lockout after repeated failures
 * - IP-based blocking
 * - Exponential backoff
 */
class BruteForceProtectionMiddleware
{
    /**
     * Maximum failed attempts before lockout
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Lockout duration in seconds (15 minutes)
     */
    private const LOCKOUT_DURATION = 900;

    /**
     * Rate limit decay in seconds (1 minute)
     */
    private const DECAY_SECONDS = 60;

    /**
     * IP ban duration in seconds (1 hour)
     */
    private const IP_BAN_DURATION = 3600;

    /**
     * Maximum failed attempts from same IP before ban
     */
    private const IP_MAX_ATTEMPTS = 10;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, int $maxAttempts = null, int $decaySeconds = null)
    {
        $maxAttempts = $maxAttempts ?? self::MAX_ATTEMPTS;
        $decaySeconds = $decaySeconds ?? self::DECAY_SECONDS;

        $email = $request->input('email');
        $ip = $request->ip();

        // Check if IP is banned
        if ($this->isIpBanned($ip)) {
            Log::warning('Login attempt from banned IP', [
                'ip' => $ip,
                'email' => $email,
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Too many failed login attempts. Your IP has been temporarily blocked.',
                'error_code' => ApiErrorCode::RATE_LIMIT_EXCEEDED->value,
                'retry_after' => $this->getIpBanRemainingTime($ip),
            ], 429);
        }

        // Check if email account is locked
        if ($email && $this->isAccountLocked($email)) {
            $retryAfter = $this->getLockoutRemainingTime($email);

            Log::warning('Login attempt on locked account', [
                'email' => $email,
                'ip' => $ip,
                'retry_after' => $retryAfter,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Account temporarily locked due to too many failed login attempts. Please try again later.',
                'error_code' => ApiErrorCode::RATE_LIMIT_EXCEEDED->value,
                'retry_after' => $retryAfter,
            ], 429);
        }

        // Check rate limiting
        if ($email && RateLimiter::tooManyAttempts($this->throttleKey($email), $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($this->throttleKey($email));

            Log::warning('Rate limit exceeded for login', [
                'email' => $email,
                'ip' => $ip,
                'retry_after' => $retryAfter,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Too many login attempts. Please try again later.',
                'error_code' => ApiErrorCode::RATE_LIMIT_EXCEEDED->value,
                'retry_after' => $retryAfter,
            ], 429);
        }

        $response = $next($request);

        // Track failed login attempts
        if ($response->status() === 401 && $email) {
            $this->incrementFailedAttempts($email, $ip);
        }

        // Clear failed attempts on successful login
        if ($response->status() === 200 && $email) {
            $this->clearFailedAttempts($email, $ip);
        }

        return $response;
    }

    /**
     * Increment failed login attempts
     */
    private function incrementFailedAttempts(string $email, string $ip): void
    {
        // Increment email-based attempts
        RateLimiter::hit($this->throttleKey($email), self::DECAY_SECONDS);

        $emailAttempts = $this->getFailedAttempts($email);
        Cache::put($this->attemptsKey($email), $emailAttempts + 1, now()->addSeconds(self::LOCKOUT_DURATION));

        // Lock account if max attempts reached
        if ($emailAttempts + 1 >= self::MAX_ATTEMPTS) {
            $this->lockAccount($email);
            
            Log::warning('Account locked due to failed login attempts', [
                'email' => $email,
                'attempts' => $emailAttempts + 1,
                'ip' => $ip,
            ]);
        }

        // Increment IP-based attempts
        $ipAttempts = $this->getIpFailedAttempts($ip);
        Cache::put($this->ipAttemptsKey($ip), $ipAttempts + 1, now()->addSeconds(self::IP_BAN_DURATION));

        // Ban IP if max attempts reached
        if ($ipAttempts + 1 >= self::IP_MAX_ATTEMPTS) {
            $this->banIp($ip);
            
            Log::warning('IP banned due to failed login attempts', [
                'ip' => $ip,
                'attempts' => $ipAttempts + 1,
            ]);
        }
    }

    /**
     * Clear failed login attempts
     */
    private function clearFailedAttempts(string $email, string $ip): void
    {
        RateLimiter::clear($this->throttleKey($email));
        Cache::forget($this->attemptsKey($email));
        Cache::forget($this->lockoutKey($email));
        Cache::forget($this->ipAttemptsKey($ip));
        
        Log::info('Successful login, cleared failed attempts', [
            'email' => $email,
            'ip' => $ip,
        ]);
    }

    /**
     * Get failed attempts count
     */
    private function getFailedAttempts(string $email): int
    {
        return Cache::get($this->attemptsKey($email), 0);
    }

    /**
     * Get IP failed attempts count
     */
    private function getIpFailedAttempts(string $ip): int
    {
        return Cache::get($this->ipAttemptsKey($ip), 0);
    }

    /**
     * Lock account
     */
    private function lockAccount(string $email): void
    {
        Cache::put($this->lockoutKey($email), true, now()->addSeconds(self::LOCKOUT_DURATION));
    }

    /**
     * Check if account is locked
     */
    private function isAccountLocked(string $email): bool
    {
        return Cache::has($this->lockoutKey($email));
    }

    /**
     * Get lockout remaining time
     */
    private function getLockoutRemainingTime(string $email): int
    {
        $lockedUntil = Cache::get($this->lockoutKey($email));
        if (!$lockedUntil) {
            return 0;
        }

        $remaining = now()->diffInSeconds($lockedUntil, false);
        return max(0, $remaining);
    }

    /**
     * Ban IP address
     */
    private function banIp(string $ip): void
    {
        Cache::put($this->ipBanKey($ip), true, now()->addSeconds(self::IP_BAN_DURATION));
    }

    /**
     * Check if IP is banned
     */
    private function isIpBanned(string $ip): bool
    {
        return Cache::has($this->ipBanKey($ip));
    }

    /**
     * Get IP ban remaining time
     */
    private function getIpBanRemainingTime(string $ip): int
    {
        $bannedUntil = Cache::get($this->ipBanKey($ip));
        if (!$bannedUntil) {
            return 0;
        }

        $remaining = now()->diffInSeconds($bannedUntil, false);
        return max(0, $remaining);
    }

    /**
     * Get throttle key for rate limiting
     */
    private function throttleKey(string $email): string
    {
        return 'login_throttle:' . strtolower($email);
    }

    /**
     * Get attempts cache key
     */
    private function attemptsKey(string $email): string
    {
        return 'login_attempts:' . strtolower($email);
    }

    /**
     * Get lockout cache key
     */
    private function lockoutKey(string $email): string
    {
        return 'account_lockout:' . strtolower($email);
    }

    /**
     * Get IP attempts cache key
     */
    private function ipAttemptsKey(string $ip): string
    {
        return 'login_ip_attempts:' . $ip;
    }

    /**
     * Get IP ban cache key
     */
    private function ipBanKey(string $ip): string
    {
        return 'ip_ban:' . $ip;
    }
}
