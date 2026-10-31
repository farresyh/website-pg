<x-mail::message>
# Confirm your email

Thanks for applying to become a PekanGame reseller, {{ $name }}. Confirm your email address so we can review your application.

<x-mail::button :url="$url">
Confirm my email
</x-mail::button>

This link works for 24 hours. If you did not apply, you can ignore this email.

Thanks,<br>
PekanGame
</x-mail::message>
