<?php

namespace Modules\CustomerPhoneSearch\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Makes a phone number find its customer from all three plain-text customer
 * searches: Search > Customers, Search > Conversations, and the shared
 * ajaxSearch lookup behind the ticket sidebar, Change Customer, Merge,
 * Cc/Bcc, New Ticket and advanced search's Customer filter.
 *
 * Two of those didn't match phone numbers at all, and the third only matched
 * a whole number typed in full. See README.md for what each surface did
 * before, and for why every hook here is one it's safe to OR a condition
 * from.
 */
class CustomerPhoneSearchServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
    }

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->hooks();
    }

    public function hooks()
    {
        // Search > Customers tab (ConversationsController::searchCustomers).
        // Core already ORs a phone condition in here, but only for a whole
        // number typed in full — this widens it to a partial. Registered for
        // 2 of the 4 arguments this hook passes, since neither $like_op nor
        // $filters is wanted here: see addPhoneMatch() on why the operator is
        // always plain 'like', and there's no per-field narrowing to read.
        // Asking for fewer doesn't shorten what any other listener gets —
        // Eventy's Filter::fire() builds each listener's parameters from the
        // full argument list independently (CustomerFieldSearch registers on
        // this same hook for all 4).
        \Eventy::addFilter('search.customers.text_match', function ($query, $q) {
            return $this->addPhoneMatch($query, $q);
        }, 20, 2);

        // Ticket sidebar / Change Customer / Merge / Cc-Bcc / New Ticket /
        // advanced search's Customer filter (CustomersController::ajaxSearch,
        // shared by all of them). This is the surface ARMS-22 was actually
        // reported against: core does have phone matching in this method, but
        // gated to search_by == 'phone', and every one of these widgets sends
        // search_by = 'all'. Core only fires this hook in 'all' mode, which is
        // exactly the gap, so the narrower modes stay untouched.
        \Eventy::addFilter('search.customers.ajax_text_match', function ($query, $q) {
            return $this->addPhoneMatch($query, $q);
        }, 20, 2);

        // Search > Conversations tab (Conversation::search). Fired from
        // inside the correctly-grouped native-match closure, before mailbox
        // scoping's AND boundary — no new core patch needed.
        \Eventy::addFilter('search.conversations.or_where', function ($query, $filters, $q) {
            return $this->addPhoneMatch($query, $q);
        }, 20, 3);
    }

    /**
     * ORs in "does this customer have a phone number containing the digits
     * the agent typed".
     *
     * Customer::formatPhones() stores each phone twice — 'value' as the admin
     * typed it, and 'n', the same number reduced to digits. Reducing the
     * search term the same way and matching it as a substring of the whole
     * JSON column is what makes formatting irrelevant on both sides: an agent
     * can type 7912 3456, +356 79123456 or 79123456 and reach a customer
     * stored under any of those spellings.
     *
     * This is deliberately the same expression core already uses for its own
     * search_by == 'phone' mode (CustomersController::ajaxSearch), rather than
     * something stricter or cleverer. Agents reach phone-mode search and these
     * three surfaces from the same UI, and two boxes that look alike returning
     * different customers for the same number is worse than either behaviour
     * on its own.
     *
     * Matching the whole column rather than picking the 'n' values out of the
     * JSON keeps this working on both MySQL and PostgreSQL, which core
     * supports and which have no portable JSON substring operator between
     * them. The cost is that the digits can also match a 'type' (1-4, the
     * work/home/mobile flag), so a single-digit search matches any customer
     * with a phone at all. That is not worth guarding against: a one-digit
     * search already matches most of the database through the name, subject
     * and message-body conditions this one is OR'd alongside.
     */
    protected function addPhoneMatch($query, $q)
    {
        if (!is_string($q) || $q === '') {
            return $query;
        }

        $digits = \Helper::phoneToNumeric($q);

        // Any term with no digits in it at all — a name, an email address —
        // can't be a phone number, so it adds nothing but a wasted LIKE.
        if ($digits === '') {
            return $query;
        }

        // No LIKE-metacharacter escaping needed, unlike the custom-field
        // match in CustomerFieldSearch: phoneToNumeric() has already stripped
        // everything that isn't a digit, so % _ and \ can't survive into the
        // pattern. Nor is 'ilike' needed on PostgreSQL — the pattern is
        // digits, which have no case to fold.
        $query->orWhere('customers.phones', 'like', '%'.$digits.'%');

        return $query;
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
