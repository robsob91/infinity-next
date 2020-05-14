<div class="actions-anchor actions-post" data-no-instant>
    <span class="actions-label"><i class="fas fa-angle-down"></i></span>

    {{-- Board specific content management actions --}}
    <div class="actions">
        <a class="action action-report " href="{!! $post->getModUrl('report') !!}">
            @lang('board.action.report')
        </a>

        <a class="action action-report-global " href="{!! $post->getModUrl('report.global') !!}">
            @lang('board.action.report_global')
        </a>

        <a class="action action-self-delete" href="{!! $post->getModUrl('delete') !!}">
            @lang('board.action.delete')
        </a>

        <a class="action action-history " href="{!! $post->getModUrl('history') !!}">
            @lang('board.action.history', [ 'board_uri' => $details['board_uri'], ])
        </a>

        <a class="action action-history-global " href="{!! $post->getModUrl('history.global') !!}">
            @lang('board.action.history_global')
        </a>

        <a class="action action-edit " href="{!! $post->getModUrl('edit') !!}">
            @lang('board.action.edit')
        </a>

        @if ($post->isOp())
        <a class="action action-sticky" href="{!! $post->getModUrl('sticky') !!}">
            @lang('board.action.sticky')
        </a>
        <a class="action action-unsticky " href="{!! $post->getModUrl('unsticky') !!}">
            @lang('board.action.unsticky')
        </a>

        <a class="action action-lock " href="{!! $post->getModUrl('lock') !!}">
            @lang('board.action.lock')
        </a>
        <a class="action action-unlock " href="{!! $post->getModUrl('unlock') !!}">
            @lang('board.action.unlock')
        </a>

        <a class="action action-bumplock " href="{!! $post->getModUrl('bumplock') !!}">
            @lang('board.action.bumplock')
        </a>
        <a class="action action-unbumplock " href="{!! $post->getModUrl('unbumplock') !!}">
            @lang('board.action.unbumplock')
        </a>

        <a class="action action-global-bumplock " href="{!! $post->getModUrl('suppress') !!}">
            @lang('board.action.suppress')
        </a>
        <a class="action action-global-unbumplock " href="{!! $post->getModUrl('unsuppress') !!}">
            @lang('board.action.unsuppress')
        </a>
        @endif

        <a class="action action-moderate" href="{!! $post->getModUrl('mod') !!}">
            @lang('board.action.moderate')
        </a>

        <a class="action action-feature-global" href="{!! $post->getModUrl('feature') !!}">
            @lang('board.action.feature')
        </a>
    </div>
</div>
