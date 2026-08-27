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
     them. Every optional component is dropped, with its spacing, when the admin clears it.

     BLOCK ORDER is deliberate: the heading and offer come BEFORE the banner, so the
     message reads even when a client blocks images (Outlook, most corporate mail) — the
     top of the email is never empty. The CTA sits directly ABOVE the offer "ticket", so
     the action is reachable without reading past the terms. --}}
@php
    $face = "Arial, Helvetica, sans-serif";
    $block = 'border-collapse:collapse;';
    $terms = $t['offer_terms'] ?? [];
    $termWidth = count($terms) > 0 ? round(100 / count($terms), 4) : 100;

    // OPTIONAL BLOCKS. Every removable part of this email is hidden by the admin
    // switching it OFF, never by clearing its text — the wording stays in the row
    // so restoring is a toggle rather than a retype. $visible comes from
    // VerificationPromotionEmail::visibleBlocks(); a block absent from it defaults
    // to visible, which is what keeps existing rows rendering unchanged.
    //
    // $show — render this block? (switched on AND has content)
    // $val  — its value, or '' when switched off, for the places a field is used
    //         as a value rather than a guard (links).
    $show = fn (string $key): bool => ($visible[$key] ?? true) && ! empty($t[$key]);
    $val  = fn (string $key): string => ($visible[$key] ?? true) ? (string) ($t[$key] ?? '') : '';

    // BOTH BUTTONS ARE THIS WIDE — the one above the banner and the one below
    // it. Fixed rather than shrink-to-fit on purpose: sized to their labels, the
    // two came out visibly different widths ("Get Bonus" against "Claim My 100
    // Free Spins") and read as two unrelated controls stacked down the email.
    //
    // One number, used twice. 280px sits inside the 600px body's 536px of
    // content at every width, so it never has to wrap or scroll, and it is wide
    // enough for the longest label in use. Change it here and both move.
    $btnWidth = 280;
@endphp
<body style="margin:0; padding:0; background-color:{{ $canvas }}; font-family:{{ $face }};">

{{-- Hidden preview (preheader) text — removable --}}
@if ($show('preheader'))
    <div style="display:none!important; visibility:hidden; opacity:0; height:0; width:0; font-size:0; line-height:0; color:transparent; overflow:hidden;">{{ $t['preheader'] }}</div>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $canvas }}" style="{{ $block }} background-color:{{ $canvas }}; padding:24px 0;">
    <tr>
        <td align="center" style="padding:24px 0;">

            <!--[if mso]><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"><tr><td><![endif]-->
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="{{ $bodyBg }}" style="{{ $block }} background-color:{{ $bodyBg }}; border-radius:8px; overflow:hidden; max-width:600px; width:100%;">

                @php
                    // THE TWO GREEN BANDS. The brand band and the confirmation
                    // strip below it are usually set to the same colour, so they
                    // read as ONE block — and the gap between their two lines of
                    // text is this band's BOTTOM padding plus that strip's TOP
                    // padding, two values that add together.
                    //
                    // So they are tightened toward each other ONLY while both
                    // render. Whichever one stands alone takes symmetric padding
                    // instead: an inner value tuned to sit against a neighbour
                    // leaves the text visibly off-centre in its own band once
                    // that neighbour is removed — which is exactly what removing
                    // the confirmation line used to do to the brand.
                    $brandShown   = $show('header_brand_text');
                    $confirmShown = $show('confirmation_text');

                    $brandPadBottom  = $confirmShown ? '3px' : '14px';
                    $confirmPadTop   = $brandShown ? '2px' : '8px';
                @endphp

                {{-- Header brand band — removable --}}
                @if ($brandShown)
                    <tr>
                        <td style="background-color:{{ $headerColor }}; padding:14px 32px {{ $brandPadBottom }}; text-align:center;">
                            <a href="{{ $val('hero_url') ?: $siteUrl }}" target="_blank" rel="nofollow sponsored noopener" style="color:#ffffff; font-size:20px; font-weight:bold; letter-spacing:0.5px; text-decoration:none; text-transform:uppercase;">{{ $t['header_brand_text'] }}</a>
                        </td>
                    </tr>
                @endif

                {{-- Confirmation strip — one thin line stating the fact the reader
                     already knows (their email is confirmed), so the heading can
                     talk about the offer instead. Removable. --}}
                @if ($confirmShown)
                    <tr>
                        <td style="background-color:{{ $accent }}; padding:{{ $confirmPadTop }} 24px 8px; text-align:center;">
                            <span style="font-size:12px; font-weight:bold; color:#ffffff; letter-spacing:0.3px;">{{ $t['confirmation_text'] }}</span>
                        </td>
                    </tr>
                @endif

                {{-- Body TOP — heading + intro come before the banner so the email
                     reads with images off. --}}
                {{-- Section guards include every block inside them, or hiding the
                     only populated one would still emit an empty padded row. --}}
                @if ($show('eyebrow_text') || $show('heading') || ! empty($greeting) || $show('intro_text'))
                    <tr>
                        <td style="padding:32px 32px 20px; font-family:{{ $face }};">
                            @if ($show('eyebrow_text'))
                                <p style="margin:0 0 8px 0; font-size:14px; color:{{ $accent }}; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px;">{{ $t['eyebrow_text'] }}</p>
                            @endif

                            @if ($show('heading'))
                                <h1 style="margin:0 0 10px 0; font-size:26px; color:{{ $headingColor }}; line-height:1.3;">{{ $t['heading'] }}</h1>
                            @endif

                            @if (! empty($greeting))
                                <p style="margin:0 0 16px 0; font-size:16px; color:{{ $textColor }}; line-height:1.6;">{{ $greeting }}</p>
                            @endif

                            {{-- Intro paragraph. Size is operator-controlled (falling
                                 back to 16px), and a background colour turns it into a
                                 padded panel like the responsible-gambling notice.
                                 Unset background = the plain paragraph, so an existing
                                 row gains no stray box. --}}
                            @if ($show('intro_text'))
                                @php $introSize = $t['intro_text_font_size'] ?? 16; @endphp
                                @if (! empty($t['intro_text_background_color']))
                                    {{-- PANEL FORM. The padding here is what sets this
                                         paragraph in from the heading above it — a panel
                                         cannot have its text flush with its own edge. It
                                         is kept small (10px/12px, not 16px/20px) so the
                                         indent reads as a panel rather than as a
                                         misalignment.

                                         If you want the intro to start on EXACTLY the
                                         same left edge as the heading, clear the
                                         background colour: that renders the plain
                                         paragraph below, with no wrapper and no inset. --}}
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $block }} background-color:{{ $t['intro_text_background_color'] }}; border-radius:6px;">
                                        <tr>
                                            <td style="padding:10px 12px;">
                                                <p style="margin:0; font-size:{{ $introSize }}px; color:{{ $textColor }}; line-height:1.6;">{!! $t['intro_text'] !!}</p>
                                            </td>
                                        </tr>
                                    </table>
                                @else
                                    <p style="margin:0; font-size:{{ $introSize }}px; color:{{ $textColor }}; line-height:1.6;">{!! $t['intro_text'] !!}</p>
                                @endif
                            @endif
                        </td>
                    </tr>
                @endif

                {{-- TOP button — ABOVE the banner, so the offer has a call to action
                     before the image rather than two stacked underneath it. The lower
                     CTA stays below the banner, giving one action either side.
                     Its label was editable, stored and returned by the API long before
                     this markup existed, so anything typed there rendered nowhere;
                     that is what this block fixes. Removable like every other block,
                     with its own destination falling back to the banner link then the
                     site. --}}
                @if ($show('top_button_text'))
                    <tr>
                        {{-- 4px above, 20px below: the body block already contributes
                             20px underneath its text, and the banner that follows has
                             none of its own. --}}
                        <td align="center" style="padding:4px 32px 20px; font-family:{{ $face }};">
                            {{-- Fixed width, and `display:block` on the anchor so it
                                 fills the cell: that is what makes this button and the
                                 one below the banner identical regardless of label
                                 length, while keeping the whole pill clickable. --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $block }} margin:0 auto;">
                                <tr>
                                    <td align="center" width="{{ $btnWidth }}" style="width:{{ $btnWidth }}px; border-radius:6px; background-color:{{ $buttonColor }};">
                                        <a href="{{ $val('top_button_url') ?: ($val('hero_url') ?: $siteUrl) }}" target="_blank" rel="nofollow sponsored noopener" style="display:block; padding:14px 12px; font-size:16px; color:#ffffff; text-decoration:none; font-weight:bold; text-align:center;">{{ $t['top_button_text'] }}</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                {{-- Banner — a short 600×300 band, AFTER the heading. Recommended
                     source image: 600×300. Removable; linked to the offer when set. --}}
                @if ($show('hero_image_url'))
                    <tr>
                        <td style="font-size:0; line-height:0;">
                            @if ($show('hero_url'))
                                <a href="{{ $t['hero_url'] }}" target="_blank" rel="nofollow sponsored noopener"><img src="{{ $t['hero_image_url'] }}" alt="{{ $val('heading') ?: $siteName }}" width="600" height="300" style="display:block; width:100%; max-width:600px; height:auto; border:0;"></a>
                            @else
                                <img src="{{ $t['hero_image_url'] }}" alt="{{ $val('heading') ?: $siteName }}" width="600" height="300" style="display:block; width:100%; max-width:600px; height:auto; border:0;">
                            @endif
                        </td>
                    </tr>
                @endif

                {{-- CTA + offer TICKET — the button first, then the amount headline
                     over the terms a subscriber checks before clicking. Button-first is
                     deliberate: the reader has already been told the offer above, so the
                     action comes before the small print rather than after it. --}}
                @if ($show('highlight_text') || ! empty($terms) || $show('cta_button_text'))
                    <tr>
                        <td style="padding:24px 32px 8px; font-family:{{ $face }};">

                            {{-- CTA button — ABOVE the offer ticket. No top margin: the
                                 cell's own 24px padding already spaces it from the block
                                 above, and doubling up left it floating. The 12px below
                                 keeps it visually attached to the ticket it belongs to.
                                 Removable. --}}
                            @if ($show('cta_button_text'))
                                {{-- Same fixed width as the top button — see $btnWidth. --}}
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $block }} margin:0 auto 12px;">
                                    <tr>
                                        <td align="center" width="{{ $btnWidth }}" style="width:{{ $btnWidth }}px; border-radius:6px; background-color:{{ $buttonColor }};">
                                            {{-- Own destination, falling back to the banner link
                                                 and then the site. Existing rows have no
                                                 cta_button_url, so they keep their current target. --}}
                                            <a href="{{ $val('cta_button_url') ?: ($val('hero_url') ?: $siteUrl) }}" target="_blank" rel="nofollow sponsored noopener" style="display:block; padding:14px 12px; font-size:16px; color:#ffffff; text-decoration:none; font-weight:bold; text-align:center;">{{ $t['cta_button_text'] }}</a>
                                        </td>
                                    </tr>
                                </table>
                            @endif

                            @if ($show('highlight_text') || ! empty($terms))
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $block }} background-color:{{ $canvas }}; border:1px solid #e5e7eb; border-radius:8px;">
                                    {{-- Ticket head: the bonus amount headline --}}
                                    @if ($show('highlight_text'))
                                        <tr>
                                            <td align="center" style="padding:20px 20px 14px;">
                                                <p style="margin:0; font-size:26px; line-height:1.2; color:{{ $buttonColor }}; font-weight:bold;">{{ $t['highlight_text'] }}</p>
                                            </td>
                                        </tr>
                                    @endif

                                    {{-- Ticket stub: the three terms in equal columns,
                                         separated by a dashed "perforation". --}}
                                    @if (! empty($terms))
                                        <tr>
                                            <td style="padding:0 16px 16px;">
                                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $block }} border-top:1px dashed #d1d5db;">
                                                    <tr>
                                                        @foreach ($terms as $term)
                                                            <td align="center" valign="top" width="{{ $termWidth }}%" style="padding:14px 6px 0; width:{{ $termWidth }}%;">
                                                                <div style="font-size:16px; font-weight:bold; color:{{ $headingColor }};">{{ $term['value'] }}</div>
                                                                <div style="margin-top:3px; font-size:11px; color:{{ $mutedColor }}; text-transform:uppercase; letter-spacing:0.4px;">{{ $term['label'] }}</div>
                                                            </td>
                                                        @endforeach
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    @endif
                                </table>
                            @endif

                        </td>
                    </tr>
                @endif

                {{-- Body BOTTOM — reassurance + fine print, after the offer. --}}
                @if ($show('secondary_text') || $show('disclaimer_text'))
                    <tr>
                        <td style="padding:8px 32px 24px; font-family:{{ $face }};">
                            @if ($show('secondary_text'))
                                <p style="margin:0; font-size:16px; color:{{ $secondaryColor }}; line-height:1.6;">{!! $t['secondary_text'] !!}</p>
                            @endif
                            @if ($show('disclaimer_text'))
                                <p style="margin:16px 0 0 0; font-size:13px; color:{{ $mutedColor }}; line-height:1.5;">{!! $t['disclaimer_text'] !!}</p>
                            @endif
                        </td>
                    </tr>
                @endif

                {{-- Responsible gambling notice — removable --}}
                @if ($show('responsible_notice_text'))
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

                {{-- Footer. Order and content follow the CAN-SPAM / deliverability
                     spec: tagline → nav → affiliate disclosure → reason-for-receipt
                     → Email preferences + Unsubscribe (equal weight) → 18+ line →
                     postal address + monitored contact → copyright. Links use their
                     own higher-contrast colour so Unsubscribe is never buried. --}}
                <tr>
                    <td style="background-color:{{ $footerBg }}; padding:24px 32px; text-align:center; font-family:{{ $face }};">
                        @if ($show('footer_tagline'))
                            <p style="margin:0 0 10px 0; font-size:13px; line-height:1.5; color:{{ $footerColor }};">{!! $t['footer_tagline'] !!}</p>
                        @endif

                        @if (! empty($t['footer_links']))
                            <p style="margin:0 0 12px 0; font-size:12px; color:{{ $footerLink }};">
                                @foreach ($t['footer_links'] as $link)
                                    <a href="{{ $link['url'] }}" target="_blank" rel="noopener" style="color:{{ $footerLink }}; text-decoration:underline;">{{ $link['label'] }}</a>@unless ($loop->last) &nbsp;·&nbsp; @endunless
                                @endforeach
                            </p>
                        @endif

                        @if ($show('affiliate_disclosure_text'))
                            <p style="margin:0 0 8px 0; font-size:11px; line-height:1.5; color:{{ $footerColor }};">{!! $t['affiliate_disclosure_text'] !!}</p>
                        @endif

                        @if ($show('reason_text'))
                            <p style="margin:0 0 12px 0; font-size:11px; line-height:1.5; color:{{ $footerColor }};">{{ $t['reason_text'] }}</p>
                        @endif

                        {{-- Email preferences + Unsubscribe — same visual weight as
                             the nav links (never weaker; that drives spam reports).
                             Unsubscribe is structural and never removed. --}}
                        <p style="margin:0 0 12px 0; font-size:12px; color:{{ $footerLink }};">
                            @if ($show('email_preferences_label') && $show('email_preferences_url'))
                                <a href="{{ $t['email_preferences_url'] }}" target="_blank" rel="noopener" style="color:{{ $footerLink }}; text-decoration:underline;">{{ $t['email_preferences_label'] }}</a>&nbsp;·&nbsp;
                            @endif
                            <a href="{{ $unsubscribeUrl }}" style="color:{{ $footerLink }}; text-decoration:underline;">{{ $t['unsubscribe_label'] }}</a>
                        </p>

                        @if ($show('age_disclaimer_text'))
                            <p style="margin:0 0 10px 0; font-size:11px; line-height:1.5; color:{{ $footerColor }};">{{ $t['age_disclaimer_text'] }}</p>
                        @endif

                        @if ($show('postal_address') || $show('contact_email'))
                            @php $addressLine = implode(', ', array_filter([$siteName, $t['postal_address'] ?? ''])); @endphp
                            <p style="margin:0; font-size:11px; line-height:1.5; color:{{ $footerColor }};">{{ $addressLine }}@if ($show('contact_email')) &nbsp;·&nbsp; <a href="mailto:{{ $t['contact_email'] }}" style="color:{{ $footerLink }}; text-decoration:underline;">{{ $t['contact_email'] }}</a>@endif</p>
                        @endif

                        @if ($show('copyright_text'))
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
