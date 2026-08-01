<span
    data-async-column-token="{{ $token }}"
    @if ($whenVisible) data-async-column-when-visible="1" @endif
    x-init="$store.asyncColumn.register($el)"
>{{ $loadingState instanceof \Illuminate\Contracts\Support\Htmlable ? new \Illuminate\Support\HtmlString($loadingState->toHtml()) : $loadingState }}</span>
