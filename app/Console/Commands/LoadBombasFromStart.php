<?php
namespace App\Console\Commands;
use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\{RawResourceImporter,WebPostoCursorSynchronizer};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class LoadBombasFromStart extends Command {
 protected $signature='webposto:load-bombas {empresa=4604} {--resume} {--pages=20}';
 protected $description='Zera e recarrega bombas do cursor 1 ate o ultimo registro';
 public function handle(WebPostoCursorSynchronizer $sync,RawResourceImporter $importer): int {
  $empresa=(int)$this->argument('empresa');$key='/INTEGRACAO/BOMBA:manual-initial';$resume=(bool)$this->option('resume');
  if(!$resume){DB::connection('webposto')->table('bombas')->where('empresaCodigo',$empresa)->delete();WebPostoSyncControl::where('empresa_codigo',$empresa)->where('endpoint',$key)->delete();}
  $totals=$sync->synchronize(endpoint:'/INTEGRACAO/BOMBA',empresaCodigo:$empresa,persist:fn($payload,$parameters)=>$importer->import($payload,$empresa,'bombas',$parameters),query:['dataInicial'=>'2000-01-01','dataFinal'=>now()->toDateString(),'limite'=>1000,'empresaCodigo'=>$empresa],cursor:['initial_value'=>1,'prefer_initial_value'=>!$resume,'single_page'=>true],initialQuery:null,maxPages:max(1,(int)$this->option('pages')),controlKey:$key);
  $this->info(json_encode($totals));
  return self::SUCCESS;
 }
}
