<x-mail::message>
# Reset your portal password

Hi {{ $name }},

We received a request to reset the password for your **{{ $business }}** partner portal account.
Choose a new password here:

<x-mail::button :url="$url" color="primary">
Reset Password
</x-mail::button>

This link expires in 24 hours and works once. Setting a new password signs you out everywhere else.
If you didn't ask for this, ignore this email: your password stays the same.

Thanks,<br>
PekanGame
</x-mail::message>
