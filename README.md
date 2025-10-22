# HRMS Shared Components Package

A comprehensive shared components package for the HRMS microservices ecosystem, providing enterprise-grade middleware, services, events, and utilities for building scalable multi-tenant HR management systems.

## 📦 Package Contents

### 🔒 Middleware (22 Components)
**Authentication & Authorization:**
- **UnifiedAuthenticationMiddleware**: Unified OAuth2 and API key authentication with tenant context
- **OAuth2TokenValidationMiddleware**: OAuth2 token validation and user context extraction
- **ApiKeyAuthenticationMiddleware**: API key-based authentication
- **ApiKeyPermissionMiddleware**: API key permission validation
- **ScopeMiddleware**: OAuth2 scope-based authorization
- **SuperAdminMiddleware**: Super admin access control

**Security & Protection:**
- **SecurityHeadersMiddleware**: Enterprise-grade security headers (CSP, HSTS, etc.)
- **InputValidationMiddleware**: XSS and injection prevention with request sanitization
- **CsrfProtectionMiddleware**: CSRF token validation and protection
- **PayloadSizeLimitMiddleware**: Request payload size validation
- **BruteForceProtectionMiddleware**: Brute force attack prevention

**Rate Limiting & Performance:**
- **EnterpriseRateLimitMiddleware**: Advanced enterprise rate limiting
- **EnhancedRateLimitMiddleware**: Enhanced rate limiting with burst control
- **ApiKeyRateLimitMiddleware**: API key-specific rate limiting
- **PerformanceMonitoringMiddleware**: Request performance monitoring and metrics
- **ResponseCachingMiddleware**: Response caching for improved performance

**Multi-tenancy & Database:**
- **HybridTenantDatabaseMiddleware**: Hybrid tenant database switching
- **TenantDatabaseMiddleware**: Standard tenant database switching
- **ProductionTenantDatabaseMiddleware**: Production-optimized tenant database handling

**Utilities:**
- **JsonResponseMiddleware**: Consistent JSON response formatting
- **StructuredLoggingMiddleware**: Structured request/response logging
- **EnvironmentAwareCorsMiddleware**: Environment-aware CORS handling

### 🛠️ Services (12 Components)
**Database & Multi-tenancy:**
- **HybridDatabaseService**: Database-per-service + Database-per-tenant management with automatic provisioning
- **TenantDatabaseService**: Tenant database lifecycle management
- **DatabaseConnectionManager**: Dynamic database connection management

**API & Communication:**
- **ApiKeyService**: API key management and validation
- **ApiResponseService**: Centralized API response management with error codes
- **EventPublisher**: Event publishing service with retry mechanisms

**Security & Auditing:**
- **AuditLogService**: Comprehensive audit logging with data sanitization
- **SecurityAuditService**: Security audit and compliance reporting
- **SecurityService**: Core security utilities and validation

**Messaging & Caching:**
- **QueryCacheService**: Database query result caching
- **OutboxDispatcher**: Outbox pattern implementation for reliable messaging
- **OutboxEnqueuer**: Event enqueueing for outbox pattern

### 📡 Events System (25+ Event Types)
**Event Infrastructure:**
- **EventBus**: Redis-based event communication with retry mechanisms
- **EventSubscriber**: Event subscription and handling system
- **BaseEvent**: Abstract base class for all HRMS events

**Tenant Events:**
- **TenantCreatedEvent**: Tenant creation events
- **TenantUpdatedEvent**: Tenant update events
- **TenantDeletedEvent**: Tenant deletion events
- **TenantMigrationEvent**: Tenant database migration events

**Employee Events:**
- **EmployeeCreatedEvent**: Employee creation events
- **EmployeeUpdatedEvent**: Employee update events
- **EmployeeDeletedEvent**: Employee deletion events
- **DepartmentCreated/Updated/Deleted**: Department lifecycle events
- **BranchCreated/Updated/Deleted**: Branch lifecycle events

**Attendance Events:**
- **AttendanceCheckIn/CheckOut**: Check-in/out events
- **AttendanceBreakStart/End**: Break management events
- **ManualEntryRequestCreated/Approved/Rejected/Updated/Deleted**: Manual entry workflow events

**Identity Events:**
- **UserCreated**: User creation events
- **IdentityEvents**: Identity service specific events

**Leave Events:**
- **LeaveEvents**: Leave management related events

**Approval Events:**
- **ApprovalEvents**: Approval workflow events

### 🏗️ Base Classes & Utilities
**Base Classes:**
- **BaseController**: Abstract base controller with tenant awareness and standardized responses
- **BaseRepository**: Abstract base repository with tenant isolation
- **BaseService**: Abstract base service class

**Traits (7 Components):**
- **StandardizedResponseTrait**: Standardized API response methods
- **EnterpriseApiResponseTrait**: Enterprise-grade API response formatting
- **TenantAwareTrait**: Tenant-aware functionality for any class
- **ApiResponseTrait**: Legacy API response methods (deprecated)
- **AuditableTrait**: Audit logging capabilities
- **AuditLogTrait**: Enhanced audit logging
- **ErrorHandlingTrait**: Centralized error handling

**Models:**
- **TenantAwareModel**: Base model with tenant isolation
- **AuditLog**: Audit log model

### ⚙️ Commands & Configuration
**Artisan Commands:**
- **EventWorkerCommand**: Start event worker for processing events
- **ProcessEventsCommand**: Process events from the event bus
- **SecurityAuditCommand**: Run security audits
- **SqsConsumerCommand**: SQS message consumer command
- **DispatchOutboxCommand**: Dispatch outbox events command

**Configuration Files:**
- **security.php**: Security configuration (CSRF, rate limiting, etc.)
- **hybrid-database.php**: Hybrid database configuration
- **cors.php**: CORS configuration
- **performance.php**: Performance monitoring configuration

**Helpers & Utilities:**
- **UuidHelper**: UUID generation utilities
- **ApiErrorCode**: Standardized API error codes enum
- **InputSanitizer**: Input sanitization utilities
- **ExternalDataTransformer**: External data transformation utilities
- **ExternalEmployeeResolver**: External employee resolution utilities

### 📨 Messaging Components
**Message Processing:**
- **MessageConsumer**: Generic message consumer interface
- **MqttConsumer**: MQTT message consumer implementation
- **MqttPublisher**: MQTT message publisher
- **MqttLogger**: MQTT logging utilities
- **SqsConsumer**: AWS SQS message consumer

**Jobs & Queues:**
- **PublishEventJob**: Event publishing job for queue processing

## 🚀 Installation

```bash
composer require hrms/shared
```

## 📋 Usage

### Service Provider Registration

```php
// In bootstrap/app.php
->withProviders([
    \Shared\Providers\SharedServicesProvider::class,
    \Shared\Providers\HybridDatabaseServiceProvider::class,
    \Shared\Providers\SecurityServiceProvider::class,
])
```

### Middleware Usage

**Basic Authentication & Multi-tenancy:**
```php
// In routes/api.php
Route::middleware(['unified.auth', 'hybrid.tenant'])->group(function () {
    // Your protected routes with tenant context
});

// API Key Authentication
Route::middleware(['api.key.auth', 'api.key.permissions:read,write'])->group(function () {
    // API key protected routes
});

// OAuth2 with Scopes
Route::middleware(['oauth2.token', 'scope:employee.read'])->group(function () {
    // OAuth2 protected routes with specific scope
});
```

**Security & Rate Limiting:**
```php
// Enterprise Security Stack
Route::middleware([
    'security.headers',
    'input.validation',
    'csrf.protection',
    'enterprise.rate.limit',
    'performance.monitoring'
])->group(function () {
    // Highly secured routes
});
```

### Service Usage

**Database & Multi-tenancy:**
```php
use Shared\Services\HybridDatabaseService;
use Shared\Services\TenantDatabaseService;

// Switch to tenant database
$dbService = app(HybridDatabaseService::class);
$dbService->switchToTenantDatabase('tenant-123');

// Create tenant database
$tenantService = app(TenantDatabaseService::class);
$tenantService->createTenantDatabase($tenantData);
```

**Event System:**
```php
use Shared\Events\EventBus;
use Shared\Events\EmployeeCreatedEvent;

// Publish events
$eventBus = app(EventBus::class);
$event = new EmployeeCreatedEvent('tenant-123', $employeeData, $userId);
$eventBus->publish($event);

// Subscribe to events
$eventBus->subscribe('employee.created', function ($payload, $metadata) {
    // Handle employee creation
});
```

**API Responses:**
```php
use Shared\Traits\StandardizedResponseTrait;

class EmployeeController extends BaseController
{
    use StandardizedResponseTrait;
    
    public function index()
    {
        return $this->success($employees, 'Employees retrieved successfully');
    }
    
    public function store(Request $request)
    {
        // Validation and creation logic
        return $this->created($employee, 'Employee created successfully');
    }
}
```

**Audit Logging:**
```php
use Shared\Services\AuditLogService;

$auditService = app(AuditLogService::class);

// Log authentication events
$auditService->logAuthenticationEvent('login', $userId, $tenantId, $request);

// Log data access events
$auditService->logDataAccessEvent('read', 'employee', $employeeId, $tenantId, $userId, $request);
```

### Event System Usage

**Publishing Events:**
```php
use Shared\Events\EmployeeCreatedEvent;
use Shared\Events\AttendanceCheckIn;

// Publish employee events
$event = new EmployeeCreatedEvent($tenantId, $employeeData, $userId);
event($event);

// Publish attendance events
$event = new AttendanceCheckIn($tenantId, $attendanceData, $userId);
event($event);
```

**Event Workers:**
```bash
# Start event worker
php artisan events:worker --service=employee-service

# Process events
php artisan events:process --service=core-service --timeout=60
```

### Configuration

**Publish Configuration Files:**
```bash
php artisan vendor:publish --provider="Shared\Providers\SharedServicesProvider"
```

**Environment Variables:**
```env
# Service Configuration
SERVICE_NAME=employee-service
TENANT_DATABASE_PREFIX=hrms_tenant_

# Security Configuration
CSRF_PROTECTION=true
RATE_LIMIT_ENABLED=true
RATE_LIMIT_BURST=100
RATE_LIMIT_HOURLY=1000

# Event System
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

## 🔧 Advanced Configuration

### Hybrid Database Architecture

The package supports a hybrid database architecture:
- **Central Database**: Stores tenant metadata, API keys, and shared data
- **Service Databases**: Each microservice has its own database
- **Tenant Databases**: Each tenant gets isolated databases per service

### Security Features

- **Enterprise Security Headers**: CSP, HSTS, X-Frame-Options, etc.
- **Input Sanitization**: Automatic XSS and injection prevention
- **Rate Limiting**: Multi-tier rate limiting with burst control
- **Audit Logging**: Comprehensive audit trail with data sanitization
- **CSRF Protection**: Token-based CSRF protection

### Event-Driven Architecture

- **Redis-based Event Bus**: Reliable event communication
- **Retry Mechanisms**: Automatic retry with exponential backoff
- **Event History**: Event persistence and replay capabilities
- **Multi-service Support**: Events can be consumed across services

## 🧪 Testing

```bash
# Run all tests
composer test

# Run with coverage
composer test-coverage

# Code style check
composer cs-check

# Fix code style
composer cs-fix
```

## 📊 Monitoring & Observability

The package includes comprehensive monitoring capabilities:

- **Performance Monitoring**: Request timing and performance metrics
- **Structured Logging**: JSON-formatted logs with correlation IDs
- **Security Auditing**: Automated security compliance checks
- **Event Tracking**: Complete event lifecycle monitoring

## 🔒 Security Considerations

- All sensitive data is automatically sanitized in audit logs
- API keys are hashed and cached for performance
- Tenant isolation is enforced at the database level
- CSRF protection is enabled by default
- Rate limiting prevents abuse and DoS attacks

## 📄 License

MIT License - see LICENSE file for details.

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests and code style checks
5. Submit a pull request

## 📞 Support

For support and questions, please contact the HRMS development team at dev@hrms.com.
