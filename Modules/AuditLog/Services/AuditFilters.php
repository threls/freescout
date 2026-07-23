<?php

namespace Modules\AuditLog\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Parsed state of the audit-log filter bar, shared by the listing and the
 * CSV export so the two always agree. Parsing is defensive: bad input falls
 * back to a default rather than 500-ing.
 */
class AuditFilters
{
    /** @var Carbon */
    public $from;

    /** @var Carbon */
    public $to;

    /** @var int|null Thread::ACTION_TYPE_* */
    public $action_type;

    /** @var int|null threads.created_by_user_id (the agent who acted) */
    public $user_id;

    /** @var int|null conversations.mailbox_id */
    public $mailbox_id;

    /** @var int|null conversations.number */
    public $ticket;

    /** @var string free-text search term */
    public $q = '';

    public static function fromRequest(Request $request)
    {
        $filters = new self();

        // Default window: last 30 days. Unparseable input → default, not a 500.
        $filters->from = self::parseDate($request->input('from'), Carbon::now()->subDays(29))->startOfDay();
        $filters->to = self::parseDate($request->input('to'), Carbon::now())->endOfDay();

        // Reversed range → swap, so from/to are never mixed up.
        if ($filters->to->lt($filters->from)) {
            [$filters->from, $filters->to] = [$filters->to->copy()->startOfDay(), $filters->from->copy()->endOfDay()];
        }

        $filters->action_type = $request->filled('action_type') ? (int) $request->input('action_type') : null;
        $filters->user_id = $request->filled('user_id') ? (int) $request->input('user_id') : null;
        $filters->mailbox_id = $request->filled('mailbox_id') ? (int) $request->input('mailbox_id') : null;
        // Ticket number: strip a leading '#' an agent might paste in.
        if ($request->filled('ticket')) {
            $ticket = (int) ltrim(trim($request->input('ticket')), '#');
            $filters->ticket = $ticket ?: null;
        }
        $filters->q = trim((string) $request->input('q', ''));

        return $filters;
    }

    protected static function parseDate($value, Carbon $fallback)
    {
        if (!$value) {
            return $fallback;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }
}
