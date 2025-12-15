<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable Log Shipping
    |--------------------------------------------------------------------------
    |
    | Toggle this to false if you want the package to do absolutely nothing.
    | Which, honestly, might be for the best sometimes.
    |
    */
    'enabled' => env('LOG_SHIPPER_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Central Server Endpoint
    |--------------------------------------------------------------------------
    |
    | The URL where your logs will be sent to meet their fate.
    |
    */
    'api_endpoint' => env('LOG_SHIPPER_ENDPOINT', ''),

    /*
    |--------------------------------------------------------------------------
    | API Key (Project Identifier)
    |--------------------------------------------------------------------------
    |
    | The magic key that identifies this project. Guard it well.
    |
    */
    'api_key' => env('LOG_SHIPPER_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Connection
    |--------------------------------------------------------------------------
    |
    | Which queue connection to use for shipping logs.
    | Use 'sync' if you want to feel the pain immediately.
    |
    */
    'queue_connection' => env('LOG_SHIPPER_QUEUE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Queue Name
    |--------------------------------------------------------------------------
    |
    | The specific queue name to dispatch jobs to.
    |
    */
    'queue_name' => env('LOG_SHIPPER_QUEUE_NAME', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Job Retries & Backoff
    |--------------------------------------------------------------------------
    |
    | Configure how many times the job should be attempted and the wait time
    | between attempts.
    |
    */
    'retries' => 3,
    'backoff' => [2, 5, 10], // Seconds to wait between retries

    /*
    |--------------------------------------------------------------------------
    | Fallback Channel
    |--------------------------------------------------------------------------
    |
    | If shipping fails, logs will be written to this local channel.
    | Set to null to disable fallback logging.
    |
    */
    'fallback_channel' => env('LOG_SHIPPER_FALLBACK', null),

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Prevent the application from repeatedly trying to ship logs when the
    | log server is down.
    |
    */
    'circuit_breaker' => [
        'enabled' => true,
        'failure_threshold' => 5, // Number of failures before opening the circuit
        'duration' => 300, // Seconds to keep the circuit open (5 minutes)
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Shipping
    |--------------------------------------------------------------------------
    |
    | Buffer logs and ship them in batches to reduce queue pressure.
    | Requires a Redis connection.
    |
    */
    'batch' => [
        'enabled' => env('LOG_SHIPPER_BATCH_ENABLED', false),
        'driver' => env('LOG_SHIPPER_BATCH_DRIVER', 'redis'), // 'redis' or 'cache'
        'size' => env('LOG_SHIPPER_BATCH_SIZE', 100), // Number of logs to ship at once
        'buffer_key' => env('LOG_SHIPPER_BATCH_KEY', 'log_shipper_buffer'),
        'connection' => env('LOG_SHIPPER_BATCH_CONNECTION', 'default'), // Redis connection or Cache store
        'interval' => env('LOG_SHIPPER_BATCH_INTERVAL', 1), // Minutes between batch runs
    ],

    /*
    |--------------------------------------------------------------------------
    | Fields to Sanitize
    |--------------------------------------------------------------------------
    |
    | These field names will be replaced with [REDACTED] to protect
    | sensitive data. Add more as needed.
    |
    */
    'sanitize_fields' => [
        'password',
        'password_confirmation',
        'credit_card',
        'card_number',
        'cvv',
        'api_key',
        'secret',
        'token',
        'authorization',
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Data to Include
    |--------------------------------------------------------------------------
    |
    | Toggle which contextual data should be attached to each log entry.
    |
    */
    'send_context' => [
        'user_id' => true,
        'ip_address' => true,
        'user_agent' => true,
        'route_name' => true,
        'controller_action' => true,
        'request_method' => true,
        'request_url' => true,
        'app_env' => true,
        'app_debug' => true,
        'referrer' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | IP Address Obfuscation
    |--------------------------------------------------------------------------
    |
    | Obfuscate IP addresses in logs for privacy protection.
    |
    */
    'ip_obfuscation' => [
        'enabled' => env('LOG_SHIPPER_IP_OBFUSCATION_ENABLED', false),
        
        // 'mask' - Mask last octets (e.g., 192.168.1.100 becomes 192.168.1.0)
        // 'hash' - One-way hash for privacy while maintaining consistency
        'method' => env('LOG_SHIPPER_IP_OBFUSCATION_METHOD', 'mask'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Status Push Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the automatic status pushing to your log server.
    |
    */
    'status' => [
        'enabled' => env('LOG_SHIPPER_STATUS_ENABLED', false),

        // The endpoint to send status to.
        'endpoint' => env('LOG_SHIPPER_STATUS_ENDPOINT', null),

        'interval' => env('LOG_SHIPPER_STATUS_INTERVAL', 5), // minutes

        'queue_connection' => env('LOG_SHIPPER_STATUS_QUEUE', 'default'),
        'queue_name' => env('LOG_SHIPPER_STATUS_QUEUE_NAME', 'default'),

        'metrics' => [
            'system' => true,
            'queue' => true,
            'database' => true,
            'cache' => true,
            'filesize' => true,
            'foldersize' => true,
            // Optional: Node.js and npm versions (requires node/npm installed)
            'node_npm' => true,
            // Optional: Check for outdated dependencies (can be slow, ~10-30s)
            'dependency_checks' => true,
            // Optional: Security audit checks (can be slow, ~10-30s)
            'security_audits' => true,
        ],

        'monitored_disk_path' => env('LOG_SHIPPER_DISK_PATH', '/'),

        'monitored_files' => [
            storage_path('logs/laravel.log'),
        ],

        'monitored_folders' => [
            // storage_path('app/uploads'),
            // storage_path('framework/cache'),
        ],
    ],
];
