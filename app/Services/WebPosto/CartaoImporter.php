<?php
namespace App\Services\WebPosto;
use Illuminate\Support\Facades\DB;
class CartaoImporter {
 public function __construct(private readonly RawResourceImporter $raw){}
 public function import(mixed $payload,int $empresa,array $parameters=[]): array {
  $rows=is_array($payload)&&isset($payload['resultados'])&&is_array($payload['resultados'])?$payload['resultados']:[];
  $db=DB::connection('webposto');
  $sales=$db->table('vendas')->where('empresaCodigo',$empresa)->whereIn('vendaCodigo',array_values(array_unique(array_column($rows,'vendaCodigo'))))->pluck('vendaCodigo')->mapWithKeys(fn($v)=>[(string)$v=>true])->all();
  $admins=$db->table('administradoras')->where('empresaCodigo',$empresa)->whereIn('administradoraCodigo',array_values(array_unique(array_column($rows,'administradoraCodigo'))))->pluck('administradoraCodigo')->mapWithKeys(fn($v)=>[(string)$v=>true])->all();
  $centers=$db->table('centros_custo')->whereIn('centroCustoCodigo',array_values(array_unique(array_column($rows,'centroCustoCodigo'))))->pluck('centroCustoCodigo')->mapWithKeys(fn($v)=>[(string)$v=>true])->all();
  $valid=[];$skipped=0;
  foreach($rows as $row){
   if(!is_array($row)||(int)($row['empresaCodigo']??0)!==$empresa||!isset($row['cartaoCodigo'],$row['vendaCodigo'],$row['administradoraCodigo'],$row['centroCustoCodigo'])){$skipped++;continue;}
   $saleOptional=(int)$row['vendaCodigo']===0 && in_array($row['tipoInclusao']??null,['Troca de Valores','EDI','Serviço'],true);
   if((!$saleOptional&&!isset($sales[(string)$row['vendaCodigo']]))||!isset($admins[(string)$row['administradoraCodigo']],$centers[(string)$row['centroCustoCodigo']])){$skipped++;continue;}
   if($saleOptional)$row['vendaCodigo']=null;
   $valid[]=$row;
  }
  $stored=$this->raw->import(['resultados'=>$valid],$empresa,'cartoes',$parameters);
  $stored['received']=count($rows);$stored['skipped']+=$skipped;return $stored;
 }
}
