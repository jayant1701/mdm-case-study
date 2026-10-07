import React, { useState } from "react";

export function AssetList({ assets, busy, onDetails, onDelete }) {
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
              <tr key={a.id}>
                <td>
                  <strong>{a.device_name}</strong>
                  <br />
                  <code>{a.serial_code}</code>
                </td>
                <td>{a.device_model?.model_name || "Unknown"}</td>
                <td>{a.specifications?.ram_gb ?? "—"} GiB</td>
                <td>{a.employee?.email || "Employee deleted locally"}</td>
                <td>
                  <button disabled={busy} onClick={() => onDetails(a.id)}>
                    Details
                  </button>
                  <button disabled={busy} onClick={() => onDelete(a.id)}>
                    Delete
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!assets.length && <p>No assets yet. Run your first sync.</p>}
    </>
  );
}

export function EmployeeList({ employees, busy, onDetails, onDelete }) {
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
              <tr key={e.id}>
                <td>
                  {e.name || "Unknown"}
                  <br />
                  {e.email}
                </td>
                <td>{e.phone || "—"}</td>
                <td>
                  {e.assets.length
                    ? e.assets.map((a) => (
                        <div key={a.id}>
                          <button
                            disabled={busy}
                            onClick={() => onDetails(a.id)}
                          >
                            {a.device_name}
                          </button>
                          <code>{a.serial_code}</code>
                        </div>
                      ))
                    : "No assigned assets"}
                </td>
                <td>
                  <button disabled={busy} onClick={() => onDelete(e.id)}>
                    Delete
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {!employees.length && <p>No employees yet.</p>}
    </>
  );
}

export function AssetDetails({ asset, onClose }) {
  const fields = {
    ...asset.specifications,
    serial_code: asset.serial_code,
    provider: asset.provider,
    provider_device_id: asset.provider_device_id,
    purchase_date: asset.purchase_date,
  };
  return (
    <section>
      <button onClick={onClose}>Close details</button>
      <h3>{asset.device_name}</h3>
      <dl>
        {Object.entries(fields)
          .filter(
            ([key]) =>
              !["id", "asset_id", "created_at", "updated_at"].includes(key),
          )
          .map(([key, value]) => (
            <React.Fragment key={key}>
              <dt>{key.replaceAll("_", " ")}</dt>
              <dd>{value ?? "Unknown"}</dd>
            </React.Fragment>
          ))}
      </dl>
    </section>
  );
}

export function Activity({
  events,
  runs,
  busy,
  onSend,
  onRestore,
  onRefresh,
  onRetry,
}) {
  const [deviceId, setDeviceId] = useState("101");
  return (
    <>
      <h2>Simulated provider notifications</h2>
      <p>
        This is our demo schema, not a real Jamf webhook. Edit the mounted
        source file first, then notify its device ID. Latest source state is
        always fetched.
      </p>
      <label>
        Provider device ID{" "}
        <input value={deviceId} onChange={(e) => setDeviceId(e.target.value)} />
      </label>
      <div className="controls">
        <button
          disabled={busy || !deviceId.trim()}
          onClick={() => onSend(deviceId.trim())}
        >
          Send simulated change
        </button>
        <button disabled={busy} onClick={onRestore}>
          Restore locally deleted assets
        </button>
        <button disabled={busy} onClick={onRefresh}>
          Refresh activity
        </button>
      </div>
      <h3>Events</h3>
      {!events.length && <p>No notifications yet.</p>}
      {events.map((e) => (
        <article key={e.id}>
          <strong>
            {e.provider_device_id}: {e.status}
          </strong>
          <p>
            {e.event_id} · attempts {e.attempts}
          </p>
          {e.last_error && <p className="error">{e.last_error}</p>}
          {e.status === "failed" && (
            <button disabled={busy} onClick={() => onRetry(e.id)}>
              Retry
            </button>
          )}
        </article>
      ))}
      <h3>Recent sync runs</h3>
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
