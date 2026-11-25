<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
final class AppleTokenExchange
{
    private function fail(): never
    {
        throw ValidationException::withMessages(['provider'=>'Não foi possível concluir a autorização Apple. Inicie nova tentativa.']);
    }
    private function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private function secret(): string
    {
        $team = config('services.apple_native.team_id'); $kid = config('services.apple_native.key_id');
        $aud = config('services.apple_native.audience'); $key = config('services.apple_native.private_key');
        abort_unless(is_string($team)&&$team!==''&&is_string($kid)&&$kid!==''&&is_string($aud)&&$aud!==''&&is_string($key)&&$key!=='',503,'Autorização Apple indisponível.');
        $head=$this->b64(json_encode(['alg'=>'ES256','kid'=>$kid],JSON_THROW_ON_ERROR));
        $body=$this->b64(json_encode(['iss'=>$team,'sub'=>$aud,'aud'=>'https://appleid.apple.com','iat'=>now()->timestamp,'exp'=>now()->addMinutes(5)->timestamp],JSON_THROW_ON_ERROR));
        $private=openssl_pkey_get_private(str_replace('\\n',"\n",$key));
        $details=$private ? openssl_pkey_get_details($private) : false;
        if (!$details || ($details['ec']['curve_name'] ?? null)!=='prime256v1' || !openssl_sign($head.'.'.$body,$der,$private,OPENSSL_ALGO_SHA256)) { $this->fail(); }
        // P-256 ECDSA DER sequence is at most 72 bytes. JOSE requires fixed 32-byte r and s.
        if (strlen($der)<8 || ord($der[0])!==48 || ord($der[1])!==strlen($der)-2 || ord($der[2])!==2) { $this->fail(); }
        $rl=ord($der[3]);$off=4+$rl;
        if ($rl<1||$rl>33||$off+2>strlen($der)||ord($der[$off])!==2) { $this->fail(); }
        $sl=ord($der[$off+1]);
        if ($sl<1||$sl>33||$off+2+$sl!==strlen($der)) { $this->fail(); }
        $r=ltrim(substr($der,4,$rl),"\x00");$s=ltrim(substr($der,$off+2,$sl),"\x00");
        if(strlen($r)>32||strlen($s)>32){$this->fail();}
        return $head.'.'.$body.'.'.$this->b64(str_pad($r,32,"\x00",STR_PAD_LEFT).str_pad($s,32,"\x00",STR_PAD_LEFT));
    }
    public function exchange(string $code, array $identity): string
    {
        $secret=$this->secret();
        try {
            $response=Http::asForm()->acceptJson()->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects'=>false])
                ->post('https://appleid.apple.com/auth/token',['client_id'=>config('services.apple_native.audience'),'client_secret'=>$secret,'code'=>$code,'grant_type'=>'authorization_code']);
            $refresh=$response->json('refresh_token');$jwt=$response->json('id_token');
            if(!$response->successful()||!is_string($refresh)||$refresh===''||strlen($refresh)>8192||!is_string($jwt)){$this->fail();}
            $parts=explode('.',$jwt);if(count($parts)!==3){$this->fail();}
            // The token endpoint is authenticated TLS; correlate its identity with the already signature-verified identity.
            $claims=json_decode(base64_decode(strtr($parts[1],'-_','+/'),true),true,8,JSON_THROW_ON_ERROR);
            if(($claims['iss']??null)!=='https://appleid.apple.com'||($claims['aud']??null)!==config('services.apple_native.audience')||($claims['sub']??null)!==$identity['sub']){$this->fail();}
            return $refresh;
        } catch (\Throwable) { $this->fail(); }
    }
    public function revoke(string $refresh): void
    {
        $secret=$this->secret();
        try {
            $response=Http::asForm()->connectTimeout(3)->timeout(8)->withOptions(['allow_redirects'=>false])->post('https://appleid.apple.com/auth/revoke',[
                'client_id'=>config('services.apple_native.audience'),'client_secret'=>$secret,'token'=>$refresh,'token_type_hint'=>'refresh_token',
            ]);
            if(!$response->successful()){$this->fail();}
        } catch (\Throwable) { $this->fail(); }
    }
}
