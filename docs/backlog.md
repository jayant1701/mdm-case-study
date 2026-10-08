# Backlog

## Navigation during a pending details request

If a user opens an employee asset, then selects Activity before its details request finishes, completion currently switches back to Assets. Respect the latest navigation choice by invalidating the older navigation request or checking a navigation revision. Add a delayed-response browser check.

Deferred at the user's request. No navigation changes were made with the outbox activity feature.

## Activity and accessibility

Activity now separates provider delivery failures from permanently failed inventory processing. It names affected devices and employees, keeps temporary automatic retries out of the manual-action list, and exposes retry actions only after the queue exhausts its attempts. Full mobile and screen-reader testing remains future work.
