{{-- A printable copy of a table Claude built. DejaVu Sans covers Latin and Cyrillic text. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $table->title }}</title>
    <style>
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #111827; }
        h1 { font-size: 15px; margin: 0 0 4px; }
        .meta { color: #6b7280; margin: 0 0 10px; }
        .summary { margin: 0 0 10px; white-space: pre-line; }
        .warning { background: #fef3c7; color: #92400e; padding: 4px 6px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: left; vertical-align: top; }
        th { background: #f3f4f6; }
        td.number { text-align: right; white-space: nowrap; }
    </style>
</head>
<body>
    <h1>{{ $table->title }}</h1>
    <p class="meta">
        Request: {{ $table->request }}<br>
        Built from: {{ $sources }} · {{ $table->created_at?->toDayDateTimeString() }} UTC
    </p>

    @if ($table->summary)
        <p class="summary">{{ $table->summary }}</p>
    @endif

    @foreach ($table->warnings as $warning)
        <p class="warning">{{ $warning }}</p>
    @endforeach

    <table>
        <thead>
            <tr>
                @foreach ($table->columns as $column)
                    <th>{{ $column }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($table->rows as $row)
                <tr>
                    @foreach ($row as $cell)
                        @if (is_int($cell) || is_float($cell))
                            <td class="number">{{ rtrim(rtrim(number_format($cell, 2, '.', ' '), '0'), '.') }}</td>
                        @else
                            <td>{{ $cell }}</td>
                        @endif
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
