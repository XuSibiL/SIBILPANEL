<?php
declare(strict_types=1);

/** Webhooks accelerate only authoritative API verification; posted payment fields are never settlement authority. */
function pay_sibil_hook_schema(): void
{
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
    return hash('sha256',"store-contract-v2\n".pay_sibil_base(set_get('pay_sibilpay_endpoint',''))."\n".gw_key('sibilpay')."\n".pay_sibil_hook_target());
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
        pay_sibil_hook_status($fp,'checking',120);
        if($target===''){pay_sibil_hook_status($fp,'public_url_missing');return;}
        if(!pay_sibil_hook_probe($target)){pay_sibil_hook_status($fp,'endpoint_unreachable');return;}
        $profile=['base'=>pay_sibil_base(set_get('pay_sibilpay_endpoint','')),'api_key'=>pay_sibil_key(gw_key('sibilpay'))];
        $res=pay_sibil_http($profile,'GET','/store');$data=$res['data']??null;
        // Persist only bounded HTTP/error identifiers, never response bodies, keys or URLs.
        $code=(string)($res['code']??'');
        set_put('pay_sibilpay_hook_http',(string)max(0,min(599,(int)($res['status']??0))));
        set_put('pay_sibilpay_hook_error',preg_match('/^[A-Z0-9_]{1,70}$/D',$code)?$code:'');
        if(!is_array($data)||!array_key_exists('webhook_url',$data)||(!is_string($data['webhook_url'])&&$data['webhook_url']!==null)){
            pay_sibil_hook_status($fp,is_array($data)?'store_contract_invalid':'store_lookup_failed');return;
        }
        $existing=trim((string)$data['webhook_url']);
        if($existing!==''&&$existing!==$target){pay_sibil_hook_status($fp,'conflict_preserved',3600);return;}
        if($fp!==pay_sibil_hook_fingerprint()||!gw_on('sibilpay'))return;
        if($existing===''){
            $res=pay_sibil_http($profile,'PATCH','/store',['webhook_url'=>$target]);
            if(!isset($res['data'])){pay_sibil_hook_status($fp,'registration_failed');return;}
            $res=pay_sibil_http($profile,'GET','/store');
            if(($res['data']['webhook_url']??null)!==$target){pay_sibil_hook_status($fp,'registration_unconfirmed');return;}
        }
        pay_sibil_hook_status($fp,'registered',3600);
    });
}
function pay_sibil_hook_status_text(): string
{
    if(set_get('pay_sibilpay_hook_fingerprint','')!==pay_sibil_hook_fingerprint())return '⏳ پیکربندی جدید در انتظار بررسی و ثبت خودکار';
    return match(set_get('pay_sibilpay_hook_status','')){
        'registered'=>'✅ ثبت و از API بازخوانی شده',
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
