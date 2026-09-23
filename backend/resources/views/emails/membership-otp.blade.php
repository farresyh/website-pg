@props(['code', 'storeName' => 'PekanGame', 'logoUrl' => null])
<x-mail::message :storeName="$storeName" :logoUrl="$logoUrl">
# Your verification code

Here is your verification code to sign in:

<x-mail::panel>
<div style="font-size: 24px; text-align: center; letter-spacing: 4px; font-weight: bold;">
{{ $code }}
</div>
</x-mail::panel>

This code expires in 10 minutes. If you didn't request this, you can safely ignore this email.

Thanks,<br>
{{ $storeName }}
</x-mail::message>
