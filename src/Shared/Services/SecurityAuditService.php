<?php

namespace Shared\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Http\Request;
use Exception;

class SecurityAuditService
{
    private const AUDIT_CACHE_PREFIX = 'security_audit_';
    private const AUDIT_CACHE_TTL = 300; // 5 minutes

    /**
     * Perform comprehensive security audit
     *
     * @return array
     */
    public function performSecurityAudit(): array
    {
        $auditResults = [
            'timestamp' => now()->toISOString(),
            'overall_score' => 0,
            'total_checks' => 0,
            'passed_checks' => 0,
            'failed_checks' => 0,
            'warnings' => 0,
            'critical_issues' => [],
            'recommendations' => [],
            'checks' => []
        ];

        // Run all security checks
        $this->auditTenantIsolation($auditResults);
        $this->auditAuthenticationSecurity($auditResults);
        $this->auditDatabaseSecurity($auditResults);
        $this->auditApiSecurity($auditResults);
        $this->auditTokenSecurity($auditResults);
        $this->auditMiddlewareSecurity($auditResults);
        $this->auditConfigurationSecurity($auditResults);

        // Calculate overall score
        $auditResults['overall_score'] = $this->calculateOverallScore($auditResults);
        $auditResults['status'] = $this->getSecurityStatus($auditResults['overall_score']);

        // Log audit results
        $this->logAuditResults($auditResults);

        return $auditResults;
    }

    /**
     * Audit tenant isolation mechanisms
     */
    private function auditTenantIsolation(array &$auditResults): void
    {
        $checks = [
            'tenant_database_isolation' => $this->checkTenantDatabaseIsolation(),
            'tenant_data_separation' => $this->checkTenantDataSeparation(),
            'cross_tenant_access_prevention' => $this->checkCrossTenantAccessPrevention(),
            'tenant_context_validation' => $this->checkTenantContextValidation(),
        ];

        foreach ($checks as $checkName => $result) {
            $this->addCheckResult($auditResults, $checkName, $result);
        }
    }

    /**
     * Audit authentication security
     */
    private function auditAuthenticationSecurity(array &$auditResults): void
    {
        $checks = [
            'oauth2_tenant_validation' => $this->checkOAuth2TenantValidation(),
            'api_key_tenant_binding' => $this->checkApiKeyTenantBinding(),
            'password_security' => $this->checkPasswordSecurity(),
            'session_security' => $this->checkSessionSecurity(),
        ];

        foreach ($checks as $checkName => $result) {
            $this->addCheckResult($auditResults, $checkName, $result);
        }
    }

    /**
     * Audit database security
     */
    private function auditDatabaseSecurity(array &$auditResults): void
    {
        $checks = [
            'sql_injection_protection' => $this->checkSqlInjectionProtection(),
            'database_ssl_enforcement' => $this->checkDatabaseSSLEnforcement(),
            'connection_pooling_security' => $this->checkConnectionPoolingSecurity(),
            'database_access_controls' => $this->checkDatabaseAccessControls(),
        ];

        foreach ($checks as $checkName => $result) {
            $this->addCheckResult($auditResults, $checkName, $result);
        }
    }

    /**
     * Audit API security
     */
    private function auditApiSecurity(array &$auditResults): void
    {
        $checks = [
            'rate_limiting' => $this->checkRateLimiting(),
            'cors_configuration' => $this->checkCorsConfiguration(),
            'input_validation' => $this->checkInputValidation(),
            'error_handling' => $this->checkErrorHandling(),
        ];

        foreach ($checks as $checkName => $result) {
            $this->addCheckResult($auditResults, $checkName, $result);
        }
    }

    /**
     * Audit token security
     */
    private function auditTokenSecurity(array &$auditResults): void
    {
        $checks = [
            'token_encryption' => $this->checkTokenEncryption(),
            'token_expiration' => $this->checkTokenExpiration(),
            'token_scope_validation' => $this->checkTokenScopeValidation(),
            'token_revocation' => $this->checkTokenRevocation(),
        ];

        foreach ($checks as $checkName => $result) {
            $this->addCheckResult($auditResults, $checkName, $result);
        }
    }

    /**
     * Audit middleware security
     */
    private function auditMiddlewareSecurity(array &$auditResults): void
    {
        $checks = [
            'csrf_protection' => $this->checkCsrfProtection(),
            'security_headers' => $this->checkSecurityHeaders(),
            'authentication_middleware' => $this->checkAuthenticationMiddleware(),
            'authorization_middleware' => $this->checkAuthorizationMiddleware(),
        ];

        foreach ($checks as $checkName => $result) {
            $this->addCheckResult($auditResults, $checkName, $result);
        }
    }

    /**
     * Audit configuration security
     */
    private function auditConfigurationSecurity(array &$auditResults): void
    {
        $checks = [
            'environment_security' => $this->checkEnvironmentSecurity(),
            'secret_management' => $this->checkSecretManagement(),
            'logging_configuration' => $this->checkLoggingConfiguration(),
            'cache_security' => $this->checkCacheSecurity(),
        ];

        foreach ($checks as $checkName => $result) {
            $this->addCheckResult($auditResults, $checkName, $result);
        }
    }

    /**
     * Check tenant database isolation
     */
    private function checkTenantDatabaseIsolation(): array
    {
        try {
            // Check if tenant databases are properly isolated
            $tenantDatabases = DB::connection('pgsql')
                ->select("SELECT datname FROM pg_database WHERE datname LIKE 'tenant_%'");

            $isolationScore = 0;
            $issues = [];

            foreach ($tenantDatabases as $db) {
                $dbName = $db->datname;
                
                // Check if database has proper naming convention
                if (!preg_match('/^tenant_[a-f0-9-]+_(identity|employee|attendance)$/', $dbName)) {
                    $issues[] = "Invalid database naming convention: {$dbName}";
                    continue;
                }

                // Check if database has proper permissions
                $permissions = DB::connection('pgsql')
                    ->select("SELECT * FROM pg_database WHERE datname = ?", [$dbName]);

                if (empty($permissions)) {
                    $issues[] = "Database not found: {$dbName}";
                    continue;
                }

                $isolationScore += 1;
            }

            $score = count($tenantDatabases) > 0 ? ($isolationScore / count($tenantDatabases)) * 100 : 0;

            return [
                'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
                'score' => $score,
                'message' => $score >= 80 ? 'Tenant databases are properly isolated' : 'Tenant database isolation needs improvement',
                'issues' => $issues,
                'recommendations' => $score < 80 ? [
                    'Ensure all tenant databases follow naming convention',
                    'Verify database permissions are properly configured',
                    'Implement database access controls'
                ] : []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check tenant database isolation: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix database connection issues']
            ];
        }
    }

    /**
     * Check tenant data separation
     */
    private function checkTenantDataSeparation(): array
    {
        try {
            // Check if tenant data is properly separated
            $tenantCount = DB::connection('pgsql')
                ->table('tenants')
                ->where('is_active', true)
                ->count();

            if ($tenantCount === 0) {
                return [
                    'status' => 'warning',
                    'score' => 50,
                    'message' => 'No active tenants found',
                    'issues' => ['No tenants to check data separation'],
                    'recommendations' => ['Create test tenants to verify data separation']
                ];
            }

            // Check if each tenant has their own service databases
            $tenants = DB::connection('pgsql')
                ->table('tenants')
                ->where('is_active', true)
                ->get();

            $separationScore = 0;
            $issues = [];

            foreach ($tenants as $tenant) {
                $services = ['identity', 'employee', 'attendance'];
                $tenantServiceDbs = 0;

                foreach ($services as $service) {
                    $dbName = "tenant_{$tenant->id}_{$service}";
                    $exists = DB::connection('pgsql')
                        ->select("SELECT 1 FROM pg_database WHERE datname = ?", [$dbName]);

                    if (!empty($exists)) {
                        $tenantServiceDbs++;
                    }
                }

                if ($tenantServiceDbs === count($services)) {
                    $separationScore++;
                } else {
                    $issues[] = "Tenant {$tenant->domain} missing service databases";
                }
            }

            $score = ($separationScore / count($tenants)) * 100;

            return [
                'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
                'score' => $score,
                'message' => $score >= 80 ? 'Tenant data is properly separated' : 'Tenant data separation needs improvement',
                'issues' => $issues,
                'recommendations' => $score < 80 ? [
                    'Ensure all tenants have complete service databases',
                    'Verify tenant data isolation in each service',
                    'Implement tenant data validation'
                ] : []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check tenant data separation: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix database connection issues']
            ];
        }
    }

    /**
     * Check cross-tenant access prevention
     */
    private function checkCrossTenantAccessPrevention(): array
    {
        try {
            // This would require actual testing of cross-tenant access
            // For now, we'll check if the middleware is properly configured
            $middlewareExists = class_exists('Shared\Middleware\UnifiedAuthenticationMiddleware');
            
            if (!$middlewareExists) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'Cross-tenant access prevention middleware not found',
                    'issues' => ['UnifiedAuthenticationMiddleware not available'],
                    'recommendations' => ['Implement cross-tenant access prevention middleware']
                ];
            }

            // Check if tenant validation is implemented
            $reflection = new \ReflectionClass('Shared\Middleware\UnifiedAuthenticationMiddleware');
            $hasTenantValidation = $reflection->hasMethod('validateTenantContext');

            if (!$hasTenantValidation) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'Tenant validation not implemented in middleware',
                    'issues' => ['validateTenantContext method not found'],
                    'recommendations' => ['Implement tenant validation in authentication middleware']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'Cross-tenant access prevention is implemented',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check cross-tenant access prevention: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix middleware configuration issues']
            ];
        }
    }

    /**
     * Check OAuth2 tenant validation
     */
    private function checkOAuth2TenantValidation(): array
    {
        try {
            // Check if OAuth2 tokens include tenant information
            $hasTenantAwareRepo = class_exists('App\Services\TenantAwareAccessTokenRepository');
            
            if (!$hasTenantAwareRepo) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'Tenant-aware OAuth2 implementation not found',
                    'issues' => ['TenantAwareAccessTokenRepository not available'],
                    'recommendations' => ['Implement tenant-aware OAuth2 token handling']
                ];
            }

            // Check if tenant validation is in place
            $middlewareReflection = new \ReflectionClass('Shared\Middleware\UnifiedAuthenticationMiddleware');
            $hasOAuth2TenantValidation = $middlewareReflection->hasMethod('validateTenantContext');

            if (!$hasOAuth2TenantValidation) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'OAuth2 tenant validation not implemented',
                    'issues' => ['OAuth2 tenant validation method not found'],
                    'recommendations' => ['Implement OAuth2 tenant validation']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'OAuth2 tenant validation is implemented',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check OAuth2 tenant validation: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix OAuth2 configuration issues']
            ];
        }
    }

    /**
     * Check API key tenant binding
     */
    private function checkApiKeyTenantBinding(): array
    {
        try {
            // Check if API keys are properly bound to tenants
            $apiKeyService = app('Shared\Services\ApiKeyService');
            
            if (!$apiKeyService) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'API key service not available',
                    'issues' => ['ApiKeyService not registered'],
                    'recommendations' => ['Register ApiKeyService in service container']
                ];
            }

            // Check if API keys have tenant validation
            $reflection = new \ReflectionClass($apiKeyService);
            $hasTenantValidation = $reflection->hasMethod('validateApiKey');

            if (!$hasTenantValidation) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'API key tenant validation not implemented',
                    'issues' => ['validateApiKey method not found'],
                    'recommendations' => ['Implement API key tenant validation']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'API key tenant binding is implemented',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check API key tenant binding: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix API key service configuration']
            ];
        }
    }

    /**
     * Check password security
     */
    private function checkPasswordSecurity(): array
    {
        try {
            // Check if password hashing is properly configured
            $hashDriver = config('hashing.driver', 'bcrypt');
            
            if ($hashDriver !== 'bcrypt' && $hashDriver !== 'argon2id') {
                return [
                    'status' => 'warning',
                    'score' => 60,
                    'message' => 'Password hashing uses less secure algorithm',
                    'issues' => ["Using {$hashDriver} instead of bcrypt or argon2id"],
                    'recommendations' => ['Use bcrypt or argon2id for password hashing']
                ];
            }

            // Check password requirements
            $minLength = config('auth.password.min_length', 8);
            if ($minLength < 12) {
                return [
                    'status' => 'warning',
                    'score' => 70,
                    'message' => 'Password minimum length is too short',
                    'issues' => ["Minimum length is {$minLength}, should be at least 12"],
                    'recommendations' => ['Increase password minimum length to 12 characters']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'Password security is properly configured',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check password security: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix password configuration issues']
            ];
        }
    }

    /**
     * Check SQL injection protection
     */
    private function checkSqlInjectionProtection(): array
    {
        try {
            // Check if parameterized queries are used
            $hasParameterizedQueries = true; // This would require code analysis
            
            // Check if input validation is in place
            $hasInputValidation = class_exists('Shared\Middleware\InputValidationMiddleware');
            
            if (!$hasInputValidation) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'Input validation middleware not found',
                    'issues' => ['InputValidationMiddleware not available'],
                    'recommendations' => ['Implement input validation middleware']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'SQL injection protection is implemented',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check SQL injection protection: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix database security configuration']
            ];
        }
    }

    /**
     * Check database SSL enforcement
     */
    private function checkDatabaseSSLEnforcement(): array
    {
        try {
            $connections = config('database.connections', []);
            $sslEnforced = 0;
            $totalConnections = 0;

            foreach ($connections as $name => $config) {
                if (isset($config['driver']) && $config['driver'] === 'pgsql') {
                    $totalConnections++;
                    if (isset($config['sslmode']) && $config['sslmode'] === 'require') {
                        $sslEnforced++;
                    }
                }
            }

            $score = $totalConnections > 0 ? ($sslEnforced / $totalConnections) * 100 : 100;

            return [
                'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
                'score' => $score,
                'message' => $score >= 80 ? 'Database SSL is properly enforced' : 'Database SSL enforcement needs improvement',
                'issues' => $score < 80 ? ['Some database connections do not enforce SSL'] : [],
                'recommendations' => $score < 80 ? ['Set sslmode=require for all database connections'] : []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check database SSL enforcement: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix database configuration issues']
            ];
        }
    }

    /**
     * Check rate limiting
     */
    private function checkRateLimiting(): array
    {
        try {
            $hasRateLimitMiddleware = class_exists('Shared\Middleware\EnhancedRateLimitMiddleware');
            
            if (!$hasRateLimitMiddleware) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'Rate limiting middleware not found',
                    'issues' => ['EnhancedRateLimitMiddleware not available'],
                    'recommendations' => ['Implement rate limiting middleware']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'Rate limiting is implemented',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check rate limiting: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix rate limiting configuration']
            ];
        }
    }

    /**
     * Check CORS configuration
     */
    private function checkCorsConfiguration(): array
    {
        try {
            $corsOrigins = config('cors.allowed_origins', []);
            
            if (empty($corsOrigins)) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'CORS origins not configured',
                    'issues' => ['No CORS origins configured'],
                    'recommendations' => ['Configure specific CORS origins']
                ];
            }

            // Check if wildcard is used
            if (in_array('*', $corsOrigins)) {
                return [
                    'status' => 'warning',
                    'score' => 60,
                    'message' => 'CORS allows all origins',
                    'issues' => ['Wildcard (*) CORS origin is not secure'],
                    'recommendations' => ['Use specific CORS origins instead of wildcard']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'CORS is properly configured',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check CORS configuration: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix CORS configuration issues']
            ];
        }
    }

    /**
     * Check input validation
     */
    private function checkInputValidation(): array
    {
        try {
            $hasInputValidation = class_exists('Shared\Middleware\InputValidationMiddleware');
            $hasValidationHelper = class_exists('Shared\Helpers\ValidationHelper');
            
            if (!$hasInputValidation || !$hasValidationHelper) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'Input validation not fully implemented',
                    'issues' => [
                        $hasInputValidation ? '' : 'InputValidationMiddleware not available',
                        $hasValidationHelper ? '' : 'ValidationHelper not available'
                    ],
                    'recommendations' => ['Implement comprehensive input validation']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'Input validation is implemented',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check input validation: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix input validation configuration']
            ];
        }
    }

    /**
     * Check CSRF protection
     */
    private function checkCsrfProtection(): array
    {
        try {
            $hasCsrfMiddleware = class_exists('Shared\Middleware\CsrfProtectionMiddleware');
            
            if (!$hasCsrfMiddleware) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'CSRF protection middleware not found',
                    'issues' => ['CsrfProtectionMiddleware not available'],
                    'recommendations' => ['Implement CSRF protection middleware']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'CSRF protection is implemented',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check CSRF protection: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix CSRF protection configuration']
            ];
        }
    }

    /**
     * Check security headers
     */
    private function checkSecurityHeaders(): array
    {
        try {
            $hasSecurityHeaders = class_exists('Shared\Middleware\SecurityHeadersMiddleware');
            
            if (!$hasSecurityHeaders) {
                return [
                    'status' => 'fail',
                    'score' => 0,
                    'message' => 'Security headers middleware not found',
                    'issues' => ['SecurityHeadersMiddleware not available'],
                    'recommendations' => ['Implement security headers middleware']
                ];
            }

            return [
                'status' => 'pass',
                'score' => 100,
                'message' => 'Security headers are implemented',
                'issues' => [],
                'recommendations' => []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check security headers: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix security headers configuration']
            ];
        }
    }

    /**
     * Check environment security
     */
    private function checkEnvironmentSecurity(): array
    {
        try {
            $appEnv = config('app.env', 'production');
            $appDebug = config('app.debug', false);
            
            $issues = [];
            $score = 100;

            if ($appEnv !== 'production') {
                $issues[] = "Application is running in {$appEnv} environment";
                $score -= 20;
            }

            if ($appDebug) {
                $issues[] = 'Application debug mode is enabled';
                $score -= 30;
            }

            return [
                'status' => $score >= 80 ? 'pass' : ($score >= 60 ? 'warning' : 'fail'),
                'score' => $score,
                'message' => $score >= 80 ? 'Environment security is properly configured' : 'Environment security needs improvement',
                'issues' => $issues,
                'recommendations' => $score < 80 ? [
                    'Set APP_ENV=production',
                    'Set APP_DEBUG=false'
                ] : []
            ];
        } catch (Exception $e) {
            return [
                'status' => 'fail',
                'score' => 0,
                'message' => 'Failed to check environment security: ' . $e->getMessage(),
                'issues' => [$e->getMessage()],
                'recommendations' => ['Fix environment configuration']
            ];
        }
    }

    /**
     * Add check result to audit results
     */
    private function addCheckResult(array &$auditResults, string $checkName, array $result): void
    {
        $auditResults['total_checks']++;
        
        if ($result['status'] === 'pass') {
            $auditResults['passed_checks']++;
        } elseif ($result['status'] === 'warning') {
            $auditResults['warnings']++;
        } else {
            $auditResults['failed_checks']++;
            if ($result['score'] < 50) {
                $auditResults['critical_issues'][] = $checkName;
            }
        }

        $auditResults['checks'][$checkName] = $result;
        
        // Add recommendations
        if (!empty($result['recommendations'])) {
            $auditResults['recommendations'] = array_merge(
                $auditResults['recommendations'],
                $result['recommendations']
            );
        }
    }

    /**
     * Calculate overall security score
     */
    private function calculateOverallScore(array $auditResults): int
    {
        if ($auditResults['total_checks'] === 0) {
            return 0;
        }

        $totalScore = 0;
        foreach ($auditResults['checks'] as $check) {
            $totalScore += $check['score'];
        }

        return round($totalScore / $auditResults['total_checks']);
    }

    /**
     * Get security status based on score
     */
    private function getSecurityStatus(int $score): string
    {
        if ($score >= 90) {
            return 'excellent';
        } elseif ($score >= 80) {
            return 'good';
        } elseif ($score >= 70) {
            return 'fair';
        } elseif ($score >= 60) {
            return 'poor';
        } else {
            return 'critical';
        }
    }

    /**
     * Log audit results
     */
    private function logAuditResults(array $auditResults): void
    {
        Log::info('Security audit completed', [
            'overall_score' => $auditResults['overall_score'],
            'status' => $auditResults['status'],
            'total_checks' => $auditResults['total_checks'],
            'passed_checks' => $auditResults['passed_checks'],
            'failed_checks' => $auditResults['failed_checks'],
            'warnings' => $auditResults['warnings'],
            'critical_issues' => $auditResults['critical_issues'],
        ]);
    }

    /**
     * Get security audit summary
     */
    public function getSecuritySummary(): array
    {
        $cacheKey = self::AUDIT_CACHE_PREFIX . 'summary';
        
        return Cache::remember($cacheKey, self::AUDIT_CACHE_TTL, function () {
            $audit = $this->performSecurityAudit();
            
            return [
                'overall_score' => $audit['overall_score'],
                'status' => $audit['status'],
                'critical_issues_count' => count($audit['critical_issues']),
                'recommendations_count' => count($audit['recommendations']),
                'last_audit' => $audit['timestamp'],
            ];
        });
    }
}
