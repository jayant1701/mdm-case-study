# Codebase onboarding

## Start here

This repository contains one React frontend and one Laravel codebase running in two roles:

- **provider role:** owns simulated Jamf state and the outgoing event outbox;
- **inventory role:** owns employees, assets, synchronization and incoming event processing.

The two roles use separate MySQL databases in Docker. Kafka carries device events between them.

Read these files first:

1. `Readme.md` — product behavior, decisions, APIs and operational limits.
2. `docker-compose.yml` — running services and their boundaries.
3. `backend/routes/api.php` and `backend/routes/provider-api.php` — HTTP entry points.
4. `backend/app/Providers/AppServiceProvider.php` — interface-to-implementation bindings.
5. `backend/app/Services/JamfSyncService.php` — inventory sync orchestration.
6. `backend/app/Services/IndependentProviderService.php` — provider state, versions and outbox writes.
7. `frontend/src.jsx` and `frontend/views.jsx` — UI state, API calls and pages.

## Repository structure

```text
.
├── backend/
│   ├── app/
│   │   ├── Contracts/            Interfaces for providers, messaging and persistence
│   │   ├── Data/                 Device/event data objects
│   │   ├── Http/Controllers/     Inventory, event, provider and demo HTTP handlers
│   │   ├── Jobs/                 Queued event-processing job
│   │   ├── Messaging/            Kafka publisher implementation
│   │   ├── Models/               Eloquent models
│   │   ├── Providers/Devices/    JSON, local and HTTP provider adapters
│   │   ├── Repositories/Eloquent Database implementations of persistence contracts
│   │   └── Services/             Sync, reconciliation, events, deletion and demo workflows
│   ├── database/
│   │   ├── migrations/           Inventory database schema
│   │   └── provider-migrations/  Provider database schema
│   ├── routes/                   HTTP and console command entry points
│   └── tests/Feature/            Backend behavior and boundary tests
├── frontend/
│   ├── src.jsx                   App state, API calls and navigation
│   ├── views.jsx                 Assets, Employees, Activity and Testing views
│   ├── pendingNotificationsCsv.js CSV creation and browser download
│   └── style.css                 Shared UI styling
├── files/
│   ├── api-mock-response.json    Supplied Jamf-format fixture
│   └── RefJamfSyncService.php    Supplied reference service
├── docs/
│   ├── assignment.md             Original task
│   ├── design.md                 Detailed implemented design
│   ├── onboarding.md             This guide
│   └── backlog.md                Deferred work
├── docker-compose.yml            Local provider, inventory, Kafka and UI deployment
└── Readme.md                     Main submission document
```

## Runtime services

| Compose service | Responsibility |
|---|---|
| `provider-mysql` | Provider devices, versions, tombstones and producer outbox |
| `provider` | Laravel provider HTTP API on port 8002 |
| `publisher` | Reads due provider-outbox rows and publishes them to Kafka |
| `kafka` | Topic transport and retained replay |
| `mysql` | Inventory, sync history, inbox and Laravel jobs |
| `backend` | Laravel inventory HTTP API on port 8000 |
| `consumer` | Reads Kafka, validates messages and stores the inventory inbox |
| `worker` | Processes stored inbox events through Laravel jobs |
| `frontend` | React/Vite UI on port 5173 |

`APP_ROLE` selects provider or inventory routes. `PROVIDER_MODE=remote` makes the inventory use the provider HTTP adapter in the Docker deployment.

## Main interfaces

Provider adapters use interfaces; an abstract base class is not required.

| Interface | Purpose | Current implementation |
|---|---|---|
| `DeviceProvider` | Read/parse a complete provider snapshot | `JamfMockProvider` reads the supplied JSON |
| `TargetedDeviceProvider` | Read one device or a complete snapshot | `HttpMockProviderAdapter` in Docker; `MockProviderAdapter` in local compatibility mode |
| `RemoteProviderGateway` | Provider snapshot/import, outbox controls, demo controls and provider CRUD | `HttpMockProviderAdapter` |
| `EventPublisher` | Publish one complete event | `KafkaEventPublisher` |
| Persistence contracts | Assets, employees, models, sync runs, inbox, checkpoints, outbox and deleted-asset identity | Classes under `Repositories/Eloquent` |
| `TransactionBoundary` | Run application work in a database transaction, optionally with the inventory provider lock | `DatabaseTransactionBoundary` |

Bindings are registered in `AppServiceProvider`. Services depend on contracts where a boundary is intended. Some contracts return Eloquent types, so the application is not fully independent of Laravel.

## Important services

| Service | Responsibility |
|---|---|
| `IndependentProviderService` | Provider create/update/remove, complete imports, device versions, provider generation and transactional outbox |
| `ProducerOutboxPublisher` | Publish pending provider events and record acknowledgement/retry state |
| `KafkaNotificationReceiver` | Validate Kafka metadata/payload and durably accept or quarantine it |
| `EventReceiver` | Deduplicate incoming events, store the inbox row and dispatch processing |
| `EventProcessor` | Apply a stored event while enforcing versions and ordering |
| `JamfSyncService` | Record and execute full, provider, event and restoration sync runs |
| `RemoteProviderSyncService` | Reconcile versioned provider HTTP snapshots and targeted lookups |
| `DeviceReconciler` | Apply normalized device state to employees, models, assets and attributes |
| `AssetDeletionService` | Local-only asset and employee deletion policies |
| `DemoControlService` | Reset/failure demonstrations and provider-only Provider Lab mutations |

## Data movement

### Event path

```text
Provider mutation
  -> provider_devices + producer_outbox transaction
  -> Kafka publisher
  -> Kafka consumer
  -> event_inboxes
  -> ProcessDeviceEvent job
  -> EventProcessor
  -> JamfSyncService / DeviceReconciler
  -> inventory tables
```

The worker processes the stored complete event payload. It does not read the JSON fixture or call the provider.

### Ordering checkpoints

`device_event_states` stores the newest applied version for each provider device. If device `101` has reached version 6, a delayed version 5 event is marked stale and cannot overwrite or recreate that device.

`shared_event_states` handles data that can arrive through several device events. Suppose Alex owns device `101` and device `103`:

```text
Device 101 event, generation 20: Alex is a Senior Engineer
Device 103 event, generation 15: Alex is a Backend Engineer
```

The generation-15 event may contain a valid change for device `103`, so processing continues for that device. Before writing the employee, `SharedMetadataPolicy` reads the employee checkpoint. Because generation 20 is already stored for `alex.smith@company.test`, the older employee fields are ignored and Alex remains a Senior Engineer. Device models use the same policy because many assets may refer to one model.

The checkpoint schema is:

| Column | Meaning |
|---|---|
| `provider` | Provider namespace, currently `jamf` |
| `kind` | `employee` or `model` |
| `identity` | Employee email or `manufacturer|model_identifier` |
| `generation` | Newest provider generation accepted for this shared identity |
| `fingerprint` | Hash of the shared fields at that generation |

`provider + kind + identity` is unique. An older generation contributes no shared fields. The same generation with a different fingerprint is rejected as a conflict. The table stores only the latest ordering checkpoint; the real data remains in `employees` and `device_models`.

### Full reconciliation path

```text
React Sync Now
  -> POST /api/sync/provider
  -> HttpMockProviderAdapter
  -> provider snapshot
  -> RemoteProviderSyncService
  -> DeviceReconciler
  -> SyncRun counters
```

`POST /api/sync` is the separate assignment-compatible path that reads the supplied JSON and imports it through the provider boundary.

### Provider Lab path

```text
Testing form
  -> local/testing inventory demo endpoint
  -> RemoteProviderGateway create/patch/delete
  -> normal provider transaction and outbox event
  -> display provider change and prompt for Sync Now

Sync Now
  -> provider snapshot
  -> inventory reconciliation
  -> refreshed Assets and Employees views
```

Provider Lab does not call inventory reconciliation or refresh inventory data. The provider mutation still creates the normal durable outbox event, so the event pipeline remains active in the background. Sync Now explicitly fetches the provider snapshot and refreshes the visible inventory; version checkpoints prevent delayed delivery from reversing newer state.

## Database ownership

Inventory migrations are in `backend/database/migrations`:

- active employees, deleted-employee identity, device models, assets and one-to-one asset attributes;
- sync runs and locally deleted asset identity;
- event inbox, processed device/shared state and rejected notifications;
- Laravel jobs and the inventory sync lock.

Provider migrations are in `backend/database/provider-migrations`:

- current provider device state and removal tombstones;
- provider generation/lock;
- producer outbox and retry schedule.

The services do not share database credentials or write each other's tables.

## Where to make common changes

| Change | Start here |
|---|---|
| Map another Jamf response field | `JamfMockProvider`, then `DeviceRecord` / `DeviceEvent`, migrations and reconciler |
| Add another provider | Provider contracts, a new adapter and provider selection/credentials |
| Change asset/employee persistence | Persistence contract, Eloquent repository and migration |
| Change event fields | `DeviceEvent`, provider state normalization and event tests |
| Change sync rules | `JamfSyncService`, `RemoteProviderSyncService` and `DeviceReconciler` |
| Change retry/delivery behavior | `ProducerOutboxPublisher`, Kafka commands and outbox repository |
| Change UI behavior | `frontend/src.jsx`, `frontend/views.jsx` and `frontend/style.css` |
| Add a local demonstration | `DemoControlService`, `DemoController`, local/testing routes and Testing UI |

## Tests

The feature tests are grouped by behavior:

- `InventorySyncTest` — assignment sync rules and reconciliation edge cases;
- `ProviderLifecycleTest` — provider lifecycle and local restoration;
- `IndependentProviderTest` — separate provider state and outbox behavior;
- `RemoteProviderSyncTest` — HTTP adapter boundary, recovery fencing and Provider Lab;
- `EventSyncTest` — event ordering, duplicate/stale handling and shared metadata;
- `KafkaNotificationReceiverTest` — durable receipt, offsets and quarantine;
- `EmployeeTombstoneTest` — separate deleted-employee identity plus full, targeted and event-driven restoration;
- `PersistenceBoundaryTest` — rollback behavior behind repository contracts;
- `NumericStateTest` — stable numeric and legacy-event comparison after JSON/database round trips.

Run backend tests from `backend` and the frontend production build from `frontend`. The exact commands and current results are in the main README.
