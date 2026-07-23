@extends('layouts.app')

@section('title', __('Audit'))

@section('content')
<div class="section-heading">{{ __('Ticket Activity') }}</div>

<div class="container">
    <p class="text-help">
        {{ __('Every ticket event across the mailboxes you can see — creation, replies, internal notes, status changes, assignments, merges, mailbox moves, customer changes, deletes and restores. Read-only.') }}
    </p>

    <form method="GET" action="{{ route('auditlog.index') }}" class="form-inline" style="margin: 15px 0;">
        <div class="form-group">
            <label>{{ __('Agent') }}&nbsp;</label>
            <select name="user_id" class="form-control input-sm">
                <option value="">{{ __('Any agent') }}</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}" @if ($filters->user_id == $user->id) selected @endif>{{ $user->getFullName() }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-left: 10px;">
            <label>{{ __('Action') }}&nbsp;</label>
            <select name="action_type" class="form-control input-sm">
                <option value="">{{ __('Any action') }}</option>
                @foreach ($action_types as $value => $label)
                    <option value="{{ $value }}" @if ($filters->action_type == $value) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-left: 10px;">
            <label>{{ __('Mailbox') }}&nbsp;</label>
            <select name="mailbox_id" class="form-control input-sm">
                <option value="">{{ __('All mailboxes') }}</option>
                @foreach ($mailboxes as $mailbox)
                    <option value="{{ $mailbox->id }}" @if ($filters->mailbox_id == $mailbox->id) selected @endif>{{ $mailbox->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="form-group" style="margin-left: 10px;">
            <label>{{ __('From') }}&nbsp;</label>
            <input type="date" name="from" class="form-control input-sm" value="{{ $filters->from->format('Y-m-d') }}" />
        </div>
        <div class="form-group" style="margin-left: 10px;">
            <label>{{ __('To') }}&nbsp;</label>
            <input type="date" name="to" class="form-control input-sm" value="{{ $filters->to->format('Y-m-d') }}" />
        </div>
        <div class="form-group" style="margin-left: 10px;">
            <label>{{ __('Ticket #') }}&nbsp;</label>
            <input type="text" name="ticket" class="form-control input-sm" style="width: 90px;" value="{{ $filters->ticket }}" placeholder="1042" />
        </div>
        <div class="form-group" style="margin-left: 10px;">
            <label>{{ __('Search') }}&nbsp;</label>
            <input type="text" name="q" class="form-control input-sm" value="{{ $filters->q }}" placeholder="{{ __('Action detail, subject…') }}" />
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-left: 10px;">{{ __('Filter') }}</button>
        <a href="{{ route('auditlog.index') }}" class="btn btn-default btn-sm">{{ __('Reset') }}</a>
        <button type="submit" formaction="{{ route('auditlog.export') }}" name="format" value="csv" class="btn btn-default btn-sm">{{ __('Export CSV') }}</button>
        <button type="submit" formaction="{{ route('auditlog.export') }}" name="format" value="pdf" class="btn btn-default btn-sm">{{ __('Export PDF') }}</button>
    </form>

    <div class="table-responsive">
        <table class="table table-striped">
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
                    <tr>
                        <td class="text-nowrap">{{ App\User::dateFormat($row->created_at, 'M j, H:i:s') }}</td>
                        <td class="text-nowrap">{{ \Modules\AuditLog\Services\AuditQuery::actorName($row) }}</td>
                        <td>{{ \Modules\AuditLog\Services\AuditQuery::actionLabel($row) }}</td>
                        <td class="text-nowrap">
                            @if ($row->conversation)
                                <a href="{{ route('conversations.view', ['id' => $row->conversation_id]) }}#thread-{{ $row->id }}" target="_blank">#{{ $row->conversation->number }}</a>
                            @endif
                        </td>
                        <td class="text-nowrap">{{ $row->conversation && $row->conversation->mailbox ? $row->conversation->mailbox->name : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-help">{{ __('No ticket activity for the selected filters.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="text-center">
        {{ $rows->links() }}
    </div>
</div>
@endsection
