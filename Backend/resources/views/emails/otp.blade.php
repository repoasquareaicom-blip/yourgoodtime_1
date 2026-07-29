<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your Good Time OTP</title>
</head>
<body style="margin:0;padding:0;background:#080b18;color:#2a1806;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#080b18;padding:28px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff8ec;border-radius:18px;overflow:hidden;border:1px solid #f2c874;">
                    <tr>
                        <td style="padding:28px 26px 12px;text-align:center;background:linear-gradient(135deg,#1d315f,#3b1d08);color:#ffe8bf;">
                            <h1 style="margin:0;font-size:28px;line-height:1.1;">Your Good Time</h1>
                            <p style="margin:8px 0 0;color:#f5d59e;font-size:14px;">Secure one-time password</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 26px;text-align:center;">
                            <p style="margin:0 0 18px;font-size:16px;line-height:1.5;color:#513314;">{{ $messageText }}</p>
                            <div style="display:inline-block;letter-spacing:8px;font-size:34px;font-weight:800;color:#1d315f;background:#fff0d0;border:1px solid #efbd55;border-radius:14px;padding:16px 18px;margin:4px 0 18px;">
                                {{ $otp }}
                            </div>
                            <p style="margin:0 0 14px;font-size:14px;color:#6b4a20;">This OTP expires in {{ $expiresMinutes }} {{ $expiresMinutes === 1 ? 'minute' : 'minutes' }}.</p>
                            <p style="margin:0 0 14px;font-size:14px;font-weight:700;color:#9a3b1f;">Do not share this OTP with anyone.</p>
                            <p style="margin:0;font-size:13px;line-height:1.5;color:#7d6649;">If you did not request this OTP, you can safely ignore this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
