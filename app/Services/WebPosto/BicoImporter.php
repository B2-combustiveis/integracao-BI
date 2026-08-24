<?php
namespace App\Services\WebPosto;
use Illuminate\Support\Facades\DB;
class BicoImporter {
 public function __construct(private readonly RawResourceImporter $raw) {}
 public function import(mixed $payload,int $empresa,array $parameters=[]): array {
  $rows=is_array($payload)&&isset($payload['resultados'])&&is_array($payload['resultados'])?$payload['resultados']:(is_array($payload)&&array_is_list($payload)?$payload:[]);
  $valid=[];$skipped=0;$db=DB::connection('webposto');
  foreach($rows as $row){
   if(!is_array($row)||($row['empresaCodigo']??$empresa)!==$empresa||!isset($row['bicoCodigo'],$row['bombaCodigo'],$row['tanqueCodigo'],$row['produtoCodigo'],$row['produtoLmcCodigo'])){$skipped++;continue;}
   $links=[
    $db->table('bombas')->where('empresaCodigo',$empresa)->where('bombaCodigo',$row['bombaCodigo'])->exists(),
    $db->table('tanques')->where('empresaCodigo',$empresa)->where('tanqueCodigo',$row['tanqueCodigo'])->exists(),
    $db->table('produtos')->where('empresaCodigo',$empresa)->where('produtoCodigo',$row['produtoCodigo'])->exists(),
    $db->table('produto_lmc_lmp')->where('empresaCodigo',$empresa)->where('produtoLmcCodigo',$row['produtoLmcCodigo'])->exists(),
   ];
   if(in_array(false,$links,true)){$skipped++;continue;}
   $valid[]=$row;
  }
  $stored=$this->raw->import(['resultados'=>$valid],$empresa,'bicos',$parameters);
  $stored['received']=count($rows);$stored['skipped']+=$skipped;
  return $stored;
 }
}
