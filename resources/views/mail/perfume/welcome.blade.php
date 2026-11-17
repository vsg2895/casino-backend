{{--
    Big Glow Day — the subscribe confirmation, and the only email the shop
    sends. Its words live here; the shop's name, contacts and accent colour
    come from Settings.

    Table layout and inline styles, like every other mail view in this
    project: Outlook and Gmail strip <style> blocks and ignore flex and grid.
--}}
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>Welcome to {{ $siteName }}</title>
</head>
@php $face = "Arial, Helvetica, sans-serif"; @endphp
<body style="margin:0; padding:0; background-color:#F4F3F7;">

{{-- The grey line a mail client prints beside the subject. --}}
<div style="display:none; font-size:1px; color:#F4F3F7; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden;">You are on the list — we will write when new fragrances arrive.</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F4F3F7;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:100%; border-collapse:collapse; background-color:#FFFFFF; border-radius:8px; overflow:hidden;">

                <tr>
                    <td style="background-color:#0F0F14; padding:22px 32px; font-family:{{ $face }};">
                        <a href="{{ $siteUrl }}" style="color:#F3F1F6; font-size:24px; font-weight:bold; text-decoration:none;">{{ $siteName }}</a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:32px 32px 0 32px; font-family:{{ $face }};">
                        <h1 style="margin:0; font-size:28px; line-height:1.2; color:#0F0F14;">Thanks for subscribing</h1>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 32px 0 32px; font-family:{{ $face }}; font-size:16px; line-height:1.6; color:#33313D;">
                        <p style="margin:0 0 1em 0;">Hi{{ $name ? ' ' . $name : '' }},</p>
                        <p style="margin:0 0 1em 0;">You are on the list. We will write when new fragrances and catalogues arrive — no more than that.</p>
                        <p style="margin:0;">Most of what we sell comes as a 2&nbsp;ml sample, so you can wear something for a few days before deciding on the bottle.</p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 32px 0 32px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="background-color:{{ $accent }}; border-radius:999px;">
                                    <a href="{{ $siteUrl }}/catalog" style="display:inline-block; padding:14px 32px; font-family:{{ $face }}; font-size:16px; font-weight:bold; color:#0F0F14; text-decoration:none;">Browse the catalogue</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:32px; font-family:{{ $face }};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #E5E3EC;">
                            <tr>
                                <td style="padding-top:20px; font-size:12px; line-height:1.6; color:#75737F;">
                                    @if ($phone || $email)
                                        <p style="margin:0 0 8px 0;">{{ collect([$phone, $email])->filter()->implode(' · ') }}</p>
                                    @endif
                                    <p style="margin:0;">
                                        You are receiving this because you subscribed at
                                        <a href="{{ $siteUrl }}" style="color:#75737F;">{{ $siteName }}</a>.
                                        <a href="{{ $unsubscribe }}" style="color:#75737F; text-decoration:underline;">Unsubscribe</a>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
