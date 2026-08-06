<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationTokenAudit extends AbstractModel
{
    public $timestamps = false;

    protected $casts = ['created_at' => 'datetime'];

    public function token(): BelongsTo
    {
        return $this->belongsTo(AutomationToken::class, 'token_id');
    }
}
