@component('mail::message')
# Your Account Password

Dear {{ $user->name }},

Your account has been created successfully. Below are your login credentials.

@component('mail::panel')
## Account Information

**Name:** {{ $user->name }}

**Email:** {{ $user->email }}

**Password:** {{ $password }}

@if($role)
**Role:** {{ $role->name }}
@endif

@if($user->phone)
**Phone:** {{ $user->phone }}
@endif

⚠️ **IMPORTANT:** Please change your password immediately after your first login for security reasons.
@endcomponent

@component('mail::button', ['url' => config('app.frontend_url', config('app.url')) . '/login'])
Login to Your Account
@endcomponent

## 🔒 Security Precautions
- **Change your password** as soon as you log in
- **Use a strong password** with letters, numbers, and symbols
- **Don't share** your login credentials with anyone
- **Keep this email secure** and delete it after changing your password

@if($createdBy)
---
**Account created by:** {{ $createdBy->name }} ({{ $createdBy->email }})
@endif

Thank you,<br>
{{ config('app.name') }} Team
@endcomponent
