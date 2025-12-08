# Laravel Log Shipper

A Laravel package that ships your application logs to a central server. Because sometimes you need your problems to be someone else's problems too.

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

```env
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

## License

MIT
