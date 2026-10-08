@php
    $record = $getRecord();
@endphp

@include('filament.jobs.attachments-gallery', [
    'email'       => $record,
    'attachments' => $record->attachments()->get(),
])
