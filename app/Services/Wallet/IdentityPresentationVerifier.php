<?php

namespace App\Services\Wallet;

use RuntimeException;

class IdentityPresentationVerifier
{
    public function verify(array $presentation, array $options): array
    {
        if (($presentation['version'] ?? null) !== 1
            || !is_string($presentation['holder'] ?? null)
            || !is_array($presentation['scopes'] ?? null)
            || !is_array($presentation['proof'] ?? null)) {
            throw new RuntimeException('identity_presentation_invalid');
        }
        if (!preg_match('/^did:yeying:wid_[A-Za-z0-9_-]{22,}$/', $presentation['holder'])) {
            throw new RuntimeException('identity_presentation_invalid');
        }

        $proof = $presentation['proof'];
        if (($proof['type'] ?? '') !== 'YeyingIdentityPresentationProofV1'
            || ($proof['purpose'] ?? '') !== 'authentication'
            || !is_string($proof['verificationMethod'] ?? null)
            || !is_string($proof['proofValue'] ?? null)) {
            throw new RuntimeException('identity_presentation_invalid');
        }

        if (($presentation['audience'] ?? '') !== ($options['audience'] ?? '')
            || ($presentation['nonce'] ?? '') !== ($options['nonce'] ?? '')) {
            throw new RuntimeException('identity_presentation_context_mismatch');
        }

        $requested = array_values(array_unique(array_map('strval', $presentation['scopes'])));
        foreach (($options['scopes'] ?? []) as $scope) {
            if (!in_array($scope, $requested, true)) {
                throw new RuntimeException('identity_presentation_scope_mismatch');
            }
        }

        $skew = 60;
        $issuedAt = strtotime((string)($presentation['issuedAt'] ?? ''));
        $expiresAt = strtotime((string)($presentation['expiresAt'] ?? ''));
        $now = time();
        if (!$issuedAt || !$expiresAt || $issuedAt > $now + $skew || $expiresAt <= $now - $skew) {
            throw new RuntimeException('identity_presentation_expired');
        }

        $document = $presentation['identityDocument'] ?? null;
        if (!is_array($document)) {
            throw new RuntimeException('identity_document_required');
        }
        if (($document['id'] ?? '') !== $presentation['holder']) {
            throw new RuntimeException('identity_document_holder_mismatch');
        }
        $this->verifyIdentityDocument($document, $presentation['holder']);
        $publicKey = $this->findControllerPublicKey($presentation['holder'], $document, $proof['verificationMethod']);
        if ($publicKey === '') {
            throw new RuntimeException('identity_presentation_key_missing');
        }

        $unsigned = $presentation;
        unset($unsigned['proof']);
        $valid = sodium_crypto_sign_verify_detached(
            $this->base64UrlDecode($proof['proofValue']),
            $this->canonicalize($unsigned),
            $this->publicKeyBytes($publicKey)
        );
        if (!$valid) {
            throw new RuntimeException('identity_presentation_proof_invalid');
        }

        return $presentation;
    }

    public function credentialTokens(array $presentation): array
    {
        return array_values(array_filter($presentation['credentials'] ?? [], 'is_string'));
    }

    public function walletAddress(array $presentation): string
    {
        $address = $presentation['walletProof']['address'] ?? '';
        return is_string($address) ? strtolower(trim($address)) : '';
    }

    private function findControllerPublicKey(string $holder, array $document, string $method): string
    {
        foreach (($document['controllers'] ?? []) as $controller) {
            if (!is_array($controller)) {
                continue;
            }
            $controllerId = (string)($controller['controllerId'] ?? $controller['id'] ?? '');
            $purposes = is_array($controller['purposes'] ?? null) ? $controller['purposes'] : [];
            if ($method === "{$holder}#{$controllerId}"
                && ($controller['status'] ?? '') === 'active'
                && in_array('authentication', $purposes, true)) {
                $publicKey = $controller['publicKey'] ?? '';
                if (is_array($publicKey)) {
                    return (string)($publicKey['x'] ?? '');
                }
                return (string)$publicKey;
            }
        }
        return '';
    }

    private function verifyIdentityDocument(array $document, string $holder): void
    {
        $documentProof = $document['proof'] ?? null;
        if (!is_array($documentProof)
            || ($documentProof['type'] ?? '') !== 'YeyingIdentityDocumentProofV1'
            || ($documentProof['purpose'] ?? '') !== 'manage'
            || !is_string($documentProof['verificationMethod'] ?? null)
            || !is_string($documentProof['proofValue'] ?? null)) {
            throw new RuntimeException('identity_document_proof_invalid');
        }
        $method = $documentProof['verificationMethod'];
        $publicKey = '';
        foreach (($document['controllers'] ?? []) as $controller) {
            if (!is_array($controller)) continue;
            $controllerId = (string)($controller['controllerId'] ?? $controller['id'] ?? '');
            $purposes = is_array($controller['purposes'] ?? null) ? $controller['purposes'] : [];
            if ($method === "{$holder}#{$controllerId}"
                && ($controller['status'] ?? '') === 'active'
                && in_array('manage', $purposes, true)) {
                $publicKey = is_array($controller['publicKey'] ?? null)
                    ? (string)($controller['publicKey']['x'] ?? '')
                    : (string)($controller['publicKey'] ?? '');
                break;
            }
        }
        if ($publicKey === '') throw new RuntimeException('identity_document_key_missing');
        $unsigned = $document;
        unset($unsigned['proof']);
        if (!sodium_crypto_sign_verify_detached(
            $this->base64UrlDecode($documentProof['proofValue']),
            $this->canonicalize($unsigned),
            $this->publicKeyBytes($publicKey)
        )) throw new RuntimeException('identity_document_proof_invalid');
    }

    private function publicKeyBytes(string $publicKey): string
    {
        $publicKey = trim($publicKey);
        if ($publicKey === '') {
            throw new RuntimeException('identity_presentation_key_missing');
        }
        $bytes = $this->base64UrlDecode($publicKey);
        if (strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('identity_presentation_key_invalid');
        }
        return $bytes;
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $decoded = base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) {
            throw new RuntimeException('identity_base64_invalid');
        }
        return $decoded;
    }

    private function canonicalize($value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_string($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_array($value) && array_is_list($value)) {
            return '[' . implode(',', array_map(fn ($item) => $this->canonicalize($item), $value)) . ']';
        }
        if (is_array($value)) {
            ksort($value, SORT_STRING);
            return '{' . implode(',', array_map(
                fn ($key) => json_encode((string)$key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ':' . $this->canonicalize($value[$key]),
                array_keys($value)
            )) . '}';
        }
        throw new RuntimeException('identity_canonical_value_invalid');
    }
}
