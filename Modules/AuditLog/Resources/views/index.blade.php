@extends('layouts.app')

@section('title', __('Audit'))

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    <div class="sidebar-title">
        {{ __('Audit') }}
    </div>
    <ul class="sidebar-menu">
        <li @if (!$filters->action_type) class="active" @endif>
            <i class="glyphicon glyphicon-list-alt"></i> <a href="{{ route('auditlog.index') }}">{{ __('Ticket Activity') }}</a>
        </li>
        <li @if ($filters->action_type == App\Thread::ACTION_TYPE_MERGED) class="active" @endif>
            <i class="glyphicon glyphicon-resize-small"></i> <a href="{{ route('auditlog.index', ['action_type' => App\Thread::ACTION_TYPE_MERGED]) }}">{{ __('Merges') }}</a>
        </li>
        <li @if ($filters->action_type == App\Thread::ACTION_TYPE_USER_CHANGED) class="active" @endif>
            <i class="glyphicon glyphicon-user"></i> <a href="{{ route('auditlog.index', ['action_type' => App\Thread::ACTION_TYPE_USER_CHANGED]) }}">{{ __('Assignments') }}</a>
        </li>
        <li @if ($filters->action_type == App\Thread::ACTION_TYPE_STATUS_CHANGED) class="active" @endif>
            <i class="glyphicon glyphicon-flag"></i> <a href="{{ route('auditlog.index', ['action_type' => App\Thread::ACTION_TYPE_STATUS_CHANGED]) }}">{{ __('Status changes') }}</a>
        </li>
    </ul>
@endsection

@section('stylesheets')
    @parent
    <style>
        .al-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 10px 14px;
            background: #f7f9fb; border: 1px solid #e6eaef; border-radius: 6px; padding: 14px; margin-bottom: 18px; }
        .al-field { display: flex; flex-direction: column; gap: 3px; }
        .al-field > label { margin: 0; font-size: 10px; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; color: #97a1ab; }
        .al-field .form-control { height: 32px; }
        .al-field.al-q { flex: 1; min-width: 170px; }
        .al-field.al-q .form-control { width: 100%; }
        .al-field.al-tkt .form-control { width: 92px; }
        .al-actions { display: flex; gap: 8px; margin-left: auto; align-items: flex-end; }

        .al-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .al-table thead th { text-align: left; font-size: 11px; font-weight: 700; letter-spacing: .04em;
            text-transform: uppercase; color: #97a1ab; padding: 8px 12px; border-bottom: 2px solid #e6eaef; white-space: nowrap; }
        .al-table tbody td { padding: 10px 12px; border-bottom: 1px solid #eef1f4; vertical-align: middle; }
        .al-table tbody tr:hover { background: #f4f9ff; }
        .al-date { color: #8a95a1; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .al-actor { display: flex; align-items: center; gap: 8px; white-space: nowrap; }
        .al-avatar { flex: none; width: 26px; height: 26px; border-radius: 50%; background: #e6eaef; color: #5b6672;
            display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; }
        .al-action { color: #2b3540; }
        .al-pill { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 1px 8px; border-radius: 999px; }
        .al-ticket a { font-family: "SFMono-Regular", Menlo, Consolas, monospace; font-size: 12.5px; white-space: nowrap; }
        .al-mbx { color: #8a95a1; white-space: nowrap; }
        .al-empty { color: #8a95a1; padding: 24px 12px; text-align: center; }
        .al-pager { text-align: center; margin-top: 14px; }
        @media (max-width: 700px) { .al-actions { margin-left: 0; } .al-mbx, .al-date { white-space: normal; } }
    </style>
@endsection

@section('content')
<div class="section-heading margin-bottom">
    {{ __('Ticket Activity') }}
    <div class="small text-help pull-right">{{ App\User::dateFormat(new Illuminate\Support\Carbon()) }}</div>
</div>

<div class="container">
    <p class="text-help">
        {{ __('Every ticket event across the mailboxes you can see: creation, replies, internal notes, status changes, assignments, merges, mailbox moves, customer changes, deletes and restores. Read-only.') }}
    </p>

    <form method="GET" action="{{ route('auditlog.index') }}" class="al-filters">
        <div class="al-field">
            <label>{{ __('Agent') }}</label>
            <select name="user_id" class="form-control input-sm">
                <option value="">{{ __('Any agent') }}</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @if ($filters->user_id == $user->id) selected @endif>{{ $user->getFullName() }}</option>
                @endforeach
            </select>
        </div>
        <div class="al-field">
            <label>{{ __('Action') }}</label>
            <select name="action_type" class="form-control input-sm">
                <option value="">{{ __('Any action') }}</option>
                @foreach ($action_types as $value => $label)
                    <option value="{{ $value }}" @if ($filters->action_type == $value) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="al-field">
            <label>{{ __('Mailbox') }}</label>
            <select name="mailbox_id" class="form-control input-sm">
                <option value="">{{ __('All mailboxes') }}</option>
                @foreach ($mailboxes as $mailbox)
                    <option value="{{ $mailbox->id }}" @if ($filters->mailbox_id == $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="al-field">
            <label>{{ __('From') }}</label>
            <input type="date" name="from" class="form-control input-sm" value="{{ $filters->from->format('Y-m-d') }}" />
        </div>
        <div class="al-field">
            <label>{{ __('To') }}</label>
            <input type="date" name="to" class="form-control input-sm" value="{{ $filters->to->format('Y-m-d') }}" />
        </div>
        <div class="al-field al-tkt">
            <label>{{ __('Ticket #') }}</label>
            <input type="text" name="ticket" class="form-control input-sm" value="{{ $filters->ticket }}" placeholder="1042" />
        </div>
        <div class="al-field al-q">
            <label>{{ __('Search') }}</label>
            <input type="text" name="q" class="form-control input-sm" value="{{ $filters->q }}" placeholder="{{ __('Action detail, subject…') }}" />
        </div>
        <div class="al-actions">
            <button type="submit" class="btn btn-primary btn-sm">{{ __('Filter') }}</button>
            <a href="{{ route('auditlog.index') }}" class="btn btn-default btn-sm">{{ __('Reset') }}</a>
            <button type="submit" formaction="{{ route('auditlog.export') }}" name="format" value="csv" class="btn btn-default btn-sm">{{ __('Export CSV') }}</button>
            <button type="submit" formaction="{{ route('auditlog.export') }}" name="format" value="pdf" class="btn btn-default btn-sm">{{ __('Export PDF') }}</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="al-table">
            <thead>
                <tr>
                    <th>{{ __('Date') }}</th>
                    <th>{{ __('Agent') }}</th>
                    <th>{{ __('Action') }}</th>
                    <th>{{ __('Ticket') }}</th>
                    <th>{{ __('Mailbox') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php
                        $actor = \Modules\AuditLog\Services\AuditQuery::actorName($row);
                    @endphp
                    <tr>
                        <td class="al-date">{{ App\User::dateFormat($row->created_at, 'M j, H:i:s') }}</td>
                        <td>
                            <span class="al-actor">
                                <span class="al-avatar">{{ \Modules\AuditLog\Services\AuditQuery::initials($actor) }}</span>
                                <span>{{ $actor }}</span>
                            </span>
                        </td>
                        <td class="al-action">{!! \Modules\AuditLog\Services\AuditQuery::actionHtml($row) !!}</td>
                        <td class="al-ticket">
                            @if ($row->conversation)
                                <a href="{{ route('conversations.view', ['id' => $row->conversation_id]) }}#thread-{{ $row->id }}" target="_blank">#{{ $row->conversation->number }}</a>
                            @endif
                        </td>
                        <td class="al-mbx">{{ $row->conversation && $row->conversation->mailbox ? $row->conversation->mailbox->name : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="al-empty">{{ __('No ticket activity for the selected filters.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="al-pager">
        {{ $rows->links() }}
    </div>
</div>
@endsection
