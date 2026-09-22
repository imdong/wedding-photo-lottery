<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PreparePhotoUploadsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'photos' => ['required', 'array', 'list', 'min:1', 'max:'.config('photos.max_batch_size')],
            'photos.*' => ['required', 'array:original_file_hash,upload_file_hash,filename,original_mime_type,original_file_size,upload_mime_type,upload_file_size'],
            'photos.*.original_file_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'photos.*.upload_file_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'photos.*.filename' => ['required', 'string', 'max:255'],
            'photos.*.original_mime_type' => ['required', 'string', Rule::in(config('photos.allowed_mime_types'))],
            'photos.*.original_file_size' => ['required', 'integer', 'min:1', 'max:'.config('photos.max_original_size_bytes')],
            'photos.*.upload_mime_type' => ['required', 'string', Rule::in(config('photos.allowed_mime_types'))],
            'photos.*.upload_file_size' => ['required', 'integer', 'min:1', 'max:'.config('photos.max_display_size_bytes')],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<array<string, int|string>> $photos */
            $photos = $this->input('photos', []);
            $largeOriginalSizeBytes = (int) config('photos.large_original_size_bytes');

            foreach ($photos as $index => $photo) {
                $isLargeOriginal = $photo['original_file_size'] > $largeOriginalSizeBytes;

                if ($isLargeOriginal && $photo['upload_mime_type'] !== 'image/webp') {
                    $validator->errors()->add("photos.{$index}.upload_mime_type", '大图的展示版本必须使用 WebP 格式。');
                }

                if (! $isLargeOriginal && (
                    $photo['original_file_hash'] !== $photo['upload_file_hash']
                    || $photo['original_mime_type'] !== $photo['upload_mime_type']
                    || $photo['original_file_size'] !== $photo['upload_file_size']
                )) {
                    $validator->errors()->add("photos.{$index}.upload_file_hash", '20 MB 以内的照片必须直接上传原片。');
                }
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $maxOriginalSizeMegabytes = (int) (config('photos.max_original_size_bytes') / 1024 / 1024);
        $maxDisplaySizeMegabytes = (int) (config('photos.max_display_size_bytes') / 1024 / 1024);

        return [
            'photos.required' => '请选择需要上传的照片。',
            'photos.array' => '照片列表格式不正确。',
            'photos.list' => '照片列表格式不正确。',
            'photos.min' => '请选择需要上传的照片。',
            'photos.max' => '单次最多选择 :max 张照片。',
            'photos.*.array' => '照片信息格式不正确。',
            'photos.*.original_file_hash.required' => '缺少原始文件哈希。',
            'photos.*.original_file_hash.regex' => '原始文件哈希格式不正确。',
            'photos.*.upload_file_hash.required' => '缺少实际上传文件哈希。',
            'photos.*.upload_file_hash.regex' => '实际上传文件哈希格式不正确。',
            'photos.*.filename.required' => '缺少照片文件名。',
            'photos.*.filename.max' => '照片文件名不能超过 255 个字符。',
            'photos.*.original_mime_type.in' => '不支持这种原片格式。',
            'photos.*.original_file_size.integer' => '原片大小格式不正确。',
            'photos.*.original_file_size.min' => '原片内容不能为空。',
            'photos.*.original_file_size.max' => "单张原片不能超过 {$maxOriginalSizeMegabytes} MB。",
            'photos.*.upload_mime_type.in' => '不支持这种展示图片格式。',
            'photos.*.upload_file_size.integer' => '展示图片大小格式不正确。',
            'photos.*.upload_file_size.min' => '展示图片内容不能为空。',
            'photos.*.upload_file_size.max' => "展示图片不能超过 {$maxDisplaySizeMegabytes} MB。",
        ];
    }
}
