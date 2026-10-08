# MDM inventory — state-carrying event demonstration

Laravel 12, React and MySQL. Required full sync reads the supplied JSON directly. The event extension simulates a provider that emits complete relevant device state; the worker applies stored payloads without fetching Jamf or rereading the file. This contract is our simulator design, not a verified Jamf webhook schema.

## Start

With Docker Desktop's engine running, from this repository:

```sh
docker compose up --build
```

Open http://localhost:5173. **Sync Now** imports the supplied fixture: **7 assets and 6 employees**. Backend API: http://localhost:8000/api/assets. Compose starts frontend, backend, MySQL and a database queue worker, applies migrations automatically, and persists MySQL in a named volume. Ports bind to localhost. Development credentials are only for this local demo.

Stop with `docker compose down`. Reset with `docker compose down -v` (deletes the database volume). Original assignment: [docs/assignment.md](docs/assignment.md). Final architecture, models, assumptions and ordering contract: [docs/design.md](docs/design.md).

## Demonstrate changes

1. Click **Sync Now** to establish current inventory and a durable simulator baseline.
2. Edit `files/api-mock-response.json`, keeping `totalCount` consistent. Change RAM, assignment, model metadata, or remove a device. No rebuild is required.
3. In **Activity**, click **Scan source and queue changes**. This calls `POST /api/simulator/jamf`, comparing the complete source with the persisted producer baseline.
4. Refresh activity after the worker processes notifications. The worker uses the queued payload, not the source file. Editing the source after enqueue does not change an already queued message.

Unchanged scans emit zero events. Changes only to `general.lastContactTime` or `general.reportDate` emit zero events. Actual assignment/hardware changes emit even when reportDate stays unchanged. Report date is informational metadata and is carried when a real change emits; its semantics are not assumed to be a complete change cursor.

Without an initial full sync, the first simulation emits seven additions. After full sync, unchanged simulation emits zero. New/edited/unassigned/removed/reappearing devices produce the corresponding lifecycle notifications. Removal includes last-known complete device state. The simulator requires source serial and provider ID to avoid inventing removals from identity gaps.

Local asset deletion records an independent restore task and removes its attributes. **Restore locally deleted assets** fetches the current file and targets those identities. Local deletion does not change the producer baseline, so unchanged scans do not restore it; full sync or targeted restoration does. Employee deletion leaves linked assets temporarily unassigned until sync.

## Notification contract

`POST /api/events/jamf` accepts a complete normalized message. Its shape is:

```json
{
  "event_id": "unique-message-id",
  "provider_device_id": "101",
  "version": 2,
  "producer_generation": 2,
  "type": "device.changed",
  "device": {
    "provider_device_id": "101",
    "serial_code": "C02DL0XYZQ6N",
    "email": "alex.smith@company.test",
    "employee": {"name": "Alex Smith", "phone": "+491234567890"},
    "model": {"manufacturer": "Apple", "model_identifier": "MacBookPro18,3", "model_name": "MacBook Pro (14-inch, M1 Pro, 2021)"},
    "asset": {"device_name": "Alex’s MacBook Pro", "udid": "6E662F59-DF86-41CE-BD4C-4AD8CE32369C", "purchased": true, "purchase_date": "2022-06-15", "report_date": "2025-11-20 09:15:00"},
    "specifications": {"ram_gb": 32, "storage_gb": null, "processor_type": "Apple M1 Pro", "core_count": 10, "battery_capacity_percent": 91}
  }
}
```

All displayed state fields are required, nullable where shown; model may be null. Types: `device.added`, `device.changed`, `device.unassigned`, `device.removed`. Unassigned requires null email; removed carries previous state. Versions are positive, monotonic per device; generation is monotonic across a producer batch. Numbers are simulator-generated, not derived from timestamps.

HTTP 202 means durably accepted. Event ID or provider/device/version reuse with different content returns 409. Identical replay is safe. The durable inbox is also the local outbox: simulator baseline and messages commit atomically; queue dispatch follows commit. `events:recover` redispatches pending rows after a dispatch gap. The worker retries exceptions up to three queue attempts; failed events can be explicitly retried.

Consumer watermarks survive asset deletion. Older versions/generations are ignored. Equal applied versions with conflicting state fail. New removal/unassignment versions store tombstones atomically with deletion, preventing stale resurrection. Shared employee/model generation guards prevent older other-device events overwriting newer metadata. Full sync atomically advances baseline/watermarks to fence old queued messages before applying its authoritative snapshot.

## Persistence and policies

Services depend on repository interfaces; Eloquent implementations own queries/writes. AssetRepository owns assets and specifications as one aggregate. TransactionBoundary and ProviderLockRepository retain a transaction-scoped permanent row lock across full sync, notification receipt, worker processing and local deletion. No TTL expires midway. Contracts return Eloquent/Laravel data records pragmatically; this is not a completely framework-independent domain layer. Schema remains in `backend/database/migrations`.

Policy choices are documented, not waiting for approval: employee deletion retains unassigned assets; source unassignment or absence removes local assets but retains employees. Absence removals require a validated complete snapshot. Explicit null clears mapped nullable values; missing optional fields preserve during full sync where supported. Event payloads carry complete state. Identity conflicts/invalid nonempty emails fail safely. Shared metadata resolves deterministically in the snapshot adapter. Report date is provenance; purchase date cannot detect later updates. RAM uses MB / 1024, labeled GiB; missing storage is unknown.

## APIs

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/assets`, `/api/assets/{id}` | Paginated list and details |
| GET | `/api/employees` | Employees and asset links |
| DELETE | `/api/assets/{id}`, `/api/employees/{id}` | Local deletion |
| POST | `/api/sync` | Complete direct-file reconciliation and event fences |
| GET | `/api/sync-runs` | Latest runs |
| POST | `/api/restorations` | Independent local-delete restoration |
| POST | `/api/simulator/jamf` | Scan semantic differences and queue complete state |
| POST | `/api/events/jamf` | Accept the versioned complete-message contract |
| GET | `/api/events` | Inbox status/version/outcome |
| POST | `/api/events/{id}/retry` | Requeue failure |

## Verification

Portable PHP suite: **25 tests / 136 assertions passed**. It covers full import/repeat/RAM/reassignment/identity/deletion rules; direct worker payload processing with a broken source file and no provider calls; ignored timestamps; same-timestamp real changes; stale/duplicate/version conflicts; removal/unassignment/readdition tombstones; shared metadata ordering; full-sync fencing; atomic producer baseline/outbox rollback; inbox acknowledgment rollback and source-free retry; restoration and missing lock protection. React production build passes.

Earlier Docker/MySQL verification established startup, migrations, required inventory flows, and real queue processing. The final payload upgrade's integration results are recorded separately in the delivery report. SQLite tests alone do not establish MySQL locking behavior.

Local tests: `cd backend`, `composer install`, `php vendor/phpunit/phpunit/phpunit` with PHP 8.3+ and required extensions. Tests use SQLite and isolated temporary fixture copies. Frontend: `cd frontend && npm ci && npm run build`. Formatting: Pint and Prettier.

## Boundaries

No real Jamf connectivity, webhook authentication, Kafka, serverless deployment, tenant scoping, automatic periodic reconciliation or GitHub publishing is claimed. The simulator reads the full local file; its worker does not. A real provider would need verified complete-payload semantics and reliable sequence numbers, or a different adapter/fallback strategy. Legacy ID-only notifications are no longer accepted; old pending legacy rows fail visibly rather than fetching implicitly. Hard process termination may leave historical sync runs in progress; production cleanup/monitoring remains future work.
