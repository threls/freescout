# AuditLog (ARMS-25)

A searchable, filterable, cross-ticket view of the ticket audit trail, plus
the index behind it. FreeScout already records every ticket action as an
immutable `threads` line-item and shows it inside the individual conversation;
this module adds the *standalone* page that lists those actions across all
tickets, which core has no equivalent of.

Companion work (separate PR): server-side filters were also added to the
built-in admin **Logs** page (`/app-logs`) for the security/system activity log
— that's a core patch in `SecureController@logs` + `resources/views/secure/logs.blade.php`,
not part of this module.

## What it shows

One row per ticket event, newest first, rendered through FreeScout's own
`Thread::getActionText()` so the wording (including custom statuses like
On-Hold) matches exactly what agents see inside a ticket. Per the ARMS-1
epic's definition ("creation, status changes, assignments, comments") the feed
spans the full record, not just the line-items:

- **Actions** (`Thread::TYPE_LINEITEM`): status changed, assigned, moved
  mailbox, merged, customer changed, deleted, restored.
- **Creation** (the first thread), **replies** (agent + customer messages),
  and **internal notes** — published threads only; drafts are never events.

The performer is resolved in the Agent column via `actorName()` — the agent for
user actions, the customer for inbound replies, a dash for system/automatic
ones. Import line-items are omitted from the Action dropdown but still appear
under "Any action".

## Filters

Agent · action/event type (real line-item actions **plus** Created / Reply /
Internal Note pseudo-types) · mailbox · date range (defaults to last 30 days) ·
ticket number · free-text (matches the line-item's action detail, the message
body, or the conversation subject). All filters combine, survive pagination,
and apply identically to the CSV and PDF exports.

## Export

- **CSV** — streamed and chunked (unbounded).
- **PDF** — rendered through dompdf (same engine as ArmsReports), capped at
  `AuditExporter::PDF_MAX_ROWS` (2000) rows with an on-page notice when capped;
  narrow the filters to export fewer.

## Access & visibility

Available to any authenticated user (route group `roles => ['user','admin']`).
Results are scoped to the mailboxes the viewer can see:

- **Admins** see every mailbox.
- **Everyone else** is restricted to `User::mailboxesIdsCanView()` — so this
  never exposes an action on a ticket the agent couldn't already open. It only
  aggregates what they can already see inside individual conversations.

**To make it admin-only** (if ARMS prefers a controlled audience): change the
route group's `roles` to `['admin']` in `Http/routes.php` and add `->isAdmin()`
to the `menu.append` guard in `AuditLogServiceProvider`. The visibility
scoping stays correct either way.

## Read-only / immutability

Nothing here writes. There is no observer, no listener, no change to how
line-items are recorded — this is a query + view layer only, satisfying
ARMS-25's "no change to how entries are recorded".

## Index

`Database/Migrations/2026_07_23_000001_add_audit_indexes_to_threads.php` adds
two guarded, idempotent composite indexes to `threads`:

- `(type, created_at)` — backs the default listing (line-items, newest first).
- `(created_by_user_id, created_at)` — backs the "by agent" filter.

`threads` is the largest table in the app and had no index on `type` /
`action_type` / `created_by_user_id`, so without these the cross-ticket query
would full-scan. Mailbox/ticket filters ride on `conversations`' existing
indexes.

## Known limits / follow-ups

- The listing renders the action as plain text; the coloured status *pills*
  from the client mockup are a visual follow-up, not built here.
- `ACTION_TYPE_MERGED` rows resolve their merge target lazily inside
  `getActionText()`; fine at 20/page, and the CSV export chunks at 500 rows.
- Date filters must stay within the MySQL `TIMESTAMP` range (< 2038-01-19),
  since `threads.created_at` is a TIMESTAMP column. Realistic (past/present)
  audit dates are always fine; the default window is the last 30 days.
