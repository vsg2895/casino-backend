<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $t['header_title'] }}</title>
</head>
{{-- Body fields (intro/offer/spam/footer notes) are pre-escaped + **bold** converted
     in SiteEmailTemplate::render(), so they are emitted with {!! !!}. All other
     strings come through {{ }} and are escaped by Blade. --}}
<body style="margin:0; padding:0; background-color:#f3f4f6; -webkit-font-smoothing:antialiased; font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    <span style="display:none!important; visibility:hidden; opacity:0; height:0; width:0; font-size:0; color:transparent;">
        {{ $t['header_subtitle'] }}
    </span>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background-color:#ffffff; border-radius:14px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.08);">

                    {{-- Header band --}}
                    <tr>
                        <td style="background-color:{{ $accent }}; padding:28px 32px;">
                            <p style="margin:0; font-size:18px; font-weight:700; color:#ffffff;">
                                {{ $t['header_title'] }}
                            </p>
                            <p style="margin:6px 0 0; font-size:13px; color:rgba(255,255,255,0.82);">
                                {{ $t['header_subtitle'] }}
                            </p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px;">
                            <h1 style="margin:0 0 18px; font-size:22px; font-weight:700; color:#111827;">
                                {{ $t['heading'] }}
                            </h1>

                            <p style="margin:0 0 14px; font-size:15px; line-height:1.6; color:#374151;">
                                {!! $t['intro_text'] !!}
                            </p>
                            <p style="margin:0 0 14px; font-size:15px; line-height:1.6; color:#374151;">
                                {!! $t['offer_text'] !!}
                            </p>
                            <p style="margin:0; font-size:14px; line-height:1.6; color:#6b7280;">
                                {!! $t['spam_notice'] !!}
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0 0;">
                                <tr><td style="border-top:1px solid #e5e7eb; font-size:0; line-height:0;">&nbsp;</td></tr>
                            </table>

                            {{-- Footer note + unsubscribe --}}
                            <p style="margin:24px 0 8px; font-size:12px; line-height:1.6; color:#9ca3af;">
                                {!! $t['footer_note'] !!}
                            </p>
                            <p style="margin:0; font-size:12px;">
                                <a href="{{ $unsubscribeUrl }}" style="color:{{ $accent }}; text-decoration:underline;">
                                    {{ $t['unsubscribe_label'] }}
                                </a>
                            </p>
                        </td>
                    </tr>

                    {{-- Copyright --}}
                    <tr>
                        <td style="padding:18px 32px; background-color:#f9fafb; border-top:1px solid #f0f1f3;">
                            <p style="margin:0; font-size:11px; color:#9ca3af; text-align:center;">
                                {{ $t['copyright_text'] }}
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
