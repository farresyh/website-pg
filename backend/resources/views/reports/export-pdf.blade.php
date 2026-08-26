<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 11px; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        p.meta { color: #555; margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background: #f2f2f2; }
        td.num { text-align: right; }
    </style>
</head>
<body>
    <h1>Sales Report</h1>
    <p class="meta">{{ $rangeLabel }}{{ $resellerLabel ? ' — '.$resellerLabel : '' }}</p>

    <table>
        <thead>
            <tr>
                <th>Order #</th>
                <th>Paid At</th>
                <th>Customer</th>
                <th>Reseller</th>
                <th>Sales (RM)</th>
                <th>Platform Profit (RM)</th>
                <th>Reseller Profit (RM)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
            <tr>
                <td>{{ $row['order_number'] }}</td>
                <td>{{ $row['paid_at'] }}</td>
                <td>{{ $row['customer_email'] }}</td>
                <td>{{ $row['reseller_name'] ?? '-' }}</td>
                <td class="num">{{ number_format($row['final_amount'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['platform_profit'] / 100, 2) }}</td>
                <td class="num">{{ number_format($row['reseller_profit'] / 100, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
