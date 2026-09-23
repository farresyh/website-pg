<x-mail::message>
# Set your portal password

Hi {{ $name }},

An account has been created for you to manage **{{ $business }}** on the partner portal. 
Set your password to activate it:

<x-mail::button :url="$url" color="primary">
Set Password
</x-mail::button>

This link expires in 24 hours. If you weren't expecting this, ignore this email.

Thanks,<br>
PekanGame
</x-mail::message>
