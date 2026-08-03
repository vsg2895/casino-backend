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
        /* Rendering helpers only — every visual rule stays inline, because a
           number of clients strip <style> entirely. */
        body, table, td, a { -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
        table, td { mso-table-lspace:0pt; mso-table-rspace:0pt; }
        img { -ms-interpolation-mode:bicubic; border:0; outline:none; text-decoration:none; }
    </style>
</head>
{{-- The markup here is deliberately not shared with any other template: the
     same offer must not go out twice as byte-identical HTML from two sending
     domains, because reputation systems fingerprint the body. Rendered output
     is unchanged — only the structure is.

     Body fields (intro_text/secondary_text/disclaimer_text) are pre-escaped +
     **bold** converted in SitePromotionEmail::render(), so they are emitted
     with {!! !!}. All other strings come through {{ }} and Blade escapes them. --}}
@php
    $face = "'DM Sans',-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif";
    // One reset per stacked block, so nested tables abut with no seam.
    $block = 'border-collapse:collapse;';
@endphp
<body style="margin:0; padding:0; background-color:#f1f1f1; font-family:{{ $face }}; -webkit-font-smoothing:antialiased;">

{{-- Hidden preview (preheader) text — removable --}}
@if (! empty($t['preheader']))
    <div style="display:none!important; visibility:hidden; opacity:0; height:0; width:0; font-size:0; line-height:0; color:transparent; overflow:hidden;">{{ $t['preheader'] }}</div>
@endif

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#f1f1f1" style="{{ $block }} background-color:#f1f1f1;">
    <tbody>
    <tr>
        <td align="center" valign="top" style="padding:0;">

            <!--[if mso]><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"><tr><td><![endif]-->
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#000000" style="{{ $block }} max-width:600px; background-color:#000000;">
                <tbody>
                <tr>
                    {{-- Single master cell: every block below is a self-contained
                         table stacked inside it, rather than a sibling row. --}}
                    <td valign="top" bgcolor="#000000" style="padding:0; background-color:#000000;">

                        {{-- Hero image — dropped entirely when the admin clears it.
                             Linked to the offer only when an offer URL is set. --}}
                        @if (! empty($t['hero_image_url']))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:0; font-size:0; line-height:0;">
                                        @if (! empty($t['hero_url']))
                                            <a href="{{ $t['hero_url'] }}" target="_blank" rel="nofollow sponsored noopener"><img
                                                    src="{{ $t['hero_image_url'] }}" width="600"
                                                    alt="{{ $t['heading'] ?: $siteName }}"
                                                    style="display:block; width:100%; max-width:600px; height:auto; border:0;"></a>
                                        @else
                                            <img src="{{ $t['hero_image_url'] }}" width="600"
                                                 alt="{{ $t['heading'] ?: $siteName }}"
                                                 style="display:block; width:100%; max-width:600px; height:auto; border:0;">
                                        @endif
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Top CTA — omitted (with its spacing) when the label is
                             cleared. Independent of the offer link: without one it
                             renders as an unlinked pill. --}}
                        @if (! empty($t['top_button_text']))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:20px 20px 40px;">
                                        @include('mail.promotion.partials.cta-button', [
                                            'label' => $t['top_button_text'],
                                            'url'   => $t['hero_url'],
                                            'color' => $buttonColor,
                                        ])
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Body copy — heading, greeting and both paragraphs are
                             individually removable; the block disappears with them. --}}
                        @if (! empty($t['heading']) || ! empty($greeting) || ! empty($t['intro_text']) || ! empty($t['secondary_text']))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:30px 20px; text-align:center; color:#ffffff; font-family:{{ $face }};">
                                        @if (! empty($t['heading']))
                                            <h2 style="margin:0 0 20px; font-size:24px; font-weight:600; line-height:1.4; color:#ffffff;">{{ $t['heading'] }}</h2>
                                        @endif

                                        @if (! empty($greeting))
                                            {{-- Optional "Dear {name}," — only when a name was captured. --}}
                                            <p style="margin:0 0 20px; font-size:17px; line-height:1.6; color:#ffffff;">{{ $greeting }}</p>
                                        @endif

                                        @if (! empty($t['intro_text']))
                                            <p style="margin:0 0 20px; font-size:17px; line-height:1.6; color:#ffffff;">{!! $t['intro_text'] !!}</p>
                                        @endif

                                        @if (! empty($t['secondary_text']))
                                            <p style="margin:0; font-size:16px; line-height:1.6; color:rgba(255,255,255,0.85);">{!! $t['secondary_text'] !!}</p>
                                        @endif
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Bottom CTA — same removal rule as the top one. --}}
                        @if (! empty($t['cta_button_text']))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:20px 20px 40px;">
                                        @include('mail.promotion.partials.cta-button', [
                                            'label' => $t['cta_button_text'],
                                            'url'   => $t['hero_url'],
                                            'color' => $buttonColor,
                                        ])
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Disclaimer — removable --}}
                        @if (! empty($t['disclaimer_text']))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:30px 20px 10px; text-align:center; font-family:{{ $face }}; font-size:13px; line-height:1.6; color:rgba(255,255,255,0.7);">{!! $t['disclaimer_text'] !!}</td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Unsubscribe — structural, never removable --}}
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                            <tbody>
                            <tr>
                                <td align="center" style="padding:10px 20px 30px; text-align:center; font-family:{{ $face }}; font-size:12px; line-height:1.5; color:rgba(255,255,255,0.5);">
                                    <a href="{{ $unsubscribeUrl }}" style="color:{{ $accent }}; text-decoration:underline;">{{ $t['unsubscribe_label'] }}</a>
                                </td>
                            </tr>
                            </tbody>
                        </table>

                    </td>
                </tr>
                </tbody>
            </table>
            <!--[if mso]></td></tr></table><![endif]-->

        </td>
    </tr>
    </tbody>
</table>
</body>
</html>
