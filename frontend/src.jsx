import React, { useEffect, useState } from "react";
import { createRoot } from "react-dom/client";
import { AssetList, EmployeeList, Activity, Testing } from "./views.jsx";
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
  const [deletedAssets, setDeletedAssets] = useState([]);
  const [providerResult, setProviderResult] = useState(null);
  const [outbox, setOutbox] = useState({
    pending: 0,
    notifications: [],
    truncated: false,
  });
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [detail, setDetail] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  async function refresh(p = page) {
    const [a, e, v, r, d, o] = await Promise.all([
      request("/assets?page=" + p),
      request("/employees"),
      request("/events"),
      request("/sync-runs"),
      request("/deleted-assets"),
      request("/provider-outbox").catch((error) => ({
        unavailable: error.message,
      })),
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
    setDeletedAssets(d.data);
    setOutbox(o);
  }
  useEffect(() => {
    refresh().catch((e) => setError(e.message));
  }, [page, tab]);

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
  async function sync() {
    await action(async () => {
      setNotice("");
      await request("/sync/provider", { method: "POST" });
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
  async function changeProvider(method, providerId, payload) {
    setBusy(true);
    setError("");
    try {
      const path =
        "/demo/provider-devices" +
        (providerId ? "/" + encodeURIComponent(providerId) : "");
      const result = await request(path, {
        method,
        body: payload ? JSON.stringify(payload) : undefined,
      });
      setProviderResult(result);
      const completedAction = {
        create: "created",
        update: "updated",
        remove: "removed",
      }[result.action];
      setNotice(
        `Provider device ${completedAction}. Click Sync Now to refresh the inventory from the provider.`,
      );
    } catch (e) {
      setError(e.message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <main>
      <header>
        <div>
          <small>LOCAL MDM DEMO</small>
          <h1>Device inventory</h1>
          <p>
            Sync Now reconciles the inventory with the current provider state.
            Testing contains the mock-provider controls. Activity shows
            notifications waiting for Kafka and provides processing and CSV
            export controls.
          </p>
        </div>
        <button disabled={busy} onClick={sync}>
          {busy ? "Working…" : "Sync Now"}
        </button>
      </header>
      <nav>
        {["assets", "employees", "activity", "testing"].map((t) => (
          <button
            className={tab === t ? "active" : ""}
            aria-current={tab === t ? "page" : undefined}
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
            detail={detail}
            busy={busy}
            onDetails={showDetails}
            onDelete={(id) => remove("assets", id)}
            onCloseDetails={() => setDetail(null)}
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
          outbox={outbox}
          onRetryDelivery={() =>
            action(async () => {
              const result = await request("/provider-outbox/retry", {
                method: "POST",
              });
              setNotice(
                `${result.published} notifications published to Kafka; ${result.pending} still waiting. Inventory processing is separate. Automatic delivery retries remain enabled.`,
              );
            })
          }
          busy={busy}
        />
      )}
      {tab === "testing" && (
        <Testing
          deletedAssets={deletedAssets}
          events={events}
          runs={runs}
          providerResult={providerResult}
          busy={busy}
          onReset={() =>
            action(async () => {
              const result = await request("/demo/reset", { method: "POST" });
              setDetail(null);
              setProviderResult(null);
              setPage(1);
              setNotice(
                `Demo reset with ${result.provider_devices} provider devices. Click Sync Now to fetch them.`,
              );
            })
          }
          onKafkaFailure={() =>
            action(async () => {
              const result = await request("/demo/kafka-failure", {
                method: "POST",
              });
              setNotice(
                `${result.affected} notification could not reach Kafka; ${result.pending} total waiting for recovery.`,
              );
              setTab("activity");
            })
          }
          onProviderCreate={(payload) => changeProvider("POST", null, payload)}
          onProviderUpdate={(id, payload) =>
            changeProvider("PATCH", id, payload)
          }
          onProviderRemove={(id) => changeProvider("DELETE", id)}
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
