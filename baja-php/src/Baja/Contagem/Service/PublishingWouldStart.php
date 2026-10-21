<?php
declare(strict_types=1);

namespace Baja\Contagem\Service;

/**
 * Thrown when a change of settings would start writing this race's counts
 * into the official results and the person has not confirmed that yet.
 *
 * Starting to publish replaces what is there (a spreadsheet's totals, say)
 * with the count as it stands, so it is never done as a side effect of
 * something else. The page catches this, asks, and repeats the request with
 * the confirmation.
 */
final class PublishingWouldStart extends \DomainException
{
}
