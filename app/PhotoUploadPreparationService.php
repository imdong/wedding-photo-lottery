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
     *     mime_type: string,
     *     file_size: int
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

                $photo = $user->photos()->create([
                    'object_key' => $this->makeObjectKey($user, $photoData['mime_type']),
                    'original_filename' => $photoData['filename'],
                    'original_file_hash' => $photoData['original_file_hash'],
                    'upload_file_hash' => $photoData['upload_file_hash'],
                    'mime_type' => $photoData['mime_type'],
                    'file_size' => $photoData['file_size'],
                ]);

                $results[] = [
                    'index' => $index,
                    'status' => 'ready',
                    'photo_id' => $photo->id,
                    'object_key' => $photo->object_key,
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

    private function makeObjectKey(User $user, string $mimeType): string
    {
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
        };

        return sprintf(
            '%s/%s/%s.%s',
            $this->configuration->uploadPrefix,
            $user->getKey(),
            Str::orderedUuid(),
            $extension,
        );
    }
}
