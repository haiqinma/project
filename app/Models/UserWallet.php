<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWallet extends AbstractModel
{
    protected $table = 'user_wallets';

    protected $fillable = [
        'userid',
        'chain',
        'chain_id',
        'address',
        'address_normalized',
        'wallet_identity_id',
        'last_login_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid', 'userid');
    }
}
