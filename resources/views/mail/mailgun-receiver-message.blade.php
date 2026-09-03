{{--
    Wrapper around a credential's authored message body.

    The body is the credential's `message_html` — rendered from its template
    fields by MailgunReceiverTemplate::render() at save time — emitted with
    {!! !!} because it IS markup. Its dynamic parts were escaped in
    MailgunReceiverMessage::personalise() before substitution, so imported
    spreadsheet values cannot inject into it.

    The unsubscribe block below is appended by this layout, NOT by the authored
    template, so it is present on every message regardless of what was written in
    the admin. Removing it requires changing this file, which is the point.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f1f1f1;">

{!! $bodyHtml !!}

{{-- Centred in the same 600px column the body renders in, on the credential's own
     background colour, so the opt-out reads as part of the message rather than as
     something bolted on after it. The markup is here and not in the template for
     the reason stated above. --}}
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#f1f1f1" style="border-collapse:collapse; background-color:#f1f1f1;">
    <tbody>
    <tr>
        <td align="center" valign="top" style="padding:0;">
            <!--[if mso]><table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600"><tr><td><![endif]-->
            <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="{{ $backgroundColor }}" style="border-collapse:collapse; max-width:600px; background-color:{{ $backgroundColor }};">
                <tbody>
                <tr>
                    <td align="center" style="padding:0 20px 30px; text-align:center; font-family:Arial,Helvetica,sans-serif; font-size:12px; line-height:1.5; color:{{ $mutedColor }};">
                        <a href="{{ $unsubscribeUrl }}" style="color:{{ $accentColor }}; text-decoration:underline;">Unsubscribe from these emails</a>
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
