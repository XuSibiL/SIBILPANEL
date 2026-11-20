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
        // Do not require this host to reach its own public URL (hairpin/DNS).
        // Provider test delivery + authenticated polling remain authoritative.
        $profile=['base'=>pay_sibil_base(set_get('pay_sibilpay_endpoint','')),'api_key'=>pay_sibil_key(gw_key('sibilpay'))];
        $request=static function(string $method,string $path,?array $body=null)use($profile):array{
            $res=pay_sibil_http($profile,$method,$path,$body);
            if(!is_array($res['data']??null)){
                $code=(string)($res['code']??'HOOK_API_FAILED');
                throw new RuntimeException(preg_match('/^[A-Z0-9_]{1,70}$/D',$code)?$code:'HOOK_API_FAILED');
            }
            return $res['data'];
        };
        $stage='discovery';
        try{
            $list=$request('GET','/hooks/self');
            $stage='registration';
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
            if($stage==='discovery'&&in_array($e->getMessage(),['NOT_FOUND','HTTP_404','HTTP_502','HTTP_503','HTTP_504','CONNECTION_FAILED'],true)){
                try{
                    $gateways=$request('GET','/gateways');
                    if(array_is_list($gateways)&&count($gateways)>0){pay_sibil_hook_status($fp,'polling_only',60);return;}
                }catch(Throwable $ignored){}
            }
            // Preserve last confirmed explicit endpoint during transient metadata failures.
            // Never fall back to another integration's first/default endpoint.
            if(in_array($e->getMessage(),['WEBHOOK_DISABLED','WEBHOOK_REGISTRATION_UNCONFIRMED','WEBHOOK_CONTRACT_MISMATCH','WEBHOOK_SECRET_MISSING','UNKNOWN_ENDPOINT'],true))
                DB::exec("UPDATE sibilpay_hook_endpoints SET state='error' WHERE scope=?",[$fp]);
            $code=$e->getMessage();set_put('pay_sibilpay_hook_error',preg_match('/^[A-Z0-9_]{1,70}$/D',$code)?$code:'HOOK_API_FAILED');
            pay_sibil_hook_status($fp,'self_registration_failed',60);
        }
    });
}
function pay_sibil_hook_endpoint(): ?string
{
    pay_sibil_hook_sync();
    if(set_get('pay_sibilpay_hook_fingerprint','')===pay_sibil_hook_fingerprint()&&set_get('pay_sibilpay_hook_status','')==='polling_only')return '';
    $e=DB::one("SELECT remote_id FROM sibilpay_hook_endpoints WHERE scope=? AND state='ready' AND secret<>''",[pay_sibil_hook_fingerprint()]);
    return isset($e['remote_id'])?(string)$e['remote_id']:null;
}
/** Timestamped endpoint HMAC. Financial authority is still GET invoice + verify. */
function pay_sibil_hook_receive(string $token,string $raw,string $signature,string $delivery,string $headerEvent): array
{
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))return ['code'=>401];
    $ep=DB::one('SELECT * FROM sibilpay_hook_endpoints WHERE token=?',[$token]);
    if(!$ep)return ['code'=>401];
    if(strlen($raw)>262144)return ['code'=>413];
    if(strlen($ep['secret'])<16||!preg_match('/^t=(\d{1,12}),v1=([a-f0-9]{64})$/D',trim($signature),$m)
      ||abs(now_ts()-(int)$m[1])>300||!hash_equals(hash_hmac('sha256',$m[1].'.'.$raw,$ep['secret']),$m[2]))return ['code'=>401,'body'=>['ok'=>false,'error'=>'bad_signature']];
    $j=json_decode($raw,true);$event=$j['event']??null;
    if(!is_array($j)||!is_string($event)||!preg_match('/^[a-z_.]{1,80}$/D',$event)||($headerEvent!==''&&$event!==$headerEvent))return ['code'=>400];
    if($delivery==='')$delivery='sha-'.hash('sha256',$raw);
    if(!preg_match('/^[A-Za-z0-9_-]{4,150}$/D',$delivery))return ['code'=>400];
    $d=is_array($j['data']??null)?$j['data']:$j;$ref=$d['order_id']??$j['order_id']??'';$payment=null;$s=null;
    if($event==='invoice.paid'){
        $s=is_string($ref)?DB::one('SELECT * FROM sibilpay_invoices WHERE order_ref=? AND profile=?',[$ref,$ep['profile']]):null;
        if(!$s)return ['code'=>200,'body'=>['ok'=>false,'error'=>'unknown_order']];
        $payment=pay_get((int)$s['payment_id']);
        if(!$payment)return ['code'=>200,'body'=>['ok'=>false,'error'=>'unknown_order']];
        if($payment['status']==='paid')return ['code'=>200,'body'=>['ok'=>true,'state'=>'already']];
        if(!is_int($d['amount_rial']??null)||$d['amount_rial']!==(int)$payment['amount']*10)return ['code'=>200,'body'=>['ok'=>false,'error'=>'amount_mismatch']];
        $remote=$d['id']??$d['invoice_id']??null;
        if(!empty($s['remote_id'])&&$remote!==null&&$remote!==$s['remote_id'])return ['code'=>200,'body'=>['ok'=>false,'error'=>'invoice_mismatch']];
        if(($j['livemode']??null)===false||($d['test_mode']??null)===true)return ['code'=>200,'body'=>['ok'=>false,'error'=>'test_event']];
    }
    return DB::tx(static function()use($ep,$raw,$delivery,$j,$event,$ref,$s):array{
        $old=DB::one('SELECT body_hash FROM sibilpay_hook_events WHERE scope=? AND delivery=?',[$ep['scope'],$delivery]);$hash=hash('sha256',$raw);
        if($old)return ['code'=>hash_equals($old['body_hash'],$hash)?200:409,'body'=>hash_equals($old['body_hash'],$hash)?['ok'=>true,'state'=>'already']:['ok'=>false,'error'=>'delivery_conflict']];
        DB::exec('INSERT INTO sibilpay_hook_events(scope,delivery,body_hash) VALUES(?,?,?)',[$ep['scope'],$delivery,$hash]);
        if($event==='invoice.paid'&&$s)pay_sibil_hook_enqueue($ref);
        return ['code'=>200,'body'=>['ok'=>true,'state'=>$event==='invoice.paid'?'queued':'ignored']];
    });
}
function pay_sibil_hook_status_text(): string
{
    if(set_get('pay_sibilpay_hook_fingerprint','')!==pay_sibil_hook_fingerprint())return '⏳ در حال اتصال';
    return match(set_get('pay_sibilpay_hook_status','')){
        'polling_only'=>'⚠️ وبهوک در دسترس نیست؛ استعلام خودکار فعال است',
        'registered'=>'✅ ثبت شده',
        'self_registration_failed'=>match(set_get('pay_sibilpay_hook_error','')){
            'LIMIT_REACHED'=>'⚠️ ظرفیت وبهوک‌های این کلید پر است',
            'INVALID_TOKEN','KEY_DISABLED'=>'⚠️ کلید API را بررسی کنید',
            'CONCURRENT_UPDATE'=>'⏳ در حال تلاش مجدد',
            default=>'⚠️ اتصال ناموفق؛ آدرس درگاه و API را بررسی کنید',
        },
        'public_url_missing'=>'⚠️ آدرس HTTPS پنل ثبت نشده',
        default=>'⏳ در حال اتصال',
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
