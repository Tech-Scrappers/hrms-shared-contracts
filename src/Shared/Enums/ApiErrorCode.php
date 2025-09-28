<?php

namespace Shared\Enums;

/**
 * Centralized API Error Code Registry
 * 
 * This enum provides a centralized registry for all API error codes
 * across the HRMS microservices system, ensuring consistency and
 * maintainability.
 */
enum ApiErrorCode: string
{
    // Authentication & Authorization Errors (1000-1999)
    case AUTHENTICATION_REQUIRED = 'AUTHENTICATION_REQUIRED';
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    case TOKEN_EXPIRED = 'TOKEN_EXPIRED';
    case TOKEN_INVALID = 'TOKEN_INVALID';
    case INSUFFICIENT_PERMISSIONS = 'INSUFFICIENT_PERMISSIONS';
    case ACCOUNT_LOCKED = 'ACCOUNT_LOCKED';
    case ACCOUNT_DISABLED = 'ACCOUNT_DISABLED';
    case INVALID_API_KEY = 'INVALID_API_KEY';
    case API_KEY_EXPIRED = 'API_KEY_EXPIRED';
    case API_KEY_REVOKED = 'API_KEY_REVOKED';
    case INVALID_SCOPE = 'INVALID_SCOPE';
    case MFA_REQUIRED = 'MFA_REQUIRED';
    case MFA_INVALID = 'MFA_INVALID';

    // Validation Errors (2000-2999)
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case REQUIRED_FIELD_MISSING = 'REQUIRED_FIELD_MISSING';
    case INVALID_FORMAT = 'INVALID_FORMAT';
    case INVALID_EMAIL = 'INVALID_EMAIL';
    case INVALID_PHONE = 'INVALID_PHONE';
    case INVALID_DATE = 'INVALID_DATE';
    case INVALID_UUID = 'INVALID_UUID';
    case VALUE_TOO_LONG = 'VALUE_TOO_LONG';
    case VALUE_TOO_SHORT = 'VALUE_TOO_SHORT';
    case VALUE_OUT_OF_RANGE = 'VALUE_OUT_OF_RANGE';
    case INVALID_ENUM_VALUE = 'INVALID_ENUM_VALUE';
    case DUPLICATE_VALUE = 'DUPLICATE_VALUE';

    // Resource Errors (3000-3999)
    case RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    case RESOURCE_ALREADY_EXISTS = 'RESOURCE_ALREADY_EXISTS';
    case RESOURCE_CONFLICT = 'RESOURCE_CONFLICT';
    case RESOURCE_LOCKED = 'RESOURCE_LOCKED';
    case RESOURCE_DELETED = 'RESOURCE_DELETED';
    case RESOURCE_UNAVAILABLE = 'RESOURCE_UNAVAILABLE';
    case INVALID_RESOURCE_ID = 'INVALID_RESOURCE_ID';
    case RESOURCE_ACCESS_DENIED = 'RESOURCE_ACCESS_DENIED';

    // Business Logic Errors (4000-4999)
    case BUSINESS_RULE_VIOLATION = 'BUSINESS_RULE_VIOLATION';
    case OPERATION_NOT_ALLOWED = 'OPERATION_NOT_ALLOWED';
    case INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE';
    case QUOTA_EXCEEDED = 'QUOTA_EXCEEDED';
    case RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    case CONCURRENT_MODIFICATION = 'CONCURRENT_MODIFICATION';
    case DEPENDENCY_CONFLICT = 'DEPENDENCY_CONFLICT';
    case WORKFLOW_VIOLATION = 'WORKFLOW_VIOLATION';
    case ATTENDANCE_ALREADY_RECORDED = 'ATTENDANCE_ALREADY_RECORDED';
    case ATTENDANCE_NOT_FOUND = 'ATTENDANCE_NOT_FOUND';
    case LEAVE_BALANCE_INSUFFICIENT = 'LEAVE_BALANCE_INSUFFICIENT';
    case LEAVE_REQUEST_CONFLICT = 'LEAVE_REQUEST_CONFLICT';

    // System Errors (5000-5999)
    case INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    case DATABASE_ERROR = 'DATABASE_ERROR';
    case CACHE_ERROR = 'CACHE_ERROR';
    case EXTERNAL_SERVICE_ERROR = 'EXTERNAL_SERVICE_ERROR';
    case CONFIGURATION_ERROR = 'CONFIGURATION_ERROR';
    case NETWORK_ERROR = 'NETWORK_ERROR';
    case TIMEOUT_ERROR = 'TIMEOUT_ERROR';
    case MAINTENANCE_MODE = 'MAINTENANCE_MODE';

    // Tenant & Multi-tenancy Errors (6000-6999)
    case TENANT_NOT_FOUND = 'TENANT_NOT_FOUND';
    case TENANT_DISABLED = 'TENANT_DISABLED';
    case TENANT_QUOTA_EXCEEDED = 'TENANT_QUOTA_EXCEEDED';
    case INVALID_TENANT_CONTEXT = 'INVALID_TENANT_CONTEXT';
    case TENANT_DATABASE_ERROR = 'TENANT_DATABASE_ERROR';
    case CROSS_TENANT_ACCESS_DENIED = 'CROSS_TENANT_ACCESS_DENIED';

    // File & Upload Errors (7000-7999)
    case FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    case INVALID_FILE_TYPE = 'INVALID_FILE_TYPE';
    case FILE_CORRUPTED = 'FILE_CORRUPTED';
    case UPLOAD_FAILED = 'UPLOAD_FAILED';
    case FILE_NOT_FOUND = 'FILE_NOT_FOUND';
    case VIRUS_DETECTED = 'VIRUS_DETECTED';

    // Request Errors (8000-8999)
    case BAD_REQUEST = 'BAD_REQUEST';
    case INVALID_JSON = 'INVALID_JSON';
    case MISSING_HEADER = 'MISSING_HEADER';
    case INVALID_HEADER = 'INVALID_HEADER';
    case CONTENT_TYPE_NOT_SUPPORTED = 'CONTENT_TYPE_NOT_SUPPORTED';
    case REQUEST_TOO_LARGE = 'REQUEST_TOO_LARGE';
    case UNSUPPORTED_MEDIA_TYPE = 'UNSUPPORTED_MEDIA_TYPE';

    /**
     * Get the HTTP status code for this error
     */
    public function getHttpStatusCode(): int
    {
        return match ($this) {
            // 4xx Client Errors
            self::AUTHENTICATION_REQUIRED,
            self::INVALID_CREDENTIALS,
            self::TOKEN_EXPIRED,
            self::TOKEN_INVALID,
            self::MFA_REQUIRED,
            self::MFA_INVALID => 401,

            self::INSUFFICIENT_PERMISSIONS,
            self::ACCOUNT_LOCKED,
            self::ACCOUNT_DISABLED,
            self::INVALID_API_KEY,
            self::API_KEY_EXPIRED,
            self::API_KEY_REVOKED,
            self::INVALID_SCOPE,
            self::RESOURCE_ACCESS_DENIED,
            self::CROSS_TENANT_ACCESS_DENIED => 403,

            self::RESOURCE_NOT_FOUND,
            self::INVALID_RESOURCE_ID,
            self::FILE_NOT_FOUND => 404,

            self::RESOURCE_CONFLICT,
            self::RESOURCE_ALREADY_EXISTS,
            self::DUPLICATE_VALUE,
            self::LEAVE_REQUEST_CONFLICT,
            self::CONCURRENT_MODIFICATION,
            self::DEPENDENCY_CONFLICT => 409,

            self::VALIDATION_FAILED,
            self::REQUIRED_FIELD_MISSING,
            self::INVALID_FORMAT,
            self::INVALID_EMAIL,
            self::INVALID_PHONE,
            self::INVALID_DATE,
            self::INVALID_UUID,
            self::VALUE_TOO_LONG,
            self::VALUE_TOO_SHORT,
            self::VALUE_OUT_OF_RANGE,
            self::INVALID_ENUM_VALUE => 422,

            self::RATE_LIMIT_EXCEEDED => 429,

            self::BAD_REQUEST,
            self::INVALID_JSON,
            self::MISSING_HEADER,
            self::INVALID_HEADER,
            self::CONTENT_TYPE_NOT_SUPPORTED,
            self::UNSUPPORTED_MEDIA_TYPE,
            self::REQUEST_TOO_LARGE,
            self::FILE_TOO_LARGE,
            self::INVALID_FILE_TYPE => 400,

            // 5xx Server Errors
            self::INTERNAL_SERVER_ERROR,
            self::DATABASE_ERROR,
            self::CACHE_ERROR,
            self::CONFIGURATION_ERROR,
            self::TENANT_DATABASE_ERROR => 500,

            self::SERVICE_UNAVAILABLE,
            self::EXTERNAL_SERVICE_ERROR,
            self::NETWORK_ERROR,
            self::TIMEOUT_ERROR,
            self::MAINTENANCE_MODE => 503,

            // Default to 400 for business logic errors
            default => 400,
        };
    }

    /**
     * Get the error type category
     */
    public function getErrorType(): string
    {
        return match ($this) {
            self::AUTHENTICATION_REQUIRED,
            self::INVALID_CREDENTIALS,
            self::TOKEN_EXPIRED,
            self::TOKEN_INVALID,
            self::MFA_REQUIRED,
            self::MFA_INVALID => 'authentication_error',

            self::INSUFFICIENT_PERMISSIONS,
            self::ACCOUNT_LOCKED,
            self::ACCOUNT_DISABLED,
            self::INVALID_API_KEY,
            self::API_KEY_EXPIRED,
            self::API_KEY_REVOKED,
            self::INVALID_SCOPE,
            self::RESOURCE_ACCESS_DENIED,
            self::CROSS_TENANT_ACCESS_DENIED => 'authorization_error',

            self::VALIDATION_FAILED,
            self::REQUIRED_FIELD_MISSING,
            self::INVALID_FORMAT,
            self::INVALID_EMAIL,
            self::INVALID_PHONE,
            self::INVALID_DATE,
            self::INVALID_UUID,
            self::VALUE_TOO_LONG,
            self::VALUE_TOO_SHORT,
            self::VALUE_OUT_OF_RANGE,
            self::INVALID_ENUM_VALUE,
            self::DUPLICATE_VALUE => 'validation_error',

            self::RESOURCE_NOT_FOUND,
            self::RESOURCE_ALREADY_EXISTS,
            self::RESOURCE_CONFLICT,
            self::RESOURCE_LOCKED,
            self::RESOURCE_DELETED,
            self::RESOURCE_UNAVAILABLE,
            self::INVALID_RESOURCE_ID,
            self::FILE_NOT_FOUND => 'resource_error',

            self::BUSINESS_RULE_VIOLATION,
            self::OPERATION_NOT_ALLOWED,
            self::INSUFFICIENT_BALANCE,
            self::QUOTA_EXCEEDED,
            self::CONCURRENT_MODIFICATION,
            self::DEPENDENCY_CONFLICT,
            self::WORKFLOW_VIOLATION,
            self::ATTENDANCE_ALREADY_RECORDED,
            self::ATTENDANCE_NOT_FOUND,
            self::LEAVE_BALANCE_INSUFFICIENT,
            self::LEAVE_REQUEST_CONFLICT => 'business_error',

            self::RATE_LIMIT_EXCEEDED => 'rate_limit_error',

            self::INTERNAL_SERVER_ERROR,
            self::SERVICE_UNAVAILABLE,
            self::DATABASE_ERROR,
            self::CACHE_ERROR,
            self::EXTERNAL_SERVICE_ERROR,
            self::CONFIGURATION_ERROR,
            self::NETWORK_ERROR,
            self::TIMEOUT_ERROR,
            self::MAINTENANCE_MODE,
            self::TENANT_DATABASE_ERROR => 'server_error',

            self::TENANT_NOT_FOUND,
            self::TENANT_DISABLED,
            self::TENANT_QUOTA_EXCEEDED,
            self::INVALID_TENANT_CONTEXT => 'tenant_error',

            self::FILE_TOO_LARGE,
            self::INVALID_FILE_TYPE,
            self::FILE_CORRUPTED,
            self::UPLOAD_FAILED,
            self::VIRUS_DETECTED => 'file_error',

            self::BAD_REQUEST,
            self::INVALID_JSON,
            self::MISSING_HEADER,
            self::INVALID_HEADER,
            self::CONTENT_TYPE_NOT_SUPPORTED,
            self::REQUEST_TOO_LARGE,
            self::UNSUPPORTED_MEDIA_TYPE => 'client_error',

            default => 'unknown_error',
        };
    }

    /**
     * Get a human-readable description of the error
     */
    public function getDescription(): string
    {
        return match ($this) {
            self::AUTHENTICATION_REQUIRED => 'Valid authentication credentials are required',
            self::INVALID_CREDENTIALS => 'The provided credentials are invalid',
            self::TOKEN_EXPIRED => 'The authentication token has expired',
            self::TOKEN_INVALID => 'The authentication token is invalid or malformed',
            self::INSUFFICIENT_PERMISSIONS => 'You do not have permission to access this resource',
            self::ACCOUNT_LOCKED => 'Your account has been locked due to multiple failed login attempts',
            self::ACCOUNT_DISABLED => 'Your account has been disabled',
            self::INVALID_API_KEY => 'The provided API key is invalid',
            self::API_KEY_EXPIRED => 'The API key has expired',
            self::API_KEY_REVOKED => 'The API key has been revoked',
            self::INVALID_SCOPE => 'The requested scope is not authorized',
            self::MFA_REQUIRED => 'Multi-factor authentication is required',
            self::MFA_INVALID => 'The multi-factor authentication code is invalid',

            self::VALIDATION_FAILED => 'The request contains validation errors',
            self::REQUIRED_FIELD_MISSING => 'A required field is missing',
            self::INVALID_FORMAT => 'The field format is invalid',
            self::INVALID_EMAIL => 'The email address format is invalid',
            self::INVALID_PHONE => 'The phone number format is invalid',
            self::INVALID_DATE => 'The date format is invalid',
            self::INVALID_UUID => 'The UUID format is invalid',
            self::VALUE_TOO_LONG => 'The value exceeds the maximum length',
            self::VALUE_TOO_SHORT => 'The value is below the minimum length',
            self::VALUE_OUT_OF_RANGE => 'The value is outside the allowed range',
            self::INVALID_ENUM_VALUE => 'The value is not a valid option',
            self::DUPLICATE_VALUE => 'The value already exists and must be unique',

            self::RESOURCE_NOT_FOUND => 'The requested resource could not be found',
            self::RESOURCE_ALREADY_EXISTS => 'A resource with this identifier already exists',
            self::RESOURCE_CONFLICT => 'The request conflicts with the current state of the resource',
            self::RESOURCE_LOCKED => 'The resource is currently locked and cannot be modified',
            self::RESOURCE_DELETED => 'The resource has been deleted',
            self::RESOURCE_UNAVAILABLE => 'The resource is temporarily unavailable',
            self::INVALID_RESOURCE_ID => 'The resource ID format is invalid',
            self::RESOURCE_ACCESS_DENIED => 'Access to this resource is denied',
            self::FILE_NOT_FOUND => 'The requested file could not be found',

            self::BUSINESS_RULE_VIOLATION => 'The operation violates a business rule',
            self::OPERATION_NOT_ALLOWED => 'This operation is not allowed in the current context',
            self::INSUFFICIENT_BALANCE => 'Insufficient balance to complete the operation',
            self::QUOTA_EXCEEDED => 'The operation would exceed the allowed quota',
            self::CONCURRENT_MODIFICATION => 'The resource was modified by another process',
            self::DEPENDENCY_CONFLICT => 'The operation conflicts with a dependency',
            self::WORKFLOW_VIOLATION => 'The operation violates the workflow rules',
            self::ATTENDANCE_ALREADY_RECORDED => 'Attendance has already been recorded for this period',
            self::ATTENDANCE_NOT_FOUND => 'No attendance record found for the specified criteria',
            self::LEAVE_BALANCE_INSUFFICIENT => 'Insufficient leave balance for this request',
            self::LEAVE_REQUEST_CONFLICT => 'This leave request conflicts with existing requests',

            self::RATE_LIMIT_EXCEEDED => 'Too many requests. Please try again later',

            self::INTERNAL_SERVER_ERROR => 'An unexpected error occurred. Please try again later',
            self::SERVICE_UNAVAILABLE => 'The service is temporarily unavailable',
            self::DATABASE_ERROR => 'A database error occurred',
            self::CACHE_ERROR => 'A cache error occurred',
            self::EXTERNAL_SERVICE_ERROR => 'An external service error occurred',
            self::CONFIGURATION_ERROR => 'A configuration error occurred',
            self::NETWORK_ERROR => 'A network error occurred',
            self::TIMEOUT_ERROR => 'The request timed out',
            self::MAINTENANCE_MODE => 'The service is in maintenance mode',
            self::TENANT_DATABASE_ERROR => 'A tenant database error occurred',

            self::TENANT_NOT_FOUND => 'The specified tenant could not be found',
            self::TENANT_DISABLED => 'The tenant has been disabled',
            self::TENANT_QUOTA_EXCEEDED => 'The tenant has exceeded its quota',
            self::INVALID_TENANT_CONTEXT => 'The tenant context is invalid or missing',
            self::CROSS_TENANT_ACCESS_DENIED => 'Cross-tenant access is not allowed',

            self::FILE_TOO_LARGE => 'The file size exceeds the maximum allowed limit',
            self::INVALID_FILE_TYPE => 'The file type is not supported',
            self::FILE_CORRUPTED => 'The file appears to be corrupted',
            self::UPLOAD_FAILED => 'The file upload failed',
            self::VIRUS_DETECTED => 'A virus was detected in the uploaded file',

            self::BAD_REQUEST => 'The request could not be understood due to malformed syntax',
            self::INVALID_JSON => 'The request body contains invalid JSON',
            self::MISSING_HEADER => 'A required header is missing',
            self::INVALID_HEADER => 'A header value is invalid',
            self::CONTENT_TYPE_NOT_SUPPORTED => 'The content type is not supported',
            self::REQUEST_TOO_LARGE => 'The request size exceeds the maximum allowed limit',
            self::UNSUPPORTED_MEDIA_TYPE => 'The media type is not supported',

            default => 'An unknown error occurred',
        };
    }

    /**
     * Get all error codes for a specific category
     */
    public static function getByCategory(string $category): array
    {
        return array_filter(
            self::cases(),
            fn(self $code) => $code->getErrorType() === $category
        );
    }

    /**
     * Get error codes by HTTP status code
     */
    public static function getByHttpStatusCode(int $statusCode): array
    {
        return array_filter(
            self::cases(),
            fn(self $code) => $code->getHttpStatusCode() === $statusCode
        );
    }
}
