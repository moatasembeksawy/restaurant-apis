<?php

declare(strict_types=1);

namespace App\Modules\POS\Menu\Http\Requests;

use App\Shared\Support\Http\Requests\ApiFormRequest;

class UploadMenuItemPhotoRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }
}
