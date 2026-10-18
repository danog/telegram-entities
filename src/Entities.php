<?php declare(strict_types=1);

/**
 * Copyright 2024 Daniil Gentili.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2024 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/license/apache-2-0 Apache 2.0
 * @link https://github.com/danog/telegram-entities TelegramEntities documentation
 */

namespace danog\TelegramEntities;

use AssertionError;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Class that represents a message + set of Telegram entities.
 *
 * Entity types and fields follow the Bot API MessageEntity type.
 *
 * @api
 *
 * @psalm-type TEntity=(
 *      array{
 *          type: "bold"|"italic"|"code"|"strikethrough"|"underline"|"blockquote"|"expandable_blockquote"|"url"|"email"|"phone_number"|"spoiler"|"mention"|"hashtag"|"cashtag"|"bot_command"|"bank_card_number",
 *          offset: int<0, max>,
 *          length: int<0, max>
 *      }
 *      |array{type: "text_mention", user: array{id: int, ...}, offset: int, length: int}
 *      |array{type: "custom_emoji", custom_emoji_id: int, offset: int, length: int}
 *      |array{type: "pre", language?: string, offset: int, length: int}
 *      |array{type: "text_link", url: string, offset: int, length: int}
 *      |array{type: "date_time", unix_time: int, date_time_format: string, offset: int, length: int}
 * )
 */
final class Entities
{
    /**
     * Creates an Entities container using a message and a list of entities.
     *
     * @psalm-mutation-free
     */
    public function __construct(
        /** Converted message */
        public string $message,
        /**
         * Converted entities.
         *
         * @var list<TEntity>
         */
        public array $entities,
    ) {
    }

    /**
     * Manually convert markdown to a message and a set of entities.
     *
     * Supports the MarkdownV2 syntax of the Bot API, including block quotations (lines starting with `>`),
     * expandable block quotations (a block quotation whose last line ends with `||`),
     * custom emojis (`![👍](tg://emoji?id=5368324170671202286)`) and
     * date-times (`![22:45 tomorrow](tg://time?unix=1647531900&format=wDT)`).
     *
     * @return Entities Object containing message and entities
     *
     * @psalm-pure
     */
    public static function fromMarkdown(string $markdown): self
    {
        $markdown = \trim(\str_replace("\r\n", "\n", $markdown));
        $message = '';
        $messageLen = 0;
        $entities = [];
        $offset = 0;
        /** @var list<array{0: string, 1: int}> */
        $stack = [];
        // Whether a > at the current position starts (or continues) a block quotation:
        // only markup characters may precede it on its line.
        $canStartQuote = true;
        while ($offset < \strlen($markdown)) {
            $len = \strcspn($markdown, "*_~`[]|!\\>\n", $offset);
            $piece = \substr($markdown, $offset, $len);
            $offset += $len;
            if ($piece !== '') {
                $canStartQuote = false;
            }
            if ($offset === \strlen($markdown)) {
                $message .= $piece;
                $messageLen += EntityTools::mbStrlen($piece);
                break;
            }

            $char = $markdown[$offset++];
            $next = $markdown[$offset] ?? '';
            if ($char === "\n") {
                $message .= $piece;
                $messageLen += EntityTools::mbStrlen($piece);
                if ($stack && $next !== '>' && self::hasQuote($stack)) {
                    [$stack, $quote] = self::closeQuote($stack, $message, $messageLen, \substr($markdown, $offset-3, 2) === '||');
                    if ($quote !== null) {
                        $entities []= $quote;
                    }
                }
                $message .= "\n";
                $messageLen++;
                $canStartQuote = true;
                continue;
            }
            if ($char === '>') {
                $message .= $piece;
                $messageLen += EntityTools::mbStrlen($piece);
                if (!$canStartQuote) {
                    $message .= $char;
                    $messageLen++;
                } elseif (!self::hasQuote($stack)) {
                    $stack []= ['>', $messageLen];
                }
                continue;
            }
            if ($char === '\\') {
                $message .= $piece.$next;
                $messageLen += EntityTools::mbStrlen($piece.$next);
                $offset += \strlen($next);
                $canStartQuote = false;
                continue;
            }

            if ($char === '_' && $next === '_') {
                $offset++;
                $char = '__';
            } elseif ($char === '|') {
                if ($next === '|') {
                    $offset++;
                    $char = '||';
                } else {
                    $message .= $piece.$char;
                    $messageLen += EntityTools::mbStrlen($piece)+1;
                    $canStartQuote = false;
                    continue;
                }
            } elseif ($char === '!') {
                if ($next === '[') {
                    $offset++;
                    $char = '](';
                } else {
                    $message .= $piece.$char;
                    $messageLen += EntityTools::mbStrlen($piece)+1;
                    $canStartQuote = false;
                    continue;
                }
            } elseif ($char === '[') {
                $char = '](';
            } elseif ($char === ']') {
                if (!$stack || \end($stack)[0] !== '](') {
                    $message .= $piece.$char;
                    $messageLen += EntityTools::mbStrlen($piece)+1;
                    $canStartQuote = false;
                    continue;
                }
                if ($next !== '(') {
                    \array_pop($stack);
                    $message .= '['.$piece.$char;
                    $messageLen += EntityTools::mbStrlen($piece)+2;
                    $canStartQuote = false;
                    continue;
                }
                $offset++;
                $char = "](";
            } elseif ($char === '`') {
                $message .= $piece;
                $messageLen += EntityTools::mbStrlen($piece);
                $canStartQuote = false;

                $token = '`';
                $language = null;
                if ($next === '`' && ($markdown[$offset+1] ?? '') === '`') {
                    $token = '```';

                    $offset += 2;
                    $langLen = \strcspn($markdown, "\n ", $offset);
                    $language = \substr($markdown, $offset, $langLen);
                    $offset += $langLen;
                    if (($markdown[$offset] ?? '') === "\n") {
                        $offset++;
                    }
                }

                $piece = '';
                $posClose = $offset;
                while (($posClose = \strpos($markdown, $token, $posClose)) !== false) {
                    if ($markdown[$posClose-1] === '\\') {
                        $piece .= \substr($markdown, $offset, ($posClose-$offset)-1).$token;
                        $posClose += \strlen($token);
                        $offset = $posClose;
                        continue;
                    }
                    break;
                }
                /** @var int|false $posClose */
                if ($posClose === false) {
                    throw new AssertionError("Unclosed ``` opened @ pos $offset!");
                }
                $piece .= \substr($markdown, $offset, $posClose-$offset);

                $start = $messageLen;

                $message .= $piece;
                $pieceLen = EntityTools::mbStrlen($piece);
                $messageLen += $pieceLen;

                for ($x = \strlen($piece)-1; $x >= 0; $x--) {
                    if (!(
                        $piece[$x] === ' '
                        || $piece[$x] === "\r"
                        || $piece[$x] === "\n"
                    )) {
                        break;
                    }
                    $pieceLen--;
                }
                if ($pieceLen > 0) {
                    \assert($start >= 0);
                    $tmp = [
                        'type' => match ($token) {
                            '```' => 'pre',
                            '`' => 'code',
                        },
                        'offset' => $start,
                        'length' => $pieceLen,
                    ];
                    if ($language !== null) {
                        $tmp['language'] = $language;
                    }
                    $entities []= $tmp;
                    unset($tmp);
                }

                $offset = $posClose+\strlen($token);
                continue;
            }

            if ($stack && \end($stack)[0] === $char) {
                [, $start] = \array_pop($stack);
                if ($char === '](') {
                    $posClose = $offset;
                    $link = '';
                    while (($posClose = \strpos($markdown, ')', $posClose)) !== false) {
                        if ($markdown[$posClose-1] === '\\') {
                            $link .= \substr($markdown, $offset, ($posClose-$offset)-1);
                            $offset = $posClose++;
                            continue;
                        }
                        $link .= \substr($markdown, $offset, ($posClose-$offset));
                        break;
                    }
                    /** @var int|false $posClose */
                    if ($posClose === false) {
                        throw new AssertionError("Unclosed ) opened @ pos $offset!");
                    }
                    $entity = self::handleLink($link);
                    $offset = $posClose+1;
                } else {
                    $entity = match ($char) {
                        '*' => ['type' => 'bold'],
                        '_' => ['type' => 'italic'],
                        '__' =>  ['type' => 'underline'],
                        '`' => ['type' => 'code'],
                        '~' => ['type' => 'strikethrough'],
                        '||' => ['type' => 'spoiler'],
                        default => throw new AssertionError("Unknown char $char @ pos $offset!")
                    };
                }
                $message .= $piece;
                $messageLen += EntityTools::mbStrlen($piece);
                if ($piece !== '') {
                    $canStartQuote = false;
                }

                $lengthReal = self::trimmedLength($message, $messageLen-$start);
                if ($lengthReal > 0) {
                    $entities []= $entity + ['offset' => $start, 'length' => $lengthReal];
                }
            } else {
                $message .= $piece;
                $messageLen += EntityTools::mbStrlen($piece);
                $stack []= [$char, $messageLen];
            }
        }
        if ($stack && self::hasQuote($stack)) {
            [$stack, $quote] = self::closeQuote($stack, $message, $messageLen, \str_ends_with($markdown, '||'));
            if ($quote !== null) {
                $entities []= $quote;
            }
        }
        if ($stack) {
            throw new AssertionError("Found unclosed markdown elements ".\implode(', ', \array_column($stack, 0)));
        }
        return new Entities(
            \trim($message),
            $entities,
        );
    }

    /**
     * @param list<array{0: string, 1: int}> $stack
     *
     * @psalm-pure
     */
    private static function hasQuote(array $stack): bool
    {
        foreach ($stack as [$char]) {
            if ($char === '>') {
                return true;
            }
        }
        return false;
    }

    /**
     * Close the block quotation at the end of the current line.
     *
     * @param non-empty-list<array{0: string, 1: int}> $stack
     * @param bool $endsWithSpoilerMarker Whether the line ends with ||, which makes the quotation expandable
     *
     * @return array{0: list<array{0: string, 1: int}>, 1: ?array{type: "blockquote"|"expandable_blockquote", offset: int<0, max>, length: int<0, max>}} New stack and quotation entity, if not empty
     *
     * @psalm-pure
     */
    private static function closeQuote(array $stack, string $message, int $messageLen, bool $endsWithSpoilerMarker): array
    {
        $type = 'blockquote';
        $top = $stack[\array_key_last($stack)];
        if ($endsWithSpoilerMarker && $top[0] === '||' && $top[1] === $messageLen) {
            $stack = \array_slice($stack, 0, -1);
            $type = 'expandable_blockquote';
            $top = $stack ? $stack[\array_key_last($stack)] : ['', 0];
        }
        if ($top[0] !== '>') {
            throw new AssertionError("Found unclosed markdown element {$top[0]} at the end of a block quotation");
        }
        $stack = \array_slice($stack, 0, -1);
        $start = $top[1];
        $lengthReal = self::trimmedLength($message, $messageLen-$start);
        if ($lengthReal > 0 && $start >= 0) {
            /** @var int<1, max> $lengthReal */
            return [$stack, ['type' => $type, 'offset' => $start, 'length' => $lengthReal]];
        }
        return [$stack, null];
    }

    /**
     * Length of an entity ending at the end of the message, without trailing whitespace.
     *
     * @psalm-pure
     */
    private static function trimmedLength(string $message, int $length): int
    {
        for ($x = \strlen($message)-1; $x >= 0; $x--) {
            if (!(
                $message[$x] === ' '
                || $message[$x] === "\r"
                || $message[$x] === "\n"
            )) {
                break;
            }
            $length--;
        }
        return $length;
    }

    /**
     * Manually convert HTML to a message and a set of entities.
     *
     * Supports the HTML syntax of the Bot API, including `<blockquote expandable>`,
     * `<pre><code class="language-php">`, `<tg-emoji emoji-id="...">` and
     * `<tg-time unix="..." format="...">`.
     *
     * @return Entities Object containing message and entities
     */
    public static function fromHtml(string $html): Entities
    {
        $dom = new DOMDocument();
        $html = \preg_replace(
            ['/\<br(\s*)?\/?\>/i', '/\<blockquote\s+expandable\s*\>/i'],
            ["\n", '<blockquote expandable="">'],
            $html
        );
        \assert($html !== null);
        $dom->loadxml('<body>' . \trim($html) . '</body>');
        $message = '';
        $entities = [];
        /** @psalm-suppress PossiblyNullArgument Ignore, will throw anyway */
        self::parseNode($dom->getElementsByTagName('body')->item(0), 0, $message, $entities);
        return new Entities(\trim($message), $entities);
    }
    /**
     * @return integer Length of the node
     *
     * @param-out list<TEntity> $entities
     * @param list<TEntity> $entities
     */
    private static function parseNode(DOMNode|DOMText $node, int $offset, string &$message, array &$entities): int
    {
        if ($node instanceof DOMText) {
            $message .= $node->wholeText;
            return EntityTools::mbStrlen($node->wholeText);
        }
        // @codeCoverageIgnoreStart
        if ($node->nodeName === 'br') {
            $message .= "\n";
            return 1;
        }
        // @codeCoverageIgnoreEnd
        $children = $node->childNodes;
        /** @var DOMElement $node */
        $entity = match ($node->nodeName) {
            's', 'strike', 'del' => ['type' => 'strikethrough'],
            'u', 'ins' =>  ['type' => 'underline'],
            'blockquote' => $node->hasAttribute('expandable')
                ? ['type' => 'expandable_blockquote']
                : ['type' => 'blockquote'],
            'b', 'strong' => ['type' => 'bold'],
            'i', 'em' => ['type' => 'italic'],
            'code' => ['type' => 'code'],
            'spoiler', 'tg-spoiler' => ['type' => 'spoiler'],
            'pre' => self::handlePre($node, $children),
            'span' => $node->hasAttribute('class') && $node->getAttribute('class') === 'tg-spoiler'
                    ? ['type' => 'spoiler']
                    : null,
            'tg-emoji' => ['type' => 'custom_emoji', 'custom_emoji_id' => (int) $node->getAttribute('emoji-id')],
            'emoji' => ['type' => 'custom_emoji', 'custom_emoji_id' => (int) $node->getAttribute('id')],
            'tg-time' => self::handleDateTime($node->getAttribute('unix'), $node->getAttribute('format')),
            'a' => self::handleLink($node->getAttribute('href')),
            default => null,
        };
        $length = 0;
        /** @var DOMNode|DOMText */
        foreach ($children as $sub) {
            $length += self::parseNode($sub, $offset+$length, $message, $entities);
        }
        if ($entity !== null) {
            $lengthReal = self::trimmedLength($message, $length);
            if ($lengthReal > 0) {
                \assert($offset >= 0);
                $entity['offset'] = $offset;
                $entity['length'] = $lengthReal;
                /** @psalm-check-type $entity = TEntity */
                $entities []= $entity;
            }
        }
        return $length;
    }
    /**
     * Handle a pre tag, either with a language attribute, or wrapping a code tag with a language-* class (Bot API syntax).
     *
     * @param \DOMNodeList $children Children to parse, replaced with the code tag's children if it carries the language
     *
     * @param-out \DOMNodeList $children
     *
     * @return array{type: "pre", language?: string}
     */
    private static function handlePre(DOMElement $node, \DOMNodeList &$children): array
    {
        if ($node->hasAttribute('language')) {
            return ['type' => 'pre', 'language' => $node->getAttribute('language')];
        }
        $code = $children->length === 1 ? $children->item(0) : null;
        if ($code instanceof DOMElement
            && $code->nodeName === 'code'
            && \str_starts_with($code->getAttribute('class'), 'language-')
        ) {
            $children = $code->childNodes;
            return ['type' => 'pre', 'language' => \substr($code->getAttribute('class'), 9)];
        }
        return ['type' => 'pre'];
    }
    /**
     * @return array{type: "date_time", unix_time: int, date_time_format: string}
     *
     * @psalm-pure
     */
    private static function handleDateTime(string $unixTime, string $format): array
    {
        if (!\ctype_digit($unixTime) || (int) $unixTime <= 0) {
            throw new AssertionError("Invalid Unix time specified: $unixTime");
        }
        return ['type' => 'date_time', 'unix_time' => (int) $unixTime, 'date_time_format' => self::normalizeDateTimeFormat($format)];
    }
    /**
     * Normalize a date-time format as the Bot API does: r for relative times, or w (day of the week), d|D (short|long date), t|T (short|long time), in this order.
     *
     * @psalm-pure
     */
    public static function normalizeDateTimeFormat(string $format): string
    {
        if ($format === 'r' || $format === 'R') {
            return 'r';
        }
        $dayOfWeek = $date = $time = '';
        foreach (\str_split($format) as $c) {
            match ($c) {
                't', 'T' => $time = $c,
                'd', 'D' => $date = $c,
                'w', 'W' => $dayOfWeek = 'w',
                default => throw new AssertionError("Invalid date-time format specified: $format"),
            };
        }
        return $dayOfWeek.$date.$time;
    }
    /**
     * @return array{type: "text_mention", user: array{id: int}}|array{type: "custom_emoji", custom_emoji_id: int}|array{type: "text_link", url: string}|array{type: "date_time", unix_time: int, date_time_format: string}
     *
     * @psalm-pure
     */
    private static function handleLink(string $href): array
    {
        if (\preg_match('|^mention:(.+)|', $href, $matches) || \preg_match('|^tg://user\\?id=(.+)|', $href, $matches)) {
            return ['type' => 'text_mention', 'user' => ['id' => (int) $matches[1]]];
        }
        if (\preg_match('|^emoji:(\d+)$|', $href, $matches) || \preg_match('|^tg://emoji\\?id=(.+)|', $href, $matches)) {
            return ['type' => 'custom_emoji', 'custom_emoji_id' => (int) $matches[1]];
        }
        if (\preg_match('|^tg://time\\?(.+)|', $href, $matches)) {
            return self::handleDateTime(
                \preg_match('/(?:^|&)unix=([^&]*)/', $matches[1], $unix) ? $unix[1] : '',
                \preg_match('/(?:^|&)format=([^&]*)/', $matches[1], $format) ? $format[1] : '',
            );
        }
        return ['type' => 'text_link', 'url' => $href];
    }
    /**
     * Convert a message and a set of entities to HTML.
     *
     * @param bool $allowTelegramTags Whether to allow telegram-specific tags like tg-spoiler, tg-emoji, tg-time, mention links and so on...
     *
     * @psalm-mutation-free
     */
    public function toHTML(bool $allowTelegramTags = false): string
    {
        $insertions = [];
        foreach ($this->entities as $entity) {
            ['offset' => $offset, 'length' => $length] = $entity;
            $insertions[$offset] ??= '';
            /** @psalm-suppress PossiblyUndefinedArrayOffset, DocblockTypeContradiction */
            $insertions[$offset] .= match ($entity['type']) {
                'bold' => '<b>',
                'italic' => '<i>',
                'code' => '<code>',
                'pre' => isset($entity['language']) && $entity['language'] !== ''
                    ? ($allowTelegramTags
                        ? '<pre><code class="language-'.EntityTools::htmlEscape($entity['language']).'">'
                        : '<pre language="'.EntityTools::htmlEscape($entity['language']).'">')
                    : '<pre>',
                'text_link' => '<a href="'.EntityTools::htmlEscape($entity['url']).'">',
                'strikethrough' => '<s>',
                "underline" => '<u>',
                "blockquote", "block_quote" => '<blockquote>',
                "expandable_blockquote" => $allowTelegramTags ? '<blockquote expandable>' : '<blockquote>',
                "url" => '<a href="'.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $offset, $length)).'">',
                "email" => '<a href="mailto:'.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $offset, $length)).'">',
                "phone_number", "phone" => '<a href="tel:'.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $offset, $length)).'">',
                "mention" => '<a href="https://t.me/'.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $offset+1, $length-1)).'">',
                "spoiler" => $allowTelegramTags ? '<tg-spoiler>' : '<span class="tg-spoiler">',
                "custom_emoji" => $allowTelegramTags ? '<tg-emoji emoji-id="'.$entity['custom_emoji_id'].'">' : '',
                "text_mention" => $allowTelegramTags ? '<a href="tg://user?id='.$entity['user']['id'].'">' : '',
                "date_time" => $allowTelegramTags
                    ? '<tg-time unix="'.$entity['unix_time'].'"'.(($entity['date_time_format'] ?? '') !== '' ? ' format="'.EntityTools::htmlEscape($entity['date_time_format']).'"' : '').'>'
                    : '',
                default => '',
            };
            $offset += $length;
            /** @psalm-suppress DocblockTypeContradiction */
            $insertions[$offset] = match ($entity['type']) {
                "bold" => '</b>',
                "italic" => '</i>',
                "code" => '</code>',
                "pre" => isset($entity['language']) && $entity['language'] !== '' && $allowTelegramTags
                    ? '</code></pre>'
                    : '</pre>',
                "text_link", "url", "email", "mention", "phone_number", "phone" => '</a>',
                "strikethrough" => '</s>',
                "underline" => '</u>',
                "blockquote", "block_quote", "expandable_blockquote" => '</blockquote>',
                "spoiler" => $allowTelegramTags ? '</tg-spoiler>' : '</span>',
                "custom_emoji" => $allowTelegramTags ? "</tg-emoji>" : '',
                "text_mention" => $allowTelegramTags ? '</a>' : '',
                "date_time" => $allowTelegramTags ? '</tg-time>' : '',
                default => '',
            } . ($insertions[$offset] ?? '');
        }
        \ksort($insertions);
        $final = '';
        $pos = 0;
        foreach ($insertions as $offset => $insertion) {
            $final .= EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $pos, $offset-$pos));
            $final .= $insertion;
            $pos = $offset;
        }
        return \str_replace("\n", "<br>", $final.EntityTools::htmlEscape(EntityTools::mbSubstr($this->message, $pos)));
    }
}
