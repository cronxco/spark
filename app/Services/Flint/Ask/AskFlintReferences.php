<?php

namespace App\Services\Flint\Ask;

use App\Models\User;
use App\Services\Api\EntityMutationService;

/**
 * The records an answer links to.
 *
 * The agent links a record as `[title](/events/<id>)`. Each link is checked
 * against the user's own data: a link to a record that exists becomes a
 * reference the app can open, and one that does not (a mistyped or invented
 * id) loses its link and keeps its words.
 */
class AskFlintReferences
{
    private const LINK = '~\[([^\]\n]{1,200})\]\(/(events|objects|blocks)/([0-9a-fA-F-]{36})\)~';

    private const KINDS = ['events' => 'event', 'objects' => 'object', 'blocks' => 'block'];

    public function __construct(private EntityMutationService $entities) {}

    /**
     * @return array{text: string, references: array<int, array{kind: string, id: string, title: string}>}
     */
    public function resolve(User $user, string $text): array
    {
        $references = [];

        $text = (string) preg_replace_callback(self::LINK, function (array $match) use ($user, &$references): string {
            $kind = self::KINDS[$match[2]];
            $id = strtolower($match[3]);
            $key = "{$kind}:{$id}";

            if (! array_key_exists($key, $references)) {
                $entity = $this->entities->find($user, $kind, $id);
                $references[$key] = $entity === null ? null : [
                    'kind' => $kind,
                    'id' => $id,
                    'title' => AskFlintChangeLog::label($entity),
                ];
            }

            return $references[$key] === null ? $match[1] : "[{$match[1]}](/{$match[2]}/{$id})";
        }, $text);

        return ['text' => $text, 'references' => array_values(array_filter($references))];
    }
}
