<div
    data-async-column-token="{{ $token }}"
    @if ($whenVisible) data-async-column-when-visible="1" @endif
    @if ($retryable) data-async-column-retryable="1" @endif
    x-init="$store.asyncColumn.register($el)"
    @if ($retryable) x-on:click="$el.dataset.asyncColumnError === '1' && $store.asyncColumn.retry($el)" @endif
>{{ $loadingState instanceof \Illuminate\Contracts\Support\Htmlable ? new \Illuminate\Support\HtmlString($loadingState->toHtml()) : $loadingState }}</div>
