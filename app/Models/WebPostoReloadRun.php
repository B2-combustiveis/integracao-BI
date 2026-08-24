<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WebPostoReloadRun extends Model {
 protected $table = 'webposto_reload_runs';
 protected $guarded = [];
 protected function casts(): array {
  return ['processed_tables'=>'array','started_at'=>'datetime','finished_at'=>'datetime'];
 }
}
