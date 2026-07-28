<?php

namespace Tests\Feature;

use App\Conversation;
use App\Customer;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Covers ARMS-29: the merge dialog's search box, extended from an exact
 * ticket-number-only lookup to also match by customer email or subject
 * keyword, reusing Conversation::search() (the same engine the main search
 * bar uses) rather than a bespoke query.
 *
 * Deliberately does NOT use RefreshDatabase (project convention - see
 * CustomerFieldSearchTest); every row created is tracked and deleted
 * explicitly in tearDown().
 */
class MergeSearchTest extends TestCase
{
    protected $mailboxIds = [];
    protected $folderIds = [];
    protected $conversationIds = [];
    protected $customerIds = [];
    protected $userIds = [];

    protected function tearDown(): void
    {
        \DB::table('threads')->whereIn('conversation_id', $this->conversationIds)->delete();
        \DB::table('conversations')->whereIn('id', $this->conversationIds)->delete();
        \DB::table('customers')->whereIn('id', $this->customerIds)->delete();
        \DB::table('mailbox_user')->whereIn('user_id', $this->userIds)->delete();
        \DB::table('users')->whereIn('id', $this->userIds)->delete();
        \DB::table('folders')->whereIn('id', $this->folderIds)->delete();
        \DB::table('mailboxes')->whereIn('id', $this->mailboxIds)->delete();

        parent::tearDown();
    }

    protected function makeMailbox()
    {
        $mailbox = factory(Mailbox::class)->create();
        $this->mailboxIds[] = $mailbox->id;

        return $mailbox;
    }

    protected function makeFolder($mailboxId)
    {
        $folder = factory(Folder::class)->create(['mailbox_id' => $mailboxId]);
        $this->folderIds[] = $folder->id;

        return $folder;
    }

    protected function makeUser($mailboxId = null)
    {
        $user = factory(User::class)->create(['role' => User::ROLE_USER]);
        $this->userIds[] = $user->id;

        if ($mailboxId) {
            $user->mailboxes()->attach($mailboxId);
        }

        return $user;
    }

    protected function makeCustomer($email = null)
    {
        $customer = factory(Customer::class)->create();
        $this->customerIds[] = $customer->id;

        if ($email) {
            $customer->syncEmails([$email]);
        }

        return $customer;
    }

    protected function makeConversation($mailboxId, $folderId, $customerId, $customerEmail, $subject)
    {
        $conversation = factory(Conversation::class)->create([
            'mailbox_id'     => $mailboxId,
            'folder_id'      => $folderId,
            'customer_id'    => $customerId,
            'customer_email' => $customerEmail,
            'subject'        => $subject,
            'status'         => Conversation::STATUS_ACTIVE,
            'state'          => Conversation::STATE_PUBLISHED,
        ]);
        $this->conversationIds[] = $conversation->id;

        // Conversation::search() inner-joins threads - a conversation with
        // no thread row at all is never returned regardless of what else
        // matches (see CustomerFieldSearchTest for the same note).
        factory(Thread::class)->create([
            'conversation_id' => $conversation->id,
            'customer_id'     => $customerId,
            'to'              => json_encode(['unrelated@example.com']),
            'body'            => 'unrelated thread content',
        ]);

        return $conversation;
    }

    protected function mergeSearchRequest($q, $curConvId)
    {
        return Request::create('/conversation/ajax', 'POST', [
            'action'      => 'merge_search',
            'q'           => $q,
            'cur_conv_id' => $curConvId,
        ]);
    }

    protected function search($q, $curConvId, $user)
    {
        $this->actingAs($user);
        $controller = new \App\Http\Controllers\ConversationsController();

        return json_decode($controller->ajax($this->mergeSearchRequest($q, $curConvId))->getContent(), true);
    }

    public function test_finds_conversation_by_exact_ticket_number()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);
        $customer = $this->makeCustomer();

        $curConversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'a@example.org', 'Current ticket');
        $target = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'b@example.org', 'Unrelated subject');

        $response = $this->search((string) $target->number, $curConversation->id, $user);

        $this->assertSame('success', $response['status']);
        $this->assertStringContainsString('#'.$target->number, $response['html']);
    }

    public function test_finds_conversation_by_customer_email()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);
        $customer = $this->makeCustomer();

        $curConversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'x@example.org', 'Current ticket');
        $target = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'jane.duplicate@example.org', 'Some other subject');

        $response = $this->search('jane.duplicate@example.org', $curConversation->id, $user);

        $this->assertSame('success', $response['status']);
        $this->assertStringContainsString('#'.$target->number, $response['html']);
    }

    public function test_finds_conversation_by_subject_keyword()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);
        $customer = $this->makeCustomer();

        $curConversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'a@example.org', 'Current ticket');
        $target = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'b@example.org', 'Refund request for order 5591');

        $response = $this->search('refund request', $curConversation->id, $user);

        $this->assertSame('success', $response['status']);
        $this->assertStringContainsString('#'.$target->number, $response['html']);
    }

    public function test_excludes_the_current_conversation_being_merged_from()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);
        $customer = $this->makeCustomer();

        $curConversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'a@example.org', 'Refund request for order 5591');

        $response = $this->search('refund request', $curConversation->id, $user);

        $this->assertNotSame('success', $response['status'], 'the current conversation must never be offered as its own merge target');
    }

    public function test_does_not_return_conversations_from_a_different_mailbox()
    {
        $mailboxA = $this->makeMailbox();
        $mailboxB = $this->makeMailbox();
        $folderA = $this->makeFolder($mailboxA->id);
        $folderB = $this->makeFolder($mailboxB->id);
        $user = $this->makeUser($mailboxA->id);
        $user->mailboxes()->attach($mailboxB->id); // user CAN view both - mailbox scoping, not permission, must be what restricts here
        $customer = $this->makeCustomer();

        $curConversation = $this->makeConversation($mailboxA->id, $folderA->id, $customer->id, 'a@example.org', 'Current ticket');
        $otherMailboxTarget = $this->makeConversation($mailboxB->id, $folderB->id, $customer->id, 'b@example.org', 'Duplicate refund request');

        $response = $this->search('duplicate refund', $curConversation->id, $user);

        $this->assertNotSame('success', $response['status'], 'a matching conversation in a different mailbox must not be offered as a merge target');
    }

    public function test_denies_when_user_cannot_view_the_current_conversation()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $unprivUser = $this->makeUser(); // no mailbox access
        $customer = $this->makeCustomer();

        $curConversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'a@example.org', 'Current ticket');
        $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'b@example.org', 'Refund request');

        $response = $this->search('refund', $curConversation->id, $unprivUser);

        $this->assertSame('error', $response['status']);
        $this->assertSame('Conversation not found', $response['msg']);
    }

    public function test_excludes_spam_conversations()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);
        $customer = $this->makeCustomer();

        $curConversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'a@example.org', 'Current ticket');
        $spamTarget = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'b@example.org', 'Refund request spam copy');
        $spamTarget->status = Conversation::STATUS_SPAM;
        $spamTarget->save();

        $response = $this->search('refund request', $curConversation->id, $user);

        $this->assertNotSame('success', $response['status'], 'a spam conversation must not be offered as a merge target');
    }

    public function test_requires_a_minimum_query_length()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);
        $customer = $this->makeCustomer();

        $curConversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'a@example.org', 'Current ticket');

        $response = $this->search('r', $curConversation->id, $user);

        $this->assertSame('error', $response['status']);
        $this->assertNotSame('Conversation not found', $response['msg'], 'a too-short query should explain the length requirement, not look like a failed search');
    }

    public function test_caps_results_and_flags_has_more()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);
        $customer = $this->makeCustomer();

        $curConversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'a@example.org', 'Current ticket');

        $limit = \App\Http\Controllers\ConversationsController::MERGE_SEARCH_LIMIT;
        for ($i = 0; $i < $limit + 3; $i++) {
            $this->makeConversation($mailbox->id, $folder->id, $customer->id, 'a@example.org', 'Bulk match subject '.$i);
        }

        $response = $this->search('bulk match', $curConversation->id, $user);

        $this->assertSame('success', $response['status']);
        preg_match_all('/#\d+/', $response['html'], $matches);
        $this->assertCount($limit, $matches[0], 'result rows must be capped at MERGE_SEARCH_LIMIT');
        $this->assertStringContainsString('Showing the first', $response['html']);
    }
}
