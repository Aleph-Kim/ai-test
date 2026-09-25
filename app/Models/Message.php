<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'content', 'calculation', 'citations', 'clarifications', 'provider', 'model', 'elapsed_ms'])]
class Message extends Model
{
    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'citations' => 'array',
            'clarifications' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
