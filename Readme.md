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
- **Activity** shows provider notifications waiting for Kafka and permanently failed inventory changes. Delivery failures can be processed manually or exported as CSV. Inventory failures identify the affected device and employee and can be retried; complete payloads and exceptions remain in the database for debugging.
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
| `userAndLocation.username`, `realname`, `position`, `phone` | Employee metadata |
| `general.name`, `udid`, purchasing fields | Asset metadata |
| `hardware.make`, `modelIdentifier`, `model`, `processorType`, `coreCount` and `general.platform` | Shared device-model and configuration data |
| RAM, storage and battery | Per-asset attributes because these can vary between physical devices |
| `general.reportDate` | Asset freshness watermark; an older report cannot overwrite newer asset state |
| `general.lastContactTime` | Stored on the asset but excluded from event change detection |
| `general.site.id`, `general.site.name` | Stored on the asset; `-1` / `None` is normalized to no site |

The supplied response uses `site: {"id":"-1","name":"None"}`, so both local site fields are `null`. Site is separate from employee assignment; assignment is determined only from employee email.

This implementation treats the model identifier, manufacturer, model name, platform, processor and core count as shared model/configuration data. A conflicting non-null value for the same manufacturer and model identifier rejects the sync instead of silently changing every asset that references the model. Missing or null model metadata does not erase an existing known value.

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

### Why shared event state exists

One employee or device model can appear in events for several devices. Kafka preserves order for one device key, but it does not guarantee that events for two different devices arrive in their original order.

For example, Alex owns a MacBook and an iPhone. A MacBook event at provider generation 20 says Alex is a Senior Engineer. A delayed iPhone event from generation 15 still contains Alex's older title, Backend Engineer. The iPhone data may be valid for that device, so the whole event cannot be discarded. `shared_event_states` records that employee `alex.smith@company.test` was already updated at generation 20. The worker applies the iPhone fields but ignores its older employee fields.

The same rule protects device-model data shared by several assets. Each checkpoint is unique by `provider + kind + identity`: `kind` is `employee` or `model`, `identity` is an employee email or manufacturer/model identifier, `generation` is the newest accepted provider generation, and `fingerprint` is a hash of the shared fields. An older generation is ignored. The same generation with a different fingerprint is rejected as conflicting data. The actual records remain in `employees` and `device_models`; `shared_event_states` stores only ordering checkpoints, not another copy or a change history.

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
| Direct outbox-to-HTTP delivery | Simpler for one consumer and a reasonable alternative; Kafka was chosen to demonstrate burst buffering and replay |
| Kafka | Implemented with the transactional outbox. The estimated average load alone does not require Kafka |
| Serverless deployment | A reasonable alternative for bursty work. API Gateway, Lambda and SQS can replace always-running API/worker/Kafka containers while retaining durable delivery and reconciliation. It is described below but is not implemented |
| gRPC | Not used. Typed calls do not solve change detection or persistence; HTTP is sufficient for query/control traffic here |
| Version/hash list or batch API | Useful future optimization for reconciliation, but not part of the supplied provider contract |

This repository modifies only the supplied mock provider. The real Jamf API's response shape, authentication, pagination, filters and rate limits must be verified before replacing the adapter.

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

Kafka is already designed to scale. The calculated load does not justify adding consumer partitions or database shards to this case study. If this becomes a multi-customer product, add `company_id` so the same email, serial number or provider device ID may safely exist in different companies and every query is restricted to the authenticated company. This is a data-isolation requirement before it is a scaling technique.

Future scaling should follow the measured bottleneck. If selecting pending database rows becomes slow, add a composite outbox index for the actual query, such as status, retry time and ID; include company when work is company-scoped. If consumer lag grows, add partitions and consumers. If a provider throttles one company, schedule work fairly by company. Database sharding remains a later option for measured throughput or storage limits.

## Alternative serverless deployment

The same application boundary could be deployed with managed, usage-based services:

```text
React on S3/CloudFront
  -> API Gateway HTTP API
  -> Lambda provider/API functions
  -> Aurora MySQL Serverless (provider state, inventory and durable outbox/inbox)
  -> scheduled Lambda outbox publisher
  -> SQS with a dead-letter queue
  -> Lambda inventory worker
  -> Aurora MySQL Serverless
```

SQS is enough for the calculated traffic and is simpler to operate than a Kafka cluster. Use a FIFO message group based on company and device only if strict per-device queue ordering is required; the existing versions and idempotency rules are still required. EventBridge Scheduler can run reconciliation and the outbox relay. Cognito or an existing identity provider can authenticate users and carry their company identity. This deployment keeps the provider adapter and application services; it changes infrastructure, not the domain model.

The following monthly event-path estimate uses the README assumptions, 9,167 to 916,667 events per month, and public US pricing. It assumes one 2 KB event, a 512 MB Lambda running for 500 ms, one HTTP API request and three SQS operations per event. It excludes free tiers and rounds the result:

| Component | Low estimate | High estimate |
|---|---:|---:|
| Lambda duration | $0.04 | $3.82 |
| Lambda requests | below $0.01 | $0.18 |
| API Gateway HTTP API | $0.01 | $0.92 |
| SQS requests | $0.01 | $1.10 |
| **Event-path subtotal** | **about $0.06/month** | **about $6.02/month** |

These figures show that the expected device-change events are not the main cost. Aurora capacity and storage, backups, network/NAT use, user-facing reads, full reconciliations, security services, support and observability are not included and may dominate the bill. They require a region, availability target, database size, API traffic and retention policy before they can be estimated. Current prices must be checked in the [AWS Lambda](https://aws.amazon.com/lambda/pricing/), [API Gateway](https://aws.amazon.com/api-gateway/pricing/), [SQS](https://aws.amazon.com/sqs/pricing/), [Aurora](https://aws.amazon.com/rds/aurora/pricing/) and [CloudWatch](https://aws.amazon.com/cloudwatch/pricing/) calculators before implementation.

Logging cost should be controlled at the source: emit structured errors and state transitions, use metrics for counts, avoid an information log for every unchanged asset, sample high-volume diagnostics, keep short searchable retention and archive older logs to object storage. CloudWatch can deliver Lambda logs to S3 or Firehose. Grafana Cloud or Datadog can receive OpenTelemetry logs and metrics, but either may cost more depending on ingestion, indexing and retention; Datadog, for example, prices log ingestion and indexed events separately. Select a service from measured telemetry volume rather than assuming one vendor is cheaper.

The design discussion referenced a previous Prime Video subscription and buy/rent workload serving millions of customers in the North America region that cost its team about 7–8k per month. The currency, traffic profile and service breakdown were not supplied, so that figure is not used as this system's forecast. It is useful evidence that customer API traffic and observability can cost much more than the small device-event path calculated above.

## Data model

### Inventory database

| Entity | Purpose |
|---|---|
| `employees` | Active employees: internal ID, unique email, username, name, position, phone and timestamps |
| `deleted_employees` | Deleted employee identity only: original employee ID, unique email and deletion time |
| `device_models` | Manufacturer, model identifier, model name, platform, processor and cores; unique by manufacturer + model identifier |
| `assets` | Unique serial, active/deleted employee reference, device-model reference, provider identity, purchase data, report/contact times and site fields |
| `asset_attributes` | One row per asset for mutable RAM, storage and battery values |
| `deleted_assets` | Provider ID, serial, device name and deletion time retained after a local asset deletion |
| `sync_runs` | Sync mode, status, counters, timestamps and error |
| `event_inboxes` | Durable incoming event payload, processing status, attempts and error |
| `device_event_states` | Last accepted version/state per provider device; retained after asset deletion |
| `shared_event_states` | Ordering state for employee and model metadata shared by several devices |
| `sync_locks` | Inventory transaction lock used to serialize sync/event/recovery changes |
| `jobs`, `failed_jobs` | Laravel queue storage |
| `rejected_notifications` | Invalid or conflicting Kafka messages retained for investigation |

Core schema rules:

| Table | Main columns and constraints |
|---|---|
| `employees` | `id` primary key; `email` unique; `username`, `name`, `position`, `phone`; timestamps |
| `deleted_employees` | `employee_id` primary key and original employee ID; `email` unique; `deleted_at`; no duplicated personal metadata |
| `device_models` | `id` primary key; `manufacturer` + `model_identifier` unique; `model_name`, `platform`, `processor_type`, `core_count`; no row timestamps |
| `assets` | `id` primary key; `serial_code` unique; nullable `employee_id` and `deleted_employee_id`; nullable `device_model_id`; `provider` + `provider_device_id` unique; name, UDID, purchase fields, `report_date`, `last_contact_at`, `site_provider_id`, `site_name`; timestamps |
| `asset_attributes` | One row per `asset_id`; `ram_gb`, `storage_gb`, `battery_capacity_percent`; timestamps |
| `deleted_assets` | Provider, provider device ID, serial, device name and `deleted_at`; unique by provider + serial |

An imported asset is assigned to either an active employee or a deleted-employee identity. Unassigned provider devices are removed instead of being stored with both references empty. Repository transactions maintain this rule during employee deletion and restoration.

### Provider database

| Entity | Purpose |
|---|---|
| `provider_devices` | Current provider-owned device state, version, generation and removal tombstone |
| `provider_generation` | Monotonic provider sequence and transaction lock |
| `producer_outbox` | Outgoing event, status, attempts, error, next retry time and publication time |

The provider and inventory use separate MySQL databases and credentials. Migrations live in `backend/database/provider-migrations` and `backend/database/migrations`. The database is not a separate network service layer: repository interfaces form the database access boundary, and Eloquent implementations contain the queries. Some application types still depend on Laravel/Eloquent, so framework independence is incomplete.

`provider_device_id` and serial serve different purposes. Serial is the local physical-asset identity. Provider device ID is the upstream record identity used for events, version tracking and targeted provider lookups. Both are retained because neither should silently change to represent a different device.

`DeviceModel` contains the shared model and fixed configuration assumed by this case study. `AssetAttributes` contains RAM, storage and battery state that can vary per physical asset. If the real provider allows processor or core-count upgrades without changing `modelIdentifier`, those fields should move back to the asset level or a separate configuration entity.

## Delete and reconciliation behavior

### Local asset deletion

1. Save provider ID, serial, device name and deletion time in `deleted_assets`.
2. Delete the local asset and its cascading attributes.
3. Keep the employee and provider record unchanged.
4. Show the retained identity as a locally deleted device under Testing.

The UI has no restore button. **Sync Now** performs complete reconciliation, so an assigned device that still exists at the provider reappears. A targeted `POST /api/restorations` recovery endpoint also exists and performs an individual provider lookup, but it is not exposed in the UI.

### Local employee deletion

The active row is removed from `employees`. A small row containing its original ID, email and deletion time is written to `deleted_employees`. Linked assets set `employee_id` to `null` and retain `deleted_employee_id`, so they display **Deleted user (email)** without keeping the deleted person in the active employee table.

An assigned device event, a complete reconciliation, or the targeted recovery endpoint recreates the active employee with the same internal ID, relinks all retained assets and removes the `deleted_employees` row. Targeted recovery gets the relevant provider devices by the provider IDs already stored on those assets; the tombstone does not duplicate that list.

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
| Worker attempt fails | Inventory writes and event completion roll back together; the inbox is marked `retrying` |
| Worker exhausts three attempts | The inbox is marked `failed`, retained for debugging and shown in Activity with a manual retry action |
| Older event arrives later | Device version and provider generation prevent regression |
| Full reconciliation races with an old event | Reconciliation records the current provider version, so the old event is ignored |

Delivery is at least once. HTTP 202 from the event-ingestion endpoint means the event was saved, not that inventory processing finished.

The local Kafka broker has three partitions, seven-day retention, one broker and one replica. Its volume survives ordinary container restarts. A deployed system should retain Kafka and completed inbox/outbox records long enough to investigate and replay failures, then archive or delete them so storage does not grow forever.

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
| DELETE | `/api/employees/{id}` | Delete the active employee locally and retain the identity tombstone |
| POST | `/api/sync` | Assignment-compatible direct fixture import and reconciliation |
| POST | `/api/sync/provider` | Reconcile with the current provider snapshot; used by Sync Now |
| GET | `/api/deleted-assets` | List pending local asset deletions |
| POST | `/api/restorations` | Targeted recovery using retained provider IDs; no UI action |
| GET | `/api/sync-runs` | Recent sync outcomes and counters |
| POST | `/api/events/jamf` | HTTP/test ingestion into the durable inbox; Kafka runtime calls the same receiver directly |
| GET | `/api/events` | Technical event-processing history |
| GET | `/api/event-failures` | Actionable inventory failures summarized by affected device and employee |
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

The backend suite completed successfully: **66 tests and 429 assertions**. The React production build and Laravel formatting check completed successfully. The migration also completed against the existing MySQL Docker volume, including legacy event records created before the new nullable fields existed.

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

Multi-page pagination, mobile layout and full screen-reader testing remain unchecked. The earlier navigation race is fixed: selecting device details moves to Assets before the request starts, so a late response cannot move the user away from a tab selected afterward.

Backend test command:

```sh
docker compose --profile test run --rm --build backend-tests
```

Frontend build command:

```sh
docker compose run --rm --no-deps --build frontend npm run build
```

## Production work

Before connecting real customers, verify the real Jamf API response, authentication, pagination and rate limits; schedule reconciliation; and protect the application and operational endpoints with company-scoped authorization.

The current result is a local case study: one mock provider, one company scope, separate provider and inventory databases, transactional outbox, Kafka transport, durable inventory inbox, queue worker and manual recovery controls.

For a concise source-code walkthrough, see [Codebase onboarding](docs/onboarding.md).
