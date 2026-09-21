<?php

namespace Tests\Unit;

use App\OssConfiguration;
use App\OssImageUrlGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OssImageUrlGeneratorTest extends TestCase
{
    #[DataProvider('presetProvider')]
    public function test_generates_an_encoded_url_for_each_preset(string $preset, string $process): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        $generator = new OssImageUrlGenerator($this->configuration());

        $url = $generator->{$preset}('wedding/photos/1/photo.jpg');

        $this->assertStringStartsWith('https://images.example.test/wedding/photos/1/photo.jpg?', $url);
        $this->assertStringContainsString('x-oss-process='.rawurlencode($process), $url);
        $this->assertStringContainsString('OSSAccessKeyId=test-access-key-id', $url);
        $this->assertStringContainsString('Expires=1789894800', $url);
        $this->assertMatchesRegularExpression('/&Signature=[^&]+$/', $url);
    }

    /** @return array<string, array{string, string}> */
    public static function presetProvider(): array
    {
        return [
            'cloud' => ['cloud', 'image/resize,m_fill,w_64,h_64/quality,q_70/format,webp'],
            'thumbnail' => ['thumbnail', 'image/resize,m_fill,w_400,h_400/quality,q_85/format,webp'],
            'gallery' => ['gallery', 'image/resize,l_1200/quality,q_90/format,webp'],
            'review' => ['review', 'image/resize,l_1600/quality,q_90/format,webp'],
            'draw' => ['draw', 'image/resize,l_1920/quality,q_92/format,webp'],
        ];
    }

    public function test_includes_image_processing_parameters_in_the_signature(): void
    {
        $this->travelTo('2026-09-20 08:00:00');
        $generator = new OssImageUrlGenerator($this->configuration());

        $url = $generator->thumbnail('wedding/photos/1/photo.jpg');

        $this->assertStringEndsWith('Signature=V87o%2By9qRPxyjDg6KAq%2B1Kyf2Ro%3D', $url);
    }

    private function configuration(): OssConfiguration
    {
        return new OssConfiguration(
            accessKeyId: 'test-access-key-id',
            accessKeySecret: 'test-access-key-secret',
            bucket: 'example-wedding-photos',
            region: 'cn-qingdao',
            endpoint: 'https://oss-cn-qingdao.aliyuncs.com',
            internalEndpoint: 'https://oss-cn-qingdao-internal.aliyuncs.com',
            bucketUrl: 'https://example-wedding-photos.oss-cn-qingdao.aliyuncs.com',
            publicUrl: 'https://images.example.test',
            roleArn: 'acs:ram::123456789:role/wedding-photo-upload',
            stsEndpoint: 'https://sts.cn-qingdao.aliyuncs.com',
            temporaryCredentialTtlSeconds: 900,
            uploadPrefix: 'wedding/photos',
            imageUrlTtlSeconds: 3600,
        );
    }
}
