# MDM inventory — event-driven demonstration

Laravel 12 + React + MySQL, with a durable simulated-event inbox and database queue. This extends the required direct-file sync; it does not modify external Jamf or claim to implement real Jamf webhooks.

## Start

Install Docker Desktop with the engine running, then from this repository:

```sh
docker compose up --build
```

Open http://localhost:5173. Click **Sync Now** to import the supplied fixture: **7 assets and 6 employees**. Backend API: http://localhost:8000/api/assets. Compose starts frontend, backend, MySQL, and a queue worker. Backend startup runs migrations automatically; MySQL uses a persistent named volume. Ports bind to localhost. The checked-in development key and database passwords are for this local demonstration only.

To stop: `docker compose down`. To reset all local records: `docker compose down -v` (deletes the database volume).

The original assignment is preserved in [docs/assignment.md](docs/assignment.md). Design, models, decisions and future production architecture are in [docs/design.md](docs/design.md).

## Exercise the workflow

- Edit `files/api-mock-response.json` to simulate upstream changes; Laravel reads the mounted file on each sync, without rebuilding images. Keep `totalCount` equal to the number of `results`.
- Full sync creates missing records, updates changed attributes and assignments, and removes absent/unassigned devices according to the documented assumptions. Unchanged records do not count as updates.
- Employee lists show assigned assets with links to details. Local asset deletion also deletes its attributes and records an independent restore task. Employee deletion leaves assets temporarily unassigned. Full sync recreates source records and assignments.
- In **Activity**, enter a provider device ID (for example `101`) and send a simulated notification. The durable worker fetches its current source state; refresh activity to see success/failure. Both changed and removed notifications use current state, so stale notifications cannot delete a device still present.
- **Restore locally deleted assets** processes retained identities independently of provider events. Missing/unassigned upstream records are not recreated. Full sync also restores naturally.
- Failed events remain visible and can be retried after fixing the fixture. The worker retries exceptions up to three queue attempts; the UI exposes an explicit retry afterward.

The demo notification schema is separate from the unchanged inventory fixture:

```json
{"event_id":"unique-demo-id","provider_device_id":"101","type":"device.changed"}
```

POST it to `/api/events/jamf`. Supported types are `device.changed` and `device.removed`. Reusing an ID with different content returns 409. Receipt stores an inbox record before returning 202; if dispatch fails, the worker's `events:recover` sweep re-dispatches pending records. Duplicate jobs are safe. There is no webhook authentication in this localhost-only demo.

## Design decisions

- A provider interface separates Jamf mapping from transactional reconciliation; controllers stay thin. Eloquent handles persistence directly without unnecessary repository wrappers.
- Employee email and asset serial are normalized unique business keys, with internal IDs as primary keys. Device models share manufacturer/model identifier/name; each asset has separate mutable RAM/storage/processor/core/battery attributes. No assignment join table: an asset has one current employee.
- MySQL enforces foreign keys and unique constraints. Full sync, targeted updates, events, and local deletes use the same permanent provider row with a transaction-scoped lock. It cannot expire midway like a TTL lock. SQLite tests do not establish MySQL lock behavior.
- Snapshot envelope validation, duplicate identity checks and existing identity collision checks happen before destructive writes. Invalid nonempty email fails rather than masquerading as unassignment. Missing serial rows are skipped and known provider identities protected from absence removal.
- The full file is treated as an authoritative snapshot only after successful validation. A device becoming unassigned or absent removes its local asset, keeping its employee. These policies await company confirmation.
- Explicit null clears nullable mapped fields; missing optional fields preserve values. Missing both model identity fields preserves an existing model reference; explicit incomplete model identity clears it. Device display name defaults to available model or `Unknown`.
- Shared employee/model metadata is resolved by lexicographic provider-ID order, with later present fields winning and warnings recorded. Targeted lookup uses the same canonical shared metadata.
- Asset counters describe changes to asset/attribute rows, not changes to shared employee/model rows. Runs also count newly created employees. Report date is stored as provenance, not assumed to be a complete incremental checkpoint. RAM uses the reference MB / 1024 conversion, labeled GiB in UI. Storage is unknown when absent.

## APIs

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/assets` | Paginated assets with employee, model and specifications |
| GET | `/api/assets/{id}` | Asset details |
| GET | `/api/employees` | Employees and assigned asset summaries |
| DELETE | `/api/assets/{id}` | Atomic local deletion and restore tracking |
| DELETE | `/api/employees/{id}` | Local employee deletion; linked assets retained |
| POST | `/api/sync` | Authoritative direct-file full sync |
| GET | `/api/sync-runs` | Latest 25 run results |
| POST | `/api/restorations` | Process pending local-delete identities |
| POST | `/api/events/jamf` | Durable simulated event receipt |
| GET | `/api/events` | Latest 50 inbox records |
| POST | `/api/events/{id}/retry` | Re-dispatch a failed event |

## Verification

The portable PHP suite passes **16 tests / 82 assertions** covering first/repeated sync, reassignment and RAM, separate configuration for shared models, null/missing fields, identity conflicts, incomplete snapshot protection, local asset/employee deletion, restoration, unassignment/absence, durable receipt, duplicate/conflicting events, latest-state processing, failure/retry, and pending-inbox recovery. React production build passes.

Docker images and Compose startup were verified with real MySQL: migrations, full 7/6 import, unchanged repeat, asset delete/targeted restore, employee delete/recreation, and a real database-queue worker processing a duplicate notification only once. Browser validation and further integration results are recorded in the handoff report.

For local tests, install PHP 8.3+ with the required extensions and Composer, then:

```sh
cd backend
composer install
php vendor/phpunit/phpunit/phpunit
```

The default tests use SQLite in memory and an isolated temporary copy of the fixture. They never edit the supplied JSON. Frontend verification: `cd frontend && npm ci && npm run build`. Code formatting: Laravel Pint and Prettier.

## Boundaries and next steps

The mock's targeted lookup still reads the complete local file. It reduces writes, not external API reads. A future real adapter could use individual/batch lookups, verified provider events, incremental cursors and periodic reconciliation. Purchase date cannot detect later updates. Kafka, gRPC, serverless deployment, tenant scoping, real credentials/webhook signatures, scheduled reconciliation and assignment history are outside this local implementation.

The queue is at-least-once, not exactly-once transport: transactions and idempotent processing make duplicate delivery safe. Pending inbox recovery covers dispatch gaps. A hard process termination may leave a historical run `in_progress`; production would add stale-run cleanup and monitoring. Restore task outcomes are terminal until another local deletion/full source reconciliation; no scheduled restore loop is claimed.

No GitHub repository was published and no reviewer permissions were changed.
