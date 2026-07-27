<?php

namespace Tests\Feature;

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\User;
use Tests\TestCase;

/**
 * Covers the AgentFolders module's Eventy filters (ARMS-46): the actual
 * "filter this folder's conversations to one fixed agent" behavior that
 * Console/PatchCustomFoldersAssignee.php's form/controller patch merely
 * captures the setting for. Custom Folders itself isn't installed in this
 * repo, so folder.conversations_query is exercised here with a real base
 * query standing in for the one Custom Folders would normally have already
 * built (mailbox + state), matching what AgentFoldersServiceProvider
 * itself falls back to in folder.update_counters when Custom Folders'
 * own listener isn't present in the chain.
 */
class AgentFoldersServiceProviderTest extends TestCase
{
    const CUSTOM_FOLDER_TYPE = 200;

    private $mailbox;
    private $agentA;
    private $agentB;
    private $conversationA1;
    private $conversationA2Closed;
    private $conversationB;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../../Modules/AgentFolders/Providers/AgentFoldersServiceProvider.php';
        (new \Modules\AgentFolders\Providers\AgentFoldersServiceProvider(app()))->boot();

        $this->mailbox = factory(Mailbox::class)->create();

        $this->agentA = factory(User::class)->create(['role' => User::ROLE_USER]);
        $this->agentB = factory(User::class)->create(['role' => User::ROLE_USER]);
        $this->mailbox->users()->sync([$this->agentA->id, $this->agentB->id]);

        $folderUnassigned = factory(Folder::class)->create(['mailbox_id' => $this->mailbox->id]);

        $this->conversationA1 = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'folder_id'  => $folderUnassigned->id,
            'user_id'    => $this->agentA->id,
            'status'     => Conversation::STATUS_ACTIVE,
        ]);
        $this->conversationA2Closed = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'folder_id'  => $folderUnassigned->id,
            'user_id'    => $this->agentA->id,
            'status'     => Conversation::STATUS_CLOSED,
        ]);
        $this->conversationB = factory(Conversation::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'folder_id'  => $folderUnassigned->id,
            'user_id'    => $this->agentB->id,
            'status'     => Conversation::STATUS_ACTIVE,
        ]);
    }

    protected function assigneeFolder($assignee_id)
    {
        $folder = factory(Folder::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'type'       => self::CUSTOM_FOLDER_TYPE,
        ]);
        $folder->meta = ['assignee_id' => $assignee_id];
        $folder->save();

        return $folder;
    }

    protected function baseQuery()
    {
        return Conversation::where('conversations.mailbox_id', $this->mailbox->id)
            ->where('conversations.state', Conversation::STATE_PUBLISHED);
    }

    public function test_conversations_query_restricts_to_the_fixed_assignee()
    {
        $folder = $this->assigneeFolder($this->agentA->id);

        $query = \Eventy::filter('folder.conversations_query', $this->baseQuery(), $folder, $this->agentB->id);
        $ids = $query->pluck('id')->all();

        $this->assertContains($this->conversationA1->id, $ids);
        $this->assertContains($this->conversationA2Closed->id, $ids);
        $this->assertNotContains($this->conversationB->id, $ids);
    }

    /**
     * The whole point of this module: the folder must show agent A's
     * tickets no matter which user_id is passed as "the current viewer" -
     * unlike Custom Folders' own "Show only own conversations", which is
     * relative to whoever is logged in.
     */
    public function test_filter_is_not_relative_to_the_viewer()
    {
        $folder = $this->assigneeFolder($this->agentA->id);

        $asViewedByA = \Eventy::filter('folder.conversations_query', $this->baseQuery(), $folder, $this->agentA->id)->pluck('id')->all();
        $asViewedByB = \Eventy::filter('folder.conversations_query', $this->baseQuery(), $folder, $this->agentB->id)->pluck('id')->all();

        sort($asViewedByA);
        sort($asViewedByB);
        $this->assertSame($asViewedByA, $asViewedByB);
    }

    public function test_conversations_query_is_a_no_op_without_assignee_meta()
    {
        $folder = factory(Folder::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'type'       => self::CUSTOM_FOLDER_TYPE,
        ]);

        $ids = \Eventy::filter('folder.conversations_query', $this->baseQuery(), $folder, $this->agentB->id)->pluck('id')->all();

        $this->assertContains($this->conversationA1->id, $ids);
        $this->assertContains($this->conversationB->id, $ids);
    }

    public function test_conversations_query_is_a_no_op_for_other_folder_types()
    {
        $folder = factory(Folder::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'type'       => Folder::TYPE_UNASSIGNED,
        ]);
        $folder->meta = ['assignee_id' => $this->agentA->id];
        $folder->save();

        $ids = \Eventy::filter('folder.conversations_query', $this->baseQuery(), $folder, $this->agentB->id)->pluck('id')->all();

        $this->assertContains($this->conversationB->id, $ids, 'assignee_id must only apply to Custom Folders-type folders');
    }

    public function test_update_counters_reflects_only_the_assignee()
    {
        $folder = $this->assigneeFolder($this->agentA->id);

        $updated = \Eventy::filter('folder.update_counters', false, $folder);

        $this->assertTrue($updated);
        $this->assertSame(1, $folder->active_count, 'only conversationA1 is active and assigned to agent A');
        $this->assertSame(2, $folder->total_count, 'both of agent A\'s conversations count, active and closed');
    }

    public function test_update_counters_is_a_no_op_without_assignee_meta()
    {
        $folder = factory(Folder::class)->create([
            'mailbox_id' => $this->mailbox->id,
            'type'       => self::CUSTOM_FOLDER_TYPE,
        ]);

        $this->assertFalse(\Eventy::filter('folder.update_counters', false, $folder));
    }

    public function test_get_nearby_query_restricts_to_the_fixed_assignee()
    {
        $folder = $this->assigneeFolder($this->agentA->id);

        $query = \Eventy::filter('conversation.get_nearby_query', $this->baseQuery(), $this->conversationA1, 'next', $folder);
        $ids = $query->pluck('id')->all();

        $this->assertContains($this->conversationA2Closed->id, $ids);
        $this->assertNotContains($this->conversationB->id, $ids);
    }
}
