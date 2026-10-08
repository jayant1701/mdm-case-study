# Backlog

## Navigation during a pending details request

If a user opens an employee asset, then selects Activity before its details request finishes, completion currently switches back to Assets. Respect the latest navigation choice by invalidating the older navigation request or checking a navigation revision. Add a delayed-response browser check.

Deferred at the user's request. No navigation changes were made with the outbox activity feature.

## Details visibility

Bring the details panel into view and move focus to its heading, or use a visible drawer. The user agreed the existing below-table placement is difficult to discover; implementation remains pending.

## Activity and accessibility

Replace raw sync counters in Testing with readable summaries, add device-specific action labels, and improve detail units. The navigation now exposes its selected page through `aria-current`. Activity shows only outbox state; other review items remain open.
