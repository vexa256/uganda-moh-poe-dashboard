<!DOCTYPE html>
<html><body style="font-family:Arial,Helvetica,sans-serif;color:#1f2937;font-size:14px;line-height:1.55;">
<p>Hello,</p>

<p>The Uganda POE Sentinel server-watch detected that the production deployment is unreachable.</p>

<table cellpadding="6" cellspacing="0" border="0" style="border-collapse:collapse;border:1px solid #e5e7eb;">
  <tr style="background:#f9fafb;">
    <th align="left" style="border:1px solid #e5e7eb;">Target</th>
    <th align="left" style="border:1px solid #e5e7eb;">URL</th>
    <th align="left" style="border:1px solid #e5e7eb;">Status</th>
    <th align="left" style="border:1px solid #e5e7eb;">Detail</th>
  </tr>
  @foreach ($results as $row)
    <tr>
      <td style="border:1px solid #e5e7eb;">{{ $row['name'] }}</td>
      <td style="border:1px solid #e5e7eb;"><a href="{{ $row['url'] }}">{{ $row['url'] }}</a></td>
      <td style="border:1px solid #e5e7eb;color:{{ $row['ok'] ? '#15803d' : '#b91c1c' }};">{{ $row['ok'] ? 'UP' : 'DOWN' }}</td>
      <td style="border:1px solid #e5e7eb;">{{ $row['detail'] }}</td>
    </tr>
  @endforeach
</table>

<p>
  Host: <strong>{{ $host }}</strong><br>
  First detected down (UTC): <strong>{{ $downSince }}</strong><br>
  Notification kind: <strong>{{ $kind }}</strong>
</p>

<p>Regards,<br>
AYEBARE Timothy<br>
ECSAHC</p>
</body></html>
