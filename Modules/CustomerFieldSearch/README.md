# CustomerFieldSearch

Plain-text customer search (Search > Customers, Search > Conversations, and
the shared ticket-sidebar lookup used by Change Customer, Merge, Cc/Bcc, New
Ticket, and advanced search's Customer filter) didn't match customer custom
field values — only name, email, phone, company, address, etc. The (paid)
Crm module already lets an agent filter by an exact custom field value via
`#field:value` syntax, but typing e.g. an Account Number or ID Card number
into the regular search box found nothing.

This module ORs a "does this customer have a custom field value starting
with the search term" condition into all three search surfaces, without
needing to know the field names in advance — any Crm custom field (Account
Number, ID Card, or any added later) becomes searchable automatically.

Search > Customers and Search > Conversations also get a "Custom Field"
dropdown filter, alongside Mailbox/Status/etc, letting an agent narrow a
search to one specific field instead of matching any of them — see
"Narrowing to one field" below.

## Requirements

* The (paid) [Crm](https://freescout.net/module/crm/) module, installed and
  active — specifically its `customer_customer_field` table. This module
  no-ops cleanly (via a `Schema::hasTable()` guard) if that table isn't
  present, so it's harmless to leave active without Crm installed.
* Confirmed compatible with this fork's core at `1.8.229` — depends on
  `search.customers.text_match` and `search.customers.ajax_text_match` (both
  new fork patches, see below), `search.conversations.or_where`,
  `search.filters_list`, `search.filters_list_customers`, and
  `search.display_filters` (the last four already present upstream).

## Substring match, and why it's affordable

Matching is `value LIKE '%q%'`. This was originally the opposite: ARMS-22
specified prefix-anchored (`'q%'`) matching for performance, and this section
used to explain why. The 29 Jul review reversed it, because prefix matching
made real data unfindable — ID card numbers are stored zero-padded, agents
type them without the leading zero, so searching `71787M` returned nothing
against a stored `071787M`.

The original performance concern doesn't apply to this query, which is worth
understanding before anyone "fixes" it back. A leading wildcard can't seek on
an index, so the worry was every search scanning the whole
`customer_customer_field` table. But the match is a **correlated** subquery
pinned on `customer_id`, and that column is the leading half of Crm's own
`customer_customer_field_customer_id_customer_field_id_unique` index
(confirmed on the live database). So each candidate customer's own handful of
field rows is seeked to, and the term is compared against only those values.
The scan the prefix anchoring was protecting against never happens.

**The `value` index this module's migration adds is now redundant** for this
query. It's deliberately left in place rather than dropped: it's a small
prefix-length index, it costs almost nothing, and dropping it would mean
another schema change against a table the Crm module owns for no functional
gain. The migration's `down()` still exists if it's ever worth removing.

The user's own search term is also escaped for LIKE metacharacters (`%`,
`_`, `\`) before being used as a pattern, so typing e.g. an account number
containing a literal `%` or `_` can't turn part of the search into an
unintended wildcard.

## New fork patches

Two new hooks were added to core, both fired from *inside* the existing
native-match closure, before mailbox-visibility scoping is applied outside
it:

* `search.customers.text_match` in
  `app/Http/Controllers/ConversationsController.php` (`searchCustomers()`,
  the Search > Customers tab) — registered `20, 4` (`$query, $q, $like_op,
  $filters`; `$filters` was added alongside the "Custom Field" dropdown so a
  listener can read the selected field without re-parsing the request).
* `search.customers.ajax_text_match` in
  `app/Http/Controllers/CustomersController.php` (`ajaxSearch()`, the
  endpoint shared by the ticket sidebar, Change Customer, Merge, Cc/Bcc, New
  Ticket, and advanced search) — registered `20, 2` (`$query, $q`), and only
  fired when `search_by == 'all'` so the intentionally-narrower
  `name`/`email`/`phone` modes aren't broadened.

Adding these as `orWhere` conditions from a *later* hook (e.g. the existing
`search.customers.apply_filters`) would have been unsafe: Laravel flattens
sequential `where()`/`orWhere()` calls at the top level, so an `orWhere`
added after mailbox scoping's `AND` condition ORs against the *entire*
preceding expression, not just the intended match group — silently
bypassing mailbox visibility restrictions. Firing from inside the original
closure avoids this.

Search > Conversations needed no new core patch: `app/Conversation.php`'s
`search()` already fires `search.conversations.or_where` from exactly the
right position, so this module just listens on it.

Every listener uses a correlated `whereExists()` subquery against
`customer_customer_field`, never a `join`. `ConversationsController`'s query
already unconditionally groups by `customers.id`, but `CustomersController::ajaxSearch()`
doesn't (its select list can carry an unaggregated `emails.email` column,
which a blanket `groupBy` would break under strict SQL mode) — `whereExists`
sidesteps the row-multiplication problem entirely rather than requiring a
matching `groupBy` at every call site.

## Narrowing to one field

Search > Customers and Search > Conversations share one filter panel
(`resources/views/conversations/search.blade.php`), which already exposes
`search.display_filters` as an extension point right where the built-in
Mailbox/Status/etc filters render — no core patch needed for this part. This
module renders a "Custom Field" `<select>` there, listing every field ID
actually present in `customer_customer_field` data.

Picking a field narrows the OR'd custom-field match (in `addCustomFieldMatch()`)
to that field's `customer_field_id` only; leaving it unset keeps the
original "matches any field" behaviour exactly as before this existed. The
filter is also registered into `search.filters_list`/`search.filters_list_customers`
so it shows up as a clickable entry in the "Filters" sidebar list, same as
any built-in filter — the show/hide toggle itself needed no JS changes,
since `main.js` already keys that purely off each filter's `data-filter`
attribute.

`ajaxSearch()` (the ticket sidebar/Change Customer/Merge/Cc-Bcc/New Ticket
widget) deliberately has no dropdown of its own — it's a compact
autocomplete, not a filtered search page — so it always matches any field,
unaffected by this filter.

## Where the dropdown's field names come from

Confirmed live against the actual Crm module (22 Jul 2026, via Forge
Commands against the demo server): its own `Modules\Crm\Entities\CustomerField`
model (table `customer_fields` — columns `id, name, type, options, required,
display, customer_can_view, customer_can_edit, sort_order, conv_list`; **no
`mailbox_id`**, so these fields are global to the install, not per-mailbox)
is the real, authoritative source. Real data confirmed: `1 => "Account
Number"`, `2 => "ID Card"`, matching exactly what ARMS-22 asked for by name.

`fieldOptionsFromCrm()` queries that model directly (ordered by its own
`sort_order`, so the dropdown matches whatever order an admin already chose
in Crm's settings page) whenever the class exists — which in practice is
always true, since this module already requires one of Crm's other tables
(`customer_customer_field`) just to do anything. The distinct-IDs-plus-config
fallback (`fieldOptionsFromDataAndConfig()`) only engages if that class is
ever genuinely unavailable.

## Configuration

`Config/config.php` exposes:

* `cache_minutes` (default 15, overridable via `CUSTOMER_FIELD_SEARCH_CACHE_MINUTES`)
  — how long the `customer_customer_field` table-existence check, and the
  "Custom Field" dropdown's resolved options, are each cached for. Both fire
  on every search-page render (the table check on every keystroke too), so
  caching avoids repeated `information_schema`/`pg_catalog` queries and a
  fresh `customer_fields`/`customer_customer_field` lookup. The dropdown
  only goes stale if a field is renamed or a brand new one added after the
  cache warms — it'll appear once the cache naturally expires, same
  trade-off as the table-existence check.
* `fields` (default `[]`) — **fallback only**, used solely if Crm's
  `CustomerField` class is ever unavailable (see above). Maps a
  `customer_field_id` to a human label (e.g. `1 => 'Account Number'`). A
  field ID present in the data but missing here still appears in that
  fallback case, just labelled `Field #<id>`.

## Tests

`tests/Feature/CustomerFieldSearchTest.php` covers: mailbox-scoping is
preserved for all three search surfaces, matching is substring (a value
containing the term is matched, one that doesn't contain it isn't), a
zero-padded ID card is found when typed without its leading zero (the
original ARMS-22 report, using the real values from it), `ajaxSearch()`
doesn't duplicate rows for a customer with multiple matching field values,
`search_by` modes other than `all` aren't broadened, a search term
containing `%`/`_` doesn't behave as a wildcard, the "Custom Field" filter
actually narrows matches on both tabs (and has no effect on `ajaxSearch()`),
the dropdown's options prefer configured labels and fall back to a generic
one, `customer_field` is registered in both tabs' filters list exactly
once, and the rendered filter reflects the selected option.

## Activation

Manage → Modules → Customer Field Search → Activate, then run
`php artisan migrate` to add the `customer_customer_field.value` index
(picked up automatically via `loadMigrationsFrom()`). No effect without the
Crm module also installed and active.
