<?php

namespace App\Console\Commands;

use App\Modules\Access\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateDemoUser extends Command
{
    protected $signature = 'demo:user';

    protected $description = 'Crear una cuenta individual ficticia en local';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Este comando solo está disponible en local.');

            return self::FAILURE;
        }
        $name = trim((string) $this->ask('Nombre ficticio'));
        $email = Str::lower(trim((string) $this->ask('Correo ficticio (.test)')));
        if (Validator::make(['name' => $name, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'regex:/\.test$/i'],
        ])->fails()) {
            $this->error('Nombre o correo ficticio no válido.');

            return self::FAILURE;
        }
        if (User::where('email', $email)->exists()) {
            $this->error('Ya existe una cuenta con ese correo.');

            return self::FAILURE;
        }
        $role = $this->choice('Rol', ['treasury', 'board']);
        $input = $this->secret('Contraseña ficticia');
        $confirmation = $this->secret('Confirma la contraseña');
        if (! is_string($input) || mb_strlen($input) < 12 || $input !== $confirmation) {
            $this->error('La contraseña debe tener al menos 12 caracteres y coincidir.');

            return self::FAILURE;
        }
        User::provision($name, $email, $input, $role);
        $this->info('Cuenta ficticia creada.');

        return self::SUCCESS;
    }
}
