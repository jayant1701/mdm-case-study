# Provider and inventory service design

## Deployment

The mock Jamf provider and inventory backend use the same Laravel source but run as separate services. APP_ROLE chooses their routes and commands. Each has its own MySQL server, credentials, migrations and persistent volume.

The provider runs only database/provider-migrations. It owns provider_devices, provider_generation and producer_outbox. It cannot access inventory credentials.

The inventory runs database/migrations. It owns active and deleted employee identity, assets, asset attributes, deleted-asset identity, event inbox, checkpoints, shared metadata ordering, sync history, rejected notifications and job records. Legacy local-provider tables remain for compatibility tests and are not the source store in this deployment.

## Device changes

Provider mutations acquire a provider-generation row lock and start a database transaction. The transaction validates identities, changes device state, increments device versions and generation, and inserts outgoing events. Any failed write rolls back all these changes.

The request succeeds when that transaction commits. Kafka delivery is asynchronous. The outbox publisher reads due pending rows and sends complete device events keyed by jamf|provider_device_id. It waits for broker acknowledgment before marking each row published. Errors leave the row pending with a scheduled retry; manual recovery may process deferred rows immediately.

Kafka uses three partitions. Device keys preserve per-device ordering within a partition; they do not establish global order. Device versions and provider generations protect processing and shared employee/model updates.

## Inventory delivery

The Kafka consumer disables automatic offset commits. It validates each record and persists it through EventReceiver. It commits the offset only after durable inbox acceptance. If queue dispatch fails, events:recover redispatches the saved inbox row.

Invalid JSON, invalid schemas and conflicting messages are saved in rejected_notifications with payload, error, topic, partition and offset. Only then is their offset acknowledged. Database failures escape without acknowledging. Quarantine replay tooling is future work.

ProcessDeviceEvent references the inbox ID. EventProcessor loads the stored payload and updates inventory, device checkpoint and completion status in one inventory transaction. It never reads the JSON or provider API. Repeated deliveries are safe; older versions are ignored.

## Synchronization and restoration

RemoteProviderGateway is the HTTP adapter boundary. HttpMockProviderAdapter validates provider snapshots and individual device identity. Provider reads return source-owned versions, generations and retained removal tombstones.

Sync Now requests the complete current provider snapshot. Recovery applies its device envelopes and checkpoints together. A snapshot older than already applied events is rejected; an equal device version with conflicting meaningful state is also rejected.

POST /api/sync is the assignment-compatible path: the inventory backend reads the supplied JSON, imports it through the provider HTTP boundary and reconciles the returned versioned snapshot. The normal UI Sync Now path calls POST /api/sync/provider and reads only the provider HTTP snapshot. Reset demo reloads the fixture into the provider, clears local inventory state and leaves inventory empty until Sync Now is selected. Provider generations and device versions remain monotonic during reset so a delayed pre-reset Kafka event is older than the new provider snapshot.

Local asset deletion saves provider identity, serial, device name and deletion time in `deleted_assets`, then removes only inventory data. Testing lists those local deletions without exposing a restore action. Full reconciliation can recreate an assigned device that still exists upstream. The targeted restoration service remains an internal recovery path and validates the provider identity/version. A confirmed provider removal or unassignment removes the deleted-asset row because there is nothing to restore.

Employee deletion removes the active `employees` row and stores only its original ID, email and deletion time in `deleted_employees`. Assets retain `deleted_employee_id` while `employee_id` becomes null. An assigned-device event, complete reconciliation or targeted provider lookup restores the same employee ID, relinks the assets and removes the tombstone. The provider IDs needed for targeted lookup already live on the retained assets.

`report_date` is a freshness watermark: an older provider report cannot overwrite a newer asset state. `report_date` and `last_contact_at` do not create events by themselves, so routine reporting/contact updates cannot flood Kafka. Event version and provider generation remain the ordering mechanism.

Device model and fixed configuration are stored together. Manufacturer plus model identifier is unique; model name, platform, processor and core count are shared. Conflicting non-null fixed metadata fails the transaction. RAM, storage and battery remain asset-specific.

## Persistence and concurrency

A transaction and a lock solve different problems. The transaction keeps device/outbox writes atomic. The lock prevents competing writes from racing. The provider uses its generation row lock; inventory uses its own provider lock. Neither service locks the other's database.

Kafka transactions do not include MySQL writes. Publishing before SQL commit can expose an event for a rolled-back change. The provider therefore always writes the outbox before publication, rather than using SQL only as a broker-failure fallback.

The local broker has a persistent volume, one replica and seven-day retention. It tolerates ordinary process restarts. This is the case-study topology, not a capacity or availability claim.

## Provider extensibility and scaling

DeviceProvider maps the fixture. RemoteProviderGateway and TargetedDeviceProvider map remote reads into DeviceRecord/DeviceEvent. Repository contracts separate persistence operations from services. Some returned types still depend on Eloquent/Laravel.

Only Jamf is supported at runtime. Multi-provider selection and company-scoped identities are not implemented. In a multi-customer product, `company_id` must scope emails, serial numbers, provider IDs, events and queries so one company cannot read or collide with another company's records.

Kafka can scale, but the current estimate does not justify more consumer partitions or database shards. Scale the measured bottleneck: add a composite outbox index if pending-row selection is slow, add partitions and consumers if processing lag grows, and schedule work fairly by company if provider throttling lets one company delay others. Company-based database shards are a later response to measured throughput or storage limits. Kafka buffers backlog; it does not remove inventory database limits. The README contains the planning estimates and an unimplemented serverless alternative.

## Delivery activity

The inventory Activity page shows two boundaries. Provider delivery uses a proxied outbox response containing the pending count and at most 100 summary rows: device name, serial, event type, provider ID, attempts, error and retry timestamps. Activity lists those rows, exports the displayed rows as CSV and offers manual processing when needed. If more than 100 are pending, the UI identifies that the list and CSV are truncated.

Inventory processing lists only inbox records that exhausted all three automatic queue attempts. Each row identifies the affected device and employee and offers a manual retry. The UI endpoint omits complete event payloads and exception details; those remain in `event_inboxes` and `failed_jobs` for debugging. An unsuccessful attempt is marked `retrying`, while the queue still owns its retry, and becomes `failed` only through the job's terminal failure callback.

Testing contains reset, deterministic delivery-failure, local-deletion listing and technical processing history. The deterministic control writes the durable state of a failed publication; it does not stop Kafka. Manual processing drains at most ten pending messages using the same publisher as automatic retries, while also bypassing their retry delay; published rows are excluded. Provider database credentials never reach the inventory service. Demo reset/failure routes are limited to local/testing environments. Outbox status and retry routes are always registered in this case study and require access control before production use.

Provider Lab is also limited to local/testing inventory routes. It sends create, patch or delete through `RemoteProviderGateway` and returns provider event metadata without running reconciliation or writing inventory tables. The UI does not refresh inventory after those forms; the user clicks Sync Now to fetch the provider snapshot and refresh Assets and Employees. The provider mutation still writes its durable outbox event, so normal background event delivery remains active.
