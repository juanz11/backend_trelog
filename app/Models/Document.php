<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['type', 'number', 'expires_at', 'file_path'])]
class Document extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }
}
