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
and matches it as a substring of the column, anchored on the `n` key:
`phones LIKE '%"n":"%<digits>%'`. Both sides being digit-only is what makes
formatting irrelevant; the anchor is about short searches, below.

Apart from the anchor this is the expression core already uses for its own
`search_by == 'phone'` mode, rather than something stricter. Agents reach
phone-mode search and these three surfaces from the same UI, and two boxes
that look alike returning different customers for the same number would be
worse than either behaviour on its own.

No LIKE-metacharacter escaping is needed here (unlike CustomerFieldSearch):
`phoneToNumeric()` has already stripped everything that isn't a digit, so
`%`, `_` and `\` can't reach the pattern. `ilike` isn't needed on PostgreSQL
either — digits have no case to fold.

### Three things this trades away, on purpose

**A country code can be dropped but not added.** The digits typed have to
appear as one run inside the stored number. So an agent can search `79123456`
and find a customer stored as `+356 79123456`, but searching `+356 79654321`
will *not* find one stored as plain `79654321`. Fixing that means normalising
both sides against an assumed country for the install — a decision to take
with the client, not one to infer here. There's a test pinning the current
behaviour so it doesn't change by accident.

**A short search can still match a `type`, on customers with several phones.**
The column is matched as text rather than by reading the numbers out of the
JSON, because core supports both MySQL and PostgreSQL and they share no
portable JSON substring operator. That leaves the `type` flag (1-6) sitting in
the same text being searched, so searching `4` would otherwise return every
customer with a mobile number.

Anchoring the pattern on `"n":"` takes most of that away. `formatPhones()`
writes each phone as `value`, then `type`, then `n`, so a phone's own `type` is
behind the anchor and out of reach. It costs nothing and loses no real match:
`n` is the digits of `value`, so any digit run findable in one is findable in
the other.

It only half works, though, and it's worth knowing which half. The pattern
allows anything between the anchor and the digits, so on a customer with two or
more phones a *later* entry's `type` is still reachable and `4` still matches
them. Closing that needs a regex to say "digits only after the anchor", which
is exactly the thing MySQL and PostgreSQL don't spell the same way, so it would
cost the portability the text match was chosen for. Both halves have a test.

There's no minimum term length either, and it's worth being precise about why,
because the sibling CustomerFieldSearch module reached the same
decision on reasoning that does **not** transfer. There, the match is a
subquery pinned to a single customer, so nothing scales with term length. Here
it genuinely is an unindexable scan across the whole customers table. The
argument is just that it isn't a scan this module introduces: a one-character
search already runs `threads.body LIKE '%x%'` and half a dozen other unanchored
LIKEs alongside it, so a threshold would buy nothing while making the box
behave differently at two characters than at three. Core's own
`search_by == 'phone'` mode has no threshold either.

**Excluded customers can still come back on a phone match.** `ajaxSearch`
adds its `exclude_id`/`exclude_email` conditions with `where()` (AND) while the
name and phone conditions use `orWhere()` at the same level, and AND binds
tighter, so the clause compiles as "(email match AND NOT excluded) OR name
match OR phone match". The Merge Customers picker passes `exclude_id` to stop
you merging a customer into themselves, so searching a phone there can now
offer the customer you're merging from.

This is left as-is on purpose. Core's own name matching already bypasses those
excludes the same way, so it isn't a new class of bug, and correcting the
precedence would change exclude behaviour for every `ajaxSearch` caller rather
than just phone matches — the same call CustomerFieldSearch made when it hit
this. `test_exclude_id_does_not_suppress_a_phone_match` pins it so a future fix
to the underlying precedence has to notice rather than change it silently.

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
on the Conversations tab, a digit-free term not matching every customer with a
phone, both halves of the `"n":"` anchor (a single phone's `type` no longer
matched, a later phone's still is), and the two documented-not-fixed
behaviours above (the `exclude_id` bypass, and a 4-argument listener on
`search.customers.text_match` still receiving all four alongside this module's
2-argument one, which is what keeps CustomerFieldSearch's "Custom Field"
dropdown narrowing when both modules are active).

Most of them fail with the module's provider not booted, confirming they test
this module rather than core behaviour that was already there. The ones that
still pass are the negative guards, which is the point of them.

The tests about the shape of the LIKE pattern go through `Eventy` against a
query scoped to their own fixtures, not through a controller. The real search
pages paginate, and a term as short as `4` matches enough of the surrounding
test data through name, address and email that a fixture falls off the first
page — so a controller-level assertion there passes or fails on pagination
rather than on the pattern, which is exactly what it looks like it's testing.

## Activation

Manage → Modules → Customer Phone Search → Activate. No migration, no
configuration.
