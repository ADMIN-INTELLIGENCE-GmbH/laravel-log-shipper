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
    | Status Push Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the automatic status pushing to your log server.
    |
    */
    'status' => [
        'enabled' => env('LOG_SHIPPER_STATUS_ENABLED', false),
        
        // The endpoint to send status to. If null, we'll try to guess it 
        // based on the log endpoint (replacing the path with /stats).
        'endpoint' => env('LOG_SHIPPER_STATUS_ENDPOINT', null), 
        
        'interval' => env('LOG_SHIPPER_STATUS_INTERVAL', 300), // 5 minutes
        
        'queue_connection' => env('LOG_SHIPPER_STATUS_QUEUE', 'default'),
        'queue_name' => env('LOG_SHIPPER_STATUS_QUEUE_NAME', 'default'),
        
        'metrics' => [
            'system' => true,
            'queue' => true,
            'database' => true,
            'cache' => true,
            'filesize' => true,
        ],

        'monitored_files' => [
            storage_path('logs/laravel.log'),
        ],
    ],
];
