<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Livewire\Utilisateurs\GestionUtilisateurs;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Ouvre un compte depuis le serveur : le premier administrateur d'une base neuve (production),
 * puis, si besoin, un compte de direction. Les autres comptes s'ouvrent dans l'application
 * (Utilisateurs). Le mot de passe se tape en saisie masquée : il ne passe ni dans
 * l'historique du shell ni dans une option.
 */
class CreerCompte extends Command
{
    protected $signature = 'ly:creer-compte
        {--role=admin : admin ou direction}
        {--nom= : nom affiché}
        {--email= : adresse e-mail de connexion}';

    protected $description = 'Crée un compte administrateur ou direction (mot de passe en saisie masquée).';

    public function handle(): int
    {
        $role = Role::tryFrom((string) $this->option('role'));
        if (! in_array($role, [Role::Admin, Role::Direction], true)) {
            $this->error('Rôle refusé : admin ou direction seulement. Les autres comptes s\'ouvrent dans l\'application.');

            return self::FAILURE;
        }

        $donnees = [
            'nom' => $this->option('nom') ?: $this->ask('Nom'),
            'email' => $this->option('email') ?: $this->ask('Adresse e-mail'),
            'motDePasse' => $this->secret('Mot de passe (au moins '.GestionUtilisateurs::MOT_DE_PASSE_MIN.' caractères)'),
            'confirmation' => $this->secret('Mot de passe, encore une fois'),
        ];

        $validation = Validator::make($donnees, [
            'nom' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'motDePasse' => ['required', 'string', 'min:'.GestionUtilisateurs::MOT_DE_PASSE_MIN, 'same:confirmation'],
        ], attributes: ['nom' => 'nom', 'email' => 'adresse e-mail', 'motDePasse' => 'mot de passe', 'confirmation' => 'confirmation']);

        if ($validation->fails()) {
            foreach ($validation->errors()->all() as $erreur) {
                $this->error($erreur);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'nom' => $donnees['nom'],
            'email' => $donnees['email'],
            'role' => $role,
            'password' => $donnees['motDePasse'],
            'actif' => true,
        ]);

        $this->info("Compte {$role->libelle()} créé : {$user->email}.");

        return self::SUCCESS;
    }
}
