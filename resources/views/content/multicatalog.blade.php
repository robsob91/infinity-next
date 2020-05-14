@extends('layouts.main')

@section('content')
<main class="multiboard-index index-catalog catalog-flyout">
    <div class="overwatch js-only" data-widget="overwatch">
        <div class="overwatch-label label-reading"><tt>Waiting for you to finish reading ...</tt></div>
    </div>

    <section class="index-threads static">
        <ul class="threads" id="CatalogMix">
            @foreach ($threads as $thread)
            <li class="thread catalog-thread mix board-{{ $thread->board_uri }}" data-id="{{ $thread->post_id }}" data-bumped="{{ $thread->bumped_last->timestamp }}">
                @include('content.board.catalog', [
                    'board'      => $board,
                    'post'       => $thread,
                    'multiboard' => true,
                    'preview'    => true,
                ])
            </li>
            @endforeach
        </ul>
    </section>

    @include('content.board.sidebar')
</main>
@stop
