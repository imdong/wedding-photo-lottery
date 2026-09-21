<?php

namespace App;

use App\Models\Photo;
use RuntimeException;

final readonly class PhotoUploadProcessingService
{
    private const int MAX_DIMENSION = 2560;

    private const int WEBP_QUALITY = 82;

    private const int JPEG_QUALITY = 85;

    public function __construct(private OssObjectService $ossObjectService) {}

    public function process(Photo $photo): void
    {
        $threshold = (int) config('photos.large_original_size_bytes', 20 * 1024 * 1024);

        if ($photo->file_size <= $threshold) {
            return;
        }

        $sourceObjectKey = $photo->original_object_key ?? $photo->object_key;

        if ($photo->original_object_key !== null && $photo->object_key !== $sourceObjectKey) {
            return;
        }

        $sourceContents = $this->ossObjectService->download($sourceObjectKey);
        $transcoded = $this->transcode($sourceContents);
        $processedObjectKey = $this->buildProcessedObjectKey($sourceObjectKey, $transcoded['extension']);

        $this->ossObjectService->put(
            $processedObjectKey,
            $transcoded['contents'],
            $transcoded['mime_type'],
        );

        $photo->update([
            'original_object_key' => $sourceObjectKey,
            'object_key' => $processedObjectKey,
        ]);
    }

    /**
     * @return array{contents: string, mime_type: string, extension: string}
     */
    private function transcode(string $sourceContents): array
    {
        if (class_exists('Imagick')) {
            return $this->transcodeWithImagick($sourceContents);
        }

        if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            return $this->transcodeWithGd($sourceContents);
        }

        if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            return $this->transcodeWithJpegFallback($sourceContents);
        }

        throw new RuntimeException('当前环境缺少图片压缩能力，无法处理大图。');
    }

    /**
     * @return array{contents: string, mime_type: string, extension: string}
     */
    private function transcodeWithImagick(string $sourceContents): array
    {
        $imagickClass = 'Imagick';
        $image = new $imagickClass;
        $image->readImageBlob($sourceContents);
        $image->setIteratorIndex(0);
        $image->stripImage();
        $image->thumbnailImage(self::MAX_DIMENSION, self::MAX_DIMENSION, true, true);

        try {
            if (! $image->setImageFormat('webp')) {
                throw new RuntimeException('Imagick 不支持 webp 输出。');
            }

            $image->setImageCompressionQuality(self::WEBP_QUALITY);

            return [
                'contents' => $image->getImagesBlob(),
                'mime_type' => 'image/webp',
                'extension' => 'webp',
            ];
        } catch (\Throwable $exception) {
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(self::JPEG_QUALITY);

            return [
                'contents' => $image->getImagesBlob(),
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
            ];
        }
    }

    /**
     * @return array{contents: string, mime_type: string, extension: string}
     */
    private function transcodeWithGd(string $sourceContents): array
    {
        $image = imagecreatefromstring($sourceContents);

        if ($image === false) {
            throw new RuntimeException('无法解析上传的图片内容。');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, self::MAX_DIMENSION / max(1, $width), self::MAX_DIMENSION / max(1, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resizedImage = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);

        imagecopyresampled(
            $resizedImage,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        ob_start();
        imagewebp($resizedImage, null, self::WEBP_QUALITY);
        $encodedImage = ob_get_clean();

        imagedestroy($image);
        imagedestroy($resizedImage);

        if (! is_string($encodedImage) || $encodedImage === '') {
            throw new RuntimeException('压缩后的图片内容为空。');
        }

        return [
            'contents' => $encodedImage,
            'mime_type' => 'image/webp',
            'extension' => 'webp',
        ];
    }

    /**
     * @return array{contents: string, mime_type: string, extension: string}
     */
    private function transcodeWithJpegFallback(string $sourceContents): array
    {
        $image = imagecreatefromstring($sourceContents);

        if ($image === false) {
            throw new RuntimeException('无法解析上传的图片内容。');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, self::MAX_DIMENSION / max(1, $width), self::MAX_DIMENSION / max(1, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resizedImage = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($resizedImage, 255, 255, 255);
        imagefilledrectangle($resizedImage, 0, 0, $targetWidth, $targetHeight, $white);
        imagecopyresampled(
            $resizedImage,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height,
        );

        ob_start();
        imagejpeg($resizedImage, null, self::JPEG_QUALITY);
        $encodedImage = ob_get_clean();

        imagedestroy($image);
        imagedestroy($resizedImage);

        if (! is_string($encodedImage) || $encodedImage === '') {
            throw new RuntimeException('压缩后的图片内容为空。');
        }

        return [
            'contents' => $encodedImage,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
        ];
    }

    private function buildProcessedObjectKey(string $sourceObjectKey, string $extension): string
    {
        $pathInfo = pathinfo($sourceObjectKey);
        $directory = $pathInfo['dirname'] ?? '';
        $filename = $pathInfo['filename'] ?? '';

        if (str_ends_with($filename, '.orig')) {
            $filename = substr($filename, 0, -5);
        }

        return sprintf(
            '%s/%s.%s',
            $directory === '.' ? '' : $directory,
            $filename,
            $extension,
        );
    }
}
