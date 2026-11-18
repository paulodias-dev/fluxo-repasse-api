<?php

namespace App\Support;

/**
 * Single source of truth for the capabilities that actually exist in the UI.
 * This is NOT a business/financial authorization policy.
 */
final class OperationalAccessCatalog
{
    public const TECHNICAL_PERMISSIONS = [
        'users.manage' => 'Gerenciar usuários administrativos',
        'parties.manage' => 'Gerenciar cadastro de pessoas e empresas',
        'roles.manage' => 'Gerenciar papéis e atribuições de acesso',
        'cases.manage' => 'Gerenciar dossiês e central operacional sem movimentar dinheiro',
        'scenarios.manage' => 'Criar e consultar cenários hipotéticos sem movimentar dinheiro',
        'scenario_reviews.manage' => 'Emitir parecer documental sem aprovação financeira',
        'packages.manage' => 'Emitir pacotes documentais privados sem aprovação financeira',
        'repasse.manage' => 'Preparar distribuições e registrar pagamentos externos de forma controlada',
        'repasse.approve' => 'Aprovar distribuições financeiras manualmente',
    ];

    public const MODULES = [
        'users' => ['name' => 'Usuários', 'requires' => ['users.manage']],
        'parties' => ['name' => 'Pessoas e empresas', 'requires' => ['parties.manage']],
        'access' => ['name' => 'Papéis e acessos', 'requires' => ['roles.manage']],
        'operations' => ['name' => 'Central operacional', 'requires' => ['cases.manage']],
        'cases' => ['name' => 'Veículos — ambiente 360', 'requires' => ['cases.manage']],
        'scenarios' => ['name' => 'Cenários de repasse', 'requires' => ['cases.manage', 'scenarios.manage']],
        'commercial' => ['name' => 'Central comercial', 'requires' => ['cases.manage', 'scenarios.manage']],
        'commercial_reviews' => ['name' => 'Revisões comerciais documentais', 'requires' => ['cases.manage', 'scenarios.manage']],
        'reviews' => ['name' => 'Revisões documentais', 'requires' => ['cases.manage', 'scenarios.manage']],
        'packages' => ['name' => 'Pacotes documentais', 'requires' => ['cases.manage', 'scenarios.manage']],
    ];

    public static function keys(): array
    {
        return array_keys(self::TECHNICAL_PERMISSIONS);
    }

    public static function missingForAdmin(array $granted): array
    {
        return array_values(array_diff(self::keys(), $granted));
    }

    public static function modulesFor(array $granted): array
    {
        $allowed = array_fill_keys($granted, true);
        $modules = [];
        foreach (self::MODULES as $id => $data) {
            $missing = array_values(array_filter(
                $data['requires'], static fn (string $p): bool => ! isset($allowed[$p])
            ));
            $modules[] = [
                'id' => $id, 'name' => $data['name'],
                'requires' => $data['requires'],
                'available' => $missing === [],
                'missing_permissions' => $missing,
            ];
        }

        return $modules;
    }
}
