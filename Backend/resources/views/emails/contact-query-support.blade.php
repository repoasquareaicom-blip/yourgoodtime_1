<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New website query</title>
</head>
<body style="margin:0;padding:0;background:#080b18;color:#2a1806;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#080b18;padding:28px 14px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#fff8ec;border-radius:18px;overflow:hidden;border:1px solid #f2c874;">
                    <tr>
                        <td style="padding:28px 26px 12px;text-align:center;background:linear-gradient(135deg,#1d315f,#3b1d08);color:#ffe8bf;">
                            <h1 style="margin:0;font-size:24px;line-height:1.1;">New website query</h1>
                            <p style="margin:8px 0 0;color:#f5d59e;font-size:14px;">Query #{{ $contactQuery->id }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 26px;text-align:left;font-size:15px;line-height:1.5;color:#513314;">
                            <p style="margin:0 0 10px;"><strong>Email:</strong> {{ $contactQuery->email }}</p>
                            <p style="margin:0 0 10px;"><strong>Phone:</strong> {{ $contactQuery->phone ?: 'Not provided' }}</p>
                            <p style="margin:0 0 10px;"><strong>Registered user:</strong> {{ $contactQuery->user_id ? 'Yes' : 'No' }}</p>
                            <p style="margin:0 0 10px;"><strong>Attachment:</strong> {{ $contactQuery->attachment_original_name ?: 'None' }}</p>
                            <p style="margin:16px 0 8px;font-size:13px;font-weight:700;color:#8a520d;text-transform:uppercase;">Message</p>
                            <p style="margin:0;white-space:pre-wrap;">{{ $contactQuery->message }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
