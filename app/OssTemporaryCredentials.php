<?php

namespace App;

final readonly class OssTemporaryCredentials
{
    public function __construct(
        public string $accessKeyId,
        public string $accessKeySecret,
        public string $securityToken,
        public string $expiration,
        public string $bucket,
        public string $region,
        public string $endpoint,
        public string $bucketUrl,
        public string $publicUrl,
        public string $objectKeyPrefix,
    ) {}

    /**
     * @return array{
     *     access_key_id: string,
     *     access_key_secret: string,
     *     security_token: string,
     *     expiration: string,
     *     bucket: string,
     *     region: string,
     *     endpoint: string,
     *     bucket_url: string,
     *     public_url: string,
     *     object_key_prefix: string
     * }
     */
    public function toArray(): array
    {
        return [
            'access_key_id' => $this->accessKeyId,
            'access_key_secret' => $this->accessKeySecret,
            'security_token' => $this->securityToken,
            'expiration' => $this->expiration,
            'bucket' => $this->bucket,
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'bucket_url' => $this->bucketUrl,
            'public_url' => $this->publicUrl,
            'object_key_prefix' => $this->objectKeyPrefix,
        ];
    }
}
