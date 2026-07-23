<?php

namespace Tests\Feature;

use App\ActivityLog;
use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\Thread;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Covers ARMS-25: search/filtering over the audit log.
 *
 * Two deliverables:
 *  - AuditLog module: the cross-ticket ticket-action audit page over
 *    `threads` line-items (query correctness + mailbox-visibility scoping).
 *  - The Activity Log filters patched into SecureController@logs.
 *
 * Follows the ArmsReports/CustomerFieldSearch suite pattern: explicit
 * require_once of the module files + explicit row cleanup in tearDown (no
 * DatabaseTransactions). The threads/activity_logs index migrations are NOT
 * run here — they only affect performance, not the correctness this asserts.
 */
class AuditLogTest extends TestCase
{
    protected $mailboxIds = [];
    protected $folderIds = [];
    protected $userIds = [];
    protected $conversationIds = [];
    protected $activityLogIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../../Modules/AuditLog/Services/AuditFilters.php';
        require_once __DIR__.'/../../Modules/AuditLog/Services/AuditQuery.php';
        require_once __DIR__.'/../../Modules/AuditLog/Services/AuditExporter.php';
        require_once __DIR__.'/../../Modules/AuditLog/Http/Controllers/AuditLogController.php';
        require_once __DIR__.'/../../Modules/AuditLog/Providers/AuditLogServiceProvider.php';

        // Registers the "auditlog::" view namespace the controller renders.
        (new \Modules\AuditLog\Providers\AuditLogServiceProvider(app()))->boot();

        // Plain require (not once): route registration must re-run per test,
        // since each test gets a fresh app/router (same gotcha as the
        // ArmsReports suite).
        require __DIR__.'/../../Modules/AuditLog/Http/routes.php';
        \Route::getRoutes()->refreshNameLookups();
    }

    protected function tearDown(): void
    {
        \DB::table('threads')->whereIn('conversation_id', $this->conversationIds)->delete();
        \DB::table('conversations')->whereIn('id', $this->conversationIds)->delete();
        \DB::table('activity_logs')->whereIn('id', $this->activityLogIds)->delete();
        \DB::table('mailbox_user')->whereIn('user_id', $this->userIds)->delete();
        \DB::table('users')->whereIn('id', $this->userIds)->delete();
        \DB::table('folders')->whereIn('id', $this->folderIds)->delete();
        \DB::table('mailboxes')->whereIn('id', $this->mailboxIds)->delete();

        parent::tearDown();
    }

    // ---- helpers ----------------------------------------------------------

    protected function makeMailbox()
    {
        $mailbox = factory(Mailbox::class)->create();
        $this->mailboxIds[] = $mailbox->id;

        return $mailbox;
    }

    protected function makeFolder($mailboxId)
    {
        $folder = factory(Folder::class)->create(['mailbox_id' => $mailboxId, 'type' => Folder::TYPE_UNASSIGNED]);
        $this->folderIds[] = $folder->id;

        return $folder;
    }

    protected function makeUser($role = User::ROLE_USER, $mailboxId = null)
    {
        $user = factory(User::class)->create(['role' => $role]);
        if ($mailboxId) {
            $user->mailboxes()->attach($mailboxId);
        }
        $this->userIds[] = $user->id;

        return $user;
    }

    protected function makeConversation($mailboxId, $folderId, array $attrs = [])
    {
        $conversation = factory(Conversation::class)->create(array_merge([
            'mailbox_id' => $mailboxId,
            'folder_id'  => $folderId,
        ], $attrs));
        $this->conversationIds[] = $conversation->id;

        return $conversation;
    }

    /**
     * A ticket-action line-item (the audit rows this feature lists).
     */
    protected function makeLineItem($conversationId, $actionType, array $attrs = [])
    {
        return factory(Thread::class)->create(array_merge([
            'conversation_id'    => $conversationId,
            'type'               => Thread::TYPE_LINEITEM,
            'action_type'        => $actionType,
            'state'              => Thread::STATE_PUBLISHED,
            'source_via'         => Thread::PERSON_USER,
            'source_type'        => Thread::SOURCE_TYPE_WEB,
            // Deterministic body so the factory's random text can't
            // accidentally satisfy a free-text search assertion.
            'body'               => '',
        ], $attrs));
    }

    protected function makeThread($conversationId, $type, array $attrs = [])
    {
        return factory(Thread::class)->create(array_merge([
            'conversation_id' => $conversationId,
            'type'            => $type,
            'state'           => Thread::STATE_PUBLISHED,
            'body'            => '',
        ], $attrs));
    }

    protected function filters(array $params = [])
    {
        return \Modules\AuditLog\Services\AuditFilters::fromRequest(new Request($params));
    }

    /**
     * The ticket number as a user would actually see and type it:
     * Conversation::number is an accessor that returns the raw `number`
     * column only when app.custom_number is enabled, and the id otherwise
     * (the default in this test environment) — reading the raw column
     * directly would test against a value nobody ever sees.
     */
    protected function displayNumber(Conversation $conversation)
    {
        return Conversation::find($conversation->id)->number;
    }

    protected function runQuery(User $viewer, array $params = [])
    {
        // Widen the default 30-day window so seeded rows aren't filtered out
        // by date unless the test is specifically about dates. Upper bound
        // stays inside the MySQL TIMESTAMP range (< 2038-01-19), since
        // threads.created_at is a TIMESTAMP column.
        $params = array_merge(['from' => '2000-01-01', 'to' => '2037-12-31'], $params);
        $filters = $this->filters($params);

        return (new \Modules\AuditLog\Services\AuditQuery($filters, $viewer))->builder()->get();
    }

    // ---- AuditQuery correctness ------------------------------------------

    public function test_includes_actions_creation_replies_notes_but_excludes_drafts()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $conv = $this->makeConversation($mailbox->id, $folder->id);

        $lineItem = $this->makeLineItem($conv->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);
        $reply = $this->makeThread($conv->id, Thread::TYPE_MESSAGE, ['created_by_user_id' => $admin->id, 'first' => false]);
        $note = $this->makeThread($conv->id, Thread::TYPE_NOTE, ['created_by_user_id' => $admin->id]);
        $created = $this->makeThread($conv->id, Thread::TYPE_CUSTOMER, ['first' => true]);
        // A draft is not an event and must be excluded.
        $draft = $this->makeThread($conv->id, Thread::TYPE_MESSAGE, ['created_by_user_id' => $admin->id, 'state' => Thread::STATE_DRAFT]);

        $ids = $this->runQuery($admin)->pluck('id')->all();

        $this->assertContains($lineItem->id, $ids);
        $this->assertContains($reply->id, $ids);
        $this->assertContains($note->id, $ids);
        $this->assertContains($created->id, $ids);
        $this->assertNotContains($draft->id, $ids, 'Draft threads are not audit events.');
    }

    public function test_action_type_filter_selects_creation_reply_and_note_pseudo_types()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $conv = $this->makeConversation($mailbox->id, $folder->id);

        $lineItem = $this->makeLineItem($conv->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);
        $reply = $this->makeThread($conv->id, Thread::TYPE_MESSAGE, ['created_by_user_id' => $admin->id, 'first' => false]);
        $note = $this->makeThread($conv->id, Thread::TYPE_NOTE, ['created_by_user_id' => $admin->id]);
        $created = $this->makeThread($conv->id, Thread::TYPE_CUSTOMER, ['first' => true]);

        // Scope to this test's mailbox: the shared test DB holds pre-existing
        // notes/replies that the broadened query would otherwise also match.
        $mb = ['mailbox_id' => $mailbox->id];
        $q = \Modules\AuditLog\Services\AuditQuery::class;
        $this->assertEquals([$note->id], $this->runQuery($admin, $mb + ['action_type' => $q::EVENT_NOTE])->pluck('id')->all());
        $this->assertEquals([$created->id], $this->runQuery($admin, $mb + ['action_type' => $q::EVENT_CREATED])->pluck('id')->all());
        $this->assertEquals([$reply->id], $this->runQuery($admin, $mb + ['action_type' => $q::EVENT_REPLY])->pluck('id')->all());
        $this->assertEquals([$lineItem->id], $this->runQuery($admin, $mb + ['action_type' => Thread::ACTION_TYPE_STATUS_CHANGED])->pluck('id')->all());
    }

    public function test_filters_by_agent_action_type_and_ticket()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $agentA = $this->makeUser(User::ROLE_USER);
        $agentB = $this->makeUser(User::ROLE_USER);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        // Explicit numbers so the ticket-number filter is deterministic
        // (not dependent on the auto-numbering observer during the test).
        $conv1 = $this->makeConversation($mailbox->id, $folder->id, ['number' => 5001]);
        $conv2 = $this->makeConversation($mailbox->id, $folder->id, ['number' => 5002]);

        $byA = $this->makeLineItem($conv1->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $agentA->id]);
        $byB = $this->makeLineItem($conv1->id, Thread::ACTION_TYPE_USER_CHANGED, ['created_by_user_id' => $agentB->id]);
        $byA_conv2 = $this->makeLineItem($conv2->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $agentA->id]);

        // Scope to this test's mailbox so pre-existing rows in the shared
        // test DB can't satisfy the exact-match assertions.
        $mb = ['mailbox_id' => $mailbox->id];

        // Agent filter.
        $ids = $this->runQuery($admin, $mb + ['user_id' => $agentA->id])->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$byA->id, $byA_conv2->id], $ids);

        // Action-type filter.
        $ids = $this->runQuery($admin, $mb + ['action_type' => Thread::ACTION_TYPE_USER_CHANGED])->pluck('id')->all();
        $this->assertEquals([$byB->id], $ids);

        // Ticket-number filter (exact), tolerant of a leading '#'.
        $num = $this->displayNumber($conv2);
        $ids = $this->runQuery($admin, $mb + ['ticket' => '#'.$num])->pluck('id')->all();
        $this->assertEquals([$byA_conv2->id], $ids);
    }

    public function test_filters_by_date_range()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $conv = $this->makeConversation($mailbox->id, $folder->id);

        // Outside the queried window — must be excluded.
        $this->makeLineItem($conv->id, Thread::ACTION_TYPE_STATUS_CHANGED, [
            'created_by_user_id' => $admin->id, 'created_at' => Carbon::parse('2026-01-10 09:00:00'),
        ]);
        $recent = $this->makeLineItem($conv->id, Thread::ACTION_TYPE_STATUS_CHANGED, [
            'created_by_user_id' => $admin->id, 'created_at' => Carbon::parse('2026-06-10 09:00:00'),
        ]);

        $ids = $this->runQuery($admin, ['mailbox_id' => $mailbox->id, 'from' => '2026-06-01', 'to' => '2026-06-30'])->pluck('id')->all();

        $this->assertEquals([$recent->id], $ids);
    }

    public function test_free_text_matches_action_detail_and_subject_and_escapes_wildcards()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $convPlain = $this->makeConversation($mailbox->id, $folder->id, ['subject' => 'Broken water meter']);
        $convWild = $this->makeConversation($mailbox->id, $folder->id, ['subject' => 'Discount 50% applied']);

        $matchesSubject = $this->makeLineItem($convPlain->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);
        $matchesDetail = $this->makeLineItem($convWild->id, Thread::ACTION_TYPE_CUSTOMER_CHANGED, [
            'created_by_user_id' => $admin->id, 'action_data' => 'meter-reading-ref',
        ]);

        // Scope to this mailbox so pre-existing rows don't satisfy the match.
        $mb = ['mailbox_id' => $mailbox->id];

        // Subject match.
        $ids = $this->runQuery($admin, $mb + ['q' => 'water meter'])->pluck('id')->all();
        $this->assertEquals([$matchesSubject->id], $ids);

        // action_data match.
        $ids = $this->runQuery($admin, $mb + ['q' => 'meter-reading'])->pluck('id')->all();
        $this->assertEquals([$matchesDetail->id], $ids);

        // A '%' must be matched literally, not as a wildcard: it should find
        // only the row whose subject actually contains '%' ("Discount 50%"),
        // NOT every row (which is what an unescaped '%' wildcard would do).
        $ids = $this->runQuery($admin, $mb + ['q' => '%'])->pluck('id')->all();
        $this->assertEquals([$matchesDetail->id], $ids, 'A literal % must not behave as a wildcard matching everything.');
    }

    // ---- Mailbox-visibility scoping (security-critical) -------------------

    public function test_non_admin_only_sees_actions_in_mailboxes_they_can_view()
    {
        $mailboxA = $this->makeMailbox();
        $mailboxB = $this->makeMailbox();
        $folderA = $this->makeFolder($mailboxA->id);
        $folderB = $this->makeFolder($mailboxB->id);

        // Agent can view mailbox A only.
        $agent = $this->makeUser(User::ROLE_USER, $mailboxA->id);

        $convA = $this->makeConversation($mailboxA->id, $folderA->id);
        $convB = $this->makeConversation($mailboxB->id, $folderB->id);

        $inA = $this->makeLineItem($convA->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $agent->id]);
        $inB = $this->makeLineItem($convB->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $agent->id]);

        $ids = $this->runQuery($agent)->pluck('id')->all();

        $this->assertContains($inA->id, $ids);
        $this->assertNotContains($inB->id, $ids, 'An agent must not see actions in a mailbox they cannot view.');
    }

    public function test_admin_sees_actions_across_all_mailboxes()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailboxA = $this->makeMailbox();
        $mailboxB = $this->makeMailbox();
        $folderA = $this->makeFolder($mailboxA->id);
        $folderB = $this->makeFolder($mailboxB->id);
        $convA = $this->makeConversation($mailboxA->id, $folderA->id);
        $convB = $this->makeConversation($mailboxB->id, $folderB->id);

        $inA = $this->makeLineItem($convA->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);
        $inB = $this->makeLineItem($convB->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);

        $ids = $this->runQuery($admin)->pluck('id')->all();

        $this->assertContains($inA->id, $ids);
        $this->assertContains($inB->id, $ids);
    }

    // ---- Rendering helper -------------------------------------------------

    public function test_action_label_uses_native_text_without_person_prefix()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $conv = $this->makeConversation($mailbox->id, $folder->id);
        $thread = $this->makeLineItem($conv->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);

        $label = \Modules\AuditLog\Services\AuditQuery::actionLabel($thread);

        $this->assertStringContainsString('marked as', $label);
        $this->assertStringNotContainsString(':person', $label);
    }

    public function test_action_label_strips_own_ticket_number_but_keeps_merge_target_number()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $conv = $this->makeConversation($mailbox->id, $folder->id);
        $target = $this->makeConversation($mailbox->id, $folder->id);

        // The Ticket column already shows the row's own conversation number,
        // so the redundant "conversation #N" self-reference must be dropped.
        $statusChange = $this->makeLineItem($conv->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);
        $label = \Modules\AuditLog\Services\AuditQuery::actionLabel($statusChange);
        $ownNumber = $this->displayNumber($conv);
        $this->assertStringNotContainsString((string) $ownNumber, $label);

        // A merge references a DIFFERENT conversation's number — that one
        // must survive the stripping (it's not the row's own ticket).
        $merged = $this->makeLineItem($conv->id, Thread::ACTION_TYPE_MERGED, [
            'created_by_user_id' => $admin->id,
        ]);
        $merged->setMeta(Thread::META_MERGED_INTO_CONV, $target->id);
        $merged->save();
        $targetNumber = $this->displayNumber($target);
        $mergeLabel = \Modules\AuditLog\Services\AuditQuery::actionLabel($merged);
        $this->assertStringContainsString((string) $targetNumber, $mergeLabel);
    }

    // ---- Controller wiring ------------------------------------------------
    // Full-page HTTP GETs can't be asserted in this CLI harness (FreeScout's
    // ResponseHeaders middleware calls header() after PHPUnit has emitted
    // output), so the controller is exercised directly and its view data
    // inspected — no HTML render, no HTTP kernel.

    public function test_controller_index_applies_ticket_filter_to_view_rows()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $conv1 = $this->makeConversation($mailbox->id, $folder->id);
        $conv2 = $this->makeConversation($mailbox->id, $folder->id);
        $onOne = $this->makeLineItem($conv1->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);
        $onTwo = $this->makeLineItem($conv2->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);

        $this->actingAs($admin);
        $num = $this->displayNumber($conv1);
        $request = Request::create('/audit', 'GET', ['ticket' => $num, 'from' => '2000-01-01', 'to' => '2037-12-31']);
        $view = (new \Modules\AuditLog\Http\Controllers\AuditLogController())->index($request);

        $ids = collect($view->getData()['rows']->items())->pluck('id')->all();
        $this->assertContains($onOne->id, $ids);
        $this->assertNotContains($onTwo->id, $ids);
        $this->assertArrayHasKey('action_types', $view->getData());
    }

    public function test_export_csv_returns_a_csv_download()
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $conv = $this->makeConversation($mailbox->id, $folder->id);
        $this->makeLineItem($conv->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);

        $this->actingAs($admin);
        $request = Request::create('/audit/export', 'GET', ['format' => 'csv', 'mailbox_id' => $mailbox->id, 'from' => '2000-01-01', 'to' => '2037-12-31']);
        $response = (new \Modules\AuditLog\Http\Controllers\AuditLogController())->export($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        ob_start();
        $response->sendContent();
        $csv = ob_get_clean();
        $this->assertStringContainsString('Agent', $csv);           // header row
        $this->assertStringContainsString('marked as', $csv);       // the action label
        $this->assertStringContainsString($mailbox->name, $csv);    // scoped to this mailbox
        // Exactly one data row (header + one line item in a fresh mailbox).
        $this->assertEquals(1, substr_count(trim($csv), "\n"));
    }

    public function test_export_pdf_returns_a_pdf_download()
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            $this->markTestSkipped('dompdf not installed in this environment.');
        }

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $mailbox = $this->makeMailbox();
        $folder = $this->makeFolder($mailbox->id);
        $conv = $this->makeConversation($mailbox->id, $folder->id);
        $this->makeLineItem($conv->id, Thread::ACTION_TYPE_STATUS_CHANGED, ['created_by_user_id' => $admin->id]);

        $this->actingAs($admin);
        $request = Request::create('/audit/export', 'GET', ['format' => 'pdf', 'from' => '2000-01-01', 'to' => '2037-12-31']);
        $response = (new \Modules\AuditLog\Http\Controllers\AuditLogController())->export($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    // ---- Activity Log filters (SecureController patch) --------------------
    // The filter helpers are invoked directly via reflection: SecureController@logs
    // declares an inner function so it can't be called twice in one process,
    // and a full render hits the ResponseHeaders issue above.

    protected function makeActivityLog($logName, $description, $causerId, $createdAt = null)
    {
        $id = \DB::table('activity_logs')->insertGetId([
            'log_name'    => $logName,
            'description' => $description,
            'causer_type' => 'App\User',
            'causer_id'   => $causerId,
            'properties'  => json_encode(['ip' => '192.0.2.1']),
            'created_at'  => $createdAt ?: Carbon::now(),
            'updated_at'  => $createdAt ?: Carbon::now(),
        ]);
        $this->activityLogIds[] = $id;

        return $id;
    }

    protected function applyActivityLogFilters($query, Request $request)
    {
        $controller = (new \ReflectionClass(\App\Http\Controllers\SecureController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($controller, 'applyActivityLogFilters');
        $method->setAccessible(true);
        $method->invoke($controller, $query, $request);
    }

    public function test_activity_log_user_filter_narrows_results()
    {
        $userA = $this->makeUser(User::ROLE_USER);
        $userB = $this->makeUser(User::ROLE_USER);
        $this->makeActivityLog(ActivityLog::NAME_USER, ActivityLog::DESCRIPTION_USER_LOGIN, $userA->id);
        $this->makeActivityLog(ActivityLog::NAME_USER, ActivityLog::DESCRIPTION_USER_LOGIN, $userB->id);

        $query = ActivityLog::inLog(ActivityLog::NAME_USER);
        $this->applyActivityLogFilters($query, Request::create('/app-logs/users', 'GET', ['f_user' => $userA->id]));
        $causerIds = $query->pluck('causer_id')->all();

        $this->assertContains($userA->id, $causerIds);
        $this->assertNotContains($userB->id, $causerIds);
    }

    public function test_activity_log_event_and_date_filters()
    {
        $userA = $this->makeUser(User::ROLE_USER);
        $this->makeActivityLog(ActivityLog::NAME_USER, ActivityLog::DESCRIPTION_USER_LOGIN, $userA->id, Carbon::parse('2026-01-05 10:00:00'));
        $this->makeActivityLog(ActivityLog::NAME_USER, ActivityLog::DESCRIPTION_USER_LOGIN_FAILED, $userA->id, Carbon::parse('2026-01-05 10:00:00'));

        // Event filter: only the 'login' row.
        $query = ActivityLog::inLog(ActivityLog::NAME_USER)->where('causer_id', $userA->id);
        $this->applyActivityLogFilters($query, Request::create('/app-logs/users', 'GET', ['f_event' => ActivityLog::DESCRIPTION_USER_LOGIN]));
        $descriptions = $query->pluck('description')->all();
        $this->assertEquals([ActivityLog::DESCRIPTION_USER_LOGIN], array_values(array_unique($descriptions)));

        // Date filter: a window after the rows excludes everything.
        $query = ActivityLog::inLog(ActivityLog::NAME_USER)->where('causer_id', $userA->id);
        $this->applyActivityLogFilters($query, Request::create('/app-logs/users', 'GET', ['f_from' => '2026-06-01', 'f_to' => '2026-06-30']));
        $this->assertEquals(0, $query->count());
    }

    public function test_activity_log_free_text_escapes_wildcards()
    {
        $userA = $this->makeUser(User::ROLE_USER);
        // description is a keyword; put the searchable literal in properties.
        $id = \DB::table('activity_logs')->insertGetId([
            'log_name' => ActivityLog::NAME_USER, 'description' => ActivityLog::DESCRIPTION_USER_LOGIN,
            'causer_type' => 'App\User', 'causer_id' => $userA->id,
            'properties' => json_encode(['ip' => '10.0.0.50%special']),
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $this->activityLogIds[] = $id;
        $other = \DB::table('activity_logs')->insertGetId([
            'log_name' => ActivityLog::NAME_USER, 'description' => ActivityLog::DESCRIPTION_USER_LOGIN,
            'causer_type' => 'App\User', 'causer_id' => $userA->id,
            'properties' => json_encode(['ip' => '10.0.0.51']),
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $this->activityLogIds[] = $other;

        // Literal '%' must match only the row whose properties contain '%'.
        $query = ActivityLog::inLog(ActivityLog::NAME_USER)->where('causer_id', $userA->id);
        $this->applyActivityLogFilters($query, Request::create('/app-logs/users', 'GET', ['f_q' => '%']));
        $ids = $query->pluck('id')->all();

        $this->assertContains($id, $ids);
        $this->assertNotContains($other, $ids);
    }
}
