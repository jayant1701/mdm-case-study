# MDM inventory case study

This application imports assigned devices and their employees into a local inventory. Laravel provides the backend, React displays the inventory, and MySQL stores both inventory and synchronization state.

I chose an enhanced mock Jamf provider that emits durable, versioned device events. Routine updates process only affected devices. Full synchronization remains available for initial import and recovery, and local asset restoration uses an individual provider lookup.

The enhancement is a contract I designed for this case study, not a claim that real Jamf supports this exact event payload. The original JSON and reference service remain preserved. The mock provider and inventory application are separate logical modules hosted in one Laravel process and MySQL database for a simple local deployment.

## Start and review

With Docker Desktop running:

```sh
docker compose up --build
```

- UI: http://localhost:5173
- API: http://localhost:8000/api/assets
- Startup runs migrations and seeds the provider once from the supplied JSON. The worker imports the seven assigned assets and six employees asynchronously.
- **Sync Now** reconciles the current provider store.
- **Import source JSON** explicitly reads the supplied file and refreshes provider and inventory state. It can overwrite changes made through provider APIs.
- **Activity** shows notification outcomes, source scans, restoration and retry actions.

Compose starts frontend, backend, MySQL and a Laravel database-queue worker. MySQL uses a persistent named volume. Ports bind to localhost; credentials are for local development. Stop with `docker compose down`. Using `docker compose down -v` also deletes the database volume and its inventory, events and checkpoints.

Original requirements: [assignment](docs/assignment.md). Additional architecture detail: [design](docs/design.md). Schema history: [migrations](backend/database/migrations).

## Alternatives and the decision

| Approach considered | Benefit | Trade-off and decision |
|---|---|---|
| Full JSON or API snapshot on every sync | Simple; detects arbitrary edits, missing devices and local deletions | Reads and compares the entire fleet. Implemented for the assignment and recovery, not the preferred routine update path |
| Periodic polling of the same full snapshot | Easy to schedule | Poll frequency trades freshness against repeated work; scheduling does not solve full-read cost |
| Incremental filtering by reportDate | Could retrieve fewer records | Requires a guarantee that every relevant change advances the field, supported filters, pagination boundaries and deletion discovery. Not assumed from this JSON |
| Incremental filtering by increasing device ID | Could identify additions under an explicit monotonic-ID contract | Misses edits, removals and restoration of locally deleted old IDs. Rejected as a general change checkpoint |
| Purchase date as a checkpoint | Available metadata | Describes purchase, not subsequent assignment or hardware changes. Rejected |
| ID-only notification followed by GET | Small event; fetches current authoritative state | Adds requests and depends on provider availability. A reasonable fallback for external providers with thin webhooks |
| Event carrying relevant complete device state | No follow-up fetch for routine updates; independent queued processing | Needs reliable event persistence, payload validation, ordering and replay handling. Selected |
| Partial-change events | Smaller messages | Missing vs null, merging and ordering are more complex. Provider PATCH accepts partial input, but emitted messages carry complete normalized state |
| Batch reads or version/hash manifests | Reduce round trips and reconciliation payloads | Useful future provider capabilities; batching does not itself provide reliable change detection |
| Kafka or managed queue with separate/serverless workers | Independent scaling, operational isolation and replay options | Adds infrastructure beyond the local assignment. The current demonstration uses a durable database queue |
| gRPC between services | Typed internal communication | Does not provide event durability or identify changes. HTTP APIs and asynchronous jobs suffice here |

Events do not replace reconciliation. Missed changes, operational errors or local deletion still require recovery. Reconciliation can later be partitioned, scheduled and assisted by provider version manifests. No automatic periodic schedule is implemented here.

## Back of the envelope capacity planning

These are estimates for deciding the architecture, not measured production benchmarks.

### Assumptions

- 100 customers with 10,000 to 1,000,000 employees each.
- One managed device per employee: **1 million to 100 million devices** in total.
- Annual organizational additions **or** departures of 10%, plus device replacement affecting 1% of the fleet.
- Initially count one event per affected device: 11% of fleet annually. A replacement represented as removal plus addition increases this simplified estimate to 12%.
- This excludes normal assignment edits, software/inventory reporting, hardware changes, retries and bursts. Net headcount growth alone does not measure total device activity.
- Compact supplied device records average approximately **660 bytes** across seven records. For planning use **2,000 bytes** for a complete relevant-state event including an envelope, versus **250 bytes** for an ID-only notification.
- Use decimal GB/TB. Retention illustrations assume uniform arrivals, 30 days and three retained copies; this is not the current deployment's replication configuration.

### Fleet and event traffic

`annual events = fleet × 0.11`

`annual raw event bytes = annual events × bytes per event`

| Total devices | Annual events | Average events/second | Complete-state traffic/year | ID-only traffic/year |
|---|---:|---:|---:|---:|
| 1 million | 110,000 | 0.0035 | 0.22 GB | 0.0275 GB |
| 100 million | 11 million | 0.35 | 22 GB | 2.75 GB |

Those averages are small; capacity must account for initial import and concentrated updates. One initial full response at 2 KB/device is approximately **2 GB to 200 GB**, excluding protocol and database overhead. Repeating that daily would transfer approximately **730 GB to 73 TB/year** even when the inventory is unchanged.

If each device instead emits one event per day, complete-state events also generate **730 GB to 73 TB/year**, averaging roughly **12 to 1,157 events/second**. Events are efficient only when they represent meaningful changes rather than routine heartbeat noise. This is why timestamp-only updates do not emit notifications.

The fleet size alone does not justify a specific broker. Actual change rate, burst size, freshness needs, availability requirements and retention determine production capacity.

### Redundancy and retained storage

At the simplified 11% annual change rate, complete payloads add about **0.1925 GB to 19.25 GB/year** over ID-only notifications. With 30-day retention and three copies, the extra retained raw payload is approximately **0.047 GB to 4.75 GB**:

`extra retained bytes = annual events × (2000 − 250) × 30/365 × 3`

Under daily-per-device events, the complete payload alone needs roughly **180 GB to 18 TB** for 30 days and three copies. These figures exclude indexes, row overhead, backups, failures and compression. Smaller notifications also cause device GET responses, so notification size alone does not measure total network cost.

Our implementation stores device state in the provider store, producer baseline and consumer checkpoint, as well as retained event payloads. This is deliberate recovery/ordering state, but it needs retention and compaction policies at scale. Successful queue jobs are removed; the application event inbox currently has no automatic retention cleanup. It would otherwise grow indefinitely.

Model normalization avoids unnecessary description duplication. As an illustration, assume 80 bytes of shared model description and 5,000 distinct models: copying descriptions into every asset costs about **80 MB to 8 GB** for this fleet range, versus about **0.4 MB** of shared descriptions plus foreign keys. Actual MySQL storage depends on field lengths and indexes. Mutable RAM, storage and processor configuration remain per asset; model identity does not guarantee identical hardware.

### Why I recommend complete-state events

The selected approach avoids routine full-fleet retrieval and follow-up GETs for each update. The payload cost is modest under the low-change estimate, and the consumer can process queued updates while provider lookup is unavailable. The price is additional persistence and ordering complexity, which is explicit and tested. Targeted GET is reserved for local restoration; full reconciliation is a recovery operation.

For a real Jamf integration, the adapter must accommodate the actual webhook/API contract. If only IDs are delivered, an ID-notification plus individual/batch lookup remains a valid alternative. I would not invent complete payloads or provider sequence guarantees.

## Architecture and design patterns

```text
Mock provider GET / POST / PATCH / DELETE
    → Provider repository
    → Atomic provider state + durable notification
    → Queue dispatch after commit
    → Worker loads stored device payload
    → Sync/reconciliation services
    → Inventory repository interfaces
    → Eloquent implementations
    → MySQL inventory
    → Laravel HTTP APIs
    → React

Local asset deletion
    → RestoreTask with provider ID and serial
    → Targeted provider adapter lookup
    → Checkpoint-fenced restoration
```

- **Adapter:** `DeviceProvider` reads/maps the fixture; `TargetedDeviceProvider` maps indexed provider records. Provider-specific data becomes a common device representation.
- **Repository:** services/controllers depend on persistence contracts. Eloquent implementations own queries and writes; the asset/specifications aggregate persists together.
- **Dependency injection:** Laravel binds contracts to implementations, allowing meaningful substitutes in tests.
- **Application services:** reconciliation determines what changes; repositories determine how it is stored.
- **Durable inbox/outbox:** producer changes and notification rows commit together; jobs reference those durable rows.
- **Transactions and provider lock:** a permanent transaction-scoped row lock serializes provider changes, sync, event processing and local deletion. There is no expiring lock TTL.
- **Idempotent consumer:** duplicate IDs and stale versions do not repeat inventory changes.

This is SOLID-aligned rather than completely framework-independent. Contracts still return some Eloquent models and Laravel collections/paginators. The demonstration shares one database/process, so source state and inbox can commit atomically. Separate deployments would require a producer-owned outbox and retrying delivery to a consumer-owned inbox; an HTTP POST alone is not atomic with the producer database.

The provider-wide lock favors simple correctness in this local setup. It serializes processing and is not a proven strategy for the upper fleet range. Production could partition by tenant/device while coordinating shared metadata and recovery. Multi-tenant identity scoping is not implemented.

## Event persistence risks and mitigation

| Failure or risk | Current mitigation | Remaining production work |
|---|---|---|
| Provider writes succeed but notification storage fails | Provider state and event row share one transaction; both roll back | Separate producer outbox if services/databases split |
| Crash after commit but before enqueue | Event remains pending; worker runs `events:recover` to redispatch | Monitor pending age and dispatch backlog |
| Worker fails mid-update | Inventory, checkpoint and success acknowledgment roll back together | Monitor retries and diagnose persistent failures |
| Duplicate delivery | Unique event ID/device-version and canonical content checks | Keep deduplication state for the chosen replay window |
| Older event arrives late | Per-device versions/generations; retained tombstones | Define genuine provider ordering/replay guarantees |
| Different devices update shared employee/model metadata out of order | Shared metadata generation guards | Partitioning and source-owned shared identities at scale |
| Old queued update arrives after restoration/full sync | Restoration and reconciliation advance checkpoint fences atomically | Recovery run observability |
| Container restarts | MySQL persistent volume retains events/state | Backups, replication, restore drills and infrastructure availability |
| Database volume is deleted or lost | No guarantee after volume loss | Durable backups and disaster recovery |
| Unbounded event/state growth | Explicitly recognized; cleanup not scheduled | Retention, archival, failed-event policy and safe watermark compaction |

HTTP 202 means the event was durably accepted, not that inventory processing finished. Dispatch failures leave pending inbox rows. The worker uses the database queue with up to three queue attempts; failed events remain visible and can be explicitly retried.

Transport is **at least once**, not exactly once. Transactions and idempotent processing make repeated delivery safe. Hard termination can leave historical SyncRun rows marked in progress; stale-run cleanup is future work.

## Data models

| Entity | Identity and relationships | Purpose |
|---|---|---|
| Employee | Internal ID; unique normalized email; nullable name/phone; deleted_at | One employee can have many assets. Deletion retains ID/email/time, clears name/phone, excludes active lists and preserves asset links |
| Asset | Internal ID; unique serial; provider + provider_device_id; employee/model foreign keys | A physical device with one current employee, device name, UDID and purchasing/report metadata |
| DeviceModel | Unique manufacturer + model_identifier; model name | Shared description, not a guarantee of immutable specifications |
| AssetAttributes | Unique asset_id; cascades with asset deletion | Per-asset RAM, storage, processor, cores and battery condition |
| RestoreTask | Provider + serial; retained provider_device_id; status/attempts/deleted_at | Independent local asset deletion record for targeted restoration |
| SyncRun | Provider/mode/status/start/end/counters/error | Execution history, not a provider change cursor |
| MockProviderDevice | Unique provider/device ID and provider/serial; normalized state | Current source data, logically separate from local assets |
| EventInbox | Unique event_id and provider/device/version; payload/status/outcome/attempts/error | Durable notification, local outbox/inbox and retry diagnostics |
| SimulatedProviderDevice | Provider/device ID; version/generation/fingerprint/state/device | Producer baseline for semantic differences |
| DeviceEventState | Provider/device ID; applied version/generation/state/device | Consumer checkpoint; survives asset deletion |
| SharedEventState | Provider/kind/identity; generation/fingerprint | Prevents shared employee/model metadata regression |
| SimulatorGeneration | Provider + counter | Producer-wide ordering generation |
| SyncLock | One permanent row per provider | Transaction-scoped serialization |
| Jobs / failed jobs | Laravel queue tables | Delivery attempts; separate from durable notification content |

No EmployeeAssets join table is needed for one current assignee per asset. Alex's two assets are represented by two asset rows referencing the same employee. Assignment history would justify a separate table later.

Serial identifies the asset for this assignment. Provider ID identifies the upstream record for lookup and events; it is not a change sequence. Internally generated numeric keys simplify relationships while email remains the employee business identity.

Local asset deletion does not delete its employee. Local employee deletion does not delete assets. Source unassignment/removal does remove the local asset because the application imports assigned source devices.

## Notification contract and update semantics

A notification envelope describes delivery and ordering; its device object carries complete normalized relevant state:

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

Types: `device.added`, `device.changed`, `device.unassigned`, `device.removed`. Unassigned has null email. This implementation's removal message retains last-known complete state, including identity, to validate deletion; a smaller identity-only removal schema is a future refinement.

Versions increase per device; producer_generation orders batches and shared metadata. They come from simulator state, not report timestamps. Identical replays are safe. Reusing an event ID or device version for different canonical content returns 409. Older versions/generations are ignored; conflicting applied versions fail.

PATCH accepts partial normalized state. Omitted fields preserve prior producer values; explicit null clears supported nullable fields. Presence-aware normalization applies the same rule to fixture import before creating complete events. Model descriptions are shared while actual configurations stay per asset.

`general.lastContactTime` is ignored for event detection. Report date is retained as metadata but excluded from semantic fingerprints. A report-date-only change emits no event; RAM or assignment edits emit even when the date stays unchanged. Purchase date, array order and increasing IDs are not used as change checkpoints.

## API endpoints

Provider CRUD below is a developer simulator API. It does not add manual create/edit to the local inventory UI.

| Method | Endpoint | Behavior |
|---|---|---|
| GET | `/api/provider/jamf/devices/{providerId}` | Indexed individual lookup; returns normalized versioned envelope; removed/unknown devices return 404 |
| POST | `/api/provider/jamf/devices` | Create/reintroduce provider device using a complete device object; returns 201 and notification summary |
| PATCH | `/api/provider/jamf/devices/{providerId}` | Partial normalized state; emits only a meaningful change; serial cannot change |
| DELETE | `/api/provider/jamf/devices/{providerId}` | Retain provider removal state and queue removal |
| POST | `/api/provider/jamf/seed` | Initialize/backfill once without resetting existing provider state |
| GET | `/api/assets` | Paginated local assets with employee/model/specifications |
| GET | `/api/assets/{id}` | Local asset details |
| GET | `/api/employees` | Active employees with assigned asset links |
| DELETE | `/api/assets/{id}` | Local asset deletion plus restoration identity; employee remains |
| DELETE | `/api/employees/{id}` | Retain identity tombstone and linked assets |
| POST | `/api/sync/provider` | Sync Now: reconcile complete current provider store |
| POST | `/api/sync` | Explicit full JSON import; refresh source and local inventory |
| POST | `/api/simulator/jamf` | Read mounted fixture differences, persist source/baseline/events and queue changes |
| POST | `/api/restorations` | Process pending local asset restoration tasks via individual lookups |
| GET | `/api/sync-runs` | Latest execution results |
| POST | `/api/events/jamf` | Accept complete versioned notification; returns 202 after persistence |
| GET | `/api/events` | Inbox status, ordering and processing outcome |
| POST | `/api/events/{id}/retry` | Requeue failed notification |

The restoration adapter performs indexed lookup in the mock provider module; it does not loop back over HTTP to its own deployment. A separately hosted provider would use an HTTP adapter behind the same boundary. Internal lookup can return a removal tombstone even when public GET returns 404, so restoration fences old updates.

## Workflows

### Provider changes

1. POST or PATCH writes provider data.
2. Relevant-state comparison decides whether to emit. The provider row and event commit together.
3. After commit, a job references the stored inbox ID.
4. Worker validates payload and ordering, reconciles inventory through repositories, advances checkpoints and marks success atomically.
5. Refresh inventory/activity to see the result.

Example individual GET and RAM update:

```sh
curl -H "Accept: application/json" http://localhost:8000/api/provider/jamf/devices/101
curl -X PATCH -H "Accept: application/json" -H "Content-Type: application/json" -d '{"specifications":{"ram_gb":64}}' http://localhost:8000/api/provider/jamf/devices/101
```

For POST, save the example envelope's `device` object as `new-device.json`, with a new provider ID and serial, then:

```sh
curl -X POST -H "Accept: application/json" -H "Content-Type: application/json" --data-binary @new-device.json http://localhost:8000/api/provider/jamf/devices
```

DELETE uses the provider URL with `-X DELETE`; it changes source truth and emits a removal. Local deletion uses the distinct `/api/assets/{localId}` endpoint.

### Local asset deletion and restoration

1. Retain provider ID and serial in RestoreTask, then delete the local asset/specifications atomically.
2. Provider and employee remain untouched.
3. Invoke restoration; lookup only that provider ID.
4. Existing assigned device is recreated; unassigned/removed/unknown source records are not imported.
5. Advance the checkpoint for current source state, including retained removal state, so older queued messages cannot undo recovery.

Unchanged scans do not automatically restore local deletion. Restoration is explicit; a complete reconciliation also recovers assigned records. Restoration records retain outcomes and attempts; synchronization results expose execution status and counters. Automatic repeated restoration scheduling is not implemented.

### Local employee deletion

1. Retain employee ID/email and deletion time; clear name/phone.
2. Keep every linked asset and display **Deleted user (email)** in asset lists/details.
3. Exclude the employee from the active Employees tab.
4. Source reconciliation or a relevant source event reactivates the same employee and assignments.

### Direct JSON import and simulated bulk changes

The backend reads `files/api-mock-response.json` directly, while React only uses HTTP APIs.

- **Import source JSON** applies the complete fixture and fences queued older messages.
- **Scan source and queue changes** compares fixture data with the durable producer baseline, emitting only semantic differences.
- An initial scan without baseline emits seven additions; a scan after full import emits zero when unchanged.
- Editing the file alone does not update indexed provider GET. Import or scan first.
- An event already queued retains its original payload even if the file changes later.

Absence reconciliation requires a complete authoritative snapshot. The fixture validates totalCount against results; this is a local contract assumption, not proof that an arbitrary external API page represents the entire fleet. A real paginated adapter must fetch/validate every page before absence cleanup. Never apply full-snapshot removal logic to a changes-only response.

## Verification

**34 backend tests / 230 assertions passed**, plus React production build. Tests use isolated temporary fixtures and SQLite. They cover provider lifecycle, atomic event persistence/rollback, source-free worker/retry, timestamp suppression, stale/duplicate conflicts, shared metadata ordering, missing vs null semantics, targeted restoration, removed-provider fencing, employee tombstones and required full import.

Docker/MySQL checks verified migrations and upgrade backfill; individual provider GET; POST creating an asset through the worker; meaningful PATCH emitting one event; timestamp-only PATCH emitting zero; DELETE removing the asset; restoration keeping RAM64 while older queued updates were ignored; employee deletion retaining assets/email and reconciliation reactivating that identity. The demo was returned to seven assets, six employees and original RAM16.

Final browser automation could not restart due to a local tool initialization failure. UI source and production build were checked; the final deleted-user flow was verified through API and tests, not claimed as a final browser run.

Run backend tests:

```sh
cd backend
composer install
php vendor/phpunit/phpunit/phpunit
```

PHP 8.3+ and required extensions are needed for host tests. Run frontend verification with `cd frontend && npm ci && npm run build`. Format with Laravel Pint and Prettier.

## Boundaries and next steps

Production work includes real provider authentication and contract validation, tenant identity scoping, partitioned processing, scheduled reconciliation, event retention/archival, backups and observability. Fleet estimates above do not establish benchmarked capacity.

No real Jamf service was changed. No Kafka, serverless deployment, gRPC or real webhook integration is required by this local demonstration. Legacy ID-only pending notifications fail validation rather than triggering hidden provider reads. GitHub publication and reviewer access remain submission steps.
