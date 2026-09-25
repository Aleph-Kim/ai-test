<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'content', 'citations', 'follow_ups', 'provider', 'model', 'elapsed_ms'])]
class Message extends Model
{
    protected function casts(): array
    {
        return [
            'citations' => 'array',
            'follow_ups' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
