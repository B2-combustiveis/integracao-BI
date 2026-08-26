<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  $db=DB::connection('webposto');
  $db->statement('ALTER TABLE administradoras ADD UNIQUE administradoras_empresa_codigo_unique (empresaCodigo,administradoraCodigo)');
  $db->statement('ALTER TABLE centros_custo ADD UNIQUE centros_custo_codigo_unique (centroCustoCodigo)');
  $db->statement('ALTER TABLE cartoes MODIFY empresaCodigo BIGINT NOT NULL, MODIFY cartaoCodigo BIGINT NOT NULL, MODIFY vendaCodigo BIGINT NOT NULL, MODIFY administradoraCodigo BIGINT NOT NULL, MODIFY centroCustoCodigo BIGINT NOT NULL');
  $db->statement('ALTER TABLE cartoes ADD UNIQUE cartoes_empresa_codigo_unique (empresaCodigo,cartaoCodigo), ADD CONSTRAINT cartoes_venda_fk FOREIGN KEY (empresaCodigo,vendaCodigo) REFERENCES vendas (empresaCodigo,vendaCodigo) ON DELETE RESTRICT, ADD CONSTRAINT cartoes_administradora_fk FOREIGN KEY (empresaCodigo,administradoraCodigo) REFERENCES administradoras (empresaCodigo,administradoraCodigo) ON DELETE RESTRICT, ADD CONSTRAINT cartoes_centro_custo_fk FOREIGN KEY (centroCustoCodigo) REFERENCES centros_custo (centroCustoCodigo) ON DELETE RESTRICT');
 }
 public function down(): void {
  $db=DB::connection('webposto');
  foreach(['cartoes_venda_fk','cartoes_administradora_fk','cartoes_centro_custo_fk'] as $fk){try{$db->statement('ALTER TABLE cartoes DROP FOREIGN KEY '.$fk);}catch(Throwable){}}
  foreach([['cartoes','cartoes_empresa_codigo_unique'],['administradoras','administradoras_empresa_codigo_unique'],['centros_custo','centros_custo_codigo_unique']] as [$table,$index]){try{$db->statement('ALTER TABLE '.$table.' DROP INDEX '.$index);}catch(Throwable){}}
 }
};
