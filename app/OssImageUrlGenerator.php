<?php

namespace App;

final readonly class OssImageUrlGenerator
{
    public function __construct(private OssConfiguration $configuration) {}

    public function thumbnail(string $objectKey): string
    {
        return $this->generate($objectKey, 'image/resize,m_fill,w_400,h_400/quality,q_85/format,webp');
    }

    public function gallery(string $objectKey): string
    {
        return $this->generate($objectKey, 'image/resize,l_1200/quality,q_90/format,webp');
    }

    public function review(string $objectKey): string
    {
        return $this->generate($objectKey, 'image/resize,l_1600/quality,q_90/format,webp');
    }

    public function draw(string $objectKey): string
    {
        return $this->generate($objectKey, 'image/resize,l_1920/quality,q_92/format,webp');
    }

    private function generate(string $objectKey, string $process): string
    {
        $expires = now()->addSeconds($this->configuration->imageUrlTtlSeconds)->timestamp;
        $canonicalResource = sprintf(
            '/%s/%s?x-oss-process=%s',
            $this->configuration->bucket,
            $objectKey,
            $process,
        );
        $signature = base64_encode(hash_hmac(
            'sha1',
            "GET\n\n\n{$expires}\n{$canonicalResource}",
            $this->configuration->accessKeySecret,
            true,
        ));
        $encodedObjectKey = collect(explode('/', $objectKey))
            ->map(fn (string $segment): string => rawurlencode($segment))
            ->implode('/');

        return sprintf('%s/%s?%s',
            $this->configuration->publicUrl,
            $encodedObjectKey,
            http_build_query([
                'x-oss-process' => $process,
                'OSSAccessKeyId' => $this->configuration->accessKeyId,
                'Expires' => $expires,
                'Signature' => $signature,
            ], encoding_type: PHP_QUERY_RFC3986),
        );
    }
}
