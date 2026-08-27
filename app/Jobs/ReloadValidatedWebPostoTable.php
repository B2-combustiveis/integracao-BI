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
    DB::connection('webposto')->table('abastecimentos')->where('empresaCodigo',$run->empresa_codigo)->delete();
    $this->load('webposto:load-venda-itens','/INTEGRACAO/VENDA_ITEM:manual-initial',$run->empresa_codigo);
    $run->update(['processed_tables'=>['venda_itens']]);
    $this->load('webposto:load-abastecimentos','/INTEGRACAO/ABASTECIMENTO:manual-initial',$run->empresa_codigo);
    $done=['venda_itens','abastecimentos'];
   }elseif($run->resource==='abastecimentos'){
    $this->load('webposto:load-abastecimentos','/INTEGRACAO/ABASTECIMENTO:manual-initial',$run->empresa_codigo);
    $done=['abastecimentos'];
   }elseif($run->resource==='bombas'){
    DB::connection('webposto')->table('bicos')->where('empresaCodigo',$run->empresa_codigo)->delete();
    $this->load('webposto:load-bombas','/INTEGRACAO/BOMBA:manual-initial',$run->empresa_codigo);
    $run->update(['processed_tables'=>['bombas']]);
    $this->load('webposto:load-bicos','/INTEGRACAO/BICO:manual-initial',$run->empresa_codigo);
    $done=['bombas','bicos'];
   }elseif($run->resource==='bicos'){
    $this->load('webposto:load-bicos','/INTEGRACAO/BICO:manual-initial',$run->empresa_codigo);
    $done=['bicos'];
   }elseif($run->resource==='tanques'){
    DB::connection('webposto')->table('bicos')->where('empresaCodigo',$run->empresa_codigo)->delete();
    $this->load('webposto:load-tanques','/INTEGRACAO/TANQUE:manual-initial',$run->empresa_codigo);
    $run->update(['processed_tables'=>['tanques']]);
    $this->load('webposto:load-bicos','/INTEGRACAO/BICO:manual-initial',$run->empresa_codigo);
    $done=['tanques','bicos'];
   }elseif($run->resource==='administradoras'){
    $resuming=in_array('administradoras',$run->processed_tables??[],true);
    if(!$resuming){
     DB::connection('webposto')->table('cartoes')->where('empresaCodigo',$run->empresa_codigo)->delete();
     $this->load('webposto:load-administradoras','/INTEGRACAO/ADMINISTRADORA:manual-initial',$run->empresa_codigo);
     $run->update(['processed_tables'=>['administradoras']]);
    }
    $this->load('webposto:load-cartoes','/INTEGRACAO/CARTAO:manual-initial',$run->empresa_codigo,$resuming);
    $done=['administradoras','cartoes'];
   }elseif($run->resource==='produto_grupos'){
    $exit=Artisan::call('webposto:load-produto-grupos',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de grupos de produtos.');
    $done=['produto_grupos'];
   }elseif($run->resource==='produto_subgrupos'){
    $exit=Artisan::call('webposto:load-produto-subgrupos',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de subgrupos de produtos.');
    $done=['produto_subgrupos'];
   }elseif($run->resource==='produtos'){
    $exit=Artisan::call('webposto:load-produtos',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de produtos.');
    $done=['produtos'];
   }elseif($run->resource==='produto_empresas'){
    $exit=Artisan::call('webposto:load-produto-empresas',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de produtos por empresa.');
    $done=['produto_empresas'];
   }elseif($run->resource==='produto_lmc_lmp'){
    $exit=Artisan::call('webposto:load-produto-lmc-lmp',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de produtos LMC/LMP.');
    $done=['produto_lmc_lmp'];
   }elseif($run->resource==='vales_funcionario'){
    $exit=Artisan::call('webposto:load-vales-funcionario',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de vales de funcionarios.');
    $done=['vales_funcionario'];
   }elseif($run->resource==='funcionario_funcoes'){
    $exit=Artisan::call('webposto:load-funcionario-funcoes',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de funcoes de funcionarios.');
    $done=['funcionario_funcoes'];
   }elseif($run->resource==='caixas'){
    $exit=Artisan::call('webposto:load-caixas',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de caixas.');
    $run->update(['processed_tables'=>['caixas']]);
    $this->load('webposto:load-caixas-apresentados','/INTEGRACAO/CAIXA_APRESENTADO:manual-initial',$run->empresa_codigo);
    $done=['caixas','caixas_apresentados'];
   }elseif($run->resource==='caixas_apresentados'){
    $this->load('webposto:load-caixas-apresentados','/INTEGRACAO/CAIXA_APRESENTADO:manual-initial',$run->empresa_codigo);
    $done=['caixas_apresentados'];
   }elseif(in_array($run->resource,['planos_conta_gerencial','planos_conta_contabil'],true)){
    $tipo=$run->resource==='planos_conta_gerencial'?'gerencial':'contabil';
    $exit=Artisan::call('webposto:load-planos-conta',['tipo'=>$tipo,'empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de planos de conta.');
    $done=[$run->resource];
   }elseif($run->resource==='lmcs'){
    $exit=Artisan::call('webposto:load-lmcs',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de LMCs.');
    $done=['lmcs'];
   }elseif($run->resource==='cartoes'){
    $this->load('webposto:load-cartoes','/INTEGRACAO/CARTAO:manual-initial',$run->empresa_codigo);
    $done=['cartoes'];
   }elseif($run->resource==='contas_bancarias'){
    DB::connection('webposto')->table('movimentos_conta')->where('empresaCodigo',$run->empresa_codigo)->delete();
    $this->load('webposto:load-contas-bancarias','/INTEGRACAO/CONTA:manual-initial',$run->empresa_codigo);
    $run->update(['processed_tables'=>['contas_bancarias']]);
    $this->load('webposto:load-movimentos-conta','/INTEGRACAO/MOVIMENTO_CONTA:manual-initial',$run->empresa_codigo);
    $done=['contas_bancarias','movimentos_conta'];
   }elseif($run->resource==='movimentos_conta'){
    $this->load('webposto:load-movimentos-conta','/INTEGRACAO/MOVIMENTO_CONTA:manual-initial',$run->empresa_codigo);
    $done=['movimentos_conta'];
   }elseif($run->resource==='funcionarios'){
    DB::connection('webposto')->table('vales_funcionario')->where('empresaCodigo',$run->empresa_codigo)->delete();
    $exit=Artisan::call('webposto:load-funcionario-funcoes',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de funcoes de funcionarios.');
    $this->load('webposto:load-funcionarios','/INTEGRACAO/FUNCIONARIO:manual-initial',$run->empresa_codigo);
    $exit=Artisan::call('webposto:load-vales-funcionario',['empresa'=>$run->empresa_codigo]);
    if($exit!==0)throw new RuntimeException('Falha na carga de vales de funcionarios.');
    $done=['funcionario_funcoes','funcionarios','vales_funcionario'];
   }elseif($run->resource==='estoque_periodos'){
    $this->load('webposto:load-estoque-periodos','/INTEGRACAO/ESTOQUE_PERIODO:manual-initial',$run->empresa_codigo);
    $done=['estoque_periodos'];
   }elseif($run->resource==='fornecedores'){
    $this->load('webposto:load-fornecedores','/INTEGRACAO/FORNECEDOR:manual-initial',$run->empresa_codigo);
    $done=['fornecedores'];
   }elseif($run->resource==='compras'){
    $this->load('webposto:load-compras','/INTEGRACAO/COMPRA:manual-initial',$run->empresa_codigo);
    $done=['compras'];
   }elseif($run->resource==='compra_itens'){
    $this->load('webposto:load-compra-itens','/INTEGRACAO/COMPRA_ITEM:manual-initial',$run->empresa_codigo);
    $done=['compra_itens'];
   }elseif($run->resource==='titulos_pagar'){
    $this->loadIncremental('titulos_pagar','/INTEGRACAO/TITULO_PAGAR:manual-initial',$run->empresa_codigo);
    $done=['titulos_pagar'];
   }elseif($run->resource==='clientes'){
    DB::connection('webposto')->table('cliente_empresas')->where('empresaCodigo',$run->empresa_codigo)->delete();
    $this->loadIncremental('clientes','/INTEGRACAO/CLIENTE:manual-initial',$run->empresa_codigo);
    $run->update(['processed_tables'=>['clientes']]);
    $this->loadIncremental('cliente_empresas','/INTEGRACAO/CLIENTE_EMPRESA:manual-initial',$run->empresa_codigo);
    $done=['clientes','cliente_empresas'];
   }elseif($run->resource==='vendas'){
    $connection=DB::connection('webposto');
    foreach(['cartoes','abastecimentos','venda_itens','venda_formas_pagamento'] as $table){
     $connection->table($table)->where('empresaCodigo',$run->empresa_codigo)->delete();
    }
    $this->loadIncremental('vendas','/INTEGRACAO/VENDA:manual-initial',$run->empresa_codigo);
    $run->update(['processed_tables'=>['vendas']]);
    $this->loadIncremental('venda_formas_pagamento','/INTEGRACAO/VENDA_FORMA_PAGAMENTO:manual-initial',$run->empresa_codigo);
    $this->load('webposto:load-venda-itens','/INTEGRACAO/VENDA_ITEM:manual-initial',$run->empresa_codigo);
    $this->load('webposto:load-abastecimentos','/INTEGRACAO/ABASTECIMENTO:manual-initial',$run->empresa_codigo);
    $this->load('webposto:load-cartoes','/INTEGRACAO/CARTAO:manual-initial',$run->empresa_codigo);
    $done=['vendas','venda_formas_pagamento','venda_itens','abastecimentos','cartoes'];
   }elseif($run->resource==='venda_formas_pagamento'){
    $this->loadIncremental('venda_formas_pagamento','/INTEGRACAO/VENDA_FORMA_PAGAMENTO:manual-initial',$run->empresa_codigo);
    $done=['venda_formas_pagamento'];
   }elseif($run->resource==='titulos_receber'){
    $this->loadIncremental('titulos_receber','/INTEGRACAO/TITULO_RECEBER:manual-initial',$run->empresa_codigo);
    $done=['titulos_receber'];
   }else throw new RuntimeException('Tabela nao habilitada.');
   $run->update(['status'=>'success','processed_tables'=>$done,'finished_at'=>now()]);
  }catch(Throwable $e){
   $run->update(['status'=>'failed','finished_at'=>now(),'error'=>mb_substr($e->getMessage(),0,2000)]);
   throw $e;
  }
 }
 private function load(string $command,string $key,int $empresa,bool $resume=false): void {
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
 private function loadIncremental(string $resource,string $key,int $empresa): void {
  $resume=false;
  for($batch=0;$batch<1000;$batch++){
   try{$exit=Artisan::call('webposto:load-incremental-resource',['resource'=>$resource,'empresa'=>$empresa,'--pages'=>80,'--resume'=>$resume]);}
   catch(Throwable $e){if(!$this->control($key,$empresa)||!str_contains($e->getMessage(),'Limite de paginacao atingido'))throw $e;}
   $control=$this->control($key,$empresa);
   if($control?->status==='ok')return;
   if(($exit??1)!==0&&!str_contains((string)$control?->last_error,'Limite de paginacao atingido')){
    throw new RuntimeException($control?->last_error?:'Falha na carga '.$resource);
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
