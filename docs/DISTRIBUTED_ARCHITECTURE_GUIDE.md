# Distributed Database Architecture Guide

## Overview

This guide explains how to use the distributed database architecture where each microservice has its own PostgreSQL database instance, designed for Docker and Kubernetes deployments.

## Architecture

### Previous Architecture (Hybrid)
- **Single PostgreSQL Instance** for all microservices
- Separate tenant databases per service within that instance
- Database naming: `tenant_{tenantId}_{service}`

### New Architecture (Distributed)
- **Each microservice has its OWN PostgreSQL instance** (Docker container)
- Each tenant gets separate databases in each service's own DB instance
- Same database naming: `tenant_{tenantId}_{service}`
- Event-driven cross-service tenant provisioning

## Key Components

### 1. DistributedDatabaseService
Main service for managing tenant databases in distributed architecture.

**Features:**
- Creates tenant databases on current service's DB instance only
- Docker-aware connection handling
- Connection pooling and caching
- Automatic cleanup and error handling

### 2. DistributedTenantDatabaseMiddleware
Middleware for automatic tenant database switching.

**Features:**
- Extracts tenant identifier from requests
- Validates tenant and database existence
- Switches to tenant database for request processing
- Automatic connection cleanup after request
- Comprehensive error handling

### 3. DistributedDatabaseServiceProvider
Service provider for registering distributed services.

**Features:**
- Registers services as singletons
- Configures middleware aliases
- Publishes configuration files
- Initializes logging and monitoring

## Configuration

### Step 1: Update Environment Variables

Each service needs its own database configuration:

```env
# Service Configuration
SERVICE_NAME=identity-service  # or employee-service, core-service
DATABASE_ARCHITECTURE_MODE=distributed

# Database Configuration (Service-specific instance)
DB_CONNECTION=pgsql
DB_HOST=identity-db              # Service-specific DB host
DB_PORT=5432
DB_DATABASE=identity_central     # Service's central database
DB_USERNAME=postgres
DB_PASSWORD=your_secure_password

# Distributed Database Settings
DISTRIBUTED_DATABASE_ENABLED=true
DISTRIBUTED_CONNECTION_POOLING=true
DISTRIBUTED_MAX_CONNECTIONS=50
DISTRIBUTED_CONNECTION_TIMEOUT=30

# Docker Settings
DOCKER_ENABLED=true

# Service-specific DB Hosts (Optional - for cross-service communication)
IDENTITY_DB_HOST=identity-db
EMPLOYEE_DB_HOST=employee-db
CORE_DB_HOST=core-db

# Health Check
DB_HEALTH_CHECK_ENABLED=true
DB_HEALTH_CHECK_INTERVAL=60
DB_HEALTH_CHECK_TIMEOUT=5
```

### Step 2: Update Service Provider

In your service's `bootstrap/app.php` or `config/app.php`:

```php
->withProviders([
    // Remove old providers
    // \Shared\Providers\HybridDatabaseServiceProvider::class,
    
    // Add new distributed provider
    \Shared\Providers\DistributedDatabaseServiceProvider::class,
    
    // Other providers...
])
```

### Step 3: Update Middleware

In your routes, use the new distributed middleware:

```php
// OLD (Hybrid Architecture)
Route::middleware(['hybrid.tenant'])->group(function () {
    // Routes...
});

// NEW (Distributed Architecture)
Route::middleware(['distributed.tenant'])->group(function () {
    // Routes...
});
```

## Docker Compose Example

```yaml
version: '3.8'

services:
  # Identity Service
  identity-service:
    build: ./hrms-identity-service
    environment:
      - SERVICE_NAME=identity-service
      - DATABASE_ARCHITECTURE_MODE=distributed
      - DB_HOST=identity-db
      - DB_PORT=5432
      - DB_DATABASE=identity_central
      - DB_USERNAME=postgres
      - DB_PASSWORD=secret
    depends_on:
      - identity-db

  identity-db:
    image: postgres:15-alpine
    environment:
      - POSTGRES_DB=identity_central
      - POSTGRES_USER=postgres
      - POSTGRES_PASSWORD=secret
    volumes:
      - identity-db-data:/var/lib/postgresql/data

  # Employee Service
  employee-service:
    build: ./hrms-employee-service
    environment:
      - SERVICE_NAME=employee-service
      - DATABASE_ARCHITECTURE_MODE=distributed
      - DB_HOST=employee-db
      - DB_PORT=5432
      - DB_DATABASE=employee_central
      - DB_USERNAME=postgres
      - DB_PASSWORD=secret
    depends_on:
      - employee-db

  employee-db:
    image: postgres:15-alpine
    environment:
      - POSTGRES_DB=employee_central
      - POSTGRES_USER=postgres
      - POSTGRES_PASSWORD=secret
    volumes:
      - employee-db-data:/var/lib/postgresql/data

  # Core Service
  core-service:
    build: ./hrms-core-service
    environment:
      - SERVICE_NAME=core-service
      - DATABASE_ARCHITECTURE_MODE=distributed
      - DB_HOST=core-db
      - DB_PORT=5432
      - DB_DATABASE=core_central
      - DB_USERNAME=postgres
      - DB_PASSWORD=secret
    depends_on:
      - core-db

  core-db:
    image: postgres:15-alpine
    environment:
      - POSTGRES_DB=core_central
      - POSTGRES_USER=postgres
      - POSTGRES_PASSWORD=secret
    volumes:
      - core-db-data:/var/lib/postgresql/data

volumes:
  identity-db-data:
  employee-db-data:
  core-db-data:
```

## Usage Examples

### Creating a Tenant Database

```php
use Shared\Services\DistributedDatabaseService;

// In your tenant creation controller/service
$distributedDbService = app(DistributedDatabaseService::class);

$tenant = [
    'id' => 'acme-uuid-here',
    'name' => 'Acme Corporation',
    'domain' => 'acme.hrms.local',
    'is_active' => true,
    'settings' => [],
];

// This creates the tenant database ONLY on the current service's DB instance
$distributedDbService->createTenantDatabase($tenant);

// To create on other services, dispatch events
event(new TenantCreatedEvent($tenant));
```

### Switching to Tenant Database

```php
use Shared\Services\DistributedDatabaseService;

$distributedDbService = app(DistributedDatabaseService::class);

// Switch to tenant database
$distributedDbService->switchToTenantDatabase('acme-uuid-here');

// Perform database operations...
$employees = DB::table('employees')->get();

// Switch back to central database
$distributedDbService->switchToCentralDatabase();
```

### Using Middleware (Automatic)

```php
// In routes/api.php
Route::middleware(['distributed.tenant'])->group(function () {
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
});

// In controller - tenant context is automatically available
class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        // Middleware has already switched to tenant database
        $tenantId = $request->get('tenant_id');
        
        // Query tenant-specific data
        $employees = Employee::all();
        
        return response()->json($employees);
    }
}
```

## Event-Driven Tenant Provisioning

In distributed architecture, tenant databases are created via events:

```php
// In Identity Service (tenant creation)
use Shared\Events\TenantCreatedEvent;

$tenant = Tenant::create([
    'name' => 'Acme Corporation',
    'domain' => 'acme.hrms.local',
    // ...
]);

// Create tenant database on Identity Service
$distributedDbService->createTenantDatabase($tenant->toArray());

// Publish event for other services to create their databases
event(new TenantCreatedEvent(
    $tenant->id,
    $tenant->toArray(),
    auth()->id()
));
```

```php
// In Employee/Core Services (event listener)
use Shared\Events\TenantCreatedEvent;
use Shared\Services\DistributedDatabaseService;

class CreateTenantDatabaseListener
{
    public function handle(TenantCreatedEvent $event): void
    {
        $distributedDbService = app(DistributedDatabaseService::class);
        
        // Create tenant database on this service's DB instance
        $distributedDbService->createTenantDatabase([
            'id' => $event->tenantId,
            'name' => $event->payload['name'],
            'domain' => $event->payload['domain'],
            'is_active' => $event->payload['is_active'] ?? true,
            'settings' => $event->payload['settings'] ?? [],
        ]);
    }
}
```

## Migration from Hybrid to Distributed

### Step 1: Backup All Databases
```bash
# Backup all tenant databases
pg_dumpall > all_databases_backup.sql
```

### Step 2: Setup New Docker Infrastructure
```bash
# Start new distributed services
docker-compose up -d
```

### Step 3: Migrate Tenant Databases

For each service, export tenant databases and import to service-specific instance:

```bash
# Export from old instance
pg_dump -h old-db-host -U postgres tenant_uuid_identity > tenant_identity.sql
pg_dump -h old-db-host -U postgres tenant_uuid_employee > tenant_employee.sql
pg_dump -h old-db-host -U postgres tenant_uuid_core > tenant_core.sql

# Import to new service-specific instances
psql -h identity-db -U postgres tenant_uuid_identity < tenant_identity.sql
psql -h employee-db -U postgres tenant_uuid_employee < tenant_employee.sql
psql -h core-db -U postgres tenant_uuid_core < tenant_core.sql
```

### Step 4: Update Application Code
- Update environment variables
- Change service provider
- Update middleware usage
- Deploy services

### Step 5: Verify Migration
```bash
# Test tenant access on each service
curl -H "HRMS-Client-ID: tenant-uuid" http://identity-service/api/health
curl -H "HRMS-Client-ID: tenant-uuid" http://employee-service/api/health
curl -H "HRMS-Client-ID: tenant-uuid" http://core-service/api/health
```

## Best Practices

### 1. Connection Management
- Always use try-finally to ensure cleanup
- Let middleware handle connection switching
- Don't manually switch in controllers unless necessary

### 2. Error Handling
```php
try {
    $distributedDbService->switchToTenantDatabase($tenantId);
    
    // Perform operations...
    
} catch (Exception $e) {
    Log::error('Database operation failed', [
        'tenant_id' => $tenantId,
        'error' => $e->getMessage(),
    ]);
    
    throw $e;
    
} finally {
    // Always cleanup
    $distributedDbService->switchToCentralDatabase();
}
```

### 3. Performance Optimization
- Enable connection pooling (default: enabled)
- Use caching for tenant lookups
- Monitor connection pool size
- Cleanup old connections regularly

### 4. Monitoring
```php
// Get current connection info
$connectionInfo = $distributedDbService->getCurrentConnectionInfo();

Log::info('Database connection status', $connectionInfo);
```

### 5. Health Checks
```php
// In health check controller
public function databaseHealth()
{
    $distributedDbService = app(DistributedDatabaseService::class);
    
    try {
        $connectionInfo = $distributedDbService->getCurrentConnectionInfo();
        
        return response()->json([
            'status' => 'healthy',
            'database' => $connectionInfo,
        ]);
        
    } catch (Exception $e) {
        return response()->json([
            'status' => 'unhealthy',
            'error' => $e->getMessage(),
        ], 500);
    }
}
```

## Troubleshooting

### Issue: "Tenant database not found"
**Solution:** Ensure tenant database was created on this service's instance via event.

### Issue: "Connection failed"
**Solution:** Check DB_HOST points to correct service-specific database container.

### Issue: "Connected to wrong database"
**Solution:** Verify DATABASE_ARCHITECTURE_MODE=distributed in environment.

### Issue: Connection pool memory leak
**Solution:** Ensure middleware cleanup is working. Check logs for cleanup errors.

## Performance Considerations

### Connection Pooling
- Enabled by default
- Max 50 connections per service (configurable)
- 30-minute timeout for idle connections
- Automatic cleanup on middleware termination

### Caching
- Tenant lookups cached for 1 hour
- Cache cleared on tenant update/deletion
- Use Redis for production caching

### Database Optimization
- Each service has dedicated database instance
- No connection contention between services
- Better resource isolation
- Easier horizontal scaling

## Security

### Database Isolation
- Each service can only access its own DB instance
- Tenant data isolated per service
- No cross-service database access

### Connection Security
- SSL/TLS enabled (configurable)
- Credentials per service
- No shared database users

### Audit Logging
- All database switches logged
- Connection errors logged
- Tenant access logged

## Monitoring Queries

```sql
-- Check tenant databases on service instance
SELECT datname 
FROM pg_database 
WHERE datname LIKE 'tenant_%'
ORDER BY datname;

-- Check active connections
SELECT datname, count(*) 
FROM pg_stat_activity 
WHERE datname LIKE 'tenant_%'
GROUP BY datname;

-- Database sizes
SELECT datname, pg_size_pretty(pg_database_size(datname))
FROM pg_database 
WHERE datname LIKE 'tenant_%';
```

## Support

For questions or issues:
- Check logs: `storage/logs/laravel.log`
- Enable debug mode: `APP_DEBUG=true`
- Check database connectivity: `php artisan tinker` then `DB::connection()->getPdo()`
- Review this guide: `/docs/DISTRIBUTED_ARCHITECTURE_GUIDE.md`

