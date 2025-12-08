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

## Log Payload

When a log event is shipped, the following data is sent:

### Core Log Data (Always Sent)

| Field | Type | Description |
|-------|------|-------------|
| `level` | string | Log level (error, warning, info, debug, etc.) |
| `message` | string | The log message |
| `context` | array | Any context array passed to the log call |
| `datetime` | string | Timestamp in `Y-m-d H:i:s.u` format |
| `channel` | string | The logging channel name |
| `extra` | array | Additional Monolog processor data |

### Optional Context Data

These fields can be toggled via the `send_context` configuration:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `user_id` | int\|null | enabled | Authenticated user's ID |
| `ip_address` | string\|null | enabled | Client IP address |
| `user_agent` | string\|null | enabled | Browser/client user agent string |
| `request_method` | string\|null | enabled | HTTP method (GET, POST, PUT, DELETE, etc.) |
| `request_url` | string\|null | enabled | Full request URL including query string |
| `route_name` | string\|null | enabled | Laravel route name |
| `controller_action` | string\|null | enabled | Controller and action handling the request |
| `app_env` | string\|null | enabled | Application environment (local, staging, production) |
| `app_debug` | bool\|null | enabled | Whether debug mode is enabled |
| `referrer` | string\|null | enabled | HTTP Referer header |

To disable specific context fields, update your `config/log-shipper.php`:

```php
'send_context' => [
    'user_id' => true,
    'ip_address' => true,
    'user_agent' => false, // disabled
    // ...
],
```

## Data Sanitization

The package automatically redacts sensitive fields from your logs. The following field patterns are redacted by default:

| Field Pattern | Examples |
|---------------|----------|
| `password` | password, user_password, password_hash |
| `password_confirmation` | password_confirmation |
| `credit_card` | credit_card, credit_card_number |
| `card_number` | card_number |
| `cvv` | cvv, card_cvv |
| `api_key` | api_key, stripe_api_key |
| `secret` | secret, client_secret |
| `token` | token, access_token, refresh_token |
| `authorization` | authorization |

Add additional fields to sanitize in the config file:

```php
'sanitize_fields' => [
    'password',
    'credit_card',
    'ssn', // add your own
    // ...
],
```

## License

MIT
