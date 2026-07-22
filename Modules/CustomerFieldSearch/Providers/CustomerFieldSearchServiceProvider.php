<?php

namespace Modules\CustomerFieldSearch\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Extends the existing customer-name/email/phone plain-text search (Search >
 * Customers tab, the ticket sidebar's shared ajaxSearch lookup used by
 * Change Customer/Merge/Cc-Bcc/New Ticket/advanced search, and Search >
 * Conversations) to also match against customer custom field values (e.g.
 * Account Number, ID Card) added by the paid Crm module. See README.md for
 * why each hook is safe to add an OR-condition from and why it must use a
 * whereExists() subquery rather than a join.
 */
class CustomerFieldSearchServiceProvider extends ServiceProvider
{
    const TABLE = 'customer_customer_field';

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // env() calls live in the module config file, per Laravel convention;
        // everything else reads config('customerfieldsearch.*').
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'customerfieldsearch');
    }

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'customerfieldsearch');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->hooks();
    }

    public function hooks()
    {
        // Search > Customers tab (ConversationsController::searchCustomers).
        // 3 args: Eventy's Filter::fire() truncates to exactly whatever count
        // a listener registers with — $like_op would silently be dropped
        // without it.
        // $filters (4th arg) is a small extension to our own existing fork
        // patch (ConversationsController.php), added so this listener can
        // read the new "Custom Field" dropdown's choice directly rather than
        // re-parsing the request itself.
        \Eventy::addFilter('search.customers.text_match', function ($query, $q, $like_op, $filters = []) {
            return $this->addCustomFieldMatch($query, $q, 'customers.id', $like_op, $this->selectedFieldId($filters));
        }, 20, 4);

        // Ticket sidebar / Change Customer / Merge / Cc-Bcc / New Ticket /
        // advanced search's Customer filter (CustomersController::ajaxSearch,
        // shared by all of these). No filter panel exists on this surface —
        // it's a compact autocomplete widget, not a full search page — so
        // this always matches any field, same as before the dropdown existed.
        \Eventy::addFilter('search.customers.ajax_text_match', function ($query, $q) {
            return $this->addCustomFieldMatch($query, $q, 'customers.id', 'like', null);
        }, 20, 2);

        // Search > Conversations tab (Conversation::search). Reuses an
        // existing hook already fired from inside the correctly-grouped
        // native-match closure, before mailbox scoping's AND boundary — no
        // new core patch needed for this one. conversations.customer_id is
        // used directly; no join to customers required. $filters is already
        // passed by this hook natively, no patch needed to get it here.
        \Eventy::addFilter('search.conversations.or_where', function ($query, $filters, $q) {
            $like_op = \Helper::isPgSql() ? 'ilike' : 'like';

            return $this->addCustomFieldMatch($query, $q, 'conversations.customer_id', $like_op, $this->selectedFieldId($filters));
        }, 20, 3);

        // Register "customer_field" as a real filter name on both search
        // tabs, so it appears as a clickable entry in the "Filters" sidebar
        // list alongside Mailbox/Status/etc — the show/hide toggle itself is
        // already generic (main.js keys off data-filter, no per-filter code).
        \Eventy::addFilter('search.filters_list', function ($filters_list) {
            return $this->appendCustomFieldFilterName($filters_list);
        }, 20, 1);
        \Eventy::addFilter('search.filters_list_customers', function ($filters_list) {
            return $this->appendCustomFieldFilterName($filters_list);
        }, 20, 1);

        // Renders the actual "Custom Field" dropdown into the shared filter
        // panel both tabs use (resources/views/conversations/search.blade.php),
        // via the one existing hook point designed for exactly this — no
        // core patch needed here at all.
        \Eventy::addAction('search.display_filters', function ($filters, $filters_data, $mode) {
            if (!$this->customerFieldTableExists()) {
                return;
            }

            echo \View::make('customerfieldsearch::filter', [
                'filters' => $filters,
                'options' => $this->fieldOptions(),
            ])->render();
        }, 20, 3);
    }

    /**
     * ORs in "does this customer have a custom field value starting with
     * $q" via a correlated whereExists — never a join, which would multiply
     * result rows per matching field value on every one of these call sites.
     *
     * Prefix-anchored ($q% not %q%) so the index added by this module's
     * migration can actually be used at 100k+ row scale.
     *
     * When the agent has picked one field from the new "Custom Field"
     * dropdown, the match narrows to that field only; otherwise (the
     * default, unchanged behaviour) it matches any field, same as before
     * this filter existed.
     */
    protected function addCustomFieldMatch($query, $q, $customerIdColumn, $like_op, $fieldId = null)
    {
        if (!is_string($q) || $q === '' || !$this->customerFieldTableExists()) {
            return $query;
        }

        // No manual case-folding here: LIKE/ILIKE already respect the
        // column's collation, and the rest of ajaxSearch()'s own native
        // matching (name/email/phone) doesn't lowercase the search term
        // either — folding it here would actually break matching against a
        // case-sensitive collation, since the column value itself isn't
        // lowered.
        $value = $this->likeEscape($q);

        $query->orWhereExists(function ($sub) use ($customerIdColumn, $value, $like_op, $fieldId) {
            $sub->select(DB::raw(1))
                ->from(self::TABLE)
                ->whereColumn(self::TABLE.'.customer_id', $customerIdColumn)
                ->where(self::TABLE.'.value', $like_op, $value.'%');

            if ($fieldId) {
                $sub->where(self::TABLE.'.customer_field_id', $fieldId);
            }
        });

        return $query;
    }

    /**
     * Pulls the "Custom Field" dropdown's chosen field ID out of a
     * $filters array (the same shape both getSearchFilters() in
     * ConversationsController and Conversation::search() already use),
     * so it flows through the same $filters every other filter already
     * uses rather than re-parsing the request separately.
     */
    protected function selectedFieldId(array $filters)
    {
        $fieldId = (int) ($filters['customer_field'] ?? 0);

        return $fieldId > 0 ? $fieldId : null;
    }

    /**
     * Appends "customer_field" to a $search_filters-shaped list exactly
     * once — both Conversation::$search_filters and Customer::$search_filters
     * are plain string arrays (see search.filters_list/_customers callers).
     */
    protected function appendCustomFieldFilterName($filters_list)
    {
        if (!$this->customerFieldTableExists() || !is_array($filters_list)) {
            return $filters_list;
        }

        if (!in_array('customer_field', $filters_list, true)) {
            $filters_list[] = 'customer_field';
        }

        return $filters_list;
    }

    /**
     * Dropdown options for the "Custom Field" filter: id => label.
     *
     * Confirmed live 22 Jul: Crm's own Modules\Crm\Entities\CustomerField
     * (table customer_fields — no mailbox_id column, so these fields are
     * global to the install, not per-mailbox) is the real, authoritative
     * source for field names, always preferred when present. Falls back to
     * the distinct-IDs-plus-config-label approach only if that class is
     * genuinely unavailable — in practice this never happens once
     * customerFieldTableExists() has already gated every caller, since
     * customer_customer_field itself is one of Crm's own tables, but the
     * fallback keeps the filter from disappearing entirely if that
     * assumption is ever wrong on some future Crm version.
     */
    protected function fieldOptions()
    {
        $minutes = (int) config('customerfieldsearch.cache_minutes', 15);

        return Cache::remember('customerfieldsearch.field_options', now()->addMinutes($minutes), function () {
            $options = $this->fieldOptionsFromCrm();

            return $options !== null ? $options : $this->fieldOptionsFromDataAndConfig();
        });
    }

    /**
     * Crm orders its own Custom Field settings list by sort_order — kept
     * here rather than re-sorting alphabetically, so the dropdown matches
     * the order an admin already chose in Crm's own settings page.
     */
    protected function fieldOptionsFromCrm()
    {
        $class = 'Modules\Crm\Entities\CustomerField';

        if (!class_exists($class)) {
            return null;
        }

        try {
            $options = $class::query()->orderBy('sort_order')->pluck('name', 'id')->all();

            return $options ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Fallback only: distinct field IDs actually present in
     * customer_customer_field, labelled from customerfieldsearch.fields
     * config where set, else generically as "Field #<id>" so the filter
     * still works, just without friendly names, rather than disappearing.
     */
    protected function fieldOptionsFromDataAndConfig()
    {
        $labels = (array) config('customerfieldsearch.fields', []);

        try {
            $ids = DB::table(self::TABLE)
                ->distinct()
                ->orderBy('customer_field_id')
                ->pluck('customer_field_id')
                ->all();
        } catch (\Throwable $e) {
            $ids = [];
        }

        $options = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $options[$id] = $labels[$id] ?? __('Field #:id', ['id' => $id]);
        }

        asort($options);

        return $options;
    }

    /**
     * Escapes LIKE metacharacters (% _ \) in the raw search term so a
     * customer typing e.g. "50%" or "a_b" can't turn part of their own query
     * into a wildcard.
     */
    protected function likeEscape($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * This fires on every search keystroke, so a raw Schema::hasTable() call
     * (an information_schema/pg_catalog query) on every request adds up.
     * Cached with a bounded TTL rather than forever — the table only
     * appears/disappears when the Crm module is installed/uninstalled, a
     * rare event, but a permanent cache could go stale if that happens
     * without an app-level cache clear in between.
     */
    protected function customerFieldTableExists()
    {
        $minutes = (int) config('customerfieldsearch.cache_minutes', 15);

        return Cache::remember('customerfieldsearch.table_exists', now()->addMinutes($minutes), function () {
            return Schema::hasTable(self::TABLE);
        });
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
