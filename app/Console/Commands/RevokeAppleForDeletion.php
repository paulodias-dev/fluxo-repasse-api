<?php
namespace App\Console\Commands;
use App\Services\AppleTokenExchange;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
final class RevokeAppleForDeletion extends Command
{
    protected $signature='repasse:privacy:revoke-apple {request_id} {--confirm}';
    protected $description='Revoga refresh token Apple de solicitação de exclusão revisada; não apaga conta ou dados financeiros';
    public function handle(AppleTokenExchange $apple): int
    {
        if(!$this->option('confirm')){$this->error('Revise a solicitação e confirme explicitamente com --confirm.');return self::FAILURE;}
        if (! config('services.privacy_closure.enabled')) { $this->error('Fluxo operacional de privacidade desabilitado.'); return self::FAILURE; }
        $row=DB::transaction(function () {
            $candidate=DB::table('account_deletion_requests')->where('id',$this->argument('request_id'))->first();
            if(!$candidate){return null;}
            DB::table('users')->where('id',$candidate->user_id)->where('tenant_id',$candidate->tenant_id)->lockForUpdate()->firstOrFail();
            $current=DB::table('account_deletion_requests')->where('id',$candidate->id)->whereIn('status',['pending','processing'])->lockForUpdate()->first();
            if(!$current){return null;}
            $missing = DB::table('social_accounts')->where('user_id',$current->user_id)->where('provider','apple')->whereNull('apple_revoked_at')->whereNull('apple_refresh_ciphertext')->exists();
            if ($missing) { return null; }
            DB::table('account_deletion_requests')->where('id',$current->id)->update(['status'=>'processing','updated_at'=>now()]);
            return $current;
        },3);
        if(!$row){$this->error('Solicitação indisponível ou credencial Apple ausente; revise antes de prosseguir.');return self::FAILURE;}
        $accounts=DB::table('social_accounts')->where('user_id',$row->user_id)->where('provider','apple')->get();
        foreach($accounts as $account){
            if($account->apple_revoked_at!==null){continue;}
            if(!$account->apple_refresh_ciphertext){$this->error('Credencial revogável indisponível: requer nova autorização Apple ou tratamento manual.');return self::FAILURE;}
            try{$apple->revoke(Crypt::decryptString($account->apple_refresh_ciphertext));}
            catch(\Throwable){$this->error('Revogação não confirmada. Não apagar a conta.');return self::FAILURE;}
            $changed=DB::table('social_accounts')->where('id',$account->id)->where('apple_refresh_ciphertext',$account->apple_refresh_ciphertext)->update(['apple_refresh_ciphertext'=>null,'apple_revoked_at'=>now(),'updated_at'=>now()]);
            if(!$changed){$this->error('Registro alterado durante revogação. Revisão manual necessária.');return self::FAILURE;}
        }
        $this->info('Revogação Apple processada; exclusão e retenção continuam sujeitas à revisão operacional.');
        return self::SUCCESS;
    }
}
