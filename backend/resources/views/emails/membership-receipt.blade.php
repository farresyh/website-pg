@props(['tier', 'opening', 'amountLine', 'expires', 'storeName' => 'PekanGame', 'logoUrl' => null, 'storeUrl' => null])
<x-mail::message :storeName="$storeName" :logoUrl="$logoUrl" :storeUrl="$storeUrl">
# {{ $tier }} Membership Receipt

{{ $opening }}

**{{ $amountLine }}**  
Active through: **{{ $expires }}**

<x-mail::button :url="$storeUrl.'/membership'">
Manage membership
</x-mail::button>

Thanks,<br>
{{ $storeName }}
</x-mail::message>
