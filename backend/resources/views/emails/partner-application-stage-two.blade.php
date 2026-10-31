<x-mail::message>
# The next step of your reseller application

Hi {{ $name }}, we would like to continue with your application. The next step asks for your address and a few documents, so we can verify you and open your account.

<x-mail::button :url="$url">
Continue my application
</x-mail::button>

You can save your progress and come back to it. The link works for 14 days and only for you, so please do not forward it.

Thanks,<br>
PekanGame
</x-mail::message>
