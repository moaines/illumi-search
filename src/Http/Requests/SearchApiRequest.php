<?php

namespace Moaines\IllumiSearch\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchApiRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q'       => 'required|string|min:1|max:200',
            'models'  => 'nullable|string',
            'limit'   => 'nullable|integer|min:1|max:50',
            'suggest' => 'nullable|boolean',
            'mode'    => 'nullable|in:basic,advanced,raw',
        ];
    }
}
