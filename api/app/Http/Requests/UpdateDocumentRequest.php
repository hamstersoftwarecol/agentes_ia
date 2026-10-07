<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('document'));
    }

    public function rules(): array
    {
        $isFile = $this->route('document')->type === DocumentType::File;

        return [
            'title' => ['required', 'string', 'max:200'],
            // Los archivos solo permiten renombrar; su texto sale del archivo.
            'content' => $isFile
                ? ['prohibited']
                : ['required', 'string', 'max:'.($this->route('document')->type === DocumentType::Faq ? 10000 : (int) config('knowledge.max_text_chars'))],
        ];
    }
}
