<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 15px; margin: 0 0 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #ddd; vertical-align: top; }
        th { background: #f2f4f6; font-size: 10px; }
        .muted { color: #888; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    @if ($capped)
        <p class="muted">{{ __('Showing the first :n rows. Narrow the filters to export fewer.', ['n' => $max_rows]) }}</p>
    @endif
    <table>
        <thead>
            <tr>
                @foreach ($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        <td>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($headers) }}" class="muted">{{ __('No ticket activity for the selected filters.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
