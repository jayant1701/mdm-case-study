# MDM inventory case study

## Purpose

This application imports devices that are assigned to employees, stores a local inventory in MySQL, and shows assets and employees in a React UI.

Jamf is the only provider in scope. The repository does **not** connect to a real Jamf tenant. The supplied `files/api-mock-response.json` file is treated as a sample Jamf API response.

The solution keeps the assignment's direct JSON sync and also demonstrates the recommended design discussed during the case study: a separate mock provider emits durable device events through Kafka, and the inventory service processes those events without reading the whole fleet for every change.

## Assignment requirements and decisions

| Requirement | Implementation |
|---|---|
| Import assigned devices | Rows without an employee email are not kept as local assets |
| Asset identity | `serial_code` is unique locally |
| Employee identity | Email is unique locally |
| Reflect changed assignments and attributes | Full sync and device events both upsert the current values |
| Recreate locally deleted data on sync | Full reconciliation recreates assigned upstream devices and reactivates employees |
| Delete only local data from the UI | UI deletes never call the provider delete endpoint |
| Laravel + React + MySQL | Implemented and started by Docker Compose |
| Future providers | Provider and persistence contracts have concrete Jamf/mock adapters and Eloquent repositories |

The assignment allows one employee to own several assets. The sample contains this case. Each asset has at most one current employee, so the current schema uses `assets.employee_id` instead of a separate employee-assets join table. A join table would be needed if assignment history or simultaneous many-to-many ownership became a requirement.

## Run the application

With Docker Desktop running:

```sh
docker compose up --build
```

- UI: http://localhost:5173
- Inventory API: http://localhost:8000/api/assets
- Mock provider API: http://localhost:8002/api/provider/snapshot

On fresh Docker volumes, the provider initializes its store from the supplied JSON. That produces seven device events; the publisher, Kafka consumer and queue worker then populate seven assigned assets and six employees. Existing Docker volumes keep their previous state.

The UI has four areas:

- **Assets** lists devices. Details open directly below the selected row.
- **Employees** lists active employees and their assigned assets. Selecting an employee opens their contact, status and complete assigned-device details directly below the row.
- **Activity** shows whether provider notifications are waiting. If any are pending, it lists their device name, serial, change type and retry state, offers a manual processing action, and can export the displayed rows as CSV.
- **Testing** can reset the demo, create one deterministic delivery-failure state, change provider devices through Provider Lab, list locally deleted devices and show technical sync/event history. Provider Lab changes the provider only; use Sync Now to fetch those changes into the visible inventory.

`docker compose down` stops the application. `docker compose down -v` also deletes both MySQL volumes and the Kafka volume.

## What the mock JSON is for

`files/api-mock-response.json` is test input representing a complete response from a third-party Jamf API. It is not an event log and it does not contain created, updated or skipped counters. Those counters are calculated by the inventory sync after comparing the response with local state.

There are two explicit sync paths:

1. `POST /api/sync` is the assignment-compatible path. The Laravel inventory backend reads the JSON file directly, sends that complete response through the provider import API, and reconciles the returned versioned snapshot.
2. The UI's **Sync Now** action calls `POST /api/sync/provider`. It does not read the file. It fetches the mock provider's current snapshot over HTTP and reconciles the inventory.

Normal provider POST, PATCH and DELETE operations update the provider database and create events. Event workers use the saved event payload; they do not read the JSON file and do not fetch the whole provider snapshot.

**Reset demo** asks the mock provider to reload the active fixture state and clears the inventory database. It deliberately leaves the inventory empty until **Sync Now** is selected. Provider generations and device versions remain monotonic so a delayed pre-reset event cannot overwrite the newer snapshot.

### Source-field mapping

| Mock response field | Local use |
|---|---|
| `id` | `provider_device_id`; identifies the upstream record for events and targeted lookups |
| `hardware.serialNumber` | `assets.serial_code`; identifies the physical asset locally |
| `userAndLocation.email` | Employee business identifier and assignment signal |
| `userAndLocation.realname`, `phone` | Employee metadata |
| `general.name`, `udid`, purchasing fields | Asset metadata |
| `hardware.make`, `modelIdentifier`, `model` | Shared device-model identity and description |
| RAM, storage, processor, cores, battery | Per-asset attributes because these can vary between devices of the same model |
| `general.reportDate` | Stored as metadata; excluded from event change detection |
| `general.lastContactTime` | Not stored and does not create an event |
| `site` | Not stored because the assignment has no site model or site behavior |

The response example discussed during design included `site: {"id":"-1","name":"None"}`, which appears to represent no site assignment. The checked-in `api-mock-response.json` does not contain a `site` field, and the mapper does not store one. In either case, site is separate from employee assignment; this implementation determines employee assignment from email.

JSON order, increasing provider IDs, purchase date and report date are not trusted as change cursors. The supplied contract does not guarantee those properties, and none of them reliably represents removals.

## Recommended design

The mock provider is enhanced to model an event-driven integration:

```text
Provider POST / PATCH / DELETE
  -> save provider state and an outbox event in one MySQL transaction
  -> publisher sends the saved event to Kafka and waits for acknowledgement
  -> consumer validates and saves the event in the inventory inbox
  -> consumer commits its Kafka offset after durable inbox acceptance
  -> Laravel queue job applies the stored event
  -> React reads the resulting inventory through HTTP APIs
```

Each event contains an event ID, provider device ID, per-device version, provider generation, lifecycle type and the complete relevant device state. Complete state avoids an extra provider GET for every event. The trade-off is a larger message.

Full reconciliation remains necessary for initial import and recovery. It is manual in this case study; no reconciliation scheduler is implemented.

### Why the outbox and Kafka are both used

The provider cannot atomically commit one transaction across MySQL and Kafka. It therefore writes the provider change and its notification to the same MySQL transaction. A publisher sends committed outbox rows to Kafka afterward.

- The **outbox** prevents a committed provider change from losing its notification.
- **Kafka** buffers bursts, separates producer and consumer availability, and retains acknowledged messages for replay during its retention period.
- The **inventory inbox** makes consumer receipt durable before the Kafka offset is committed.
- Event IDs and device versions make repeated delivery safe and stop older messages from overwriting newer data.

A lock coordinates concurrent provider mutations, while the transaction makes the provider row and outbox row atomic. A lock is not a replacement for a transaction.

The deterministic **Simulate Kafka failure** button does not shut down Kafka. It changes one provider device and creates the same durable pending-outbox state that a failed publish would leave: one attempt, an error, and a deferred retry time. This makes the recovery path reviewable without changing Docker infrastructure. The automated publisher ignores the deferred row until it is due; the Activity action can process it immediately.

## Solutions considered

| Solution | Decision |
|---|---|
| Full synchronization | Keep for initial import and recovery. Re-reading every device for every change is wasteful |
| Scheduled polling | Simple fallback, but shorter intervals repeatedly transfer unchanged data |
| `reportDate` cursor | Do not use without a provider guarantee that every relevant change updates it and removals are represented |
| Increasing device ID | Do not use as a cursor; it misses edits, unassignments and removals |
| Purchase-date filter | Do not use for change detection; it does not change with later updates |
| ID-only event followed by provider GET | Valid and smaller on Kafka, but adds a provider request and depends on provider availability |
| Complete-state event | Chosen; the worker can process a stored event without a provider read |
| Changed-fields-only event | Not chosen; merging partial messages makes ordering and replay harder |
| Webhook | A possible delivery trigger, but a webhook is not persistent by itself. It still needs a durable sender/outbox, idempotent receiver and reconciliation |
| Direct outbox-to-HTTP delivery | Simpler for one consumer and a reasonable alternative; Kafka was chosen to demonstrate burst buffering and replay |
| Kafka | Implemented with the transactional outbox. The estimated average load alone does not require Kafka |
| Serverless consumer | Possible deployment choice for bursty work, but not implemented and does not change the event contract |
| gRPC | Not used. Typed calls do not solve change detection or persistence; HTTP is sufficient for query/control traffic here |
| Version/hash list or batch API | Useful future optimization for reconciliation, but not part of the supplied provider contract |

This repository modifies only the supplied mock provider. A real Jamf integration may expose webhooks, polling filters or different response shapes; those capabilities must be verified before replacing the adapter.

## Back-of-the-envelope capacity estimate

These are planning assumptions from the design discussion, not assignment facts or benchmark results:

- 100 customers.
- 10,000 to 1,000,000 devices per customer: 1 million to 100 million devices in total.
- 10% annual additions/departures plus 1% replacements.
- One event per affected device: 11% of the fleet per year. If a replacement produces both removal and addition, use 12%.
- 2 KB for a complete device event and 250 bytes for an ID-only event.

| Total devices | Events/year at 11% | Average events/second | Complete-event traffic/year | ID-only traffic/year |
|---|---:|---:|---:|---:|
| 1 million | 110,000 | 0.0035 | 0.22 GB | 0.0275 GB |
| 100 million | 11 million | 0.35 | 22 GB | 2.75 GB |

At 2 KB per device, one full import is about 2 GB to 200 GB. A daily full import transfers about 730 GB to 73 TB per year, mostly unchanged data.

Complete events add about 0.19 GB to 19.25 GB per year over ID-only events. With 30 days of retention and three stored copies, that difference is about 0.047 GB to 4.75 GB. Three copies are a planning assumption; the local Docker setup has one Kafka broker and one replica.

If every device created one event per day, the average would rise to about 12 to 1,157 events per second. This is why routine contact timestamps are excluded from the event fingerprint. Kafka can absorb a temporary burst, but database and worker capacity still determine how fast the backlog clears.

At larger scale, add `company_id` to all identities and event keys. Partition workers by company or company group, and shard the database only after measurement shows it is needed. Company scoping and sharding are design considerations, not implemented features.

## Data model

### Inventory database

| Entity | Purpose |
|---|---|
| `employees` | Internal ID, unique email, name, phone and soft-deletion time |
| `device_models` | Manufacturer, model identifier and shared model name; unique by manufacturer + model identifier |
| `assets` | Unique serial, current employee/model references, device metadata, provider and provider device ID |
| `asset_attributes` | One row per asset for RAM, storage, processor, cores and battery condition |
| `restore_tasks` | Provider ID, serial, device name and deletion status retained after a local asset deletion |
| `sync_runs` | Sync mode, status, counters, timestamps and error |
| `event_inboxes` | Durable incoming event payload, processing status, attempts and error |
| `device_event_states` | Last accepted version/state per provider device; retained after asset deletion |
| `shared_event_states` | Ordering state for employee and model metadata shared by several devices |
| `sync_locks` | Inventory transaction lock used to serialize sync/event/recovery changes |
| `jobs`, `failed_jobs` | Laravel queue storage |
| `rejected_notifications` | Invalid or conflicting Kafka messages retained for investigation |

### Provider database

| Entity | Purpose |
|---|---|
| `provider_devices` | Current provider-owned device state, version, generation and removal tombstone |
| `provider_generation` | Monotonic provider sequence and transaction lock |
| `producer_outbox` | Outgoing event, status, attempts, error, next retry time and publication time |

The provider and inventory use separate MySQL databases and credentials. Migrations live in `backend/database/provider-migrations` and `backend/database/migrations`. The database is not a separate network service layer: repository interfaces form the database access boundary, and Eloquent implementations contain the queries. Some application types still depend on Laravel/Eloquent, so framework independence is incomplete.

`provider_device_id` and serial serve different purposes. Serial is the local physical-asset identity. Provider device ID is the upstream record identity used for events, version tracking and targeted provider lookups. Both are retained because neither should silently change to represent a different device.

`DeviceModel` and `AssetAttributes` are intentionally different. Model identity and description are shared, while installed RAM, storage and battery state can vary per physical asset. The implementation does not assume every hardware property is immutable for the life of a model identifier.

## Delete and reconciliation behavior

### Local asset deletion

1. Save provider ID, serial, device name and deletion time in `restore_tasks`.
2. Delete the local asset and its cascading attributes.
3. Keep the employee and provider record unchanged.
4. Show the retained identity as a locally deleted device under Testing.

The UI has no restore button. **Sync Now** performs complete reconciliation, so an assigned device that still exists at the provider reappears. A targeted `POST /api/restorations` recovery endpoint also exists and performs an individual provider lookup, but it is not exposed in the UI.

### Local employee deletion

The employee row is soft deleted: its ID, email and deletion time remain, while name and phone are cleared. Linked assets remain and display **Deleted user (email)**. A later event or full sync can reactivate the same employee.

This choice preserves asset ownership history while meeting the required behavior that sync recreates a locally deleted employee. A strict physical-row deletion would require a separate tombstone/identity table to keep the same information.

### Provider removal or unassignment

A provider removal or missing employee email removes the local asset because only assigned devices are imported. It does not delete the employee. Provider DELETE retains a tombstone and emits `device.removed`; unassignment emits `device.unassigned`.

Absence is treated as removal only when processing a validated complete snapshot. A real paginated adapter must fetch every page before applying that rule.

## Failure handling

| Failure | Implemented behavior |
|---|---|
| Provider change succeeds but notification write fails | Same MySQL transaction rolls back both |
| Kafka publish fails | Outbox row remains pending with retry details |
| Publisher crashes after Kafka accepted the event | Event may be resent; inventory deduplicates it |
| Consumer stops before offset commit | Kafka redelivers; the inbox deduplicates it |
| Queue dispatch fails after receipt | Pending inbox row is recovered and dispatched again |
| Invalid/conflicting Kafka payload | Record and error are saved in `rejected_notifications` before acknowledgement |
| Worker fails | Inventory writes and event completion roll back together |
| Older event arrives later | Device version and provider generation prevent regression |
| Full reconciliation races with an old event | Reconciliation records the current provider version, so the old event is ignored |

Delivery is at least once. HTTP 202 from the event-ingestion endpoint means the event was saved, not that inventory processing finished.

The local Kafka broker has three partitions, seven-day retention, one broker and one replica. Its volume survives ordinary container restarts, but this is not a highly available cluster. SQL inbox/outbox cleanup, backups, monitoring and recovery drills remain production work.

## Provider and database boundaries

The adapter pattern is expressed by contracts rather than by requiring a base class:

- `DeviceProvider` parses the supplied Jamf-format fixture.
- `RemoteProviderGateway` and `TargetedDeviceProvider` define remote snapshot, lookup and control operations.
- `HttpMockProviderAdapter` implements those operations for the separate mock provider.
- Repository contracts isolate application services from Eloquent queries.
- Laravel dependency injection selects the concrete implementations.

Adding a second real provider still requires a provider registry/selection mechanism and tenant-scoped credentials. The current runtime binds only one provider and some services use the literal provider key `jamf`; multi-provider runtime selection is not implemented.

## API endpoints

Provider endpoints are on port 8002 and own simulated source state:

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/provider/snapshot` | Complete versioned provider state, including removal tombstones |
| GET | `/api/provider/devices/{providerId}` | One versioned device/tombstone; unknown ID returns 404 |
| POST | `/api/provider/devices` | Create a provider device and outbox event |
| PATCH | `/api/provider/devices/{providerId}` | Apply partial changes; serial cannot change |
| DELETE | `/api/provider/devices/{providerId}` | Retain a tombstone and create a removal event |
| POST | `/api/provider/import` | Import a complete raw Jamf-format response |
| POST | `/api/provider/scan` | Compare the mounted fixture with provider state and emit semantic changes |
| POST | `/api/provider/seed` | Initialize an empty provider store once |
| GET | `/api/provider/outbox` | Pending count and up to 100 notification summaries |
| POST | `/api/provider/outbox/retry` | Immediately try up to ten pending notifications |
| POST | `/api/provider/testing/reset` | Local/testing demo reset |
| POST | `/api/provider/testing/kafka-failure` | Local/testing deterministic failure state |

Inventory endpoints are on port 8000:

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/assets`, `/api/assets/{id}` | List assets and read details |
| GET | `/api/employees`, `/api/employees/{id}` | List active employees and read one employee with complete assigned-device details |
| DELETE | `/api/assets/{id}` | Delete only the local asset and retain recovery identity |
| DELETE | `/api/employees/{id}` | Soft delete only the local employee |
| POST | `/api/sync` | Assignment-compatible direct fixture import and reconciliation |
| POST | `/api/sync/provider` | Reconcile with the current provider snapshot; used by Sync Now |
| GET | `/api/deleted-assets` | List pending local asset deletions |
| POST | `/api/restorations` | Targeted recovery using retained provider IDs; no UI action |
| GET | `/api/sync-runs` | Recent sync outcomes and counters |
| POST | `/api/events/jamf` | HTTP/test ingestion into the durable inbox; Kafka runtime calls the same receiver directly |
| GET | `/api/events` | Technical event-processing history |
| POST | `/api/events/{id}/retry` | Retry a failed inventory event |
| GET | `/api/provider-outbox` | Proxy pending count and notification summaries for Activity/CSV export |
| POST | `/api/provider-outbox/retry` | Proxy manual outbox processing for Activity |
| POST | `/api/simulator/jamf` | Technical fixture scan; not exposed in the UI |
| POST | `/api/demo/reset` | Local/testing reset of provider fixture state and local inventory |
| POST | `/api/demo/kafka-failure` | Local/testing deterministic pending notification |
| POST | `/api/demo/provider-devices` | Provider Lab: create a provider device without running reconciliation |
| PATCH | `/api/demo/provider-devices/{providerId}` | Provider Lab: update provider name/RAM without running reconciliation |
| DELETE | `/api/demo/provider-devices/{providerId}` | Provider Lab: remove a provider device without running reconciliation |

The demo reset/failure routes are registered only in local/testing environments. The outbox status/retry routes are currently available without authentication because this is a local case study; they require restricted access in production.

## Verification

The backend suite completed successfully: **61 tests and 404 assertions**. The React production build completed successfully.

Automated tests cover initial/repeated sync, RAM and assignment changes, missing/invalid data, local deletion and recreation, provider CRUD, transactional outbox behavior, retries, duplicate/stale events, Kafka receipt/quarantine rules, timestamp-only suppression, remote reconciliation and adapter boundaries.

Browser checks confirmed:

- reset followed by Sync Now restores the seven-device baseline;
- asset details render under the selected row;
- the deterministic failure remains pending across automatic publisher cycles;
- Activity shows the pending device name, serial, change type and retry time;
- the CSV export action completes for the displayed pending rows;
- Activity manually publishes that one pending notification;
- Activity then reports an empty outbox.
- Provider Lab creates, updates and removes provider state separately; Sync Now then reconciles the visible inventory.

Multi-page pagination, mobile layout and full screen-reader testing remain unchecked. The pending asset-details navigation race is recorded in `docs/backlog.md`.

Backend test command:

```sh
cd backend
composer install
php vendor/phpunit/phpunit/phpunit
```

Frontend build command:

```sh
cd frontend
npm ci
npm run build
```

## Production work

Before connecting real customers, verify the real Jamf API/webhook contract, add provider authentication and tenant-scoped authorization, schedule periodic reconciliation, define SQL/Kafka retention, add metrics and alerts, benchmark peak workloads, and test database/Kafka backup and recovery.

The current result is a local case study: one mock provider, one company scope, separate provider and inventory databases, transactional outbox, Kafka transport, durable inventory inbox, queue worker and manual recovery controls.

For a concise source-code walkthrough, see [Codebase onboarding](docs/onboarding.md).
