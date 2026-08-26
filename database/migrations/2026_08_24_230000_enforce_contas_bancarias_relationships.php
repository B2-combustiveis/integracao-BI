<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  $db=DB::connection('webposto');
  $db->statement('ALTER TABLE contas_bancarias MODIFY empresaCodigo BIGINT NOT NULL, MODIFY contaCodigo BIGINT NOT NULL, ADD UNIQUE contas_bancarias_empresa_codigo_unique (empresaCodigo,contaCodigo)');
  $db->statement('ALTER TABLE movimentos_conta MODIFY empresaCodigo BIGINT NOT NULL, MODIFY contaCodigo BIGINT NOT NULL, ADD CONSTRAINT movimentos_conta_conta_fk FOREIGN KEY (empresaCodigo,contaCodigo) REFERENCES contas_bancarias (empresaCodigo,contaCodigo) ON DELETE RESTRICT');
 }
 public function down(): void {
  $db=DB::connection('webposto');$db->statement('ALTER TABLE movimentos_conta DROP FOREIGN KEY movimentos_conta_conta_fk');$db->statement('ALTER TABLE contas_bancarias DROP INDEX contas_bancarias_empresa_codigo_unique');
 }
};
