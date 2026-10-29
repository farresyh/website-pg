<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Payment Receipt {{ $number }}</title>
<style>
    @page { margin: 40px 44px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1b1b1f; }
    h1 { font-size: 22px; margin: 0; }
    .muted { color: #5c5c66; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 2px 0; vertical-align: top; }
    .meta td.k { width: 120px; color: #5c5c66; }
    .parties { margin-top: 26px; }
    .parties td { vertical-align: top; width: 50%; line-height: 1.5; }
    .headline { font-size: 15px; font-weight: bold; margin: 34px 0 12px; }
    .items th { text-align: left; padding: 6px 8px; border-bottom: 1px solid #1b1b1f; font-size: 10px; }
    .items td { padding: 8px; border-bottom: 1px solid #e3e3e8; }
    .items .num { text-align: right; }
    .totals { width: 55%; margin: 18px 0 0 auto; }
    .totals td { padding: 7px 8px; border-bottom: 1px solid #e3e3e8; }
    .totals td.num { text-align: right; }
    .totals tr.paid td { font-weight: bold; border-bottom: 0; }
    .note { margin-top: 40px; font-size: 10px; line-height: 1.5; }
</style>
</head>
<body>
<table>
    <tr>
        <td><h1>Payment Receipt</h1></td>
        <td style="text-align: right;"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(resource_path('img/lwf-logo.png'))) }}" alt="LWF" style="height: 34px;"></td>
    </tr>
</table>

<table class="meta" style="margin-top: 14px;">
    <tr><td class="k">Receipt number</td><td>{{ $number }}</td></tr>
    <tr><td class="k">Date paid</td><td>{{ $issued_at->format('j F Y, g:i A') }} (Malaysia time)</td></tr>
    <tr><td class="k">Payment method</td><td>{{ $paid_via }}</td></tr>
</table>

<table class="parties">
    <tr>
        <td>
            <strong>{{ $seller['name'] ?? 'PekanGame' }}</strong><br>
            @if($seller['reg_no']) Registration no. {{ $seller['reg_no'] }}<br> @endif
            @if($seller['address']) {!! nl2br(e($seller['address'])) !!} @endif
        </td>
        <td>
            <strong>Bill to</strong><br>
            {{ $bill_to['name'] }}<br>
            @if(!empty($bill_to['contact'])) {{ $bill_to['contact'] }}<br> @endif
            @if(!empty($bill_to['email'])) {{ $bill_to['email'] }} @endif
        </td>
    </tr>
</table>

<div class="headline">RM {{ number_format($total_sen / 100, 2) }} paid on {{ $issued_at->format('j F Y') }}</div>

<table class="items">
    <tr><th>Description</th><th class="num">Quantity</th><th class="num">Amount</th></tr>
    <tr><td>Purchase Balance added</td><td class="num">1</td><td class="num">RM {{ number_format($amount_sen / 100, 2) }}</td></tr>
    @if($fee_sen > 0)
        <tr><td>Payment processing fee</td><td class="num">1</td><td class="num">RM {{ number_format($fee_sen / 100, 2) }}</td></tr>
    @endif
</table>

<table class="totals">
    <tr><td>Total</td><td class="num">RM {{ number_format($total_sen / 100, 2) }}</td></tr>
    <tr class="paid"><td>Amount paid</td><td class="num">RM {{ number_format($total_sen / 100, 2) }}</td></tr>
</table>

<p class="note muted">
    Purchase Balance is prepaid credit for orders on PekanGame. It cannot be withdrawn, cashed out or transferred.
    This is a receipt for money received, not an invoice. Orders paid from this balance appear in your order history.
</p>
</body>
</html>
