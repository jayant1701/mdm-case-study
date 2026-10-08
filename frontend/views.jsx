import React, { useState } from "react";
import { downloadPendingNotificationsCsv } from "./pendingNotificationsCsv.js";

export function AssetList({
  assets,
  detail,
  busy,
  onDetails,
  onDelete,
  onCloseDetails,
}) {
  return (
    <>
      <h2>Assets</h2>
      <div className="table">
        <table>
          <thead>
            <tr>
              <th>Device / serial</th>
              <th>Model</th>
              <th>RAM</th>
              <th>Employee</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {assets.map((a) => (
              <React.Fragment key={a.id}>
                <tr>
                  <td>
                    <strong>{a.device_name}</strong>
                    <br />
                    <code>{a.serial_code}</code>
                  </td>
                  <td>{a.device_model?.model_name || "Unknown"}</td>
                  <td>{a.specifications?.ram_gb ?? "—"} GiB</td>
                  <td>
                    {a.employee?.email ||
                      (a.deleted_employee?.email
                        ? `Deleted user (${a.deleted_employee.email})`
                        : "Unassigned")}
                  </td>
                  <td>
                    <button disabled={busy} onClick={() => onDetails(a.id)}>
                      Details
                    </button>
                    <button disabled={busy} onClick={() => onDelete(a.id)}>
                      Delete
                    </button>
                  </td>
                </tr>
                {detail?.id === a.id && (
                  <tr className="detail-row">
                    <td colSpan="5">
                      <AssetDetails asset={detail} onClose={onCloseDetails} />
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </div>
      {!assets.length && <p>No assets yet. Run your first sync.</p>}
    </>
  );
}

export function EmployeeList({
  employees,
  detail,
  busy,
  onEmployeeDetails,
  onAssetDetails,
  onCloseDetails,
  onDelete,
}) {
  return (
    <>
      <h2>Employees</h2>
      <div className="table">
        <table>
          <thead>
            <tr>
              <th>Name / email</th>
              <th>Phone</th>
              <th>Assigned assets</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {employees.map((e) => (
              <React.Fragment key={e.id}>
                <tr>
                  <td>
                    <button
                      className="employee-link"
                      disabled={busy}
                      onClick={() => onEmployeeDetails(e.id)}
                    >
                      <strong>{e.name || "Unknown"}</strong>
                      <span>{e.email}</span>
                    </button>
                  </td>
                  <td>{e.phone || "—"}</td>
                  <td>
                    {e.assets.length
                      ? e.assets.map((a) => (
                          <div key={a.id}>
                            <button
                              disabled={busy}
                              onClick={() => onAssetDetails(a.id)}
                            >
                              {a.device_name}
                            </button>
                            <code>{a.serial_code}</code>
                          </div>
                        ))
                      : "No assigned assets"}
                  </td>
                  <td>
                    <button
                      disabled={busy}
                      onClick={() => onEmployeeDetails(e.id)}
                    >
                      Details
                    </button>
                    <button disabled={busy} onClick={() => onDelete(e.id)}>
                      Delete
                    </button>
                  </td>
                </tr>
                {detail?.id === e.id && (
                  <tr className="detail-row">
                    <td colSpan="4">
                      <EmployeeDetails
                        employee={detail}
                        onAssetDetails={onAssetDetails}
                        onClose={onCloseDetails}
                      />
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </div>
      {!employees.length && <p>No employees yet.</p>}
    </>
  );
}

function EmployeeDetails({ employee, onAssetDetails, onClose }) {
  const assets = employee.assets || [];
  const fields = [
    ["Name", employee.name],
    ["Email", employee.email],
    ["Username", employee.username],
    ["Position", employee.position],
    ["Phone", employee.phone],
    ["Status", "Active"],
    ["Assigned devices", assets.length],
  ];

  return (
    <section>
      <button onClick={onClose}>Close details</button>
      <h3>{employee.name || employee.email}</h3>
      <dl className="asset-details-list">
        {fields.map(([label, value]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>{value ?? "Unknown"}</dd>
          </div>
        ))}
      </dl>
      <h4>Assigned devices</h4>
      {!assets.length && <p>No assigned devices.</p>}
      {assets.length > 0 && (
        <div className="table employee-assets">
          <table>
            <thead>
              <tr>
                <th>Device / serial</th>
                <th>Model</th>
                <th>RAM</th>
                <th>Provider ID</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {assets.map((asset) => (
                <tr key={asset.id}>
                  <td>
                    <strong>{asset.device_name}</strong>
                    <br />
                    <code>{asset.serial_code}</code>
                  </td>
                  <td>{asset.device_model?.model_name || "Unknown"}</td>
                  <td>{asset.specifications?.ram_gb ?? "Unknown"} GiB</td>
                  <td>{asset.provider_device_id}</td>
                  <td>
                    <button onClick={() => onAssetDetails(asset.id)}>
                      Device details
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

export function AssetDetails({ asset, onClose }) {
  const specifications = asset.specifications || {};
  const fields = [
    ["RAM", specifications.ram_gb, "GiB"],
    ["Storage", specifications.storage_gb, "GiB"],
    ["Processor type", asset.device_model?.processor_type],
    ["Core count", asset.device_model?.core_count],
    ["Platform", asset.device_model?.platform],
    ["Battery capacity", specifications.battery_capacity_percent, "%"],
    ["Serial code", asset.serial_code],
    [
      "Employee",
      asset.employee?.email ||
        (asset.deleted_employee?.email
          ? `Deleted user (${asset.deleted_employee.email})`
          : "Unassigned"),
    ],
    ["Provider", asset.provider],
    ["Provider device ID", asset.provider_device_id],
    ["Purchase date", asset.purchase_date],
    ["Report date", asset.report_date],
    ["Last contact", asset.last_contact_at],
    ["Site", asset.site_name],
  ];
  return (
    <section>
      <button onClick={onClose}>Close details</button>
      <h3>{asset.device_name}</h3>
      <dl className="asset-details-list">
        {fields.map(([label, value, unit]) => (
          <div key={label}>
            <dt>{label}</dt>
            <dd>
              {value ?? "Unknown"}
              {value != null && unit ? (unit === "%" ? unit : ` ${unit}`) : ""}
            </dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

export function Activity({ outbox, onRetryDelivery, busy }) {
  const notifications = outbox.notifications || [];

  return (
    <>
      <h2>Notification delivery</h2>
      <p>
        The provider outbox holds notifications that have not yet been accepted
        by Kafka. Automatic processing continues in the background.
      </p>
      {outbox.unavailable ? (
        <p role="status">Provider outbox unavailable: {outbox.unavailable}</p>
      ) : outbox.pending === 0 ? (
        <p className="success" role="status">
          Outbox empty — all notifications have been delivered to Kafka.
        </p>
      ) : (
        <section className="pending">
          <strong>
            {outbox.pending} notification{outbox.pending === 1 ? "" : "s"}{" "}
            waiting for Kafka.
          </strong>
          <p>Process them now, or leave the automatic publisher to retry.</p>
          <div className="activity-actions">
            <button disabled={busy} onClick={onRetryDelivery}>
              Process pending notifications
            </button>
            <button
              disabled={busy || !notifications.length}
              onClick={() => downloadPendingNotificationsCsv(notifications)}
            >
              Export pending CSV
            </button>
          </div>
          <h3>Pending notifications</h3>
          <div className="table">
            <table>
              <thead>
                <tr>
                  <th>Device / serial</th>
                  <th>Change</th>
                  <th>Attempts</th>
                  <th>Next retry</th>
                </tr>
              </thead>
              <tbody>
                {notifications.map((notification) => (
                  <tr key={notification.event_id}>
                    <td>
                      <strong>
                        {notification.device_name || "Unknown device"}
                      </strong>
                      <br />
                      <code>
                        {notification.serial_code || "Unknown serial"}
                      </code>
                    </td>
                    <td>
                      {(notification.type || "unknown")
                        .replace("device.", "")
                        .replaceAll("_", " ")}
                    </td>
                    <td>{notification.attempts}</td>
                    <td>
                      {notification.next_attempt_at
                        ? new Date(
                            notification.next_attempt_at,
                          ).toLocaleString()
                        : "Ready now"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {outbox.truncated && (
            <p>
              Showing the first {notifications.length} of {outbox.pending}{" "}
              notifications. The CSV contains the displayed rows.
            </p>
          )}
        </section>
      )}
    </>
  );
}

export function Testing({
  deletedAssets,
  runs,
  providerResult,
  busy,
  onReset,
  onKafkaFailure,
  onProviderCreate,
  onProviderUpdate,
  onProviderRemove,
}) {
  return (
    <>
      <h2>Testing tools</h2>
      <p>
        These controls reset the local demonstration and show how a persisted
        notification recovers after Kafka is unavailable.
      </p>
      <div className="testing-grid">
        <section>
          <h3>Reset the demonstration</h3>
          <p>
            Reload the supplied JSON into the mock provider and clear the local
            inventory. Then use Sync Now to fetch the provider snapshot.
          </p>
          <button disabled={busy} onClick={onReset}>
            Reset demo
          </button>
        </section>
        <section>
          <h3>Test Kafka recovery</h3>
          <p>
            Change one provider device and simulate a failed Kafka delivery.
            Activity will show how many notifications are waiting.
          </p>
          <button disabled={busy} onClick={onKafkaFailure}>
            Simulate Kafka failure
          </button>
        </section>
      </div>
      <ProviderLab
        busy={busy}
        result={providerResult}
        onCreate={onProviderCreate}
        onUpdate={onProviderUpdate}
        onRemove={onProviderRemove}
      />
      <h3>Locally deleted devices</h3>
      {!deletedAssets.length && <p>No locally deleted devices.</p>}
      {deletedAssets.length > 0 && (
        <div className="table">
          <table>
            <thead>
              <tr>
                <th>Device / serial</th>
                <th>Provider device ID</th>
                <th>Deleted at</th>
              </tr>
            </thead>
            <tbody>
              {deletedAssets.map((asset) => (
                <tr key={`${asset.provider}-${asset.serial_code}`}>
                  <td>
                    <strong>{asset.device_name || "Unknown device"}</strong>
                    <br />
                    <code>{asset.serial_code}</code>
                  </td>
                  <td>{asset.provider_device_id || "Unknown"}</td>
                  <td>{asset.deleted_at}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <h3>Synchronization runs</h3>
      {runs.map((r) => (
        <article key={r.id}>
          {r.mode} · {r.status} · {r.started_at}
          <pre>{JSON.stringify(r.counters, null, 2)}</pre>
          {r.error_details && <p className="error">{r.error_details}</p>}
        </article>
      ))}
    </>
  );
}

function ProviderLab({ busy, result, onCreate, onUpdate, onRemove }) {
  const [create, setCreate] = useState({
    provider_device_id: "demo-asset-001",
    serial_code: "DEMO-SERIAL-001",
    device_name: "Provider Lab MacBook",
    employee_email: "demo.employee@company.test",
    employee_name: "Demo Employee",
    ram_gb: "16",
  });
  const [update, setUpdate] = useState({
    provider_device_id: "101",
    device_name: "Alex’s MacBook Pro (Provider Lab)",
    ram_gb: "32",
  });
  const [removeId, setRemoveId] = useState("demo-asset-001");

  function field(state, setState, key) {
    return {
      value: state[key],
      onChange: (event) => setState({ ...state, [key]: event.target.value }),
    };
  }

  return (
    <>
      <h3>Provider Lab</h3>
      <p>
        Change the mock provider through its HTTP adapter. These forms do not
        run inventory reconciliation. Click Sync Now after a provider change to
        fetch the new provider state.
      </p>
      <div className="provider-lab-grid">
        <form
          onSubmit={(event) => {
            event.preventDefault();
            onCreate({ ...create, ram_gb: Number(create.ram_gb) });
          }}
        >
          <h4>Create a provider device</h4>
          <label>
            Provider device ID
            <input
              required
              {...field(create, setCreate, "provider_device_id")}
            />
          </label>
          <label>
            Serial number
            <input required {...field(create, setCreate, "serial_code")} />
          </label>
          <label>
            Device name
            <input required {...field(create, setCreate, "device_name")} />
          </label>
          <label>
            Employee email
            <input
              required
              type="email"
              {...field(create, setCreate, "employee_email")}
            />
          </label>
          <label>
            Employee name
            <input required {...field(create, setCreate, "employee_name")} />
          </label>
          <label>
            RAM (GiB)
            <input
              required
              min="0"
              step="0.01"
              type="number"
              {...field(create, setCreate, "ram_gb")}
            />
          </label>
          <button disabled={busy} type="submit">
            Create in provider
          </button>
        </form>
        <form
          onSubmit={(event) => {
            event.preventDefault();
            const { provider_device_id, ...changes } = update;
            onUpdate(provider_device_id, {
              ...changes,
              ram_gb: Number(changes.ram_gb),
            });
          }}
        >
          <h4>Update a provider device</h4>
          <label>
            Provider device ID
            <input
              required
              {...field(update, setUpdate, "provider_device_id")}
            />
          </label>
          <label>
            Device name
            <input required {...field(update, setUpdate, "device_name")} />
          </label>
          <label>
            RAM (GiB)
            <input
              required
              min="0"
              step="0.01"
              type="number"
              {...field(update, setUpdate, "ram_gb")}
            />
          </label>
          <button disabled={busy} type="submit">
            Update in provider
          </button>
        </form>
        <form
          onSubmit={(event) => {
            event.preventDefault();
            onRemove(removeId);
          }}
        >
          <h4>Remove a provider device</h4>
          <label>
            Provider device ID
            <input
              required
              value={removeId}
              onChange={(event) => setRemoveId(event.target.value)}
            />
          </label>
          <p>
            This removes the device from the mock provider. Click Sync Now to
            remove its local asset.
          </p>
          <button disabled={busy} type="submit">
            Remove from provider
          </button>
        </form>
      </div>
      {result && (
        <section className="provider-result" role="status">
          <h4>Last provider change</h4>
          <p>
            {result.action} · provider device {result.provider_device_id} ·{" "}
            {result.provider_event} · {result.notifications_created}{" "}
            notification
            {result.notifications_created === 1 ? "" : "s"} created
          </p>
          <p>Click Sync Now to reconcile this provider change.</p>
        </section>
      )}
    </>
  );
}
