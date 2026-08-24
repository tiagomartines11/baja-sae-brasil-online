<?php

namespace Baja\Certificado;

/**
 * Settings for the public certificate pages.
 */
final class Config
{
    /*
     * There is deliberately no CONTACT_EMAIL here any more.
     *
     * These pages used to print comite@bajasaebrasil.net as the way to report
     * a wrong certificate. /requerimento replaced it, and the address was removed
     * rather than kept beside it: two channels means two queues, and the
     * unstructured one — no protocol number, no status, no record of who
     * answered, no retention policy, and a CPF sitting in a mailbox forever —
     * is the one people pick, because it asks nothing of them.
     *
     * What replaces it as the reply-able end of the conversation is the
     * confirmation /requerimento sends, so SMTP_FROM_EMAIL has to be a mailbox
     * somebody reads. See Baja\Certificado\Requerimento\Aviso.
     */

    /**
     * Where the Aviso de Privacidade lives.
     *
     * Empty until there is one to link to — the repository contains no privacy
     * notice today, and writing one is not a code change. The pages link it
     * when this is set and say nothing when it is not, rather than shipping a
     * link to a 404. Filling this in is a one-line edit.
     */
    public const PRIVACY_NOTICE_URL = '';

    /**
     * The one message every failure returns.
     *
     * Identical for "no such document", "document found but no row's name
     * matches", and "no such token", on purpose. Any difference between those
     * answers turns the form into an oracle that confirms whether a given
     * person competed, which is itself personal data.
     */
    public const FAILURE_MESSAGE = 'Não foi possível localizar um certificado com os dados informados.';

    /**
     * Shown once a document has been tried and failed too many times.
     *
     * A third state rather than a variation on the message above, and it does
     * not reopen the oracle that one exists to close: failures are counted for
     * any value submitted, so an invented CPF reaches this state after five
     * attempts exactly as a real one does. It reports the caller's own recent
     * history, which they already know.
     *
     * Saying nothing was worse than a small disclosure here. Somebody who
     * mistypes their name a few times, works out the right spelling and is
     * then told their certificate does not exist has been given a false
     * answer, with no way to tell that waiting would fix it.
     */
    public const THROTTLED_MESSAGE = 'Muitas tentativas com este documento. Aguarde %s e tente novamente.';
}
