<!doctype html>
<html lang="ms">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title>PekanGame · Login Order</title>
<style>
  :root { color-scheme: light dark; --bg:#f6f7f9; --card:#fff; --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --brand:#2563eb; }
  @media (prefers-color-scheme: dark) { :root { --bg:#0b0f17; --card:#151b26; --ink:#f3f4f6; --muted:#9ca3af; --line:#273042; --brand:#60a5fa; } }
  * { box-sizing: border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  main { max-width:420px; margin:0 auto; padding:24px 16px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; }
  h1 { font-size:18px; margin:0 0 4px; }
  .muted { color:var(--muted); font-size:14px; margin:0 0 16px; }
  dl { margin:0; } dt { color:var(--muted); font-size:13px; margin-top:12px; } dd { margin:2px 0 0; font-weight:600; word-break:break-all; }
  button { width:100%; margin-top:20px; padding:12px; border:0; border-radius:8px; background:var(--brand); color:#fff; font:inherit; font-weight:600; cursor:pointer; min-height:44px; }
  .secret { display:flex; gap:8px; align-items:center; } .secret code { flex:1; font-size:16px; }
  .secret button { width:auto; margin:0; padding:8px 12px; min-height:36px; }
  .note { margin-top:16px; font-size:13px; color:var(--muted); }
</style>
</head>
<body>
<main>
  <div class="card">
    <h1>PekanGame · Login Order</h1>
    @if ($state === 'dead')
      <p class="muted">Link ini tidak sah atau sudah tamat. Order mungkin sudah selesai, gagal atau dihantar semula.</p>
    @else
      <p class="muted">{{ collect([$link->order->order_number, $link->order->game?->name, $link->order->package?->name])->filter()->implode(' · ') }}</p>
      @if (! empty($fields))
        <dl>
          @foreach ($fields as $field)
            <dt>{{ $field['label'] }}</dt>
            <dd>{{ $field['value'] }}</dd>
          @endforeach
        </dl>
      @endif
      @if ($state === 'summary')
        <p class="note">Tekan butang di bawah hanya bila anda sedia untuk buat top-up. Setiap kali login dibuka, ia direkod.</p>
        <form method="post" action="{{ route('supplier-login.reveal', ['token' => $token]) }}">
          @csrf
          <button type="submit">Show login</button>
        </form>
      @elseif ($state === 'revealed')
        <dl>
          @foreach ($secrets as $secret)
            <dt>{{ $secret['label'] }}</dt>
            <dd class="secret"><code>{{ $secret['value'] }}</code><button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.textContent)">Copy</button></dd>
          @endforeach
        </dl>
        <p class="note">Bila siap, balas dalam group: .done {{ $link->order->order_number }}</p>
      @elseif ($state === 'wiped')
        <p>Login pelanggan ini sudah dipadam. Sila hubungi admin.</p>
      @elseif ($state === 'limited')
        <p>Terlalu banyak cubaan untuk link ini. Cuba lagi dalam sejam, atau hubungi admin.</p>
      @endif
    @endif
  </div>
</main>
</body>
</html>
