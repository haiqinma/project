<?php

namespace App\Services\Wallet;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class IdentityCredentialVerifier
{
    private const JWKS_TTL = 300;

    /**
     * Verify a Node-issued JWT-VC and its active status before using claims.
     */
    public function verify(string $token, string $expectedDid, string $expectedType): array
    {
        $token = trim($token);
        $expectedDid = trim($expectedDid);
        if ($token === '' || $expectedDid === '') {
            throw new RuntimeException('identity_credential_invalid');
        }

        $metadata = $this->metadata();
        $issuer = $metadata['issuer'];
        $decoded = JWT::decode($token, JWK::parseKeySet($this->jwks($metadata)));
        $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
        if (($claims['iss'] ?? '') !== $issuer || ($claims['sub'] ?? '') !== $expectedDid) {
            throw new RuntimeException('identity_credential_subject_mismatch');
        }
        if (empty($claims['jti']) || empty($claims['exp']) || intval($claims['exp']) <= time() || (!empty($claims['nbf']) && intval($claims['nbf']) > time())) {
            throw new RuntimeException('identity_credential_expired');
        }

        $types = $claims['vc']['type'] ?? [];
        if (!is_array($types) || !in_array('VerifiableCredential', $types, true) || !in_array($expectedType, $types, true)) {
            throw new RuntimeException('identity_credential_type_mismatch');
        }

        $status = $this->status($metadata, $issuer, (string)$claims['jti']);
        if (($status['status'] ?? '') !== 'active') {
            throw new RuntimeException('identity_credential_not_active');
        }
        return $claims;
    }

    private function metadata(): array
    {
        $base = rtrim(trim((string)config('dootask.passport_node_url', '')), '/');
        if ($base === '') throw new RuntimeException('identity_issuer_not_configured');
        $key = 'identity_issuer_metadata:' . hash('sha256', $base);
        return Cache::remember($key, self::JWKS_TTL, function () {
            $response = (new Client(['timeout' => 10, 'connect_timeout' => 5]))->get($this->nodeUrl() . '/.well-known/openid-credential-issuer');
            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data) || !is_string($data['issuer'] ?? null) || !is_string($data['jwks_uri'] ?? null) || !is_string($data['credential_status_uri'] ?? null)) throw new RuntimeException('identity_issuer_metadata_invalid');
            return $data;
        });
    }

    private function nodeUrl(): string
    {
        $base = rtrim(trim((string)config('dootask.passport_node_url', '')), '/');
        if ($base === '') throw new RuntimeException('identity_issuer_not_configured');
        return $base;
    }

    private function jwks(array $metadata): array
    {
        $key = 'identity_issuer_jwks:' . hash('sha256', $metadata['issuer']);
        return Cache::remember($key, self::JWKS_TTL, function () use ($metadata) {
            $response = (new Client(['timeout' => 10, 'connect_timeout' => 5]))->get($metadata['jwks_uri']);
            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data) || !is_array($data['keys'] ?? null) || $data['keys'] === []) throw new RuntimeException('identity_issuer_jwks_invalid');
            return $data;
        });
    }

    private function status(array $metadata, string $issuer, string $credentialId): array
    {
        $response = (new Client(['timeout' => 10, 'connect_timeout' => 5]))->post($metadata['credential_status_uri'], [
            'json' => ['issuer' => $issuer, 'credentials' => [$credentialId]],
        ]);
        $data = json_decode((string)$response->getBody(), true);
        $status = $data['data']['statuses'][$credentialId] ?? null;
        if (!is_string($status)) throw new RuntimeException('identity_credential_status_unavailable');
        return ['status' => $status];
    }
}
