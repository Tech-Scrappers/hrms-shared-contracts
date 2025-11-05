# HRMS Shared Components Package

A comprehensive shared components package for the HRMS microservices ecosystem, providing enterprise-grade middleware, services, events, and utilities for building scalable multi-tenant HR management systems with **distributed database architecture**.

## 📦 Package Contents

### 🗄️ **Database Architecture: Distributed**

Each microservice has its own PostgreSQL database instance (Docker container). Each tenant gets separate databases in each service's own DB instance.

**Key Components:**
- **DistributedDatabaseService**: Manages tenant databases on current service's DB instance
- **DistributedTenantDatabaseMiddleware**: Automatic tenant database switching
- **DistributedDatabaseServiceProvider**: Service registration and configuration

**Database Naming:** `tenant_{tenantId}_{service}`

---

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
- **DistributedTenantDatabaseMiddleware**: Distributed tenant database switching (each service has own DB instance)

**Utilities:**
- **JsonResponseMiddleware**: Consistent JSON response formatting
- **StructuredLoggingMiddleware**: Structured request/response logging
- **EnvironmentAwareCorsMiddleware**: Environment-aware CORS handling

---

### 🛠️ Services (6 Components)

**Database & Multi-tenancy:**
- **DistributedDatabaseService**: Tenant database management for distributed architecture with Docker support

**API & Communication:**
- **ApiKeyService**: API key management and validation
- **ApiResponseService**: Centralized API response management with error codes
- **EventPublisher**: Event publishing service with retry mechanisms

**Security & Auditing:**
- **AuditLogService**: Comprehensive audit logging with data sanitization
- **SecurityAuditService**: Security audit and compliance reporting
- **SecurityService**: Core security utilities and validation

**Caching:**
- **QueryCacheService**: Database query result caching

---

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

**Other Events:**
- **AttendanceEvents**: Check-in/out and break management
- **LeaveEvents**: Leave management
- **ApprovalEvents**: Approval workflow
- **IdentityEvents**: User and identity management

---

### 🏗️ Base Classes & Utilities

**Base Classes:**
- **BaseController**: Abstract base controller with tenant awareness
- **BaseRepository**: Abstract base repository with tenant isolation
- **BaseService**: Abstract base service class

**Traits (7 Components):**
- **StandardizedResponseTrait**: Standardized API response methods
- **EnterpriseApiResponseTrait**: Enterprise-grade API response formatting
- **TenantAwareTrait**: Tenant-aware functionality
- **AuditableTrait**: Audit logging capabilities
- **AuditLogTrait**: Enhanced audit logging
- **ErrorHandlingTrait**: Centralized error handling

**Models:**
- **TenantAwareModel**: Base model with tenant isolation
- **AuditLog**: Audit log model

---

## 🚀 Installation

```bash
composer require hrms/shared
```

## 📋 Usage

### Service Provider Registration

In `bootstrap/app.php`:

```php
->withProviders([
    \Shared\Providers\SharedServicesProvider::class,
    \Shared\Providers\DistributedDatabaseServiceProvider::class,
])
```

### Middleware Usage

```php
// In routes/api.php
Route::middleware(['tenant.distributed'])->group(function () {
    // Your tenant-specific routes
    Route::get('/employees', [EmployeeController::class, 'index']);
});

// With authentication
Route::middleware(['unified.auth', 'tenant.distributed'])->group(function () {
    // Protected tenant routes
});
```

### Environment Configuration

Each service needs its own database configuration:

```env
# Service Configuration
SERVICE_NAME=identity-service  # or employee-service, core-service
DATABASE_ARCHITECTURE_MODE=distributed

# Database Configuration (Service-specific instance)
DB_CONNECTION=pgsql
DB_HOST=identity-db              # Service-specific DB host
DB_PORT=5432
DB_DATABASE=hrms_identity
DB_USERNAME=postgres
DB_PASSWORD=your_secure_password

# Distributed Database Settings
DISTRIBUTED_DATABASE_ENABLED=true
DISTRIBUTED_CONNECTION_POOLING=true
DISTRIBUTED_MAX_CONNECTIONS=50
DISTRIBUTED_CONNECTION_TIMEOUT=30

# Docker Settings
DOCKER_ENABLED=true
DB_HEALTH_CHECK_ENABLED=true
```

### Database Service Usage

```php
use Shared\Services\DistributedDatabaseService;

// Create tenant database on current service's DB instance
$dbService = app(DistributedDatabaseService::class);
$dbService->createTenantDatabase([
    'id' => 'tenant-uuid',
    'name' => 'Acme Corporation',
    'domain' => 'acme.hrms.local',
    'is_active' => true,
]);

// Switch to tenant database (done automatically by middleware)
$dbService->switchToTenantDatabase('tenant-uuid');

// Query tenant data
$employees = DB::table('employees')->get();

// Switch back to central database
$dbService->switchToCentralDatabase();
```

### Event-Driven Tenant Provisioning

```php
// In Identity Service (tenant creation)
use Shared\Events\TenantCreatedEvent;

$tenant = Tenant::create([...]);

// Create tenant database on Identity Service
$dbService->createTenantDatabase($tenant->toArray());

// Publish event for other services
event(new TenantCreatedEvent(
    $tenant->id,
    $tenant->toArray(),
    auth()->id()
));
```

```php
// In Employee/Core Services (event listener)
class CreateTenantDatabaseListener
{
    public function handle(TenantCreatedEvent $event): void
    {
        $dbService = app(DistributedDatabaseService::class);
        $dbService->createTenantDatabase($event->payload);
    }
}
```

---

## 🐳 Docker Architecture

Each microservice has its own PostgreSQL container:

```
├── identity-db (PostgreSQL)
│   ├── hrms_identity (central)
│   ├── tenant_uuid1_identity
│   └── tenant_uuid2_identity
│
├── employee-db (PostgreSQL)
│   ├── hrms_employee (central)
│   ├── tenant_uuid1_employee
│   └── tenant_uuid2_employee
│
└── core-db (PostgreSQL)
    ├── hrms_core (central)
    ├── tenant_uuid1_core
    └── tenant_uuid2_core
```

---

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

---

## 📊 Monitoring & Observability

**Performance Monitoring:**
- Request timing and performance metrics
- Database connection pool monitoring
- Query performance tracking

**Structured Logging:**
- JSON-formatted logs with correlation IDs
- Tenant context in all logs
- Database operation logging

**Security Auditing:**
- Automated security compliance checks
- Complete audit trail
- Data access logging

---

## 🔒 Security

**Database Isolation:**
- Each service has dedicated database instance
- No cross-service database access
- Tenant data isolated per service

**Connection Security:**
- SSL/TLS enabled
- Per-service credentials
- Automatic connection cleanup

**Audit Logging:**
- All database switches logged
- Connection errors tracked
- Tenant access monitored

---

## 📞 Support

For detailed documentation, see:
- [Distributed Architecture Guide](/docs/DISTRIBUTED_ARCHITECTURE_GUIDE.md)

For issues or questions:
- Check logs: `storage/logs/laravel.log`
- Enable debug mode: `APP_DEBUG=true`
- Test database: `php artisan tinker` → `DB::connection()->getPdo()`

---

## 📄 License

MIT License - see LICENSE file for details.

---

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Run tests and code style checks
5. Submit a pull request
