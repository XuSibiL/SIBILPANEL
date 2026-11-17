<?php
function test_self_hook($profile,$method,$path,$body):array{
 $scope=hash('sha256',$profile['base']."\n".$profile['api_key']);$eps=&$GLOBALS['self_hook_eps'][$scope];if(!is_array($eps))$eps=[];
 if($path==='/hooks/self'&&$method==='GET')return ['status'=>200,'data'=>['key'=>'pk_test','endpoints'=>array_map(fn($e)=>array_diff_key($e,['secret'=>1]),array_values($eps))]];
 if($path==='/hooks/self'&&$method==='PUT'){
  if(!empty($GLOBALS['self_hook_limit']))return ['status'=>409,'code'=>'LIMIT_REACHED'];
  foreach($eps as $e)if($e['url']===$body['url'])return ['status'=>200,'data'=>array_diff_key($e,['secret'=>1])+['created'=>false]];
  $id='ep'.(count($eps)+1);$e=['id'=>$id,'url'=>$body['url'],'enabled'=>true,'secret'=>'whsec_'.bin2hex(random_bytes(24))];$eps[$id]=$e;return ['status'=>201,'data'=>$e+['created'=>true]];
 }
 if($path==='/hooks/self/rotate'){$id=$body['id'];$eps[$id]['secret']='whsec_'.bin2hex(random_bytes(24));$GLOBALS['self_hook_rotations']=($GLOBALS['self_hook_rotations']??0)+1;return ['status'=>200,'data'=>$eps[$id]];}
 if($path==='/hooks/self/test')return ['status'=>200,'data'=>['queued'=>true]];
 return ['status'=>404,'code'=>'UNKNOWN_ENDPOINT'];
}
