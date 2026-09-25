<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'filename', 'content'])]
class Document extends Model
{
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
