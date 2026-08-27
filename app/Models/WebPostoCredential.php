<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPostoCredential extends Model
{
    public const STATUS_AGUARDANDO_SINCRONIZACAO = 'aguardando_sincronizacao';

    public const STATUS_SINCRONIZADO = 'sincronizado';

    protected $connection = 'webposto';

    protected $table = 'webposto_credentials';

    protected $fillable = [
        'empresa_codigo',
        'base_url',
        'token',
        'ativo',
        'implantacao_status',
        'carga_inicial_iniciada_em',
        'carga_inicial_concluida_em',
        'carga_inicial_erro',
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
            'carga_inicial_iniciada_em' => 'datetime',
            'carga_inicial_concluida_em' => 'datetime',
            'ultimo_uso_em' => 'datetime',
        ];
    }
}
