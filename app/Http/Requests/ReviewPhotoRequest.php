<?php

namespace App\Http\Requests;

use App\Enums\PhotoReviewStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewPhotoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                'string',
                Rule::in([
                    PhotoReviewStatus::Normal->value,
                    PhotoReviewStatus::Good->value,
                    PhotoReviewStatus::Rejected->value,
                ]),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status.required' => '请选择审核结果。',
            'status.string' => '审核结果无效。',
            'status.in' => '审核结果无效。',
        ];
    }
}
