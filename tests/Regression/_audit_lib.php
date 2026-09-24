<?php
// shared harness. Usage: require __DIR__.'/_lib.php';
$__root = dirname(__DIR__, 2); chdir($__root);
require $__root.'/vendor/autoload.php';
$app = require $__root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,DB};
use App\Models\{User,Sale};
$__u = User::find(2);
Auth::guard('api')->setUser($__u); Auth::login($__u); auth()->shouldUse('api');
echo "DB=".DB::connection()->getDatabaseName()."\n";
function call($ctrlClass, $method, array $payload, $verb='POST', $args=[]) {
    $req = Request::create('/api/x', $verb, $payload);
    $u = Auth::guard('api')->user();
    $req->setUserResolver(fn()=>$u);
    app()->instance('request', $req);
    try {
        $r = app($ctrlClass)->$method($req, ...$args);
        $body = $r instanceof Illuminate\Http\JsonResponse || $r instanceof Illuminate\Http\Response ? $r->getContent() : json_encode($r);
        $code = method_exists($r,'getStatusCode') ? $r->getStatusCode() : '-';
        return [$code, substr($body,0,4000)];
    } catch (Illuminate\Http\Exceptions\HttpResponseException $e) {
        return [$e->getResponse()->getStatusCode(), substr($e->getResponse()->getContent(), 0, 300)];
    } catch (Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return [404, 'model not found'];
    } catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
        return [$e->getStatusCode(), $e->getMessage()];
    } catch (Illuminate\Validation\ValidationException $e) {
        return [422, json_encode($e->errors())];
    } catch (Throwable $e) {
        return ['EXC', get_class($e).': '.substr($e->getMessage(),0,250)];
    }
}
function stock($pid,$wh=1,$var=null){ $q=DB::table('product_warehouse')->where('product_id',$pid)->where('warehouse_id',$wh)->whereNull('deleted_at'); $var===null?$q->whereNull('product_variant_id'):$q->where('product_variant_id',$var); return (float)($q->value('qte')); }
function setStock($pid,$wh,$q){ DB::table('product_warehouse')->where('product_id',$pid)->where('warehouse_id',$wh)->whereNull('product_variant_id')->update(['qte'=>$q]); }
function line($pid,$qty,$price=999,$extra=[]){ return array_merge(['product_id'=>$pid,'quantity'=>$qty,'Unit_price'=>$price,'tax_percent'=>0,'tax_method'=>'1','subtotal'=>$qty*$price,'discount'=>0,'discount_Method'=>'2','product_variant_id'=>null,'serial_numbers'=>[],'sale_unit_id'=>1],$extra); }
function salePayload($lines,$o=[]){ $sum=array_sum(array_map(fn($l)=>$l['subtotal'],$lines)); return array_merge(['client_id'=>2,'warehouse_id'=>1,'date'=>date('Y-m-d'),'statut'=>'completed','notes'=>'verify','tax_rate'=>0,'TaxNet'=>0,'discount'=>0,'discount_Method'=>'2','shipping'=>0,'GrandTotal'=>$sum,'discount_from_points'=>0,'used_points'=>0,'payment'=>['status'=>'pending'],'details'=>$lines],$o); }
function mkSale($lines,$o=[]){ [$c,$b]=call(App\Http\Controllers\SalesController::class,'store',salePayload($lines,$o)); $j=json_decode($b,true); return [$c,$b,$j['sale_id']??null]; }
function row($t,$id){ return (array)DB::table($t)->where('id',$id)->first(); }
function brief($cid=2){ [$c,$b]=call(App\Http\Controllers\ClientController::class,'clientBrief',[],'GET',[$cid]); $j=json_decode($b,true); return $c.' '.($j? json_encode(array_intersect_key($j['data']??$j,array_flip(['sale_due','return_due','netBalance','net_balance','total_amount','total_paid','total_amount_return','total_paid_return','points','payments_total']))):$b); }
function updSale($sid,$lines,$o=[]){ return call(App\Http\Controllers\SalesController::class,'update',salePayload($lines,$o),'PUT',[$sid]); }
function detIds($sid){ return DB::table('sale_details')->where('sale_id',$sid)->pluck('id')->all(); }
function destroySale($sid){ return call(App\Http\Controllers\SalesController::class,'destroy',[],'DELETE',[$sid]); }
function cl($id=2){ return (float)DB::table('clients')->where('id',$id)->value('points'); }

// ---- tiny assertion layer (audit suite) ----
$GLOBALS['__fails'] = [];
function check($label, $cond, $detail = '') {
    echo ($cond ? '  PASS  ' : '  FAIL  ').$label.($cond ? '' : "  [$detail]")."\n";
    if (! $cond) { $GLOBALS['__fails'][] = $label; }
}
function finish($name) {
    $n = count($GLOBALS['__fails']);
    echo $n === 0 ? "\n$name: PASS\n" : "\n$name: FAIL ($n): ".implode('; ', $GLOBALS['__fails'])."\n";
    exit($n === 0 ? 0 : 1);
}
