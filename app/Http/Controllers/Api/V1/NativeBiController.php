<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseCategoriesRequest;
use App\Support\TenantDB;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
final class NativeBiController extends Controller
{
    public function show(ExpenseCategoriesRequest $request): JsonResponse
    {
        $p = $request->validated();
        $money = TenantDB::table('repasse_case_financial_entries')->whereNull('voided_at')->where('status','realized')
            ->select('repasse_case_id')->selectRaw("SUM(CASE WHEN kind = 'expense' THEN amount_cents ELSE 0 END) AS expenses, SUM(CASE WHEN kind = 'income' THEN amount_cents ELSE 0 END) AS income")
            ->groupBy('repasse_case_id');
        $sold = TenantDB::table('repasse_cases as c')->whereNull('c.admin_deleted_at')->whereBetween('c.sold_on', [$p['from'], $p['to']])
            ->leftJoinSub($money,'m',fn ($join) => $join->on('m.repasse_case_id','=','c.id'))
            ->select('c.id','c.vehicle_type','c.purchased_on','c.sold_on','c.purchase_amount_cents','c.sale_amount_cents','m.expenses','m.income');
        $types = []; $count = 0; $known = 0; $profit = 0; $cost = 0; $days = 0; $dated = 0; $profitable = 0; $loss = 0; $even = 0;
        $sold->chunkById(500, function ($rows) use (&$types,&$count,&$known,&$profit,&$cost,&$days,&$dated,&$profitable,&$loss,&$even): void {
            foreach ($rows as $v) {
                $count++; $type = in_array($v->vehicle_type,['cars','motorcycles','trucks'],true) ? $v->vehicle_type : 'other';
                $types[$type] ??= ['type'=>$type,'sold_count'=>0,'known_count'=>0,'result_cents'=>0,'cost_cents'=>0];
                $types[$type]['sold_count']++;
                if ($v->purchased_on && $v->purchased_on <= $v->sold_on) {
                    $days += (int) CarbonImmutable::parse($v->purchased_on)->diffInDays(CarbonImmutable::parse($v->sold_on)); $dated++;
                }
                if ($v->purchase_amount_cents === null || $v->sale_amount_cents === null) { continue; }
                $c = (int) $v->purchase_amount_cents + (int) $v->expenses;
                $result = (int) $v->sale_amount_cents + (int) $v->income - $c;
                $known++; $profit += $result; $cost += $c;
                if ($result > 0) { $profitable++; } elseif ($result < 0) { $loss++; } else { $even++; }
                $types[$type]['known_count']++; $types[$type]['result_cents'] += $result; $types[$type]['cost_cents'] += $c;
            }
        }, 'c.id', 'id');
        $settlements = null;
        if (Auth::guard('api')->user()->hasPermission('repasse.manage')) {
            $payments = TenantDB::table('repasse_settlement_payments')->whereNull('voided_at')
                ->select('line_id')->selectRaw('SUM(amount_cents) AS paid_cents')->groupBy('line_id');
            $rows = TenantDB::table('repasse_settlement_lines as l')
                ->join('repasse_settlements as s','s.id','=','l.settlement_id')
                ->join('repasse_cases as c','c.id','=','s.repasse_case_id')
                ->leftJoinSub($payments,'p',fn($join)=>$join->on('p.line_id','=','l.id'))
                ->where('s.status','approved')->whereNull('c.admin_deleted_at')->whereBetween('c.sold_on',[$p['from'],$p['to']])
                ->select('l.beneficiary_party_id','l.beneficiary_name')->selectRaw('SUM(l.amount_cents) AS approved_cents, SUM(COALESCE(p.paid_cents, 0)) AS paid_cents')
                ->groupBy('l.beneficiary_party_id','l.beneficiary_name')->orderBy('l.beneficiary_party_id')->get();
            $settlements = $rows->map(fn($v)=>['party_id'=>(int)$v->beneficiary_party_id,'name'=>$v->beneficiary_name,'approved_cents'=>(int)$v->approved_cents,'paid_cents'=>(int)$v->paid_cents,'remaining_cents'=>(int)$v->approved_cents-(int)$v->paid_cents])->values();
        }
        return response()->json(['data'=>[
            'from'=>$p['from'],'to'=>$p['to'],'sold_count'=>$count,'known_count'=>$known,'dated_count'=>$dated,
            'result_cents'=>$profit,'cost_cents'=>$cost,'profitable_count'=>$profitable,'loss_count'=>$loss,'break_even_count'=>$even,
            'average_result_cents'=>$known ? (int) round($profit/$known) : null,'average_stock_days'=>$dated ? round($days/$dated,1) : null,
            'segments'=>array_values($types),'settlements'=>$settlements,
        ]])->header('Cache-Control','no-store, private');
    }
}
