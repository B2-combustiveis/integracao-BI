<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class IntegrationServiceRunChange extends Model {
 protected $guarded = [];
 protected function casts(): array {
  return ['natural_key'=>'array','payload'=>'array','source_updated_at'=>'datetime','detected_at'=>'datetime'];
 }
 public function run(): BelongsTo { return $this->belongsTo(IntegrationServiceRun::class, 'integration_service_run_id'); }
}
