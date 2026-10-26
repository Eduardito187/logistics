<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Auth\PasswordOtp\PasswordOtpService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Concerns\RestrictsFileUploadsToSchemaComponents;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Reseteo de contraseña por CÓDIGO OTP para el panel admin (DisTrack).
 *
 * El reseteo nativo de Laravel/Filament es por ENLACE firmado; el dueño del proyecto quiere
 * un flujo por CÓDIGO. Para evitar la ruta `password-reset/reset` (que Filament fuerza con
 * middleware `signed`), todo el flujo vive en UNA sola página de dos pasos, enlazada desde el
 * login por `->passwordReset(RequestPasswordOtp::class)`:
 *
 *   Paso 1: el usuario ingresa su email  → PasswordOtpService::issue($email) (envía el código).
 *   Paso 2: ingresa el código + la nueva contraseña → PasswordOtpService::reset(...).
 *
 * Los mensajes al usuario son en inglés, generales y NO revelan si el email existe (anti-enumeración):
 * siempre avanzamos al paso 2 tras el paso 1, exista o no la cuenta.
 *
 * @property-read Schema $form
 */
class RequestPasswordOtp extends SimplePage
{
    use RestrictsFileUploadsToSchemaComponents;
    use WithRateLimiting;

    /**
     * Estado del formulario (email, code, password, passwordConfirmation).
     *
     * @var array<string, mixed> | null
     */
    public ?array $data = [];

    /**
     * Bandera de paso: false = pedir email, true = pedir código + nueva contraseña.
     * No es `#[Locked]` a propósito: aunque el usuario la manipule, `reset()` siempre
     * verifica el código contra el servicio, así que no hay riesgo de saltarse la validación.
     */
    public bool $codeSent = false;

    public function mount(): void
    {
        // Un usuario ya autenticado no tiene por qué resetear desde el login: lo devolvemos al panel.
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }

        $this->form->fill();
    }

    /**
     * Único manejador de envío del formulario; ramifica según el paso actual.
     */
    public function submit(): mixed
    {
        return $this->codeSent
            ? $this->resetPassword()
            : $this->sendCode();
    }

    /**
     * Paso 1: emite y envía el código OTP. Respuesta siempre genérica (anti-enumeración).
     */
    protected function sendCode(): void
    {
        try {
            // Como mucho 2 intentos por minuto para no abusar del envío de códigos.
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        $email = (string) ($data['email'] ?? '');

        // El servicio decide si existe la cuenta y envía el código; aquí nunca revelamos el resultado.
        app(PasswordOtpService::class)->issue($email);

        $this->codeSent = true;

        Notification::make()
            ->title(__('If an account exists for that email, we have sent a reset code to it.'))
            ->body(__('Enter the code below together with your new password. The code expires shortly.'))
            ->success()
            ->send();
    }

    /**
     * Paso 2: verifica el código y asienta la nueva contraseña.
     */
    protected function resetPassword(): void
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        $wasReset = app(PasswordOtpService::class)->reset(
            (string) ($data['email'] ?? ''),
            (string) ($data['code'] ?? ''),
            (string) ($data['password'] ?? ''),
        );

        if (!$wasReset) {
            // Mensaje general: no distinguimos "código inválido" de "email inexistente".
            Notification::make()
                ->title(__('We could not reset your password.'))
                ->body(__('The code is invalid or has expired. Request a new code and try again.'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Your password has been reset.'))
            ->body(__('You can now sign in with your new password.'))
            ->success()
            ->send();

        // Al login a iniciar sesión con la nueva contraseña.
        $this->redirect(Filament::getLoginUrl());
    }

    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        return Notification::make()
            ->title(__('Too many attempts.'))
            ->body(__('Please wait :seconds seconds before trying again.', [
                'seconds' => $exception->secondsUntilAvailable,
            ]))
            ->danger();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getCodeFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
            ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('Email address'))
            ->email()
            ->required()
            ->autocomplete()
            ->autofocus()
            // En el paso 2 el email queda de solo lectura (readOnly, NO disabled) para que
            // siga viajando en el estado del formulario y lo recibamos en reset().
            ->readOnly(fn (): bool => $this->codeSent);
    }

    protected function getCodeFormComponent(): Component
    {
        // TextInput plano (no OneTimeCodeInput): este último impone una regla `digits:N` fija y
        // acoplaría la pantalla a un formato exacto de código. El formato/longitud del OTP lo decide
        // PasswordOtpService; aquí solo lo recogemos y se lo pasamos a reset() para que él lo valide.
        return TextInput::make('code')
            ->label(__('Reset code'))
            ->required()
            ->autocomplete('one-time-code')
            ->visible(fn (): bool => $this->codeSent);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('New password'))
            ->password()
            ->autocomplete('new-password')
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(PasswordRule::default())
            ->same('passwordConfirmation')
            ->visible(fn (): bool => $this->codeSent)
            ->validationAttribute(__('password'));
    }

    protected function getPasswordConfirmationFormComponent(): Component
    {
        return TextInput::make('passwordConfirmation')
            ->label(__('Confirm new password'))
            ->password()
            ->autocomplete('new-password')
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->visible(fn (): bool => $this->codeSent)
            // No se persiste: solo sirve para el `same()` de la contraseña.
            ->dehydrated(false);
    }

    public function loginAction(): Action
    {
        return Action::make('login')
            ->link()
            ->label(__('Back to sign in'))
            ->icon(Heroicon::ArrowLeft)
            ->url(filament()->getLoginUrl());
    }

    public function getTitle(): string | Htmlable
    {
        return __('Reset password');
    }

    public function getHeading(): string | Htmlable | null
    {
        return __('Reset password');
    }

    public function getSubheading(): string | Htmlable | null
    {
        if (!filament()->hasLogin()) {
            return null;
        }

        return $this->loginAction;
    }

    /**
     * @return array<Action | ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSubmitFormAction(),
        ];
    }

    protected function getSubmitFormAction(): Action
    {
        return Action::make('submit')
            ->label($this->codeSent ? __('Reset password') : __('Send reset code'))
            ->submit('submit');
    }

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('submit')
            ->footer([
                Actions::make($this->getFormActions())
                    ->alignment($this->getFormActionsAlignment())
                    ->fullWidth($this->hasFullWidthFormActions())
                    ->key('form-actions'),
            ]);
    }
}
