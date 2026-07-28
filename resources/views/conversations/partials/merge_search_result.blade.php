<table class="table table-striped">
    @foreach ($conversations as $conversation)
        <tr>
            <td>
                <div class="checkbox">
                    <input type="checkbox" class="conv-merge-id" value="{{ $conversation->id }}" />
                    <a href="{{ $conversation->url() }}" target="_blank" data-toggle="tooltip" title="{{ __('Click to view') }}"><strong>#{{ $conversation->number }}</strong> {{ $conversation->getSubject() }}</a>
                    <div class="text-help">{{ $conversation->customer_email }}</div>
                </div>
            </td>
        </tr>
    @endforeach
</table>
@if ($has_more ?? false)
    <div class="text-help">{{ __('Showing the first :count results — narrow your search for more.', ['count' => \App\Http\Controllers\ConversationsController::MERGE_SEARCH_LIMIT]) }}</div>
@endif
