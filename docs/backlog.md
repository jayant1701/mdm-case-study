# Backlog

## Navigation during a pending details request

If a user opens an employee asset, then selects Activity before its details request finishes, completion currently switches back to Assets. Respect the latest navigation choice by invalidating the older navigation request or checking a navigation revision. Add a delayed-response browser check.

Deferred at the user's request. No navigation changes were made with the outbox activity feature.

## Activity and accessibility

Replace raw sync counters in Testing with readable summaries, add device-specific action labels, and improve detail units. The navigation exposes its selected page through `aria-current`, asset details render directly below the selected row, and Activity is limited to outbox state with pending-device summaries and CSV export. Other review items remain open.
