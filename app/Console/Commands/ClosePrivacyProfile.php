<?php

namespace App\Console\Commands;

use App\Services\PrivacyProfileClosure;
use Illuminate\Console\Command;

final class ClosePrivacyProfile extends Command
{
    protected $signature = 'repasse:privacy:close-profile {request_id} {--apply} {--confirm-id=}';
    protected $description = 'Prévia ou encerramento revisado do perfil; preserva histórico e dados de terceiros';

    public function handle(PrivacyProfileClosure $closure): int
    {
        $id = filter_var($this->argument('request_id'), FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if (! $id || ($this->option('apply') && (string) $this->option('confirm-id') !== (string) $id)) {
            $this->error('Identificador inválido ou confirmação explícita ausente.');
            return self::FAILURE;
        }
        try {
            $result = $closure->inspect($id, (bool) $this->option('apply'));
        } catch (\Throwable) {
            $this->error('Não foi possível confirmar a operação. Revise os registros sem expor dados pessoais.');
            return self::FAILURE;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        return $result['blockers'] ? self::FAILURE : self::SUCCESS;
    }
}
