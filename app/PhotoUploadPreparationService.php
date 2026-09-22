<?php

namespace App;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PhotoUploadPreparationService
{
    public function __construct(private OssConfiguration $configuration) {}

    /**
     * @param  list<array{
     *     original_file_hash: string,
     *     upload_file_hash: string,
     *     filename: string,
     *     original_mime_type: string,
     *     original_file_size: int,
     *     upload_mime_type: string,
     *     upload_file_size: int
     * }>  $photos
     * @return list<array<string, int|string|list<string>>>
     */
    public function prepare(User $user, array $photos): array
    {
        return DB::transaction(function () use ($user, $photos): array {
            $results = [];

            foreach ($photos as $index => $photoData) {
                $hashes = array_values(array_unique([
                    $photoData['original_file_hash'],
                    $photoData['upload_file_hash'],
                ]));
                $duplicateHashes = $this->findDuplicateHashes($hashes);

                if ($duplicateHashes !== []) {
                    $results[] = [
                        'index' => $index,
                        'status' => 'duplicate',
                        'duplicate_hashes' => $duplicateHashes,
                    ];

                    continue;
                }

                [$objectKey, $originalObjectKey] = $this->makeObjectKeys($user, $photoData);

                $photo = $user->photos()->create([
                    'object_key' => $objectKey,
                    'original_object_key' => $originalObjectKey,
                    'original_filename' => $photoData['filename'],
                    'original_file_hash' => $photoData['original_file_hash'],
                    'upload_file_hash' => $photoData['upload_file_hash'],
                    'mime_type' => $photoData['original_mime_type'],
                    'file_size' => $photoData['original_file_size'],
                ]);

                $results[] = [
                    'index' => $index,
                    'status' => 'ready',
                    'photo_id' => $photo->id,
                    'object_key' => $photo->object_key,
                    'original_object_key' => $photo->original_object_key,
                    'original_file_hash' => $photo->original_file_hash,
                    'upload_file_hash' => $photo->upload_file_hash,
                ];
            }

            return $results;
        });
    }

    /**
     * @param  list<string>  $hashes
     * @return list<string>
     */
    private function findDuplicateHashes(array $hashes): array
    {
        $knownHashes = Photo::query()
            ->whereIn('original_file_hash', $hashes)
            ->orWhereIn('upload_file_hash', $hashes)
            ->get(['original_file_hash', 'upload_file_hash'])
            ->flatMap(fn (Photo $photo): array => [
                $photo->original_file_hash,
                $photo->upload_file_hash,
            ])
            ->unique()
            ->all();

        return array_values(array_intersect($hashes, $knownHashes));
    }

    /**
     * @param  array{original_mime_type: string, original_file_size: int, upload_mime_type: string}  $photoData
     * @return array{string, string}
     */
    private function makeObjectKeys(User $user, array $photoData): array
    {
        $baseName = sprintf(
            '%s/%s/%s',
            $this->configuration->uploadPrefix,
            $user->getKey(),
            Str::orderedUuid(),
        );
        $originalExtension = $this->extensionForMimeType($photoData['original_mime_type']);
        $isLargeOriginal = $photoData['original_file_size'] > (int) config('photos.large_original_size_bytes');

        if (! $isLargeOriginal) {
            $objectKey = "{$baseName}.{$originalExtension}";

            return [$objectKey, $objectKey];
        }

        return [
            $baseName.'.'.$this->extensionForMimeType($photoData['upload_mime_type']),
            "{$baseName}.orig.{$originalExtension}",
        ];
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
        };
    }
}
