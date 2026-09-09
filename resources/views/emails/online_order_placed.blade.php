@component('mail::message')
# {{ __('Thank you for your order') }}

<span>{{ __('Hi') }} {{ $order->customer_name }}, {{ __('we have received your order.') }}</span>

**{{ __('Order Reference') }}:** {{ $order->ref }}
**{{ __('Date') }}:** {{ $order->date }} {{ $order->time }}
**{{ __('Payment Method') }}:** {{ $order->payment_method }}
**{{ __('Payment Status') }}:** {{ $order->payment_status }}

@component('mail::table')
| {{ __('Summary') }} | |
|:--------------------|--------------------:|
| {{ __('Subtotal') }} | {{ number_format((float) $order->subtotal, 2) }} |
| {{ __('Tax') }} ({{ rtrim(rtrim(number_format((float) $order->tax_rate, 3), '0'), '.') }}%) | {{ number_format((float) $order->tax, 2) }} |
| {{ __('Shipping') }}{{ $order->shipping_method_name ? ' — '.$order->shipping_method_name : '' }} | {{ number_format((float) $order->shipping_cost, 2) }} |
| **{{ __('Total') }}** | **{{ number_format((float) $order->total, 2) }}** |
@endcomponent

**{{ __('Shipping to') }}:**
{{ $order->customer_name }}
{{ $order->shipping_address }}@if($order->shipping_city), {{ $order->shipping_city }}@endif @if($order->shipping_state){{ $order->shipping_state }}@endif @if($order->shipping_zip){{ $order->shipping_zip }}@endif
{{ $order->shipping_country }}

<span>{{ __('We will notify you when your order is processed.') }}</span>

<span>{{ __('Regards') }},<br>
{{ $storeName ?: config('app.name') }}</span>
@endcomponent
