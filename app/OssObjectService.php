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

    private function objectUrl(string $objectKey): string
    {
        $encodedObjectKey = collect(explode('/', $objectKey))
            ->map(fn (string $segment): string => rawurlencode($segment))
            ->implode('/');

        return $this->configuration->bucketUrl.'/'.$encodedObjectKey;
    }
}
