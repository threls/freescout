<?php

namespace Modules\AuditLog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Http\Request;
use Modules\AuditLog\Services\AuditExporter;
use Modules\AuditLog\Services\AuditFilters;
use Modules\AuditLog\Services\AuditQuery;

class AuditLogController extends Controller
{
    const PER_PAGE = 20;

    /**
     * Cross-ticket audit log listing (ARMS-25).
     */
    public function index(Request $request)
    {
        $user = auth()->user();
        $filters = AuditFilters::fromRequest($request);

        $rows = (new AuditQuery($filters, $user))->builder()
            ->paginate(self::PER_PAGE)
            ->appends($request->query()); // keep filters across pagination

        return view('auditlog::index', [
            'rows'           => $rows,
            'filters'        => $filters,
            'action_types'   => AuditQuery::actionTypeOptions(),
            'mailboxes'      => $user->mailboxesCanView()->sortBy('name'),
            'users'          => User::nonDeleted()->orderBy('first_name')->get(),
        ]);
    }

    /**
     * Export the same filtered, visibility-scoped result set. Defaults to CSV;
     * pass format=pdf for PDF.
     */
    public function export(Request $request)
    {
        $user = auth()->user();
        $filters = AuditFilters::fromRequest($request);

        $builder = (new AuditQuery($filters, $user))->builder();

        $filename = 'audit-'.$filters->from->format('Ymd').'-'.$filters->to->format('Ymd');

        if ($request->input('format') === 'pdf') {
            return AuditExporter::pdf($builder, __('Ticket Activity'), $filename);
        }

        return AuditExporter::csv($builder, $filename);
    }
}
