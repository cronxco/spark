@if($item->image())
    <div class="wep-spotlight-item-icon">
        <img src="{{ $item->image() }}" alt="">
    </div>
    @elseif($item->icon())
    <div class="wep-spotlight-item-icon">
        {!! $item->icon()->svg() !!}
    </div>
@endif

<div class="wep-spotlight-item-content">
    <div class="wep-spotlight-item-title">{{ $item->title() }}</div>
    @if($item->subtitle())
        <div class="wep-spotlight-item-subtitle">{!! $item->subtitle() !!}</div>
    @endif
</div>
<div class="wep-spotlight-item-instructions">
    @if($item->action())
    <div class="wep-spotlight-item-action">
        <span class="wep-spotlight-item-action-key">
            {{ __('wire-elements-pro::spotlight.enter') }}
        </span>
        <span>
            {{ __('wire-elements-pro::spotlight.to') }} <span class="wep-spotlight-item-action-description">{{ $item->action()->description() }}</span>
        </span>
    </div>
    @endif
    @if($item->tokens()->isNotEmpty())
    <div class="wep-spotlight-item-token">
        <span class="wep-spotlight-item-token-key">{{ __('wire-elements-pro::spotlight.tab') }}</span> <span>{{ __('wire-elements-pro::spotlight.to_search') }}</span>
    </div>
    @endif
</div>
