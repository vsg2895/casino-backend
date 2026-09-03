{{-- Confirm page for the visible unsubscribe link. Renders only; the POST below acts. --}}
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Unsubscribe</title></head>
<body style="margin:0;padding:40px 20px;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222;">
<div style="max-width:460px;margin:0 auto;background:#fff;padding:28px;border-radius:8px;">
    @if ($email === null)
        <h1 style="font-size:18px;margin:0 0 12px;">Link not recognised</h1>
        <p style="font-size:14px;color:#666;margin:0;">This unsubscribe link is no longer valid.</p>
    @elseif ($alreadyOff)
        <h1 style="font-size:18px;margin:0 0 12px;">You are already unsubscribed</h1>
        <p style="font-size:14px;color:#666;margin:0;">{{ $email }} will not receive further emails.</p>
    @else
        <h1 style="font-size:18px;margin:0 0 12px;">Unsubscribe {{ $email }}?</h1>
        <p style="font-size:14px;color:#666;margin:0 0 20px;">You will stop receiving these emails immediately.</p>
        <form method="POST" action="{{ url('/api/v1/mailgun-unsubscribe/' . $token . '/confirm') }}">
            @csrf
            <button type="submit" style="background:#c0392b;color:#fff;border:0;border-radius:6px;padding:11px 20px;font-size:15px;cursor:pointer;">
                Yes, unsubscribe me
            </button>
        </form>
    @endif
</div>
</body>
</html>
