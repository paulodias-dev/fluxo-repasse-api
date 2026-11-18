<?php

use App\Http\Controllers\Api\V1\AdminUserAuditController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AccessManagementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\TenantInvitationController;
use App\Http\Controllers\Api\V1\CaseEvidenceController;
use App\Http\Controllers\Api\V1\CasePackageController;
use App\Http\Controllers\Api\V1\CaseWorkController;
use App\Http\Controllers\Api\V1\VehicleFinanceController;
use App\Http\Controllers\Api\V1\VehicleAccountabilityController;
use App\Http\Controllers\Api\V1\VehicleFinanceCategoryController;
use App\Http\Controllers\Api\V1\VehicleFinanceDocumentController;
use App\Http\Controllers\Api\V1\OperationsCenterController;
use App\Http\Controllers\Api\V1\VehiclePortfolioController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReadinessController;
use App\Http\Controllers\Api\V1\RepasseCaseController;
use App\Http\Controllers\Api\V1\RepasseScenarioController;
use App\Http\Controllers\Api\V1\ScenarioReviewController;
use App\Http\Controllers\Api\V1\DistributionWorkbenchController;
use App\Http\Controllers\Api\V1\CommercialTermsController;
use App\Http\Controllers\Api\V1\FinancialPreparationController;
use App\Http\Controllers\Api\V1\PreparationRecordController;
use App\Http\Controllers\Api\V1\ObligationProposalController;
use App\Http\Controllers\Api\V1\ExternalTransferReportController;
use App\Http\Controllers\Api\V1\ControlledRepasseController;
use App\Http\Controllers\Api\V1\CommercialPortfolioController;
use App\Http\Controllers\Api\V1\CommercialReviewController;
use App\Http\Controllers\Api\V1\PartyController;
use App\Http\Controllers\Api\V1\PartyEventController;
use App\Http\Controllers\Api\V1\PartyLinkController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');
    Route::get('/ready', ReadinessController::class)->middleware('throttle:30,1')->name('api.v1.ready');

    Route::prefix('auth')->group(function (): void {
        Route::post('/register', [RegistrationController::class, 'store'])
            ->middleware('throttle:repasse-registration')
            ->name('api.v1.auth.register');
        Route::post('/verify-email', [RegistrationController::class, 'verify'])
            ->middleware('throttle:repasse-verification')
            ->name('api.v1.auth.verify-email');
        Route::post('/resend-verification', [RegistrationController::class, 'resend'])
            ->middleware('throttle:repasse-verification')
            ->name('api.v1.auth.resend-verification');
        Route::post('/invitations/{token}/accept', [RegistrationController::class, 'acceptInvitation'])
            ->middleware('throttle:repasse-registration')
            ->name('api.v1.auth.invitations.accept');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:repasse-login')
            ->name('api.v1.auth.login');

        // A renovação precisa aceitar o access token expirado enquanto ele
        // ainda estiver dentro da janela JWT_REFRESH_TTL.
        Route::post('/refresh', [AuthController::class, 'refresh'])
            ->middleware(['bearer.only', 'throttle:repasse-refresh'])
            ->name('api.v1.auth.refresh');

        Route::middleware(['bearer.only', 'auth:api', 'session.current'])->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/profile', [AuthController::class, 'updateProfile'])
                ->middleware('throttle:repasse-admin-write')->name('api.v1.auth.profile.update');
            Route::get('/access', [AuthController::class, 'access'])
                ->middleware('throttle:repasse-admin-read')->name('api.v1.auth.access');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
            Route::post('/password', [AuthController::class, 'changePassword'])
                ->middleware('throttle:repasse-password')->name('api.v1.auth.password');
        });
    });
    // Cadastro base de pessoas/empresas, sem conta bancária, valores ou regras de repasse.
    Route::prefix('parties')->middleware([
        'bearer.only', 'auth:api', 'session.current', 'permission:parties.manage',
    ])->group(function (): void {
        Route::get('/', [PartyController::class, 'index'])->name('api.v1.parties.index');
        Route::post('/', [PartyController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.parties.store');
        Route::get('/{party}', [PartyController::class, 'show'])->name('api.v1.parties.show');
        Route::patch('/{party}', [PartyController::class, 'update'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.parties.update');
        Route::patch('/{party}/status', [PartyController::class, 'status'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.parties.status');
        Route::get('/{party}/links', [PartyLinkController::class, 'index'])->name('api.v1.parties.links.index');
        Route::post('/{party}/links', [PartyLinkController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.parties.links.store');
        Route::patch('/{party}/links/{link}', [PartyLinkController::class, 'update'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.parties.links.update');
        Route::get('/{party}/events', PartyEventController::class)
            ->middleware('throttle:repasse-admin-read')->name('api.v1.parties.events');
    });

    // Single-org commercial portfolio, documentary triage only; never a payment queue.
    Route::prefix('commercial-portfolio')->middleware([
        'bearer.only', 'auth:api', 'session.current',
        'permission:cases.manage', 'permission:scenarios.manage',
    ])->group(function (): void {
        Route::get('/', [CommercialPortfolioController::class, 'index'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.commercial.portfolio');
        Route::get('/export', [CommercialPortfolioController::class, 'export'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.commercial.portfolio.export');
    });

    // Conferência HUMANA documental de minutas (não aprova repasse monetário).
    // Reuses scenario_reviews.manage for the independent reviewer's extra grant.
    Route::prefix('commercial-reviews')->middleware([
        'bearer.only', 'auth:api', 'session.current',
        'permission:cases.manage', 'permission:scenarios.manage',
    ])->group(function (): void {
        Route::get('/', [CommercialReviewController::class, 'index'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.commercial.reviews.index');
        Route::post('/', [CommercialReviewController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.commercial.reviews.store');
        Route::get('/{review}', [CommercialReviewController::class, 'show'])
            ->name('api.v1.commercial.reviews.show');
        Route::post('/{review}/assign', [CommercialReviewController::class, 'assign'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.commercial.reviews.assign');
        Route::post('/{review}/decide', [CommercialReviewController::class, 'decide'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.commercial.reviews.decide');
        Route::post('/{review}/comment', [CommercialReviewController::class, 'comment'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.commercial.reviews.comment');
        Route::post('/{review}/withdraw', [CommercialReviewController::class, 'withdraw'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.commercial.reviews.withdraw');
        Route::get('/{review}/events', [CommercialReviewController::class, 'events'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.commercial.reviews.events');
        Route::get('/{review}/snapshot', [CommercialReviewController::class, 'snapshot'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.commercial.reviews.snapshot');
        Route::get('/{review}/integrity', [CommercialReviewController::class, 'integrity'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.commercial.reviews.integrity');
    });

    Route::prefix('repasse-cases')->middleware([
        'bearer.only', 'auth:api', 'session.current', 'permission:cases.manage',
    ])->group(function (): void {
        Route::get('/', [RepasseCaseController::class, 'index'])->name('api.v1.cases.index');
        Route::post('/', [RepasseCaseController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.store');
        Route::get('/{case}', [RepasseCaseController::class, 'show'])->name('api.v1.cases.show');
        Route::get('/{case}/closeout-review', [RepasseCaseController::class, 'closeoutReview'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.closeout.review');
        // Macro 49: declaração de repasse externo sem minuta nem cenário.
        // Reutiliza tabela, serviço e comprovante privado da Macro 48.
        Route::get('/{case}/external-transfers/options',
            [ExternalTransferReportController::class, 'directOptions'])
            ->middleware('throttle:repasse-admin-read')
            ->name('api.v1.cases.external.options');
        Route::get('/{case}/external-transfers',
            [ExternalTransferReportController::class, 'directIndex'])
            ->middleware('throttle:repasse-admin-read')
            ->name('api.v1.cases.external.index');
        Route::post('/{case}/external-transfers',
            [ExternalTransferReportController::class, 'directStore'])
            ->middleware('throttle:repasse-admin-write')
            ->name('api.v1.cases.external.store');
        Route::get('/{case}/external-transfers/export',
            [ExternalTransferReportController::class, 'directExport'])
            ->middleware('throttle:repasse-admin-read')
            ->name('api.v1.cases.external.export');
        Route::get('/{case}/external-transfers/{transfer}',
            [ExternalTransferReportController::class, 'directShow'])
            ->middleware('throttle:repasse-admin-read')
            ->name('api.v1.cases.external.show');
        Route::post('/{case}/external-transfers/{transfer}/void',
            [ExternalTransferReportController::class, 'directVoid'])
            ->middleware('throttle:repasse-admin-write')
            ->name('api.v1.cases.external.void');
        Route::get('/{case}/external-transfers/{transfer}/proof',
            [ExternalTransferReportController::class, 'directProof'])
            ->middleware('throttle:repasse-admin-read')
            ->name('api.v1.cases.external.proof');


        // Macro 52: obrigações manuais com aprovação, parcelas externas e trilha imutável.
        // 'repasse.approve' é separado de 'repasse.manage'; autor pode aprovar se autorizado.
        Route::prefix('/{case}/settlements')->middleware('permission:repasse.manage')
            ->group(function (): void {
                Route::get('/overview', [ControlledRepasseController::class, 'overview'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.settlements.overview');
                Route::get('/options', [ControlledRepasseController::class, 'options'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.settlements.options');
                Route::get('/', [ControlledRepasseController::class, 'index'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.settlements.index');
                Route::post('/', [ControlledRepasseController::class, 'store'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.settlements.store');
                Route::get('/{settlement}', [ControlledRepasseController::class, 'show'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.settlements.show');
                Route::post('/{settlement}/approve', [ControlledRepasseController::class, 'approve'])
                    ->middleware(['permission:repasse.approve', 'throttle:repasse-admin-write'])
                    ->name('api.v1.settlements.approve');
                Route::post('/{settlement}/cancel', [ControlledRepasseController::class, 'cancel'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.settlements.cancel');
                Route::post('/{settlement}/payments', [ControlledRepasseController::class, 'pay'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.settlements.pay');
                Route::post('/{settlement}/payments/{payment}/void', [ControlledRepasseController::class, 'voidPayment'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.settlements.payment.void');
                Route::get('/{settlement}/payments/{payment}/proof', [ControlledRepasseController::class, 'proof'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.settlements.payment.proof');
            });

        Route::post('/{case}/sale/complete', [RepasseCaseController::class, 'completeSale'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.sale.complete');
        Route::post('/{case}/complete', [RepasseCaseController::class, 'completeVehicle'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.complete');
        Route::patch('/{case}', [RepasseCaseController::class, 'update'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.update');
        Route::post('/{case}/parties', [RepasseCaseController::class, 'attach'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.parties.attach');
        Route::delete('/{case}/parties/{party}', [RepasseCaseController::class, 'detach'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.parties.detach');
        Route::get('/{case}/events', [RepasseCaseController::class, 'events'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.events');
        // Escrituração operacional, sem pagamentos ou liquidações.
        Route::get('/{case}/finance/summary', [VehicleFinanceController::class, 'summary'])->name('api.v1.cases.finance.summary');
        Route::get('/{case}/finance/entries', [VehicleFinanceController::class, 'index'])->name('api.v1.cases.finance.entries');
        Route::post('/{case}/finance/entries', [VehicleFinanceController::class, 'store'])->middleware('throttle:repasse-admin-write')->name('api.v1.cases.finance.entries.store');
        Route::post('/{case}/finance/entries/{entry}/realize', [VehicleFinanceController::class, 'realize'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.finance.entries.realize');
        Route::post('/{case}/finance/entries/{entry}/void', [VehicleFinanceController::class, 'void'])->middleware('throttle:repasse-admin-write')->name('api.v1.cases.finance.entries.void');

        // Financeiro 360: categoria operacional, documentos privados, correção e relatórios.
        // Todos os endpoints já estão dentro de JWT + session.current + cases.manage.
        Route::get('/{case}/finance/categories', [VehicleFinanceCategoryController::class, 'index'])
            ->name('api.v1.cases.finance.categories');
        Route::post('/{case}/finance/categories', [VehicleFinanceCategoryController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.finance.categories.store');
        Route::patch('/{case}/finance/categories/{category}', [VehicleFinanceCategoryController::class, 'update'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.finance.categories.update');
        Route::get('/{case}/finance/report', [VehicleFinanceController::class, 'report'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.finance.report');
        Route::get('/{case}/finance/export', [VehicleFinanceController::class, 'export'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.finance.export');
        Route::get('/{case}/finance/entries/{entry}', [VehicleFinanceController::class, 'show'])
            ->name('api.v1.cases.finance.entries.show');
        Route::get('/{case}/finance/entries/{entry}/events', [VehicleFinanceController::class, 'events'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.finance.entries.events');
        Route::post('/{case}/finance/entries/{entry}/replace', [VehicleFinanceController::class, 'replace'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.finance.entries.replace');
        Route::post('/{case}/finance/entries/{entry}/documents', [VehicleFinanceDocumentController::class, 'attach'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.finance.entries.documents.attach');
        Route::delete('/{case}/finance/entries/{entry}/documents/{evidence}', [VehicleFinanceDocumentController::class, 'detach'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.finance.entries.documents.detach');
        Route::get('/{case}/finance/entries/{entry}/documents/{evidence}/download', [VehicleFinanceDocumentController::class, 'download'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.finance.entries.documents.download');
        Route::get('/{case}/tasks', [CaseWorkController::class, 'tasks'])->name('api.v1.cases.tasks');
        Route::post('/{case}/tasks', [CaseWorkController::class, 'createTask'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.tasks.store');
        Route::patch('/{case}/tasks/{task}', [CaseWorkController::class, 'updateTask'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.tasks.update');
        Route::get('/{case}/checklist', [CaseWorkController::class, 'checklist'])->name('api.v1.cases.checklist');
        Route::post('/{case}/checklist', [CaseWorkController::class, 'addChecklist'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.checklist.store');
        Route::patch('/{case}/checklist/{item}', [CaseWorkController::class, 'updateChecklist'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.checklist.update');
        Route::get('/{case}/notes', [CaseWorkController::class, 'notes'])->name('api.v1.cases.notes');
        Route::post('/{case}/notes', [CaseWorkController::class, 'addNote'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.notes.store');
        Route::get('/{case}/evidence', [CaseEvidenceController::class, 'index'])->name('api.v1.cases.evidence');
        Route::post('/{case}/evidence', [CaseEvidenceController::class, 'upload'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.evidence.store');
        Route::get('/{case}/evidence/{evidence}/download', [CaseEvidenceController::class, 'download'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.evidence.download');
        Route::patch('/{case}/evidence/{evidence}/archive', [CaseEvidenceController::class, 'archive'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.evidence.archive');

        // Etapa 5 — minutas de condições comerciais, NUNCA autorizações de pagamento.
        // Read and write scoped by vehicle; require case and simulation privileges.
        Route::prefix('/{case}/commercial-terms')->middleware('permission:scenarios.manage')
            ->group(function (): void {
                Route::get('/', [CommercialTermsController::class, 'index'])
                    ->name('api.v1.cases.terms.index');
                Route::post('/', [CommercialTermsController::class, 'store'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.terms.store');
                Route::get('/export', [CommercialTermsController::class, 'export'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.terms.export');
                Route::get('/{term}', [CommercialTermsController::class, 'show'])
                    ->name('api.v1.cases.terms.show');
                Route::put('/{term}', [CommercialTermsController::class, 'update'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.terms.update');
                Route::patch('/{term}/state', [CommercialTermsController::class, 'state'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.terms.state');
                Route::get('/{term}/versions', [CommercialTermsController::class, 'versions'])
                    ->name('api.v1.cases.terms.versions');
                Route::get('/{term}/versions/{revision}', [CommercialTermsController::class, 'version'])
                    ->name('api.v1.cases.terms.version');
                Route::get('/{term}/preview', [CommercialTermsController::class, 'preview'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.terms.preview');
                // Simulação consolidada + checklist de bloqueios, sem emitir obrigação.
                Route::get('/{term}/preparation', [FinancialPreparationController::class, 'show'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.terms.preparation');
                Route::get('/{term}/preparation/export', [FinancialPreparationController::class, 'export'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.terms.preparation.export');
                // Apuração MANUAL e imutável, nunca registro de obrigação/ledger.
                Route::get('/{term}/preparation/records', [PreparationRecordController::class, 'index'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.terms.records.index');
                Route::post('/{term}/preparation/records', [PreparationRecordController::class, 'store'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.terms.records.store');
                Route::get('/{term}/preparation/records/{record}', [PreparationRecordController::class, 'show'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.terms.records.show');
                Route::get('/{term}/preparation/records/{record}/export', [PreparationRecordController::class, 'export'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.terms.records.export');
                // Macro 45: propostas INTERNAS não executáveis a partir de apuração verificada.
                Route::get('/{term}/preparation/proposals/options', [ObligationProposalController::class, 'options'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.proposals.options');
                Route::get('/{term}/preparation/proposals', [ObligationProposalController::class, 'index'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.proposals.index');
                Route::post('/{term}/preparation/proposals', [ObligationProposalController::class, 'store'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.proposals.store');
                Route::get('/{term}/preparation/proposals/{proposal}', [ObligationProposalController::class, 'show'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.proposals.show');
                Route::get('/{term}/preparation/proposals/{proposal}/export', [ObligationProposalController::class, 'export'])
                    ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.proposals.export');
                // Macro 48: relatos comprovados de repasses realizados FORA do sistema.
                // Não gera obrigação, saldo devedor, aprovação monetária ou PIX.
                Route::get('/{term}/preparation/proposals/{proposal}/external-transfers',
                    [ExternalTransferReportController::class, 'index'])
                    ->middleware('throttle:repasse-admin-read')
                    ->name('api.v1.cases.proposals.external.index');
                Route::post('/{term}/preparation/proposals/{proposal}/external-transfers',
                    [ExternalTransferReportController::class, 'store'])
                    ->middleware('throttle:repasse-admin-write')
                    ->name('api.v1.cases.proposals.external.store');
                Route::get('/{term}/preparation/proposals/{proposal}/external-transfers/export',
                    [ExternalTransferReportController::class, 'export'])
                    ->middleware('throttle:repasse-admin-read')
                    ->name('api.v1.cases.proposals.external.export');
                Route::get('/{term}/preparation/proposals/{proposal}/external-transfers/{transfer}',
                    [ExternalTransferReportController::class, 'show'])
                    ->middleware('throttle:repasse-admin-read')
                    ->name('api.v1.cases.proposals.external.show');
                Route::post('/{term}/preparation/proposals/{proposal}/external-transfers/{transfer}/void',
                    [ExternalTransferReportController::class, 'void'])
                    ->middleware('throttle:repasse-admin-write')
                    ->name('api.v1.cases.proposals.external.void');
                Route::get('/{term}/preparation/proposals/{proposal}/external-transfers/{transfer}/proof',
                    [ExternalTransferReportController::class, 'proof'])
                    ->middleware('throttle:repasse-admin-read')
                    ->name('api.v1.cases.proposals.external.proof');
                Route::post('/{term}/fork', [CommercialTermsController::class, 'fork'])
                    ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.terms.fork');
            });

        // Etapa 4: leitura consolidada por veículo; exige casos E cenários.
        // Não concede permissão de revisão nem de criação de pacotes.
        Route::get('/{case}/documentary/overview', [VehicleAccountabilityController::class, 'index'])
            ->middleware(['permission:scenarios.manage', 'throttle:repasse-admin-read'])
            ->name('api.v1.cases.documentary.overview');
        Route::get('/{case}/documentary/export', [VehicleAccountabilityController::class, 'export'])
            ->middleware(['permission:scenarios.manage', 'throttle:repasse-admin-read'])
            ->name('api.v1.cases.documentary.export');

        // Pacotes de prestação de contas e conferência: snapshots não executáveis.
        // Ler exige dossiês + cenários; criar exige uma terceira permissão específica.
        Route::prefix('/{case}/packages')->middleware('permission:scenarios.manage')->group(function (): void {
            Route::get('/options', [CasePackageController::class, 'options'])
                ->name('api.v1.cases.packages.options');
            Route::get('/', [CasePackageController::class, 'index'])
                ->name('api.v1.cases.packages.index');
            Route::post('/preview', [CasePackageController::class, 'preview'])
                ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.packages.preview');
            Route::post('/', [CasePackageController::class, 'store'])
                ->middleware(['permission:packages.manage', 'throttle:repasse-admin-write'])
                ->name('api.v1.cases.packages.store');
            Route::get('/{package}', [CasePackageController::class, 'show'])
                ->name('api.v1.cases.packages.show');
            Route::get('/{package}/integrity', [CasePackageController::class, 'integrity'])
                ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.packages.integrity');
            Route::get('/{package}/compare', [CasePackageController::class, 'compare'])
                ->name('api.v1.cases.packages.compare');
            Route::get('/{package}/download', [CasePackageController::class, 'download'])
                ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.packages.download');
        });
    });

    Route::prefix('operations')->middleware([
        'bearer.only', 'auth:api', 'session.current', 'permission:cases.manage',
    ])->group(function (): void {
        Route::get('/dashboard', [OperationsCenterController::class, 'dashboard'])
            ->name('api.v1.operations.dashboard');
        Route::get('/vehicles', [VehiclePortfolioController::class, 'index'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.operations.vehicles');
        Route::get('/assignees', [OperationsCenterController::class, 'assignees'])
            ->name('api.v1.operations.assignees');
        Route::get('/tasks', [OperationsCenterController::class, 'tasks'])
            ->name('api.v1.operations.tasks');
        Route::get('/export', [OperationsCenterController::class, 'export'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.operations.export');
    });


    // Cenários financeiros exclusivamente de simulação: sem pagamentos, aprovação ou ledger.
    Route::prefix('finance')->middleware([
        'bearer.only', 'auth:api', 'session.current',
        'permission:cases.manage', 'permission:scenarios.manage',
    ])->group(function (): void {
        Route::get('/summary', [RepasseScenarioController::class, 'summary'])->name('api.v1.finance.summary');
        Route::get('/cases', [RepasseScenarioController::class, 'cases'])->name('api.v1.finance.cases');
        Route::get('/cases/{case}/parties', [RepasseScenarioController::class, 'parties'])->name('api.v1.finance.case_parties');
        Route::get('/scenarios', [RepasseScenarioController::class, 'index'])->name('api.v1.finance.scenarios.index');
        Route::post('/scenarios', [RepasseScenarioController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.scenarios.store');
        Route::get('/scenarios/{scenario}', [RepasseScenarioController::class, 'show'])->name('api.v1.finance.scenarios.show');
        Route::put('/scenarios/{scenario}', [RepasseScenarioController::class, 'replace'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.scenarios.replace');
        Route::patch('/scenarios/{scenario}/state', [RepasseScenarioController::class, 'changeState'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.scenarios.state');
        Route::get('/scenarios/{scenario}/versions', [RepasseScenarioController::class, 'versions'])
            ->name('api.v1.finance.scenarios.versions');
        Route::get('/scenarios/{scenario}/versions/{revision}', [RepasseScenarioController::class, 'version'])
            ->name('api.v1.finance.scenarios.version');
        Route::get('/scenarios/{scenario}/compare', [RepasseScenarioController::class, 'compare'])
            ->name('api.v1.finance.scenarios.compare');
        Route::post('/scenarios/{scenario}/fork', [RepasseScenarioController::class, 'fork'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.scenarios.fork');
        Route::get('/scenarios/{scenario}/export', [RepasseScenarioController::class, 'export'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.finance.scenarios.export');

        // Modelos versionados e geração idempotente de SIMULAÇÕES, nunca pagamentos.
        Route::get('/distribution-templates', [DistributionWorkbenchController::class, 'templates'])
            ->name('api.v1.finance.distributions.templates');
        Route::post('/distribution-templates', [DistributionWorkbenchController::class, 'createTemplate'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.distributions.templates.store');
        Route::get('/distribution-templates/{template}', [DistributionWorkbenchController::class, 'showTemplate'])
            ->name('api.v1.finance.distributions.templates.show');
        Route::put('/distribution-templates/{template}', [DistributionWorkbenchController::class, 'replaceTemplate'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.distributions.templates.update');
        Route::patch('/distribution-templates/{template}/state', [DistributionWorkbenchController::class, 'changeTemplateState'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.distributions.templates.state');
        Route::get('/distribution-templates/{template}/versions', [DistributionWorkbenchController::class, 'templateVersions'])
            ->name('api.v1.finance.distributions.templates.versions');
        Route::get('/distribution-templates/{template}/versions/{revision}', [DistributionWorkbenchController::class, 'templateVersion'])
            ->name('api.v1.finance.distributions.templates.version');
        Route::post('/distribution-templates/{template}/fork', [DistributionWorkbenchController::class, 'forkTemplate'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.distributions.templates.fork');
        Route::post('/distribution-templates/{template}/preview', [DistributionWorkbenchController::class, 'previewTemplate'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.finance.distributions.templates.preview');
        Route::post('/distribution-templates/{template}/batch-preview', [DistributionWorkbenchController::class, 'previewBatch'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.finance.distributions.batch.preview');
        Route::post('/distribution-templates/{template}/batches', [DistributionWorkbenchController::class, 'applyBatch'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.distributions.batch.apply');
        Route::get('/distribution-batches', [DistributionWorkbenchController::class, 'batches'])
            ->name('api.v1.finance.distributions.batches');
        Route::get('/distribution-batches/{batch}', [DistributionWorkbenchController::class, 'showBatch'])
            ->name('api.v1.finance.distributions.batches.show');
        Route::get('/distribution-batches/{batch}/export', [DistributionWorkbenchController::class, 'exportBatch'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.finance.distributions.batches.export');

        // Pareceres DOCUMENTAIS: nenhuma decisão autoriza execução financeira.
        Route::get('/reviews/summary', [ScenarioReviewController::class, 'summary'])
            ->name('api.v1.finance.reviews.summary');
        Route::get('/reviews/reviewers', [ScenarioReviewController::class, 'reviewers'])
            ->name('api.v1.finance.reviews.reviewers');
        Route::get('/reviews', [ScenarioReviewController::class, 'index'])
            ->name('api.v1.finance.reviews.index');
        Route::get('/scenarios/{scenario}/reviews', [ScenarioReviewController::class, 'forScenario'])
            ->name('api.v1.finance.reviews.scenario');
        Route::post('/scenarios/{scenario}/reviews', [ScenarioReviewController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.reviews.store');
        Route::get('/reviews/{review}', [ScenarioReviewController::class, 'show'])
            ->name('api.v1.finance.reviews.show');
        Route::post('/reviews/{review}/comment', [ScenarioReviewController::class, 'comment'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.reviews.comment');
        Route::post('/reviews/{review}/withdraw', [ScenarioReviewController::class, 'withdraw'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.finance.reviews.withdraw');
        Route::post('/reviews/{review}/assign', [ScenarioReviewController::class, 'assign'])
            ->middleware(['permission:scenario_reviews.manage', 'throttle:repasse-admin-write'])
            ->name('api.v1.finance.reviews.assign');
        Route::post('/reviews/{review}/decide', [ScenarioReviewController::class, 'decide'])
            ->middleware(['permission:scenario_reviews.manage', 'throttle:repasse-admin-write'])
            ->name('api.v1.finance.reviews.decide');
        Route::get('/reviews/{review}/events', [ScenarioReviewController::class, 'events'])
            ->name('api.v1.finance.reviews.events');
        Route::get('/reviews/{review}/snapshot', [ScenarioReviewController::class, 'snapshot'])
            ->name('api.v1.finance.reviews.snapshot');
        Route::get('/reviews/{review}/integrity', [ScenarioReviewController::class, 'integrity'])
            ->name('api.v1.finance.reviews.integrity');
        Route::get('/reviews/{review}/export', [ScenarioReviewController::class, 'export'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.finance.reviews.export');


    });

    Route::prefix('admin/access')->middleware([
        'bearer.only', 'auth:api', 'session.current', 'permission:roles.manage',
    ])->group(function (): void {
        Route::get('/', [AccessManagementController::class, 'index'])->name('api.v1.access.index');
        Route::post('/roles', [AccessManagementController::class, 'saveRole'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.access.roles.store');
        Route::patch('/roles/{role}', [AccessManagementController::class, 'saveRole'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.access.roles.update');
        Route::get('/users/{user}/roles', [AccessManagementController::class, 'userRoles'])
            ->name('api.v1.access.users.roles');
        Route::put('/users/{user}/roles', [AccessManagementController::class, 'assign'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.access.users.roles.update');
    });

    Route::prefix('admin')->middleware([
        'bearer.only', 'auth:api', 'session.current', 'permission:users.manage',
    ])->group(function (): void {
        Route::get('/invitations', [TenantInvitationController::class, 'index'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.admin.invitations.index');
        Route::post('/invitations', [TenantInvitationController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.admin.invitations.store');
        Route::delete('/invitations/{invitation}', [TenantInvitationController::class, 'revoke'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.admin.invitations.revoke');
        Route::get('/users', [AdminUserController::class, 'index'])->name('api.v1.admin.users.index');
        Route::post('/users', [AdminUserController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.admin.users.store');
        Route::patch('/users/{user}', [AdminUserController::class, 'update'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.admin.users.update');
        Route::get('/users/{user}/events', AdminUserAuditController::class)
            ->middleware('throttle:repasse-admin-read')->name('api.v1.admin.users.events');
        Route::patch('/users/{user}/status', [AdminUserController::class, 'status'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.admin.users.status');
    });
});
