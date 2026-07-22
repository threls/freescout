<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Covers ARMS-28: the Previous Conversations sidebar shows each row's
 * status and last-reply date, reusing Conversation::getStatusColor()/
 * getStatusName()/getLastReplyAtHuman() rather than anything new.
 */
class PrevConvsStatusDateTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeMailbox()
    {
        return factory(Mailbox::class)->create();
    }

    protected function makeUser()
    {
        return factory(User::class)->create(['role' => User::ROLE_USER]);
    }

    protected function makeConversation($mailboxId, $customerId, $userId, $status, $lastReplyAt)
    {
        return factory(Conversation::class)->create([
            'type'               => Conversation::TYPE_EMAIL,
            'mailbox_id'         => $mailboxId,
            'customer_id'        => $customerId,
            'status'             => $status,
            'state'              => Conversation::STATE_PUBLISHED,
            'last_reply_at'      => $lastReplyAt,
            'created_by_user_id' => $userId,
        ]);
    }

    protected function renderSidebar($customer, $prevConversationIds)
    {
        $prevConversations = Conversation::whereIn('id', $prevConversationIds)
            ->orderBy('id')
            ->paginate(10);

        return view('conversations/partials/prev_convs_short', [
            'prev_conversations' => $prevConversations,
            'customer'           => $customer,
        ])->render();
    }

    public function test_row_shows_status_and_last_reply_date()
    {
        $mailbox = $this->makeMailbox();
        $user = $this->makeUser();
        $customer = factory(Customer::class)->create();

        // getLastReplyAtHuman() requires a genuine reply thread, not just a
        // populated last_reply_at column (fixed 22 Jul — see
        // Conversation::hasReplied()'s docblock and LastReplyAtColumnTest),
        // so this fixture needs one to actually exercise the date span.
        $conversation = $this->makeConversation($mailbox->id, $customer->id, $user->id, Conversation::STATUS_PENDING, null);
        factory(Thread::class)->create([
            'conversation_id' => $conversation->id,
            'type'            => Thread::TYPE_MESSAGE,
            'state'           => Thread::STATE_PUBLISHED,
            'user_id'         => $user->id,
            'created_by_user_id' => $user->id,
        ]);
        \DB::table('conversations')->where('id', $conversation->id)->update(['last_reply_at' => '2026-01-05 10:00:00']);
        $conversation = $conversation->fresh();

        $html = $this->renderSidebar($customer, [$conversation->id]);

        $this->assertStringContainsString('prev-conv-status', $html);
        $this->assertStringContainsString($conversation->getStatusName(), $html);
        $this->assertStringContainsString($conversation->getStatusColor(), $html);
        $this->assertStringContainsString('prev-conv-date', $html);
        $this->assertStringContainsString($conversation->getLastReplyAtHuman(), $html);
    }

    /**
     * getLastReplyAtHuman() already returns '' for a conversation with no
     * reply yet (tested at the model level in LastReplyAtColumnTest) — this
     * checks the Blade @if actually suppresses the date span at render
     * time too, rather than printing an empty one.
     */
    public function test_row_omits_date_span_when_there_is_no_reply_yet()
    {
        $mailbox = $this->makeMailbox();
        $user = $this->makeUser();
        $customer = factory(Customer::class)->create();

        $conversation = $this->makeConversation($mailbox->id, $customer->id, $user->id, Conversation::STATUS_CLOSED, null);

        $html = $this->renderSidebar($customer, [$conversation->id]);

        $this->assertStringContainsString($conversation->getStatusName(), $html);
        $this->assertStringNotContainsString('prev-conv-date', $html);
    }

    /**
     * Confirms different statuses actually produce different colours in
     * the rendered output, not just that some colour is present.
     */
    public function test_different_statuses_get_different_colors()
    {
        $mailbox = $this->makeMailbox();
        $user = $this->makeUser();
        $customer = factory(Customer::class)->create();

        $active = $this->makeConversation($mailbox->id, $customer->id, $user->id, Conversation::STATUS_ACTIVE, '2026-01-01 00:00:00');
        $closed = $this->makeConversation($mailbox->id, $customer->id, $user->id, Conversation::STATUS_CLOSED, '2026-01-02 00:00:00');

        $html = $this->renderSidebar($customer, [$active->id, $closed->id]);

        $this->assertNotSame($active->getStatusColor(), $closed->getStatusColor());
        $this->assertStringContainsString($active->getStatusColor(), $html);
        $this->assertStringContainsString($closed->getStatusColor(), $html);
    }
}
