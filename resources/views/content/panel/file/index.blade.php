@extends('layouts.main.panel')

@section('body')
<div class="attachments checkered-background">
    @foreach ($files as $file)
    @if ($file->hasFile())
    <a class="attachment" href="{{ route('panel.site.files.show', $file->hash) }}" style="height: 100px; width: 100px;">
        {!! $file->toHtml(100) !!}
    </a>
    @endif
    @endforeach
</div>
@endsection
