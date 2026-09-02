{{--
    Mailgun credential connection test.

    Diagnostic only. The admin triggered this seconds ago and typed the
    destination address themselves, so it states plainly what it is, asserts
    nothing about the recipient, and carries no tracking and no unsubscribe
    footer. Everything is escaped by Blade; nothing is rendered raw.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mailgun connection test</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#222222;">

<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;margin:0 auto;background:#ffffff;">
    <tr>
        <td style="padding:28px;">

            <p style="margin:0 0 16px;font-size:16px;font-weight:bold;">
                Mailgun connection test
            </p>

            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                This is an automated message sent from your admin panel to check that the Mailgun
                credential below can authenticate and deliver.
            </p>

            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                If you are reading this, the credential works. Nothing was sent to anyone else, and
                no action is needed.
            </p>

            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="font-size:14px;margin:0 0 16px;">
                <tr>
                    <td style="padding:5px 0;color:#666666;width:140px;">Credential</td>
                    <td style="padding:5px 0;">{{ $credentialName }}</td>
                </tr>
                <tr>
                    <td style="padding:5px 0;color:#666666;">Sending domain</td>
                    <td style="padding:5px 0;">{{ $domain }}</td>
                </tr>
                <tr>
                    <td style="padding:5px 0;color:#666666;">API region</td>
                    <td style="padding:5px 0;">{{ $region }}</td>
                </tr>
                <tr>
                    <td style="padding:5px 0;color:#666666;">Sent</td>
                    <td style="padding:5px 0;">{{ $sentAt }}</td>
                </tr>
            </table>

            <p style="margin:24px 0 0;padding-top:14px;border-top:1px solid #e0e0e0;font-size:12px;color:#999999;">
                Automated diagnostic message. You received it because this address was entered in the
                admin panel's Mailgun credential test.
            </p>

        </td>
    </tr>
</table>

</body>
</html>
