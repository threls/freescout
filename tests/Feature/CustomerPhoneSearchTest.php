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
 * Covers the phone half of ARMS-22 across all three search surfaces.
 *
 * Every customer gets an explicit phone number rather than the factory's
 * random one, so no test can pass or fail on a faker number happening to
 * contain the digits being searched for.
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
     * Stored through formatPhones(), the same path the profile form uses, so
     * fixtures carry the real value/type/n shape rather than an idealised one.
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
        // no thread row is never returned. The body deliberately contains none
        // of the digits searched for, so a match can only be the phone match.
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
            // Fixtures have no email, and without this ajaxSearch inner-joins
            // emails and drops them before the phone match gets a say.
            'allow_non_emails' => 1,
        ], $extra));

        $response = json_decode($controller->ajaxSearch($request)->getContent(), true);

        return array_column($response['results'], 'id');
    }

    /**
     * The gap ARMS-22 was reported against: every widget sharing ajaxSearch
     * sends search_by = 'all', the one mode core never checked phones in.
     */
    public function test_ajax_search_matches_phone_in_all_mode()
    {
        $customer = $this->makeCustomer(['79123456']);

        $this->actingAs($this->makeUser());

        $this->assertContains($customer->id, $this->ajaxSearch('79123456'));
    }

    /**
     * Core fires the hook only for search_by == 'all', so the deliberately
     * narrower modes keep their old meaning.
     */
    public function test_ajax_search_does_not_broaden_name_only_mode()
    {
        $customer = $this->makeCustomer(['79556677']);

        $this->actingAs($this->makeUser());

        $this->assertNotContains($customer->id, $this->ajaxSearch('79556677', 'name'));
    }

    /**
     * ajaxSearch AND's its mailbox restriction on after the closure this hook
     * fires inside, so the new OR condition must not leak past it.
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
     * Not a risk while this matches a column rather than joining, but it is
     * what would silently break if it ever became a join.
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
     * PRE-EXISTING BEHAVIOUR this widens the reach of, not a fix. ajaxSearch
     * AND's exclude_id in while ORing the match conditions, and AND binds
     * tighter, so any OR'd match escapes the exclusion. Visible in the Merge
     * Customers picker, which passes exclude_id to stop you merging a customer
     * into themselves. Left alone because core's name matching already escapes
     * it the same way and the precedence fix would hit every ajaxSearch caller
     * (see README); pinned here so that fix can't land unnoticed.
     */
    public function test_exclude_id_does_not_suppress_a_phone_match()
    {
        $customer = $this->makeCustomer(['79556001']);

        $this->actingAs($this->makeUser());

        $ids = $this->ajaxSearch('79556001', 'all', ['exclude_id' => $customer->id]);

        $this->assertContains($customer->id, $ids, 'documents current pre-existing behaviour, see docblock');
    }

    /**
     * This module registers for 2 of the 4 arguments search.customers.text_match
     * passes; CustomerFieldSearch registers for all 4 on the same hook. Asking
     * for fewer must not shorten what the other one gets, or that module's
     * "Custom Field" dropdown quietly stops narrowing. A probe listener stands
     * in for the sibling, since the property under test is Eventy's argument
     * handling rather than anything about custom fields, and it keeps this test
     * free of the CRM table.
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
     * Core matched phones here as '%"<digits>"%', quotes included, so only a
     * number typed out in full ever matched.
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
     * Search > Conversations had no phone condition at all.
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
     * Conversation::search left-joins customers only if the compiled SQL
     * doesn't already mention `customers`.`id`, so a phone condition written
     * as a subquery correlated on that column would suppress the join and take
     * core's name matching with it. Proves the direct column match doesn't.
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
     * The point of reducing both sides to digits.
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
     * The typed digits have to appear as one run inside the stored number, so
     * a country code can be left off but not added. Normalising against an
     * assumed country is the client's call; pinned so it isn't changed by
     * accident or reported as a bug.
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
     * A term with no digits must not become LIKE '%%' and match every
     * customer who has a phone.
     */
    public function test_a_term_without_digits_does_not_match_every_phone()
    {
        $customer = $this->makeCustomer(['79001122']);
        $user = $this->makeUser();

        $ids = $this->searchCustomers('zzzzzznotacustomer', $user);

        $this->assertNotContains($customer->id, $ids);
    }
}
