<?php
declare(strict_types=1);
$home=sys_get_temp_dir().'/sibil-hook-'.getmypid();@mkdir($home,0775,true);
file_put_contents($home.'/.env',"PANEL_BOT_TOKEN=123456:TESTTOKEN\nPANEL_HOME=$home\nPANEL_PRIMARY_OWNER_ID=1\nPANEL_BOT_NAME=Test\n");
putenv('PANEL_HOME='.$home);putenv('PANEL_ENV='.$home.'/.env');require __DIR__.'/../sibil.php';
load_env();db_bootstrap();settings_bootstrap();Tg::$dry=true;Tg::$outbox=[];
$pass=0;$fail=0;function h(bool $v,string $s):void{global$pass,$fail;if($v){$pass++;echo"ok $s\n";}else{$fail++;echo"FAIL $s\n";}}
set_put('web_url','https://panel.example.test');set_put('pay_sibilpay_endpoint','https://pay.example.test:2053');set_put('pay_sibilpay_key','sibil_pay_live_TEST');set_put('pay_sibilpay_on','1');set_put('pay_sibilpay_cashback','9');
$healthy=true;$store=['webhook_url'=>null];$calls=[];$mode='normal';$invoices=[];
$GLOBALS['sibilpay_hook_probe']=function($url)use(&$healthy){return $healthy;};
$GLOBALS['sibilpay_transport']=function($profile,$method,$path,$body)use(&$store,&$calls,&$mode,&$invoices){
 $calls[]=[$method,$path,$body];
 if($path==='/store'){
  if($mode==='storefail')return ['status'=>502,'code'=>'HTTP_502'];
  if($mode==='missingfield')return ['status'=>200,'data'=>['name'=>'Test']];
  if($method==='PATCH'){$store['webhook_url']=$body['webhook_url'];return ['status'=>200,'data'=>$store];}
  return ['status'=>200,'data'=>$store];
 }
 if($method==='POST'&&$path==='/invoices'){
  $id='inv_'.bin2hex(random_bytes(6));$d=['id'=>$id,'order_id'=>$body['order_id'],'amount_rial'=>$body['amount_rial'],'payable_rial'=>$body['amount_rial']+137,'status'=>'pending','test_mode'=>false,'payment_url'=>'https://pay.example.test:2053/pay/'.$id,'expires_at'=>date(DATE_ATOM,time()+1800),'paid_amount_rial'=>0];$invoices[$id]=$d;return ['status'=>201,'data'=>$d];
 }
 $id=str_ends_with($path,'/verify')?basename(dirname($path)):basename($path);
 if(str_ends_with($path,'/verify'))return ['status'=>200,'data'=>['verified'=>true,'invoice_id'=>$id]];
 if($mode==='apifail')return ['status'=>503,'code'=>'HTTP_503'];
 return ['status'=>200,'data'=>$invoices[$id]??[]];
};
function reset_hook():void{set_put('pay_sibilpay_hook_next','0');}
function patches():int{global$calls;return count(array_filter($calls,fn($c)=>$c[0]==='PATCH'));}
h(pay_sibil_hook_due(),'enabled configured gateway schedules registration');
pay_sibil_hook_sync();h($store['webhook_url']==='https://panel.example.test/pay/sibilpay/webhook','empty store webhook registered to exact panel endpoint');
h(set_get('pay_sibilpay_hook_status')==='registered','registration confirmed by GET readback');
h(count($calls)===3&&$calls[0][0]==='GET'&&$calls[1][0]==='PATCH'&&$calls[2][0]==='GET','registration GET/PATCH/GET only; no invoice/payment operations');
$n=count($calls);pay_sibil_hook_sync();h(count($calls)===$n,'successful registration not repeated every bot tick');
reset_hook();$n=patches();pay_sibil_hook_sync();h(patches()===$n,'already matching webhook never rewritten');
$store['webhook_url']='https://mirza.example.test/payment/sibilpay.php';reset_hook();$n=patches();pay_sibil_hook_sync();h(patches()===$n&&$store['webhook_url']==='https://mirza.example.test/payment/sibilpay.php','existing Mirza/third-party webhook preserved');
h(set_get('pay_sibilpay_hook_status')==='conflict_preserved','conflict visible without adding a destructive button');
$store['webhook_url']=null;$healthy=false;reset_hook();$n=count($calls);pay_sibil_hook_sync();h(count($calls)===$n&&set_get('pay_sibilpay_hook_status')==='endpoint_unreachable','unreachable local endpoint cannot replace provider configuration');$healthy=true;
$mode='storefail';reset_hook();$n=patches();pay_sibil_hook_sync();h(patches()===$n&&set_get('pay_sibilpay_hook_status')==='store_lookup_failed','failed GET store never treated as empty webhook');
$mode='missingfield';reset_hook();$n=patches();pay_sibil_hook_sync();h(patches()===$n,'missing webhook field never assumed empty');$mode='normal';
set_put('web_url','');set_put('web_domain','');reset_hook();$n=count($calls);pay_sibil_hook_sync();h(count($calls)===$n&&set_get('pay_sibilpay_hook_status')==='public_url_missing','missing HTTPS base leaves polling intact and registers nothing');set_put('web_url','https://panel.example.test');
set_put('pay_sibilpay_on','0');reset_hook();$n=count($calls);pay_sibil_hook_sync();h(count($calls)===$n,'disabled gateway does not modify remote webhook');set_put('pay_sibilpay_on','1');pay_sibil_hook_sync();
$_SERVER=['REQUEST_URI'=>'/pay/sibilpay/webhook','REQUEST_METHOD'=>'GET'];$_GET=[];$_POST=[];ob_start();web_handle();$ready=json_decode(ob_get_clean(),true);h(($ready['ready']??false)&&($ready['service']??'')==='SIBILPANEL','public GET health is nonfinancial and directly discoverable');
user_ensure(991001,'Hook','test');$order=create_order(991001,'topup',50000);$created=pay_start($order,'sibilpay');$p=pay_get((int)$created['payment']);$s=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[$p['id']]);
$before=balance_of(991001);$n=count($calls);pay_sibil_hint($s['order_ref']);h(count($calls)===$n&&balance_of(991001)===$before,'webhook ingress performs no network request or financial mutation');
h((int)DB::val('SELECT COUNT(*) FROM sibilpay_hook_wakes')===1,'webhook wake persisted durably before response');
pay_sibil_hint($s['order_ref']);h((int)DB::val('SELECT generation FROM sibilpay_hook_wakes WHERE payment_id=?',[$p['id']])===1,'duplicate hints rate-limited per invoice');
pay_sibil_hook_work((int)$p['id']);h(balance_of(991001)===$before,'forged paid event while API pending cannot credit');
h((int)DB::val('SELECT attempts FROM sibilpay_hook_wakes WHERE payment_id=?',[$p['id']])===1,'pending API state schedules durable retry');
$invoices[$s['remote_id']]['status']='paid';$invoices[$s['remote_id']]['paid_amount_rial']=500137;
DB::exec('UPDATE sibilpay_hook_wakes SET due_at=0 WHERE payment_id=?',[$p['id']]);DB::exec('UPDATE sibilpay_invoices SET next_check=? WHERE payment_id=?',[time()+3600,$p['id']]);
pay_sibil_hook_work((int)$p['id']);h(balance_of(991001)===$before+54500,'manual paid event bypasses normal poll delay, verifies API and credits configured cashback');
h((int)DB::val('SELECT COUNT(*) FROM sibilpay_hook_wakes')===0,'completed wake removed only after processing');
pay_sibil_hint($s['order_ref']);pay_sibil_hook_work((int)$p['id']);h(balance_of(991001)===$before+54500,'replayed events cannot credit twice');
pay_sibil_hint('sp-'.str_repeat('f',32));h((int)DB::val('SELECT COUNT(*) FROM sibilpay_hook_wakes')===0,'unknown order event cannot manufacture a payment');
$order2=create_order(991001,'topup',50000);$c2=pay_start($order2,'sibilpay');$p2=pay_get((int)$c2['payment']);$s2=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[$p2['id']]);
pay_sibil_hint($s2['order_ref']);$mode='apifail';pay_sibil_hook_work((int)$p2['id']);h((int)DB::val('SELECT COUNT(*) FROM sibilpay_hook_wakes')===1&&balance_of(991001)===$before+54500,'API failure retains retry work and never assumes paid');$mode='normal';
$invoices[$s2['remote_id']]['status']='paid';$invoices[$s2['remote_id']]['paid_amount_rial']=500137;$invoices[$s2['remote_id']]['order_id']='wrong';DB::exec('UPDATE sibilpay_hook_wakes SET due_at=0');pay_sibil_hook_work((int)$p2['id']);h(balance_of(991001)===$before+54500,'wrong invoice identity still fails after webhook');
$invoices[$s2['remote_id']]['order_id']=$s2['order_ref'];DB::exec('UPDATE sibilpay_hook_wakes SET due_at=0');pay_sibil_hook_work((int)$p2['id']);h(balance_of(991001)===$before+109000,'second independent invoice settles once after valid retry');
$order3=create_order(991001,'topup',50000);$c3=pay_start($order3,'sibilpay');$p3=pay_get((int)$c3['payment']);$s3=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[$p3['id']]);
DB::exec('UPDATE orders SET created_at=? WHERE id=?',[time()-4000,$order3['id']]);DB::exec("UPDATE payments SET status='expire' WHERE id=?",[$p3['id']]);
$invoices[$s3['remote_id']]['status']='paid';$invoices[$s3['remote_id']]['paid_amount_rial']=500137;pay_sibil_hint($s3['order_ref']);pay_sibil_hook_work((int)$p3['id']);h(balance_of(991001)===$before+109000,'late payment retains existing finance-review rule; webhook cannot force credit');
h(DB::val('SELECT state FROM sibilpay_invoices WHERE payment_id=?',[$p3['id']])==='late','late payment classified for finance, not discarded');
Tg::$outbox=[];screen_pay_gateway(1,null,'sibilpay');$last=Tg::$outbox[count(Tg::$outbox)-1][1];$count=0;foreach($last['reply_markup']['inline_keyboard']as$row)$count+=count($row);h($count===7&&str_contains($last['text'],'وبهوک'),'same six settings and Back; webhook status is text only');
echo"$pass passed; $fail failed\n";exit($fail?1:0);
