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
            ->with(['created_by_user_cached', 'created_by_customer', 'customer_cached', 'user', 'conversation.mailbox'])
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

    /** Neutral fallback used whenever a status has no usable registered colour. */
    const DEFAULT_STATUS_COLOR = '#d99a2b';

    /**
     * The new status's colour for a status-change line-item, sourced from
     * Conversation::$status_colors — the same live map core and other
     * modules register into (e.g. OnHoldStatus adds its own amber entry),
     * so a pill always matches whatever colour that status actually renders
     * with elsewhere, including any custom status added later. Falls back to
     * a neutral amber if a status isn't registered, or if it's registered
     * with something other than a plain 6-digit hex value — statusPillHtml()
     * appends an alpha channel directly onto this string (e.g. '#f39c12' ->
     * '#f39c1222'), which only produces valid CSS for that exact shape; a
     * future status registered as a CSS keyword or rgb() string would
     * otherwise silently lose both its text and background colour.
     */
    public static function statusColor(Thread $thread)
    {
        $colors = Conversation::$status_colors;
        $color = $colors[$thread->status] ?? null;

        return self::isHexColor($color) ? $color : self::DEFAULT_STATUS_COLOR;
    }

    protected static function isHexColor($color)
    {
        return is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color);
    }

    /**
     * Safe HTML for the Action cell: a coloured pill for the new status on a
     * status change, and a bold name for an assignment or customer change —
     * matching the visual language agreed with ARMS — falling back to the
     * plain, native-wording label (via actionLabel()) for every other event.
     * Every dynamic piece is escaped; the surrounding markup is static.
     */
    public static function actionHtml(Thread $thread)
    {
        if ($thread->type == Thread::TYPE_LINEITEM) {
            switch ($thread->action_type) {
                case Thread::ACTION_TYPE_STATUS_CHANGED:
                    return e(__('marked as')).' '.self::statusPillHtml($thread);
                case Thread::ACTION_TYPE_USER_CHANGED:
                    return e(__('assigned')).' <strong>'.e($thread->getAssigneeName(false)).'</strong>';
                case Thread::ACTION_TYPE_CUSTOMER_CHANGED:
                    $customer_name = $thread->customer_cached ? $thread->customer_cached->getFullName(true) : '';

                    return e(__('changed the customer to')).' <strong>'.e($customer_name).'</strong>';
            }
        }

        return e(self::actionLabel($thread));
    }

    /**
     * A soft pill for a status name: solid-colour text on a light tint of
     * that same colour, so it reads as "coloured" without core's harsher
     * solid-background/white-text tag style.
     */
    protected static function statusPillHtml(Thread $thread)
    {
        // statusColor() already guarantees a 6-digit hex string, but every
        // other dynamic value in this class is escaped before reaching HTML
        // — escape this one too rather than leaving it the one exception.
        $color = e(self::statusColor($thread));
        $name = e($thread->getStatusName());

        return '<span class="al-pill" style="color: '.$color.'; background: '.$color.'22;">'.$name.'</span>';
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
