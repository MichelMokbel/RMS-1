# POS Print SSE Relay (Push-Only)

## Route
- `GET /api/pos/print-jobs/stream`

## Reverse Proxy Requirements
For the stream route, configure your proxy to avoid buffering/compression delays:

- Disable response buffering for this location.
- Disable gzip/compression for `text/event-stream`.
- Keep upstream connection/read timeout above the server stream lifetime (default stream closes around 55s).
- Preserve headers:
  - `Content-Type: text/event-stream`
  - `Cache-Control: no-cache`
  - `Connection: keep-alive`
  - `X-Accel-Buffering: no`

## Delivery Notes
- Server pushes full print jobs via SSE `event: job`.
- SSE `id` is required and used with `Last-Event-ID` for replay.
- Delivery is at-least-once; POS deduplicates by `(job_id, claim_token)`.
- Legacy `GET /api/pos/print-jobs/pull` remains available temporarily.

## Structured Logs / Metrics Inputs
Use these log events to derive operational metrics:

- Active streams:
  - `pos_print_stream` with `event=stream_connect|stream_disconnect`
- Push throughput:
  - `pos_print_push_dispatch` with `pushed_count`
- Replay counts:
  - `pos_print_stream_replay` with `replayed_jobs`
- Ack latency:
  - `pos_print_ack` and `pos_print_retry` with `ack_latency_ms`
- Retry count:
  - `pos_print_retry`
- Failed deliveries:
  - `pos_print_ack` with `result=failed_terminal`
