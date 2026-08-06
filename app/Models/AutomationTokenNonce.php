<?php

namespace App\Models;

class AutomationTokenNonce extends AbstractModel
{
    public $timestamps = false;

    protected $casts = [
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
