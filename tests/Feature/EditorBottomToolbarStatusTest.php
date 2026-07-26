<?php

namespace Tests\Feature;

use App\Conversation;
use App\Mailbox;
use App\MailboxUser;
use App\User;
use Tests\TestCase;

/**
 * Covers the reply/note editor's Status <select>
 * (editor_bottom_toolbar.blade.php), which was hardcoded to three literal
 * options (Active/Pending/Closed), so a module-registered status (On-Hold,
 * code 5, added by Modules/OnHoldStatus) never appeared there even though
 * every other status UI in the app (conv-status dropdown, bulk actions,
 * search filter) already reads Conversation::$statuses. Fixed to loop the
 * same registry, Spam excluded.
 */
class EditorBottomToolbarStatusTest extends TestCase
{
    // This uses the real DB, same as ConversationChangeCustomerTest.
    protected $statuses_backup = [];

    protected function setUp(): void
    {
        parent::setUp();
        \Session::start();

        $this->statuses_backup = Conversation::$statuses;
    }

    protected function tearDown(): void
    {
        Conversation::$statuses = $this->statuses_backup;

        parent::tearDown();
    }

    protected function renderToolbar($mailbox, $conversation)
    {
        return view('conversations.editor_bottom_toolbar', [
            'mailbox'         => $mailbox,
            'conversation'    => $conversation,
            'after_send'      => MailboxUser::AFTER_SEND_STAY,
            'new_converstion' => false,
            // Normally injected app-wide by Laravel's error-sharing middleware.
            'errors'          => new \Illuminate\Support\ViewErrorBag(),
        ])->render();
    }

    protected function makeMailboxAndConversation(User $admin)
    {
        $mailbox = factory(Mailbox::class)->create();
        $mailbox->users()->sync([$admin->id]);

        $conversation = factory(Conversation::class)->create([
            'mailbox_id' => $mailbox->id,
            'status'     => Conversation::STATUS_ACTIVE,
            'state'      => Conversation::STATE_PUBLISHED,
        ]);

        return [$mailbox, $conversation];
    }

    /**
     * The synthetic status is the point of this test, not an afterthought:
     * asserting only Active/Pending/Closed would pass identically against
     * the pre-fix markup, which hardcoded exactly those three literal
     * <option>s — that old code could never render a status it doesn't know
     * about, no matter what's registered. Adding one here is what actually
     * proves the select reads Conversation::$statuses live rather than a
     * fixed list; the sibling test below covers the real On-Hold case
     * end-to-end (including its name-resolution filter), which this test
     * deliberately doesn't depend on.
     */
    public function test_reply_status_select_offers_every_registered_status_except_spam()
    {
        $syntheticStatus = 12345;
        Conversation::$statuses[$syntheticStatus] = 'synthetic';

        $admin = factory(User::class)->create(['role' => User::ROLE_ADMIN]);
        [$mailbox, $conversation] = $this->makeMailboxAndConversation($admin);

        $this->actingAs($admin);

        $html = $this->renderToolbar($mailbox, $conversation);

        $this->assertStringContainsString('value="'.Conversation::STATUS_ACTIVE.'"', $html);
        $this->assertStringContainsString('value="'.Conversation::STATUS_PENDING.'"', $html);
        $this->assertStringContainsString('value="'.Conversation::STATUS_CLOSED.'"', $html);
        $this->assertStringContainsString('value="'.$syntheticStatus.'"', $html);
        $this->assertStringNotContainsString('value="'.Conversation::STATUS_SPAM.'"', $html);
    }

    public function test_reply_status_select_picks_up_module_registered_status()
    {
        // Simulate OnHoldStatus being active without depending on its class,
        // the same way SendAndSetStatusTest does.
        $onHold = 5;
        Conversation::$statuses[$onHold] = 'onhold';
        \Eventy::addFilter('conversation.status_name', function ($name, $status) use ($onHold) {
            return $status == $onHold ? 'On Hold' : $name;
        }, 20, 2);

        $admin = factory(User::class)->create(['role' => User::ROLE_ADMIN]);
        [$mailbox, $conversation] = $this->makeMailboxAndConversation($admin);

        $this->actingAs($admin);

        $html = $this->renderToolbar($mailbox, $conversation);

        $this->assertStringContainsString('value="'.$onHold.'"', $html);
        $this->assertStringContainsString('On Hold', $html);
    }
}
