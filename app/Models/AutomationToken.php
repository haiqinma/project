<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationToken extends AbstractModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_EXPIRED = 'expired';

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected $hidden = ['secret_hash'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid', 'userid');
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?: [], true);
    }

    public function getScopesAttribute($value): array
    {
        return $this->decodeArrayAttribute($value);
    }

    public function getProjectIdsAttribute($value): array
    {
        return $this->decodeArrayAttribute($value);
    }

    public function allowsProject(int $projectId): bool
    {
        return in_array($projectId, array_map('intval', $this->project_ids ?: []), true);
    }

    private function decodeArrayAttribute($value): array
    {
        for ($i = 0; $i < 2 && is_string($value); $i++) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }
}
