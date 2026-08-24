<?php
namespace App\Console\Commands;
use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\{BicoImporter,WebPostoCursorSynchronizer};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class LoadBicosFromStart extends Command {
 protected $signature='webposto:load-bicos {empresa=4604} {--resume} {--pages=20}';
 protected $description='Zera e recarrega bicos do cursor 1 ate o ultimo registro';
 public function handle(WebPostoCursorSynchronizer $sync,BicoImporter $importer): int {
  $empresa=(int)$this->argument('empresa');$key='/INTEGRACAO/BICO:manual-initial';$resume=(bool)$this->option('resume');
  if(!$resume){DB::connection('webposto')->table('bicos')->truncate();WebPostoSyncControl::where('empresa_codigo',$empresa)->where('endpoint',$key)->delete();}
  $totals=$sync->synchronize(endpoint:'/INTEGRACAO/BICO',empresaCodigo:$empresa,persist:fn($payload,$parameters)=>$importer->import($payload,$empresa,$parameters),query:['dataInicial'=>'2000-01-01','dataFinal'=>now()->toDateString(),'limite'=>1000],cursor:['initial_value'=>1,'prefer_initial_value'=>!$resume],initialQuery:null,maxPages:max(1,(int)$this->option('pages')),controlKey:$key);
  $this->info(json_encode($totals));
  return self::SUCCESS;
 }
}
