# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.1] - 2025-11-06

### Fixed
- Fixed tenant database naming convention to properly sanitize UUIDs (hyphens to underscores)
- Updated DistributedDatabaseService to sanitize tenant IDs in database names
- Updated DistributedTenantDatabaseMiddleware to match production database naming
- Updated SecurityAuditService to use sanitized tenant IDs for database checks
- Updated TenantAwareModel to properly sanitize tenant IDs in connection configuration
- Ensures compatibility with PostgreSQL database naming requirements in production

## [1.1.0] - 2025-11-05

### Added
- TenantApiClient for HTTP-based tenant information retrieval
- SecurityEventApiClient for centralized security event logging
- Interface abstractions (TenantServiceInterface, SecurityEventServiceInterface, ApiKeyServiceInterface)
- Circuit breaker pattern for resilience
- Multi-layer caching with fallback mechanisms
- Internal service authentication support

### Changed
- Updated ApiKeyService to use internal API endpoint instead of direct database access
- Modified UnifiedAuthenticationMiddleware to use TenantApiClient for tenant lookups
- Updated SecurityService to use SecurityEventApiClient for event logging
- Updated DistributedDatabaseService to use TenantApiClient
- Updated DistributedTenantDatabaseMiddleware for improved tenant database handling

### Fixed
- Removed all direct database queries from shared services (microservices best practice)
- Removed cross-service Eloquent relationship from AuditLog model
- Fixed ApiKeyAuthenticationMiddleware to not update timestamps directly

### Removed
- Direct database access from UnifiedAuthenticationMiddleware (5 queries removed)
- Direct database access from SecurityService
- Cross-service Eloquent relationships

## [1.0.0] - 2025-01-20

### Added
- Initial release of HRMS Shared Components Package
- Comprehensive middleware suite for security and authentication
- Hybrid database service for multi-tenant architecture
- Event-driven communication system
- Base classes and traits for consistent development
- Complete audit logging and monitoring capabilities

### Features
- **Security**: Enterprise-grade security headers and input validation
- **Authentication**: Unified OAuth2 and API key authentication
- **Multi-tenancy**: Database-per-service + Database-per-tenant architecture
- **Events**: Redis-based event bus for microservice communication
- **Monitoring**: Comprehensive logging and performance monitoring
