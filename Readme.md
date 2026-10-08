# MDM inventory case study

## What the application does

The application keeps a local inventory of assigned devices and employees. Laravel handles imports and synchronization, MySQL stores the data, and React displays it.

I chose to enhance the sample Jamf provider so it creates an event when a device is added, changed, unassigned or removed. Laravel processes each event using the device data stored in that event. It does not fetch the whole fleet for every change.

This enhancement is implemented in the mock provider. The provider and inventory backend run as separate services with separate MySQL databases. They share Laravel source code, but expose different routes and use different database credentials. Kafka carries their device events. A real Jamf integration would need an adapter for its actual API and webhook contract.

## Run the application

With Docker Desktop running:

```sh
docker compose up --build
```

- UI: http://localhost:5173
- Asset API: http://localhost:8000/api/assets
- Mock provider API: http://localhost:8002/api/provider/snapshot
- Startup loads the supplied JSON into the provider store once. The worker imports seven assigned assets and six employees.
- **Sync Now** reads the current provider store and reconciles the local inventory.
- **Import source JSON** reads the supplied file and updates both stores. This can overwrite provider API changes.
- **Activity** shows events, results, retries and restoration actions.

Docker runs React, the inventory API, inventory MySQL, the provider API, provider MySQL, an outbox publisher, Kafka, a Kafka consumer and an inventory job worker. Separate named volumes retain both databases and Kafka records. `docker compose down` stops the application; adding `-v` deletes those volumes.

The Compose project is now `mdm-kafka-demo`; it uses new volumes. Earlier `mdm-event-demo` data is not migrated automatically. Stop the older demo before starting this one if its ports are occupied.

See the [assignment](docs/assignment.md), [detailed design](docs/design.md) and [database migrations](backend/database/migrations).

## Solutions considered

| Solution | Why I considered it | Decision |
|---|---|---|
| Full synchronization | Simple; finds additions, edits and removals | Keep for initial import and recovery. Reading every device for every update repeats work |
| Scheduled full polling | Simple to run periodically | Same full-read cost; shorter intervals increase that cost |
| Filter by reportDate | Could fetch only changed records | The sample does not guarantee that every relevant change updates this date, or explain how to find removals |
| Filter by increasing device ID | Could find new records | Misses edits and removals of existing records |
| Filter by purchase date | Field is present in the response | Purchase date does not track later changes |
| ID-only event, then GET that device | Small notification; individual lookup | Valid alternative, but adds a provider request and requires the provider to be available |
| Event containing current device data | Worker can process it without a provider request | Chosen. Requires persistent events, duplicate handling and ordering |
| Event containing only changed fields | Smaller payload | More complex merging and ordering. PATCH accepts partial changes, but emitted events contain complete relevant device data |
| Batch requests or version/hash lists | Reduce requests or reconciliation traffic | Possible future enhancement; still needs a way to detect changes |
| Kafka between services | Independent consumers, burst buffering and retained replay | Implemented to demonstrate service separation. The annual traffic estimate alone does not require it; it adds infrastructure |
| gRPC | Typed communication between services | Does not solve persistence or change detection. Use HTTP APIs and queued jobs |

Full reconciliation remains necessary for recovery. It is manual in this implementation.

## Back-of-the-envelope calculations

These are planning assumptions, not performance measurements:

- 100 customers, each with 10,000 to 1,000,000 employees.
- One device per employee: 1 million to 100 million devices.
- 10% annual additions or departures, plus 1% device replacements.
- Count one event per affected device: 11% of the fleet per year. If replacement creates a removal and an addition, use 12%.
- Use 2 KB for a device event and 250 bytes for an ID-only notification.
- Use decimal GB and TB.

These assumptions exclude assignment edits, hardware changes, retries and bursts. Net employee growth does not tell us the total number of device changes.

`annual events = total devices × 11%`

| Total devices | Events/year | Average events/second | Device-event traffic/year | ID-only traffic/year |
|---|---:|---:|---:|---:|
| 1 million | 110,000 | 0.0035 | 0.22 GB | 0.0275 GB |
| 100 million | 11 million | 0.35 | 22 GB | 2.75 GB |

At 2 KB per device, one full import is 2 GB to 200 GB. A daily full import transfers about 730 GB to 73 TB per year, including unchanged devices.

Complete device events add 0.1925 GB to 19.25 GB per year over ID-only notifications. With 30 days of retention and three copies, the additional stored payload is about 0.047 GB to 4.75 GB:

`extra storage = annual events × (2000 − 250) × 30/365 × 3`

Three copies are an estimate, not the current deployment configuration. These figures exclude database indexes, backups and row overhead. ID-only events also require GET responses, so their notification size is not their total network cost.

If every device sends an event daily, traffic rises to 730 GB to 73 TB per year, averaging 12 to 1,157 events per second. Retaining those payloads for 30 days with three copies takes about 180 GB to 18 TB. This is why routine contact timestamps must not create events.

Shared model data also reduces repetition. For example, 80 bytes of model description copied into 1 million to 100 million assets uses 80 MB to 8 GB. Storing 5,000 model descriptions once uses about 0.4 MB, plus asset foreign keys. Device-specific specifications still belong to each asset.

## Why I chose device events

Device events avoid routine full-fleet reads and an extra GET for each change. Under the assumed change rate, the additional payload storage is small compared with daily full imports. A worker can also finish processing stored events while the provider is unavailable.

The trade-off is more work to persist events and handle duplicates, failures and ordering. Those protections are implemented and tested. Kafka separates delivery from inventory processing, buffers bursts and permits replay within retention. It does not increase MySQL write capacity; workers and database capacity determine how quickly a backlog clears.

The provider outbox and Kafka have different jobs. The outbox makes a device change and its notification atomic in the provider database. Kafka transports the saved events. Direct HTTP delivery from an outbox would be simpler for one consumer; Kafka is included here to demonstrate the separate stream and recovery behaviour we discussed.

Individual GET is used to restore a locally deleted asset. Full synchronization is used for initial import and recovery. If a real provider only supplies ID notifications, its adapter would fetch the affected device instead.

## Design

The normal update flow is:

```text
Provider POST / PATCH / DELETE
  → Save provider data and outbox event in one provider transaction
  → Publisher sends to Kafka and waits for acknowledgment
  → Consumer saves event in the inventory inbox
  → Consumer commits Kafka offset after durable acceptance
  → Queue an inventory job
  → Worker reads the stored event
  → Apply inventory changes and record the processed version
  → React reads the inventory through Laravel APIs
```

The code uses:

- **Provider adapters** to map JSON and HTTP provider data into a common device format. The HTTP adapter also preserves provider-owned versions for recovery.
- **Repository interfaces** for database access. Eloquent implementations contain the queries and writes; migrations are in backend/database/migrations.
- **Application services** to decide how imports, events, deletion and restoration change the inventory.
- **Dependency injection** to bind interfaces to implementations and substitute them in tests.
- **Transactions** to save related changes together.
- **Event IDs and versions** to handle repeated or older messages.

The services still use some Eloquent models and Laravel collections. The repository layer separates database queries, but the application is not fully independent of Laravel.

A database row lock serializes provider mutations. A separate inventory lock coordinates inventory processing and recovery. Neither service locks or writes the other's database. Device-level locks can reduce contention, but do not replace transactions: locks coordinate concurrent updates; transactions save related writes together. Shared employee/model changes also need coordination.

Only Jamf is implemented. Provider interfaces allow another adapter, but provider selection and some services still assume Jamf. Supporting several providers simultaneously requires a registry and removal of those assumptions.

### Partitioning by company

For a larger deployment, use company_id to separate each customer's inventory, events, provider connections and sync state. Employee emails and provider device IDs must be scoped to the company; duplicate checks must not mix customers.

Start with company-scoped processing and locks so one company's sync does not block every customer. If database capacity becomes a limit, route each company to a database shard. Several companies can share a shard; a separate database per company is not required.

Assign worker capacity by company or company group. When company scoping is introduced, include company and device identity in the Kafka key. The current single-company demo keys by Jamf and device ID. Kafka partitions and database shards are separate choices.

Company-based routing spreads work, but a large company can still overload one shard. That company may need device-based processing partitions and coordinated updates to shared employee/model data. Measure peak traffic, database load and processing delay before choosing shard counts, partition counts or worker capacity.

This is a scaling consideration. Company scoping and database sharding are not implemented. Kafka transport is implemented.

## Event persistence and failure handling

The main risk is updating a device and losing its notification before the inventory receives it. The provider always writes an outbox event in the device transaction. Kafka publication happens afterward. Publishing to Kafka first and falling back to SQL is unsafe: Kafka could accept an event and the SQL commit could then fail.

| Risk | Implemented protection |
|---|---|
| Provider data saves but the event does not | Device and outbox writes share a transaction; failure rolls back both and the request fails |
| Kafka is unavailable | Provider commits to its own durable outbox; publisher retains pending records and retries |
| Publisher crashes after Kafka accepts a message | The outbox can send it again; inventory deduplicates by event ID/version |
| Consumer stops before acknowledging Kafka | Kafka redelivers; acknowledgment follows durable inbox acceptance |
| Application stops before the inventory job is queued | Event remains pending in the inbox; the worker recovers it |
| Invalid or conflicting Kafka message | Save its payload/error/offset in rejected_notifications before acknowledging; database failures are not acknowledged |
| Worker fails during an update | Inventory changes, processed version and completion status roll back together |
| Event is delivered twice | Event ID and device version identify duplicates; conflicting content is rejected |
| Older event arrives later | Stored versions prevent it from overwriting newer data |
| Events update shared employee/model data out of order | Shared-data ordering checks prevent older values from replacing newer ones |
| Old event arrives after restoration or full sync | Recovery records the current source version so older messages are ignored |
| Container restarts | Persistent MySQL volume retains events and processing state |

Delivery can happen more than once. Processing is designed to make repeated delivery safe. HTTP 202 means the event was saved, not that processing has finished. Failed events remain available for retry.

The provider transaction ends once its device and outbox records commit. HTTP success means the change and notification are saved; it does not mean Kafka delivery or inventory processing has finished. Kafka and MySQL do not share a transaction. Kafka transactions cover Kafka records and offsets, not this provider's database writes.

The publisher marks an outbox event published only after broker acknowledgment. Kafka uses three partitions and device-based keys. The local broker has a persistent volume and seven-day retention, but only one broker and one replica: this is not high availability. Events remain in Kafka after consumption and can be replayed within retention; they are not kept forever.

Backups, automatic SQL event retention, monitoring and quarantine replay tools remain production work. Deleting or losing a volume loses its data. Completed queue jobs are removed, but inbox/outbox records currently have no automatic cleanup. An interrupted sync can also leave its history marked in progress.

## Data models

| Entity | What it stores |
|---|---|
| Employee | Internal ID, unique email, name, phone and deletion time |
| Asset | Unique serial, provider, provider device ID, employee/model references, device name, UDID and purchase/report dates |
| DeviceModel | Shared manufacturer, model identifier and model name |
| AssetAttributes | Per-asset RAM, storage, processor, core count and battery condition |
| RestoreTask | Deleted asset's provider ID and serial, restoration status and attempts |
| SyncRun | Sync start/end times, status, counts and error |
| ProviderDevice | Current provider-owned device state, version and removal tombstone |
| ProducerOutbox | Provider-owned outgoing event, publication status, attempts and error |
| Provider generation | Provider-owned sequence and mutation lock |
| EventInbox | Stored event payload, status, outcome, attempts and error |
| DeviceEventState | Last processed device version and state; retained after asset deletion |
| SharedEventState | Ordering information for shared employee/model updates |
| SyncLock | Database row used to coordinate provider operations |
| Jobs / failed jobs | Laravel queue records |
| Rejected notifications | Invalid/conflicting Kafka records retained for investigation |

Provider schema is tracked in backend/database/provider-migrations. Inventory schema is tracked in backend/database/migrations. Earlier local provider tables remain in the inventory migration history for compatibility tests; the separated runtime does not use them as source state.

One employee can have several assets. Each asset has one current employee, so a separate EmployeeAssets table is not needed. The sample employee with two devices has two asset rows referencing the same employee.

Email is the employee business identifier; an internal ID links the tables. Serial identifies the physical asset. Provider device ID identifies the upstream record for lookups and events.

DeviceModel is unique by manufacturer and model identifier. AssetAttributes remains separate because devices of the same model can have different RAM, storage or other specifications. Model identifier does not imply immutable hardware.

## Change detection

Events are `device.added`, `device.changed`, `device.unassigned` or `device.removed`. Each contains an event ID, provider device ID, device version, provider-wide sequence and current normalized device data. Removal events currently retain the last known device data.

PATCH accepts partial input. Omitted fields keep their values; explicit null clears supported nullable fields. The resulting event contains complete relevant state.

- `general.lastContactTime` does not trigger events.
- Report date is stored as metadata but does not trigger events by itself.
- A RAM or assignment change triggers an event even if report date stays unchanged.
- Purchase date, JSON order and increasing IDs are not sync checkpoints.
- Reusing an event ID or device version with different content returns HTTP 409.

The provider store, outbox, Kafka and inventory inbox/checkpoint contain some repeated device data for delivery, replay and ordering. Their retention must be planned separately at scale.

## API endpoints

Provider endpoints change the simulated source. Inventory endpoints manage local records.

| Method | Endpoint | Purpose |
|---|---|---|
| GET | /api/provider/devices/{providerId} | Provider port 8002: device envelope; removed IDs return retained tombstones, unknown IDs return 404 |
| POST | /api/provider/devices | Provider: create from complete normalized device data |
| PATCH | /api/provider/devices/{providerId} | Provider: update supplied fields; serial cannot change |
| DELETE | /api/provider/devices/{providerId} | Provider: retain removal state and create outbox event |
| POST | /api/provider/seed | Provider: initialize once |
| GET | /api/provider/snapshot | Provider: complete versioned state, including tombstones |
| POST | /api/provider/import | Provider: accept a complete raw JSON response |
| POST | /api/provider/scan | Provider: scan mounted JSON and persist changes/events |
| GET | /api/assets | List local assets with pagination |
| GET | /api/assets/{id} | Get asset details |
| GET | /api/employees | List active employees and their assets |
| DELETE | /api/assets/{id} | Delete a local asset and retain restoration details |
| DELETE | /api/employees/{id} | Mark an employee deleted and retain linked assets |
| POST | /api/sync/provider | Reconcile inventory with the current provider store |
| POST | /api/sync | Import the supplied JSON into provider and inventory stores |
| POST | /api/simulator/jamf | Scan JSON differences and queue events |
| POST | /api/restorations | Restore eligible locally deleted assets using individual lookups |
| GET | /api/sync-runs | Get recent sync results |
| POST | /api/events/jamf | Save a device event for processing |
| GET | /api/events | Get event processing results |
| POST | /api/events/{id}/retry | Retry a failed event |

Inventory endpoints use port 8000. Provider endpoints use port 8002 and are absent from the inventory API. Restoration and reconciliation use the HTTP adapter, not direct provider database access. The local UI does not provide manual asset creation or editing.

## Workflows

### Add or update a provider device

1. Call provider POST or PATCH.
2. Save the changed provider data and outbox event together.
3. Publish to Kafka, then persist in the inventory inbox and queue processing.
4. Worker applies the stored device data and records its version.
5. Refresh the UI to see the result.

For example, update RAM:

```sh
curl -X PATCH -H "Accept: application/json" -H "Content-Type: application/json" -d '{"specifications":{"ram_gb":64}}' http://localhost:8002/api/provider/devices/101
```

The worker uses the event payload. It does not read the JSON file or fetch the device again.

### Delete and restore a local asset

1. Save its provider ID and serial in RestoreTask, then delete the local asset and attributes.
2. Keep the employee and provider device.
3. Run restoration and look up only that provider ID.
4. Restore it if it still exists and is assigned.
5. Record the current source version so older queued updates cannot overwrite the result.

Removed, unassigned or unknown provider devices are not restored. An unchanged file scan does not restore local deletions. Restoration is explicit; full reconciliation also restores eligible assigned devices.

### Delete an employee

Retain the employee ID, email and deletion time, and clear name/phone. Keep the assets and display **Deleted user (email)**. Hide that employee from the active employee list.

A later source reconciliation or relevant event can reactivate the same employee.

This uses soft deletion to preserve the identity and asset links we chose in the design discussion. The assignment says local deletion; if interpreted as physical row deletion, the identity would need a separate retained record instead.

### Remove or unassign a provider device

Create a removal or unassignment event. Processing removes the local asset because this application imports assigned devices. The employee is not deleted.

### Import or scan the JSON

The supplied JSON represents provider output. If device records disagree on shared employee/model descriptions, sorted provider-ID precedence supplies a deterministic value and the mapper records a warning. This is an explicit conflict policy, not a Jamf guarantee.

- **Import source JSON** makes the backend read the full file directly, sends it to the provider import API, then applies returned provider versions to inventory.
- **Scan source and queue changes** asks the provider to scan its mounted file and persist meaningful differences in its outbox.
- Editing the file alone does not update provider GET responses; import or scan first.
- Queued events keep their saved payload even if the file later changes.

The two databases cannot commit a full import together. If provider import succeeds but inventory reconciliation fails, retry reconciliation against current provider state. Both event processing and recovery use provider-owned versions, so older queued events cannot reverse the recovered state.

Removing assets because they are missing from a response requires a complete snapshot. The fixture checks totalCount against results. A real paginated adapter must read every page before treating absence as removal. A changes-only response cannot be used for this cleanup.

## Verification

Passed: 56 backend tests with 341 assertions, and the React production build.

Tests cover provider changes, event persistence, retries, duplicate/stale messages, timestamp suppression, partial fields, restoration and employee deletion. Docker/MySQL checks also verified provider CRUD, queued processing, restoration with older pending events and employee reactivation.

Kafka integration checks verified initial import, provider create/update/remove, a broker outage leaving a pending outbox record, recovery after restart, timestamp-only suppression, targeted restoration while older events waited, and successful JSON import/current-provider reconciliation. MySQL's JSON numeric round-trip is normalized so 16 and 16.0 do not create false conflicts.

A UX agent tested Assets, Details, Employees, Activity, both sync actions, asset deletion/restoration and employee deletion/reactivation through the browser. No browser console errors were observed. Multi-page pagination, mobile and screen-reader testing remain unchecked.

```sh
cd backend
composer install
php vendor/phpunit/phpunit/phpunit
```

Host tests require PHP 8.3+ and its required extensions. Frontend check:

```sh
cd frontend
npm ci
npm run build
```

## Production work

Before using this with real customers, add provider authentication, validate the real API/event contract, scope identities by tenant, benchmark processing, schedule reconciliation, define retention, add monitoring and test database backups.

The current implementation is a local case study with separate provider/inventory services, separate databases, a provider outbox, Kafka, an inventory inbox/job queue and manual recovery actions. Company sharding and multiple providers remain future work.

## Outbox delivery controls

Activity separates Delivery to Kafka from Inventory processing. Waiting for Kafka means the provider outbox event has not received broker acknowledgment. Published to Kafka does not mean the inventory worker has finished. Each notification shows its device name, delivery attempts and last delivery error.

Retry pending delivery immediately attempts up to ten pending outbox notifications through the provider API. Published notifications are not resent. Automatic delivery retries continue in the background. All events use the outbox, including when Kafka is healthy; it is not only an outage fallback. If the provider cannot be reached, inventory remains usable and Activity reports the unavailable outbox.

Provider endpoints: GET /api/provider/outbox and POST /api/provider/outbox/retry on port 8002. Inventory proxies: GET /api/provider-outbox and POST /api/provider-outbox/retry on port 8000. These unauthenticated controls are for the local demonstration; production requires restricted access.

The pending-details navigation issue is deferred in docs/backlog.md at the user's request.

The new outbox controls passed API outage/retry checks and the frontend build. Fresh browser verification of those controls was blocked by an unavailable browser connection.
