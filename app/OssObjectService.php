<?php

namespace App;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final readonly class OssObjectService
{
    public function __construct(private OssConfiguration $configuration) {}

    public function exists(string $objectKey): bool
    {
        $date = now()->utc()->format('D, d M Y H:i:s \G\M\T');
        $canonicalResource = sprintf('/%s/%s', $this->configuration->bucket, $objectKey);
        $signature = base64_encode(hash_hmac(
            'sha1',
            "HEAD\n\n\n{$date}\n{$canonicalResource}",
            $this->configuration->accessKeySecret,
            true,
        ));

        try {
            $response = Http::withHeaders([
                'Authorization' => sprintf(
                    'OSS %s:%s',
                    $this->configuration->accessKeyId,
                    $signature,
                ),
                'Date' => $date,
            ])
                ->connectTimeout(3)
                ->timeout(5)
                ->head($this->objectUrl($objectKey));
        } catch (ConnectionException $exception) {
            throw new RuntimeException('暂时无法确认照片状态，请稍后重试。', previous: $exception);
        }

        if ($response->successful()) {
            return true;
        }

        if ($response->notFound()) {
            return false;
        }

        throw new RuntimeException('暂时无法确认照片状态，请稍后重试。');
    }

    public function download(string $objectKey): string
    {
        $response = Http::connectTimeout(5)
            ->timeout(30)
            ->get($this->signedUrl('GET', $objectKey));

        if (! $response->successful()) {
            throw new RuntimeException('无法从 OSS 下载原始照片。');
        }

        return $response->body();
    }

    public function put(string $objectKey, string $contents, string $contentType): void
    {
        $response = Http::withHeaders($this->signedHeaders('PUT', $objectKey, $contentType))
            ->withBody($contents, $contentType)
            ->connectTimeout(5)
            ->timeout(60)
            ->put($this->objectUrl($objectKey));

        if (! $response->successful()) {
            throw new RuntimeException('无法将压缩后的照片写回 OSS。');
        }
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $objectKey, string $contentType = ''): array
    {
        $date = now()->utc()->format('D, d M Y H:i:s \G\M\T');
        $canonicalResource = sprintf('/%s/%s', $this->configuration->bucket, $objectKey);
        $signature = base64_encode(hash_hmac(
            'sha1',
            "{$method}\n\n{$contentType}\n{$date}\n{$canonicalResource}",
            $this->configuration->accessKeySecret,
            true,
        ));

        return [
            'Authorization' => sprintf(
                'OSS %s:%s',
                $this->configuration->accessKeyId,
                $signature,
            ),
            'Date' => $date,
        ];
    }

    private function signedUrl(string $method, string $objectKey): string
    {
        $expires = now()->utc()->addMinutes(5)->timestamp;
        $canonicalResource = sprintf('/%s/%s', $this->configuration->bucket, $objectKey);
        $signature = base64_encode(hash_hmac(
            'sha1',
            "{$method}\n\n\n{$expires}\n{$canonicalResource}",
            $this->configuration->accessKeySecret,
            true,
        ));

        return sprintf('%s/%s?%s',
            $this->configuration->bucketUrl,
            collect(explode('/', $objectKey))
                ->map(fn (string $segment): string => rawurlencode($segment))
                ->implode('/'),
            http_build_query([
                'OSSAccessKeyId' => $this->configuration->accessKeyId,
                'Expires' => $expires,
                'Signature' => $signature,
            ], encoding_type: PHP_QUERY_RFC3986),
        );
    }

    private function objectUrl(string $objectKey, bool $internal = false): string
    {
        $encodedObjectKey = collect(explode('/', $objectKey))
            ->map(fn (string $segment): string => rawurlencode($segment))
            ->implode('/');

        if ($internal) {
            return sprintf('%s/%s/%s', $this->configuration->internalEndpoint, $this->configuration->bucket, $encodedObjectKey);
        }

        return $this->configuration->bucketUrl.'/'.$encodedObjectKey;
    }
}
