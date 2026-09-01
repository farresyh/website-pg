<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #101828;">
    <h2 style="color: #b91c1c;">Backup failure — {{ config('app.name') }}</h2>
    <p><strong>Context:</strong> {{ $context }}</p>
    <p><strong>Details:</strong></p>
    <pre style="white-space: pre-wrap; background: #f4f4f5; padding: 12px; border-radius: 6px;">{{ $failureMessage }}</pre>
    <p>Check <code>/middleware/backups</code> for the full run history.</p>
</body>
</html>
