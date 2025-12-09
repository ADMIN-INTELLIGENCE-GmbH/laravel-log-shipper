# Laravel Log Shipper

[![Tests](https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/actions/workflows/tests.yml/badge.svg)](https://github.com/ADMIN-INTELLIGENCE-GmbH/laravel-log-shipper/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/adminintelligence/laravel-log-shipper.svg)](https://packagist.org/packages/adminintelligence/laravel-log-shipper)
[![License](https://img.shields.io/packagist/l/adminintelligence/laravel-log-shipper.svg)](https://packagist.org/packages/adminintelligence/laravel-log-shipper)

A Laravel package that ships your application logs to a central server.

## Requirements

- PHP 8.1 or higher
- Laravel 10, 11, or 12
- A queue driver (recommended: Redis, database, or SQS)

## Installation

```bash
composer require adminintelligence/laravel-log-shipper
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --tag=log-shipper-config
```

Add the following to your `.env` file:

```
LOG_SHIPPER_ENABLED=true
LOG_SHIPPER_ENDPOINT=https://your-log-server.com/api/logs
LOG_SHIPPER_KEY=your-project-api-key
LOG_SHIPPER_QUEUE=redis
LOG_SHIPPER_QUEUE_NAME=logs
```

## Usage

Add the log shipper channel to your `config/logging.php`:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'log_shipper'],
        'ignore_exceptions' => false,
    ],

    'log_shipper' => [
        'driver' => 'custom',
        'via' => \AdminIntelligence\LogShipper\Logging\CreateCustomLogger::class,
        'level' => 'error', // Only ship errors and above
    ],
    
    // ... other channels
],
```

Now any log at the configured level or above will be shipped to your central server:

```php
Log::error('Something went wrong', ['order_id' => 123]);
```

## Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `enabled` | Enable/disable log shipping | `true` |
| `api_endpoint` | The URL of your log server | - |
| `api_key` | Your project's API key | - |
| `queue_connection` | Queue connection to use | `default` |
| `queue_name` | Queue name for log jobs | `default` |
| `sanitize_fields` | Fields to redact from logs | See config |
| `send_context` | Context data to include | See config |

## Data Sanitization

The package automatically redacts sensitive fields from your logs. Configure which fields to sanitize in the config file.

## Sync Mode

By default, logs are shipped via queued jobs for better performance. If you prefer synchronous shipping (useful for debugging or simple setups), set:

```env
LOG_SHIPPER_QUEUE=sync
```

> **Note:** Sync mode will block your application until the HTTP request completes. Use queued mode in production for better performance.

## Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover a security vulnerability, please see [SECURITY.md](SECURITY.md) for reporting instructions.

## License

The MIT License (MIT). Please see [LICENSE](LICENSE) for more information.
