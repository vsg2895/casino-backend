<?php

declare(strict_types=1);

namespace App\Perfume\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class Subscriber extends PerfumeModel
{
    protected $table = 'perfume_subscribers';

    protected $fillable = ['email', 'name', 'active', 'token', 'unsubscribed_at', 'last_sent_at'];

    /**
     * The unsubscribe token is a credential: it opts its owner out with no
     * other proof, so it never leaves the server in an admin response.
     *
     * @var list<string>
     */
    protected $hidden = ['token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'active'          => 'boolean',
            'unsubscribed_at' => 'datetime',
            'last_sent_at'    => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public static function generateToken(): string
    {
        return Str::random(64);
    }
}
