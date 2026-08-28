<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $t['header_title'] }}</title>
</head>
{{-- Body fields (intro/offer/spam/footer notes) are pre-escaped + **bold** converted
     in SiteVerifyEmail::render(), so they are emitted with {!! !!}. All other
     strings come through {{ }} and are escaped by Blade. --}}
@php
    // OPTIONAL BLOCKS — the footer identity lines. Hidden by the admin switching
    // them OFF, never by clearing their text, so restoring is a toggle rather
    // than a retype. Absent from $visible means visible, which keeps existing
    // rows rendering unchanged.
    $show = fn (string $key): bool => (($visible[$key] ?? true)) && ! empty($t[$key]);

    // Footer text colour — one value for all three footer lines. Coalesced on
    // the model, so this is always a real colour.
    $footerColor = $t['footer_text_color'] ?? '#9ca3af';

    // The gap between the footer note and the address line is this cell's
    // BOTTOM padding plus the identity row's TOP padding — two values that add
    // up, which is what made it read as a break rather than a footer. They are
    // tightened toward each other ONLY while the identity row renders; with it
    // hidden the body keeps its full 32px, or the email would end abruptly.
    $identityShown = $show('postal_address') || $show('contact_email');
    $bodyPadBottom = $identityShown ? '18px' : '32px';
@endphp
<body style="margin:0; padding:0; background-color:#f3f4f6; -webkit-font-smoothing:antialiased; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    @if (! empty($t['header_subtitle']))
        <span style="display:none!important; visibility:hidden; opacity:0; height:0; width:0; font-size:0; color:transparent;">
            {{ $t['header_subtitle'] }}
        </span>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">

                    {{-- Header band — dropped when both strings are cleared --}}
                    @if (! empty($t['header_title']) || ! empty($t['header_subtitle']))
                        <tr>
                            <td style="background-color:{{ $accent }}; padding:28px 32px;">
                                @if (! empty($t['header_title']))
                                    <p style="margin:0; font-size:18px; font-weight:700; color:#ffffff;">
                                        {{ $t['header_title'] }}
                                    </p>
                                @endif
                                @if (! empty($t['header_subtitle']))
                                    <p style="margin:6px 0 0; font-size:13px; color:rgba(255,255,255,0.82);">
                                        {{ $t['header_subtitle'] }}
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @endif

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px 32px {{ $bodyPadBottom }};">
                            @if (! empty($t['heading']))
                                <h1 style="margin:0 0 18px; font-size:22px; font-weight:700; color:#111827;">
                                    {{ $t['heading'] }}
                                </h1>
                            @endif

                            @if (! empty($greeting))
                                {{-- Optional "Dear {name}," greeting — only when a name was captured. --}}
                                <p style="margin:0 0 14px; font-size:15px; line-height:1.6; color:#374151;">
                                    {{ $greeting }}
                                </p>
                            @endif

                            @if (! empty($t['intro_text']))
                                <p style="margin:0 0 14px; font-size:15px; line-height:1.6; color:#374151;">
                                    {!! $t['intro_text'] !!}
                                </p>
                            @endif
                            @if (! empty($t['offer_text']))
                                <p style="margin:0 0 14px; font-size:15px; line-height:1.6; color:#374151;">
                                    {!! $t['offer_text'] !!}
                                </p>
                            @endif
                            @if (! empty($t['spam_notice']))
                                <p style="margin:0; font-size:14px; line-height:1.6; color:#6b7280;">
                                    {!! $t['spam_notice'] !!}
                                </p>
                            @endif

                            @if (! empty($verifyUrl))
                                {{-- Primary double opt-in call to action --}}
                                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0 4px;">
                                    <tr>
                                        <td align="center" style="border-radius:10px; background-color:{{ $accent }};">
                                            <a href="{{ $verifyUrl }}" target="_blank" rel="noopener"
                                               style="display:inline-block; padding:13px 30px; font-size:{{ $t['button_text_font_size'] ?? 15 }}px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                                                {{ $t['verify_button_text'] }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                                {{-- The paste-the-link fallback under the button. Deliberately NOT tied to
                                     footer_text_color: it sits in the body, and a setting called
                                     "footer text colour" recolouring it would be a surprise. --}}
                                <p style="margin:12px 0 0; font-size:12px; line-height:1.6; color:#9ca3af; word-break:break-all;">
                                    Or paste this link into your browser:<br>
                                    <a href="{{ $verifyUrl }}" style="color:{{ $accent }};">{{ $verifyUrl }}</a>
                                </p>
                            @endif

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 0;">
                                <tr><td style="border-top:1px solid #e5e7eb; font-size:0; line-height:0;">&nbsp;</td></tr>
                            </table>

                            {{-- Footer note + unsubscribe --}}
                            @if (! empty($t['footer_note']))
                                <p style="margin:24px 0 8px; font-size:12px; line-height:1.6; color:{{ $footerColor }};">
                                    {!! $t['footer_note'] !!}
                                </p>
                            @endif
                            {{-- Removable in the admin. $showUnsubscribe is the stored
                                 flag; the label is kept in the database either way, so
                                 restoring renders this block back unchanged.
                                 The List-Unsubscribe headers are emitted regardless —
                                 see VerifyEmailMail::headers(). --}}
                            @if (($showUnsubscribe ?? true) && ! empty($t['unsubscribe_label']))
                                <p style="margin:0; font-size:12px;">
                                    <a href="{{ $unsubscribeUrl }}" style="color:{{ $accent }}; text-decoration:underline;">
                                        {{ $t['unsubscribe_label'] }}
                                    </a>
                                </p>
                            @endif
                        </td>
                    </tr>

                    {{-- Footer identity — postal address · monitored contact. Each
                         hideable, each keeping its text. --}}
                    @if ($identityShown)
                        <tr>
                            <td style="padding:6px 32px 0; text-align:center;">
                                @php $addressLine = implode(', ', array_filter([$siteName, $t['postal_address'] ?? ''])); @endphp
                                <p style="margin:0; font-size:11px; line-height:1.5; color:{{ $footerColor }};">{{ $addressLine }}@if ($show('contact_email')) &nbsp;·&nbsp; <a href="mailto:{{ $t['contact_email'] }}" style="color:{{ $accent }}; text-decoration:underline;">{{ $t['contact_email'] }}</a>@endif</p>
                            </td>
                        </tr>
                    @endif

                    {{-- Copyright — removable --}}
                    @if ($show('copyright_text'))
                        <tr>
                            <td style="padding:12px 32px; background-color:#f9fafb; border-top:1px solid #f0f1f3;">
                                @if ($show('copyright_text'))
                                    <p style="margin:0; font-size:11px; color:{{ $footerColor }}; text-align:center;">
                                        {{ $t['copyright_text'] }}
                                    </p>
                                @endif
                            </td>
                        </tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
