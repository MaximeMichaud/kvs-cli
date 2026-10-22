<?php

namespace KVS\CLI\Config;

use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

final class PhpConfigParser
{
    /**
     * Read syntactic define() calls without executing the configuration.
     * Recoverable syntax errors retain complete nodes so config edit can still repair the file.
     *
     * @return list<array{name: Node\Scalar\String_, value: Node\Expr}>
     */
    public static function getDefines(string $content): array
    {
        $statements = (new ParserFactory())->createForHostVersion()->parse($content, new Collecting()) ?? [];
        $calls = (new NodeFinder())->findInstanceOf($statements, Node\Expr\FuncCall::class);
        $defines = [];
        foreach ($calls as $call) {
            if (
                !$call->name instanceof Node\Name || $call->name->toString() !== 'define'
                || $call->name->isRelative() || count($call->args) !== 2
            ) {
                continue;
            }
            [$name, $value] = $call->args;
            if (
                !$name instanceof Node\Arg || !$value instanceof Node\Arg
                || $name->name !== null || $value->name !== null || $name->unpack || $value->unpack
                || !$name->value instanceof Node\Scalar\String_
                || self::getQuotedString($name->value) === null
            ) {
                continue;
            }
            $defines[] = ['name' => $name->value, 'value' => $value->value];
        }
        return $defines;
    }

    public static function getQuotedString(Node\Expr $expression): ?string
    {
        if (
            $expression instanceof Node\Scalar\String_
            && in_array($expression->getAttribute('kind'), [
                Node\Scalar\String_::KIND_SINGLE_QUOTED,
                Node\Scalar\String_::KIND_DOUBLE_QUOTED,
            ], true)
        ) {
            return $expression->value;
        }
        return null;
    }
}
