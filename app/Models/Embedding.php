<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['chunk_id', 'provider', 'model', 'part', 'vector'])]
class Embedding extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'vector' => AsVector::class,
        ];
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(Chunk::class);
    }
}
