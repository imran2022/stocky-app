@component('mail::message')
# {{ __('New Online Order') }}

**{{ __('Order Reference') }}:** {{ $order->ref }}
**{{ __('Date') }}:** {{ $order->date }} {{ $order->time }}
**{{ __('Customer') }}:** {{ $order->customer_name }} ({{ $order->customer_email }})
**{{ __('Phone') }}:** {{ $order->customer_phone }}
**{{ __('Payment Method') }}:** {{ $order->payment_method }} — {{ $order->payment_status }}
**{{ __('Total') }}:** {{ number_format((float) $order->total, 2) }}

@if($order->is_flagged)
> ⚠️ **{{ __('Flagged for review') }}:** {{ $order->flag_reason }}
@endif

**{{ __('Ship to') }}:**
{{ $order->shipping_address }}@if($order->shipping_city), {{ $order->shipping_city }}@endif
{{ $order->shipping_country }}

@component('mail::button', ['url' => url('/app/Store/Orders')])
{{ __('Review Order') }}
@endcomponent

{{ $storeName ?: config('app.name') }}
@endcomponent
