<?php
namespace App\Console\Commands;
use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\{RawResourceImporter,WebPostoCursorSynchronizer};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class LoadContasBancariasFromStart extends Command {
 protected $signature='webposto:load-contas-bancarias {empresa=4604} {--resume} {--pages=20}';
 protected $description='Padrão 2000: zera e recarrega contas bancárias desde o cursor 1';
 public function handle(WebPostoCursorSynchronizer $sync,RawResourceImporter $importer): int {
  $empresa=(int)$this->argument('empresa');$key='/INTEGRACAO/CONTA:manual-initial';$resume=(bool)$this->option('resume');
  if(!$resume){DB::connection('webposto')->table('contas_bancarias')->where('empresaCodigo',$empresa)->delete();WebPostoSyncControl::where('empresa_codigo',$empresa)->where('endpoint',$key)->delete();}
  $totals=$sync->synchronize(endpoint:'/INTEGRACAO/CONTA',empresaCodigo:$empresa,persist:fn($payload,$parameters)=>$importer->import($payload,$empresa,'contas_bancarias',$parameters),query:['dataInicial'=>'2000-01-01','dataFinal'=>now()->toDateString(),'limite'=>1000,'empresaCodigo'=>$empresa],cursor:['initial_value'=>1,'prefer_initial_value'=>!$resume],initialQuery:null,maxPages:max(1,(int)$this->option('pages')),controlKey:$key);
  $this->info(json_encode($totals));return self::SUCCESS;
 }
}
