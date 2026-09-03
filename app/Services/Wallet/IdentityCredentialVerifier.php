<?php

namespace App\Services\Wallet;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use RuntimeException;

class IdentityCredentialVerifier
{
    /**
     * Verify a Node-issued JWT-VC against the locally pinned issuer trust anchor.
     */
    public function verify(string $token, string $expectedDid, string $expectedType): array
    {
        $token = trim($token);
        $expectedDid = trim($expectedDid);
        if ($token === '' || $expectedDid === '') {
            throw new RuntimeException('identity_credential_invalid');
        }

        ['issuer' => $issuer, 'jwks' => $jwks] = $this->trustBundle();
        $decoded = JWT::decode($token, JWK::parseKeySet($jwks));
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

        return $claims;
    }

    private function trustBundle(): array
    {
        $directory = rtrim(trim((string)config('dootask.identity_trust_dir', '')), DIRECTORY_SEPARATOR);
        if ($directory === '') throw new RuntimeException('identity_trust_directory_not_configured');
        $metadataPath = $directory . DIRECTORY_SEPARATOR . 'issuer-metadata.json';
        $jwksPath = $directory . DIRECTORY_SEPARATOR . 'jwks.json';
        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
        foreach ([$metadataPath, $jwksPath, $manifestPath] as $path) {
            if (!is_file($path) || !is_readable($path)) throw new RuntimeException('identity_trust_bundle_unavailable');
        }
        $metadata = json_decode((string)file_get_contents($metadataPath), true);
        $jwks = json_decode((string)file_get_contents($jwksPath), true);
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        if (!is_array($metadata) || !is_array($jwks) || !is_array($manifest)) throw new RuntimeException('identity_trust_bundle_invalid');
        if (!hash_equals((string)($manifest['metadataSha256'] ?? ''), hash_file('sha256', $metadataPath))
            || !hash_equals((string)($manifest['jwksSha256'] ?? ''), hash_file('sha256', $jwksPath))) {
            throw new RuntimeException('identity_trust_bundle_checksum_mismatch');
        }
        $issuer = trim((string)($metadata['issuer'] ?? ''));
        if ($issuer === '' || $issuer !== ($manifest['issuer'] ?? '')) throw new RuntimeException('identity_issuer_invalid');
        if (!is_array($jwks['keys'] ?? null) || $jwks['keys'] === []) throw new RuntimeException('identity_issuer_jwks_invalid');
        return ['issuer' => $issuer, 'jwks' => $jwks];
    }
}
