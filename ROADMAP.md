# Roadmap

## Batch Shipping
Currently, every log event dispatches a queue job. For high-traffic applications, this can create significant queue pressure.
- **Goal**: Implement a buffering mechanism.
- **Implementation**: Push logs to a Redis list or cache key. Use a scheduled command to pop a batch of logs (e.g., 100) and ship them in a single HTTP request.