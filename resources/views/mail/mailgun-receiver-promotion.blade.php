{{--
    The receiver message layout.

    Block order and construction follow the promotion email
    (mail/promotion/offer.blade.php): copy first so the message reads with images
    blocked, then the call to action, then the banner, then the fine print. Every
    visual rule is inline, because a number of clients strip <style> entirely.

    This is a FRAGMENT. mail/mailgun-receiver-message.blade.php wraps it in
    <html>/<body> and appends the unsubscribe block — which is why no unsubscribe
    link is authored here, and why nothing an admin types can remove one.

    Nothing in this file names a site. There is no logo, no brand, no site URL and
    no default copy: $t comes entirely from what the admin typed into the
    credential's settings, defaulted by MailgunReceiverTemplate::defaults().

    RICH_FIELDS (intro_text/secondary_text/disclaimer_text/footer_text) were
    escaped and **bold**-converted in MailgunReceiverTemplate::render(), so they
    are emitted with {!! !!}. Every other string goes through {{ }}.
--}}
@php
    $face  = "'DM Sans',-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif";
    $block = 'border-collapse:collapse;';

    // A block renders only when its own field has content. There is no separate
    // visibility flag: an empty field IS the off switch, which keeps the settings
    // modal to one control per block.
    $has = fn (string $key): bool => trim((string) ($t[$key] ?? '')) !== '';
@endphp

@if ($has('preheader'))
    {{-- Hidden preview text — the line the inbox lists under the subject. --}}
    <div style="display:none!important; visibility:hidden; opacity:0; height:0; width:0; font-size:0; line-height:0; color:transparent; overflow:hidden;">{{ $t['preheader'] }}</div>
@endif

<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#f1f1f1" style="{{ $block }} background-color:#f1f1f1;">
    <tbody>
    <tr>
        <td align="center" valign="top" style="padding:0;">

            <!--[if mso]><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"><tr><td><![endif]-->
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="{{ $t['background_color'] }}" style="{{ $block }} max-width:600px; background-color:{{ $t['background_color'] }};">
                <tbody>
                <tr>
                    {{-- One master cell; every block below is a self-contained table
                         stacked inside it, so blocks abut with no seam whichever
                         ones are present. --}}
                    <td valign="top" bgcolor="{{ $t['background_color'] }}" style="padding:0; background-color:{{ $t['background_color'] }};">

                        {{-- Copy first: heading and both paragraphs. --}}
                        @if ($has('heading') || $has('intro_text') || $has('secondary_text'))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:30px 20px; text-align:center; color:{{ $t['text_color'] }}; font-family:{{ $face }};">
                                        @if ($has('heading'))
                                            <h2 style="margin:0 0 20px; font-size:24px; font-weight:600; line-height:1.4; color:{{ $t['heading_color'] }};">{{ $t['heading'] }}</h2>
                                        @endif

                                        @if ($has('intro_text'))
                                            <p style="margin:0 0 20px; font-size:17px; line-height:1.6; color:{{ $t['text_color'] }};">{!! $t['intro_text'] !!}</p>
                                        @endif

                                        @if ($has('secondary_text'))
                                            <p style="margin:0; font-size:16px; line-height:1.6; color:{{ $t['secondary_text_color'] }};">{!! $t['secondary_text'] !!}</p>
                                        @endif
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Call to action, above the banner so the message has one
                             before the image rather than only after it. Renders as an
                             unlinked pill when no URL was given — the pill is painted
                             by the cell, not the anchor, because Outlook ignores
                             background-color on inline anchors. --}}
                        @if ($has('button_text'))
                            @php
                                $labelType = 'padding:14px 48px; font-family:' . $face
                                    . '; font-size:' . (int) $t['button_text_font_size']
                                    . 'px; font-weight:600; color:#ffffff; -webkit-text-fill-color:#ffffff;';
                            @endphp
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:4px 20px 20px;">
                                        <table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" style="border-collapse:separate; border-spacing:0;">
                                            <tbody>
                                            <tr>
                                                <td align="center" bgcolor="{{ $t['button_color'] }}" style="background-color:{{ $t['button_color'] }}; border-radius:8px;">
                                                    @if ($has('button_url'))
                                                        <a href="{{ $t['button_url'] }}" target="_blank" rel="nofollow noopener"
                                                           style="display:inline-block; text-decoration:none; {{ $labelType }}">{{ $t['button_text'] }}</a>
                                                    @else
                                                        <span style="display:inline-block; {{ $labelType }}">{{ $t['button_text'] }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Banner. Linked only when a destination was given; the alt
                             text falls back to the heading so an images-off client
                             still shows words rather than a broken-image box. --}}
                        @if ($has('hero_image_url'))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:0; font-size:0; line-height:0;">
                                        @if ($has('hero_url'))
                                            <a href="{{ $t['hero_url'] }}" target="_blank" rel="nofollow noopener"><img
                                                    src="{{ $t['hero_image_url'] }}" width="600"
                                                    alt="{{ $t['heading'] }}"
                                                    style="display:block; width:100%; max-width:600px; height:auto; border:0;"></a>
                                        @else
                                            <img src="{{ $t['hero_image_url'] }}" width="600"
                                                 alt="{{ $t['heading'] }}"
                                                 style="display:block; width:100%; max-width:600px; height:auto; border:0;">
                                        @endif
                                    </td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        @if ($has('disclaimer_text'))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:30px 20px 10px; text-align:center; font-family:{{ $face }}; font-size:13px; line-height:1.6; color:{{ $t['muted_text_color'] }};">{!! $t['disclaimer_text'] !!}</td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Footer identity. Free text rather than named address and
                             contact fields, because what belongs here depends on the
                             legal entity behind the sending domain — which this
                             platform does not know and must not assume. --}}
                        @if ($has('footer_text'))
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                                <tbody>
                                <tr>
                                    <td align="center" style="padding:0 20px 8px; text-align:center; font-family:{{ $face }}; font-size:11px; line-height:1.5; color:{{ $t['muted_text_color'] }};">{!! $t['footer_text'] !!}</td>
                                </tr>
                                </tbody>
                            </table>
                        @endif

                        {{-- Bottom padding stands in for the unsubscribe block, which
                             the wrapper appends immediately after this fragment. --}}
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="{{ $block }}">
                            <tbody>
                            <tr>
                                <td style="padding:0 20px 20px; font-size:0; line-height:0;">&nbsp;</td>
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
