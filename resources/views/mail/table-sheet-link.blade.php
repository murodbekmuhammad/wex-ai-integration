<x-mail::message>
# {{ $table->title }}

{{ $note }}

<x-mail::button :url="$table->google_sheet_url">
Open in Google Sheets
</x-mail::button>

@if ($table->warnings)
@foreach ($table->warnings as $warning)
- {{ $warning }}
@endforeach
@endif

{{ config('app.name') }}
</x-mail::message>
