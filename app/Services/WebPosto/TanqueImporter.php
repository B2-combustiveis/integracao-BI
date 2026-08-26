<?php
namespace App\Services\WebPosto;
use Illuminate\Support\Facades\DB;
class TanqueImporter {
 public function __construct(private readonly RawResourceImporter $raw){}
 public function import(mixed $payload,int $empresa,array $parameters=[]): array {
  $rows=is_array($payload)&&isset($payload['resultados'])&&is_array($payload['resultados'])?$payload['resultados']:[];
  $valid=[];$skipped=0;$db=DB::connection('webposto');
  foreach($rows as $row){
   if(!is_array($row)||(int)($row['empresaCodigo']??0)!==$empresa||!isset($row['tanqueCodigo'],$row['produtoCodigo'])){$skipped++;continue;}
   if(!$db->table('produtos')->where('empresaCodigo',$empresa)->where('produtoCodigo',$row['produtoCodigo'])->exists()){$skipped++;continue;}
   $valid[]=$row;
  }
  $stored=$this->raw->import(['resultados'=>$valid],$empresa,'tanques',$parameters);
  $stored['received']=count($rows);$stored['skipped']+=$skipped;return $stored;
 }
}
