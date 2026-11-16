<x-mail::message>
@if ($deactivated)
# Your reseller account has been deactivated

The PekanGame reseller account of **{{ $businessName }}** had no transaction for {{ $days }} days, so it has been deactivated. You cannot sign in to the partner portal or place orders by bot or API until it is switched back on.

{{ $wayBack }}
@else
# Your reseller account will be deactivated soon

The PekanGame reseller account of **{{ $businessName }}** has had no transaction for a while. It will be deactivated on **{{ $offOn }}** unless you place an order or add balance before then.
@endif

Your Purchase Balance (**{{ $balance }}**) stays safe in your account.

Thanks,<br>
PekanGame
</x-mail::message>
