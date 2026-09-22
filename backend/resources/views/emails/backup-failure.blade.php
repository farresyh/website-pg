<x-mail::message>
# System Alert: Backup Failure

**Context:** {{ $context }}

<x-mail::panel>
**Error details:**  
{{ $messageText }}
</x-mail::panel>

This is an automated alert requiring admin attention. Please check the server logs for more details.

Thanks,<br>
PekanGame System
</x-mail::message>
