<?php

namespace Modules\AgentFolders\Providers;

use App\Conversation;
use Illuminate\Support\ServiceProvider;

/**
 * Adds a fixed-agent "Assignee" filter to the paid Custom Folders module
 * (ARMS-46).
 *
 * Custom Folders already has a "Show only own conversations" setting, but
 * it resolves to Auth::id() at view time - the same folder shows different
 * conversations depending on who is logged in, so there is no way to build
 * one folder that always shows a specific named agent's tickets, which is
 * what the client asked for (a per-agent folder an admin can browse into).
 *
 * Custom Folders' `folder.conversations_query` and `conversation.get_nearby_
 * query` Eventy filters both discard whatever query they're handed and
 * build a fresh one (mailbox + state + tag + status + own_only/unassigned),
 * so registering our own filter on the same hooks at a later priority lets
 * us receive their fully-built query and just layer our assignee condition
 * on top - no need to touch their service provider at all. Eventy filters
 * run in ascending priority order (overrides/tormjens/eventy/src/Event.php,
 * usort by priority) and Custom Folders registers at the default priority
 * (20), so 30 guarantees we run after it.
 *
 * Two small vendor-file patches are still unavoidable, since "which agent"
 * has to be captured and saved somewhere: the create/update form needs a
 * new field, and the controller needs to persist it into the folder's meta
 * blob. See Console/PatchCustomFoldersAssignee.php for that - deliberately
 * kept separate from the filtering logic here, so the only thing touching
 * Custom Folders' own files is the minimum needed to capture the selection.
 */
class AgentFoldersServiceProvider extends ServiceProvider
{
    const META_KEY = 'assignee_id';

    /**
     * Custom Folders' own Folder::type value for a custom folder
     * (Modules/CustomFolders/Providers/CustomFoldersServiceProvider::
     * TYPE_CUSTOM). Hardcoded rather than referencing that class directly:
     * it's a fixed, documented value ("max 255" per their own comment) and
     * this way the check works whether or not Custom Folders happens to be
     * autoloaded/active, and is straightforward to test without a stub.
     */
    const CUSTOM_FOLDER_TYPE = 200;

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    public function boot()
    {
        $this->hooks();
    }

    public function register()
    {
        $this->commands([
            \Modules\AgentFolders\Console\PatchCustomFoldersAssignee::class,
        ]);
    }

    public function hooks()
    {
        \Eventy::addFilter('folder.conversations_query', function ($query, $folder, $user_id) {
            $assignee_id = $this->assigneeIdFor($folder);
            if (!empty($assignee_id)) {
                $query->where('conversations.user_id', $assignee_id);
            }

            return $query;
        }, 30, 3);

        // Keeps the sidebar count badge in sync with the assignee filter.
        // Custom Folders' own setCounters() builds its own query directly
        // rather than through folder.conversations_query, so without this
        // the folder would list the right conversations but show the
        // mailbox-wide count next to it in the sidebar.
        \Eventy::addFilter('folder.update_counters', function ($updated, $folder) {
            $assignee_id = $this->assigneeIdFor($folder);
            if (empty($assignee_id)) {
                return $updated;
            }

            // Re-invokes the same filter chain that lists the folder's
            // conversations (already assignee-aware, since it's the same
            // hook this class also answers above) rather than re-deriving
            // Custom Folders' mailbox/tag/status query-building logic here.
            // Seeded with a real base query (mailbox + published) rather
            // than null: Custom Folders' own listener normally replaces
            // this seed with its own fresh query before ours runs, but this
            // filter must not assume that listener is present - a folder
            // could carry assignee_id while Custom Folders is deactivated.
            $base_query = Conversation::where('conversations.mailbox_id', $folder->mailbox_id)
                ->where('conversations.state', Conversation::STATE_PUBLISHED);
            $query = \Eventy::filter('folder.conversations_query', $base_query, $folder, $folder->user_id);

            $active_query = clone $query;
            $folder->active_count = $active_query->where('conversations.status', Conversation::STATUS_ACTIVE)->count();
            $folder->total_count = (clone $query)->count();
            $folder->save();

            return true;
        }, 30, 2);

        // Keeps "next/previous ticket" navigation inside an assignee-scoped
        // folder from jumping to a conversation assigned to someone else.
        \Eventy::addFilter('conversation.get_nearby_query', function ($query, $conversation, $mode, $folder) {
            $assignee_id = $this->assigneeIdFor($folder);
            if (!empty($assignee_id)) {
                $query->where('conversations.user_id', $assignee_id);
            }

            return $query;
        }, 30, 4);
    }

    /**
     * Returns the assignee user id for a Custom Folders folder, or null if
     * this isn't an assignee-scoped custom folder.
     */
    protected function assigneeIdFor($folder)
    {
        if (!$folder || $folder->type != self::CUSTOM_FOLDER_TYPE) {
            return null;
        }

        return $folder->meta[self::META_KEY] ?? null;
    }
}
