<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SpeechToTextRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'textFile' => 'required|file|mimes:txt'
        ];

        if ($this->input('audioFile') == 'audio') {
            $rules['audioFile'] = 'mimes:mp3,mp4';
        }

        if ($this->input('audioFile') == 'video') {
            $rules['audioFile'] = 'mimes:mp4,3gp';

        }
        return $rules;
    }

}
