<div
    data-async-column-token="{{ $token }}"
    @if ($whenVisible) data-async-column-when-visible="1" @endif
    @if ($retryable) data-async-column-retryable="1" @endif
    data-async-column-error-label="{{ __('async-column::async-column.error') }}"
    @if ($retryable) data-async-column-retry-label="{{ __('async-column::async-column.retry') }}" @endif
    x-init="$store.asyncColumn?.register($el)"
    @if ($retryable) x-on:click="$el.dataset.asyncColumnError === '1' && $store.asyncColumn?.retry($el)" @endif
>{{ $loadingState }}</div>
