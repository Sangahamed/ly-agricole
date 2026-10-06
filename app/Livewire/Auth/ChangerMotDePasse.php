<?php

namespace App\Livewire\Auth;

use App\Livewire\Utilisateurs\GestionUtilisateurs;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Chacun change son propre mot de passe, en redonnant l'actuel. Un mot de passe oublié reste
 * l'affaire de l'administrateur (page Utilisateurs).
 */
#[Title('Mon mot de passe')]
class ChangerMotDePasse extends Component
{
    public const TENTATIVES_MAX = 5;

    public string $actuel = '';

    public string $nouveau = '';

    public string $nouveau_confirmation = '';

    public string $statut = '';

    public function changer(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $cle = 'changer-mot-de-passe|'.$user->id;

        if (RateLimiter::tooManyAttempts($cle, self::TENTATIVES_MAX)) {
            throw ValidationException::withMessages([
                'actuel' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($cle)]),
            ]);
        }

        $this->validate([
            'actuel' => ['required', 'string'],
            'nouveau' => ['required', 'string', 'min:'.GestionUtilisateurs::MOT_DE_PASSE_MIN, 'confirmed', 'different:actuel'],
        ], attributes: [
            'actuel' => 'mot de passe actuel',
            'nouveau' => 'nouveau mot de passe',
        ]);

        if (! Hash::check($this->actuel, $user->password)) {
            RateLimiter::hit($cle);
            $this->reset('actuel');

            throw ValidationException::withMessages(['actuel' => 'Le mot de passe actuel est incorrect.']);
        }

        RateLimiter::clear($cle);

        // Nouveau jeton « rester connecté » : les autres navigateurs restés connectés ainsi
        // devront se reconnecter. Journal : par le trait Journalise (mot de passe masqué).
        $user->forceFill([
            'password' => $this->nouveau,
            'remember_token' => Str::random(60),
        ])->save();

        session()->regenerate();

        $this->reset('actuel', 'nouveau', 'nouveau_confirmation');
        $this->statut = 'Mot de passe changé.';
    }

    public function render(): View
    {
        return view('livewire.auth.changer-mot-de-passe');
    }
}
