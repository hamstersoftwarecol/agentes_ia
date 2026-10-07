<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Document::class);
    }

    public function rules(): array
    {
        $maxChars = (int) config('knowledge.max_text_chars');
        $extensions = implode(',', config('knowledge.allowed_extensions'));

        return match ($this->input('type')) {
            DocumentType::Text->value => [
                'type' => ['required'],
                'title' => ['required', 'string', 'max:200'],
                'content' => ['required', 'string', "max:$maxChars"],
            ],
            // En una FAQ el título es la pregunta y el contenido la respuesta.
            DocumentType::Faq->value => [
                'type' => ['required'],
                'title' => ['required', 'string', 'max:200'],
                'content' => ['required', 'string', 'max:10000'],
            ],
            DocumentType::File->value => [
                'type' => ['required'],
                'title' => ['nullable', 'string', 'max:200'],
                'file' => [
                    'required', 'file', 'max:'.config('knowledge.max_upload_kb'),
                    "extensions:$extensions", "mimes:$extensions",
                ],
            ],
            default => ['type' => ['required', Rule::enum(DocumentType::class)]],
        };
    }

    public function messages(): array
    {
        return [
            'file.extensions' => 'Solo se aceptan archivos PDF, TXT o MD.',
            'file.mimes' => 'Solo se aceptan archivos PDF, TXT o MD.',
            'file.max' => 'El archivo no puede pesar más de '.(int) (config('knowledge.max_upload_kb') / 1024).' MB.',
        ];
    }
}
