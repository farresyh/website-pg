@props(['tier', 'opening', 'amountLine', 'expires', 'storeName' => 'PekanGame', 'logoUrl' => null])
<x-mail::message :storeName="$storeName" :logoUrl="$logoUrl">
# {{ $tier }} Membership Receipt

{{ $opening }}

**{{ $amountLine }}**  
Active through: **{{ $expires }}**

<x-mail::panel>
You can manage your membership at any time by visiting your account's Membership page on the storefront.
</x-mail::panel>

Thanks,<br>
{{ $storeName }}
</x-mail::message>
