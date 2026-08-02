# Customer Phone Search

Typing a customer's mobile number into a search box mostly didn't find them.
This module makes it work on all three plain-text customer searches, matching
on digits alone so however the number was typed in — `+356 7912 3456`,
`79123456`, `7912-3456` — it's still found.

## What each search did before

* **Search > Customers** matched phones already, but only a number typed in
  full: core's condition is `phones LIKE '%"<digits>"%'`, quotes included, so
  it compares against the whole stored number and nothing less. Reading the
  last six digits off a screen and searching those found nothing.
* **The `customer:` lookup** — the autocomplete behind the ticket sidebar,
  Change Customer, Merge, Cc/Bcc, New Ticket and advanced search's Customer
  filter — didn't match phones at all. `CustomersController::ajaxSearch()`
  does have phone matching, but only under `search_by == 'phone'`, and every
  one of those widgets sends `search_by = 'all'`. This is the surface
  ARMS-22 was reported against.
* **Search > Conversations** had no phone condition of any kind.

## Requirements

Nothing beyond core. Phone numbers live in `customers.phones`, a core column
— unlike the sibling [CustomerFieldSearch](../CustomerFieldSearch/README.md)
module, this one has no dependency on the paid Crm module and is useful
without it.

Confirmed against this fork's core at `1.8.231`. Depends on three hooks that
all already exist: `search.customers.text_match` and
`search.customers.ajax_text_match` (both added to the fork for
CustomerFieldSearch) and `search.conversations.or_where` (upstream). **No new
core patch was needed for this module.**

## How the matching works

`Customer::formatPhones()` stores every phone twice — `value` as the admin
typed it, and `n`, the same number reduced to digits:

```json
[{"value":"+356 7912 3456","type":4,"n":"35679123456"}]
```

Each listener reduces the search term with the same `Helper::phoneToNumeric()`
and matches it as a substring of the column: `phones LIKE '%<digits>%'`. Both
sides being digit-only is what makes formatting irrelevant.

This is deliberately the identical expression core already uses for its own
`search_by == 'phone'` mode, rather than something stricter. Agents reach
phone-mode search and these three surfaces from the same UI, and two boxes
that look alike returning different customers for the same number would be
worse than either behaviour on its own.

No LIKE-metacharacter escaping is needed here (unlike CustomerFieldSearch):
`phoneToNumeric()` has already stripped everything that isn't a digit, so
`%`, `_` and `\` can't reach the pattern. `ilike` isn't needed on PostgreSQL
either — digits have no case to fold.

### Two things this trades away, on purpose

**A country code can be dropped but not added.** The digits typed have to
appear as one run inside the stored number. So an agent can search `79123456`
and find a customer stored as `+356 79123456`, but searching `+356 79654321`
will *not* find one stored as plain `79654321`. Fixing that means normalising
both sides against an assumed country for the install — a decision to take
with the client, not one to infer here. There's a test pinning the current
behaviour so it doesn't change by accident.

**The digits can match a `type`.** Matching the whole JSON column, rather than
picking the `n` values out of it, is what keeps this working on both MySQL and
PostgreSQL — core supports both and they share no portable JSON substring
operator. The cost is that `"type":4` is also digits, so a single-digit search
matches any customer who has a phone at all. Not worth guarding against: a
one-digit search already matches most of the database through the name,
subject and message-body conditions this one is OR'd alongside.

## Why these hooks and not later ones

All three fire from *inside* the existing native-match closure, before
mailbox-visibility scoping is AND'd on outside it. Adding an `orWhere` from a
later hook would be unsafe: Laravel flattens sequential `where()`/`orWhere()`
calls at the top level, so an `orWhere` added after the mailbox condition ORs
against the *entire* preceding expression rather than the intended match
group — quietly returning customers from mailboxes the agent can't view.
Every search surface has a mailbox-scoping test for exactly this.

Note also that this matches `customers.phones` **directly**, not through a
correlated subquery the way CustomerFieldSearch does. That's not just
simplicity: `Conversation::search()` only left-joins the `customers` table if
the compiled SQL doesn't already mention `` `customers`.`id` ``, so a subquery
correlated on `customers.id` would suppress that join and take the native
first/last-name matching down with it. `test_conversations_tab_still_matches_customer_name`
guards that.

On Search > Customers, core's own whole-number condition is left in place
rather than patched out. It's now redundant — this module's match is a strict
superset of it — but it's harmless, and leaving it means one less core patch
to carry through upgrades.

## Tests

`tests/Feature/CustomerPhoneSearchTest.php` covers: the `customer:` lookup
matching a phone in `all` mode (the reported gap) and not being broadened in
`name` mode, a partial number matching on the Customers tab, the Conversations
tab matching at all, mailbox scoping preserved on all three surfaces plus
the Customers tab's separate explicit-`f[mailbox]`-filter path, no duplicate
rows for a customer with two matching numbers, formatting ignored on both
sides, the country-code asymmetry above, customer-name matching still working
on the Conversations tab, and a digit-free term not matching every customer
with a phone.

Ten of the thirteen fail with the module's provider not booted, confirming
they test this module rather than core behaviour that was already there.

## Activation

Manage → Modules → Customer Phone Search → Activate. No migration, no
configuration.
