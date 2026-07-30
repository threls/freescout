# Use Note As Reply

ARMS's macros post their canned text as an internal note, which an agent then
had to select and copy into the reply box by hand ([ARMS-49](https://threls.atlassian.net/browse/ARMS-49),
task 2). This adds a **Use as Reply** option to those notes' own options
dropdown: it opens the reply editor and drops the note's text straight into it.

The macros themselves are untouched, per the client's direction (they keep
posting notes exactly as they do now), and the note stays in place after being
used, so the conversation history still shows what the macro did.

## Requirements

* Confirmed compatible with this fork's core at `1.8.229`. Uses only
  extension points already present upstream, so there are **no core patches**,
  no new route and no new table.
* No dependency on the paid Workflows module. It is what posts the notes in
  practice, but nothing here reads that module's code or tables (see "Which
  notes get the option" below).

## How it works

Everything needed was already in core:

* `thread.menu` is an existing Blade action fired from inside the thread's own
  dropdown (`resources/views/conversations/partials/thread.blade.php`), which
  is exactly where the option belongs.
* The note's body is already rendered on the page inside `.thread-content`, so
  `Public/js/module.js` reads it out of the DOM instead of fetching it again.
  That is why there is no new endpoint.
* `main.js`'s own `prepareReplyForm()` and `showReplyForm({body: …})` — the
  same two calls the Reply button itself makes — open the editor and set its
  contents.

## Which notes get the option

There is no core column recording "a macro made this", and Workflows is a paid
module living outside this repo, so keying off its internals would break the
moment it updates. What *is* reliable in core: a note an agent typed always
records its author, and a macro's note has none. That is precisely why such a
note renders its author as "Macro" via the `thread.action_person` filter rather
than a user's name (`Thread::getActionPerson()` returns an empty string when
there is no `created_by_user`).

So the gate is **a note, with no author, that has some text in it**, which
needs nothing from the paid module.

**Assumption still to confirm on the demo server**: that a macro's note really
does arrive with `created_by_user_id` empty. It follows from how the author is
rendered, but it hasn't been checked against real data — the grep that would
have confirmed it failed on the server twice. If it turns out macros do record
an author, the gate in `isMacroNote()` is the one place to change.

If another module ever posts authorless notes of its own, they would also get
the option. That is harmless: it only copies text into the agent's own reply
box.

## Not overwriting an agent's own writing

Core deliberately blocks switching between reply and note once the editor is
open, because each mode keeps its own draft (see the comment on `.conv-reply`'s
handler in `main.js`). This module respects that rather than working around it:

* **Editor closed** — opens it as a reply, same as clicking Reply, and fills it
  in.
* **Editor already open as a reply** — only the text changes. It deliberately
  does *not* go through `showReplyForm()` in this case, because that resets the
  status and assignee selects to their defaults and clears attachments, undoing
  choices the agent has already made.
* **Editor open with something written in it** — asks first. Confirmed with the
  client that asking is preferred over silently replacing. The check compares
  the editor's *text*, not its HTML, since Summernote leaves wrapper markup
  around an otherwise empty editor.

**Known caveat, inherited from core**: if the editor is open as a *note* with a
draft already saved, confirming the switch to reply can leave that draft note
behind. This is the exact situation core avoids by refusing the switch at all;
the confirmation is what stands in for that. Worth watching once agents use it,
and the fallback would be to refuse the switch while a note draft is open.

## Translations

The confirmation wording is carried on the rendered element as `data-confirm`
rather than added to core's `resources/views/js/vars.blade.php`, so the string
stays translatable without a core patch. Same approach the Workflows module
uses for its own delete confirmation.

## Activation

Manage → Modules → Use Note As Reply → Activate (activation state is
DB-driven; `module.json`'s `active` flag is ignored). No migration.

Activating also creates the `public/modules/usenoteasreply` symlink that serves
this module's JS, via core's own `Module::checkSymlinks()`. If the option
renders but clicking it does nothing, that symlink is the first thing to check
— `php artisan freescout:clear-cache` recreates it. Same for the other modules
here that ship a `Public/js` folder.

## Tests

`tests/Feature/UseNoteAsReplyTest.php` — the option appears on an authorless
note; is hidden on a note an agent wrote, on replies and customer messages, on
line items, and on notes with no text (including markup-only bodies); the
rendered element carries its confirmation wording; and the module's JS is
registered. Each gate was mutation-tested: the condition removed, the test
confirmed to fail, then restored.
