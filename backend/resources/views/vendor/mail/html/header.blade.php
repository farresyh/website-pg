@props(['url', 'storeName' => 'PekanGame', 'logoUrl' => null])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logoUrl)
<img src="{{ $logoUrl }}" class="logo" alt="{{ $storeName }} Logo" style="max-height: 50px;">
@else
{{ $storeName }}
@endif
</a>
</td>
</tr>
