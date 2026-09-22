<?php

namespace Tests\Unit;

use App\Models\User;
use App\OssConfiguration;
use App\OssTemporaryCredentialService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OssTemporaryCredentialServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_issues_credentials_limited_to_the_users_upload_directory(): void
    {
        $this->travelTo('2026-09-20 08:00:00');

        Http::fake([
            'https://sts.cn-qingdao.aliyuncs.com' => Http::response([
                'Credentials' => [
                    'AccessKeyId' => 'temporary-access-key-id',
                    'AccessKeySecret' => 'temporary-access-key-secret',
                    'SecurityToken' => 'temporary-security-token',
                    'Expiration' => '2026-09-20T08:15:00Z',
                ],
            ]),
        ]);

        $credentials = $this->service()->issueForUser($this->user());

        $this->assertSame([
            'access_key_id' => 'temporary-access-key-id',
            'access_key_secret' => 'temporary-access-key-secret',
            'security_token' => 'temporary-security-token',
            'expiration' => '2026-09-20T08:15:00Z',
            'bucket' => 'example-wedding-photos',
            'upload_url' => 'https://uploads.example.test',
        ], $credentials->toArray());

        Http::assertSent(function (Request $request): bool {
            $parameters = $request->data();
            $signature = $parameters['Signature'] ?? null;
            unset($parameters['Signature']);

            $policy = json_decode($parameters['Policy'], true, flags: JSON_THROW_ON_ERROR);

            return $request->method() === 'POST'
                && $request->url() === 'https://sts.cn-qingdao.aliyuncs.com'
                && $parameters['Action'] === 'AssumeRole'
                && $parameters['DurationSeconds'] === '900'
                && $parameters['RoleArn'] === 'acs:ram::123456789:role/wedding-photo-upload'
                && $parameters['Timestamp'] === '2026-09-20T08:00:00Z'
                && $policy === [
                    'Version' => '1',
                    'Statement' => [[
                        'Effect' => 'Allow',
                        'Action' => ['oss:PutObject'],
                        'Resource' => ['acs:oss:*:*:example-wedding-photos/wedding/photos/42/*'],
                    ]],
                ]
                && $signature === $this->sign($parameters);
        });
    }

    public function test_reports_a_clear_error_when_sts_rejects_the_request(): void
    {
        Http::fake([
            'https://sts.cn-qingdao.aliyuncs.com' => Http::response([], 403),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('无法获取 OSS 临时凭证，请检查 RAM Role 和 STS 权限。');

        $this->service()->issueForUser($this->user());
    }

    public function test_reports_a_clear_error_for_an_invalid_sts_response(): void
    {
        Http::fake([
            'https://sts.cn-qingdao.aliyuncs.com' => Http::response([
                'Credentials' => [
                    'AccessKeyId' => 'temporary-access-key-id',
                ],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('阿里云 STS 返回了无效的临时凭证。');

        $this->service()->issueForUser($this->user());
    }

    public function test_reports_a_clear_error_when_sts_cannot_be_reached(): void
    {
        Http::fake([
            'https://sts.cn-qingdao.aliyuncs.com' => Http::failedConnection(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('无法连接阿里云 STS，请稍后重试。');

        $this->service()->issueForUser($this->user());
    }

    private function service(): OssTemporaryCredentialService
    {
        return new OssTemporaryCredentialService(new OssConfiguration(
            accessKeyId: 'test-access-key-id',
            accessKeySecret: 'test-access-key-secret',
            bucket: 'example-wedding-photos',
            region: 'cn-qingdao',
            endpoint: 'https://oss-cn-qingdao.aliyuncs.com',
            internalEndpoint: 'https://oss-cn-qingdao-internal.aliyuncs.com',
            bucketUrl: 'https://example-wedding-photos.oss-cn-qingdao.aliyuncs.com',
            serverUrl: 'https://example-wedding-photos.oss-cn-qingdao.aliyuncs.com',
            publicUrl: 'https://uploads.example.test',
            roleArn: 'acs:ram::123456789:role/wedding-photo-upload',
            stsEndpoint: 'https://sts.cn-qingdao.aliyuncs.com',
            temporaryCredentialTtlSeconds: 900,
            uploadPrefix: 'wedding/photos',
        ));
    }

    private function user(): User
    {
        $user = new User;
        $user->setAttribute('id', 42);

        return $user;
    }

    /** @param  array<string, mixed>  $parameters */
    private function sign(array $parameters): string
    {
        ksort($parameters, SORT_STRING);

        $canonicalizedQueryString = collect($parameters)
            ->map(fn (mixed $value, string $key): string => rawurlencode($key).'='.rawurlencode((string) $value))
            ->implode('&');
        $stringToSign = 'POST&'.rawurlencode('/').'&'.rawurlencode($canonicalizedQueryString);

        return base64_encode(hash_hmac(
            'sha1',
            $stringToSign,
            'test-access-key-secret&',
            true,
        ));
    }
}
