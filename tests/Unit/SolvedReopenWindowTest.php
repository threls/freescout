<?php

namespace Tests\Unit;

use App\Conversation;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Covers the SolvedReopenWindow module (ARMS-21) and the fork patch it depends
 * on: the conversation.should_reopen Eventy filter at FetchEmails::
 * saveCustomerThread()'s reuse decision.
 *
 * The filter decides, for a header-matched conversation, whether a customer
 * reply reopens it (true) or starts a new ticket (false). Default true =
 * core's unconditional reopen; the module returns false only for a Solved
 * conversation closed longer ago than the window.
 */
class SolvedReopenWindowTest extends TestCase
{
    const ONHOLD = 5;

    protected function setUp(): void
    {
        parent::setUp();

        // The module is not autoloaded while inactive — load the provider directly.
        require_once __DIR__.'/../../Modules/SolvedReopenWindow/Providers/SolvedReopenWindowServiceProvider.php';

        // Deterministic 7-day window regardless of any env override in the test box.
        config(['solvedreopenwindow.days' => 7]);
    }

    protected function bootModule()
    {
        (new \Modules\SolvedReopenWindow\Providers\SolvedReopenWindowServiceProvider(app()))->boot();
    }

    /** Build an unsaved conversation with a given status and solve timestamps. */
    protected function conv($status, $closedAt = null, $updatedAt = null)
    {
        $conversation = new Conversation();
        $conversation->status = $status;
        $conversation->closed_at = $closedAt;
        $conversation->updated_at = $updatedAt;

        return $conversation;
    }

    protected function shouldReopen(Conversation $conversation)
    {
        return \Eventy::filter('conversation.should_reopen', true, $conversation);
    }

    /**
     * Without the module the fork patch must preserve core behaviour: a reply
     * always reopens, even a long-Solved conversation. (Eventy is rebuilt per
     * test, so no filter is registered here.)
     */
    public function test_default_reopens_without_module()
    {
        $conversation = $this->conv(Conversation::STATUS_CLOSED, Carbon::now()->subDays(365));

        $this->assertTrue($this->shouldReopen($conversation));
    }

    /** Non-Solved statuses always reopen, regardless of any stale timestamp. */
    public function test_non_solved_statuses_always_reopen()
    {
        $this->bootModule();

        $old = Carbon::now()->subDays(365);
        foreach ([
            Conversation::STATUS_ACTIVE,
            Conversation::STATUS_PENDING,
            Conversation::STATUS_SPAM,
            self::ONHOLD,
        ] as $status) {
            $this->assertTrue(
                $this->shouldReopen($this->conv($status, $old, $old)),
                "status {$status} should reopen"
            );
        }
    }

    /** Solved within the window → reopen the same ticket. */
    public function test_solved_within_window_reopens()
    {
        $this->bootModule();

        $conversation = $this->conv(Conversation::STATUS_CLOSED, Carbon::now()->subDays(3));

        $this->assertTrue($this->shouldReopen($conversation));
    }

    /** Solved past the window → new ticket (filter returns false). */
    public function test_solved_past_window_starts_new_ticket()
    {
        $this->bootModule();

        $conversation = $this->conv(Conversation::STATUS_CLOSED, Carbon::now()->subDays(10));

        $this->assertFalse($this->shouldReopen($conversation));
    }

    /**
     * closed_at is null on workflow auto-solves (no user) — the module must
     * fall back to updated_at. Old updated_at past the window → new ticket.
     */
    public function test_null_closed_at_falls_back_to_updated_at_past_window()
    {
        $this->bootModule();

        $conversation = $this->conv(Conversation::STATUS_CLOSED, null, Carbon::now()->subDays(10));

        $this->assertFalse($this->shouldReopen($conversation));
    }

    /** Same fallback, but within the window → reopen. */
    public function test_null_closed_at_falls_back_to_updated_at_within_window()
    {
        $this->bootModule();

        $conversation = $this->conv(Conversation::STATUS_CLOSED, null, Carbon::now()->subDays(2));

        $this->assertTrue($this->shouldReopen($conversation));
    }

    /** Neither timestamp available → err toward reopening (keep the default). */
    public function test_no_timestamps_keeps_default_reopen()
    {
        $this->bootModule();

        $conversation = $this->conv(Conversation::STATUS_CLOSED, null, null);

        $this->assertTrue($this->shouldReopen($conversation));
    }

    /** The window is configurable (SOLVED_REOPEN_WINDOW_DAYS → config). */
    public function test_window_is_configurable()
    {
        config(['solvedreopenwindow.days' => 2]);
        $this->bootModule();

        // 3 days ago is now past a 2-day window → new ticket.
        $this->assertFalse(
            $this->shouldReopen($this->conv(Conversation::STATUS_CLOSED, Carbon::now()->subDays(3)))
        );
        // 1 day ago is still within it → reopen.
        $this->assertTrue(
            $this->shouldReopen($this->conv(Conversation::STATUS_CLOSED, Carbon::now()->subDays(1)))
        );
    }

    /** closed_at wins over updated_at when both are present. */
    public function test_closed_at_takes_precedence_over_updated_at()
    {
        $this->bootModule();

        // Closed long ago (past window) but touched recently: closed_at must win
        // → new ticket. Guards against updated_at masking a genuinely old solve.
        $conversation = $this->conv(
            Conversation::STATUS_CLOSED,
            Carbon::now()->subDays(30),
            Carbon::now()->subDays(1)
        );

        $this->assertFalse($this->shouldReopen($conversation));
    }
}
