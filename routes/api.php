<?php

use App\Http\Controllers\Api\V1\AdminUserAuditController;
use App\Http\Controllers\Api\V1\AdminUserController;
use App\Http\Controllers\Api\V1\AccessManagementController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CaseEvidenceController;
use App\Http\Controllers\Api\V1\CasePackageController;
use App\Http\Controllers\Api\V1\CaseWorkController;
use App\Http\Controllers\Api\V1\OperationsCenterController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ReadinessController;
use App\Http\Controllers\Api\V1\RepasseCaseController;
use App\Http\Controllers\Api\V1\RepasseScenarioController;
use App\Http\Controllers\Api\V1\ScenarioReviewController;
use App\Http\Controllers\Api\V1\DistributionWorkbenchController;
use App\Http\Controllers\Api\V1\PartyController;
use App\Http\Controllers\Api\V1\PartyEventController;
use App\Http\Controllers\Api\V1\PartyLinkController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');
    Route::get('/ready', ReadinessController::class)->middleware('throttle:30,1')->name('api.v1.ready');

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:repasse-login')
            ->name('api.v1.auth.login');

        Route::middleware(['bearer.only', 'auth:api', 'session.current'])->group(function (): void {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/refresh', [AuthController::class, 'refresh'])
                ->middleware('throttle:repasse-refresh')->name('api.v1.auth.refresh');
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

    Route::prefix('repasse-cases')->middleware([
        'bearer.only', 'auth:api', 'session.current', 'permission:cases.manage',
    ])->group(function (): void {
        Route::get('/', [RepasseCaseController::class, 'index'])->name('api.v1.cases.index');
        Route::post('/', [RepasseCaseController::class, 'store'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.store');
        Route::get('/{case}', [RepasseCaseController::class, 'show'])->name('api.v1.cases.show');
        Route::patch('/{case}', [RepasseCaseController::class, 'update'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.update');
        Route::post('/{case}/parties', [RepasseCaseController::class, 'attach'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.parties.attach');
        Route::delete('/{case}/parties/{party}', [RepasseCaseController::class, 'detach'])
            ->middleware('throttle:repasse-admin-write')->name('api.v1.cases.parties.detach');
        Route::get('/{case}/events', [RepasseCaseController::class, 'events'])
            ->middleware('throttle:repasse-admin-read')->name('api.v1.cases.events');
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
