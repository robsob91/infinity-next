@extends('layouts.main.panel')

@section('title', trans("panel.title.board_role_list", [
    'board_uri' => $board->board_uri,
]))

@section('actions')
    <a class="panel-action" href="{{ $board->getPanelUrl('roles.create') }}">+ @lang('panel.action.add_role')</a>
@endsection

@section('body')
    <div class="filterlist">
        <h4 class="filterlist-heading">@lang('panel.list.head.roles')</h4>

        <ol class="filterlist-list">
            @foreach ($roles as $role)
            <li class="filterlist-item">
                <a class="filterlist-secondary" href="{{ $role->getPanelUrl('delete') }}">
                    <i class="fas fa-trash"></i>
                </a>
                <a class="filterlist-secondary" href="{{ $role->getPanelUrl('permissions') }}">
                    @lang('panel.list.field.permissions')
                </a>
                <a class="filterlist-primary" href="{{ $role->getPanelUrl() }}">
                    <em>{{ $role->getDisplayName() }}</em>
                    <dfn>{{ $role->getDisplayWeight() }}</dfn>
                </a>
            </li>
            @endforeach
        </ol>
    </div>
@endsection
