<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Services\Gmail\GmailAuthMode;
use App\Services\Gmail\OAuthClientFactory;
use App\Services\Gmail\OAuthTokenStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Estado de la conexión con Gmail y, en modo OAuth, el botón para autorizarla.
 *
 * Va en el grupo Watchers, al lado del listado de buzones vigilados, porque es
 * la misma pregunta que hace ese listado: ¿de dónde está entrando el correo?
 *
 * En modo `service_account` la pantalla dice que la credencial es del dominio y
 * NO ofrece el botón: para quien tiene Google Workspace montado, esta pantalla
 * sería ruido.
 */
class GmailConnection extends Page
{
    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Watchers;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-key';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Conexión con Gmail';

    protected static ?string $navigationLabel = 'Conexión con Gmail';

    protected string $view = 'filament.pages.gmail-connection';

    public function getSubheading(): ?string
    {
        return GmailAuthMode::current()->label();
    }

    /**
     * El botón solo aparece en modo OAuth. En `service_account` no hay nada que
     * autorizar: la credencial ya tiene permisos sobre el dominio.
     *
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        if (! GmailAuthMode::current()->authorizesAMailbox()) {
            return [];
        }

        return [
            Action::make('authorize')
                ->label('Autorizar con Google')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('primary')
                ->url(route('gmail.oauth.redirect')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $tokens = app(OAuthTokenStore::class);
        $expiresAt = $tokens->expiresAt();
        $authorizedAt = $tokens->authorizedAt();

        $days = $authorizedAt === null
            ? null
            : (int) floor((time() - $authorizedAt) / 86400);

        return [
            'oauthMode'        => GmailAuthMode::current()->authorizesAMailbox(),
            'delegatedUser'    => config('gmail_docs.google.delegated_user'),
            'mailboxes'        => (array) config('gmail_docs.google.mailboxes'),
            'mailbox'          => $tokens->mailbox(),
            'scopes'           => $tokens->read()['scopes'] ?? [],
            'expiresAt'        => $expiresAt === null
                ? null
                : Carbon::createFromTimestamp($expiresAt)->toDateTimeString(),
            'authorizedDaysAgo' => $days,
            'redirectUri'      => app(OAuthClientFactory::class)->redirectUri(),
        ];
    }
}