<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\Redirects\Http\Controllers\CP;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Statamic\Http\Controllers\CP\CpController;
use UAMSWeb\Aether\Redirects\Services\TaggedUrlLookupReader;
use UAMSWeb\Aether\Redirects\Support\LookupOption;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlComposer;
use UAMSWeb\Aether\Redirects\Support\TaggedUrlRequest;

/**
 * Serves the options each step of the tagged URL cascade offers, so the request form
 * can narrow every list by the choices already made (UAMS-Web/aether-redirects#118).
 *
 * The cascade rules stay in {@see TaggedUrlComposer}; this only reads them out. It is
 * read-only and adds no data of its own: a host with no lookup data answers an empty
 * cascade.
 */
final class TaggedUrlCascadeController extends CpController
{
    /**
     * The cascade for the choices in the query.
     *
     * The query carries `channel`, `steps[<step>]` and `lists[<list id>]`, each an
     * option id; anything else, and any id that no longer exists (an empty one included), is ignored.
     */
    public function __invoke(Request $request, TaggedUrlLookupReader $reader): JsonResponse
    {
        // The same people who may request a tagged URL
        abort_unless(Gate::any([
            MarketingRedirectController::TAGGED_PERMISSION,
            MarketingRedirectController::VANITY_PERMISSION,
            MarketingRedirectController::CUSTOM_VANITY_PERMISSION,
            MarketingRedirectController::PERMISSION,
        ]), 403);

        $composer = $reader->composer();
        // The cascade never reads the destination, so no test can tell one value from another
        // @pest-mutate-ignore:EmptyStringToNotEmpty
        $choices = new TaggedUrlRequest(
            destination: '',
            channel: self::text($request->query('channel')),
            steps: self::texts($request->query('steps')),
            lists: self::texts($request->query('lists')),
        );

        // Each step an enabled parameter reads, offered the list the earlier choices name
        $steps = [];
        foreach ($composer->stepsInUse() as $step) {
            $steps[] = ['step' => $step, 'options' => self::options($composer->optionsFor($step, $choices))];
        }

        // Each list-sourced parameter, with its own list's options
        $lists = [];
        foreach ($composer->listParameters() as $parameter) {
            $lists[] = ['parameter' => $parameter->name, 'list' => $parameter->source, 'options' => self::options($composer->optionsOfList($parameter->source))];
        }

        return response()->json([
            'channel' => self::options($composer->channelOptions()),
            'steps' => $steps,
            'lists' => $lists,
            'fields' => $composer->requestFields(),
        ]);
    }

    /**
     * The options as the form shows them.
     *
     * @param  list<LookupOption>  $options  The options
     * @return list<array{id: string, label: string}> Each option's id and label
     */
    private static function options(array $options): array
    {
        return array_map(static fn (LookupOption $option): array => ['id' => $option->id, 'label' => $option->label], $options);
    }

    /**
     * A query value that is text, or null.
     *
     * @param  mixed  $value  The query value
     * @return string|null The text
     */
    private static function text(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /**
     * The text values of a query array, by key.
     *
     * @param  mixed  $value  The query value
     * @return array<string, string> The entries whose key and value are text
     */
    private static function texts(mixed $value): array
    {
        $texts = [];
        foreach (\is_array($value) ? $value : [] as $key => $entry) {
            if (\is_string($entry)) {
                // PHP turns a numeric string key into an integer key whichever way it is written, so the cast changes nothing at run time
                // @pest-mutate-ignore:RemoveStringCast
                $texts[(string) $key] = $entry;
            }
        }

        return $texts;
    }
}
