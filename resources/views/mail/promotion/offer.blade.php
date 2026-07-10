<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $t['subject'] }}</title>
</head>
{{-- Body fields (intro_text/secondary_text/disclaimer_text) are pre-escaped +
     **bold** converted in SitePromotionEmail::render(), so they are emitted with
     {!! !!}. All other strings come through {{ }} and are escaped by Blade. --}}
<body style="margin:0; padding:0; background-color:#f1f1f1; -webkit-font-smoothing:antialiased; font-family:'DM Sans',-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
    {{-- Hidden preview (preheader) text --}}
    <span style="display:none!important; visibility:hidden; opacity:0; height:0; width:0; font-size:0; color:transparent;">
        {{ $t['preheader'] }}
    </span>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f1f1;">
        <tr>
            <td align="center" style="padding:0;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background-color:#000000; border-collapse:collapse;">

                    {{-- Hero image (optional), links to the offer --}}
                    @if (!empty($t['hero_image_url']))
                        <tr>
                            <td style="padding:0; background-color:#000000; text-align:center;">
                                <a href="{{ $t['hero_url'] }}" target="_blank" rel="nofollow sponsored noopener">
                                    <img src="{{ $t['hero_image_url'] }}" alt="{{ $t['heading'] }}" width="600"
                                         style="display:block; width:100%; max-width:600px; height:auto; border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic;">
                                </a>
                            </td>
                        </tr>
                    @endif

                    {{-- Top CTA button --}}
                    <tr>
                        <td style="padding:20px 20px 40px; background-color:#000000; text-align:center;">
                            <a href="{{ $t['hero_url'] }}" target="_blank" rel="nofollow sponsored noopener"
                               style="display:inline-block; background-color:{{ $buttonColor }}; text-decoration:none; padding:14px 48px; border-radius:8px; font-size:18px; font-weight:600; color:#ffffff;">
                                <span style="color:#ffffff; -webkit-text-fill-color:#ffffff; display:inline-block;">{{ $t['top_button_text'] }}</span>
                            </a>
                        </td>
                    </tr>

                    {{-- Body copy --}}
                    <tr>
                        <td style="background-color:#000000; color:#ffffff;">
                            <div style="text-align:center; padding:30px 20px;">
                                <h2 style="color:#ffffff; font-size:24px; font-weight:600; margin:0 0 20px; line-height:1.4;">
                                    {{ $t['heading'] }}
                                </h2>
                                @if (! empty($greeting))
                                    {{-- Optional "Dear {name}," greeting — only when a name was captured. --}}
                                    <p style="color:#ffffff; font-size:17px; margin:0 0 20px; line-height:1.6;">
                                        {{ $greeting }}
                                    </p>
                                @endif
                                <p style="color:#ffffff; font-size:17px; margin:0 0 20px; line-height:1.6;">
                                    {!! $t['intro_text'] !!}
                                </p>
                                <p style="color:rgba(255,255,255,0.85); font-size:16px; margin:0; line-height:1.6;">
                                    {!! $t['secondary_text'] !!}
                                </p>
                            </div>
                        </td>
                    </tr>

                    {{-- Bottom CTA button --}}
                    <tr>
                        <td style="padding:20px 20px 40px; background-color:#000000; text-align:center;">
                            <a href="{{ $t['hero_url'] }}" target="_blank" rel="nofollow sponsored noopener"
                               style="display:inline-block; background-color:{{ $buttonColor }}; text-decoration:none; padding:14px 48px; border-radius:8px; font-size:18px; font-weight:600; color:#ffffff;">
                                <span style="color:#ffffff; -webkit-text-fill-color:#ffffff; display:inline-block;">{{ $t['cta_button_text'] }}</span>
                            </a>
                        </td>
                    </tr>

                    {{-- Disclaimer --}}
                    <tr>
                        <td style="background-color:#000000; color:rgba(255,255,255,0.7); text-align:center; padding:30px 20px 10px; font-size:13px; line-height:1.6;">
                            {!! $t['disclaimer_text'] !!}
                        </td>
                    </tr>

                    {{-- Unsubscribe --}}
                    <tr>
                        <td style="background-color:#000000; color:rgba(255,255,255,0.5); text-align:center; padding:10px 20px 30px; font-size:12px; line-height:1.5;">
                            <a href="{{ $unsubscribeUrl }}" style="color:{{ $accent }}; text-decoration:underline;">
                                {{ $t['unsubscribe_label'] }}
                            </a>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
