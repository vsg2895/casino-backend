{{--
    Bulletproof CTA button.

    The pill is painted by the table cell (bgcolor + border-radius) rather than
    by the anchor itself, so Outlook — which ignores background-color on inline
    anchors — still renders the filled shape. The anchor keeps the padding, so
    the whole pill stays clickable.

    Renders as an unlinked pill when the offer URL was removed in the admin.

    @param  string       $label  Button text (already placeholder-substituted).
    @param  string|null  $url    Offer destination, or empty for no link.
    @param  string       $color  Fill colour (hex).
--}}
@php
    $face = "'DM Sans',-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif";
    $type = "padding:14px 48px; font-family:{$face}; font-size:18px; font-weight:600; color:#ffffff; -webkit-text-fill-color:#ffffff;";
@endphp
<table role="presentation" align="center" border="0" cellpadding="0" cellspacing="0" style="border-collapse:separate; border-spacing:0;">
    <tbody>
        <tr>
            <td align="center" bgcolor="{{ $color }}" style="background-color:{{ $color }}; border-radius:8px;">
                @if (! empty($url))
                    <a href="{{ $url }}" target="_blank" rel="nofollow sponsored noopener"
                       style="display:inline-block; text-decoration:none; {{ $type }}">{{ $label }}</a>
                @else
                    <span style="display:inline-block; {{ $type }}">{{ $label }}</span>
                @endif
            </td>
        </tr>
    </tbody>
</table>
