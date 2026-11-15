<?php
declare(strict_types=1);

/** SibilPay v1: original connection per invoice, independent orders, authoritative API settlement. */
function pay_sibil_schema(): void
{
    DB::pdo()->exec("CREATE TABLE IF NOT EXISTS sibilpay_connections (
        id TEXT PRIMARY KEY, base TEXT NOT NULL, api_key TEXT NOT NULL, created_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS sibilpay_invoices (
        payment_id INTEGER PRIMARY KEY, order_ref TEXT NOT NULL UNIQUE, profile TEXT NOT NULL,
        remote_id TEXT UNIQUE, state TEXT NOT NULL DEFAULT 'creating', payable_rial INTEGER NOT NULL DEFAULT 0,
        payment_url TEXT NOT NULL DEFAULT '', expires_at INTEGER NOT NULL DEFAULT 0,
        next_check INTEGER NOT NULL DEFAULT 0, last_hint INTEGER NOT NULL DEFAULT 0, last_error TEXT NOT NULL DEFAULT '');
        CREATE INDEX IF NOT EXISTS sibilpay_due ON sibilpay_invoices(state,next_check);");
    pay_sibil_hook_schema();
    static $registered = false;
    if (!$registered && !in_array('--sibilpay-worker', $GLOBALS['argv'] ?? [], true)) {
        $registered = true;
        register_shutdown_function('pay_sibil_kick');
    }
}

function pay_sibil_key(string $key): string
{
    return (string)preg_replace('/[\s\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', '', $key);
}
function pay_sibil_base(string $url): string
{
    $url = rtrim(trim($url), '/');
    if (!gw_https_url($url) || str_contains($url, '\\')) return '';
    $u = parse_url($url);
    if (isset($u['query']) || isset($u['fragment'])) return '';
    $url = (string)preg_replace('~/g/[A-Za-z0-9_-]+$~D', '', $url);
    $url = (string)preg_replace('~/api/v1$~D', '', $url);
    return $url . '/api/v1';
}
function pay_sibil_toman(int $rial): string
{
    return number_format(intdiv($rial,10)) . ($rial%10 ? '.' . ($rial%10) : '');
}
function pay_sibil_guard(string $name, callable $body, mixed $busy = null): mixed
{
    static $held = [];
    if (isset($held[$name])) return $body();
    $f = @fopen(panel_home() . '/.sibilpay-' . hash('sha256',$name) . '.lock','c');
    if (!$f) return $busy;
    if (!flock($f,LOCK_EX|LOCK_NB)) {fclose($f);return $busy;}
    $held[$name]=true;
    try {return $body();} finally {unset($held[$name]);flock($f,LOCK_UN);fclose($f);}
}

/** No redirects, credential forwarding, guessed endpoints or provider-body logging. */
function pay_sibil_http(array $profile,string $method,string $path,?array $body=null): array
{
    if (isset($GLOBALS['sibilpay_transport']) && is_callable($GLOBALS['sibilpay_transport']) && Tg::$dry) {
        return ($GLOBALS['sibilpay_transport'])($profile,$method,$path,$body);
    }
    $base=(string)$profile['base'];$key=pay_sibil_key((string)$profile['api_key']);
    if (pay_sibil_base($base)!==$base || !preg_match('/^(sibil_pay_live_|sp_live_)[A-Za-z0-9_-]+$/D',$key)) return ['status'=>0,'code'=>'CONFIG_INVALID'];
    $host=(string)parse_url($base,PHP_URL_HOST);$port=(int)(parse_url($base,PHP_URL_PORT)?:443);
    $ips=[];
    if (filter_var($host,FILTER_VALIDATE_IP)) $ips=[$host];
    else foreach (dns_get_record($host,DNS_A|DNS_AAAA)?:[] as $r) $ips[]=$r['ip']??$r['ipv6']??'';
    if (!$ips) return ['status'=>0,'code'=>'DNS_FAILED'];
    foreach ($ips as $ip) if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return ['status'=>0,'code'=>'PUBLIC_HTTPS_REQUIRED'];
    $ch=curl_init($base.$path);$raw='';$tooLarge=false;
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_RESOLVE=>[$host.':'.$port.':'.(str_contains($ips[0],':')?'['.$ips[0].']':$ips[0])],
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Accept: application/json','Content-Type: application/json'],
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$raw,&$tooLarge):int {
            if (strlen($raw)+strlen($chunk)>1048576){$tooLarge=true;return 0;}$raw.=$chunk;return strlen($chunk);
        }]);
    if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body===[]?'{}':json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if ($ok===false || $tooLarge) return ['status'=>$status,'code'=>'CONNECTION_FAILED'];
    $j=json_decode($raw,true);
    if ($status>=200 && $status<300 && is_array($j) && ($j['success']??null)===true && is_array($j['data']??null)) return ['status'=>$status,'data'=>$j['data']];
    $code=is_array($j)?($j['error']['code']??''):'';
    return ['status'=>$status,'code'=>is_string($code)&&preg_match('/^[A-Z0-9_]{1,70}$/D',$code)?$code:'HTTP_'.$status,
        'definite'=>$status>=400&&$status<500&&is_array($j)&&($j['success']??null)===false];
}
function pay_sibil_error(string $code): string
{
    return match ($code) {
        'WALLET_INSUFFICIENT'=>'اعتبار کیف کارمزد فروشگاه کافی نیست؛ لطفاً روش دیگری انتخاب کنید یا به پشتیبانی اطلاع دهید.',
        'INVALID_TOKEN','KEY_DISABLED','STORE_INACTIVE','STORE_BLOCKED','CONFIG_INVALID'=>'تنظیمات اتصال سیبیل پی نیاز به بررسی پشتیبانی دارد.',
        'NO_CARD_AVAILABLE','AMOUNT_POOL_EXHAUSTED','GATEWAY_DISABLED','UNKNOWN_GATEWAY'=>'درگاه فعلاً امکان ساخت فاکتور ندارد؛ کمی بعد یا با روش دیگری تلاش کنید.',
        'INVALID_AMOUNT','TOPUP_TOO_SMALL','VALIDATION_ERROR'=>'درگاه مبلغ یا اطلاعات درخواست را نپذیرفت؛ با پشتیبانی هماهنگ کنید.',
        'HTTP_301','HTTP_302','HTTP_307','HTTP_308'=>'آدرس API درگاه تغییر مسیر می‌دهد؛ پشتیبانی باید آدرس و پورت اتصال را بررسی کند.',
        default=>'نتیجهٔ این درخواست هنوز مشخص نیست و خودکار پیگیری می‌شود. شناسهٔ همین سفارش را نگه دارید؛ شارژهای جدید کیف پول مستقل هستند.',
    };
}
function pay_sibil_profile(array $s): ?array
{
    return DB::one('SELECT * FROM sibilpay_connections WHERE id=?',[$s['profile']]);
}
function pay_sibil_log_error(int $id,string $code): void
{
    $code=(string)preg_replace('/[^A-Z0-9_]/','',$code);
    DB::exec('UPDATE sibilpay_invoices SET last_error=?,next_check=? WHERE payment_id=?',[substr($code,0,70),now_ts()+60,$id]);
    log_payment('SibilPay request failed',['payment'=>$id,'code'=>substr($code,0,70)]);
}
function pay_sibil_adopt(array $payment,array $s,array $d,array $profile): bool
{
    $url=(string)($d['payment_url']??'');$u=parse_url($url);$o=parse_url($profile['base']);$rial=(int)$payment['amount']*10;
    $exp=strtotime((string)($d['expires_at']??''));
    if (!is_string($d['id']??null)||!preg_match('/^[A-Za-z0-9_-]{4,120}$/D',$d['id'])
        ||($d['order_id']??null)!==$s['order_ref']||($d['amount_rial']??null)!==$rial
        ||!is_int($d['payable_rial']??null)||$d['payable_rial']<$rial||$d['payable_rial']>$rial+999
        ||($d['test_mode']??null)!==false||!in_array($d['status']??'', ['pending','partially_paid','paid','expired','cancelled'],true)
        ||!gw_https_url($url)||str_contains($url,'\\')||isset($u['query'])||isset($u['fragment'])
        ||strtolower($u['host']??'')!==strtolower($o['host']??'')||!$exp||$exp>now_ts()+10900) {
        pay_sibil_log_error((int)$payment['id'],'INVALID_INVOICE_RESPONSE');return false;
    }
    if (!empty($s['remote_id'])&&$s['remote_id']!==$d['id']) {pay_sibil_log_error((int)$payment['id'],'INVOICE_ID_MISMATCH');return false;}
    if ((int)$s['payable_rial']>0&&(int)$s['payable_rial']!==$d['payable_rial']) {pay_sibil_log_error((int)$payment['id'],'INVOICE_AMOUNT_MISMATCH');return false;}
    $closed=in_array($d['status'],['expired','cancelled'],true);
    DB::tx(static function()use($payment,$s,$d,$url,$exp,$closed):void {
        DB::exec('UPDATE sibilpay_invoices SET remote_id=?,payable_rial=?,payment_url=?,expires_at=?,state=?,next_check=?,last_error=\'\' WHERE payment_id=?',
            [$d['id'],$d['payable_rial'],$url,$exp,$closed?'closed':'pending',now_ts()+($closed?3600:30),(int)$payment['id']]);
        $meta=pay_meta(pay_get((int)$payment['id'])??$payment);
        $meta['checkout_url']=$url;$meta['sibilpay_payable_rial']=$d['payable_rial'];
        $meta['sibilpay_order_ref']=$s['order_ref'];$meta['sibilpay_expires_at']=$exp;
        pay_save_meta((int)$payment['id'],$meta);
        DB::exec('UPDATE payments SET authority=? WHERE id=?',[$d['id'],(int)$payment['id']]);
    });
    return true;
}
function pay_sibil_reject(array $payment,string $code): void
{
    DB::tx(static function()use($payment,$code):void {
        DB::exec("UPDATE sibilpay_invoices SET state='rejected',last_error=? WHERE payment_id=?",[$code,(int)$payment['id']]);
        DB::update("UPDATE payments SET status='expire' WHERE id=? AND status='unpaid'",[(int)$payment['id']]);
    });
}
function pay_sibil_process(array $payment,bool $forceCheck=false): array
{
    return pay_sibil_guard('order-'.(int)$payment['order_id'],static function()use($payment,$forceCheck):array {
        $id=(int)$payment['id'];$fresh=pay_get($id);
        if (!$fresh||$fresh['method']!=='sibilpay') return ['ok'=>false,'state'=>'notfound'];
        if ($fresh['status']==='paid') {
            $done=pay_settle($id);
            DB::exec('UPDATE sibilpay_invoices SET state=? WHERE payment_id=?',[!empty($done['ok'])?'done':'paid',$id]);
            return $done;
        }
        $s=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[$id]);
        if (!$s||in_array($s['state'],['rejected','done','late'],true)) return ['ok'=>false,'state'=>'closed'];
        if (!$forceCheck&&(int)$s['next_check']>now_ts()) return ['ok'=>false,'state'=>'waiting'];
        DB::exec('UPDATE sibilpay_invoices SET next_check=? WHERE payment_id=?',[now_ts()+60,$id]);
        $profile=pay_sibil_profile($s);
        if (!$profile) return ['ok'=>false,'state'=>'waiting'];
        $path=empty($s['remote_id'])?'/invoices/by-order/'.rawurlencode($s['order_ref']):'/invoices/'.rawurlencode($s['remote_id']);
        $res=pay_sibil_http($profile,'GET',$path);
        if (!isset($res['data'])) {
            if (empty($s['remote_id'])&&($res['status']??0)===404&&($res['code']??'')==='INVOICE_NOT_FOUND'&&!empty($res['definite'])) pay_sibil_reject($fresh,'INVOICE_NOT_FOUND');
            else pay_sibil_log_error($id,(string)($res['code']??'UNKNOWN'));
            return ['ok'=>false,'state'=>'waiting'];
        }
        $d=$res['data'];
        if (!pay_sibil_adopt($fresh,$s,$d,$profile)) return ['ok'=>false,'state'=>'waiting'];
        if ($d['status']!=='paid') return ['ok'=>false,'state'=>'waiting'];
        if (!is_int($d['paid_amount_rial']??null)||$d['paid_amount_rial']<$d['payable_rial']) {pay_sibil_log_error($id,'INVOICE_UNDERPAID');return ['ok'=>false,'state'=>'waiting'];}
        $verify=pay_sibil_http($profile,'POST','/invoices/'.rawurlencode($d['id']).'/verify',[]);
        $verified=($verify['data']['verified']??null)===true&&($verify['data']['invoice_id']??'')===$d['id'];
        if (!$verified&&($verify['status']??0)===409&&($verify['code']??'')==='INVOICE_ALREADY_VERIFIED') {
            $status=pay_sibil_http($profile,'GET','/invoices/'.rawurlencode($d['id']).'/status');$v=$status['data']??[];
            $verified=($v['id']??'')===$d['id']&&($v['status']??'')==='paid'&&($v['verified']??null)===true
                &&($v['amount_rial']??null)===$d['amount_rial']&&($v['payable_rial']??null)===$d['payable_rial']
                &&is_int($v['paid_amount_rial']??null)&&$v['paid_amount_rial']>=$d['payable_rial'];
        }
        if (!$verified) {pay_sibil_log_error($id,(string)($verify['code']??'VERIFY_FAILED'));return ['ok'=>false,'state'=>'waiting'];}
        $result=pay_accept($fresh,(float)$fresh['amount'],$d['id'],['invoice_id'=>$d['id'],'verified'=>true]);
        DB::exec('UPDATE sibilpay_invoices SET state=?,next_check=? WHERE payment_id=?',[
            ($result['state']??'')==='expired'?'late':(!empty($result['ok'])?'done':'paid'),now_ts()+60,$id]);
        return $result;
    },['ok'=>false,'state'=>'busy']);
}
function pay_sibil_start(array $order): array
{
    return pay_sibil_guard('order-'.(int)$order['id'],static function()use($order):array {
        $row=DB::one("SELECT s.* FROM sibilpay_invoices s JOIN payments p ON p.id=s.payment_id WHERE p.order_id=? AND p.status='unpaid' AND s.state IN ('creating','unknown','pending') ORDER BY p.id DESC LIMIT 1",[(int)$order['id']]);
        if ($row) {
            $p=pay_get((int)$row['payment_id']);pay_sibil_process($p);
            $row=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[(int)$row['payment_id']]);
            $p=pay_get((int)$row['payment_id']);
            if ($p['status']==='paid') return ['ok'=>true,'url'=>'','text'=>'پرداخت این سفارش تأیید شده است.'];
            if ($row['state']==='pending'&&$row['payment_url']!=='') return ['ok'=>true,'url'=>$row['payment_url'],'text'=>'سیبیل پی (کارت به کارت آنی)','payment'=>(int)$p['id']];
            if ($row['state']!=='rejected') return ['ok'=>false,'url'=>'','text'=>pay_sibil_error($row['last_error']).' کد پیگیری: '.$row['order_ref']];
        }
        if ((int)$order['payable']<1||(int)$order['payable']>intdiv(PHP_INT_MAX,10)) return ['ok'=>false,'url'=>'','text'=>'مبلغ معتبر نیست.'];
        $base=pay_sibil_base(set_get('pay_sibilpay_endpoint',''));$key=pay_sibil_key(gw_key('sibilpay'));
        if ($base===''||!preg_match('/^(sibil_pay_live_|sp_live_)[A-Za-z0-9_-]+$/D',$key)) return ['ok'=>false,'url'=>'','text'=>pay_sibil_error('CONFIG_INVALID')];
        $created=DB::tx(static function()use($order,$base,$key):array {
            $profile=hash('sha256',$base."\n".$key);
            DB::exec('INSERT OR IGNORE INTO sibilpay_connections(id,base,api_key,created_at) VALUES(?,?,?,?)',[$profile,$base,$key,now_ts()]);
            $p=pay_new($order,'sibilpay',(int)$order['payable']);pay_prepare($p,$order);
            $ref='sp-'.bin2hex(random_bytes(16));
            DB::exec('INSERT INTO sibilpay_invoices(payment_id,order_ref,profile,next_check) VALUES(?,?,?,?)',[(int)$p['id'],$ref,$profile,now_ts()+30]);
            return [$p,$ref,$profile];
        });
        [$p,$ref,$profileId]=$created;$profile=['base'=>$base,'api_key'=>$key];
        $body=['amount_rial'=>(int)$p['amount']*10,'order_id'=>$ref,'description'=>'SIBILPANEL '.$ref,'expiry_minutes'=>30];
        $callback=pay_callback_url('sibilpay');if (gw_https_url($callback)) $body['callback_url']=$callback.'?order='.rawurlencode($ref);
        $res=pay_sibil_http($profile,'POST','/invoices',$body);
        $s=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[(int)$p['id']]);
        if (isset($res['data'])&&pay_sibil_adopt($p,$s,$res['data'],$profile)) {
            $s=DB::one('SELECT * FROM sibilpay_invoices WHERE payment_id=?',[(int)$p['id']]);
            if ($res['data']['status']==='pending'&&$s['expires_at']>now_ts()) return ['ok'=>true,'url'=>$s['payment_url'],'text'=>'سیبیل پی (کارت به کارت آنی)','payment'=>(int)$p['id']];
            pay_sibil_process($p,true);
            return ['ok'=>false,'url'=>'','text'=>'وضعیت فاکتور در حال پیگیری است؛ برای همین سفارش دوباره پرداخت نکنید.'];
        }
        $code=(string)($res['code']??'INVALID_INVOICE_RESPONSE');
        if (!empty($res['definite'])) pay_sibil_reject($p,$code);
        else {DB::exec("UPDATE sibilpay_invoices SET state='unknown' WHERE payment_id=?",[(int)$p['id']]);pay_sibil_log_error((int)$p['id'],$code);}
        return ['ok'=>false,'url'=>'','text'=>pay_sibil_error($code).' کد پیگیری: '.$ref];
    },['ok'=>false,'url'=>'','text'=>'درخواست همین سفارش در حال پردازش است؛ کمی بعد دوباره تلاش کنید.']);
}

/** Webhooks are untrusted wake hints only. NEVER trust a posted paid flag or ambiguous HMAC documentation. */
function pay_sibil_hint(string $ref): void
{
    if (!preg_match('/^sp-[a-f0-9]{32}$/D',$ref)) return;
    pay_sibil_hook_enqueue($ref);
}
function pay_sibil_poll(): int
{
    return (int)pay_sibil_guard('worker',static function():int {
        $settled=0;$start=microtime(true);
        foreach (DB::all("SELECT p.* FROM sibilpay_invoices s JOIN payments p ON p.id=s.payment_id WHERE s.next_check<=? AND s.state IN ('creating','unknown','pending','closed') ORDER BY s.next_check,p.id LIMIT 5",[now_ts()]) as $p) {
            if (microtime(true)-$start>10) break;
            try {$r=pay_sibil_process($p);if (($r['state']??'')==='success') $settled++;}
            catch(Throwable $e){pay_sibil_log_error((int)$p['id'],'INTERNAL_RETRY');}
        }
        return $settled;
    },0);
}
function pay_sibil_kick(): void
{
    if (Tg::$dry||!function_exists('shell_exec')||in_array('--sibilpay-worker',$GLOBALS['argv']??[],true)||in_array('--sibilpay-hook',$GLOBALS['argv']??[],true)) return;
    static $last=0;if ($last===time()) return;$last=time();
    try {
        // Priority jobs do not wait behind another provider's or another order's poll backlog.
        foreach(DB::all('SELECT payment_id FROM sibilpay_hook_wakes WHERE due_at<=? ORDER BY due_at LIMIT 5',[now_ts()])as$wake)
            pay_sibil_spawn('--sibilpay-hook '.(int)$wake['payment_id']);
        if(pay_sibil_hook_due()||DB::val("SELECT 1 FROM sibilpay_invoices WHERE next_check<=? AND state IN ('creating','unknown','pending','closed') LIMIT 1",[now_ts()]))pay_sibil_spawn('--sibilpay-worker');
    }catch(Throwable $e){/* Existing normal poll remains the durable fallback. */}
}
