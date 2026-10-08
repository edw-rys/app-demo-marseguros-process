<?php

namespace App\Services\Gmail;

/**
 * De dónde sale la credencial con la que se habla con Gmail.
 *
 * Son dos mundos que no se mezclan:
 *
 *   ServiceAccount → Google Workspace + domain-wide delegation. El admin del
 *                    dominio autoriza el acceso y desde ahí se leen N buzones
 *                    "como" un mismo usuario. Requiere ser super-admin.
 *
 *   Oauth          → una cuenta de Google authorizes su propio acceso. Funciona
 *                    en cualquier cuenta, `@gmail.com` incluida, pero cubre UN
 *                    buzón y solo lectura.
 *
 * El modo es explícito en el `.env` y no se deduce de qué archivos haya en
 * `storage/private/`: un `service-account.json` viejo en el disco cambiaría el
 * comportamiento de un despliegue ya en OAuth sin que nadie lo pidiera.
 */
enum GmailAuthMode: string
{
    case ServiceAccount = 'service_account';

    case Oauth = 'oauth';

    /**
     * El modo configurado. Cae a `ServiceAccount` porque es el que existía
     * antes de que existiera este enum: un valor ausente o mal escrito deja
     * las cosas como estaban, que es lo que menos sorprende a quien ya lo
     * tenía funcionando.
     */
    public static function current(): self
    {
        return self::tryFrom((string) config('gmail_docs.google.auth_mode'))
            ?? self::ServiceAccount;
    }

    public function label(): string
    {
        return match ($this) {
            self::ServiceAccount => 'Service account + DWD (Google Workspace)',
            self::Oauth => 'OAuth 2.0 (cuenta propia)',
        };
    }

    /** En OAuth no hay buzones en el `.env`: hay una cuenta que autorizar. */
    public function authorizesAMailbox(): bool
    {
        return $this === self::Oauth;
    }
}