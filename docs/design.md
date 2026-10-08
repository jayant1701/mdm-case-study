# Provider and inventory service design

## Deployment

The mock Jamf provider and inventory backend use the same Laravel source but run as separate services. APP_ROLE chooses their routes and commands. Each has its own MySQL server, credentials, migrations and persistent volume.

The provider runs only database/provider-migrations. It owns provider_devices, provider_generation and producer_outbox. It cannot access inventory credentials.

The inventory runs database/migrations. It owns assets, employees, specifications, recovery tasks, event inbox, checkpoints, shared metadata ordering, sync history, rejected notifications and job records. Legacy local-provider tables remain for compatibility tests and are not the source store in this deployment.

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

The direct JSON import path remains available for assignment-level tests. The normal UI Sync Now path reads only the provider HTTP snapshot. Reset demo reloads the fixture into the provider, clears local inventory state and leaves inventory empty until Sync Now is selected. Provider generations and device versions remain monotonic during reset so a delayed pre-reset Kafka event is older than the new provider snapshot.

Local asset deletion saves provider identity and device name in RestoreTask and removes only inventory data. Testing lists pending local deletions without exposing a restore action. Full reconciliation can recreate an assigned device that still exists upstream. The targeted restoration service remains an internal recovery path and validates the provider identity/version.

Employee deletion retains internal ID/email/deletion time, clears name/phone and preserves asset links. Relevant source events or reconciliation may reactivate that employee.

## Persistence and concurrency

A transaction and a lock solve different problems. The transaction keeps device/outbox writes atomic. The lock prevents competing writes from racing. The provider uses its generation row lock; inventory uses its own provider lock. Neither service locks the other's database.

Kafka transactions do not include MySQL writes. Publishing before SQL commit can expose an event for a rolled-back change. The provider therefore always writes the outbox before publication, rather than using SQL only as a broker-failure fallback.

The local broker has a persistent volume, one replica and seven-day retention. It tolerates process restarts but is not a highly available cluster. SQL inbox/outbox cleanup, backups, lag monitoring and restore drills remain production work.

## Provider extensibility and scaling

DeviceProvider maps the fixture. RemoteProviderGateway and TargetedDeviceProvider map remote reads into DeviceRecord/DeviceEvent. Repository contracts separate persistence operations from services. Some returned types still depend on Eloquent/Laravel.

Only Jamf is supported at runtime. Multi-provider selection and company-scoped identities are not implemented.

Company-based worker partitions and database shards can spread customer traffic. A large company's burst may still need device-based processing and coordination for shared metadata. Kafka buffers backlog; it does not remove inventory database limits. The README contains planning estimates, not capacity benchmarks.

## Delivery activity

The inventory Activity page proxies only the provider-owned pending outbox count over HTTP. It reports empty or waiting state and offers manual processing when needed. Testing contains reset, deterministic delivery-failure, local-deletion listing and technical processing history. Manual processing drains at most ten pending messages using the same publisher as automatic retries, while also bypassing their retry delay; published rows are excluded. Provider database credentials never reach the inventory service. The controls are registered only in local/testing environments.
