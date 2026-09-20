<?php

namespace App;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class OssTemporaryCredentialService
{
    public function __construct(private OssConfiguration $configuration) {}

    public function issueForUser(User $user): OssTemporaryCredentials
    {
        $objectKeyPrefix = sprintf(
            '%s/%s/',
            $this->configuration->uploadPrefix,
            $user->getKey(),
        );

        $parameters = $this->signedParameters($objectKeyPrefix);

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->post($this->configuration->stsEndpoint, $parameters);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('无法连接阿里云 STS，请稍后重试。', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('无法获取 OSS 临时凭证，请检查 RAM Role 和 STS 权限。');
        }

        $credentials = $response->json('Credentials');

        if (! is_array($credentials)) {
            throw new RuntimeException('阿里云 STS 返回了无效的临时凭证。');
        }

        $accessKeyId = $credentials['AccessKeyId'] ?? null;
        $accessKeySecret = $credentials['AccessKeySecret'] ?? null;
        $securityToken = $credentials['SecurityToken'] ?? null;
        $expiration = $credentials['Expiration'] ?? null;

        foreach ([$accessKeyId, $accessKeySecret, $securityToken, $expiration] as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException('阿里云 STS 返回了无效的临时凭证。');
            }
        }

        return new OssTemporaryCredentials(
            accessKeyId: $accessKeyId,
            accessKeySecret: $accessKeySecret,
            securityToken: $securityToken,
            expiration: $expiration,
            bucket: $this->configuration->bucket,
            uploadUrl: $this->configuration->publicUrl,
        );
    }

    /** @return array<string, string> */
    private function signedParameters(string $objectKeyPrefix): array
    {
        $policy = json_encode([
            'Version' => '1',
            'Statement' => [[
                'Effect' => 'Allow',
                'Action' => ['oss:PutObject'],
                'Resource' => [sprintf(
                    'acs:oss:*:*:%s/%s*',
                    $this->configuration->bucket,
                    $objectKeyPrefix,
                )],
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $parameters = [
            'AccessKeyId' => $this->configuration->accessKeyId,
            'Action' => 'AssumeRole',
            'DurationSeconds' => (string) $this->configuration->temporaryCredentialTtlSeconds,
            'Format' => 'JSON',
            'Policy' => $policy,
            'RoleArn' => $this->configuration->roleArn,
            'RoleSessionName' => 'wedding-photo-upload',
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureNonce' => (string) Str::uuid(),
            'SignatureVersion' => '1.0',
            'Timestamp' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'Version' => '2015-04-01',
        ];

        ksort($parameters, SORT_STRING);

        $canonicalizedQueryString = collect($parameters)
            ->map(fn (string $value, string $key): string => rawurlencode($key).'='.rawurlencode($value))
            ->implode('&');
        $stringToSign = 'POST&'.rawurlencode('/').'&'.rawurlencode($canonicalizedQueryString);

        $parameters['Signature'] = base64_encode(hash_hmac(
            'sha1',
            $stringToSign,
            $this->configuration->accessKeySecret.'&',
            true,
        ));

        return $parameters;
    }
}
