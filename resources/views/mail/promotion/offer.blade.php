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

    // OPTIONAL BLOCKS. Every removable text and image is hidden by the admin
    // switching it OFF, never by clearing its field — the content stays in the row
    // so restoring is a toggle rather than a retype. $visible comes from
    // SitePromotionEmail::visibleBlocks(); a block absent from it defaults to
    // visible, which keeps existing rows rendering unchanged.
    //
    // $show — render this block? (switched on AND has content)
    // $val  — its value, or '' when switched off, for fields used as a value
    //         rather than a guard (the link behind a button or image).
    $show = fn (string $key): bool => ($visible[$key] ?? true) && ! empty($t[$key]);
    $val  = fn (string $key): string => ($visible[$key] ?? true) ? (string) ($t[$key] ?? '') : '';
@endphp
<body style="margin:0; padding:0; background-color:#f1f1f1; font-family:{{ $face }}; -webkit-font-smoothing:antialiased;">

{{-- Hidden preview (preheader) text — removable --}}
@if ($show('preheader'))
    <div style="display:none!important; visibility:hidden; opacity:0; height:0; width:0; font-size:0; line-height:0; color:transparent; overflow:hidden;">{{ $t['preheader'] }}</div>
@endif

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#f1f1f1" style="{{ $block }} background-color:#f1f1f1;">
    <tbody>
    <tr>
        <td align="center" valign="top" style="padding:0;">

            <!--[if mso]><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"><tr><td><![endif]-->
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="{{ $background }}" style="{{ $block }} max-width:600px; background-color:{{ $background }};">
                <tbody>
                <tr>
                    {{-- Single master cell: every block below is a self-contained
                         table stacked inside it, rather than a sibling row. --}}
                    <td valign="top" bgcolor="{{ $background }}" style="padding:0; background-color:{{ $background }};">

                        {{-- Body copy FIRST — the welcome and the offer in words, before
                             the banner, so the message still reads with images blocked
                             (Outlook, most corporate mail). Heading, greeting and both
                             paragraphs are individually removable; the block disappears
                             with them. --}}
                        @if ($show('heading') || ! empty($greeting) || $show('intro_text') || $show('secondary_text'))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    {{-- Full top padding again: this block now opens the
                                         email, where the tightened 10px was only right when
                                         it sat under a button. --}}
                                    <td align="center" style="padding:30px 20px; text-align:center; color:{{ $textColor }}; font-family:{{ $face }};">
                                        @if ($show('heading'))
                                            <h2 style="margin:0 0 20px; font-size:24px; font-weight:600; line-height:1.4; color:{{ $headingColor }};">{{ $t['heading'] }}</h2>
                                        @endif

                                        @if (! empty($greeting))
                                            {{-- Optional "Dear {name}," — only when a name was captured. --}}
                                            <p style="margin:0 0 20px; font-size:17px; line-height:1.6; color:{{ $textColor }};">{{ $greeting }}</p>
                                        @endif

                                        @if ($show('intro_text'))
                                            <p style="margin:0 0 20px; font-size:17px; line-height:1.6; color:{{ $textColor }};">{!! $t['intro_text'] !!}</p>
                                        @endif

                                        @if ($show('secondary_text'))
                                            <p style="margin:0; font-size:16px; line-height:1.6; color:{{ $secondaryColor }};">{!! $t['secondary_text'] !!}</p>
                                        @endif
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Hero image — dropped entirely when the admin clears it.
                             Linked to the offer only when an offer URL is set. --}}
                        @if ($show('hero_image_url'))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:0; font-size:0; line-height:0;">
                                        @if ($show('hero_url'))
                                            <a href="{{ $t['hero_url'] }}" target="_blank" rel="nofollow sponsored noopener"><img
                                                    src="{{ $t['hero_image_url'] }}" width="600"
                                                    alt="{{ $val('heading') ?: $siteName }}"
                                                    style="display:block; width:100%; max-width:600px; height:auto; border:0;"></a>
                                        @else
                                            <img src="{{ $t['hero_image_url'] }}" width="600"
                                                 alt="{{ $val('heading') ?: $siteName }}"
                                                 style="display:block; width:100%; max-width:600px; height:auto; border:0;">
                                        @endif
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- The CTA — the template's only button, sitting under the
                             banner. Omitted (with its spacing) when the label is cleared.
                             Independent of the offer link: without one it renders as an
                             unlinked pill. --}}
                        @if ($show('top_button_text'))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    {{-- 20px below, not 40: paired with the body's
                                         reduced top padding this closes a 70px band of
                                         empty canvas that read as the email ending. --}}
                                    <td align="center" style="padding:20px;">
                                        @include('mail.promotion.partials.cta-button', [
                                            'label' => $t['top_button_text'],
                                            // The template's single button, so it takes the
                                            // CTA's own destination and falls back to the
                                            // offer link — existing rows keep their target.
                                            'url'   => $val('cta_button_url') ?: $val('hero_url'),
                                            'color' => $buttonColor,
                                        ])
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Disclaimer — removable --}}
                        @if ($show('disclaimer_text'))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:30px 20px 10px; text-align:center; font-family:{{ $face }}; font-size:13px; line-height:1.6; color:{{ $mutedColor }};">{!! $t['disclaimer_text'] !!}</td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Unsubscribe — structural, never removable --}}
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                            <tbody>
                            <tr>
                                <td align="center" style="padding:10px 20px 30px; text-align:center; font-family:{{ $face }}; font-size:12px; line-height:1.5; color:{{ $mutedColor }};">
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
