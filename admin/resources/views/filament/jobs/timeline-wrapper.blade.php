@php
    $record = $getRecord();
    $runs = \App\Filament\Resources\Jobs\Schemas\JobInfolist::runsOf($record);
@endphp

@if (empty($runs))
    @include('filament.jobs.timeline', [
        'runs'  => [],
        'run'   => null,
        'index' => 0,
        'email' => $record,
    ])
@else
    <div class="space-y-4">
        @foreach ($runs as $index => $run)
            @include('filament.jobs.timeline', [
                'email' => $record,
                'runs'  => $runs,
                'run'   => $run,
                'index' => $index,
            ])
        @endforeach
    </div>
@endif
