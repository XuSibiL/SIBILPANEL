<?php
/** Offline queue, provider failure, ownership, notifications and forecast regressions. */
declare(strict_types=1);
$home=sys_get_temp_dir().'/sibil-wallet-life-'.getmypid();@mkdir($home,0700,true);
file_put_contents($home.'/.env',"PANEL_BOT_TOKEN=123456:TESTTOKEN\nPANEL_HOME=$home\nPANEL_PRIMARY_OWNER_ID=1\n");
putenv('PANEL_HOME='.$home);putenv('PANEL_ENV='.$home.'/.env');
require __DIR__.'/../sibil.php';load_env();db_bootstrap();settings_bootstrap();Tg::$dry=true;
$pass=0;$fail=0;
function oklife(bool $c,string $m):void{global $pass,$fail;if($c){$pass++;echo "ok $m\n";}else{$fail++;echo "FAIL $m\n";}}
$uid=3181001;user_ensure($uid,'Wallet Test','wallet');
$pg=DB::exec('INSERT INTO pg_panels(name,url,api_key,created_at) VALUES(?,?,?,?)',['Wallet PG','https://panel.invalid','TEST',now_ts()]);
$api=[];$reject=false;
$GLOBALS['panel_http_mock']=function($method,$url,$headers,$body)use(&$api,&$reject){
 $api[]=[$method,$url,$body];if($reject)return [json_encode(['detail'=>'outage']),'',503];
 return [json_encode(['id'=>91,'status'=>'active']),'',200];
};
function lp(int $uid,int $pg,string $name,array $extra=[]):array {
 $id=DB::exec('INSERT INTO panels(product_id,tg_id,panel_admin_id,panel_username,panel_password,label,max_users,device_limit,data_cap,expires_at,per_gb_price,hourly_price,min_balance,status,created_at,pg_panel_id,last_meter_at,billed_bytes,last_usage,spent_total) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
 [0,$uid,91,$name,'TEST','Wallet test',10,1,100*1024**3,now_ts()+86400,3000,50,0,'active',now_ts()-2*DAY,$pg,now_ts()-7200,2*USAGE_BILL_BYTES,2*USAGE_BILL_BYTES,7000]);
 foreach($extra as $k=>$v)DB::exec('UPDATE panels SET '.$k.'=? WHERE id=?',[$v,$id]);
 return DB::one('SELECT * FROM panels WHERE id=?',[$id]);
}
$a=lp($uid,$pg,'recover_a');$b=lp($uid,$pg,'recover_b');
oklife(suspend_panel($a,'کمبود موجودی')&&suspend_panel($b,'موجودی کیف پول صفر است'),'wallet holds persist explicit reasons');
oklife(DB::val('SELECT suspended_reason FROM panels WHERE id=?',[$a['id']])==='balance','usage-meter low-balance reason is recoverable');
$manual=lp($uid,$pg,'manual');suspend_panel($manual,'تعلیق دستی توسط مدیر',1);
$expired=lp($uid,$pg,'expired',['expires_at'=>now_ts()-1]);suspend_panel($expired,'پایان اعتبار');
$traffic=lp($uid,$pg,'traffic',['last_usage'=>100*1024**3]);suspend_panel($traffic,'پایان حجم ترافیک');
$unknown=lp($uid,$pg,'unknown',['status'=>'suspended','suspended_reason'=>null]);
$trial=lp($uid,$pg,'trial',['is_trial'=>1]);
$beforeCalls=count($api);add_balance($uid,250000,'topup','fixture');
oklife(count($api)===$beforeCalls,'credit transaction contains no provider calls');
oklife((int)DB::val('SELECT COUNT(*) FROM wallet_panel_work')>=2,'credit durably schedules all held panels');
$reject=true;wallet_worker_run();
oklife(DB::val('SELECT status FROM panels WHERE id=?',[$a['id']])==='suspended'&&DB::val('SELECT suspended_reason FROM panels WHERE id=?',[$a['id']])==='balance','failed remote resume preserves suspended status and reason');
oklife((int)DB::val('SELECT attempts FROM wallet_panel_work WHERE panel_id=?',[$a['id']])===1,'provider outage is queued with backoff');
$reject=false;DB::exec('UPDATE wallet_panel_work SET due_at=?',[now_ts()]);$stats=wallet_worker_run();
oklife($stats['resumed']===2,'one charge resumes every eligible panel');
$f=DB::one('SELECT * FROM panels WHERE id=?',[$a['id']]);
oklife($f['status']==='active'&&$f['suspended_reason']===null,'hold marker clears only after successful API activation');
oklife((int)$f['billed_bytes']===(int)$a['billed_bytes']&&(int)$f['spent_total']===(int)$a['spent_total']&&(int)$f['last_usage']===(int)$a['last_usage'],'resume preserves billed traffic, usage and spent totals');
oklife(abs((int)$f['last_meter_at']-now_ts())<3,'suspended hours are excluded by successful resume baseline');
foreach([$manual,$expired,$traffic,$unknown]as$p)oklife(DB::val('SELECT status FROM panels WHERE id=?',[$p['id']])==='suspended','non-wallet hold remains suspended: '.$p['panel_username']);
oklife(DB::val('SELECT status FROM panels WHERE id=?',[$trial['id']])==='active','free trial is not part of wallet lifecycle');
$calls=count($api);wallet_worker_run();oklife(count($api)===$calls,'repeated recovery does not re-enable or re-charge active panels');
$bal=balance_of($uid);add_balance($uid,-$bal,'admin-debit');wallet_worker_run();
oklife(DB::val('SELECT status FROM panels WHERE id=?',[$a['id']])==='suspended'&&DB::val('SELECT status FROM panels WHERE id=?',[$b['id']])==='suspended','zero balance disables all billable panels');
$rows=(int)DB::val('SELECT COUNT(*) FROM wallet_balance_events');$balance=balance_of($uid);
try{DB::tx(function()use($uid){add_balance($uid,99999,'rollback-test');throw new RuntimeException('rollback');});}catch(Throwable $e){}
oklife(balance_of($uid)===$balance&&(int)DB::val('SELECT COUNT(*) FROM wallet_balance_events')===$rows,'rollback does not leak wallet credit or lifecycle events');
// All top-up providers share this exact idempotent settlement route.
$order=create_order($uid,'topup',200000,null,['topup'=>200000]);$paid=settle_topup_once($order,'stars');wallet_worker_run();
oklife(!empty($paid['credited'])&&DB::val('SELECT status FROM panels WHERE id=?',[$a['id']])==='active','atomic verified top-up wakes recovery without waiting for usage meter');
$again=settle_topup_once($order,'stars');oklife(empty($again['credited'])&&balance_of($uid)===200000,'duplicate top-up cannot create another credit');
// Accurate account-level 23-hour mean, with billing-only entries.
$at=now_ts();foreach([$a,$b]as$p)DB::exec('INSERT INTO usage_charges(panel_id,tg_id,amount,kind,created_at) VALUES(?,?,?,?,?)',[$p['id'],$uid,49611,'usage',$at-3600]);
DB::exec('INSERT INTO usage_charges(panel_id,tg_id,amount,kind,created_at) VALUES(?,?,?,?,?)',[$a['id'],$uid,999999,'usage',$at-24*3600]);
$est=wallet_consumption_estimate($uid,$at);
oklife($est['spent']===99222&&abs($est['rate']-4314)<0.01&&abs($est['hours']-23)<0.01,'23-hour mean aggregates actual costs of all panels and excludes older charges');
add_balance($uid,-187471,'adjust');$est=wallet_consumption_estimate($uid,$at);
oklife(abs($est['remaining']-12529/4314)<0.01,'remaining hours use current account balance divided by measured hourly average');
$text=wallet_low_text($uid,50000);oklife(str_contains($text,'12,529')&&str_contains($text,'4,314')&&str_contains($text,'2.9'),'warning contains measured current balance, average and approximate runway');
$other=3181002;user_ensure($other,'No history','none');
oklife(wallet_consumption_estimate($other)['remaining']===null,'no fabricated estimate when history is insufficient');
// Three separate threshold crossings and no spam for spending within a band.
DB::exec('DELETE FROM wallet_balance_events');DB::exec('DELETE FROM wallet_low_notices');
DB::exec('UPDATE users SET balance=100000 WHERE tg_id=?',[$uid]);wallet_events_collect();
foreach([90000,50000,10000]as$target){DB::exec('UPDATE users SET balance=? WHERE tg_id=?',[$target,$uid]);wallet_worker_run();}
$levels=array_map('intval',array_column(DB::all('SELECT threshold FROM wallet_low_notices ORDER BY id'),'threshold'));
oklife($levels===[90000,50000,10000],'one warning per descending 90k/50k/10k crossing');
DB::exec('UPDATE users SET balance=9999 WHERE tg_id=?',[$uid]);wallet_worker_run();
oklife((int)DB::val('SELECT COUNT(*) FROM wallet_low_notices')===3,'small debits in the same balance band do not spam');
DB::exec('UPDATE users SET balance=100000 WHERE tg_id=?',[$uid]);DB::exec('UPDATE users SET balance=90000 WHERE tg_id=?',[$uid]);wallet_worker_run();
oklife((int)DB::val('SELECT COUNT(*) FROM wallet_low_notices WHERE threshold=90000')===2,'recharging above a threshold rearms it for the next descent');
$h=fopen($home.'/.wallet-worker.lock','c');flock($h,LOCK_EX|LOCK_NB);$calls=count($api);$busy=wallet_worker_run();
oklife($busy['processed']===0&&count($api)===$calls,'second worker cannot race an existing worker');flock($h,LOCK_UN);fclose($h);
// Known cap/expiry still wins over a balance hold.
DB::exec("UPDATE panels SET status='suspended',suspended_reason='balance',expires_at=? WHERE id=?",[now_ts()-1,$a['id']]);
add_balance($uid,10000,'topup');wallet_worker_run();
oklife(DB::val('SELECT status FROM panels WHERE id=?',[$a['id']])==='suspended','funding cannot override expiration even on a balance-tagged hold');
unset($GLOBALS['panel_http_mock']);DB::close();
foreach(glob($home.'/*')?:[]as$p)if(is_file($p))@unlink($p);
foreach(glob($home.'/.*')?:[]as$p)if(is_file($p))@unlink($p);@rmdir($home);
echo "$pass passed; $fail failed\n";exit($fail?1:0);
