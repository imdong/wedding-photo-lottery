<?php

namespace Database\Seeders;

use App\Enums\PhotoReviewStatus;
use App\Enums\PhotoUploadStatus;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

class PhotoSeeder extends Seeder
{
    private const int PHOTO_COUNT = 68;

    private const int USER_COUNT = 8;

    private const string OSS_PREFIX = 'wedding/images';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $imageDirectory = storage_path('images');

        if (! File::isDirectory($imageDirectory)) {
            $this->command?->warn("未找到测试图片目录：{$imageDirectory}");

            return;
        }

        $imageFiles = collect(File::files($imageDirectory))
            ->filter(fn (SplFileInfo $file): bool => in_array(
                strtolower($file->getExtension()),
                ['jpg', 'jpeg', 'png', 'webp'],
                true,
            ))
            ->sortBy(fn (SplFileInfo $file): string => hash('sha256', 'wedding-demo-'.$file->getFilename()))
            ->take(self::PHOTO_COUNT)
            ->values();

        if ($imageFiles->isEmpty()) {
            $this->command?->warn("测试图片目录中没有可用图片：{$imageDirectory}");

            return;
        }

        $users = collect(range(1, self::USER_COUNT))
            ->map(fn (int $number): User => User::query()->firstOrCreate(
                ['phone' => sprintf('1399001%04d', $number)],
                ['invite_code' => sprintf('WD%06d', $number)],
            ));

        $imageFiles->each(function (SplFileInfo $file, int $index) use ($users): void {
            $filename = $file->getFilename();
            $fileHash = hash_file('sha256', $file->getPathname());

            if ($fileHash === false) {
                $this->command?->warn("无法读取测试图片：{$filename}");

                return;
            }

            $imageSize = getimagesize($file->getPathname());
            $reviewStatus = $index % 3 === 0
                ? PhotoReviewStatus::Good
                : PhotoReviewStatus::Normal;

            Photo::query()->updateOrCreate(
                ['object_key' => self::OSS_PREFIX.'/'.$filename],
                [
                    'user_id' => $users[$index % $users->count()]->id,
                    'original_object_key' => self::OSS_PREFIX.'/'.$filename,
                    'original_filename' => $filename,
                    'original_file_hash' => $fileHash,
                    'upload_file_hash' => $fileHash,
                    'mime_type' => File::mimeType($file->getPathname()) ?: 'image/jpeg',
                    'file_size' => $file->getSize(),
                    'width' => $imageSize === false ? null : $imageSize[0],
                    'height' => $imageSize === false ? null : $imageSize[1],
                    'upload_status' => PhotoUploadStatus::Uploaded,
                    'review_status' => $reviewStatus,
                    'reviewed_at' => now(),
                ],
            );
        });

        $this->command?->info(sprintf(
            '已准备 %d 个测试用户和 %d 张 OSS 测试照片。',
            $users->count(),
            $imageFiles->count(),
        ));
    }
}
