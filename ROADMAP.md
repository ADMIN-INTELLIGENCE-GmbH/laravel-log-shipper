# Roadmap

## Batch Shipping
Currently, every log event dispatches a queue job. For high-traffic applications, this can create significant queue pressure.
- **Goal**: Implement a buffering mechanism.
- **Implementation**: Push logs to a Redis list or cache key. Use a scheduled command to pop a batch of logs (e.g., 100) and ship them in a single HTTP request.

## Circuit Breaker
Prevent the application from repeatedly trying to ship logs when the log server is down.
- **Goal**: Stop shipping logs temporarily after repeated failures.
- **Implementation**: If `ShipLogJob` fails X times consecutively, set a cache key (e.g., `log_shipper_dead_until`) for a set duration (e.g., 5 minutes). The handler will check this key and skip dispatching jobs while it exists.

## Fallback Channel
Ensure logs are not lost if the log shipper fails.
- **Goal**: Write logs to a local channel if shipping fails.
- **Implementation**: Allow defining a `fallback_channel` (e.g., `daily`) in the configuration. If the HTTP request in `ShipLogJob` fails, write the payload to the fallback channel.
