<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Event Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default event driver that will be used for
    | publishing events. You may set this to any of the drivers defined
    | in the "drivers" array below.
    |
    | Supported: "sqs", "webhook", "both"
    |
    */

    'driver' => env('EVENTS_DRIVER', 'sqs'),

    /*
    |--------------------------------------------------------------------------
    | Event Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the event drivers for your application. Each
    | driver has its own configuration options.
    |
    */

    'drivers' => [
        'sqs' => [
            'region' => env('AWS_REGION', 'us-east-1'),
            'key' => env('AWS_KEY'),
            'secret' => env('AWS_SECRET'),
            'account_id' => env('AWS_ACCOUNT_ID'),
            'queue_prefix' => env('SQS_QUEUE_PREFIX', 'hrms'),
        ],

        'webhook' => [
            'timeout' => env('WEBHOOK_TIMEOUT', 30),
            'retry_attempts' => env('WEBHOOK_RETRY_ATTEMPTS', 3),
            'retry_delay' => env('WEBHOOK_RETRY_DELAY', 1000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SQS Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for SQS queues used by different services.
    |
    */

    'queues' => [
        'core' => env('SQS_CORE_QUEUE', 'hrms-core-events.fifo'),
        'employee' => env('SQS_EMPLOYEE_QUEUE', 'hrms-employee-events.fifo'),
        'identity' => env('SQS_IDENTITY_QUEUE', 'hrms-identity-events.fifo'),
        'dlq' => env('SQS_DLQ_QUEUE', 'hrms-dlq.fifo'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Attributes
    |--------------------------------------------------------------------------
    |
    | Default attributes for SQS queues.
    |
    */

    'queue_attributes' => [
        'FifoQueue' => 'true',
        'ContentBasedDeduplication' => 'true',
        'VisibilityTimeoutSeconds' => env('SQS_VISIBILITY_TIMEOUT', 30),
        'MessageRetentionPeriod' => env('SQS_MESSAGE_RETENTION', 1209600), // 14 days
        'ReceiveMessageWaitTimeSeconds' => env('SQS_WAIT_TIME', 20),
        'RedrivePolicy' => [
            'deadLetterTargetArn' => 'arn:aws:sqs:' . env('AWS_REGION', 'us-east-1') . ':' . env('AWS_ACCOUNT_ID') . ':' . env('SQS_DLQ_QUEUE', 'hrms-dlq.fifo'),
            'maxReceiveCount' => env('SQS_MAX_RECEIVE_COUNT', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Processing
    |--------------------------------------------------------------------------
    |
    | Configuration for event processing and retry logic.
    |
    */

    'processing' => [
        'max_retries' => env('EVENT_MAX_RETRIES', 3),
        'retry_delay' => env('EVENT_RETRY_DELAY', 1000),
        'batch_size' => env('EVENT_BATCH_SIZE', 10),
        'visibility_timeout' => env('EVENT_VISIBILITY_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Events
    |--------------------------------------------------------------------------
    |
    | Configuration for handling failed events.
    |
    */

    'failed_events' => [
        'table' => 'failed_events',
        'retention_days' => env('FAILED_EVENTS_RETENTION_DAYS', 30),
        'cleanup_frequency' => env('FAILED_EVENTS_CLEANUP_FREQUENCY', 'daily'),
    ],
];
