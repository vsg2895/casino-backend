{{--
    Forum account email — confirm an address, or reset a password.

    Deliberately plain: table-free, inline-styled, and short. These are security
    emails, and the more they look like a marketing send the more they train
    people to click links in marketing sends.

    There is NO unsubscribe link, and that is correct rather than an oversight:
    these are transactional and are only ever sent in response to something the
    recipient just did. The "ignore this" line below is what a recipient who did
    NOT do it needs.
--}}
<div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;font-size:16px;line-height:1.6;color:#12191a;max-width:560px;margin:0 auto;padding:24px">

    <p style="margin:0 0 20px">Hi {{ $name }},</p>

    @if ($type === 'verify')
        <p style="margin:0 0 20px">
            Confirm this address to finish setting up your {{ $brand }} forum account.
            You will not be able to post until you do.
        </p>
    @else
        <p style="margin:0 0 20px">
            Someone asked to reset the password on your {{ $brand }} forum account.
            Use the button below to choose a new one.
        </p>
    @endif

    <p style="margin:0 0 24px">
        <a href="{{ $actionUrl }}"
           style="display:inline-block;background:#0e6e5e;color:#ffffff;text-decoration:none;
                  padding:12px 24px;border-radius:999px;font-weight:600">
            {{ $type === 'verify' ? 'Confirm my address' : 'Choose a new password' }}
        </a>
    </p>

    @if ($expiresIn)
        <p style="margin:0 0 20px;color:#647775;font-size:14px">
            This link stops working in {{ $expiresIn }} minutes.
        </p>
    @endif

    {{-- The line that matters most to anyone who did not request this. --}}
    <p style="margin:0 0 20px;color:#647775;font-size:14px">
        @if ($type === 'verify')
            If you did not create this account, ignore this email — nothing will happen
            and the address will not be used.
        @else
            If you did not ask for this, ignore this email. Your password has not been
            changed and nobody can change it without this link.
        @endif
    </p>

    <p style="margin:24px 0 0;color:#8ca09c;font-size:12px;word-break:break-all">
        If the button does not work, paste this into your browser:<br>{{ $actionUrl }}
    </p>
</div>
