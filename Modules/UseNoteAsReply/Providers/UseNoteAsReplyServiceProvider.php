<?php

namespace Modules\UseNoteAsReply\Providers;

use App\Thread;
use Illuminate\Support\ServiceProvider;

/**
 * Adds "Use as Reply" to the options dropdown of an internal note written by
 * a macro, which opens the reply editor and drops the note's text into it
 * (ARMS-49, task 2).
 *
 * ARMS's macros post their canned text as an internal note, which an agent
 * then had to select and copy into the reply box by hand. Everything needed
 * to skip that already exists in core, so this module adds no route, no
 * table and no core patch:
 *
 * - `thread.menu` is an upstream Blade action fired from inside the thread's
 *   own dropdown (resources/views/conversations/partials/thread.blade.php),
 *   which is where the item belongs.
 * - The note's body is already rendered on the page inside
 *   `.thread-content`, so Public/js/module.js reads it from the DOM rather
 *   than fetching it again.
 * - main.js's own `prepareReplyForm()` / `showReplyForm({body: ...})` pair
 *   (the same two calls the Reply button itself makes) opens the editor and
 *   sets its contents.
 *
 * The macros themselves are untouched, per the client's direction: they keep
 * posting notes exactly as they do now, and the note stays in place after
 * being used so the history still shows what the macro did.
 */
class UseNoteAsReplyServiceProvider extends ServiceProvider
{
    const MODULE_ALIAS = 'usenoteasreply';

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', self::MODULE_ALIAS);
        $this->hooks();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
    }

    public function hooks()
    {
        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(self::MODULE_ALIAS).'/js/module.js';

            return $javascripts;
        });

        // thread.menu fires twice in thread.blade.php: once for line items
        // (admin-only branch) and once for messages/notes. isMacroNote()
        // rules out line items either way, so the item only ever renders on
        // the notes it's meant for.
        \Eventy::addAction('thread.menu', function ($thread) {
            if (!$this->isMacroNote($thread)) {
                return;
            }

            echo \View::make(self::MODULE_ALIAS.'::menu_item')->render();
        });
    }

    /**
     * A note posted by a macro rather than typed by an agent.
     *
     * There is no core column saying "a macro made this", and the Workflows
     * module is paid and lives outside this repo, so keying off its internals
     * would break on any update of it. What is reliable in core: a note an
     * agent wrote always records its author, and a macro's note has none —
     * which is exactly why such a note renders its author as "Macro" through
     * the `thread.action_person` filter instead of a user's name (see
     * Thread::getActionPerson(), which returns an empty string when there is
     * no created_by_user).
     *
     * So "note with no author" is the signal, and it needs nothing from the
     * paid module. If another module ever posts authorless notes of its own
     * they would also qualify, which is harmless: the item only copies text
     * into the agent's own reply box.
     */
    protected function isMacroNote($thread)
    {
        if (empty($thread) || !$thread instanceof Thread) {
            return false;
        }

        if (!$thread->isNote() || !empty($thread->created_by_user_id)) {
            return false;
        }

        // Nothing to copy across.
        return trim(strip_tags($thread->body ?? '')) !== '';
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
