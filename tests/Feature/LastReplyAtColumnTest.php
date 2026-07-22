<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Covers ARMS-36: the "Last Replied At" column in the conversation list.
 * Pure core (no paid-module dependency), so this runs against the real
 * conversations/folders/mailboxes tables inside a transaction, unlike the
 * ad hoc-table tests elsewhere in this suite.
 */
class LastReplyAtColumnTest extends TestCase
{
    use DatabaseTransactions;

    protected function makeMailbox()
    {
        return factory(Mailbox::class)->create();
    }

    protected function makeFolder($mailboxId, $type = Folder::TYPE_UNASSIGNED)
    {
        return factory(Folder::class)->create(['mailbox_id' => $mailboxId, 'type' => $type]);
    }

    protected function makeUser($mailboxId = null)
    {
        $user = factory(User::class)->create(['role' => User::ROLE_USER]);
        if ($mailboxId) {
            $user->mailboxes()->attach($mailboxId);
        }

        return $user;
    }

    protected function makeConversation($mailboxId, $folderId, $lastReplyAt, $userId)
    {
        return factory(Conversation::class)->create([
            'mailbox_id'         => $mailboxId,
            'folder_id'          => $folderId,
            'last_reply_at'      => $lastReplyAt,
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * A genuine reply (Thread::TYPE_MESSAGE) — distinct from the customer's
     * own incoming message, which ThreadObserver also touches (see
     * hasReplied()'s docblock) but which is not "a reply" in the sense this
     * column means.
     */
    protected function makeReplyThread($conversationId, $userId)
    {
        return factory(Thread::class)->create([
            'conversation_id' => $conversationId,
            'type'            => Thread::TYPE_MESSAGE,
            'state'           => Thread::STATE_PUBLISHED,
            'source_via'      => Thread::PERSON_USER,
            'user_id'         => $userId,
            'created_by_user_id' => $userId,
        ]);
    }

    /**
     * The whole point of the new column: it must keep showing
     * last_reply_at in folders where "Waiting Since" shows something else
     * entirely (closed_at here).
     */
    public function test_last_reply_at_is_independent_of_waiting_since_field()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id, Folder::TYPE_CLOSED);
        $user = $this->makeUser($mailbox->id);

        // Created with a null last_reply_at, then set by hand after the
        // reply thread, since ThreadObserver::created() would otherwise
        // overwrite it with "now" as a real side effect of creating the
        // thread — same reasoning as test_search_sorts_by_last_reply_at
        // below. A raw DB update, not Eloquent save(), plus a fresh reload,
        // since save() would only re-persist this object's stale in-memory
        // last_reply_at (still null) rather than reading back what the raw
        // update just wrote.
        $conversation = $this->makeConversation($mailbox->id, $folder->id, null, $user->id);
        $this->makeReplyThread($conversation->id, $user->id);
        \DB::table('conversations')->where('id', $conversation->id)->update([
            'last_reply_at' => '2026-01-05 10:00:00',
            'closed_at'     => '2026-01-10 12:00:00',
        ]);
        $conversation = $conversation->fresh();

        // Waiting Since, in the Closed folder, reads closed_at...
        $this->assertSame(
            \App\User::dateDiffForHumans('2026-01-10 12:00:00'),
            $conversation->getWaitingSince($folder)
        );

        // ...but the new column must still reflect last_reply_at, not closed_at.
        $this->assertSame(
            \App\User::dateDiffForHumans('2026-01-05 10:00:00'),
            $conversation->getLastReplyAtHuman()
        );
    }

    public function test_last_reply_at_human_is_empty_when_there_is_no_reply_yet()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);

        $conversation = $this->makeConversation($mailbox->id, $folder->id, null, $user->id);

        $this->assertSame('', $conversation->getLastReplyAtHuman());
    }

    /**
     * Reproduces the exact bug reported live (Omar, 22 Jul): a conversation
     * with only the customer's initial message — no genuine reply — still
     * ends up with a non-null last_reply_at, because ThreadObserver::created()
     * stamps it on message #1 too (last_reply_from starts out null, and
     * null != Conversation::PERSON_CUSTOMER, so the "skip consecutive
     * customer messages" guard there doesn't catch the very first one).
     * getLastReplyAtHuman() must return '' here regardless — trusting
     * last_reply_at being null was never a safe way to detect "no reply".
     */
    public function test_last_reply_at_human_is_empty_when_only_customer_message_exists()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);

        $conversation = $this->makeConversation($mailbox->id, $folder->id, null, $user->id);
        factory(Thread::class)->create([
            'conversation_id' => $conversation->id,
            'type'            => Thread::TYPE_CUSTOMER,
            'state'           => Thread::STATE_PUBLISHED,
        ]);
        $conversation = $conversation->fresh();

        $this->assertNotNull($conversation->last_reply_at, 'sanity check: this is the actual bug mechanism — last_reply_at IS set here');
        $this->assertFalse($conversation->hasReplied());
        $this->assertSame('', $conversation->getLastReplyAtHuman());
    }

    /**
     * Companion: once a genuine reply (Thread::TYPE_MESSAGE) exists,
     * hasReplied() must flip true and the human string must actually
     * appear — proves the fix doesn't just suppress everything.
     */
    public function test_has_replied_becomes_true_after_a_genuine_reply()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);

        $conversation = $this->makeConversation($mailbox->id, $folder->id, null, $user->id);
        $this->assertFalse($conversation->hasReplied());

        $this->makeReplyThread($conversation->id, $user->id);
        $conversation = $conversation->fresh();

        $this->assertTrue($conversation->hasReplied());
        $this->assertNotSame('', $conversation->getLastReplyAtHuman());
    }

    public function test_sort_by_last_reply_at_is_accepted()
    {
        request()->merge(['sorting' => ['sort_by' => 'last_reply_at', 'order' => 'asc']]);

        $sorting = Conversation::getConvTableSorting();

        $this->assertSame('last_reply_at', $sorting['sort_by']);
        $this->assertSame('asc', $sorting['order']);
    }

    /**
     * Unrecognized sort_by values must still be rejected — proves the
     * whitelist addition didn't accidentally turn into an open allowlist.
     */
    public function test_arbitrary_sort_by_is_still_rejected()
    {
        request()->merge(['sorting' => ['sort_by' => 'phones', 'order' => 'asc']]);

        $sorting = Conversation::getConvTableSorting();

        $this->assertSame('date', $sorting['sort_by']);
    }

    /**
     * getOrderByArray()'s generic "anything but 'date' replaces the whole
     * order" branch must produce a plain last_reply_at order — checked
     * against a folder type whose own default order is NOT last_reply_at
     * (Closed defaults to closed_at), proving the sort request actually
     * overrides the folder default rather than being ignored.
     */
    public function test_order_by_array_uses_last_reply_at_when_requested()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id, Folder::TYPE_CLOSED);

        request()->merge(['sorting' => ['sort_by' => 'last_reply_at', 'order' => 'asc']]);

        $this->assertSame([['last_reply_at' => 'asc']], $folder->getOrderByArray());
    }

    /**
     * End-to-end proof, not just SQL-shape inspection: seed real
     * conversations and confirm the actual query returns them ordered by
     * last_reply_at, in a folder whose own default order is unrelated.
     */
    public function test_sorting_by_last_reply_at_orders_conversations_end_to_end()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id, Folder::TYPE_CLOSED);
        $user = $this->makeUser($mailbox->id);

        $oldest = $this->makeConversation($mailbox->id, $folder->id, '2026-01-01 00:00:00', $user->id);
        $newest = $this->makeConversation($mailbox->id, $folder->id, '2026-01-03 00:00:00', $user->id);
        $middle = $this->makeConversation($mailbox->id, $folder->id, '2026-01-02 00:00:00', $user->id);

        request()->merge(['sorting' => ['sort_by' => 'last_reply_at', 'order' => 'asc']]);

        $query = Conversation::getQueryByFolder($folder, $user->id);
        $results = $folder->queryAddOrderBy($query)->get();

        $this->assertSame(
            [$oldest->id, $middle->id, $newest->id],
            $results->pluck('id')->all()
        );
    }

    /**
     * Render-level check: the new header and cell actually appear in the
     * table output, with the sort attribute the generic JS click handler
     * (main.js's .conv-col-sort) reads.
     */
    public function test_table_renders_last_replied_at_header_and_cell()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);
        $this->actingAs($user);

        $conversation = $this->makeConversation($mailbox->id, $folder->id, null, $user->id);
        $this->makeReplyThread($conversation->id, $user->id);
        \DB::table('conversations')->where('id', $conversation->id)->update(['last_reply_at' => '2026-01-01 00:00:00']);
        $conversation = $conversation->fresh();

        $conversations = Conversation::getQueryByFolder($folder, $user->id)->paginate(50);

        $html = view('conversations/conversations_table', [
            'folder'        => $folder,
            'conversations' => $conversations,
            'params'        => [],
        ])->render();

        $this->assertStringContainsString('data-sort-by="last_reply_at"', $html);
        $this->assertStringContainsString('Last Replied At', $html);
        $this->assertStringContainsString('conv-last-reply-at', $html);
        $this->assertNotSame('', $conversation->getLastReplyAtHuman(), 'fixture must actually have a reply, or this assertion is vacuous');
        $this->assertStringContainsString($conversation->getLastReplyAtHuman(), $html);
    }

    /**
     * The cell's tooltip must show the absolute timestamp when there's a
     * reply, and fall back to the generic "View conversation" title (no
     * tooltip) when there isn't — found missing during self-review: the
     * accessor's empty-string handling was unit-tested, but this Blade
     * conditional wasn't checked at the render level at all.
     */
    public function test_table_cell_tooltip_reflects_reply_presence()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);
        $this->actingAs($user);

        $withReply = $this->makeConversation($mailbox->id, $folder->id, null, $user->id);
        $this->makeReplyThread($withReply->id, $user->id);
        \DB::table('conversations')->where('id', $withReply->id)->update(['last_reply_at' => '2026-01-01 00:00:00']);
        $withReply = $withReply->fresh();

        // Only a customer-type thread, no genuine reply — reproduces the
        // exact bug found live 22 Jul (Omar): ThreadObserver::created()
        // stamps last_reply_at on the customer's own first message too, so
        // this conversation's last_reply_at is NOT null despite never
        // having been replied to (see hasReplied()'s docblock).
        $withoutReply = $this->makeConversation($mailbox->id, $folder->id, null, $user->id);
        factory(Thread::class)->create([
            'conversation_id' => $withoutReply->id,
            'type'            => Thread::TYPE_CUSTOMER,
            'state'           => Thread::STATE_PUBLISHED,
        ]);
        $withoutReply = $withoutReply->fresh();
        $this->assertNotNull($withoutReply->last_reply_at, 'sanity check: the bug this reproduces is last_reply_at being set despite no reply');

        $conversations = Conversation::getQueryByFolder($folder, $user->id)->orderBy('conversations.id')->paginate(50);

        $html = view('conversations/conversations_table', [
            'folder'        => $folder,
            'conversations' => $conversations,
            'params'        => [],
        ])->render();

        $this->assertStringContainsString(
            'title="'.\App\User::dateFormat($withReply->last_reply_at).'" data-toggle="tooltip"',
            $html,
            'a conversation with a reply must show the absolute timestamp on hover'
        );

        // The no-reply conversation's cell must render no <a> at all — an
        // empty-but-focusable link is bad for keyboard/screen-reader users
        // (gemini-code-assist review, PR #17) — not just fall back to a
        // generic title on an empty link. And it must read "No Replies"
        // (Omar, 22 Jul) rather than a misleading "X hours/days ago" from
        // last_reply_at's stale value, or a silent blank cell.
        $rowStart = strpos($html, 'data-conversation_id="'.$withoutReply->id.'"');
        $this->assertNotFalse($rowStart);
        $rowEnd = strpos($html, '</tr>', $rowStart);
        $row = substr($html, $rowStart, $rowEnd - $rowStart);

        $cell = $this->lastReplyAtCell($row);
        $this->assertStringNotContainsString('<a', $cell);
        $this->assertStringContainsString('No Replies', $cell);
    }

    /**
     * Only the conv-last-reply-at <td> in this row, isolated from the
     * conv-date <td> right before it — both use "View conversation" as a
     * title in some cases, so asserting against the whole row risks a
     * false pass/fail from the wrong cell.
     */
    protected function lastReplyAtCell($rowHtml)
    {
        $start = strpos($rowHtml, 'class="conv-last-reply-at"');
        $end = strpos($rowHtml, '</td>', $start);

        return substr($rowHtml, $start, $end - $start);
    }

    /**
     * Conversation::search() is a structurally different code path from
     * the folder view (it calls orderBy() directly rather than going
     * through Folder::getOrderByArray()) — the PR description claims
     * sorting also works there, so it needs its own proof rather than
     * relying on the folder-view test above.
     */
    public function test_search_sorts_by_last_reply_at()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);

        $oldest = $this->makeConversation($mailbox->id, $folder->id, '2026-01-01 00:00:00', $user->id);
        $newest = $this->makeConversation($mailbox->id, $folder->id, '2026-01-03 00:00:00', $user->id);
        $middle = $this->makeConversation($mailbox->id, $folder->id, '2026-01-02 00:00:00', $user->id);

        // Conversation::search() inner-joins threads — a conversation with
        // no thread row at all wouldn't be returned regardless of sort.
        // ThreadObserver::created() bumps the parent's last_reply_at to
        // "now" as a side effect (correct production behavior — a new
        // reply should do that), so restore each conversation's intended
        // test value afterward. A raw DB update, not Eloquent save(), is
        // required here: the observer updates a *separate* Eloquent
        // instance ($thread->conversation), so this $conversation object's
        // own "original" snapshot never saw that change — setting the
        // attribute back to the same value it already held in memory
        // leaves save() with nothing "dirty" to persist, silently no-op'ing
        // and leaving the observer's "now" value in place.
        $conversations = ['oldest' => $oldest, 'newest' => $newest, 'middle' => $middle];
        $lastReplyAts = ['oldest' => '2026-01-01 00:00:00', 'newest' => '2026-01-03 00:00:00', 'middle' => '2026-01-02 00:00:00'];
        foreach ($conversations as $key => $conversation) {
            factory(Thread::class)->create(['conversation_id' => $conversation->id]);
            \DB::table('conversations')->where('id', $conversation->id)->update(['last_reply_at' => $lastReplyAts[$key]]);
        }

        request()->merge(['sorting' => ['sort_by' => 'last_reply_at', 'order' => 'asc']]);

        $results = Conversation::search('', [], $user)->get();

        $this->assertSame(
            [$oldest->id, $middle->id, $newest->id],
            $results->pluck('id')->all()
        );
    }
}
