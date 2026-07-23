<?php

namespace Tests\Feature;

use App\Console\Commands\FetchEmails;
use App\Conversation;
use App\Customer;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Covers the ARMS-21 core-patch wiring end to end: FetchEmails::
 * saveCustomerThread() must reopen a recently-Solved conversation but start a
 * NEW ticket when the same conversation was Solved longer ago than the window.
 *
 * The decision logic itself is unit-tested in tests/Unit/SolvedReopenWindowTest;
 * this proves the fork patch actually routes through it — i.e. that the filter
 * result reaches the reuse-vs-create branch, which a unit test cannot show.
 *
 * Pure core (the module is booted by hand), so it runs against the real tables
 * inside a transaction. Laravel events are faked to isolate the reuse/create
 * decision from downstream notification listeners; the Eventy filter the patch
 * depends on is a separate system and is unaffected by Event::fake().
 */
class SolvedReopenWindowTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../../Modules/SolvedReopenWindow/Providers/SolvedReopenWindowServiceProvider.php';
        config(['solvedreopenwindow.days' => 7]);
    }

    protected function bootModule()
    {
        (new \Modules\SolvedReopenWindow\Providers\SolvedReopenWindowServiceProvider(app()))->boot();
    }

    /**
     * Build a Solved conversation with a prior customer thread, closed
     * $daysAgo days ago. Returns [conversation, prev_thread, customer_email].
     */
    protected function makeSolvedConversation($daysAgo)
    {
        $mailbox = factory(Mailbox::class)->create();
        $folder = factory(Folder::class)->create(['mailbox_id' => $mailbox->id, 'type' => Folder::TYPE_UNASSIGNED]);
        $user = factory(User::class)->create(['role' => User::ROLE_USER]);

        $email = 'reopen-window-'.uniqid().'@example.com';
        $customer = Customer::create($email);

        $conversation = factory(Conversation::class)->create([
            'mailbox_id'         => $mailbox->id,
            'folder_id'          => $folder->id,
            'created_by_user_id' => $user->id,
            'customer_id'        => $customer->id,
            'customer_email'     => $email,
        ]);
        // Raw update + reload: set the Solved status and an exact closed_at the
        // factory doesn't control.
        \DB::table('conversations')->where('id', $conversation->id)->update([
            'status'    => Conversation::STATUS_CLOSED,
            'closed_at' => now()->subDays($daysAgo)->toDateTimeString(),
        ]);
        $conversation = $conversation->fresh();

        $prevThread = factory(Thread::class)->create([
            'conversation_id' => $conversation->id,
            'customer_id'     => $customer->id,
            // 'to' set explicitly: ThreadFactory references an undefined $customer
            // when customer_id is passed without it (latent factory bug).
            'to'              => $email,
            'type'            => Thread::TYPE_CUSTOMER,
            'message_id'      => 'orig-'.uniqid().'@example.com',
        ]);

        return [$mailbox, $conversation, $prevThread, $email];
    }

    protected function deliverReply($mailbox, $prevThread, $email)
    {
        return (new FetchEmails())->saveCustomerThread(
            $mailbox,
            'reply-'.uniqid().'@example.com',
            $prevThread,
            $email,
            ['support@example.com'],
            [],
            [],
            'Re: original subject',
            'A customer reply body.',
            [],
            '',
            now()->toDateTimeString()
        );
    }

    public function test_reply_within_window_reopens_same_ticket()
    {
        Event::fake();
        $this->bootModule();

        [$mailbox, $conversation, $prevThread, $email] = $this->makeSolvedConversation(2);
        $before = Conversation::where('mailbox_id', $mailbox->id)->count();

        $thread = $this->deliverReply($mailbox, $prevThread, $email);

        // No new conversation, and the original was reopened to Active.
        $this->assertSame($before, Conversation::where('mailbox_id', $mailbox->id)->count());
        $this->assertSame($conversation->id, $thread->conversation_id);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
    }

    public function test_reply_past_window_starts_new_ticket()
    {
        Event::fake();
        $this->bootModule();

        [$mailbox, $conversation, $prevThread, $email] = $this->makeSolvedConversation(30);
        $before = Conversation::where('mailbox_id', $mailbox->id)->count();

        $thread = $this->deliverReply($mailbox, $prevThread, $email);

        // A brand-new conversation was created; the original stays Solved.
        $this->assertSame($before + 1, Conversation::where('mailbox_id', $mailbox->id)->count());
        $this->assertNotSame($conversation->id, $thread->conversation_id);
        $this->assertSame(Conversation::STATUS_CLOSED, (int) $conversation->fresh()->status);
    }

    /**
     * Without the module active the fork patch must preserve core behaviour:
     * even a long-Solved ticket reopens (the default-true filter). Guards
     * against the patch silently changing core when the module is absent.
     */
    public function test_reply_reopens_when_module_inactive()
    {
        Event::fake();
        // Note: bootModule() intentionally NOT called.

        [$mailbox, $conversation, $prevThread, $email] = $this->makeSolvedConversation(30);
        $before = Conversation::where('mailbox_id', $mailbox->id)->count();

        $thread = $this->deliverReply($mailbox, $prevThread, $email);

        $this->assertSame($before, Conversation::where('mailbox_id', $mailbox->id)->count());
        $this->assertSame($conversation->id, $thread->conversation_id);
        $this->assertSame(Conversation::STATUS_ACTIVE, (int) $conversation->fresh()->status);
    }
}
