<?php

namespace Tests\Feature;

use Tests\TestCase;

final class WelcomePageTest extends TestCase
{
    public function test_welcome_page_is_available(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertViewIs('welcome')
            ->assertSee('Fluxo Repasse')
            ->assertSee('Verificar API');
    }
}
