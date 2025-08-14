<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UserAccountCreatedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $password;
    public $createdBy;
    public $role;
    public $accountType;

    public function __construct(User $user, string $password, ?User $createdBy = null)
    {
        try {
            $this->user = $user;
            $this->password = $password;
            $this->createdBy = $createdBy;

            // Since your job loads fresh(['role']), the role should be available
            $this->role = $user->role ?? null;

            // Determine account type for personalized messaging
            if ($this->role) {
                $this->accountType = $this->determineAccountType($this->role->name);
            } else {
                $this->accountType = 'standard';
            }

            // Log data for debugging
            Log::info('Account Created Mailable Constructor Data', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'email' => $user->email,
                'role_name' => $this->role ? $this->role->name : null,
                'account_type' => $this->accountType,
                'created_by' => $createdBy ? $createdBy->id : 'system',
                'has_role' => isset($this->role),
                'user_data' => [
                    'id' => $user->id,
                    'has_name' => isset($user->name),
                    'has_email' => isset($user->email),
                    'role_id' => $user->role_id ?? null
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error in UserAccountCreatedNotification constructor', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function build()
    {
        return $this->subject('Your Account Password - ' . config('app.name'))
            ->markdown('emails.auth.account_created');
    }

    private function determineAccountType($roleName)
    {
        $roleName = strtolower($roleName);

        if (str_contains($roleName, 'manager') || str_contains($roleName, 'supervisor')) {
            return 'manager';
        } elseif (str_contains($roleName, 'auditor') || str_contains($roleName, 'audit')) {
            return 'auditor';
        } elseif (str_contains($roleName, 'technician') || str_contains($roleName, 'tech')) {
            return 'technician';
        } else {
            return 'standard';
        }
    }
}
