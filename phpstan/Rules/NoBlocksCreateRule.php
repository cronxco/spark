<?php

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Rule to prevent usage of deprecated blocks()->create() method
 *
 * @implements Rule<MethodCall>
 */
class NoBlocksCreateRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall) {
            return [];
        }

        // Check if this is a ->create() call
        if (! $node->name instanceof Node\Identifier || $node->name->toString() !== 'create') {
            return [];
        }

        // Check if the previous method call is ->blocks()
        if (! $node->var instanceof MethodCall) {
            return [];
        }

        $previousCall = $node->var;
        if (! $previousCall->name instanceof Node\Identifier || $previousCall->name->toString() !== 'blocks') {
            return [];
        }

        // This is a blocks()->create() call - flag it as deprecated
        return [
            RuleErrorBuilder::message(
                'Using blocks()->create() is deprecated. Use $event->createBlock() instead to prevent duplicate blocks.'
            )
                ->tip('Replace with: $event->createBlock([...]) to ensure unique blocks per event')
                ->identifier('spark.deprecated.blocks-create')
                ->build(),
        ];
    }
}
