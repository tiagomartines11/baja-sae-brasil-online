<?php

namespace Baja\Certificado;

/**
 * Settings for the public certificate pages.
 */
final class Config
{
    /**
     * Where a participant is told to write when their details are wrong.
     *
     * Provisional: this may become a dedicated LGPD data-subject channel
     * instead. It is a constant, and every template prints it from here, so
     * that decision is a one-line edit rather than a grep.
     */
    public const CONTACT_EMAIL = 'comite@bajasaebrasil.net';

    /**
     * The one message every failure returns.
     *
     * Identical for "no such document", "document found but no row's name
     * matches", and "no such token", on purpose. Any difference between those
     * answers turns the form into an oracle that confirms whether a given
     * person competed, which is itself personal data.
     */
    public const FAILURE_MESSAGE = 'Não foi possível localizar um certificado com os dados informados.';
}
