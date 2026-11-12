<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PubliciteVue extends Model
{
    protected $table = 'publicite_vues';

    protected $fillable = [
        'publicite_id',
        'user_id',
        'vu_at',
        'pris_note_at',
    ];

    protected $casts = [
        'vu_at' => 'datetime',
        'pris_note_at' => 'datetime',
    ];

    public function publicite(): BelongsTo
    {
        return $this->belongsTo(Publicite::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(AutoEcoleUser::class, 'user_id');
    }
}
