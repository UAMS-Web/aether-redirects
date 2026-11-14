<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Services;

use Illuminate\Validation\ValidationException;
use UAMSWeb\Aether\Redirects\Support\LookupOption;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlLookup;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlParameter;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest;

/**
 * Checks the choices a submission carries against what the host's cascade offers
 * (UAMS-Web/aether-redirects#26), and builds the request they make.
 *
 * Nothing the form said is trusted: a chosen option must be one the cascade offers at that
 * step given the earlier choices, a list choice must belong to a list a parameter reads, a
 * custom value is bounded text, and a field no parameter reads is refused rather than
 * dropped, so a submission that meant to set it is not saved without it.
 */
final readonly class TaggedUrlChoiceValidator
{
    /**
     * The longest a request field or a custom value may be.
     */
    public const int MAX_LENGTH = 255;

    /**
     * @param  TaggedUrlLookupReader  $reader  Reads the host's lists, cascade and parameters
     */
    public function __construct(private TaggedUrlLookupReader $reader) {}

    /**
     * Validate a submission's choices and build the request.
     *
     * @param  string  $destination  The URL the tag is added to
     * @param  bool  $suppressUtm  Whether the submission leaves every `utm_*` parameter out
     * @param  array<string, mixed>  $input  The submission: `channel`, `steps`, `lists`, `custom_values`, and each request field by handle
     * @return TaggedUrlRequest The request, holding only values that passed
     *
     * @throws ValidationException When a choice or a field is not allowed, naming each.
     */
    public function validate(string $destination, bool $suppressUtm, array $input): TaggedUrlRequest
    {
        $composer = $this->reader->composer();
        $parameters = $this->reader->parameterList();

        // The request fields and lists the site's parameters read
        $readFields = [];
        $readLists = [];

        foreach ($parameters as $parameter) {
            if ($parameter->from === TaggedUrlParameter::FROM_REQUEST_FIELD) {
                $readFields[] = $parameter->source;
            } elseif ($parameter->from === TaggedUrlParameter::FROM_LIST) {
                $readLists[] = $parameter->source;
            }
        }

        $errors = [];

        // Request fields: bounded text, by the handles a parameter reads
        $fields = [];

        foreach (array_unique($readFields) as $handle) {
            $value = $input[$handle] ?? null;

            if ($value === null) {
                continue;
            }

            $text = $this->text($value, $handle, $errors);

            if ($text !== null && $text !== '') {
                $fields[$handle] = $text;
            }
        }

        // A field the form once carried that no parameter reads now
        foreach (TaggedUrlRequestStore::requestFields() as $handle) {
            if (! \in_array($handle, $readFields, true) && $this->isFilled($input[$handle] ?? null)) {
                $errors[$handle] = [__('No tagged URL parameter reads this field.')];
            }
        }

        // The channel
        $channel = $input['channel'] ?? null;
        $channelId = null;

        if ($this->isFilled($channel)) {
            if (! \is_string($channel) || ! \in_array($channel, $this->ids($composer->channelOptions()), true)) {
                $errors['channel'] = [__('The channel is not one this site offers.')];
            } else {
                $channelId = trim($channel);
            }
        }

        // The option at each step, in cascade order, against the list the earlier choices lead to
        $steps = [];
        $chosen = $this->map($input['steps'] ?? null, 'steps', $errors);

        foreach (array_diff(array_keys($chosen), TaggedUrlLookup::steps()) as $unknown) {
            $errors['steps.'.$unknown] = [__('This is not a step of the cascade.')];
        }

        foreach (TaggedUrlLookup::steps() as $step) {
            if (! isset($chosen[$step]) || ! $this->isFilled($chosen[$step])) {
                continue;
            }

            $offered = $composer->optionsFor($step, new TaggedUrlRequest($destination, $channelId, $steps));

            if (! \is_string($chosen[$step]) || ! \in_array($chosen[$step], $this->ids($offered), true)) {
                $errors['steps.'.$step] = [__('This choice is not offered at this step.')];
            } else {
                $steps[$step] = $chosen[$step];
            }
        }

        // The option from each list a parameter reads
        $lists = [];
        $listChoices = $this->map($input['lists'] ?? null, 'lists', $errors);
        $catalog = $this->reader->catalog();

        foreach ($listChoices as $listId => $optionId) {
            if (! $this->isFilled($optionId)) {
                continue;
            }

            if (! \in_array($listId, $readLists, true)) {
                $errors['lists.'.$listId] = [__('No tagged URL parameter reads this list.')];
            } elseif (! \is_string($optionId) || ! \in_array($optionId, $this->ids($catalog->options($listId)), true)) {
                $errors['lists.'.$listId] = [__('This choice is not in the list.')];
            } else {
                $lists[$listId] = $optionId;
            }
        }

        // Custom values sit beside a step or list choice
        $custom = [];

        foreach ($this->map($input['custom_values'] ?? null, 'custom_values', $errors) as $key => $value) {
            if (! \in_array($key, [...TaggedUrlLookup::steps(), ...$readLists], true)) {
                $errors['custom_values.'.$key] = [__('No tagged URL parameter reads this value.')];

                continue;
            }

            $text = $this->text($value, 'custom_values.'.$key, $errors);

            if ($text !== null && $text !== '') {
                $custom[$key] = $text;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new TaggedUrlRequest($destination, $channelId, $steps, $lists, $custom, $fields, $suppressUtm);
    }

    /**
     * A bounded, trimmed text value, recording an error when it is not text or is too long.
     *
     * @param  mixed  $value  The submitted value
     * @param  string  $name  The name the error is filed under
     * @param  array<string, list<string>>  $errors  The errors so far
     * @return string|null The trimmed text, or null when the value is not usable
     */
    private function text(mixed $value, string $name, array &$errors): ?string
    {
        if (! \is_string($value)) {
            $errors[$name] = [__('This must be text.')];

            return null;
        }

        $value = trim($value);

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $errors[$name] = [__('This may not be longer than :max characters.', ['max' => self::MAX_LENGTH])];

            return null;
        }

        return $value;
    }

    /**
     * A submitted map, or an empty one when none was submitted.
     *
     * @param  mixed  $value  The submitted value
     * @param  string  $name  The name the error is filed under
     * @param  array<string, list<string>>  $errors  The errors so far
     * @return array<string, mixed> The map
     */
    private function map(mixed $value, string $name, array &$errors): array
    {
        if ($value === null) {
            return [];
        }

        if (! \is_array($value)) {
            $errors[$name] = [__('This must be a list of choices.')];

            return [];
        }

        $map = [];

        foreach ($value as $key => $entry) {
            $map[(string) $key] = $entry;
        }

        return $map;
    }

    /**
     * Whether a submitted value holds anything, so that a blank choice means no choice.
     *
     * @param  mixed  $value  The submitted value
     */
    private function isFilled(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [] && (! \is_string($value) || trim($value) !== '');
    }

    /**
     * The ids of options.
     *
     * @param  list<LookupOption>  $options  The options
     * @return list<string> Their ids
     */
    private function ids(array $options): array
    {
        return array_map(static fn (LookupOption $option): string => $option->id, $options);
    }
}
