# SolvedReopenWindow (ARMS-21)

Adds ARMS's Solved-ticket lifecycle: a customer reply **within N days** (default 7)
of a ticket being Solved reopens the same ticket; a reply **after N days** starts a
brand-new ticket.

## Why a module + core patch

Core FreeScout reopens a header-matched email conversation **unconditionally**, no
matter how long ago it was closed (`FetchEmails::saveCustomerThread()`). The native
mailbox "start a new conversation after N days" checkbox is Chat-widget-only and has
no effect on email — traced at code level (see
`local-specs/arms-freescout/customizations/7-day-reopen-window.md`).

So the fork adds one extensibility hook and keeps the logic here:

- **Core patch** (`app/Console/Commands/FetchEmails.php`, marked `// threls fork patch`):
  the reuse decision is wrapped in
  `\Eventy::filter('conversation.should_reopen', true, $prev_thread->conversation)`.
  Default `true` preserves core's behaviour when this module is absent or inactive.
- **This module** answers the filter, returning `false` for a Solved conversation
  whose close time is older than the window — which drops into core's existing
  "create a new conversation" branch, giving the customer a fresh ticket.

## Behaviour

| Matched conversation | Filter returns | Result |
|---|---|---|
| Not Solved (Active / Pending / On-Hold / Spam) | `true` | Reopen (unchanged) |
| Solved, closed ≤ N days ago | `true` | Reopen the same ticket |
| Solved, closed > N days ago | `false` | New ticket created |

"When was it solved?" uses `closed_at`, falling back to `updated_at` — core only
populates `closed_at` when a **user** closes the ticket, so a Workflows auto-solve
(ARMS-20, no user) can leave it null. The fallback errs toward reopening.

## Config

- `SOLVED_REOPEN_WINDOW_DAYS` (`.env`, default `7`) — calendar days. Matches the
  ARMS-20 Pending windows; the Workflows/date logic has no business-day mode.

## Activation

`php artisan module:enable SolvedReopenWindow` (or via the Modules admin page), then
`php artisan freescout:clear-cache`. Inactive = core's unconditional reopen.

## Accepted edge behaviours

- **Scope:** the window applies only to the email-fetch reopen path
  (`FetchEmails::saveCustomerThread`), the channel ARMS uses. A hypothetical
  reopen via another channel (portal/API) would not be gated by it.
- **Repeat replies to a long-Solved ticket:** each incoming reply is judged
  independently, so two replies to the same expired ticket can each spawn a new
  ticket. Acceptable — the ticket is genuinely closed and each reply is new work.
- **Boundary:** the N-day cutoff is compared to the second and resolves the exact
  instant to "past" (new ticket); immaterial given the fetch scheduler's interval.

## Tests

- `tests/Unit/SolvedReopenWindowTest.php` — the filter across statuses,
  within/after the window, the `closed_at`-null fallback, config, and precedence.
- `tests/Feature/SolvedReopenWindowTest.php` — the core-patch wiring: a real
  `saveCustomerThread` reply reopens a recently-Solved conversation but starts a
  new one past the window.
