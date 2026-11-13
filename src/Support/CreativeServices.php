<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Support;

use LogicException;

/**
 * The Creative Services question every redirect request answers, and the job
 * number a "yes" answer must carry (UAMS-Web/aether-redirects#25).
 *
 * One check serves every request form. A vanity URL request must involve
 * Creative Services; a tagged-only request needs a job number only when its
 * answer is yes. Both read the same setting, so the format is changed in one place.
 *
 * The format is the `creative_services.job_number.pattern` setting, a regular
 * expression with delimiters, and the words that describe it to the requester
 * are the `creative_services.job_number.description` setting. With no pattern
 * set, any non-blank job number is accepted and the approver confirms it.
 */
final readonly class CreativeServices
{
    /**
     * The answer for a request that involves Creative Services.
     */
    public const string YES = 'yes';

    /**
     * The answer for a request that does not.
     */
    public const string NO = 'no';

    /**
     * The longest job number accepted, so a stored value stays a short reference.
     */
    // PCOV attributes no coverage to a constant's initializer, so Pest selects no test for its mutants and
    // reports them uncovered. A changed value is observable, and the length test in `MarketingRedirectCreativeServicesTest` pins it.
    // @pest-mutate-ignore:IncrementInteger,DecrementInteger
    public const int JOB_NUMBER_MAX_LENGTH = 100;

    /**
     * The setting that holds the job number format.
     */
    private const string PATTERN_KEY = 'aether-redirects.creative_services.job_number.pattern';

    /**
     * The setting that holds the human description of the format.
     */
    private const string DESCRIPTION_KEY = 'aether-redirects.creative_services.job_number.description';

    /**
     * The answers a request may give.
     *
     * @return list<string>
     */
    public static function answers(): array
    {
        return [self::YES, self::NO];
    }

    /**
     * Whether an answer is a "yes".
     *
     * @param  string|null  $answer  The submitted answer.
     */
    public static function involved(?string $answer): bool
    {
        return $answer === self::YES;
    }

    /**
     * The words that describe the job number format, for the form and for a refusal.
     *
     * With no description set, the description is a generic one, so a host that
     * sets a pattern and no description still shows the requester a sentence.
     */
    public static function description(): string
    {
        $description = config(self::DESCRIPTION_KEY);

        return \is_string($description) && trim($description) !== '' ? trim($description) : 'the Creative Services job number';
    }

    /**
     * The message a job number is refused with, or null when it is acceptable.
     *
     * @param  string|null  $jobNumber  The submitted job number.
     * @return string|null Why it was refused, or null when it passes.
     *
     * @throws LogicException When the configured pattern is not a valid regular expression.
     */
    public static function jobNumberError(?string $jobNumber): ?string
    {
        $number = trim($jobNumber ?? '');

        if ($number === '') {
            return __('Enter the Creative Services job number.');
        }

        $pattern = config(self::PATTERN_KEY);

        if (! \is_string($pattern) || $pattern === '') {
            return null;
        }

        $matched = @preg_match($pattern, $number);

        if ($matched === false) {
            throw new LogicException(sprintf('The "%s" setting is not a valid regular expression.', self::PATTERN_KEY));
        }

        if ($matched === 1) {
            return null;
        }

        return __('The job number must match :format.', ['format' => self::description()]);
    }
}
