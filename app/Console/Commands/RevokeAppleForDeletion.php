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
        $row=DB::table('account_deletion_requests')->where('id',$this->argument('request_id'))->where('status','pending')->first();
        if(!$row){$this->error('Solicitação pendente não encontrada.');return self::FAILURE;}
        $accounts=DB::table('social_accounts')->where('user_id',$row->user_id)->where('provider','apple')->get();
        foreach($accounts as $account){
            if($account->apple_revoked_at!==null){continue;}
            if(!$account->apple_refresh_ciphertext){$this->error('Credencial revogável indisponível: requer nova autorização Apple ou tratamento manual.');return self::FAILURE;}
            try{$apple->revoke(Crypt::decryptString($account->apple_refresh_ciphertext));}
            catch(\Throwable){$this->error('Revogação não confirmada. Não apagar a conta.');return self::FAILURE;}
            DB::table('social_accounts')->where('id',$account->id)->where('apple_refresh_ciphertext',$account->apple_refresh_ciphertext)->update(['apple_refresh_ciphertext'=>null,'apple_revoked_at'=>now(),'updated_at'=>now()]);
        }
        $this->info('Revogação Apple processada; exclusão e retenção continuam sujeitas à revisão operacional.');
        return self::SUCCESS;
    }
}
