<?php

namespace App\Contracts;

use App\Models\User;
use Illuminate\Http\Request;

interface AutomationTokenCreationAuthorizer
{
    /**
     * Implementations may require an additional wallet signature or external approval.
     */
    public function authorize(User $user, Request $request): void;
}
