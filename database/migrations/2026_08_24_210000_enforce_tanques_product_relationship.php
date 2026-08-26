<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  $db=DB::connection('webposto');
  $db->statement('ALTER TABLE tanques MODIFY produtoCodigo BIGINT UNSIGNED NOT NULL');
  $db->statement('ALTER TABLE tanques ADD CONSTRAINT tanques_produto_fk FOREIGN KEY (empresaCodigo,produtoCodigo) REFERENCES produtos (empresaCodigo,produtoCodigo) ON DELETE RESTRICT');
 }
 public function down(): void {
  $db=DB::connection('webposto');$db->statement('ALTER TABLE tanques DROP FOREIGN KEY tanques_produto_fk');
 }
};
