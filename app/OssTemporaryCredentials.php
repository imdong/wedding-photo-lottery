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
        public string $uploadUrl,
    ) {}

    /**
     * @return array{
     *     access_key_id: string,
     *     access_key_secret: string,
     *     security_token: string,
     *     expiration: string,
     *     bucket: string,
     *     upload_url: string
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
            'upload_url' => $this->uploadUrl,
        ];
    }
}
