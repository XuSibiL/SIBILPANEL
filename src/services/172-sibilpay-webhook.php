<?php
declare(strict_types=1);

/** Webhooks accelerate only authoritative API verification; posted payment fields are never settlement authority. */
function pay_sibil_hook_schema(): void
{
    DB::pdo()->exec("CREATE TABLE IF NOT EXISTS sibilpay_hook_endpoints (
        scope TEXT PRIMARY KEY, profile TEXT NOT NULL, token TEXT NOT NULL UNIQUE,
        url TEXT NOT NULL, remote_id TEXT NOT NULL DEFAULT '', secret TEXT NOT NULL DEFAULT '',
        state TEXT NOT NULL DEFAULT 'pending')");
    DB::pdo()->exec('CREATE TABLE IF NOT EXISTS sibilpay_hook_events (
        scope TEXT NOT NULL,delivery TEXT NOT NULL,body_hash TEXT NOT NULL,
        PRIMARY KEY(scope,delivery))');
    DB::pdo()->exec('CREATE TABLE IF NOT EXISTS sibilpay_hook_wakes (
        payment_id INTEGER PRIMARY KEY, generation INTEGER NOT NULL DEFAULT 1,
        due_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0)');
}
function pay_sibil_hook_target(): string
{
    $url=rtrim(web_base(),'/').'/pay/sibilpay/webhook';
    $p=parse_url($url);
    return gw_https_url($url)&&!isset($p['query'])&&!isset($p['fragment'])&&!str_contains($url,'\\')?$url:'';
}
function pay_sibil_hook_fingerprint(): string
{
    return hash('sha256',"self-contract-v4-endpoint\n".pay_sibil_base(set_get('pay_sibilpay_endpoint',''))."\n".gw_key('sibilpay')."\n".pay_sibil_hook_target());
}
function pay_sibil_hook_due(): bool
{
    return gw_on('sibilpay')&&pay_sibil_base(set_get('pay_sibilpay_endpoint',''))!==''&&gw_key('sibilpay')!==''
        &&(set_get('pay_sibilpay_hook_fingerprint','')!==pay_sibil_hook_fingerprint()||set_int('pay_sibilpay_hook_next',0)<=now_ts());
}
/** No authorization header or redirect is ever used for the local endpoint health check. */
function pay_sibil_hook_probe(string $url): bool
{
    if (Tg::$dry&&isset($GLOBALS['sibilpay_hook_probe'])) return (bool)($GLOBALS['sibilpay_hook_probe'])($url);
    $p=parse_url($url);$host=(string)($p['host']??'');$port=(int)($p['port']??443);$ips=[];
    if (filter_var($host,FILTER_VALIDATE_IP)) $ips=[$host];
    else foreach(dns_get_record($host,DNS_A|DNS_AAAA)?:[] as $r)$ips[]=$r['ip']??$r['ipv6']??'';
    if (!$ips) return false;
    foreach($ips as $ip) if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return false;
    $ch=curl_init($url);$raw='';
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>4,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_RESOLVE=>[$host.':'.$port.':'.(str_contains($ips[0],':')?'['.$ips[0].']':$ips[0])],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>4096)return 0;$raw.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$j=json_decode($raw,true);
    return $ok!==false&&$status===200&&($j['service']??'')==='SIBILPANEL'&&($j['webhook']??'')==='sibilpay'&&($j['ready']??null)===true;
}
function pay_sibil_hook_status(string $fingerprint,string $status,int $delay=300): void
{
    // Do not overwrite a new configuration's status with an older worker's result.
    if($fingerprint!==pay_sibil_hook_fingerprint())return;
    DB::tx(static function()use($fingerprint,$status,$delay):void{
        set_put('pay_sibilpay_hook_fingerprint',$fingerprint);set_put('pay_sibilpay_hook_status',$status);
        set_put('pay_sibilpay_hook_next',(string)(now_ts()+$delay));
    });
}
function pay_sibil_hook_sync(): void
{
    if(!pay_sibil_hook_due())return;
    pay_sibil_guard('hook-registration',static function():void{
        if(!pay_sibil_hook_due())return;
        $fp=pay_sibil_hook_fingerprint();$target=pay_sibil_hook_target();
        pay_sibil_hook_status($fp,'checking',60);
        if($target===''){pay_sibil_hook_status($fp,'public_url_missing');return;}
        if(!pay_sibil_hook_probe($target)){pay_sibil_hook_status($fp,'endpoint_unreachable');return;}
        $profile=['base'=>pay_sibil_base(set_get('pay_sibilpay_endpoint','')),'api_key'=>pay_sibil_key(gw_key('sibilpay'))];
        $request=static function(string $method,string $path,?array $body=null)use($profile):array{
            $res=pay_sibil_http($profile,$method,$path,$body);
            if(!is_array($res['data']??null)){
                $code=(string)($res['code']??'HOOK_API_FAILED');
                throw new RuntimeException(preg_match('/^[A-Z0-9_]{1,70}$/D',$code)?$code:'HOOK_API_FAILED');
            }
            return $res['data'];
        };
        try{
            $list=$request('GET','/hooks/self');
            if(!is_array($list['endpoints']??null)||!is_string($list['key']??null))throw new RuntimeException('WEBHOOK_CONTRACT_MISMATCH');
            if($fp!==pay_sibil_hook_fingerprint()||!gw_on('sibilpay'))return;
            $saved=DB::one('SELECT * FROM sibilpay_hook_endpoints WHERE scope=?',[$fp]);
            if(!$saved){
                $token=bin2hex(random_bytes(32));
                DB::exec('INSERT INTO sibilpay_hook_endpoints(scope,profile,token,url) VALUES(?,?,?,?)',[$fp,hash('sha256',$profile['base']."\n".$profile['api_key']),$token,$target.'?hook='.$token]);
                $saved=DB::one('SELECT * FROM sibilpay_hook_endpoints WHERE scope=?',[$fp]);
            }
            $ep=null;foreach($list['endpoints'] as $e)if(($e['url']??null)===$saved['url']){$ep=$e;break;}
            $fresh=false;
            if(!$ep){$ep=$request('PUT','/hooks/self',['url'=>$saved['url'],'label'=>'sibilpanel']);$fresh=true;}
            $id=$ep['id']??'';
            if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,150}$/D',$id)||($ep['url']??'')!==$saved['url'])throw new RuntimeException('WEBHOOK_CONTRACT_MISMATCH');
            if(($ep['enabled']??true)===false)throw new RuntimeException('WEBHOOK_DISABLED');
            $secret=$saved['remote_id']===$id?$saved['secret']:'';
            if(is_string($ep['secret']??null)&&strlen($ep['secret'])>=16)$secret=$ep['secret'];
            if($secret===''){
                $rot=$request('POST','/hooks/self/rotate',['id'=>$id]);
                if(($rot['id']??'')!==$id||($rot['url']??'')!==$saved['url'])throw new RuntimeException('WEBHOOK_CONTRACT_MISMATCH');
                $secret=$rot['secret']??'';$fresh=true;
            }
            if(!is_string($secret)||strlen($secret)<16||strlen($secret)>500)throw new RuntimeException('WEBHOOK_SECRET_MISSING');
            DB::exec("UPDATE sibilpay_hook_endpoints SET remote_id=?,secret=?,state='pending' WHERE scope=?",[$id,$secret,$fp]);
            $confirm=$request('GET','/hooks/self');$found=false;
            foreach($confirm['endpoints']??[] as $e)if(($e['id']??'')===$id&&($e['url']??'')===$saved['url']&&($e['enabled']??false)===true)$found=true;
            if(!$found)throw new RuntimeException('WEBHOOK_REGISTRATION_UNCONFIRMED');
            DB::exec("UPDATE sibilpay_hook_endpoints SET state='ready' WHERE scope=?",[$fp]);
            pay_sibil_hook_status($fp,'registered',3600);
            try{if($fp===pay_sibil_hook_fingerprint())pay_sibil_cache_storefront($request('GET','/store'));}catch(Throwable $ignored){}
            if($fresh){try{$request('POST','/hooks/self/test',['id'=>$id]);}catch(Throwable $ignored){}}
        }catch(Throwable $e){
            DB::exec("UPDATE sibilpay_hook_endpoints SET state='error' WHERE scope=?",[$fp]);
            $code=$e->getMessage();set_put('pay_sibilpay_hook_error',preg_match('/^[A-Z0-9_]{1,70}$/D',$code)?$code:'HOOK_API_FAILED');
            pay_sibil_hook_status($fp,'self_registration_failed',60);
        }
    });
}
function pay_sibil_hook_endpoint(): string
{
    pay_sibil_hook_sync();
    $e=DB::one("SELECT remote_id FROM sibilpay_hook_endpoints WHERE scope=? AND state='ready' AND secret<>''",[pay_sibil_hook_fingerprint()]);
    return (string)($e['remote_id']??'');
}
/** Timestamped endpoint HMAC. Financial authority is still GET invoice + verify. */
function pay_sibil_hook_receive(string $token,string $raw,string $signature,string $delivery,string $headerEvent): array
{
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))return ['code'=>404];
    $ep=DB::one('SELECT * FROM sibilpay_hook_endpoints WHERE token=?',[$token]);
    if(!$ep)return ['code'=>404];
    if(strlen($raw)>262144)return ['code'=>413];
    if(strlen($ep['secret'])<16||!preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/D',trim($signature),$m)
      ||abs(now_ts()-(int)$m[1])>300||!hash_equals(hash_hmac('sha256',$m[1].'.'.$raw,$ep['secret']),$m[2]))return ['code'=>401];
    $j=json_decode($raw,true);$event=$j['event']??null;
    if(!is_array($j)||!is_string($event)||!preg_match('/^[a-z_.]{1,80}$/D',$event)||($headerEvent!==''&&$event!==$headerEvent)||!preg_match('/^[A-Za-z0-9_-]{4,150}$/D',$delivery))return ['code'=>400];
    return DB::tx(static function()use($ep,$raw,$delivery,$j,$event):array{
        $old=DB::one('SELECT body_hash FROM sibilpay_hook_events WHERE scope=? AND delivery=?',[$ep['scope'],$delivery]);$hash=hash('sha256',$raw);
        if($old)return ['code'=>hash_equals($old['body_hash'],$hash)?200:409];
        DB::exec('INSERT INTO sibilpay_hook_events(scope,delivery,body_hash) VALUES(?,?,?)',[$ep['scope'],$delivery,$hash]);
        if(in_array($event,['invoice.paid','invoice.verified'],true)&&($j['livemode']??null)!==false&&($j['data']['test_mode']??null)!==true){
            $ref=(string)($j['data']['order_id']??'');$s=DB::one('SELECT * FROM sibilpay_invoices WHERE order_ref=? AND profile=?',[$ref,$ep['profile']]);
            if($s&&($s['remote_id']===(string)($j['data']['id']??'')||empty($s['remote_id'])&&in_array($s['state'],['creating','unknown'],true)))pay_sibil_hook_enqueue($ref);
        }
        return ['code'=>200];
    });
}
function pay_sibil_hook_status_text(): string
{
    if(set_get('pay_sibilpay_hook_fingerprint','')!==pay_sibil_hook_fingerprint())return '⏳ پیکربندی جدید در انتظار بررسی و ثبت خودکار';
    return match(set_get('pay_sibilpay_hook_status','')){
        'registered'=>'✅ مقصد مستقل و امضاشده ثبت و بازخوانی شده',
        'self_registration_failed'=>'⚠️ ثبت مقصد نیازمند بررسی: '.e(set_get('pay_sibilpay_hook_error','HOOK_API_FAILED')).'؛ استعلام پرداخت‌های قبلی ادامه دارد',
        'conflict_preserved'=>'⚠️ وبهوک دیگری برای فروشگاه ثبت است؛ برای حفظ اتصال قبلی تغییر ندادیم. از فروشگاه مستقل استفاده کنید.',
        'public_url_missing'=>'⚠️ آدرس عمومی HTTPS پنل ثبت نشده؛ استعلام پشتیبان فعال است.',
        'endpoint_unreachable'=>'⚠️ مسیر وبهوک پنل از سرور قابل تأیید نیست؛ HTTPS و سرویس کال‌بک را بررسی کنید.',
        'store_contract_invalid'=>'⚠️ پاسخ API فروشگاه فاقد webhook_url معتبر است؛ نسخهٔ سرور سیبیل‌پی باید با قرارداد جدید هماهنگ شود.',
        'store_lookup_failed'=>'⚠️ دریافت تنظیمات وبهوک فروشگاه موفق نبود؛ HTTP '.max(0,min(599,set_int('pay_sibilpay_hook_http',0))).'؛ '.e(preg_replace('/[^A-Z0-9_]/','',substr(set_get('pay_sibilpay_hook_error',''),0,70))).'؛ استعلام پشتیبان ادامه دارد.',
        'registration_failed','registration_unconfirmed'=>'⚠️ ثبت وبهوک هنوز تأیید نشده؛ تلاش مجدد و استعلام پشتیبان ادامه دارد.',
        default=>'⏳ ثبت و بررسی خودکار پس از روشن‌کردن درگاه',
    };
}
function pay_sibil_hook_enqueue(string $ref): void
{
    DB::tx(static function()use($ref):void{
        $s=DB::one('SELECT * FROM sibilpay_invoices WHERE order_ref=?',[$ref]);
        if(!$s||!in_array($s['state'],['creating','unknown','pending','closed'],true)||(int)$s['last_hint']>now_ts()-30)return;
        DB::exec('UPDATE sibilpay_invoices SET next_check=MIN(next_check,?),last_hint=? WHERE payment_id=?',[now_ts(),now_ts(),(int)$s['payment_id']]);
        DB::exec('INSERT INTO sibilpay_hook_wakes(payment_id,due_at) VALUES(?,?) ON CONFLICT(payment_id) DO UPDATE SET generation=generation+1,due_at=excluded.due_at,attempts=0',[(int)$s['payment_id'],now_ts()]);
    });
}
function pay_sibil_hook_work(int $paymentId): void
{
    pay_sibil_guard('hook-job-'.$paymentId,static function()use($paymentId):void{
        $wake=DB::one('SELECT * FROM sibilpay_hook_wakes WHERE payment_id=? AND due_at<=?',[$paymentId,now_ts()]);
        if(!$wake)return;
        $p=pay_get($paymentId);$result=[];
        if($p&&$p['method']==='sibilpay'){
            try{$result=pay_sibil_process($p,true);}catch(Throwable $e){pay_sibil_log_error($paymentId,'WEBHOOK_API_RETRY');}
        }
        $s=DB::one('SELECT state FROM sibilpay_invoices WHERE payment_id=?',[$paymentId]);
        if(!$p||!$s||in_array($s['state'],['done','late','rejected','closed','paid'],true)||(int)$wake['attempts']>=5){
            DB::exec('DELETE FROM sibilpay_hook_wakes WHERE payment_id=? AND generation=?',[$paymentId,(int)$wake['generation']]);
        }else{
            DB::exec('UPDATE sibilpay_hook_wakes SET attempts=attempts+1,due_at=? WHERE payment_id=? AND generation=?',[now_ts()+min(120,5*(1<<(int)$wake['attempts'])),$paymentId,(int)$wake['generation']]);
        }
    });
}
function pay_sibil_spawn(string $command): void
{
    if(Tg::$dry||!function_exists('shell_exec'))return;
    $script=realpath(__FILE__);if(!$script||!is_file(PHP_BINARY))return;
    @shell_exec('PANEL_HOME='.escapeshellarg(panel_home()).' PANEL_ENV='.escapeshellarg(env_file_path()).' nohup '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.$command.' >/dev/null 2>&1 &');
}
