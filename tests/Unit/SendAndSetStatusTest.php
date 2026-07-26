<?php

namespace Tests\Unit;

use App\Conversation;
use Tests\TestCase;

/**
 * Covers the SendAndSetStatus module: renders a primary "Send & Solve"
 * button plus a "Send as <status>" item per remaining registered status,
 * via the conversation.prepend_send_dropdown Blade action that
 * editor_bottom_toolbar.blade.php already fires.
 */
class SendAndSetStatusTest extends TestCase
{
    const ONHOLD = 5;

    protected $statuses_backup = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The module is not autoloaded while inactive — load the provider directly.
        require_once __DIR__.'/../../Modules/SendAndSetStatus/Providers/SendAndSetStatusServiceProvider.php';

        // Static array persists across tests in the same process — back it up
        // (same precaution as OnHoldStatusTest).
        $this->statuses_backup = Conversation::$statuses;
    }

    protected function tearDown(): void
    {
        Conversation::$statuses = $this->statuses_backup;

        parent::tearDown();
    }

    protected function bootModule()
    {
        (new \Modules\SendAndSetStatus\Providers\SendAndSetStatusServiceProvider(app()))->boot();
    }

    protected function renderDropdown($conversation = null, $mailbox = null, $new_conversation = false)
    {
        ob_start();
        \Eventy::action('conversation.prepend_send_dropdown', $conversation, $mailbox, $new_conversation);

        return ob_get_clean();
    }

    public function test_primary_button_is_send_and_solve_targeting_closed()
    {
        $this->bootModule();

        $html = $this->renderDropdown();

        // e() escapes the & for HTML output.
        $this->assertStringContainsString('Send &amp; Solve', $html);
        $this->assertStringContainsString('data-send-status="'.Conversation::STATUS_CLOSED.'"', $html);
        $this->assertStringContainsString('btn-success', $html);
    }

    public function test_secondary_items_cover_active_and_pending_but_not_closed_or_spam()
    {
        $this->bootModule();

        $html = $this->renderDropdown();

        $this->assertStringContainsString('data-send-status="'.Conversation::STATUS_ACTIVE.'"', $html);
        $this->assertStringContainsString('data-send-status="'.Conversation::STATUS_PENDING.'"', $html);
        $this->assertStringContainsString(__('Send as').' '.Conversation::statusCodeToName(Conversation::STATUS_ACTIVE), $html);
        $this->assertStringContainsString(__('Send as').' '.Conversation::statusCodeToName(Conversation::STATUS_PENDING), $html);

        // Closed is the primary button only — must not also appear as a
        // "Send as" secondary item.
        $this->assertStringNotContainsString(__('Send as').' '.Conversation::statusCodeToName(Conversation::STATUS_CLOSED), $html);

        // Spam is never a valid reply-time destination status.
        $this->assertStringNotContainsString('data-send-status="'.Conversation::STATUS_SPAM.'"', $html);
    }

    /**
     * The secondary set is read from the live registry, not a fixed list —
     * a module-registered status (On-Hold) must appear automatically with
     * no coupling to OnHoldStatus's own class.
     */
    public function test_module_registered_status_appears_automatically()
    {
        Conversation::$statuses[self::ONHOLD] = 'onhold';
        \Eventy::addFilter('conversation.status_name', function ($name, $status) {
            return $status == self::ONHOLD ? 'On Hold' : $name;
        }, 20, 2);

        $this->bootModule();

        $html = $this->renderDropdown();

        $this->assertStringContainsString('data-send-status="'.self::ONHOLD.'"', $html);
        $this->assertStringContainsString(__('Send as').' On Hold', $html);
    }

    public function test_skipped_entirely_for_a_new_conversation()
    {
        $this->bootModule();

        $html = $this->renderDropdown(null, null, true);

        $this->assertSame('', $html);
    }
}
