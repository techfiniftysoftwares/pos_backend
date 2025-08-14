<?php

namespace App\Jobs;

use App\Mail\UserAccountCreatedNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendUserAccountCreatedNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $user;
    protected $password;
    protected $createdBy;

    public function __construct(User $user, string $password, ?User $createdBy = null)
    {
        $this->user = $user;
        $this->password = $password;
        $this->createdBy = $createdBy;
    }

    public function handle()
    {
        try {
            if ($this->user && $this->user->email && filter_var($this->user->email, FILTER_VALIDATE_EMAIL)) {
                // Reload the user with fresh relationships to ensure we have the latest data
                $user = $this->user->fresh(['role']);

                Mail::to($user->email)
                    ->send(new UserAccountCreatedNotification(
                        $user,
                        $this->password,
                        $this->createdBy
                    ));

                Log::info('Account created notification sent successfully', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'user_name' => $user->name,
                    'created_by' => $this->createdBy ? $this->createdBy->id : 'system',
                    'role_id' => $user->role_id
                ]);
            } else {
                Log::warning('Account created notification skipped - invalid email', [
                    'user_id' => $this->user->id,
                    'email' => $this->user->email,
                    'user_name' => $this->user->name
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to send account created notification email', [
                'user_id' => $this->user->id,
                'user_email' => $this->user->email,
                'user_name' => $this->user->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::error('Account created notification job failed', [
            'user_id' => $this->user->id,
            'user_email' => $this->user->email,
            'user_name' => $this->user->name,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
