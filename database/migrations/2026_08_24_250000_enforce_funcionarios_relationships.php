<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $db = DB::connection('webposto');
        $db->statement('ALTER TABLE funcionario_funcoes MODIFY funcaoCodigo BIGINT NOT NULL, ADD UNIQUE funcionario_funcoes_codigo_unique (funcaoCodigo)');
        $db->statement('ALTER TABLE funcionarios MODIFY empresaCodigo BIGINT NOT NULL, MODIFY funcionarioCodigo BIGINT NOT NULL, MODIFY funcaoCodigo BIGINT NOT NULL, ADD UNIQUE funcionarios_empresa_codigo_unique (empresaCodigo, funcionarioCodigo), ADD CONSTRAINT funcionarios_funcao_fk FOREIGN KEY (funcaoCodigo) REFERENCES funcionario_funcoes (funcaoCodigo) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        $db = DB::connection('webposto');
        $db->statement('ALTER TABLE funcionarios DROP FOREIGN KEY funcionarios_funcao_fk, DROP INDEX funcionarios_empresa_codigo_unique, MODIFY empresaCodigo BIGINT NULL, MODIFY funcionarioCodigo BIGINT NULL, MODIFY funcaoCodigo BIGINT NULL');
        $db->statement('ALTER TABLE funcionario_funcoes DROP INDEX funcionario_funcoes_codigo_unique, MODIFY funcaoCodigo BIGINT NULL');
    }
};
