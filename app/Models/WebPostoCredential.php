<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPostoCredential extends Model
{
    protected $connection = 'webposto';

    protected $table = 'webposto_credentials';

    protected $fillable = [
        'empresa_codigo',
        'base_url',
        'token',
        'ativo',
        'ultimo_uso_em',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'ativo' => 'boolean',
            'ultimo_uso_em' => 'datetime',
        ];
    }
}
