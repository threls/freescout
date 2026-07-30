<?php

namespace Tests\Feature;

use App\Thread;
use Tests\TestCase;

/**
 * Covers ARMS-49 task 2: "Use as Reply" on an internal note written by a
 * macro, so its text can be moved into the reply editor without copying and
 * pasting it by hand.
 *
 * The client's direction was to leave the macros themselves alone, so these
 * tests are all about the gate — which threads get the option and which
 * don't. Whether a macro's note really does arrive with no author is a fact
 * about the paid Workflows module, which isn't installed in this repo, so
 * that one assumption is documented in the provider and needs confirming on
 * the demo server rather than here.
 *
 * No DB rows are needed: the listener only ever reads a Thread's type, author
 * and body, so in-memory models are enough and nothing has to be cleaned up.
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

    protected function makeThread($attributes = [])
    {
        $thread = new Thread();
        $thread->type = Thread::TYPE_NOTE;
        $thread->created_by_user_id = null;
        $thread->body = '<div>Your request has been noted and referred to the Advisory Board.</div>';

        foreach ($attributes as $key => $value) {
            $thread->{$key} = $value;
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
     * A note an agent typed themselves records its author, and they already
     * have the text in front of them. Only the authorless (macro) ones get
     * the option.
     */
    public function test_option_hidden_on_a_note_written_by_an_agent()
    {
        $html = $this->renderThreadMenu($this->makeThread(['created_by_user_id' => 7]));

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
