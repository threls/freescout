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
moment it updates. What core does give us is *who authored the note*: a macro
posts its own as a **robot** user (`User::TYPE_ROBOT`, whose comment in core
reads "Workflows, teams, etc."), where an agent's note records the agent.

So the gate is **a note, authored by a robot, that has some text in it**, which
needs nothing from the paid module.

Confirmed against the demo server rather than assumed:

* There is a robot user literally named "Workflow", which is where the name
  shown on those notes comes from. The Workflows module doesn't hook
  `thread.action_person` at all (grepped, no matches) — it simply authors the
  thread as that user.
* Of 30 notes, 23 are authored by robots and 7 by real agents, so both sides of
  the gate exist in real data.

**An earlier version of this gate was wrong** and worth recording, since the
reasoning was plausible: it keyed on a note having *no* author, inferred from
`Thread::getActionPerson()` returning an empty string when there is no
`created_by_user`. In reality no note is authorless (customer messages are the
authorless threads), so the option would never have appeared at all. There is a
regression test for that specific case.

Teams are also robot users, so a note ever authored by a team would qualify
too. That is harmless: the option only copies text into the agent's own reply
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

**Known caveat, inherited from core, and observed rather than theorised**: core
autosaves whatever is in the editor as a draft thread. So if an agent has
already typed something, autosave has stored it, and they then confirm the
replacement, their text stays behind as a `[Draft]` on the conversation (and
counts towards the Drafts folder). Their work isn't lost, which is arguably the
better failure, but the conversation does pick up a draft they need to discard.

This was seen during the browser verification, not inferred. It only happens on
the replace path, which requires the agent to have typed something and then
confirmed. The alternative would be to refuse the action while a draft exists
and ask them to discard it first, which seemed more annoying than the draft.

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

Two things worth knowing, both hit while verifying this locally:

* The list of installed modules is cached in Laravel's cache for 60 minutes
  (`config/modules.php`). Activating through Manage → Modules clears it; flipping
  the `modules` table directly does not, so the module stays invisible until that
  cache expires. `Cache::forget('laravel-modules')` is the shortcut.
* Module JS is concatenated into core's minified `/js/builds/…` bundle rather
  than served as its own `<script src>`. That happens automatically once the
  module registers, so there's no build step to run, but it does mean you won't
  find a `usenoteasreply` script tag in the page source.

## Tests

`tests/Feature/UseNoteAsReplyTest.php` — the option appears on a robot-authored
note; is hidden on a note an agent wrote, on a note with no author at all (the
regression guard for the wrong gate described above), on replies and customer
messages, on line items, and on notes with no text (including markup-only
bodies); the rendered element carries its confirmation wording; and the
module's JS is registered. Each gate was mutation-tested: the condition
removed, the test confirmed to fail, then restored.

`Public/js/module.js` has no automated test, but it was driven in a real browser
(Playwright against the local install, with a robot-authored note and an
agent-written one on the same conversation) and all four paths were exercised:

* editor closed → opens as a reply, in reply mode, with the note's text in the
  editor **and** in the hidden `body` field the form actually submits
* editor already open with the agent's own text → prompts, and cancelling leaves
  their text untouched
* same, accepting → replaces it with the note's text
* editor open as a *note* → switches to reply mode and fills it in

Also checked: the option renders on the robot-authored note and not on the
agent's, the JS really is present in the served bundle, and the page logs no JS
errors throughout.
