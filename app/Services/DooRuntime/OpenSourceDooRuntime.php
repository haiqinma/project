<?php

namespace App\Services\DooRuntime;

use App\Exceptions\ApiException;
use App\Module\Base;
use App\Models\User;
use App\Services\RequestContext;
use Cache;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

class OpenSourceDooRuntime extends AbstractDooRuntime
{
    protected const LICENSE_FILE = 'license';

    protected ?array $tokenPayload = null;

    public function __construct(
        protected $token = null,
        protected $language = null,
    ) {
    }

    protected function unsupported(string $method): never
    {
        throw new ApiException("开源 Doo 运行时暂未实现 {$method}()");
    }

    protected function getJwtKey(): string
    {
        $appKey = (string)config('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $appKey;
    }

    protected function getTokenPayload(): array
    {
        if ($this->tokenPayload !== null) {
            return $this->tokenPayload;
        }

        $empty = [
            'userid' => 0,
            'email' => '',
            'encrypt' => '',
            'expired_at' => null,
        ];

        $token = trim((string)$this->token);
        if ($token === '') {
            return $this->tokenPayload = $empty;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->getJwtKey(), 'HS256'));
            return $this->tokenPayload = [
                'userid' => intval($decoded->userid ?? 0),
                'email' => strval($decoded->email ?? ''),
                'encrypt' => strval($decoded->encrypt ?? ''),
                'expired_at' => isset($decoded->exp) ? date('Y-m-d H:i:s', intval($decoded->exp)) : null,
            ];
        } catch (Throwable) {
            return $this->tokenPayload = $this->parseTokenPayloadWithoutVerify($token) ?: $empty;
        }
    }

    protected function parseTokenPayloadWithoutVerify(string $token): array
    {
        $segments = explode('.', $token);
        if (count($segments) !== 3 || empty($segments[1])) {
            return [];
        }

        $payload = JWT::urlsafeB64Decode($segments[1]);
        $payload = json_decode($payload, true);
        if (!is_array($payload)) {
            return [];
        }

        return [
            'userid' => intval($payload['userid'] ?? 0),
            'email' => strval($payload['email'] ?? ''),
            'encrypt' => strval($payload['encrypt'] ?? ''),
            'expired_at' => isset($payload['exp']) ? date('Y-m-d H:i:s', intval($payload['exp'])) : null,
        ];
    }

    protected function licenseFilePath(): string
    {
        return config_path(self::LICENSE_FILE);
    }

    protected function defaultLicense(): array
    {
        return [
            'edition' => 'MIT',
            'type' => 'community',
            'source' => 'opensource',
            'people' => 0,
            'sn' => $this->dooSN(),
            'mac' => $this->macs(),
            'ip' => [],
            'domain' => [],
            'email' => [],
            'expired_at' => null,
            'created_at' => null,
        ];
    }

    protected function normalizeList(array|string|null $value, callable $validator): array
    {
        $items = is_array($value) ? $value : explode(',', (string)$value);
        $items = array_map(static fn($item) => trim((string)$item), $items);
        $items = array_values(array_filter(array_unique($items), static fn($item) => $item !== '' && $validator($item)));
        return $items;
    }

    protected function normalizeLicenseArray(array $data): array
    {
        $defaults = $this->defaultLicense();

        return array_merge($defaults, [
            'edition' => trim((string)($data['edition'] ?? $defaults['edition'])),
            'type' => trim((string)($data['type'] ?? $defaults['type'])),
            'source' => trim((string)($data['source'] ?? $defaults['source'])),
            'people' => max(0, intval($data['people'] ?? $defaults['people'])),
            'sn' => trim((string)($data['sn'] ?? $defaults['sn'])),
            'mac' => $this->normalizeList($data['mac'] ?? $defaults['mac'], static fn($mac) => Base::isMac($mac)),
            'ip' => $this->normalizeList($data['ip'] ?? $defaults['ip'], static fn($ip) => Base::is_ipv4($ip)),
            'domain' => $this->normalizeList($data['domain'] ?? $defaults['domain'], static fn($domain) => Base::is_domain($domain)),
            'email' => $this->normalizeList($data['email'] ?? $defaults['email'], static fn($email) => Base::isEmail($email)),
            'expired_at' => empty($data['expired_at']) ? null : trim((string)$data['expired_at']),
            'created_at' => empty($data['created_at']) ? null : trim((string)$data['created_at']),
        ]);
    }

    protected function decodeLicenseValue($license): array
    {
        if (is_array($license)) {
            return $this->normalizeLicenseArray($license);
        }

        $license = trim((string)$license);
        if ($license === '') {
            return [];
        }

        $decoded = Base::json2array($license);
        if (is_array($decoded) && $decoded !== []) {
            return $this->normalizeLicenseArray($decoded);
        }

        $base64 = base64_decode($license, true);
        if ($base64 !== false) {
            $decoded = Base::json2array($base64);
            if (is_array($decoded) && $decoded !== []) {
                return $this->normalizeLicenseArray($decoded);
            }
        }

        return [];
    }

    public function license(): array
    {
        $path = $this->licenseFilePath();
        if (!is_file($path)) {
            return $this->defaultLicense();
        }

        $decoded = $this->decodeLicenseValue(file_get_contents($path) ?: '');
        return $decoded ?: $this->defaultLicense();
    }

    public function licenseDecode($license): array
    {
        return $this->decodeLicenseValue($license);
    }

    public function licenseSave($license): void
    {
        $decoded = $this->decodeLicenseValue($license);
        if (($decoded['sn'] ?? '') === '') {
            throw new ApiException('LICENSE 格式错误');
        }

        $content = Base::array2json($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (@file_put_contents($this->licenseFilePath(), $content) === false) {
            throw new ApiException('LICENSE 保存失败');
        }
    }

    public function userId(): int
    {
        $user = RequestContext::get('auth');
        if ($user instanceof User) {
            return $user->userid;
        }
        return intval($this->getTokenPayload()['userid'] ?? 0);
    }

    public function userExpiredAt(): ?string
    {
        return $this->getTokenPayload()['expired_at'] ?? null;
    }

    public function userEmail(): string
    {
        $user = RequestContext::get('auth');
        if ($user instanceof User) {
            return $user->email;
        }
        return strval($this->getTokenPayload()['email'] ?? '');
    }

    public function userEncrypt(): string
    {
        $user = RequestContext::get('auth');
        if ($user instanceof User) {
            return (string) $user->encrypt;
        }
        return strval($this->getTokenPayload()['encrypt'] ?? '');
    }

    public function userToken(): string
    {
        return (string)$this->token;
    }

    public function userCreate($email, $password): User|null
    {
        $email = trim((string)$email);
        $password = (string)$password;

        if (!Base::isEmail($email)) {
            throw new ApiException('请输入正确的邮箱地址');
        }

        $existing = User::whereEmail($email)->first();
        if ($existing) {
            throw new ApiException('邮箱地址已存在');
        }

        $encrypt = Base::generatePassword(6);
        $isBot = preg_match('/@bot\.system$/', $email) === 1;

        $user = User::createInstance([
            'email' => $email,
            'nickname' => Base::formatName($email),
            'encrypt' => $encrypt,
            'password' => $this->md5s($password, $encrypt),
            'email_verity' => $isBot ? 1 : 0,
            'bot' => $isBot ? 1 : 0,
            'identity' => '',
        ]);
        $user->save();

        return User::whereEmail($email)->first();
    }

    public function tokenEncode($userid, $email, $encrypt, int $days = 15): string
    {
        $now = time();
        $payload = [
            'iss' => config('app.url') ?: 'yeying',
            'iat' => $now,
            'nbf' => $now,
            'userid' => intval($userid),
            'email' => strval($email),
            'encrypt' => strval($encrypt),
        ];
        if ($days > 0) {
            $payload['exp'] = strtotime("+{$days} days", $now);
        }
        return JWT::encode($payload, $this->getJwtKey(), 'HS256');
    }

    public function tokenDecode($token): array
    {
        return $this->parseTokenPayloadWithoutVerify((string)$token) ?: [
            'userid' => 0,
            'email' => '',
            'encrypt' => '',
            'expired_at' => null,
        ];
    }

    public function translate($text, ?string $lang = ''): string
    {
        return (string)$text;
    }

    public function md5s($text, string $password = ''): string
    {
        return md5((string)$text . ':' . $password);
    }

    public function macs(): array
    {
        $commands = match (PHP_OS_FAMILY) {
            'Darwin' => ['ifconfig'],
            'Linux' => ['ip link', 'ifconfig -a'],
            default => [],
        };

        $macs = [];
        foreach ($commands as $command) {
            $output = @shell_exec($command . ' 2>/dev/null');
            if (!is_string($output) || trim($output) === '') {
                continue;
            }
            if (preg_match_all('/(?:ether|link\/ether)\s+([0-9a-f]{2}(?::[0-9a-f]{2}){5})/i', $output, $matches)) {
                foreach ($matches[1] as $mac) {
                    $mac = strtolower(trim($mac));
                    if (Base::isMac($mac)) {
                        $macs[] = $mac;
                    }
                }
            }
            if ($macs) {
                break;
            }
        }

        return array_values(array_unique($macs));
    }

    public function dooSN(): string
    {
        $fingerprint = implode('|', [
            (string)config('app.key', ''),
            (string)config('app.url', ''),
            base_path(),
            php_uname('n'),
        ]);
        return 'OS-' . strtoupper(substr(hash('sha256', $fingerprint), 0, 16));
    }

    public function dooVersion(): string
    {
        return app()->version();
    }

    public function pgpGenerateKeyPair($name, $email, string $passphrase = ''): array
    {
        return app(OpenPgpService::class)->generateKeyPair((string)$name, (string)$email, $passphrase);
    }

    public function pgpEncrypt($plaintext, $publicKey): string
    {
        if (strlen((string)$publicKey) < 50) {
            $cached = Base::json2array(Cache::get('KeyPair::' . $publicKey));
            $publicKey = $cached['public_key'] ?? $publicKey;
        }
        return app(OpenPgpService::class)->encrypt((string)$plaintext, (string)$publicKey);
    }

    public function pgpDecrypt($encryptedText, $privateKey, $passphrase = null): string
    {
        if (strlen((string)$privateKey) < 50) {
            $cached = Base::json2array(Cache::get('KeyPair::' . $privateKey));
            $privateKey = $cached['private_key'] ?? $privateKey;
            $passphrase = $cached['passphrase'] ?? $passphrase;
        }
        return app(OpenPgpService::class)->decrypt((string)$encryptedText, (string)$privateKey, $passphrase);
    }
}
