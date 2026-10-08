import React, { useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import { AssetList, EmployeeList, AssetDetails, Activity } from "./views.jsx";
import "./style.css";

async function request(path, options = {}) {
  const response = await fetch("/api" + path, {
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    ...options,
  });
  const body = response.status === 204 ? null : await response.json();
  if (!response.ok) throw new Error(body?.message || "Request failed");
  return body;
}

function App() {
  const [tab, setTab] = useState("assets");
  const [assets, setAssets] = useState([]);
  const [employees, setEmployees] = useState([]);
  const [events, setEvents] = useState([]);
  const [runs, setRuns] = useState([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [detail, setDetail] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  async function refresh(p = page) {
    const [a, e, v, r] = await Promise.all([
      request("/assets?page=" + p),
      request("/employees"),
      request("/events"),
      request("/sync-runs"),
    ]);
    if (p > a.last_page) {
      setPage(a.last_page);
      return;
    }
    setAssets(a.data);
    setLastPage(a.last_page);
    setEmployees(e.data);
    setEvents(v.data);
    setRuns(r.data);
  }
  useEffect(() => {
    refresh().catch((e) => setError(e.message));
  }, [page]);

  async function action(fn) {
    setBusy(true);
    setError("");
    try {
      await fn();
      await refresh();
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  async function sync(mode = "provider") {
    await action(async () => {
      const r = await request(mode === "file" ? "/sync" : "/sync/provider", {
        method: "POST",
      });
      const c = r.counters;
      setNotice(
        `Sync complete: ${c.created_assets} created, ${c.updated_assets} updated, ${c.unchanged_assets} unchanged, ${c.removed_assets} removed. ${c.warnings.length} warnings.`,
      );
    });
  }
  async function remove(kind, id) {
    const message =
      kind === "employees"
        ? "Delete this employee locally? Their assets retain a Deleted user reference and email until sync restores the employee."
        : "Delete this asset locally? A later sync can restore it.";
    if (!window.confirm(message)) return;
    await action(async () => {
      await request("/" + kind + "/" + id, { method: "DELETE" });
      setDetail(null);
      setNotice("Deleted locally. The source file was not changed.");
    });
  }
  async function showDetails(id) {
    await action(async () => {
      setDetail(await request("/assets/" + id));
      setTab("assets");
    });
  }
  async function sendEvent() {
    await action(async () => {
      const result = await request("/simulator/jamf", { method: "POST" });
      setNotice(
        `${result.emitted} state notifications queued. Timestamp-only changes are ignored. Refresh activity after the worker processes them.`,
      );
    });
  }
  return (
    <main>
      <header>
        <div>
          <small>LOCAL MDM DEMO</small>
          <h1>Device inventory</h1>
          <p>
            Sync Now uses current provider state. Import source JSON explicitly
            reloads the fixture.
          </p>
        </div>
        <button disabled={busy} onClick={() => sync("provider")}>
          {busy ? "Working…" : "Sync Now"}
        </button>
      </header>
      <nav>
        {["assets", "employees", "activity"].map((t) => (
          <button
            className={tab === t ? "active" : ""}
            key={t}
            onClick={() => setTab(t)}
          >
            {t}
          </button>
        ))}
      </nav>
      {error && (
        <p role="alert" className="error">
          {error}
        </p>
      )}
      {notice && (
        <p role="status" className="notice">
          {notice}
        </p>
      )}
      {tab === "assets" && (
        <>
          <AssetList
            assets={assets}
            busy={busy}
            onDetails={showDetails}
            onDelete={(id) => remove("assets", id)}
          />
          <div className="controls">
            <button
              disabled={page <= 1 || busy}
              onClick={() => setPage(page - 1)}
            >
              Previous
            </button>
            <span>
              Page {page} of {lastPage}
            </span>
            <button
              disabled={page >= lastPage || busy}
              onClick={() => setPage(page + 1)}
            >
              Next
            </button>
          </div>
          {detail && (
            <AssetDetails asset={detail} onClose={() => setDetail(null)} />
          )}
        </>
      )}
      {tab === "employees" && (
        <EmployeeList
          employees={employees}
          busy={busy}
          onDetails={showDetails}
          onDelete={(id) => remove("employees", id)}
        />
      )}
      {tab === "activity" && (
        <Activity
          events={events}
          runs={runs}
          busy={busy}
          onSend={sendEvent}
          onRestore={() =>
            action(async () => {
              const r = await request("/restorations", { method: "POST" });
              setNotice(
                `Restoration complete: ${r.counters.created_assets} assets recreated.`,
              );
            })
          }
          onRefresh={() => action(() => refresh())}
          onRetry={(id) =>
            action(() =>
              request("/events/" + id + "/retry", { method: "POST" }),
            )
          }
        />
      )}
      <footer>
        Local deletions never change Jamf or the mock. RAM uses the reference
        conversion: megabytes ÷ 1024.
      </footer>
    </main>
  );
}
createRoot(document.getElementById("root")).render(<App />);
