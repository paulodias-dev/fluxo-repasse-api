<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AcceptTenantInvitationRequest;
use App\Http\Requests\Api\V1\RegisterTenantRequest;
use App\Http\Requests\Api\V1\ResendVerificationRequest;
use App\Http\Requests\Api\V1\VerifyEmailRequest;
use App\Services\PublicRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class RegistrationController extends Controller
{
    public function __construct(private readonly PublicRegistrationService $registrations) {}

    public function store(RegisterTenantRequest $request): JsonResponse
    {
        $result = $this->registrations->register($request->validated(), $request);

        if ($result['verification_token'] === null) {
            $accessToken = Auth::guard('api')->login($result['user']);

            return response()->json([
                'message' => 'Cadastro criado. Você já pode acessar o Fluxo Repasse.',
                'email' => $result['user']->email,
                'requires_email_verification' => false,
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
            ], 201)->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'message' => 'Cadastro criado. Confira seu e-mail para ativar o ambiente.',
            'email' => $result['user']->email,
            'requires_email_verification' => true,
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function verify(VerifyEmailRequest $request): JsonResponse
    {
        $this->registrations->verify($request->validated('token'), $request);

        return response()->json([
            'message' => 'E-mail confirmado. Agora você já pode entrar no Fluxo Repasse.',
        ])->header('Cache-Control', 'no-store');
    }

    public function resend(ResendVerificationRequest $request): JsonResponse
    {
        $this->registrations->resend($request->validated('email'), $request);

        // A resposta é intencionalmente indistinguível para não expor quais
        // endereços já possuem cadastro ou ainda aguardam confirmação.
        return response()->json([
            'message' => 'Se o cadastro estiver aguardando confirmação, enviaremos um novo link.',
        ], 202)->header('Cache-Control', 'no-store');
    }

    public function acceptInvitation(
        AcceptTenantInvitationRequest $request,
        string $token,
    ): JsonResponse {
        $user = $this->registrations->acceptInvitation($token, $request->validated(), $request);
        $accessToken = Auth::guard('api')->login($user);

        return response()->json([
            'message' => 'Convite aceito. Seu acesso foi criado.',
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
        ], 201)->header('Cache-Control', 'no-store');
    }
}
