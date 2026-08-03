<?php

namespace Modules\CustomerPhoneSearch\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Makes a phone number find its customer from all three plain-text customer
 * searches. See README.md for what each one matched before, and for the two
 * behaviours this deliberately doesn't fix.
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

    /**
     * All three hooks fire from inside core's own native-match closure, before
     * mailbox scoping is AND'd on outside it. A later hook would be unsafe:
     * Laravel flattens sequential where()/orWhere() calls, so an orWhere added
     * after the mailbox condition ORs against the whole preceding expression
     * and leaks customers from mailboxes the agent can't view.
     */
    public function hooks()
    {
        // Search > Customers. Core matches phones here already, but only a
        // whole number typed in full.
        \Eventy::addFilter('search.customers.text_match', function ($query, $q) {
            return $this->addPhoneMatch($query, $q);
        }, 20, 2);

        // The ticket sidebar, Change Customer, Merge, Cc/Bcc, New Ticket and
        // advanced search's Customer filter, which all share ajaxSearch and
        // all send search_by = 'all'. Core has phone matching in this method
        // but gates it to search_by == 'phone', and fires this hook only in
        // 'all' mode, so the narrower modes stay as they were.
        \Eventy::addFilter('search.customers.ajax_text_match', function ($query, $q) {
            return $this->addPhoneMatch($query, $q);
        }, 20, 2);

        // Search > Conversations.
        \Eventy::addFilter('search.conversations.or_where', function ($query, $filters, $q) {
            return $this->addPhoneMatch($query, $q);
        }, 20, 3);
    }

    /**
     * ORs in "does this customer have a phone number containing these digits".
     *
     * formatPhones() stores each phone as typed plus a digits-only copy, so
     * reducing the search term the same way is what makes formatting
     * irrelevant on both sides. The expression is deliberately identical to
     * core's own search_by == 'phone' mode: agents reach that and these three
     * surfaces from the same UI, and two boxes that look alike returning
     * different customers would be worse than either behaviour alone.
     *
     * Matching the column directly rather than through a correlated subquery
     * is load-bearing, not just simpler. Conversation::search() left-joins
     * customers only if the compiled SQL doesn't already mention
     * `customers`.`id`, so a subquery correlated on it (the shape the sibling
     * CustomerFieldSearch module uses) would suppress that join and take core's
     * first/last-name matching down with it.
     */
    protected function addPhoneMatch($query, $q)
    {
        if (!is_string($q) || $q === '') {
            return $query;
        }

        $digits = \Helper::phoneToNumeric($q);

        if ($digits === '') {
            return $query;
        }

        // Anchoring on the "n" key keeps a short search off the JSON's own
        // structure: without it, searching "4" matches "type":4 and so returns
        // every customer with a mobile number. It only half works, and the
        // README says why, but it costs nothing and loses no real match: a
        // phone's digits-only "n" is by definition a superset of any digit run
        // in the "value" it was built from, so nothing findable before the
        // anchor is unfindable after it.
        //
        // The anchor includes the colon and opening quote, which assumes the
        // column holds exactly what PHP's json_encode wrote. It does:
        // customers.phones is a TEXT column (created that way in 2018 and never
        // altered) written via Helper::jsonEncodeUtf8, which is json_encode with
        // JSON_UNESCAPED_UNICODE and no pretty-printing, so there is never a
        // space after the colon. Were the column ever migrated to a native
        // JSON/JSONB type, the database could re-serialise it as `"n": "..."`
        // and this would silently stop matching, so the assumption is pinned by
        // test_the_anchor_matches_the_stored_json_format rather than left
        // implicit.
        //
        // No LIKE-metacharacter escaping needed, unlike CustomerFieldSearch:
        // phoneToNumeric() has already stripped everything that isn't a digit.
        // Nor 'ilike' on PostgreSQL, digits having no case to fold.
        $query->orWhere('customers.phones', 'like', '%"n":"%'.$digits.'%');

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
