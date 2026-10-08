const columns = [
  ["event_id", "Event ID"],
  ["type", "Change"],
  ["device_name", "Device name"],
  ["serial_code", "Serial number"],
  ["provider_device_id", "Provider device ID"],
  ["attempts", "Attempts"],
  ["last_error", "Last error"],
  ["next_attempt_at", "Next retry"],
  ["created_at", "Created at"],
];

function csvCell(value) {
  let text = value == null ? "" : String(value);
  if (/^\s*[=+\-@]/.test(text)) text = "'" + text;

  return `"${text.replaceAll('"', '""')}"`;
}

export function buildPendingNotificationsCsv(notifications) {
  return (
    "\uFEFF" +
    [
      columns.map(([, label]) => csvCell(label)).join(","),
      ...notifications.map((notification) =>
        columns.map(([key]) => csvCell(notification[key])).join(","),
      ),
    ].join("\r\n")
  );
}

export function downloadPendingNotificationsCsv(notifications) {
  const url = URL.createObjectURL(
    new Blob([buildPendingNotificationsCsv(notifications)], {
      type: "text/csv;charset=utf-8",
    }),
  );
  const link = document.createElement("a");
  link.href = url;
  link.download = `pending-notifications-${new Date()
    .toISOString()
    .slice(0, 10)}.csv`;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 0);
}
