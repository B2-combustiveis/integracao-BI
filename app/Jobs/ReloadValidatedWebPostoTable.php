<?php
namespace App\Jobs;
use App\Models\{WebPostoReloadRun,WebPostoSyncControl};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\{ShouldBeUnique,ShouldQueue};
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue,SerializesModels};
use Illuminate\Support\Facades\{Artisan,DB};
use RuntimeException;
use Throwable;
class ReloadValidatedWebPostoTable implements ShouldQueue,ShouldBeUnique {
 use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
 public int $tries=1,$timeout=7200,$uniqueFor=7500;
 public function __construct(public readonly int $runId){$this->onQueue('default');}
 public function uniqueId(): string {
  $run=WebPostoReloadRun::find($this->runId);
  return $run ? $run->empresa_codigo.':'.$run->resource : (string)$this->runId;
 }
 public function handle(): void {
  $run=WebPostoReloadRun::findOrFail($this->runId);
  $run->update(['status'=>'running','started_at'=>now(),'error'=>null]);
  try {
   if($run->resource==='venda_itens'){
    DB::connection('webposto')->table('abastecimentos')->truncate();
    $this->load('webposto:load-venda-itens','/INTEGRACAO/VENDA_ITEM:manual-initial',$run->empresa_codigo);
    $run->update(['processed_tables'=>['venda_itens']]);
    $this->load('webposto:load-abastecimentos','/INTEGRACAO/ABASTECIMENTO:manual-initial',$run->empresa_codigo);
    $done=['venda_itens','abastecimentos'];
   }elseif($run->resource==='abastecimentos'){
    $this->load('webposto:load-abastecimentos','/INTEGRACAO/ABASTECIMENTO:manual-initial',$run->empresa_codigo);
    $done=['abastecimentos'];
   }elseif($run->resource==='bombas'){
    DB::connection('webposto')->table('bicos')->truncate();
    $this->load('webposto:load-bombas','/INTEGRACAO/BOMBA:manual-initial',$run->empresa_codigo);
    $run->update(['processed_tables'=>['bombas']]);
    $this->load('webposto:load-bicos','/INTEGRACAO/BICO:manual-initial',$run->empresa_codigo);
    $done=['bombas','bicos'];
   }elseif($run->resource==='bicos'){
    $this->load('webposto:load-bicos','/INTEGRACAO/BICO:manual-initial',$run->empresa_codigo);
    $done=['bicos'];
   }else throw new RuntimeException('Tabela nao habilitada.');
   $run->update(['status'=>'success','processed_tables'=>$done,'finished_at'=>now()]);
  }catch(Throwable $e){
   $run->update(['status'=>'failed','finished_at'=>now(),'error'=>mb_substr($e->getMessage(),0,2000)]);
   throw $e;
  }
 }
 private function load(string $command,string $key,int $empresa): void {
  $resume=false;
  for($batch=0;$batch<1000;$batch++){
   try{$exit=Artisan::call($command,['empresa'=>$empresa,'--pages'=>80,'--resume'=>$resume]);}
   catch(Throwable $e){if(!$this->control($key,$empresa)||!str_contains($e->getMessage(),'Limite de paginacao atingido'))throw $e;}
   $control=$this->control($key,$empresa);
   if($control?->status==='ok')return;
   if(($exit??1)!==0&&!str_contains((string)$control?->last_error,'Limite de paginacao atingido')){
    throw new RuntimeException($control?->last_error?:'Falha na carga '.$command);
   }
   $resume=true;
  }
  throw new RuntimeException('Limite de lotes excedido.');
 }
 private function control(string $key,int $empresa): ?WebPostoSyncControl {
  return WebPostoSyncControl::where('empresa_codigo',$empresa)->where('endpoint',$key)->first();
 }
 public function failed(?Throwable $e): void {
  WebPostoReloadRun::whereKey($this->runId)->whereIn('status',['queued','running'])->update([
   'status'=>'failed','finished_at'=>now(),'error'=>mb_substr($e?->getMessage()??'Falha na fila.',0,2000)]);
 }
}
