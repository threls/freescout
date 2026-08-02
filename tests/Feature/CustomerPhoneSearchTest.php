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
 * Covers the phone half of ARMS-22: finding a customer by their mobile
 * number from Search > Customers, Search > Conversations, and the shared
 * ajaxSearch lookup behind the ticket sidebar, Change Customer, Merge,
 * Cc/Bcc, New Ticket and advanced search's Customer filter.
 *
 * Every customer here gets an explicit phone number rather than the factory's
 * random one, so a test can't pass or fail on a faker number happening to
 * contain (or not contain) the digits being searched for.
 */
class CustomerPhoneSearchTest extends TestCase
{
    protected $mailboxIds = [];
    protected $folderIds = [];
    protected $conversationIds = [];
    protected $customerIds = [];
    protected $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../../Modules/CustomerPhoneSearch/Providers/CustomerPhoneSearchServiceProvider.php';

        (new \Modules\CustomerPhoneSearch\Providers\CustomerPhoneSearchServiceProvider(app()))->boot();
    }

    protected function tearDown(): void
    {
        \DB::table('threads')->whereIn('conversation_id', $this->conversationIds)->delete();
        \DB::table('conversations')->whereIn('id', $this->conversationIds)->delete();
        \DB::table('customers')->whereIn('id', $this->customerIds)->delete();
        \DB::table('mailbox_user')->whereIn('user_id', $this->userIds)->delete();
        \DB::table('users')->whereIn('id', $this->userIds)->delete();
        \DB::table('folders')->whereIn('id', $this->folderIds)->delete();
        \DB::table('mailboxes')->whereIn('id', $this->mailboxIds)->delete();

        config(['app.limit_user_customer_visibility' => false]);

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

    /**
     * $phones are stored exactly as an admin would have typed them, through
     * Customer::formatPhones() — the same path the customer profile form
     * uses — so these fixtures carry the real 'value'/'type'/'n' shape rather
     * than an idealised one.
     */
    protected function makeCustomer($phones = ['70000000'])
    {
        $rows = [];
        foreach ((array) $phones as $phone) {
            $rows[] = ['value' => $phone, 'type' => Customer::PHONE_TYPE_MOBILE];
        }

        $customer = factory(Customer::class)->create([
            'phones' => json_encode(Customer::formatPhones($rows)),
        ]);
        $this->customerIds[] = $customer->id;

        return $customer;
    }

    protected function makeConversation($mailboxId, $folderId, $customerId, $userId)
    {
        $conversation = factory(Conversation::class)->create([
            'mailbox_id'         => $mailboxId,
            'folder_id'          => $folderId,
            'customer_id'        => $customerId,
            'created_by_user_id' => $userId,
        ]);
        $this->conversationIds[] = $conversation->id;

        // Conversation::search() inner-joins threads, so a conversation with
        // no thread row is never returned whatever else matches. The content
        // deliberately doesn't contain any of the digits searched for below,
        // so a match can only have come from the phone condition.
        factory(Thread::class)->create([
            'conversation_id' => $conversation->id,
            'customer_id'     => $customerId,
            'to'              => json_encode(['unrelated@example.com']),
            'body'            => 'unrelated thread content',
        ]);

        return $conversation;
    }

    protected function searchCustomers($q, $user)
    {
        $controller = new \App\Http\Controllers\ConversationsController();
        $request = Request::create('/conversations/search', 'GET', ['q' => $q]);

        return collect($controller->searchCustomers($request, $user)->items())->pluck('id')->all();
    }

    protected function ajaxSearch($q, $searchBy = 'all', $extra = [])
    {
        $controller = new \App\Http\Controllers\CustomersController();
        $request = Request::create('/customers/ajax-search', 'GET', array_merge([
            'q'         => $q,
            'search_by' => $searchBy,
            'use_id'    => 1,
            // These fixtures have no email address — without this,
            // ajaxSearch's inner join to emails drops them before any phone
            // match gets a say.
            'allow_non_emails' => 1,
        ], $extra));

        $response = json_decode($controller->ajaxSearch($request)->getContent(), true);

        return array_column($response['results'], 'id');
    }

    /**
     * The gap ARMS-22 was actually reported against. ajaxSearch backs the
     * ticket sidebar, Change Customer, Merge, Cc/Bcc, New Ticket and the
     * advanced search "Customer" filter, and all of them send
     * search_by = 'all' — the one mode where core never checked phones.
     */
    public function test_ajax_search_matches_phone_in_all_mode()
    {
        $customer = $this->makeCustomer(['79123456']);

        $this->actingAs($this->makeUser());

        $this->assertContains($customer->id, $this->ajaxSearch('79123456'));
    }

    /**
     * Core only fires this hook for search_by == 'all', so the deliberately
     * narrower modes keep their old meaning. Name mode would otherwise start
     * quietly returning phone matches too.
     */
    public function test_ajax_search_does_not_broaden_name_only_mode()
    {
        $customer = $this->makeCustomer(['79556677']);

        $this->actingAs($this->makeUser());

        $this->assertNotContains($customer->id, $this->ajaxSearch('79556677', 'name'));
    }

    /**
     * ajaxSearch applies its mailbox restriction as an AND'd join/whereIn
     * added *after* the closure this module's hook fires inside. Proves the
     * new OR condition can't leak past it and surface a customer from a
     * mailbox the agent can't view.
     */
    public function test_ajax_search_respects_mailbox_scoping()
    {
        config(['app.limit_user_customer_visibility' => true]);

        $mailboxA = $this->makeMailbox();
        $mailboxB = $this->makeMailbox();
        $folderA = $this->makeFolder($mailboxA->id);
        $folderB = $this->makeFolder($mailboxB->id);
        $user = $this->makeUser($mailboxA->id);

        $visible = $this->makeCustomer(['79881122']);
        $hidden = $this->makeCustomer(['79881122']);

        $this->makeConversation($mailboxA->id, $folderA->id, $visible->id, $user->id);
        $this->makeConversation($mailboxB->id, $folderB->id, $hidden->id, $user->id);

        $this->actingAs($user);
        $ids = $this->ajaxSearch('79881122');

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    /**
     * A customer with two numbers that both match must come back once. Not a
     * risk today — this matches a column on the customers row rather than
     * joining anything — but it's the property that would silently break if
     * this were ever reworked into a join against a phones table.
     */
    public function test_ajax_search_does_not_duplicate_customer_with_two_matching_phones()
    {
        $customer = $this->makeCustomer(['79447001', '79447002']);

        $this->actingAs($this->makeUser());

        $matches = array_filter($this->ajaxSearch('7944700'), function ($id) use ($customer) {
            return $id == $customer->id;
        });

        $this->assertCount(1, $matches);
    }

    /**
     * PRE-EXISTING BEHAVIOUR this widens the reach of, confirmed by running it
     * rather than reasoned about. ajaxSearch adds exclude_id/exclude_email
     * with where() (AND) while the name and phone conditions use orWhere() at
     * the same nesting level, and AND binds tighter than OR, so the compiled
     * clause is "(email match AND NOT excluded) OR name match OR phone match",
     * not "(any match) AND NOT excluded". A customer passed as exclude_id can
     * therefore still come back on a phone match.
     *
     * Where that shows: the Merge Customers picker (#merge_customer2_id in
     * main.js) passes exclude_id to keep you from merging a customer into
     * themselves, and sends search_by = 'all', so searching a phone number
     * there can now offer the customer you're merging from.
     *
     * Not fixed here, deliberately, and the same call was made for the same
     * reason when CustomerFieldSearch hit this: the excludes are equally
     * bypassed by core's own name matching today, so this isn't a new class of
     * bug, and correcting the precedence would change exclude behaviour for
     * every ajaxSearch caller rather than just phone matches. Pinned by a test
     * so that a future fix to the underlying precedence has to notice it
     * instead of changing this silently.
     */
    public function test_exclude_id_does_not_suppress_a_phone_match()
    {
        $customer = $this->makeCustomer(['79556001']);

        $this->actingAs($this->makeUser());

        $ids = $this->ajaxSearch('79556001', 'all', ['exclude_id' => $customer->id]);

        $this->assertContains($customer->id, $ids, 'documents current pre-existing behaviour, see docblock');
    }

    /**
     * Both this module and CustomerFieldSearch listen on
     * search.customers.text_match, and they register for different numbers of
     * the four arguments core passes (2 here, 4 there). Eventy's Filter::fire()
     * builds each listener's parameter list independently from the full
     * argument array, so asking for fewer can't shorten what another listener
     * receives — but the failure mode if that ever stopped being true is
     * silent, and it would be CustomerFieldSearch's "Custom Field" dropdown
     * that quietly stopped narrowing, not anything in this module.
     *
     * Uses a probe listener rather than booting the real sibling module, since
     * the property under test is Eventy's argument handling and nothing about
     * custom fields. That also keeps this test free of the CRM table the
     * sibling's own suite has to create.
     */
    public function test_registering_for_fewer_arguments_does_not_truncate_other_listeners()
    {
        $received = null;

        \Eventy::addFilter('search.customers.text_match', function ($query, $q, $like_op, $filters = []) use (&$received) {
            $received = func_get_args();

            return $query;
        }, 30, 4);

        $this->makeCustomer(['79773311']);
        $this->searchCustomers('79773311', $this->makeUser());

        $this->assertCount(4, $received, 'a 4-argument listener must still get all four');
        $this->assertSame('79773311', $received[1]);
        $this->assertIsArray($received[3], '$filters must survive alongside this module\'s 2-argument listener');
    }

    /**
     * Search > Customers already matched phones, but only a whole number:
     * core's condition is '%"<digits>"%', quotes included, so it matches the
     * stored digit string end to end or not at all. Typing the last six
     * digits of a number you can see on screen found nothing.
     */
    public function test_customers_tab_matches_a_partial_number()
    {
        $customer = $this->makeCustomer(['+356 7912 3456']);
        $user = $this->makeUser();

        $this->assertContains($customer->id, $this->searchCustomers('123456', $user));
    }

    /**
     * Same safety proof as the ajaxSearch one, for searchCustomers' own
     * $limited_visibility branch.
     */
    public function test_customers_tab_respects_mailbox_scoping()
    {
        config(['app.limit_user_customer_visibility' => true]);

        $mailboxA = $this->makeMailbox();
        $mailboxB = $this->makeMailbox();
        $folderA = $this->makeFolder($mailboxA->id);
        $folderB = $this->makeFolder($mailboxB->id);
        $user = $this->makeUser($mailboxA->id);

        $visible = $this->makeCustomer(['79662200']);
        $hidden = $this->makeCustomer(['79662200']);

        $this->makeConversation($mailboxA->id, $folderA->id, $visible->id, $user->id);
        $this->makeConversation($mailboxB->id, $folderB->id, $hidden->id, $user->id);

        $ids = $this->searchCustomers('7966220', $user);

        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    /**
     * searchCustomers has a second, differently-shaped restriction — an
     * explicit ?f[mailbox]=X filter, which builds its own join/where after
     * the closure the hook fires inside. Needs its own proof; the
     * $limited_visibility branch above doesn't stand in for it.
     */
    public function test_customers_tab_explicit_mailbox_filter_restricts_phone_match()
    {
        $mailboxA = $this->makeMailbox();
        $mailboxB = $this->makeMailbox();
        $folderA = $this->makeFolder($mailboxA->id);
        $folderB = $this->makeFolder($mailboxB->id);
        $user = $this->makeUser($mailboxA->id);
        $user->mailboxes()->attach($mailboxB->id); // can view both — the filter does the restricting here

        $inFilter = $this->makeCustomer(['79334455']);
        $outOfFilter = $this->makeCustomer(['79334455']);

        $this->makeConversation($mailboxA->id, $folderA->id, $inFilter->id, $user->id);
        $this->makeConversation($mailboxB->id, $folderB->id, $outOfFilter->id, $user->id);

        $controller = new \App\Http\Controllers\ConversationsController();
        $request = Request::create('/conversations/search', 'GET', [
            'q' => '7933445',
            'f' => ['mailbox' => $mailboxA->id],
        ]);
        $ids = collect($controller->searchCustomers($request, $user)->items())->pluck('id')->all();

        $this->assertContains($inFilter->id, $ids);
        $this->assertNotContains($outOfFilter->id, $ids);
    }

    /**
     * Search > Conversations didn't match phone numbers at all. Reuses the
     * existing search.conversations.or_where hook, so no core patch — but
     * that hook sits inside a query whose customers table is left-joined
     * conditionally, so it needs proving rather than assuming.
     */
    public function test_conversations_tab_matches_phone()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);

        $customer = $this->makeCustomer(['79775533']);
        $conversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, $user->id);

        $ids = Conversation::search('79775533', [], $user)->pluck('id')->all();

        $this->assertContains($conversation->id, $ids);
    }

    /**
     * Conversation::search always restricts to mailboxesIdsCanView(),
     * regardless of the app.limit_user_customer_visibility setting, applied
     * before the closure this hook fires inside.
     */
    public function test_conversations_tab_respects_mailbox_scoping()
    {
        $mailboxA = $this->makeMailbox();
        $mailboxB = $this->makeMailbox();
        $folderA = $this->makeFolder($mailboxA->id);
        $folderB = $this->makeFolder($mailboxB->id);
        $user = $this->makeUser($mailboxA->id);

        $visible = $this->makeCustomer(['79228844']);
        $hidden = $this->makeCustomer(['79228844']);

        $visibleConversation = $this->makeConversation($mailboxA->id, $folderA->id, $visible->id, $user->id);
        $hiddenConversation = $this->makeConversation($mailboxB->id, $folderB->id, $hidden->id, $user->id);

        $ids = Conversation::search('7922884', [], $user)->pluck('id')->all();

        $this->assertContains($visibleConversation->id, $ids);
        $this->assertNotContains($hiddenConversation->id, $ids);
    }

    /**
     * Adding the customers.phones condition must not stop Conversation::search
     * left-joining the customers table. That join is added only if the
     * compiled SQL doesn't already mention `customers`.`id` — so a phone
     * condition written as a subquery correlated on customers.id (the shape
     * the sibling CustomerFieldSearch module uses) would suppress it and take
     * the native first_name/last_name matching down with it. Searching a
     * customer's name proves that still works.
     */
    public function test_conversations_tab_still_matches_customer_name()
    {
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $user = $this->makeUser($mailbox->id);

        $customer = $this->makeCustomer();
        $conversation = $this->makeConversation($mailbox->id, $folder->id, $customer->id, $user->id);

        $ids = Conversation::search($customer->first_name, [], $user)->pluck('id')->all();

        $this->assertContains($conversation->id, $ids);
    }

    /**
     * The point of reducing both sides to digits: however the number was
     * typed into the profile, and however the agent types it into the search
     * box, they meet in the middle.
     */
    public function test_formatting_is_ignored_on_both_sides()
    {
        $customer = $this->makeCustomer(['+356 7912 3456']);
        $user = $this->makeUser();

        $this->assertContains($customer->id, $this->searchCustomers('79123456', $user), 'stored with spaces, searched without');
        $this->assertContains($customer->id, $this->searchCustomers('+356 7912 3456', $user), 'searched exactly as stored');
        $this->assertContains($customer->id, $this->searchCustomers('7912-3456', $user), 'searched with different punctuation');
    }

    /**
     * Documents the one asymmetry worth knowing about, so nobody reports it
     * as a bug or "fixes" it without deciding to. The digits typed have to
     * appear as one run inside the stored number, so an agent can leave the
     * country code off a number stored with one — but can't add a country
     * code to a number stored without one. Making that work would mean
     * guessing the install's country to normalise against, which is a real
     * decision to take with the client rather than a detail to infer here.
     */
    public function test_a_country_code_can_be_omitted_but_not_invented()
    {
        $withCode = $this->makeCustomer(['+356 79123456']);
        $withoutCode = $this->makeCustomer(['79654321']);
        $user = $this->makeUser();

        $this->assertContains($withCode->id, $this->searchCustomers('79123456', $user));
        $this->assertNotContains($withoutCode->id, $this->searchCustomers('+356 79654321', $user));
    }

    /**
     * A search term with no digits in it — an ordinary name or email — can't
     * be a phone number, and must not turn into a LIKE '%%' matching every
     * customer who has one.
     */
    public function test_a_term_without_digits_does_not_match_every_phone()
    {
        $customer = $this->makeCustomer(['79001122']);
        $user = $this->makeUser();

        $ids = $this->searchCustomers('zzzzzznotacustomer', $user);

        $this->assertNotContains($customer->id, $ids);
    }
}
