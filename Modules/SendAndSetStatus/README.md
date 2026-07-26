# SendAndSetStatus

Adds one-click **Send + Status** actions to the reply/note editor's Send
dropdown: a primary **Send & Solve** button plus a **Send as \<status\>**
item for every other registered status — New, Pending, and (when active)
On-Hold.

## Why this exists instead of the paid Send & Close module

The client already had FreeScout's official paid **Send & Close** module
installed: a single green button that sets status to Closed and sends in one
click. Omar asked to generalize it — rename to "Send & Solve" and add
equivalents for New / On-Hold / Pending.

That module is a good fit for the "Closed only" case it ships with, but two
things rule out extending it directly:

1. It's a paid, runtime-installed module and — like Workflows (see
   `Modules/OnHoldStatus`'s README) — **not tracked by this repo's git**
   (`.gitignore`'s blanket `/Modules/*` rule has no allowlist entry for it).
   Hand-editing its files would be invisible to git and silently wiped by the
   next module update or reinstall.
2. It hardcodes one status (Closed) with no Eventy hook to extend the set —
   so even a runtime patch (the `onholdstatus:patch-workflows` style of fix)
   couldn't teach it about On-Hold, since On-Hold doesn't exist as a concept
   in core FreeScout at all; it's a status this fork's own `OnHoldStatus`
   module registers into `Conversation::$statuses` at boot.

So this module reimplements the same one-click idea from scratch, reading
`Conversation::$statuses` directly instead of hardcoding a status list —
deactivate **Send & Close** before activating this one, to avoid two
competing buttons in the same dropdown.

## How it works

`editor_bottom_toolbar.blade.php` already renders a `dropdown-after-send`
menu next to the Send button, and already calls
`@action('conversation.prepend_send_dropdown', $conversation, $mailbox,
$new_converstion ?? false)` at the very top of it — a hook point core ships
with, used by nothing else in this codebase yet. This module's provider
listens on that action and echoes:

- one primary `<li>` — a green `.btn-success` button, always labelled **Send
  & Solve**, `data-send-status` hardcoded to `Conversation::STATUS_CLOSED`
- one plain `<li>` per remaining registered status (Active/New, Pending, and
  On-Hold if `OnHoldStatus` is active) except Spam, labelled "Send as
  \<name\>" via `Conversation::statusCodeToName()` — so labels stay in sync
  with the existing New/Solved renames (`ActiveToNewLabel`, core's own
  Closed→Solved) and the On-Hold name filter, with no separate lang file to
  maintain here.

Because the loop reads the live registry rather than a fixed list, the
secondary buttons appear/disappear automatically as status-registering
modules (`OnHoldStatus`) are activated/deactivated — no coupling between the
two modules' code.

Skipped entirely for a brand-new outgoing conversation (`$new_converstion`)
— same gate core already uses for "Conversation History" /
"Change default redirect" in this same dropdown, since a conversation with no
prior status has nothing to "leave".

`Public/js/module.js` (enqueued via the `javascripts` Eventy filter, same
pattern as `SortableCustomFields`) binds one delegated click handler for
every `.sas-send-status` item: set the shared Status `<select>` to the
button's `data-send-status`, then trigger whichever `.btn-reply-submit`
button is currently visible (Send/Forward/Note/Create — same selector core's
own Cmd+Enter shortcut uses). No new backend endpoint or request format —
this produces the exact same `action=send_reply` POST the Status select +
Send button already do today, just pre-filled.

## Companion fix (core fork patch)

The reply editor's own Status `<select>`
(`resources/views/conversations/editor_bottom_toolbar.blade.php`) was
hardcoded to three literal options (Active/Pending/Closed) rather than
looping `Conversation::$statuses` like every other status UI in the app
(the `conv-status` dropdown, bulk actions, search filter) — so On-Hold never
appeared there even after `OnHoldStatus` registered it everywhere else.
Fixed as a direct core patch (not part of this module, since it's a bug in a
core view, not new functionality) to loop the registry the same way, Spam
excluded. This module's quick-send buttons and that dropdown now agree on
the same set of selectable statuses.

## Activation

Manage → Modules → SendAndSetStatus → Activate (activation state lives in
the database; `module.json`'s `active` flag is ignored, per
`app/Module.php`). Deactivate the paid Send & Close module first.

## Tests

`tests/Unit/SendAndSetStatusTest.php` — the primary/secondary item set
against a fixed `Conversation::$statuses` registry (with and without
On-Hold registered), the `$new_converstion` gate, and that Spam and Closed
are never rendered as secondary items.
