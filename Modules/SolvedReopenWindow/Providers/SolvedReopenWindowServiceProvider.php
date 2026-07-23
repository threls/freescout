<?php

namespace Modules\SolvedReopenWindow\Providers;

use App\Conversation;
use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;

/**
 * Solved -> N-day reopen window, then a new ticket (ARMS-21).
 *
 * ARMS's Zendesk behaviour: a customer reply within 7 days of a ticket being
 * Solved reopens the same ticket; a reply after 7 days starts a brand-new one.
 *
 * Core FreeScout has no such cutoff — FetchEmails::saveCustomerThread() reuses a
 * header-matched conversation unconditionally, however long ago it was closed
 * (traced 14 Jul, see customizations/7-day-reopen-window.md; the native mailbox
 * "start new conversation after N days" checkbox is Chat-only and does nothing
 * for email). The threls fork adds a single filter, `conversation.should_reopen`,
 * at that reuse decision — default true, so core's unconditional reopen is
 * unchanged when this module is absent/inactive. This module answers the filter,
 * returning false for Solved conversations closed longer ago than the configured
 * window, which drops execution into core's existing "create a new conversation"
 * branch.
 */
class SolvedReopenWindowServiceProvider extends ServiceProvider
{
    /** Fallback window if config/env is unset. */
    const DEFAULT_DAYS = 7;

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
        $this->hooks();
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'solvedreopenwindow');
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        // Answered at FetchEmails::saveCustomerThread()'s reuse decision (threls
        // fork patch, ARMS-21). Returning false makes a reply start a new ticket
        // instead of reopening the matched one.
        \Eventy::addFilter('conversation.should_reopen', function ($reopen, $conversation) {
            // Guard against an orphaned thread whose conversation is gone: core's
            // own reuse code assumes it is non-null, but the filter shouldn't emit
            // a "property on null" warning — fall back to the default.
            if (!$conversation) {
                return $reopen;
            }

            // Only Solved (Closed) conversations are subject to the window;
            // every other status keeps core's incoming default.
            if ((int) $conversation->status !== Conversation::STATUS_CLOSED) {
                return $reopen;
            }

            // "When was it solved?" `closed_at` is the correct field, but core
            // only sets it when a *user* closes the ticket
            // (Conversation::setStatus, guarded on $user) — a workflow auto-solve
            // (ARMS-20) has no user, so closed_at can be null on exactly those
            // tickets. Fall back to updated_at: for an untouched Solved ticket
            // that is the solve time; if it were touched afterwards the window
            // only lengthens, erring toward reopening (i.e. core's behaviour).
            $solvedAt = $conversation->closed_at ?: $conversation->updated_at;
            if (!$solvedAt) {
                return $reopen;
            }

            $days = (int) config('solvedreopenwindow.days', self::DEFAULT_DAYS);

            // Within the window -> reopen the same ticket (true).
            // Past the window   -> let core start a new ticket (false).
            // Comparison is to the second; the exact N-day instant resolves to
            // "past" (new ticket). That boundary is immaterial in practice — the
            // fetch scheduler runs on a coarse interval, not to the second — so no
            // effort is spent making it inclusive.
            return Carbon::parse($solvedAt)->addDays($days)->isFuture();
        }, 20, 2);
    }
}
