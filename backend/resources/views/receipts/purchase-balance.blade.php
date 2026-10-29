<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Payment Receipt {{ $number }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1b1b1f; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .muted { color: #5c5c66; }
    table { width: 100%; border-collapse: collapse; margin-top: 18px; }
    td { padding: 8px 0; border-bottom: 1px solid #e3e3e8; }
    td.num { text-align: right; }
    tr.total td { font-weight: bold; border-bottom: 2px solid #1b1b1f; }
    .note { margin-top: 28px; font-size: 11px; }
</style>
</head>
<body>
<h1>Payment Receipt</h1>
<div class="muted">{{ $number }} &middot; {{ $issued_at->format('j M Y, g:i A') }} (Malaysia time)</div>

<p>
    <strong>{{ $seller['name'] ?? 'PekanGame' }}</strong><br>
    @if($seller['reg_no']) Registration no. {{ $seller['reg_no'] }}<br> @endif
    @if($seller['address']) {!! nl2br(e($seller['address'])) !!} @endif
</p>

<p><span class="muted">Received from</span><br><strong>{{ $reseller_name }}</strong><br><span class="muted">Paid by {{ $paid_via }}</span></p>

<table>
    <tr><td>Purchase Balance added</td><td class="num">RM {{ number_format($amount_sen / 100, 2) }}</td></tr>
    @if($fee_sen > 0)
        <tr><td>Payment processing fee</td><td class="num">RM {{ number_format($fee_sen / 100, 2) }}</td></tr>
    @endif
    <tr class="total"><td>Total paid</td><td class="num">RM {{ number_format($total_sen / 100, 2) }}</td></tr>
</table>

<p class="note muted">
    Purchase Balance is prepaid credit for orders on PekanGame. It cannot be withdrawn, cashed out or transferred.
    This is a receipt for money received, not an invoice. An invoice is issued for each order.
</p>
</body>
</html>
