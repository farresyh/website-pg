@props(['tier', 'before', 'endsOn', 'fee', 'renewUrl', 'storeName' => 'PekanGame', 'logoUrl' => null, 'storeUrl' => null])
<x-mail::message :storeName="$storeName" :logoUrl="$logoUrl" :storeUrl="$storeUrl">
@if ($before)
# Your {{ $tier }} membership ends soon

Your {{ $tier }} membership ends on **{{ $endsOn }}**. Renew before then to keep your member prices without a break.
@else
# Your {{ $tier }} membership has ended

Your {{ $tier }} membership ended on **{{ $endsOn }}**. Renew to get your member prices back.
@endif

Renewal: **{{ $fee }}** for 30 days, plus the payment provider's fee shown at checkout.

<x-mail::button :url="$renewUrl">
Renew membership
</x-mail::button>

Thanks,<br>
{{ $storeName }}
</x-mail::message>
