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
`Conversation::$statuses` directly instead of hardcoding a status list.
Deactivating **Send & Close** first is still the right long-term move (see
Activation below), but this module also actively hides Send & Close's own
button if that manual step is ever skipped — see "Suppressing Send & Close"
— rather than relying on the step being remembered. Confirmed live on the
demo: both modules were active at once, and the dropdown showed Send & Close
sitting there completely unchanged, next to Send & Solve, which isn't what
"change Send & Close to Send & Solve" means.

## How it works

`editor_bottom_toolbar.blade.php` already renders a `dropdown-after-send`
menu next to the Send button, and already calls
`@action('conversation.prepend_send_dropdown', $conversation, $mailbox,
$new_converstion ?? false)` at the very top of it — a hook point core ships
with. The real Send & Close module (its source pulled from the production
install to check this module against it) listens on that same action with a
zero-argument callback, ignoring whatever's passed — it renders
unconditionally, including on a brand-new outgoing conversation. This module
follows that precedent rather than adding an untested gate of its own, and
echoes:

- one primary `<li>` — a `<button type="button" class="btn btn-success
  btn-block">`, matching Send & Close's own markup exactly, always labelled
  **Send & Solve**, `data-send-status` hardcoded to
  `Conversation::STATUS_CLOSED`
- one plain `<li><a>` per remaining registered status (Active/New, Pending,
  and On-Hold if `OnHoldStatus` is active) except Spam, labelled "Send as
  \<name\>" via `Conversation::statusCodeToName()` — so labels stay in sync
  with the existing New/Solved renames (`ActiveToNewLabel`, core's own
  Closed→Solved) and the On-Hold name filter, with no separate lang file to
  maintain here.

Because the loop reads the live registry rather than a fixed list, the
secondary buttons appear/disappear automatically as status-registering
modules (`OnHoldStatus`) are activated/deactivated — no coupling between the
two modules' code.

`Public/js/module.js` (enqueued via the `javascripts` Eventy filter, same
pattern as `SortableCustomFields`) binds one delegated click handler for
every `.sas-send-status` item, which does two things:

1. Sets the Status `<select>` to the button's `data-send-status` — but
   scoped to `.note-statusbar:visible:first select[name="status"]:first`,
   not the more obvious-looking `#editor_bottom_toolbar` select itself.
   `main.js`'s `convEditorInit()` clones the *entire* hidden
   `#editor_bottom_toolbar` template's HTML into Summernote's own
   `.note-statusbar` element on every editor init, so the DOM ends up with
   two elements named `status`: the original (outside the `<form>`, never
   submitted) and this visible clone (inside the `<form>`, the one
   `form.serialize()` actually picks up). This selector is copied verbatim
   from the real Send & Close module's own JS, which already handles this
   correctly in production — not something to rediscover by reading the
   Blade templates alone.
2. Triggers whichever `.btn-reply-submit` button is currently visible
   (Send/Forward/Note/Create — same selector core's own Cmd+Enter shortcut
   uses). No new backend endpoint or request format — this produces the
   exact same `action=send_reply` POST the Status select + Send button
   already do today, just pre-filled.

## Suppressing Send & Close

`Public/css/style.css` (enqueued via the `stylesheets` Eventy filter) hides
Send & Close's own button outright:

```css
li:has(> .sc-reply-submit) { display: none; }
li:has(> .sc-reply-submit) + li.divider { display: none; }
```

`sc-reply-submit` is Send & Close's real button class (confirmed from its
production source, not guessed). CSS rather than JS DOM removal here
specifically because of the Summernote clone behaviour described above:
`.note-statusbar` gets rebuilt from scratch on every editor init, so a
one-time JS removal at page load would go stale the next time that happens,
where a plain CSS rule just keeps matching. This is a pure CSS `:has()`
selector — it does nothing (Send & Close's button stays visible, same as
today) on a browser old enough not to support it, rather than erroring; every
current mainstream browser has supported `:has()` since 2023.

If Send & Close isn't active at all, this CSS matches nothing and is a
no-op.

## Translations

Send & Close ships `Resources/lang/*.json` translations for its button
label in 11 locales (cs, bg, pl, fi, it, ru, zh-CN, fr, sk, de, pt-BR). This
module matches that: the same locales, same `loadJsonTranslationsFrom`
mechanism, covering the two strings this module introduces ("Send & Solve"
and "Send as") that aren't already covered by core's or another module's
translation files — `Conversation::statusCodeToName()`'s own output (New,
Pending, On Hold, Solved) is already translated independently via core and
`ActiveToNewLabel`/`OnHoldStatus`. Translated by general language knowledge
rather than a native-speaker review, same confidence level as any other
non-English string in this codebase — worth a native check before this
instance is ever switched off English.

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
`app/Module.php`). Deactivating the paid Send & Close module too is still
recommended (no point loading two modules' JS for one job), but no longer
required for a clean-looking dropdown — see "Suppressing Send & Close".

## Tests

`tests/Unit/SendAndSetStatusTest.php` — the primary/secondary item set
against a fixed `Conversation::$statuses` registry (with and without
On-Hold registered), that it renders even when composing a new conversation,
that Spam and Closed are never rendered as secondary items, that every
shipped language file is valid JSON carrying both keys, that switching the
app locale actually resolves a translated string end to end, and that the
suppression CSS ships and targets Send & Close's real button class. The CSS
rule's actual effect (an element disappearing) isn't something PHPUnit can
verify — that's a live-browser check, not covered here.
