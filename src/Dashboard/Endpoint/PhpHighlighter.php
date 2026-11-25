<?php

declare(strict_types=1);

namespace Tollbooth\Dashboard\Endpoint;

/**
 * @internal
 */
final class PhpHighlighter
{
    private const array KEYWORDS = [
        \T_ABSTRACT, \T_ARRAY, \T_AS, \T_CLASS, \T_CONST, \T_ECHO, \T_ELSE, \T_ELSEIF, \T_ENUM, \T_EXTENDS, \T_FINAL, \T_FN,
        \T_FOREACH, \T_FOR, \T_FUNCTION, \T_IF, \T_IMPLEMENTS, \T_INSTANCEOF, \T_INTERFACE, \T_MATCH, \T_NEW, \T_PRIVATE,
        \T_PROTECTED, \T_PUBLIC, \T_READONLY, \T_RETURN, \T_STATIC, \T_THROW, \T_TRAIT, \T_TRY, \T_CATCH, \T_USE, \T_YIELD,
    ];

    public static function highlight(string $code): string
    {
        $html = '';
        $attribute = false;
        $depth = 0;
        $tokens = token_get_all('<?php '.$code);
        array_shift($tokens);

        foreach ($tokens as $token) {
            if (\is_string($token)) {
                if ($attribute && '[' === $token) {
                    ++$depth;
                } elseif ($attribute && ']' === $token && $depth > 0) {
                    --$depth;
                } elseif ($attribute && ']' === $token) {
                    $attribute = false;
                    $html .= '<span class="at">]</span>';
                    continue;
                }
                $html .= self::escape($token);
                continue;
            }

            [$id, $text] = $token;
            $class = match (true) {
                \T_ATTRIBUTE === $id => 'at',
                \in_array($id, self::KEYWORDS, true) => 'kw',
                \T_CONSTANT_ENCAPSED_STRING === $id, \T_ENCAPSED_AND_WHITESPACE === $id => 's',
                \T_LNUMBER === $id, \T_DNUMBER === $id => 'nu',
                \T_COMMENT === $id, \T_DOC_COMMENT === $id => 'mu',
                \T_STRING === $id && $attribute && '' !== $html && str_ends_with($html, '#[</span>') => 'at',
                \in_array($id, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true) && ctype_upper($text[0] ?? '') => 'ty',
                default => null,
            };
            if (\T_ATTRIBUTE === $id) {
                $attribute = true;
            }
            $html .= null === $class ? self::escape($text) : '<span class="'.$class.'">'.self::escape($text).'</span>';
        }

        return $html;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}
