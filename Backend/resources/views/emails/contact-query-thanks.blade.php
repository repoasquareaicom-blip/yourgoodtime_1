<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Thank you - Your Good Time</title>
</head>
<body style="margin:0;padding:0;background:#080b18;color:#2a1806;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#080b18;padding:28px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff8ec;border-radius:18px;overflow:hidden;border:1px solid #f2c874;">
                    <tr>
                        <td style="padding:28px 26px 12px;text-align:center;background:linear-gradient(135deg,#1d315f,#3b1d08);color:#ffe8bf;">
                            <h1 style="margin:0;font-size:28px;line-height:1.1;">Your Good Time</h1>
                            <p style="margin:8px 0 0;color:#f5d59e;font-size:14px;">We received your query</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 26px;text-align:left;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#513314;">Thank you for writing to us. Our team will review your query and get back to you at {{ $contactQuery->email }}.</p>
                            <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#8a520d;text-transform:uppercase;">Your message</p>
                            <p style="margin:0 0 18px;font-size:15px;line-height:1.55;color:#513314;white-space:pre-wrap;">{{ $contactQuery->message }}</p>
                            <p style="margin:0;font-size:13px;line-height:1.5;color:#7d6649;">If you did not submit this query, you can ignore this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
