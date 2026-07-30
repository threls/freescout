<?php

namespace Tests\Feature;

use App\Thread;
use App\User;
use Tests\TestCase;

/**
 * Covers ARMS-49 task 2: "Use as Reply" on an internal note written by a
 * macro, so its text can be moved into the reply editor without copying and
 * pasting it by hand.
 *
 * The client's direction was to leave the macros themselves alone, so these
 * tests are all about the gate — which threads get the option and which
 * don't.
 *
 * A macro authors its note as a "robot" user rather than as a person, which
 * is confirmed against the demo server: it has a robot user literally named
 * "Workflow", and 23 of its 30 notes are authored by robots. The Workflows
 * module isn't installed in this repo, so these tests stand the equivalent
 * up by hand.
 *
 * No DB rows are needed: the listener only ever reads a Thread's type, body
 * and author, and the author relation can be set directly on the model, so
 * in-memory models are enough and nothing has to be cleaned up.
 */
class UseNoteAsReplyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../../Modules/UseNoteAsReply/Providers/UseNoteAsReplyServiceProvider.php';

        // Booted per test rather than once: each test gets a fresh
        // application, so Eventy's listeners (and the module's view
        // namespace) don't carry over. This also means the action can't
        // accumulate duplicate listeners across tests.
        (new \Modules\UseNoteAsReply\Providers\UseNoteAsReplyServiceProvider(app()))->boot();
    }

    /**
     * A macro's note by default: authored by a robot user, the way the
     * Workflows module's own "Workflow" user does it.
     */
    protected function makeThread($attributes = [], $author_type = User::TYPE_ROBOT)
    {
        $thread = new Thread();
        $thread->type = Thread::TYPE_NOTE;
        $thread->created_by_user_id = 5;
        $thread->body = '<div>Your request has been noted and referred to the Advisory Board.</div>';

        foreach ($attributes as $key => $value) {
            $thread->{$key} = $value;
        }

        if ($author_type !== null && $thread->created_by_user_id) {
            $author = new User();
            $author->id = $thread->created_by_user_id;
            $author->type = $author_type;

            // Set directly so the gate doesn't try to load the relation from
            // the database, which these tests deliberately don't touch.
            $thread->setRelation('created_by_user_cached', $author);
        }

        return $thread;
    }

    protected function renderThreadMenu($thread)
    {
        ob_start();
        \Eventy::action('thread.menu', $thread);

        return ob_get_clean();
    }

    public function test_option_shows_on_a_macro_note()
    {
        $html = $this->renderThreadMenu($this->makeThread());

        $this->assertStringContainsString('unar-use-as-reply', $html);
        $this->assertStringContainsString('Use as Reply', $html);
    }

    /**
     * A note an agent typed is authored by that agent, and they already have
     * the text in front of them. Only the robot-authored (macro) ones get the
     * option.
     */
    public function test_option_hidden_on_a_note_written_by_an_agent()
    {
        $html = $this->renderThreadMenu($this->makeThread([], User::TYPE_USER));

        $this->assertSame('', $html);
    }

    /**
     * Guards the mistake this gate was originally built on: authorless notes
     * don't exist in practice (customer messages are the authorless threads),
     * so treating "no author" as the macro signal meant the option never
     * appeared at all.
     */
    public function test_option_hidden_on_a_note_with_no_author()
    {
        $html = $this->renderThreadMenu($this->makeThread(['created_by_user_id' => null]));

        $this->assertSame('', $html);
    }

    public function test_option_hidden_on_replies_and_customer_messages()
    {
        foreach ([Thread::TYPE_MESSAGE, Thread::TYPE_CUSTOMER] as $type) {
            $html = $this->renderThreadMenu($this->makeThread(['type' => $type]));

            $this->assertSame('', $html, 'thread type '.$type.' must not offer the option');
        }
    }

    /**
     * thread.menu also fires from the line-item branch of the thread view
     * (status changes, assignments and so on), which has no body to copy.
     */
    public function test_option_hidden_on_line_items()
    {
        $html = $this->renderThreadMenu($this->makeThread([
            'type' => Thread::TYPE_LINEITEM,
            'body' => '',
        ]));

        $this->assertSame('', $html);
    }

    /**
     * Nothing to copy across, so the option would do nothing if clicked.
     * Markup-only bodies count as empty.
     */
    public function test_option_hidden_when_the_note_has_no_text()
    {
        foreach (['', '   ', '<div><br></div>'] as $body) {
            $html = $this->renderThreadMenu($this->makeThread(['body' => $body]));

            $this->assertSame('', $html, 'empty body "'.$body.'" must not offer the option');
        }
    }

    /**
     * The JS reads its confirmation wording off the rendered element, so that
     * the string stays translatable without patching core's JS vars.
     */
    public function test_rendered_option_carries_the_confirmation_text()
    {
        $html = $this->renderThreadMenu($this->makeThread());

        $this->assertStringContainsString('data-confirm="', $html);
        $this->assertStringContainsString('Replace what you have already written', $html);
    }

    public function test_module_javascript_is_registered()
    {
        $javascripts = \Eventy::filter('javascripts', []);

        $this->assertContains('/modules/usenoteasreply/js/module.js', $javascripts);
    }
}
