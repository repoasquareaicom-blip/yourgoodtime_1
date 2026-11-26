New website query #{{ $contactQuery->id }}

Email: {{ $contactQuery->email }}
Phone: {{ $contactQuery->phone ?: 'Not provided' }}
Registered user: {{ $contactQuery->user_id ? 'Yes' : 'No' }}
Attachment: {{ $contactQuery->attachment_original_name ?: 'None' }}

Message:
{{ $contactQuery->message }}
