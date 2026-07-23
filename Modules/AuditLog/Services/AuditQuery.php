<?php

namespace Modules\AuditLog\Services;

use App\Conversation;
use App\Thread;
use App\User;

/**
 * Builds the cross-ticket audit query over the `threads` timeline, applying
 * the filter bar and — crucially — the viewer's mailbox visibility. Nothing
 * here writes; the audit trail stays exactly as FreeScout records it.
 *
 * "Events" spans the full record the ARMS-1 epic names — creation, status
 * changes, assignments, and comments — i.e. the ticket-action line-items
 * (Thread::TYPE_LINEITEM) plus the published message/reply/note threads.
 * Draft threads are never events and are excluded.
 */
class AuditQuery
{
    /** @var AuditFilters */
    protected $filters;

    /** @var User the viewer, whose mailbox visibility scopes the results */
    protected $user;

    // Pseudo action-type codes for the non-line-item events, kept well clear
    // of the real Thread::ACTION_TYPE_* range (1–11) so they never collide.
    const EVENT_CREATED = 100; // first thread in a conversation
    const EVENT_REPLY   = 101; // a reply (agent or customer), not the first
    const EVENT_NOTE    = 102; // an internal note

    /** Thread types that count as audit events (drafts excluded separately). */
    protected static function eventMessageTypes()
    {
        return [Thread::TYPE_MESSAGE, Thread::TYPE_CUSTOMER, Thread::TYPE_NOTE];
    }

    /**
     * The action types offered in the filter dropdown, in display order.
     * Import types are intentionally omitted (not day-to-day agent actions);
     * they still appear in results under "Any action".
     */
    public static function actionTypeOptions()
    {
        return [
            Thread::ACTION_TYPE_STATUS_CHANGED     => __('Status changed'),
            Thread::ACTION_TYPE_USER_CHANGED       => __('Assigned'),
            Thread::ACTION_TYPE_MOVED_FROM_MAILBOX => __('Moved mailbox'),
            Thread::ACTION_TYPE_MERGED             => __('Merged'),
            Thread::ACTION_TYPE_CUSTOMER_CHANGED   => __('Customer changed'),
            Thread::ACTION_TYPE_DELETED_TICKET     => __('Deleted'),
            Thread::ACTION_TYPE_RESTORE_TICKET     => __('Restored'),
            self::EVENT_CREATED                    => __('Created'),
            self::EVENT_REPLY                      => __('Reply'),
            self::EVENT_NOTE                        => __('Internal Note'),
        ];
    }

    public function __construct(AuditFilters $filters, User $user)
    {
        $this->filters = $filters;
        $this->user = $user;
    }

    /**
     * The Eloquent builder for the filtered, visibility-scoped, ordered feed.
     */
    public function builder()
    {
        $f = $this->filters;

        // An event is a line-item (any state — line-items aren't drafts) or a
        // published message/reply/note. Drafts are excluded.
        $messageTypes = self::eventMessageTypes();
        $query = Thread::where(function ($w) use ($messageTypes) {
            $w->where('threads.type', Thread::TYPE_LINEITEM)
                ->orWhere(function ($m) use ($messageTypes) {
                    $m->whereIn('threads.type', $messageTypes)
                        ->where('threads.state', Thread::STATE_PUBLISHED);
                });
        })
            ->whereBetween('threads.created_at', [$f->from, $f->to])
            ->with(['created_by_user_cached', 'created_by_customer', 'conversation.mailbox'])
            ->orderBy('threads.created_at', 'desc')
            ->orderBy('threads.id', 'desc');

        $this->applyActionTypeFilter($query, $f->action_type);

        if ($f->user_id) {
            $query->where('threads.created_by_user_id', $f->user_id);
        }

        // Mailbox / ticket filters live on the conversation, as does the
        // visibility scope. Fold them into a single whereHas so a
        // line-item whose conversation was hard-deleted never leaks.
        $mailbox_id = $f->mailbox_id;
        $ticket = $f->ticket;
        $restrict_ids = $this->visibleMailboxIds();

        $query->whereHas('conversation', function ($c) use ($mailbox_id, $ticket, $restrict_ids) {
            if ($mailbox_id) {
                $c->where('mailbox_id', $mailbox_id);
            }
            if ($ticket) {
                // Ticket numbers displayed to users are $conversation->number,
                // which is an accessor: it returns the raw `number` column
                // only when app.custom_number is enabled, and `id` otherwise
                // (the default). numberFieldName() is core's own helper for
                // matching against whichever one is actually in effect —
                // hardcoding 'number' here would silently stop matching real
                // ticket numbers whenever custom numbering is off.
                $c->where(Conversation::numberFieldName(), $ticket);
            }
            if ($restrict_ids !== null) {
                $c->whereIn('mailbox_id', $restrict_ids);
            }
        });

        // Free-text: match the action detail stored on the line-item, the
        // message body, or the conversation subject. Always rides alongside
        // the date/type narrowing above, so the un-indexed LIKE stays cheap.
        if ($f->q !== '') {
            $like = '%'.self::escapeLike($f->q).'%';
            $query->where(function ($w) use ($like) {
                $w->where('threads.action_data', 'like', $like)
                    ->orWhere('threads.body', 'like', $like)
                    ->orWhereHas('conversation', function ($c) use ($like) {
                        $c->where('subject', 'like', $like);
                    });
            });
        }

        return $query;
    }

    /**
     * Narrow to one event type — a real Thread::ACTION_TYPE_* (line-items) or
     * one of the pseudo message/reply/note codes. No filter = all events.
     */
    protected function applyActionTypeFilter($query, $action_type)
    {
        if (!$action_type) {
            return;
        }

        switch ($action_type) {
            case self::EVENT_CREATED:
                $query->where('threads.first', true);
                break;
            case self::EVENT_REPLY:
                $query->whereIn('threads.type', [Thread::TYPE_MESSAGE, Thread::TYPE_CUSTOMER])
                    ->where('threads.first', false);
                break;
            case self::EVENT_NOTE:
                $query->where('threads.type', Thread::TYPE_NOTE);
                break;
            default:
                // A real line-item action type.
                $query->where('threads.type', Thread::TYPE_LINEITEM)
                    ->where('threads.action_type', $action_type);
                break;
        }
    }

    /**
     * Mailbox ids the results must be restricted to, or null for "no
     * restriction" (admins see every mailbox).
     */
    protected function visibleMailboxIds()
    {
        if ($this->user->isAdmin()) {
            return null;
        }

        return $this->user->mailboxesIdsCanView();
    }

    /**
     * Who performed the event: the agent for user-created rows, the customer
     * for inbound replies, or a dash for system/automatic actions.
     */
    public static function actorName(Thread $thread)
    {
        if ($thread->created_by_user_id && $thread->created_by_user_cached) {
            return $thread->created_by_user_cached->getFullName();
        }
        if ($thread->created_by_customer_id && $thread->created_by_customer) {
            return $thread->created_by_customer->getFullName(true);
        }

        return '—';
    }

    /**
     * Plain-text label for an event, reusing FreeScout's own renderer so the
     * wording (incl. custom statuses like On-Hold) matches what agents see
     * inside the ticket. The performer is shown in its own column, so the
     * ":person" prefix is stripped here.
     */
    public static function actionLabel(Thread $thread)
    {
        $number = $thread->conversation ? $thread->conversation->number : '';
        $text = $thread->getActionText($number, false, true);
        $text = str_replace(':person', '', $text);

        // Drop the row's own "conversation #N" self-reference — the Ticket
        // column already shows it — while keeping references to *other*
        // conversations (e.g. a merge target has a different number).
        if ($number !== '' && $number !== null) {
            $n = preg_quote($number, '/');
            $text = preg_replace('/\s*(to|into|in|for|a new)?\s*conversation #'.$n.'\b/i', '', $text);
            $text = preg_replace('/\s*#'.$n.'\b/', '', $text);
        }

        return trim(preg_replace('/\s{2,}/', ' ', $text));
    }

    /**
     * A colour for the event's left indicator — the ticket's new status colour
     * for status changes, otherwise a fixed hue per event kind. Purely visual.
     */
    public static function eventColor(Thread $thread)
    {
        if ($thread->type == Thread::TYPE_LINEITEM) {
            switch ($thread->action_type) {
                case Thread::ACTION_TYPE_STATUS_CHANGED:   return self::statusColor($thread);
                case Thread::ACTION_TYPE_USER_CHANGED:     return '#4a90d9'; // assign
                case Thread::ACTION_TYPE_MERGED:           return '#9b6dc6';
                case Thread::ACTION_TYPE_MOVED_FROM_MAILBOX: return '#3aa5a0';
                case Thread::ACTION_TYPE_CUSTOMER_CHANGED: return '#d99a2b';
                case Thread::ACTION_TYPE_DELETED_TICKET:   return '#de6864';
                case Thread::ACTION_TYPE_RESTORE_TICKET:   return '#6ac27b';
                default:                                   return '#8b98a6';
            }
        }
        if ($thread->type == Thread::TYPE_NOTE) {
            return '#d99a2b'; // internal note
        }
        if ($thread->first) {
            return '#4a90d9'; // creation
        }

        return '#8b98a6'; // reply
    }

    /**
     * The new status's colour for a status-change line-item, falling back to
     * amber for custom statuses (e.g. On-Hold) not in the core map.
     */
    public static function statusColor(Thread $thread)
    {
        $colors = Conversation::$status_colors;

        return isset($colors[$thread->status]) ? $colors[$thread->status] : '#d99a2b';
    }

    /**
     * Up-to-two-letter initials for the actor avatar.
     */
    public static function initials($name)
    {
        $name = trim((string) $name);
        if ($name === '' || $name === '—') {
            return '·';
        }
        $parts = preg_split('/\s+/', $name);
        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Escape LIKE wildcards in a user-supplied term so a literal % or _ in a
     * search can't turn into an unintended wildcard.
     */
    public static function escapeLike($term)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
