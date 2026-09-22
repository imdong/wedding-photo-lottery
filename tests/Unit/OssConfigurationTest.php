<?php

namespace Tests\Unit;

use App\OssConfiguration;
use RuntimeException;
use Tests\TestCase;

class OssConfigurationTest extends TestCase
{
    public function test_returns_typed_configuration_when_required_values_are_present(): void
    {
        config()->set('oss', $this->validConfiguration());

        $configuration = OssConfiguration::fromConfig();

        $this->assertSame('test-access-key-id', $configuration->accessKeyId);
        $this->assertSame('test-access-key-secret', $configuration->accessKeySecret);
        $this->assertSame('example-wedding-photos', $configuration->bucket);
        $this->assertSame('cn-qingdao', $configuration->region);
        $this->assertSame('https://oss-cn-qingdao.aliyuncs.com', $configuration->endpoint);
        $this->assertSame('https://example-wedding-photos.oss-cn-qingdao-internal.aliyuncs.com', $configuration->serverUrl);
        $this->assertSame('https://cdn.example.com', $configuration->publicUrl);
        $this->assertSame('acs:ram::123456789:role/wedding-photo-upload', $configuration->roleArn);
        $this->assertSame(900, $configuration->temporaryCredentialTtlSeconds);
        $this->assertSame('Asia/Shanghai', $configuration->imageUrlTimezone);
        $this->assertSame('wedding/photos', $configuration->uploadPrefix);
    }

    public function test_throws_clear_error_listing_missing_configuration(): void
    {
        config()->set('oss', [
            ...$this->validConfiguration(),
            'access_key_id' => null,
            'access_key_secret' => '',
            'role_arn' => null,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'OSS 配置缺失或无效：Ali_Key、Ali_Secret、ALIYUN_OSS_ROLE_ARN。请在 .env 中补充后重试。',
        );

        OssConfiguration::fromConfig();
    }

    public function test_throws_clear_error_for_invalid_temporary_credential_ttl(): void
    {
        config()->set('oss', [
            ...$this->validConfiguration(),
            'temporary_credential_ttl_seconds' => 0,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ALIYUN_OSS_TEMPORARY_CREDENTIAL_TTL_SECONDS');

        OssConfiguration::fromConfig();
    }

    public function test_rejects_temporary_credential_ttl_above_the_application_limit(): void
    {
        config()->set('oss', [
            ...$this->validConfiguration(),
            'temporary_credential_ttl_seconds' => 3601,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ALIYUN_OSS_TEMPORARY_CREDENTIAL_TTL_SECONDS');

        OssConfiguration::fromConfig();
    }

    public function test_rejects_invalid_image_url_timezone(): void
    {
        config()->set('oss', [
            ...$this->validConfiguration(),
            'image_url_timezone' => 'Wedding/Reception',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ALIYUN_OSS_IMAGE_URL_TIMEZONE');

        OssConfiguration::fromConfig();
    }

    /** @return array<string, mixed> */
    private function validConfiguration(): array
    {
        return [
            'access_key_id' => 'test-access-key-id',
            'access_key_secret' => 'test-access-key-secret',
            'bucket' => 'example-wedding-photos',
            'region' => 'cn-qingdao',
            'endpoint' => 'https://oss-cn-qingdao.aliyuncs.com/',
            'internal_endpoint' => 'https://oss-cn-qingdao-internal.aliyuncs.com/',
            'bucket_url' => 'https://example-wedding-photos.oss-cn-qingdao.aliyuncs.com/',
            'server_url' => 'https://example-wedding-photos.oss-cn-qingdao-internal.aliyuncs.com/',
            'public_url' => 'https://cdn.example.com/',
            'role_arn' => 'acs:ram::123456789:role/wedding-photo-upload',
            'sts_endpoint' => 'https://sts.cn-qingdao.aliyuncs.com/',
            'temporary_credential_ttl_seconds' => 900,
            'image_url_timezone' => 'Asia/Shanghai',
            'upload_prefix' => '/wedding/photos/',
        ];
    }
}
