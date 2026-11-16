<?php

namespace Tests\Feature;

use Tests\Support\AuthTestCase;

class DemoAccountCommandTest extends AuthTestCase
{
    public function test_provisioning_requires_local_environment_and_valid_input(): void
    {
        $this->artisan('demo:user')->expectsOutput('Este comando solo está disponible en local.')->assertFailed();
        $this->app['env'] = 'local';
        $this->artisan('demo:user')->expectsQuestion('Nombre ficticio', 'Persona')->expectsQuestion('Correo ficticio (.test)', 'persona@example.com')->expectsOutput('Nombre o correo ficticio no válido.')->assertFailed();
        $this->artisan('demo:user')->expectsQuestion('Nombre ficticio', 'Persona')->expectsQuestion('Correo ficticio (.test)', 'persona@example.test')->expectsChoice('Rol', 'board', ['treasury', 'board'])->expectsQuestion('Contraseña ficticia', 'long-password-one')->expectsQuestion('Confirma la contraseña', 'different-password')->expectsOutput('La contraseña debe tener al menos 12 caracteres y coincidir.')->assertFailed();
        $this->artisan('demo:user')->expectsQuestion('Nombre ficticio', 'Persona')->expectsQuestion('Correo ficticio (.test)', 'persona@example.test')->expectsChoice('Rol', 'board', ['treasury', 'board'])->expectsQuestion('Contraseña ficticia', 'long-password-one')->expectsQuestion('Confirma la contraseña', 'long-password-one')->expectsOutput('Cuenta ficticia creada.')->assertSuccessful();
        $this->app['env'] = 'testing';
        $this->post('/login', ['email' => 'persona@example.test', 'password' => 'long-password-one'])->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertSee('Persona')->assertSee('Junta');
    }

    public function test_duplicate_provisioning_is_refused_without_changing_original_login(): void
    {
        $this->app['env'] = 'local';
        $this->account('ana@example.test', 'board', 'original-password', 'Ana ficticia');
        $this->artisan('demo:user')
            ->expectsQuestion('Nombre ficticio', 'Otra persona')
            ->expectsQuestion('Correo ficticio (.test)', 'ANA@example.test')
            ->expectsOutput('Ya existe una cuenta con ese correo.')
            ->assertFailed();
        $this->app['env'] = 'testing';
        $this->post('/login', ['email' => 'ana@example.test', 'password' => 'original-password'])
            ->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertSee('Ana ficticia');
    }
}
