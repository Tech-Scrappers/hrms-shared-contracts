# HRMS Shared Components Package

A comprehensive shared components package for the HRMS microservices ecosystem.

## 📦 Package Contents

### Middleware
- **SecurityHeadersMiddleware**: Enterprise security headers
- **UnifiedAuthenticationMiddleware**: OAuth2 and API key authentication
- **HybridTenantDatabaseMiddleware**: Tenant database switching
- **RateLimitMiddleware**: Advanced rate limiting
- **InputValidationMiddleware**: XSS and injection prevention
- **JsonResponseMiddleware**: Consistent JSON responses

### Services
- **HybridDatabaseService**: Database-per-service + Database-per-tenant management
- **TenantMigrationService**: Tenant database provisioning
- **ApiKeyService**: API key management
- **EventBus**: Redis-based event communication
- **AuditLogService**: Comprehensive audit logging

### Events
- **TenantCreatedEvent**: Tenant creation events
- **EmployeeCreatedEvent**: Employee creation events
- **AttendanceEvents**: Attendance-related events

### Utilities
- **BaseController**: Base controller with common functionality
- **BaseRepository**: Base repository pattern
- **TenantAwareTrait**: Tenant-aware functionality
- **ApiResponseTrait**: Standardized API responses

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
])
```

### Middleware Usage

```php
// In routes/api.php
Route::middleware(['unified.auth', 'hybrid.tenant'])->group(function () {
    // Your protected routes
});
```

### Service Usage

```php
use Shared\Services\HybridDatabaseService;
use Shared\Services\EventBus;

$dbService = app(HybridDatabaseService::class);
$eventBus = app(EventBus::class);
```

## 🔧 Configuration

The package uses Laravel's configuration system. Publish the config files:

```bash
php artisan vendor:publish --provider="Shared\Providers\SharedServicesProvider"
```

## 🧪 Testing

```bash
composer test
composer test-coverage
```

## 📄 License

MIT License - see LICENSE file for details.
