<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class MercadoSubscriptions
{
    public static function call(string $env, string $method, string $path, array $data = [], ?string $key = null): array
    {
        $token = DB::table('saas_gateways')->where('environment',$env)->value('token');
        abort_unless($token,503,'Mercado Pago não configurado.');
        $http = Http::baseUrl('https://api.mercadopago.com')->withToken(Crypt::decryptString($token))
            ->acceptJson()->timeout(10)->connectTimeout(3)->withoutRedirecting();
        if ($key) $http = $http->withHeaders(['X-Idempotency-Key'=>$key]);
        try {
            $result = $http->{$method}($path,$data);
        } catch (ConnectionException) {
            abort(503,'Mercado Pago indisponível.');
        }
        abort_unless($result->successful() && is_array($result->json()),502,'O Mercado Pago não confirmou a operação.');
        return $result->json();
    }

    public static function notify(Request $request, string $env): void
    {
        $secret = DB::table('saas_gateways')->where('environment',$env)->value('secret');
        abort_unless($secret,403);
        // PHP normaliza pontos nos nomes da query. Validar o ID que será consultado.
        parse_str($request->server('QUERY_STRING',''),$query);
        $id = $query['data_id'] ?? '';
        abort_unless(is_string($id) && preg_match('/^[a-zA-Z0-9-]{1,128}$/D',$id),400);
        $parts = [];
        foreach (explode(',',(string)$request->header('x-signature')) as $pair) {
            [$k,$v] = array_pad(explode('=',trim($pair),2),2,'');
            $parts[$k]=$v;
        }
        $rid=$request->header('x-request-id');
        $ts=$parts['ts'] ?? '';
        abort_unless($rid && ctype_digit($ts),403);
        $manifest='id:'.strtolower($id).';request-id:'.$rid.';ts:'.$ts.';';
        abort_unless(hash_equals(hash_hmac('sha256',$manifest,Crypt::decryptString($secret)), $parts['v1'] ?? ''),403);
        $type=$request->input('type');
        if ($type === 'subscription_preapproval') {
            $remote=self::call($env,'get','/preapproval/'.$id);
            DB::table('saas_subscriptions')->where('environment',$env)->where('provider_id',$id)
                ->where('id',$remote['external_reference'] ?? '')->update(['status'=>$remote['status'],'updated_at'=>now()]);
            return;
        }
        if ($type !== 'subscription_authorized_payment' && $type !== 'payment') return;
        if ($type === 'payment') {
            $list=self::call($env,'get','/authorized_payments/search',['payment_id'=>$id]);
            $invoiceId=$list['results'][0]['id'] ?? null;
            if (! $invoiceId) return;
        } else $invoiceId=$id;
        $invoice=self::call($env,'get','/authorized_payments/'.rawurlencode((string)$invoiceId));
        $pid=$invoice['payment']['id'] ?? null;
        if (! $pid) return;
        DB::transaction(function () use ($env,$invoice,$pid) {
            $sub=DB::table('saas_subscriptions')->where('environment',$env)
                ->where('provider_id',$invoice['preapproval_id'])->lockForUpdate()->first();
            if (! $sub) return;
            $payment=self::call($env,'get','/v1/payments/'.rawurlencode((string)$pid));
            $amount=(int)round(((float)($payment['transaction_amount'] ?? 0))*100);
            abort_unless((string)$payment['id'] === (string)$pid && $payment['currency_id']==='BRL'
                && $amount === (int)$sub->monthly_cents && (bool)$payment['live_mode'] === ($env === 'production'),422);
            $until=null;
            if ($payment['status']==='approved' && (float)($payment['transaction_amount_refunded'] ?? 0) === 0.0) {
                abort_unless(!empty($invoice['debit_date']),422);
                $until=CarbonImmutable::parse($invoice['debit_date'])->utc()->addMonthNoOverflow();
            }
            DB::table('saas_charges')->upsert([['environment'=>$env,'provider_id'=>(string)$pid,
                'subscription_id'=>$sub->id,'tenant_id'=>$sub->tenant_id,'status'=>$payment['status'],
                'amount_cents'=>$amount,'paid_until'=>$until,'created_at'=>now(),'updated_at'=>now()]],
                ['environment','provider_id'],['status','paid_until','updated_at']);
            DB::table('saas_subscriptions')->where('id',$sub->id)->update([
                'paid_until'=>DB::table('saas_charges')->where('subscription_id',$sub->id)->max('paid_until'),'updated_at'=>now(),
            ]);
        });
    }
}
