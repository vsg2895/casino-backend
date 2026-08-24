<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $t['subject'] }}</title>
    <!--[if mso]>
    <noscript>
        <xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>
    </noscript>
    <![endif]-->
    <style type="text/css">
        body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
        table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
        img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
    </style>
</head>
{{-- Every visible string arrives pre-rendered from VerificationPromotionEmail::render():
     rich fields (intro_text, secondary_text, disclaimer_text, responsible_notice_text,
     footer_tagline, affiliate_disclosure_text) are pre-escaped + **bold** converted, so
     they are emitted with {!! !!}; all other strings come through {{ }} and Blade escapes
     them. Every optional component is dropped, with its spacing, when the admin clears it. --}}
@php
    $face = "Arial, Helvetica, sans-serif";
    $block = 'border-collapse:collapse;';
@endphp
<body style="margin:0; padding:0; background-color:{{ $canvas }}; font-family:{{ $face }};">

{{-- Hidden preview (preheader) text — removable --}}
@if (! empty($t['preheader']))
    <div style="display:none!important; visibility:hidden; opacity:0; height:0; width:0; font-size:0; line-height:0; color:transparent; overflow:hidden;">{{ $t['preheader'] }}</div>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $canvas }}" style="{{ $block }} background-color:{{ $canvas }}; padding:24px 0;">
    <tr>
        <td align="center" style="padding:24px 0;">

            <!--[if mso]><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"><tr><td><![endif]-->
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $bodyBg }}" style="{{ $block }} background-color:{{ $bodyBg }}; border-radius:8px; overflow:hidden; max-width:600px; width:100%;">

                {{-- Header brand band — removable --}}
                @if (! empty($t['header_brand_text']))
                    <tr>
                        <td style="background-color:{{ $headerColor }}; padding:24px 32px; text-align:center;">
                            <a href="{{ $t['hero_url'] ?: $siteUrl }}" target="_blank" rel="nofollow sponsored noopener" style="color:#ffffff; font-size:20px; font-weight:bold; letter-spacing:0.5px; text-decoration:none; text-transform:uppercase;">{{ $t['header_brand_text'] }}</a>
                        </td>
                    </tr>
                @endif

                {{-- Hero image — removable. Linked to the offer only when a URL is set. --}}
                @if (! empty($t['hero_image_url']))
                    <tr>
                        <td style="font-size:0; line-height:0;">
                            @if (! empty($t['hero_url']))
                                <a href="{{ $t['hero_url'] }}" target="_blank" rel="nofollow sponsored noopener"><img src="{{ $t['hero_image_url'] }}" alt="{{ $t['heading'] ?: $siteName }}" width="600" style="display:block; width:100%; max-width:600px; height:auto; border:0;"></a>
                            @else
                                <img src="{{ $t['hero_image_url'] }}" alt="{{ $t['heading'] ?: $siteName }}" width="600" style="display:block; width:100%; max-width:600px; height:auto; border:0;">
                            @endif
                        </td>
                    </tr>
                @endif

                {{-- Body --}}
                <tr>
                    <td style="padding:32px; font-family:{{ $face }};">
                        @if (! empty($t['eyebrow_text']))
                            <p style="margin:0 0 8px 0; font-size:14px; color:{{ $accent }}; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px;">{{ $t['eyebrow_text'] }}</p>
                        @endif

                        @if (! empty($t['heading']))
                            <h1 style="margin:0 0 16px 0; font-size:26px; color:{{ $headingColor }}; line-height:1.3;">{{ $t['heading'] }}</h1>
                        @endif

                        @if (! empty($greeting))
                            <p style="margin:0 0 16px 0; font-size:16px; color:{{ $textColor }}; line-height:1.6;">{{ $greeting }}</p>
                        @endif

                        @if (! empty($t['intro_text']))
                            <p style="margin:0 0 16px 0; font-size:16px; color:{{ $textColor }}; line-height:1.6;">{!! $t['intro_text'] !!}</p>
                        @endif

                        {{-- Star-rating highlight box — shown when either the stars or the
                             highlight text is set. --}}
                        @if (! empty($t['rating_stars']) || ! empty($t['highlight_text']))
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $block }} margin:24px 0;">
                                <tr>
                                    <td style="padding:20px; text-align:center; background-color:{{ $canvas }}; border-radius:6px;">
                                        @if (! empty($t['rating_stars']))
                                            <p style="margin:0 0 4px 0; font-size:15px; color:{{ $headingColor }};">{{ $t['rating_stars'] }}</p>
                                        @endif
                                        @if (! empty($t['highlight_text']))
                                            <p style="margin:0; font-size:22px; color:{{ $buttonColor }}; font-weight:bold;">{{ $t['highlight_text'] }}</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        @endif

                        @if (! empty($t['secondary_text']))
                            <p style="margin:0 0 16px 0; font-size:16px; color:{{ $secondaryColor }}; line-height:1.6;">{!! $t['secondary_text'] !!}</p>
                        @endif

                        {{-- CTA button — removable. --}}
                        @if (! empty($t['cta_button_text']))
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $block }} margin:24px auto;">
                                <tr>
                                    <td align="center" style="border-radius:6px; background-color:{{ $buttonColor }};">
                                        <a href="{{ $t['hero_url'] ?: $siteUrl }}" target="_blank" rel="nofollow sponsored noopener" style="display:inline-block; padding:14px 32px; font-size:16px; color:#ffffff; text-decoration:none; font-weight:bold;">{{ $t['cta_button_text'] }}</a>
                                    </td>
                                </tr>
                            </table>
                        @endif

                        @if (! empty($t['disclaimer_text']))
                            <p style="margin:24px 0 0 0; font-size:13px; color:{{ $mutedColor }}; line-height:1.5;">{!! $t['disclaimer_text'] !!}</p>
                        @endif
                    </td>
                </tr>

                {{-- Responsible gambling notice — removable --}}
                @if (! empty($t['responsible_notice_text']))
                    <tr>
                        <td style="padding:0 32px 24px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $block }} background-color:{{ $canvas }}; border-radius:6px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <p style="margin:0; font-size:12px; color:{{ $mutedColor }}; line-height:1.6;">{!! $t['responsible_notice_text'] !!}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                {{-- Footer --}}
                <tr>
                    <td style="background-color:{{ $footerBg }}; padding:24px 32px; text-align:center; font-family:{{ $face }};">
                        @if (! empty($t['footer_tagline']))
                            <p style="margin:0 0 8px 0; font-size:13px; color:{{ $footerColor }};">{!! $t['footer_tagline'] !!}</p>
                        @endif

                        @if (! empty($t['footer_links']))
                            <p style="margin:0 0 12px 0; font-size:12px; color:{{ $footerColor }};">
                                @foreach ($t['footer_links'] as $link)
                                    <a href="{{ $link['url'] }}" target="_blank" rel="noopener" style="color:{{ $footerColor }}; text-decoration:underline;">{{ $link['label'] }}</a>@unless ($loop->last) &nbsp;·&nbsp; @endunless
                                @endforeach
                            </p>
                        @endif

                        @if (! empty($t['affiliate_disclosure_text']))
                            <p style="margin:0 0 8px 0; font-size:11px; color:{{ $footerColor }};">{!! $t['affiliate_disclosure_text'] !!}</p>
                        @endif

                        {{-- Unsubscribe — structural, never removed --}}
                        <p style="margin:0; font-size:11px; color:{{ $footerColor }};">
                            <a href="{{ $unsubscribeUrl }}" style="color:{{ $footerColor }}; text-decoration:underline;">{{ $t['unsubscribe_label'] }}</a>
                        </p>

                        @if (! empty($t['copyright_text']))
                            <p style="margin:8px 0 0 0; font-size:11px; color:{{ $footerColor }};">{{ $t['copyright_text'] }}</p>
                        @endif
                    </td>
                </tr>

            </table>
            <!--[if mso]></td></tr></table><![endif]-->

        </td>
    </tr>
</table>
</body>
</html>
