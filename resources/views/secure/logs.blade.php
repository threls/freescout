@extends('layouts.app')

@section('title', __('Logs'))

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    <div class="sidebar-title">
        {{ __('Logs') }}
    </div>
    <ul class="sidebar-menu">
        @foreach ($names as $name)
            <li @if ($current_name == $name)class="active"@endif><i class="glyphicon glyphicon-list-alt"></i> <a href="{{ route('logs', ['name'=>$name]) }}">{{ App\ActivityLog::getLogTitle($name) }}</a></li>
        @endforeach
    </ul>
@endsection

@section('content')
<form method="post">
    {{ csrf_field() }}
    <div class="section-heading margin-bottom">
        {{ __('Log Records') }} @if ($current_name != App\ActivityLog::NAME_OUT_EMAILS)&nbsp;&nbsp;<button type="submit" name="action" value="clean" class="btn btn-default btn-xs" data-toggle="tooltip">{{ __('Clear Log') }}</button>@endif

        <div class="small text-help pull-right">{{ App\User::dateFormat(new Illuminate\Support\Carbon()) }}</div>
    </div>
</form>

<div class="container">

    {{-- ARMS-25: server-side filters over the whole log. --}}
    <form method="GET" action="{{ route('logs', ['name' => $current_name]) }}" class="form-inline margin-bottom">
        @if ($current_name != App\ActivityLog::NAME_OUT_EMAILS)
            <div class="form-group">
                <label>{{ __('User') }}&nbsp;</label>
                <select name="f_user" class="form-control input-sm">
                    <option value="">{{ __('Any user') }}</option>
                    @foreach ($log_users as $log_user)
                        <option value="{{ $log_user->id }}" @if ($filters['f_user'] == $log_user->id) selected @endif>{{ $log_user->getFullName() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group" style="margin-left: 10px;">
                <label>{{ __('Event') }}&nbsp;</label>
                <select name="f_event" class="form-control input-sm">
                    <option value="">{{ __('Any event') }}</option>
                    @foreach ($event_options as $value => $label)
                        <option value="{{ $value }}" @if ($filters['f_event'] === $value) selected @endif>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="form-group" style="margin-left: 10px;">
            <label>{{ __('From') }}&nbsp;</label>
            <input type="date" name="f_from" class="form-control input-sm" value="{{ $filters['f_from'] }}" />
        </div>
        <div class="form-group" style="margin-left: 10px;">
            <label>{{ __('To') }}&nbsp;</label>
            <input type="date" name="f_to" class="form-control input-sm" value="{{ $filters['f_to'] }}" />
        </div>
        <div class="form-group" style="margin-left: 10px;">
            <label>{{ __('Search') }}&nbsp;</label>
            <input type="text" name="f_q" class="form-control input-sm" value="{{ $filters['f_q'] }}" placeholder="@if ($current_name != App\ActivityLog::NAME_OUT_EMAILS){{ __('Event, IP…') }}@else{{ __('Email…') }}@endif" />
        </div>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-left: 10px;">{{ __('Filter') }}</button>
        <a href="{{ route('logs', ['name' => $current_name]) }}" class="btn btn-default btn-sm">{{ __('Reset') }}</a>
    </form>

    @if (count($logs))
        <table id="table-logs" class="stripe hover order-column row-border" style="width:100%">
            <thead>
                <tr>
                    @foreach ($cols as $col)
                        <th>{{ App\ActivityLog::formatColTitle($col) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($logs as $row)
                    <tr>
                        @foreach ($cols as $col)
                            <td class="break-words">
                                @if (isset($row[$col]))
                                    @if ($col == 'user' || $col == 'customer')
                                        <a href="{{ $row[$col]->url() }}">{{ $row[$col]->getFullName(true) }}</a>
                                    @elseif ($col == 'date')
                                        {{  App\User::dateFormat(new Illuminate\Support\Carbon($row[$col]), 'M j, H:i:s') }}
                                    @elseif (is_object($row[$col]) && get_class($row[$col]) == 'App\Thread')
                                        <a href="{{ route('conversations.view', ['id' => $row[$col]->conversation_id]) }}#thread-{{ $row[$col]->id }}" target="_blank">#{{ $row[$col]->conversation->number }}</a>
                                    @else
                                        {{ $row[$col] }}
                                    @endif
                                @else
                                    &nbsp;
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{ $activities->links() }}

    @else
        @include('partials/empty', ['empty_text' => __('Log is empty')])
    @endif
</div>
@endsection

@section('stylesheets')
    <link href="{{ asset('js/datatables/datatables.min.css') }}" rel="stylesheet">
@endsection

@section('javascripts')
    <script src="{{ asset('js/datatables/datatables.min.js') }}" {!! \Helper::cspNonceAttr() !!}></script>
@endsection

@section('javascript')
    @parent
    logsInit();
@endsection