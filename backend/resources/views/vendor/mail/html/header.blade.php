@props(['url', 'storeName' => 'PekanGame', 'logoUrl' => null])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if ($logoUrl)
<img src="{{ $logoUrl }}" class="logo" alt="{{ $storeName }} Logo" height="50">
@else
{{ $storeName }}
@endif
</a>
</td>
</tr>
