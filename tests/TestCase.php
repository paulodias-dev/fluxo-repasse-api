<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Testes legados podem arquivar sem perder a proteção SHA/transação.
     * Não use PATCH status=archived: este atalho foi desabilitado na Macro 54.
     */
    protected function archiveVehicleForQa(string $jwt, string $vehicleUrl): void
    {
        $review = $this->withToken($jwt)->getJson(
            $vehicleUrl.'/closeout-review'
        )->assertOk()->json('data');

        $this->withToken($jwt)->postJson($vehicleUrl.'/complete', [
            'expected_closeout_sha256' => $review['review_sha256'],
            'acknowledge_pending' => true,
        ])->assertOk()->assertJsonPath('data.status', 'archived');
    }
}
