<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'photos.*' => ['required', 'array:original_file_hash,upload_file_hash,filename,mime_type,file_size'],
            'photos.*.original_file_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'photos.*.upload_file_hash' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'photos.*.filename' => ['required', 'string', 'max:255'],
            'photos.*.mime_type' => ['required', 'string', Rule::in(config('photos.allowed_mime_types'))],
            'photos.*.file_size' => ['required', 'integer', 'min:1', 'max:'.config('photos.max_size_bytes')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $maxSizeMegabytes = (int) (config('photos.max_size_bytes') / 1024 / 1024);

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
            'photos.*.mime_type.in' => '不支持这种照片格式。',
            'photos.*.file_size.integer' => '照片大小格式不正确。',
            'photos.*.file_size.min' => '照片内容不能为空。',
            'photos.*.file_size.max' => "单张照片不能超过 {$maxSizeMegabytes} MB。",
        ];
    }
}
