<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationToken extends AbstractModel
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_EXPIRED = 'expired';

    public const SCOPE_FILE_CABINET = 'file_cabinet';

    public const AVAILABLE_SCOPES = [
        self::SCOPE_FILE_CABINET,
    ];

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

    public function allowsScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public static function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            if (!is_scalar($scope)) {
                continue;
            }
            $scope = trim((string) $scope);
            if ($scope === '' || !in_array($scope, self::AVAILABLE_SCOPES, true) || in_array($scope, $normalized, true)) {
                continue;
            }
            $normalized[] = $scope;
        }
        return $normalized;
    }

    public static function scopesAreValid(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (!is_scalar($scope)) {
                return false;
            }
            $scope = trim((string) $scope);
            if ($scope !== '' && !in_array($scope, self::AVAILABLE_SCOPES, true)) {
                return false;
            }
        }
        return true;
    }

    private function decodeArrayAttribute($value): array
    {
        for ($i = 0; $i < 2 && is_string($value); $i++) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : [];
    }
}
