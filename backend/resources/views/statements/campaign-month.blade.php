<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sales Statement {{ $campaign }} {{ $month->format('F Y') }}</title>
<style>
    @page { margin: 40px 44px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1b1b1f; }
    h1 { font-size: 22px; margin: 0; }
    .muted { color: #5c5c66; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 2px 0; vertical-align: top; }
    .meta td.k { width: 130px; color: #5c5c66; }
    .flag { margin-top: 14px; padding: 8px 10px; border: 1px solid #b3261e; color: #b3261e; }
    .headline { font-size: 15px; font-weight: bold; margin: 26px 0 10px; }
    .items th { text-align: left; padding: 5px 6px; border-bottom: 1px solid #1b1b1f; font-size: 9px; }
    .items td { padding: 5px 6px; border-bottom: 1px solid #e3e3e8; }
    .num { text-align: right; }
    .totals { width: 60%; margin: 18px 0 0 auto; }
    .totals td { padding: 6px 8px; border-bottom: 1px solid #e3e3e8; }
    .totals tr.due td { font-weight: bold; border-bottom: 0; }
    .note { margin-top: 30px; font-size: 9px; line-height: 1.5; }
</style>
</head>
<body>
<table>
    <tr>
        <td><h1>Sales Statement</h1></td>
        <td style="text-align: right;"><img src="data:image/png;base64,{{ base64_encode(file_get_contents(resource_path('img/lwf-logo.png'))) }}" alt="LWF" style="height: 34px;"></td>
    </tr>
</table>

<table class="meta" style="margin-top: 14px;">
    <tr><td class="k">Campaign</td><td>{{ $campaign }}</td></tr>
    <tr><td class="k">Month</td><td>{{ $month->format('F Y') }}</td></tr>
    <tr><td class="k">Issued by</td><td>{{ $seller['name'] ?? 'PekanGame' }}@if($seller['reg_no']) ({{ $seller['reg_no'] }})@endif</td></tr>
    <tr><td class="k">Month closed on</td><td>{{ $closed_at->format('j F Y') }}</td></tr>
    @if($replaces)
        <tr><td class="k">Replaces</td><td>the statement of {{ $replaces->format('j F Y') }}, after the month was reopened</td></tr>
    @endif
</table>

@if($voided)
    <div class="flag">This statement was replaced after the month was reopened. It is kept for the record only.</div>
@endif

<div class="headline">{{ count($lines) }} {{ count($lines) === 1 ? 'order' : 'orders' }}, RM {{ number_format($s->sales_sen / 100, 2) }} in sales</div>

<table class="items">
    <tr><th>Order</th><th>Date</th><th>Game</th><th>Package</th><th>Player ID</th><th class="num">Sales (RM)</th></tr>
    @foreach($lines as $line)
        <tr>
            <td>{{ $line['order'] }}</td>
            <td>{{ \Illuminate\Support\Carbon::parse($line['date'])->format('j M') }}</td>
            <td>{{ $line['game'] }}</td>
            <td>{{ $line['package'] }}</td>
            <td>{{ $line['player'] }}</td>
            <td class="num">{{ number_format($line['sales_sen'] / 100, 2) }}</td>
        </tr>
    @endforeach
</table>

<table class="totals">
    <tr><td>Sales</td><td class="num">RM {{ number_format($s->sales_sen / 100, 2) }}</td></tr>
    @if($s->revenue_share_bp > 0)
        <tr><td>Share at {{ rtrim(rtrim(number_format($s->revenue_share_bp / 100, 2), '0'), '.') }}%</td><td class="num">RM {{ number_format($s->share_before_limits_sen / 100, 2) }}</td></tr>
        @if($s->limited_by === 'cap')
            <tr><td>Limited to the agreed monthly cap</td><td class="num">RM {{ number_format($s->share_sen / 100, 2) }}</td></tr>
        @elseif($s->limited_by === 'profit')
            <tr><td>Limited by the agreement's monthly limit</td><td class="num">RM {{ number_format($s->share_sen / 100, 2) }}</td></tr>
        @endif
    @endif
    @if($s->monthly_fee_sen > 0)
        <tr><td>Monthly fee</td><td class="num">RM {{ number_format($s->monthly_fee_sen / 100, 2) }}</td></tr>
    @endif
    <tr class="due"><td>Amount for {{ $month->format('F Y') }}</td><td class="num">RM {{ number_format($s->payable_sen / 100, 2) }}</td></tr>
</table>

<p class="note muted">
    Sales are the amounts customers paid for orders made with your code, counted in the month each order was delivered.
    Failed orders are not sales. The share is worked out once on the month's total and rounded down to the sen.
    Player IDs are partly hidden for the customers' privacy. This is a statement, not an invoice or a receipt.
</p>
</body>
</html>
