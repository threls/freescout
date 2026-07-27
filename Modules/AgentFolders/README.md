# AgentFolders

Adds a fixed-**Assignee** filter to the paid **Custom Folders** module, so
an admin can create a folder that always shows one specific agent's
conversations - regardless of who is looking at it. Tracked as
[ARMS-46](https://threls.atlassian.net/browse/ARMS-46).

## The gap this fills

The client wants a per-agent overview: something you click on (like a
"Jack Sultana" folder) to see that agent's actual ticket list. Custom
Folders already has a **"Show only own conversations"** setting, but it
resolves to `Auth::id()` at view time - the same folder shows *different*
conversations depending on who is currently logged in. There is no
existing setting that pins a folder's content to one specific, fixed agent
chosen when the folder was created. (Custom Folders' own `setCounters()`
even has this commented out: `// if (!empty($folder->user_id)) { $query->
where('conversations.user_id', $folder->user_id); }` - the module's own
author appears to have prototyped exactly this and shelved it, likely
because `user_id` was already committed to a different role: see below.)

Custom Folders' "Show Only To" field (`folder->user_id`) looks like it
might already do this, but it doesn't - it only controls **who can see the
folder in their sidebar at all**
(`CustomFoldersServiceProvider::hooks()`'s `mailbox.folders` filter unsets
the folder from anyone whose id doesn't match). It has zero effect on
which conversations appear inside it.

## How it works

Custom Folders is a paid, runtime-installed module and is **not tracked by
this repo's git** (`.gitignore`'s blanket `/Modules/*` rule has no
allowlist entry for it, unlike this module). Rather than hand-editing its
files, the query-filtering behavior lives entirely in this module's own
Eventy hooks, layered on top of Custom Folders' existing ones:

- Custom Folders' `folder.conversations_query` and `conversation.get_
  nearby_query` filters both **discard whatever query they're handed and
  build a fresh one** (mailbox + state + tag + status + own_only/
  unassigned). Eventy filters run in ascending priority order
  (`overrides/tormjens/eventy/src/Event.php`, `usort` by priority) and
  Custom Folders registers at the default priority (20), so this module
  registers its own listeners on the *same* hooks at priority 30 -
  guaranteed to run after Custom Folders, receiving its fully-built query
  and just adding `where('conversations.user_id', $assignee_id)` on top.
  No need to touch `CustomFoldersServiceProvider.php` at all.
- `folder.update_counters` needs its own listener too: Custom Folders'
  `setCounters()` builds its *own* query directly rather than through
  `folder.conversations_query`, so without this the folder would list the
  right conversations but show the mailbox-wide count next to it in the
  sidebar. This module's listener re-invokes the (already assignee-aware)
  `folder.conversations_query` chain rather than re-deriving Custom
  Folders' mailbox/tag/status logic, seeded with a real base query (not
  relying on Custom Folders' listener being present to replace a `null`
  seed) so this degrades safely if Custom Folders is ever deactivated.

`AgentFoldersServiceProvider::CUSTOM_FOLDER_TYPE` hardcodes Custom Folders'
`TYPE_CUSTOM` value (`200`) rather than referencing that class directly -
it's a fixed, documented value on their side ("max 255" per their own
comment), and this way the check works whether or not Custom Folders
happens to be installed/active, and is straightforward to test without a
stub class standing in for a module this repo doesn't have.

## The two patches that ARE unavoidable

"Which agent" has to be captured and saved somewhere, and that requires
touching Custom Folders' own files - a new field on the create/update form,
and one line in the controller to persist it into the folder's `meta`
blob. `php artisan agentfolders:patch-custom-folders`
(`Console/PatchCustomFoldersAssignee.php`) does this the same way
`onholdstatus:patch-workflows` already patches the (also paid, also not
git-tracked) Workflows module:

- **Idempotent** - a no-op if already patched, safe to run on every deploy
- **Guarded per file** - before writing anything, each of the two files is
  checked to contain the expected content exactly once; if Custom Folders
  has changed shape since this was written, it refuses to modify that file
  and exits non-zero rather than guessing
- **Backed up** - writes `.bak` next to each file before any change
- **Reversible** - `--revert` removes the field from both files again
- **No-ops cleanly** per file if Custom Folders isn't installed, or if only
  one of the two files is present

Add `$FORGE_PHP artisan agentfolders:patch-custom-folders` to the deploy
script, right after `onholdstatus:patch-workflows`, so it self-heals after
every Custom Folders update the same way that patch already does for
Workflows.

The patch's exact expected text was transcribed from a live-server
terminal paste, not verified byte-for-byte against the file - the
occurrence-count guard above is what protects the real files if that
transcription turns out to be slightly off. **Run it once manually on the
demo server and check the output before wiring it into the automatic
deploy step.**

## Creating a per-agent folder

Manual, one at a time, the same way every other custom folder is created
today: `Manage > Custom Folders > New Folder`, pick the new **Assignee**
dropdown (added next to "Show Only To" by the patch above), leave "Show
only own conversations" unchecked. This was a deliberate choice over
auto-generating/syncing folders as agents come and go - simpler, no extra
moving parts, and matches how every other custom folder already works.

## Activation

Manage → Modules → AgentFolders → Activate. Requires Custom Folders to
already be installed and patched (see above) - without the patch, the
Assignee field won't appear on the folder form and this module's filters
will simply never find `meta['assignee_id']` set on anything.

## Tests

- `tests/Feature/AgentFoldersServiceProviderTest.php` - the filtering
  logic itself (conversations_query, update_counters, get_nearby_query),
  including the core guarantee that the filter is *not* relative to
  whoever's viewing (unlike "Show only own conversations").
- `tests/Feature/PatchCustomFoldersAssigneeTest.php` - the patch command,
  against fixture files mirroring Custom Folders' real form and controller
  (mirrors `tests/Feature/PatchWorkflowsStatusesTest.php`'s approach for
  the same reason: the real module isn't installed in this repo).
