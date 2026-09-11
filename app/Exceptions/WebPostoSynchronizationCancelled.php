<?php

namespace App\Exceptions;

use RuntimeException;

class WebPostoSynchronizationCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sincronizacao cancelada porque a execucao nao esta mais ativa.');
    }
}
