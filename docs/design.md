# Final state-carrying event solution

## Scope and architecture

Deliver Laravel + React + MySQL in Docker Compose. Full sync reads the original JSON directly and initially produces 7 assets / 6 employees. JamfSyncService is our Laravel class, not external Jamf. A hypothetical complete-state provider is demonstrated by a source-file simulator. No actual Jamf webhook behavior is asserted.

```mermaid
flowchart TD
    React[React UI] -->|HTTP| API[Laravel controllers]
    API --> Full[JamfSyncService full sync]
    Full --> Adapter[DeviceProvider / Jamf mock adapter]
    Adapter --> File[Original JSON]
    API --> Simulator[Semantic change simulator]
    Simulator --> Adapter
    Simulator --> Baseline[Durable producer baseline]
    Simulator --> Inbox[Complete-state durable inbox / outbox]
    Inbox --> Queue[Database queue]
    Queue --> Worker[ProcessDeviceEvent / EventProcessor]
    Worker --> Apply[Apply stored payload and version policy]
    Apply --> Reconcile[DeviceReconciler]
    Full --> Reconcile
    Reconcile --> Repositories[Persistence contracts / Eloquent repositories]
    Repositories --> DB[(MySQL)]
```

The worker does not connect to the provider/file. Controllers validate HTTP boundaries; services own sync policy; repositories own persistence; TransactionBoundary owns locked transactions. No generic repository base or Kafka/gRPC is required.

## Models

| Entity | Key fields and purpose |
|---|---|
| Employee | Internal ID; normalized unique email; name/phone |
| DeviceModel | Shared manufacturer + model identifier uniqueness; model name |
| Asset | Internal ID; unique serial; nullable employee/model FKs; device name, provider/device ID, purchase and report provenance |
| AssetAttributes | Unique asset FK with cascade; individual RAM/storage/processor/cores/battery |
| SyncRun | Mode/status/times/counters/error diagnostics |
| RestoreTask | Source identity and deleted time retained independently; pending/restored/missing/unassigned outcome |
| EventInbox | Unique event ID and provider/device/version; complete payload, content hash, generation, status/attempts/outcome |
| SimulatedProviderDevice | Durable per-device source baseline, semantic fingerprint, version/generation, last complete state, removal tombstone |
| DeviceEventState | Applied per-device version/generation and state; survives asset deletion |
| SharedEventState | Employee/model identity, latest generation and metadata fingerprint |
| SimulatorGeneration | Monotonic provider-wide producer generation |
| SyncLock | Permanent provider row; database transaction owns lock lifetime |

```mermaid
erDiagram
    EMPLOYEE o|--o{ ASSET : assigned
    DEVICE_MODEL o|--o{ ASSET : describes
    ASSET ||--|| ASSET_ATTRIBUTE : specifications
```

No employee-assets join table is required for one current assignee. History and multi-tenant identities are future scope. Device-model descriptions are not assumed immutable. Individual specifications can differ/change.

## Provider and event contracts

DeviceProvider exposes complete snapshot and targeted identity lookup for future adapters. JamfMockProvider validates the envelope and maps to DeviceRecord. Full sync/restoration use it; queued payload processing does not.

DeviceEvent contains event ID, provider-device ID, positive per-device version, producer_generation, lifecycle type and complete normalized device state. Complete state includes serial, nullable email, employee metadata, nullable shared model, asset metadata and specifications. All mapped keys must be present, with explicit nulls. Report date travels as provenance when a semantic change emits.

Lifecycle types: added, changed, unassigned, removed. Unassigned has null email; removed carries last-known complete state and serial. This is our simulator schema. External provider versions/authentication must be verified before a real integration.

## Producer algorithm

POST /api/simulator/jamf reads and validates the file, then compares normalized application-relevant state with the durable baseline under a provider lock. Initial simulation emits seven additions. Unchanged scans emit zero. ReportDate and lastContactTime alone do not trigger events. A real change emits even with unchanged reportDate; a changed event carries current report date. Source identity gaps fail simulation instead of inventing removal.

New/changed/unassigned/removed/reappearing records receive increasing device versions. One semantic batch gets a producer-global generation. Baseline/tombstones and complete inbox/outbox records commit atomically. Dispatch happens after commit; a pending-inbox recovery sweep closes dispatch gaps. A failure writing the outbox rolls back the baseline and generation too.

## Consumer ordering and transactions

Receipt validates the entire message and persists before 202. Exact event replay is safe; reused event ID or device/version with different content is 409. Worker decodes only stored payload. Older versions/generations complete as ignored. Equal applied versions with conflicting state fail. Newer state is reconciled and watermark/tombstone committed atomically with inbox success.

Removal validates serial/provider identity before deleting, preventing an ID from deleting a different asset. Removed/unassigned tombstones survive deletion, blocking stale resurrection. A newer reassignment/readdition can create the asset. Shared employee/model metadata has separate generation guards: older other-device events cannot regress it, and equal-generation conflicting metadata fails.

Full sync remains authoritative. It silently seeds/advances the simulator baseline and consumer watermarks in its reconciliation transaction, fencing obsolete queued messages. Thus unchanged simulation after full sync emits zero. Targeted local restoration is independent of provider changes; local deletion never advances producer state.

TransactionBoundary uses the same database connection and permanent lock row for nested receipt/full/worker/delete operations. No TTL can expire while reconciliation is active. Nested failure rolls back device/watermark/inbox changes; diagnostics persist in a separate locked transaction. Failed runs remain visible. Deadlock retries initialize counters per attempt.

## Chosen sync policies

- Only assigned source devices are imported. Existing unassigned or missing devices are removed locally after a validated complete snapshot; employees remain.
- Employee deletion sets related assets' employee FK null. Full sync recreates the employee and assignment.
- Local asset deletion cascades attributes and atomically stores a restore task; full sync or explicit targeted restoration recreates an assigned source device.
- Invalid/truncated snapshots, duplicate serial/provider IDs, conflicting existing identity or invalid nonempty email fail before destructive writes. Missing serial rows during full sync preserve known provider identities.
- Explicit null clears nullable mapped values; missing optional fields preserve during full sync where supported. Events carry complete values. Source shared metadata uses deterministic lexicographic provider-ID precedence with warnings.
- Asset counters track asset/specification persistence changes; shared employee/model updates are separate data. RAM conversion follows MB / 1024; storage absent in the original fixture stays unknown.

These are documented implementation choices, not pending approval gates.

## Persistence boundary and schema

Contracts live in app/Contracts/Persistence; Eloquent implementations in app/Repositories/Eloquent. AssetRepository owns the asset/specifications aggregate; separate repositories own employees, models, runs, restorations, inbox/state and locks. EventStateRepository stores baseline and watermarks but does not choose version policy. Policy stays in simulator/consumer/shared-metadata services.

Services/controllers/jobs do not invoke Eloquent queries or model save/update/delete methods. Contracts pragmatically return Eloquent records and Laravel collections/paginators, so a future ORM replacement still needs DTO migration. Schema remains in database/migrations. Laravel's framework queue persistence is unchanged.

## API and UI

Assets list/details, employee list with asset links, local delete confirmations, full Sync Now, activity outcomes, explicit restoration and retry. Activity's Scan source and queue changes calls POST /api/simulator/jamf; it does not run full sync automatically. GET /api/events shows versions/generations/outcomes. POST /api/events/jamf accepts complete state, and POST /api/events/{id}/retry requeues failures.

## Verification and boundaries

Substantive tests cover required sync behavior, source-free worker/retry with broken file, ignored timestamps, same-timestamp hardware updates, duplicate/version conflicts, tombstones, shared ordering, full-sync fencing, outbox/baseline atomic rollback and acknowledgment rollback. Frontend build and Docker/MySQL runtime checks are reported with exact measured counts in README/delivery.

Future work: verified Jamf complete-event semantics/authentication, tenant scope, scheduled authoritative reconciliation, retention/stale-run cleanup and operational monitoring. No real webhook integration or provider sequence guarantee is invented. Legacy ID-only pending records fail validation visibly. Kafka/serverless are deployment options beyond this local demonstration.
