<div class="infobox" id="site-description">
    <div class="infobox-title">@lang('index.title.welcome', [ 'site_name' => site_setting('siteName'), ])</div>
    <div class="infobox-info">{!! site_setting('siteDescription') ?: trans('index.info.welcome') !!}</div>
</div>
