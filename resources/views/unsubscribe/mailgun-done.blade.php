<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Unsubscribed</title></head>
<body style="margin:0;padding:40px 20px;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">
<div style="max-width:460px;margin:0 auto;background:#fff;padding:28px;border-radius:8px;">
    <h1 style="font-size:18px;margin:0 0 12px;">{{ $done ? 'You have been unsubscribed' : 'Link not recognised' }}</h1>
    <p style="font-size:14px;color:#666;margin:0;">
        {{ $done ? 'You will not receive further emails from this list.' : 'This unsubscribe link is no longer valid.' }}
    </p>
</div>
</body>
</html>
