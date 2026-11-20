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
require __DIR__.'/self_hook_fixture.php';
$GLOBALS['sibilpay_transport']=function($profile,$method,$path,$body)use(&$store,&$calls,&$mode,&$invoices){
 if($path==='/hooks/self'&&$method==='GET'&&$mode==='hooksfail')return ['status'=>503,'code'=>'HTTP_503'];
 if(str_starts_with($path,'/hooks/'))return test_self_hook($profile,$method,$path,$body);
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
pay_sibil_hook_sync();h(set_get('pay_sibilpay_hook_status')==='registered','key-scoped registration confirmed');
$ep=DB::one('SELECT * FROM sibilpay_hook_endpoints WHERE scope=?',[pay_sibil_hook_fingerprint()]);
h(str_starts_with($ep['url'],pay_sibil_hook_target().'?hook=')&&strlen($ep['secret'])>16,'random-token URL and endpoint secret durably stored');
$epCount=count($GLOBALS['self_hook_eps'][$ep['profile']]);reset_hook();pay_sibil_hook_sync();h(count($GLOBALS['self_hook_eps'][$ep['profile']])===$epCount&&empty($GLOBALS['self_hook_rotations']),'repeat registration neither duplicates nor rotates');
h(patches()===0,'legacy store callback never patched');
$healthy=false;reset_hook();pay_sibil_hook_sync();h(set_get('pay_sibilpay_hook_status')==='registered','failed self-probe does not block authenticated endpoint registration');$healthy=true;reset_hook();pay_sibil_hook_sync();
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
// Full signed + rounded settlement, independent new invoice.
$co=pay_start(create_order(991001,'topup',30000),'sibilpay');$pp=pay_get((int)$co['payment']);$ss=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[$pp['id']]);
$endpoint=DB::one('SELECT * FROM sibilpay_hook_endpoints WHERE scope=?',[pay_sibil_hook_fingerprint()]);
$invoices[$ss['remote_id']]['status']='paid';$invoices[$ss['remote_id']]['paid_amount_rial']=300127;
$event=json_encode(['event'=>'invoice.paid','livemode'=>true,'data'=>['id'=>$ss['remote_id'],'order_id'=>$ss['order_ref'],'status'=>'paid','amount_rial'=>300000]]);
$t=now_ts();$signature='t='.$t.',v1='.hash_hmac('sha256',$t.'.'.$event,$endpoint['secret']);$bal=balance_of(991001);
$res=pay_sibil_hook_receive($endpoint['token'],$event,$signature,'whd_live_round_100','invoice.paid');
h($res['code']===200&&balance_of(991001)===$bal,'signed paid request queues without any financial effect');
pay_sibil_hook_work((int)$pp['id']);h(balance_of(991001)===$bal+32700,'signed webhook + authenticated paid + verify credits 10-rial rounded invoice with configured cashback');
pay_sibil_hook_receive($endpoint['token'],$event,$signature,'whd_live_round_100','invoice.paid');pay_sibil_hook_work((int)$pp['id']);h(balance_of(991001)===$bal+32700,'signed delivery replay cannot credit twice');
h(count(array_filter($calls,fn($c)=>$c[0]==='POST'&&$c[1]==='/invoices'&&($c[2]['webhook_endpoint']??'')===$endpoint['remote_id']))>0,'new invoices explicitly select owned endpoint');
// Signed inbox tests use the same financial wake machinery, without trusting posted paid.
$ep=DB::one('SELECT * FROM sibilpay_hook_endpoints WHERE scope=?',[pay_sibil_hook_fingerprint()]);
$raw=json_encode(['event'=>'hook.test','data'=>[]]);
$sign=fn($raw,$t=null)=>'t='.($t??now_ts()).',v1='.hash_hmac('sha256',($t??now_ts()).'.'.$raw,$ep['secret']);
h(pay_sibil_hook_receive($ep['token'],$raw,'','whd_test_100','hook.test')['code']===401,'unsigned endpoint request refused');
h(pay_sibil_hook_receive($ep['token'],$raw,$sign($raw,now_ts()-301),'whd_test_100','hook.test')['code']===401,'stale signature refused');
h(pay_sibil_hook_receive($ep['token'],$raw,$sign($raw),'whd_test_100','hook.test')['code']===200,'signed hook.test accepted');
h(pay_sibil_hook_receive($ep['token'],$raw,$sign($raw),'whd_test_100','hook.test')['code']===200,'duplicate delivery idempotent');
h(pay_sibil_hook_receive($ep['token'],$raw.' ',$sign($raw.' '),'whd_test_100','hook.test')['code']===409,'changed signed body conflicts');
h(pay_sibil_hook_receive($ep['token'],$raw.' ',$sign($raw),'whd_tamper_100','hook.test')['code']===401,'raw body tampering refused');
DB::exec("UPDATE sibilpay_hook_endpoints SET secret='' WHERE scope=?",[$ep['scope']]);reset_hook();pay_sibil_hook_sync();
h(($GLOBALS['self_hook_rotations']??0)===1&&DB::val('SELECT secret FROM sibilpay_hook_endpoints WHERE scope=?',[$ep['scope']])!==$ep['secret'],'lost secret rotates only owned endpoint');
foreach([0,1,5,9,10]as$gap)h(pay_sibil_paid_amount_ok(['status'=>'paid','amount_rial'=>300000,'payable_rial'=>300075,'paid_amount_rial'=>300075-$gap]),'documented suffix gap '.$gap.' accepted');
h(pay_sibil_paid_amount_ok(['status'=>'paid','amount_rial'=>300000,'payable_rial'=>300075,'paid_amount_rial'=>300000]),'docs29/30 principal-only manual paid accepted');
h(!pay_sibil_paid_amount_ok(['status'=>'paid','amount_rial'=>300075,'payable_rial'=>300075,'paid_amount_rial'=>300070]),'principal shortfall refused');
$mode='hooksfail';reset_hook();pay_sibil_hook_sync();
$existing=DB::one('SELECT * FROM sibilpay_hook_endpoints WHERE scope=?',[pay_sibil_hook_fingerprint()]);
h($existing['state']==='ready'&&pay_sibil_hook_endpoint()===$existing['remote_id'],'transient metadata failure preserves confirmed explicit endpoint');
$rr=pay_start(create_order(991001,'topup',35000),'sibilpay');h(!empty($rr['ok']),'temporary metadata outage does not block creation on known endpoint');
$mode='normal';reset_hook();pay_sibil_hook_sync();
$oldScope=pay_sibil_hook_fingerprint();set_put('pay_sibilpay_key','sibil_pay_live_LIMITKEY');$GLOBALS['self_hook_limit']=true;reset_hook();pay_sibil_hook_sync();
h(set_get('pay_sibilpay_hook_error')==='LIMIT_REACHED'&&DB::val('SELECT state FROM sibilpay_hook_endpoints WHERE scope=?',[$oldScope])==='ready','limit reached preserves existing integration');
$GLOBALS['self_hook_limit']=false;reset_hook();pay_sibil_hook_sync();
$mode='normal';
h(pay_sibil_base('https://pay.example.test:2053/pay/inv_test')==='','invoice link rejected as configuration');
h(pay_sibil_base('https://pay.example.test:2053/api/v1/invoices')==='','unknown API subpath rejected');
set_put('pay_sibilpay_endpoint','https://pay.example.test:2053/api/v1');
pay_sibil_cache_storefront(['slug'=>'shop046806']);
h(pay_sibil_storefront()==='https://pay.example.test:2053/g/shop046806','authenticated slug derives storefront preserving port');
Tg::$outbox=[];screen_sibilpay_gateway(1,null);$text=Tg::$outbox[count(Tg::$outbox)-1][1]['text'];
h(str_contains($text,'آدرس درگاه: <code>https://pay.example.test:2053/g/shop046806</code>')&&!str_contains($text,'آدرس API (اتصال سرور):')&&!str_contains($text,'payment_url')&&!str_contains($text,'/api/v1'),'concise admin screen shows storefront without internal API jargon');
set_put('pay_sibilpay_key','sibil_pay_live_OTHER');
h(pay_sibil_storefront()==='','changed merchant key hides stale storefront cache');
set_put('pay_sibilpay_endpoint','https://pay.example.test:2053/g/manualshop');
h(pay_sibil_storefront()==='https://pay.example.test:2053/g/manualshop','legacy stored storefront input remains readable');
// Restore current endpoint's profile and exercise nested modern ingress precisely.
set_put('pay_sibilpay_key','sibil_pay_live_TEST');set_put('pay_sibilpay_endpoint','https://pay.example.test:2053');reset_hook();pay_sibil_hook_sync();
$ep=DB::one('SELECT * FROM sibilpay_hook_endpoints WHERE scope=?',[pay_sibil_hook_fingerprint()]);
$co=pay_start(create_order(991001,'topup',50000),'sibilpay');$pp=pay_get((int)$co['payment']);$ss=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[$pp['id']]);
$b=['event'=>'invoice.paid','livemode'=>true,'data'=>['id'=>$ss['remote_id'],'order_id'=>$ss['order_ref'],'amount_rial'=>500000,'paid_amount_rial'=>500000,'status'=>'paid','test_mode'=>false]];$raw=json_encode($b);$t=now_ts();$sig='t='.$t.',v1='.hash_hmac('sha256',$t.'.'.$raw,$ep['secret']);
$r=pay_sibil_hook_receive($ep['token'],$raw,$sig,'','invoice.paid');h(($r['body']['state']??'')==='queued','nested modern event queues without delivery header');
$r=pay_sibil_hook_receive($ep['token'],$raw,$sig,'','invoice.paid');h(($r['body']['state']??'')==='already','replayed modern event returns already');
h(pay_sibil_hook_receive($ep['token'],$raw,'t='.$t.',v1='.hash_hmac('sha256',$raw,$ep['secret']),'whd_legacy_reject','invoice.paid')['code']===401,'raw-only legacy signature rejected');
$b['data']['order_id']='sp-'.str_repeat('f',32);$raw=json_encode($b);$sig='t='.$t.',v1='.hash_hmac('sha256',$t.'.'.$raw,$ep['secret']);$rr=pay_sibil_hook_receive($ep['token'],$raw,$sig,'whd_unknown_new','invoice.paid');h(($rr['body']['error']??'')==='unknown_order','signed unknown order reports logical error rather than fake success');
$fakeScope=hash('sha256','other-deployment');$otherToken=bin2hex(random_bytes(32));$otherSecret='whsec_'.bin2hex(random_bytes(24));
DB::exec("INSERT INTO sibilpay_hook_endpoints(scope,profile,token,url,remote_id,secret,state) VALUES(?,?,?,?,?,?,'ready')",[$fakeScope,'different_api_key_profile',$otherToken,'https://other.example/hook','ep_other',$otherSecret]);
$raw=json_encode(['event'=>'invoice.paid','data'=>['id'=>$ss['remote_id'],'order_id'=>$ss['order_ref'],'amount_rial'=>500000]]);$sig='t='.$t.',v1='.hash_hmac('sha256',$t.'.'.$raw,$otherSecret);$cross=pay_sibil_hook_receive($otherToken,$raw,$sig,'whd_crossdomain','invoice.paid');h(($cross['body']['error']??'')==='unknown_order','valid signature for another deployment cannot bind this invoice');
echo"$pass passed; $fail failed\n";exit($fail?1:0);
