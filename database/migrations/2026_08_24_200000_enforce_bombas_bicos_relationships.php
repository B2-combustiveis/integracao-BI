<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  $db=DB::connection('webposto');$schema=$db->getDatabaseName();
  $fkExists=fn(string $name):bool=>$db->table('information_schema.REFERENTIAL_CONSTRAINTS')->where('CONSTRAINT_SCHEMA',$schema)->where('CONSTRAINT_NAME',$name)->exists();
  foreach(['bicos_bomba_fk','bicos_tanque_fk','bicos_produto_fk','bicos_produto_lmc_fk'] as $fk)if($fkExists($fk))$db->statement('ALTER TABLE bicos DROP FOREIGN KEY '.$fk);
  $db->statement('ALTER TABLE bombas MODIFY empresaCodigo BIGINT UNSIGNED NOT NULL, MODIFY bombaCodigo BIGINT UNSIGNED NOT NULL');
  $db->statement('ALTER TABLE tanques MODIFY empresaCodigo BIGINT UNSIGNED NOT NULL, MODIFY tanqueCodigo BIGINT UNSIGNED NOT NULL');
  $db->statement('ALTER TABLE bicos MODIFY empresaCodigo BIGINT UNSIGNED NOT NULL, MODIFY bicoCodigo BIGINT UNSIGNED NOT NULL, MODIFY bombaCodigo BIGINT UNSIGNED NOT NULL, MODIFY tanqueCodigo BIGINT UNSIGNED NOT NULL, MODIFY produtoCodigo BIGINT UNSIGNED NOT NULL, MODIFY produtoLmcCodigo BIGINT UNSIGNED NOT NULL');
  $indexExists=fn(string $table,string $name):bool=>$db->table('information_schema.STATISTICS')->where('TABLE_SCHEMA',$schema)->where('TABLE_NAME',$table)->where('INDEX_NAME',$name)->exists();
  if(!$indexExists('bombas','bombas_empresa_codigo_unique'))$db->statement('ALTER TABLE bombas ADD UNIQUE bombas_empresa_codigo_unique (empresaCodigo,bombaCodigo)');
  if(!$indexExists('tanques','tanques_empresa_codigo_unique'))$db->statement('ALTER TABLE tanques ADD UNIQUE tanques_empresa_codigo_unique (empresaCodigo,tanqueCodigo)');
  if(!$indexExists('bicos','bicos_empresa_codigo_unique'))$db->statement('ALTER TABLE bicos ADD UNIQUE bicos_empresa_codigo_unique (empresaCodigo,bicoCodigo)');
  $db->statement('ALTER TABLE bicos ADD CONSTRAINT bicos_bomba_fk FOREIGN KEY (empresaCodigo,bombaCodigo) REFERENCES bombas (empresaCodigo,bombaCodigo) ON DELETE RESTRICT, ADD CONSTRAINT bicos_tanque_fk FOREIGN KEY (empresaCodigo,tanqueCodigo) REFERENCES tanques (empresaCodigo,tanqueCodigo) ON DELETE RESTRICT, ADD CONSTRAINT bicos_produto_fk FOREIGN KEY (empresaCodigo,produtoCodigo) REFERENCES produtos (empresaCodigo,produtoCodigo) ON DELETE RESTRICT, ADD CONSTRAINT bicos_produto_lmc_fk FOREIGN KEY (empresaCodigo,produtoLmcCodigo) REFERENCES produto_lmc_lmp (empresaCodigo,produtoLmcCodigo) ON DELETE RESTRICT');
 }
 public function down(): void {
  $db=DB::connection('webposto');
  foreach(['bicos_bomba_fk','bicos_tanque_fk','bicos_produto_fk','bicos_produto_lmc_fk'] as $fk){try{$db->statement('ALTER TABLE bicos DROP FOREIGN KEY '.$fk);}catch(Throwable){}}
  foreach([['bicos','bicos_empresa_codigo_unique'],['bombas','bombas_empresa_codigo_unique'],['tanques','tanques_empresa_codigo_unique']] as [$table,$index]){try{$db->statement('ALTER TABLE '.$table.' DROP INDEX '.$index);}catch(Throwable){}}
 }
};
