<?php

namespace App;

use DateTimeZone;
use RuntimeException;

final readonly class OssConfiguration
{
    public function __construct(
        public string $accessKeyId,
        public string $accessKeySecret,
        public string $bucket,
        public string $region,
        public string $endpoint,
        public string $internalEndpoint,
        public string $bucketUrl,
        public string $serverUrl,
        public string $publicUrl,
        public string $roleArn,
        public string $stsEndpoint,
        public int $temporaryCredentialTtlSeconds,
        public string $uploadPrefix,
        public string $imageUrlTimezone = 'Asia/Shanghai',
    ) {}

    public static function fromConfig(): self
    {
        $values = [
            'access_key_id' => ['label' => 'Ali_Key', 'value' => config('oss.access_key_id')],
            'access_key_secret' => ['label' => 'Ali_Secret', 'value' => config('oss.access_key_secret')],
            'bucket' => ['label' => 'ALIYUN_OSS_BUCKET', 'value' => config('oss.bucket')],
            'region' => ['label' => 'ALIYUN_OSS_REGION', 'value' => config('oss.region')],
            'endpoint' => ['label' => 'ALIYUN_OSS_ENDPOINT', 'value' => config('oss.endpoint')],
            'internal_endpoint' => ['label' => 'ALIYUN_OSS_INTERNAL_ENDPOINT', 'value' => config('oss.internal_endpoint')],
            'bucket_url' => ['label' => 'ALIYUN_OSS_BUCKET_URL', 'value' => config('oss.bucket_url')],
            'server_url' => ['label' => 'ALIYUN_OSS_SERVER_URL', 'value' => config('oss.server_url')],
            'public_url' => ['label' => 'ALIYUN_OSS_PUBLIC_URL', 'value' => config('oss.public_url')],
            'role_arn' => ['label' => 'ALIYUN_OSS_ROLE_ARN', 'value' => config('oss.role_arn')],
            'sts_endpoint' => ['label' => 'ALIYUN_STS_ENDPOINT', 'value' => config('oss.sts_endpoint')],
            'image_url_timezone' => ['label' => 'ALIYUN_OSS_IMAGE_URL_TIMEZONE', 'value' => config('oss.image_url_timezone', 'Asia/Shanghai')],
            'upload_prefix' => ['label' => 'ALIYUN_OSS_UPLOAD_PREFIX', 'value' => config('oss.upload_prefix')],
        ];

        $missingLabels = [];

        foreach ($values as $configuration) {
            if (! is_string($configuration['value']) || trim($configuration['value']) === '') {
                $missingLabels[] = $configuration['label'];
            }
        }

        $temporaryCredentialTtlSeconds = config('oss.temporary_credential_ttl_seconds');

        if (
            ! is_int($temporaryCredentialTtlSeconds)
            || $temporaryCredentialTtlSeconds < 900
            || $temporaryCredentialTtlSeconds > 3600
        ) {
            $missingLabels[] = 'ALIYUN_OSS_TEMPORARY_CREDENTIAL_TTL_SECONDS';
        }

        if ($missingLabels !== []) {
            throw new RuntimeException(sprintf(
                'OSS 配置缺失或无效：%s。请在 .env 中补充后重试。',
                implode('、', $missingLabels),
            ));
        }

        if (! in_array(trim($values['image_url_timezone']['value']), DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('OSS 配置缺失或无效：ALIYUN_OSS_IMAGE_URL_TIMEZONE。请在 .env 中补充后重试。');
        }

        return new self(
            accessKeyId: trim($values['access_key_id']['value']),
            accessKeySecret: trim($values['access_key_secret']['value']),
            bucket: trim($values['bucket']['value']),
            region: trim($values['region']['value']),
            endpoint: rtrim(trim($values['endpoint']['value']), '/'),
            internalEndpoint: rtrim(trim($values['internal_endpoint']['value']), '/'),
            bucketUrl: rtrim(trim($values['bucket_url']['value']), '/'),
            serverUrl: rtrim(trim($values['server_url']['value']), '/'),
            publicUrl: rtrim(trim($values['public_url']['value']), '/'),
            roleArn: trim($values['role_arn']['value']),
            stsEndpoint: rtrim(trim($values['sts_endpoint']['value']), '/'),
            temporaryCredentialTtlSeconds: $temporaryCredentialTtlSeconds,
            uploadPrefix: trim($values['upload_prefix']['value'], '/'),
            imageUrlTimezone: trim($values['image_url_timezone']['value']),
        );
    }
}
