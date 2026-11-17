<?php
declare(strict_types=1);
$home=sys_get_temp_dir().'/sibil-sibilpay-'.getmypid();@mkdir($home,0775,true);
file_put_contents($home.'/.env',"PANEL_BOT_TOKEN=123456:TESTTOKEN\nPANEL_HOME=$home\nPANEL_PRIMARY_OWNER_ID=1\nPANEL_BOT_NAME=Test\n");
putenv('PANEL_HOME='.$home);putenv('PANEL_ENV='.$home.'/.env');
require __DIR__.'/../sibil.php';load_env();db_bootstrap();settings_bootstrap();Tg::$dry=true;Tg::$outbox=[];
$pass=0;$fail=0;
function oks(bool $v,string $s):void {global$pass,$fail;if($v){$pass++;echo "ok $s\n";}else{$fail++;echo "FAIL $s\n";}}
function so(int $u=901001,int $amount=50000):array {user_ensure($u,'SibilPay','test');return create_order($u,'topup',$amount);}
oks(!gw_on('sibilpay'),'new gateway OFF by default');
oks(!array_key_exists('pay_sibilpay_key2',pay_setting_defaults()),'no second secret setting');
oks(gw_requires_kyc('sibilpay')&&!gw_requires_kyc('tetrapay'),'rial KYC includes SibilPay; Tetra exclusion unchanged');
oks(pay_sibil_base('https://pay.example.com:2053/g/store123')==='https://pay.example.com:2053/api/v1','merchant link retains port, normalizes API');
oks(pay_sibil_base('https://pay.example.com:2053/api/v1/')==='https://pay.example.com:2053/api/v1','API suffix not duplicated');
foreach(['http://pay.example.com','https://user:key@pay.example.com','https://pay.example.com/?key=secret','https://pay.example.com/#x']as$bad)oks(pay_sibil_base($bad)==='','unsafe gateway base rejected');
oks(pay_sibil_key(" sibil_pay_live_AB\nCD\u{200E} ")==='sibil_pay_live_ABCD','wrapped key normalization');
oks(pay_sibil_toman(500868)==='50,086.8','exact payable amount never rounded');
set_put('pay_sibilpay_endpoint','https://pay.example.com:2053');set_put('pay_sibilpay_key','sibil_pay_live_TEST');set_put('pay_sibilpay_on','1');set_put('pay_sibilpay_cashback','7');
[$ready]=gw_readiness('sibilpay');oks($ready,'site/key are sufficient; no webhook-secret configuration required');
$invoices=[];$calls=[];$mode='normal';
require __DIR__.'/self_hook_fixture.php';
$GLOBALS['sibilpay_hook_probe']=fn()=>true;set_put('web_url','https://panel.example.test');
$GLOBALS['sibilpay_transport']=function($profile,$method,$path,$body)use(&$invoices,&$calls,&$mode){
    if(str_starts_with($path,'/hooks/'))return test_self_hook($profile,$method,$path,$body);
    if($path==='/store')return ['status'=>200,'data'=>['slug'=>'testshop','webhook_url'=>'https://existing.example/hook']];
    $calls[]=[$profile,$method,$path,$body];
    if($method==='POST'&&$path==='/invoices'){
        if($mode==='timeout')return ['status'=>0,'code'=>'CONNECTION_FAILED'];
        if($mode==='redirect')return ['status'=>302,'code'=>'HTTP_302'];
        if($mode==='wallet')return ['status'=>402,'code'=>'WALLET_INSUFFICIENT','definite'=>true];
        $id='inv_'.bin2hex(random_bytes(6));$d=['id'=>$id,'order_id'=>$body['order_id'],'amount_rial'=>$body['amount_rial'],'payable_rial'=>$body['amount_rial']+137,'status'=>'pending','test_mode'=>false,'payment_url'=>'https://pay.example.com:2053/pay/'.$id,'expires_at'=>date(DATE_ATOM,time()+1800),'paid_amount_rial'=>0];
        $invoices[$id]=$d;return ['status'=>201,'data'=>$d];
    }
    if(str_contains($path,'/by-order/')){
        if($mode==='generic404')return ['status'=>404,'code'=>'NOT_FOUND','definite'=>true];
        if($mode==='auth')return ['status'=>401,'code'=>'INVALID_TOKEN','definite'=>true];
        foreach($invoices as$d)if($d['order_id']===basename($path))return ['status'=>200,'data'=>$d];
        return ['status'=>404,'code'=>'INVOICE_NOT_FOUND','definite'=>true];
    }
    $id=str_ends_with($path,'/verify')||str_ends_with($path,'/status')?basename(dirname($path)):basename($path);
    $d=$invoices[$id]??[];
    if(str_ends_with($path,'/verify')){
        if($mode==='already')return ['status'=>409,'code'=>'INVOICE_ALREADY_VERIFIED','definite'=>true];
        if($mode==='badverify')return ['status'=>200,'data'=>['verified'=>true,'invoice_id'=>'wrong']];
        return ['status'=>200,'data'=>['verified'=>true,'invoice_id'=>$id]];
    }
    if(str_ends_with($path,'/status'))$d['verified']=true;
    return ['status'=>200,'data'=>$d];
};
$o=so();$a=pay_start($o,'sibilpay');$p=pay_get((int)$a['payment']);$s=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[$p['id']]);
oks($a['ok']&&$s['payable_rial']===500137,'checkout persists provider amount and URL');
oks($calls[0][3]['amount_rial']===500000&&$calls[0][3]['expiry_minutes']===30,'toman converted to rial; 30-minute expiry');
oks($calls[0][0]['base']==='https://pay.example.com:2053/api/v1','API uses configured port without redirect guessing');
$count=count($calls);$again=pay_start($o,'sibilpay');oks($again['url']===$a['url']&&count($calls)===$count,'same order re-click reuses its own invoice, no duplicate POST');
$b=pay_start(so(),'sibilpay');oks($b['payment']!==$a['payment']&&$b['url']!==$a['url'],'new equal-value wallet order gets independent invoice');
$oldBalance=balance_of(901001);pay_sibil_hint($s['order_ref']);oks(balance_of(901001)===$oldBalance,'forged webhook paid hint cannot credit balance');
$r=pay_sibil_process($p,true);oks(!$r['ok']&&balance_of(901001)===$oldBalance,'API pending never credits');
$id=$s['remote_id'];$real=$invoices[$id];$invoices[$id]['status']='paid';$invoices[$id]['paid_amount_rial']=500137;
$paid=$invoices[$id];$invoices[$id]['amount_rial']++;pay_sibil_process($p,true);oks(balance_of(901001)===$oldBalance,'wrong amount rejected');$invoices[$id]=$paid;
$invoices[$id]['order_id']='wrong';pay_sibil_process($p,true);oks(balance_of(901001)===$oldBalance,'wrong remote order rejected');$invoices[$id]=$paid;
$invoices[$id]['test_mode']=true;pay_sibil_process($p,true);oks(balance_of(901001)===$oldBalance,'test response cannot credit live wallet');$invoices[$id]=$paid;
$invoices[$id]['paid_amount_rial']=500000;pay_sibil_process($p,true);oks(balance_of(901001)===$oldBalance,'underpayment rejected');$invoices[$id]=$paid;
$invoices[$id]['payment_url']='https://evil.example.com/pay/'.$id;pay_sibil_process($p,true);oks(balance_of(901001)===$oldBalance,'foreign checkout host rejected');$invoices[$id]=$paid;
$mode='badverify';pay_sibil_process($p,true);oks(balance_of(901001)===$oldBalance,'wrong verify invoice cannot settle');
$mode='already';$r=pay_sibil_process($p,true);oks($r['ok']&&balance_of(901001)===$oldBalance+53500,'already-verified payment rechecks API identity and credits configured 7 percent');
pay_sibil_process($p,true);oks(balance_of(901001)===$oldBalance+53500,'repeat processing credits once only');
$mode='timeout';$oUnknown=so();$u=pay_start($oUnknown,'sibilpay');$up=DB::one("SELECT * FROM payments WHERE order_id=? AND method='sibilpay'",[$oUnknown['id']]);$us=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[$up['id']]);
oks(!$u['ok']&&$up['status']==='unpaid'&&$us['state']==='unknown','ambiguous creation remains recoverable, not expired');
$before=count($calls);pay_start($oUnknown,'sibilpay');oks(count($calls)===$before,'same unknown order not blindly posted again');
$mode='normal';$new=pay_start(so(),'sibilpay');oks($new['ok'],'unknown old wallet order does not block new topup');
$remote=$real;$remote['id']='inv_recovered';$remote['order_id']=$us['order_ref'];$remote['payment_url']='https://pay.example.com:2053/pay/inv_recovered';$remote['expires_at']=date(DATE_ATOM,time()+1800);$invoices[$remote['id']]=$remote;
pay_sibil_process($up,true);$us=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[$up['id']]);oks($us['state']==='pending'&&$us['remote_id']==='inv_recovered','lost response recovered by original unique order ID');
$mode='timeout';$ox=so();pay_start($ox,'sibilpay');$xp=DB::one("SELECT * FROM payments WHERE order_id=?",[$ox['id']]);
$mode='generic404';pay_sibil_process($xp,true);oks(pay_get((int)$xp['id'])['status']==='unpaid','generic route 404 never proves absence');
$mode='auth';pay_sibil_process($xp,true);oks(pay_get((int)$xp['id'])['status']==='unpaid','revoked key never proves absence');
$mode='normal';pay_sibil_process($xp,true);oks(pay_get((int)$xp['id'])['status']==='expire','only explicit INVOICE_NOT_FOUND releases unknown attempt');
$retry=pay_start($ox,'sibilpay');oks($retry['ok']&&(int)$retry['payment']!==(int)$xp['id'],'confirmed absence allows fresh unique attempt for same order');
$mode='wallet';$wo=so();$wr=pay_start($wo,'sibilpay');$wp=DB::one('SELECT * FROM payments WHERE order_id=?',[$wo['id']]);oks(!$wr['ok']&&$wp['status']==='expire'&&str_contains($wr['text'],'کارمزد'),'definitive wallet rejection is nonblocking and explained');
$mode='redirect';$ro=so();$rr=pay_start($ro,'sibilpay');oks(!$rr['ok']&&str_contains($rr['text'],'تغییر مسیر'),'HTTP 302 gives configuration guidance, never follows redirect');
$mode='normal';$po=so();$pr=pay_start($po,'sibilpay');$pp=pay_get((int)$pr['payment']);
set_put('pay_sibilpay_endpoint','https://other.example.com');set_put('pay_sibilpay_key','sibil_pay_live_OTHER');$before=count($calls);pay_sibil_process($pp,true);$profile=$calls[$before][0];oks($profile['base']==='https://pay.example.com:2053/api/v1'&&$profile['api_key']==='sibil_pay_live_TEST','site/key changes preserve original invoice connection');
set_put('pay_sibilpay_endpoint','https://pay.example.com:2053');set_put('pay_sibilpay_key','sibil_pay_live_TEST');
Tg::$outbox=[];screen_pay_gateway(1,null,'sibilpay');$msg=Tg::$outbox[count(Tg::$outbox)-1][1];$kb=$msg['reply_markup']['inline_keyboard'];$cbs=[];foreach($kb as$row)foreach($row as$btn)$cbs[]=$btn['callback_data']??'';
oks(count($cbs)===7&&!in_array('ad:pay:sibilpay:key2',$cbs,true)&&!in_array('ad:pay:sibilpay:daily',$cbs,true)&&!in_array('ad:pay:sibilpay:test',$cbs,true),'admin UI exactly six requested controls plus back');
oks(str_contains($msg['text'],'@sibilpay')&&!str_contains($msg['text'],'sibil_pay_live_TEST'),'requested tutorial shown without revealing credential');
wiz_finish(1,['flow'=>'gwfield','ctx'=>['gw'=>'sibilpay','field'=>'cashback'],'values'=>['value'=>'101']]);oks(gw_cashback('sibilpay')===7,'cashback above 100 rejected');
set_put('pay_sibilpay_on','0');wiz_finish(1,['flow'=>'gwfield','ctx'=>['gw'=>'sibilpay','field'=>'key'],'values'=>['value'=>" sibil_pay_live_NEW\nKEY "]]);oks(!gw_on('sibilpay')&&gw_key('sibilpay')==='sibil_pay_live_NEWKEY','saving key normalizes formatting but does not auto-enable gateway');
$old=gw_key('sibilpay');wiz_finish(901001,['flow'=>'gwfield','ctx'=>['gw'=>'sibilpay','field'=>'key'],'values'=>['value'=>'sibil_pay_live_FORGED']]);oks(gw_key('sibilpay')===$old,'revoked/unprivileged wizard cannot change secret');
oks(in_array('sibilpay',mirza_payment_gateway_order(),true)&&mirza_payment_button_label('sibilpay')==='💳 سیبیل پی (کارت به کارت آنی)','buyer gateway selector includes exact requested label');
echo "$pass passed; $fail failed\n";exit($fail?1:0);
