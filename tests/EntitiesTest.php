<?php

declare(strict_types=1);

namespace danog\TestTelegramEntities;

use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use AssertionError;
use danog\TelegramEntities\Entities;
use danog\TelegramEntities\EntityTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/** @internal */
class EntitiesTest extends TestCase
{
    private static HttpClient $client;
    public static function setUpBeforeClass(): void
    {
        self::$client = HttpClientBuilder::buildDefault();
    }
    public function testMb(): void
    {
        $this->assertEquals(1, EntityTools::mbStrlen('t'));
        $this->assertEquals(1, EntityTools::mbStrlen('я'));
        $this->assertEquals(2, EntityTools::mbStrlen('👍'));
        $this->assertEquals(4, EntityTools::mbStrlen('🇺🇦'));

        $this->assertEquals('st', EntityTools::mbSubstr('test', 2));
        $this->assertEquals('aя', EntityTools::mbSubstr('aяaя', 2));
        $this->assertEquals('a👍', EntityTools::mbSubstr('a👍a👍', 3));
        $this->assertEquals('🇺🇦', EntityTools::mbSubstr('🇺🇦🇺🇦', 4));

        $this->assertEquals(['te', 'st'], EntityTools::mbStrSplit('test', 2));
        $this->assertEquals(['aя', 'aя'], EntityTools::mbStrSplit('aяaя', 2));
        $this->assertEquals(['a👍', 'a👍'], EntityTools::mbStrSplit('a👍a👍', 3));
        $this->assertEquals(['🇺🇦', '🇺🇦'], EntityTools::mbStrSplit('🇺🇦🇺🇦', 4));
    }
    private static function render(string $message, string $parse_mode): Entities
    {
        return match ($parse_mode) {
            'html' => Entities::fromHtml($message),
            'markdown' => Entities::fromMarkdown($message),
        };
    }
    public function testEntities(): void
    {
        // Report every failing case at once, the Bot API cross-check can only be run in CI
        $failures = [];
        foreach ($this->provideEntities() as $params) {
            try {
                $this->testEntitiesInner(...$params);
            } catch (ExpectationFailedException $e) {
                $failures[] = $params[0].' '.\json_encode($params[1], JSON_UNESCAPED_UNICODE).': '.$e->getMessage()."\n".$e->getComparisonFailure()?->getDiff();
            }
        }
        $this->assertSame([], $failures, \implode("\n\n", $failures));
    }
    public function testUnclosed(): void
    {
        $this->expectExceptionMessage("Found unclosed markdown elements ](");
        Entities::fromMarkdown('[');
    }
    public function testUnclosedLink(): void
    {
        $this->expectExceptionMessage("Unclosed ) opened @ pos 7!");
        Entities::fromMarkdown('[test](https://google.com');
    }
    public function testUnclosedQuoteEntity(): void
    {
        $this->expectExceptionMessage("Found unclosed markdown element * at the end of a block quotation");
        Entities::fromMarkdown(">*quote\nnot");
    }
    public function testInvalidDateTimeFormat(): void
    {
        $this->expectExceptionMessage("Invalid date-time format specified: x");
        Entities::fromHtml('<tg-time unix="1" format="x">a</tg-time>');
    }
    public function testInvalidUnixTime(): void
    {
        $this->expectExceptionMessage("Invalid Unix time specified: ");
        Entities::fromMarkdown('![a](tg://time?format=r)');
    }
    /**
     * Trimming the message must move the entities.
     */
    public function testTrimmedEntities(): void
    {
        $test = Entities::fromMarkdown("> *b* c");
        $this->assertSame('b c', $test->message);
        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 1], ['type' => 'blockquote', 'offset' => 0, 'length' => 3]], $test->entities);

        $test = Entities::fromMarkdown("\\  x *y*");
        $this->assertSame('x y', $test->message);
        $this->assertSame([['type' => 'bold', 'offset' => 2, 'length' => 1]], $test->entities);

        $test = Entities::fromHtml('<b> x</b> y');
        $this->assertSame('x y', $test->message);
        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 1]], $test->entities);
    }
    /**
     * > only starts a block quotation at the start of a line, outside of other entities.
     */
    public function testGreaterThanInEntities(): void
    {
        $test = Entities::fromMarkdown('*> note*');
        $this->assertSame('> note', $test->message);
        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 6]], $test->entities);

        $test = Entities::fromMarkdown('[>a](https://example.com)');
        $this->assertSame('>a', $test->message);
        $this->assertSame([['type' => 'text_link', 'url' => 'https://example.com', 'offset' => 0, 'length' => 2]], $test->entities);

        $test = Entities::fromMarkdown(">*a\n>b*");
        $this->assertSame("a\nb", $test->message);
        $this->assertSame([['type' => 'bold', 'offset' => 0, 'length' => 3], ['type' => 'blockquote', 'offset' => 0, 'length' => 3]], $test->entities);
    }
    public function testExpandableAttribute(): void
    {
        $test = Entities::fromHtml('<blockquote class="x" expandable>a</blockquote>');
        $this->assertSame([['type' => 'expandable_blockquote', 'offset' => 0, 'length' => 1]], $test->entities);
    }
    public function testLiteralGreaterThan(): void
    {
        $test = Entities::fromMarkdown('a > b');
        $this->assertEmpty($test->entities);
        $this->assertSame('a > b', $test->message);
    }
    public function testUnclosedCode(): void
    {
        $this->expectExceptionMessage('Unclosed ``` opened @ pos 3!');
        Entities::fromMarkdown('```');
    }
    public function testStandalone(): void
    {
        $test = Entities::fromMarkdown(']');
        $this->assertEmpty($test->entities);
        $this->assertSame(']', $test->message);

        $test = Entities::fromMarkdown('!!');
        $this->assertEmpty($test->entities);
        $this->assertSame('!!', $test->message);

        $test = Entities::fromMarkdown('|');
        $this->assertEmpty($test->entities);
        $this->assertSame('|', $test->message);
    }

    #[DataProvider('provideHtmlEntities')]
    public function testToHtml(string $message, string $htmlTg, string $htmlNoTg, array $entities): void
    {
        $e = new Entities($message, $entities);
        $this->assertEquals($htmlTg, $e->toHTML(true));
        $this->assertEquals($htmlNoTg, $e->toHTML(false));
        $this->assertEquals($htmlNoTg, $e->toHTML());
    }
    public static function provideHtmlEntities(): iterable
    {
        yield [
            'test',
            'test',
            'test',
            [[
                'type' => 'bank_card',
                'offset' => 0,
                'length' => 4,
            ]]
        ];
        yield [
            'test',
            '<blockquote>test</blockquote>',
            '<blockquote>test</blockquote>',
            [[
                'type' => 'blockquote',
                'offset' => 0,
                'length' => 4,
            ]]
        ];
        yield [
            'test',
            '<b>test</b>',
            '<b>test</b>',
            [[
                'type' => 'bold',
                'offset' => 0,
                'length' => 4,
            ]]
        ];
        yield [
            'test',
            '<b>t<i>es</i>t</b>',
            '<b>t<i>es</i>t</b>',
            [[
                'type' => 'bold',
                'offset' => 0,
                'length' => 4,
            ], [
                'type' => 'italic',
                'offset' => 1,
                'length' => 2,
            ]]
        ];
        yield [
            'test',
            'test',
            'test',
            [[
                'type' => 'bot_command',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            'test',
            'test',
            [[
                'type' => 'cashtag',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            '<code>test</code>',
            '<code>test</code>',
            [[
                'type' => 'code',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            '<tg-emoji emoji-id="12345">test</tg-emoji>',
            'test',
            [[
                'type' => 'custom_emoji',
                'offset' => 0,
                'length' => 4,
                'custom_emoji_id' => 12345,
            ]]
        ];

        yield [
            'test',
            '<a href="mailto:test">test</a>',
            '<a href="mailto:test">test</a>',
            [[
                'type' => 'email',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            'test',
            'test',
            [[
                'type' => 'hashtag',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            '<i>test</i>',
            '<i>test</i>',
            [[
                'type' => 'italic',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            '<a href="tg://user?id=12345">test</a>',
            'test',
            [[
                'type' => 'text_mention',
                'offset' => 0,
                'length' => 4,
                'user' => ['id' => 12345]
            ]]
        ];

        yield [
            '@test',
            '<a href="https://t.me/test">@test</a>',
            '<a href="https://t.me/test">@test</a>',
            [[
                'type' => 'mention',
                'offset' => 0,
                'length' => 5,
            ]]
        ];

        yield [
            'test',
            '<a href="tel:test">test</a>',
            '<a href="tel:test">test</a>',
            [[
                'type' => 'phone_number',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            '<pre><code class="language-language">test</code></pre>',
            '<pre language="language">test</pre>',
            [[
                'type' => 'pre',
                'offset' => 0,
                'length' => 4,
                'language' => 'language',
            ]]
        ];

        yield [
            'test',
            '<tg-spoiler>test</tg-spoiler>',
            '<span class="tg-spoiler">test</span>',
            [[
                'type' => 'spoiler',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            '<s>test</s>',
            '<s>test</s>',
            [[
                'type' => 'strikethrough',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            '<a href="https://google.com">test</a>',
            '<a href="https://google.com">test</a>',
            [[
                'type' => 'text_link',
                'offset' => 0,
                'length' => 4,
                'url' => 'https://google.com',
            ]]
        ];

        yield [
            'test',
            '<a href="https://google.com/?arg=a&amp;arg2=b">test</a>',
            '<a href="https://google.com/?arg=a&amp;arg2=b">test</a>',
            [[
                'type' => 'text_link',
                'offset' => 0,
                'length' => 4,
                'url' => 'https://google.com/?arg=a&arg2=b',
            ]]
        ];

        yield [
            'test',
            '<u>test</u>',
            '<u>test</u>',
            [[
                'type' => 'underline',
                'offset' => 0,
                'length' => 4,
            ]]
        ];

        yield [
            'test',
            '<a href="test">test</a>',
            '<a href="test">test</a>',
            [[
                'type' => 'url',
                'offset' => 0,
                'length' => 4,
            ]]
        ];
    }
    private function testEntitiesInner(string $mode, string $html, string $bare, array $entities, ?string $htmlReverse = null): void
    {
        $result = self::render(message: $html, parse_mode: $mode);
        $this->assertEquals($bare, $result->message);
        $this->assertEquals($entities, $result->entities);

        if (
            !\str_contains($html, 'tg://emoji')
            && !\str_contains($html, '<br')
            && !\str_contains($html, 'mention:')
            && $html !== '[not a link]'
            && $bare !== "a_b\n\\ ```"
        ) {
            $token = \getenv("TOKEN");
            $dest = \getenv("DEST");
        }
        if (isset($token, $dest) && $token && $dest) {
            // The jobs of the CI share the bot, and can hit its flood limits
            do {
                $resultApi = \json_decode(self::$client->request(new Request(
                    "https://api.telegram.org/bot{$token}/sendMessage?".\http_build_query([
                        'chat_id'=> $dest,
                        'parse_mode'=> match ($mode) {
                            'markdown' => 'MarkdownV2',
                            'html' => 'html'
                        },
                        'text' => $html
                    ])
                ))->getBody()->buffer(), true);
                $retryAfter = $resultApi['parameters']['retry_after'] ?? null;
                if ($retryAfter !== null) {
                    \Amp\delay($retryAfter + 1);
                }
            } while ($retryAfter !== null);

            if (!isset($resultApi['result'])) {
                throw new AssertionError(\json_encode($resultApi));
            }

            $entitiesApi = $resultApi['result']['entities'] ?? [];
            $entitiesApi = \array_map(function (array $e): array {
                if (isset($e['user'])) {
                    $e['user'] = ['id' => $e['user']['id']];
                }
                if (isset($e['custom_emoji_id'])) {
                    $e['custom_emoji_id'] = (int) $e['custom_emoji_id'];
                }
                return $e;
            }, $entitiesApi);
            $this->assertEquals($bare, $resultApi['result']['text']);
            // The Bot API splits nested entities into non-overlapping ones: compare the entities applied to each character
            $this->assertEquals(self::coverage($bare, $entities), self::coverage($bare, \array_values(\array_filter(
                $entitiesApi,
                // Entities detected by the server
                static fn (array $e): bool => !\in_array($e['type'], ['url', 'email', 'phone_number', 'mention', 'bot_command', 'hashtag', 'cashtag', 'bank_card_number'], true)
            ))));
        }

        if (\strtolower($mode) === 'html') {
            $this->assertEquals(
                \trim(\str_replace(['<br/>', 'mention:'], ['<br>', 'tg://user?id='], $htmlReverse ?? $html)),
                $result->toHTML(true)
            );
            $result = self::render(message: EntityTools::htmlEscape($html), parse_mode: $mode);
            $this->assertEquals($html, $result->message);
            $this->assertNoRelevantEntities($result->entities);
        } else {
            $result = self::render(message: EntityTools::markdownEscape($html), parse_mode: $mode);
            $this->assertEquals($html, $result->message);
            $this->assertNoRelevantEntities($result->entities);

            $result = self::render(message: "```\n".EntityTools::markdownCodeblockEscape($html)."\n```", parse_mode: $mode);
            $this->assertEquals($html, \rtrim($result->message));
            $this->assertEquals([['offset' => 0, 'length' => EntityTools::mbStrlen($html), 'language' => '', 'type' => 'pre']], $result->entities);
        }
    }

    /**
     * Get the entities applied to each UTF-16 code unit of the text.
     *
     * @return list<list<string>>
     */
    private static function coverage(string $text, array $entities): array
    {
        $length = EntityTools::mbStrlen($text);
        $result = $length ? \array_fill(0, $length, []) : [];
        foreach ($entities as $entity) {
            $key = $entity;
            unset($key['offset'], $key['length']);
            \ksort($key);
            $key = \json_encode($key);
            for ($x = $entity['offset']; $x < \min($length, $entity['offset'] + $entity['length']); $x++) {
                $result[$x][] = $key;
            }
        }
        foreach ($result as &$keys) {
            \sort($keys);
        }
        return $result;
    }

    private function assertNoRelevantEntities(array $entities): void
    {
        $entities = \array_filter($entities, static fn (array $e) => !\in_array(
            $e['type'],
            ['url', 'email', 'phone_number', 'mention', 'bot_command'],
            true
        ));
        $this->assertEmpty($entities);
    }

    private function provideEntities(): array
    {
        return [
            [
                'html',
                '<b>test</b>',
                'test',
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'bold',
                    ],
                ],
            ],
            [
                'html',
                '<b>test</b><br>test',
                "test\ntest",
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'bold',
                    ],
                ],
            ],
            [
                'html',
                '<b>test</b><br/>test',
                "test\ntest",
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'bold',
                    ],
                ],
            ],
            [
                'html',
                '🇺🇦<b>🇺🇦</b>',
                '🇺🇦🇺🇦',
                [
                    [
                        'offset' => 4,
                        'length' => 4,
                        'type' => 'bold',
                    ],
                ],
            ],
            [
                'html',
                'test<b>test </b>',
                'testtest',
                [
                    [
                        'offset' => 4,
                        'length' => 4,
                        'type' => 'bold',
                    ],
                ],
                'test<b>test</b>',
            ],
            [
                'html',
                'è»test<b>test </b>test',
                'è»testtest test',
                [
                    [
                        'offset' => 6,
                        'length' => 5,
                        'type' => 'bold',
                    ],
                ],
            ],
            [
                'html',
                'test<b> test</b>',
                'test test',
                [
                    [
                        'offset' => 4,
                        'length' => 5,
                        'type' => 'bold',
                    ],
                ],
            ],
            [
                'markdown',
                'test* test*',
                'test test',
                [
                    [
                        'offset' => 4,
                        'length' => 5,
                        'type' => 'bold',
                    ],
                ],
            ],
            [
                'html',
                '<b>test</b><br><i>test</i> <code>test</code> <pre><code class="language-html">test</code></pre> <a href="https://example.com/">test</a> <s>strikethrough</s> <u>underline</u> <blockquote>blockquote</blockquote> https://google.com daniil@daniil.it +39398172758722 @daniilgentili <tg-spoiler>spoiler</tg-spoiler> &lt;b&gt;not_bold&lt;/b&gt;',
                "test\ntest test test test strikethrough underline blockquote https://google.com daniil@daniil.it +39398172758722 @daniilgentili spoiler <b>not_bold</b>",
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'bold',
                    ],
                    [
                        'offset' => 5,
                        'length' => 4,
                        'type' => 'italic',
                    ],
                    [
                        'offset' => 10,
                        'length' => 4,
                        'type' => 'code',
                    ],
                    [
                        'offset' => 15,
                        'length' => 4,
                        'language' => 'html',
                        'type' => 'pre',
                    ],
                    [
                        'offset' => 20,
                        'length' => 4,
                        'url' => 'https://example.com/',
                        'type' => 'text_link',
                    ],
                    [
                        'offset' => 25,
                        'length' => 13,
                        'type' => 'strikethrough',
                    ],
                    [
                        'offset' => 39,
                        'length' => 9,
                        'type' => 'underline',
                    ],
                    [
                        'offset' => 49,
                        'length' => 10,
                        'type' => 'blockquote',
                    ],
                    [
                        'offset' => 127,
                        'length' => 7,
                        'type' => 'spoiler',
                    ],
                ],
                '<b>test</b><br><i>test</i> <code>test</code> <pre><code class="language-html">test</code></pre> <a href="https://example.com/">test</a> <s>strikethrough</s> <u>underline</u> <blockquote>blockquote</blockquote> https://google.com daniil@daniil.it +39398172758722 @daniilgentili <tg-spoiler>spoiler</tg-spoiler> &lt;b&gt;not_bold&lt;/b&gt;',
            ],
            [
                'markdown',
                'test *bold _bold and italic_ bold*',
                'test bold bold and italic bold',
                [
                    [
                        'offset' => 10,
                        'length' => 15,
                        'type' => 'italic',
                    ],
                    [
                        'offset' => 5,
                        'length' => 25,
                        'type' => 'bold',
                    ],
                ],
            ],
            [
                'markdown',
                "a\nb\nc",
                "a\nb\nc",
                [],
            ],
            [
                'markdown',
                "a\n\nb\n\nc",
                "a\n\nb\n\nc",
                [],
            ],
            [
                'markdown',
                "a\n\n\nb\n\n\nc",
                "a\n\n\nb\n\n\nc",
                [],
            ],
            [
                'markdown',
                "a\n```php\n<?php\necho 'yay';\n```",
                "a\n<?php\necho 'yay';",
                [
                    [
                        'offset' => 2,
                        'length' => 17,
                        'type' => 'pre',
                        'language' => 'php',
                    ],
                ],
            ],
            [
                'html',
                '<b>\'"</b>',
                '\'"',
                [
                    [
                        'offset' => 0,
                        'length' => 2,
                        'type' => 'bold',
                    ],
                ],
                '<b>&apos;&quot;</b>',
            ],
            [
                'html',
                '<span class="tg-spoiler">spoiler</span>',
                'spoiler',
                [
                    [
                        'offset' => 0,
                        'length' => 7,
                        'type' => 'spoiler',
                    ],
                ],
                '<tg-spoiler>spoiler</tg-spoiler>',
            ],
            [
                'html',
                '<a href="mention:101374607">mention1</a> <a href="tg://user?id=101374607">mention2</a>',
                'mention1 mention2',
                [
                    [
                        'offset' => 0,
                        'length' => 8,
                        'type' => 'text_mention',
                        'user' => ['id' => 101374607],
                    ],
                    [
                        'offset' => 9,
                        'length' => 8,
                        'type' => 'text_mention',
                        'user' => ['id' => 101374607],
                    ],
                ],
            ],
            [
                'html',
                '<a href="tg://user?id=101374607">mention1</a> <a href="tg://user?id=101374607">mention2</a>',
                'mention1 mention2',
                [
                    [
                        'offset' => 0,
                        'length' => 8,
                        'type' => 'text_mention',
                        'user' => ['id' => 101374607],
                    ],
                    [
                        'offset' => 9,
                        'length' => 8,
                        'type' => 'text_mention',
                        'user' => ['id' => 101374607],
                    ],
                ],
            ],
            [
                'markdown',
                '_a b c <b\> & " \' \_ \* \~ \\__',
                'a b c <b> & " \' _ * ~ _',
                [
                    [
                        'offset' => 0,
                        'length' => 23,
                        'type' => 'italic',
                    ],
                ],
            ],
            [
                'markdown',
                EntityTools::markdownEscape('\\ test testovich _*~'),
                '\\ test testovich _*~',
                [],
            ],
            [
                'markdown',
                "```\na_b\n".EntityTools::markdownCodeblockEscape('\\ ```').'```',
                "a_b\n\\ ```",
                [
                    [
                        'offset' => 0,
                        'length' => 9,
                        'type' => 'pre',
                        'language' => '',
                    ],
                ],
            ],
            [
                'markdown',
                '`c_d '.EntityTools::markdownCodeEscape('`').'`',
                'c_d `',
                [
                    [
                        'offset' => 0,
                        'length' => 5,
                        'type' => 'code',
                    ],
                ],
            ],
            [
                'markdown',
                '[link ](https://google.com/)test',
                'link test',
                [
                    [
                        'offset' => 0,
                        'length' => 5,
                        'type' => 'text_link',
                        'url' => 'https://google.com/',
                    ],
                ],
            ],
            [
                'markdown',
                '[link]('.EntityTools::markdownUrlEscape('https://transfer.sh/(/test/test.PNG,/test/test.MP4).zip').')',
                'link',
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'text_link',
                        'url' => 'https://transfer.sh/(/test/test.PNG,/test/test.MP4).zip',
                    ],
                ],
            ],
            [
                'markdown',
                '[link]('.EntityTools::markdownUrlEscape('https://google.com/').')',
                'link',
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'text_link',
                        'url' => 'https://google.com/',
                    ],
                ],
            ],
            [
                'markdown',
                '[link]('.EntityTools::markdownUrlEscape('https://google.com/?v=\\test').')',
                'link',
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'text_link',
                        'url' => 'https://google.com/?v=\\test',
                    ],
                ],
            ],
            [
                'markdown',
                '[link ](https://google.com/)',
                'link',
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'text_link',
                        'url' => 'https://google.com/',
                    ],
                ],
            ],
            [
                'markdown',
                '![link ](tg://emoji?id=5368324170671202286)',
                'link',
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'custom_emoji',
                        'custom_emoji_id' => 5368324170671202286,
                    ],
                ],
            ],
            [
                'markdown',
                '[not a link]',
                '[not a link]',
                [],
            ],
            [
                'html',
                '<a href="https://google.com/">link </a>test',
                'link test',
                [
                    [
                        'offset' => 0,
                        'length' => 5,
                        'type' => 'text_link',
                        'url' => 'https://google.com/',
                    ],
                ],
            ],
            [
                'html',
                '<a href="https://google.com/?a=a&amp;b=b">link </a>',
                'link',
                [
                    [
                        'offset' => 0,
                        'length' => 4,
                        'type' => 'text_link',
                        'url' => 'https://google.com/?a=a&b=b',
                    ],
                ],
                '<a href="https://google.com/?a=a&amp;b=b">link</a> ',
            ],
            [
                'markdown',
                'test _italic_ *bold* __underlined__ ~strikethrough~ ```test pre``` `code` ||spoiler||',
                'test italic bold underlined strikethrough  pre code spoiler',
                [
                    [
                        'offset' => 5,
                        'length' => 6,
                        'type' => 'italic',
                    ],
                    [
                        'offset' => 12,
                        'length' => 4,
                        'type' => 'bold',
                    ],
                    [
                        'offset' => 17,
                        'length' => 10,
                        'type' => 'underline',
                    ],
                    [
                        'offset' => 28,
                        'length' => 13,
                        'type' => 'strikethrough',
                    ],
                    [
                        'offset' => 42,
                        'length' => 4,
                        'type' => 'pre',
                        'language' => 'test',
                    ],
                    [
                        'offset' => 47,
                        'length' => 4,
                        'type' => 'code',
                    ],
                    [
                        'offset' => 52,
                        'length' => 7,
                        'type' => 'spoiler',
                    ],
                ],
            ],
            [
                'markdown',
                '[special link]('.EntityTools::markdownUrlEscape('https://google.com/)').')',
                'special link',
                [
                    [
                        'offset' => 0,
                        'length' => 12,
                        'type' => 'text_link',
                        'url' => 'https://google.com/)',
                    ],
                ],
                '<a href="https://google.com/)">link</a> ',
            ],
            [
                'markdown',
                '`'.EntityTools::markdownCodeEscape('``').'`',
                '``',
                [
                    [
                        'offset' => 0,
                        'length' => 2,
                        'type' => 'code',
                    ],
                ],
                '`\`\``',
            ],
            [
                'html',
                'a<blockquote expandable>quote</blockquote>',
                'aquote',
                [['offset' => 1, 'length' => 5, 'type' => 'expandable_blockquote']],
            ],
            [
                'html',
                'a<blockquote>quote</blockquote>',
                'aquote',
                [['offset' => 1, 'length' => 5, 'type' => 'blockquote']],
            ],
            [
                'html',
                'Meet <tg-time unix="1647531900" format="wDT">22:45 tomorrow</tg-time>',
                'Meet 22:45 tomorrow',
                [['offset' => 5, 'length' => 14, 'type' => 'date_time', 'unix_time' => 1647531900, 'date_time_format' => 'wDT']],
            ],
            [
                'html',
                'Meet <tg-time unix="1647531900" format="Tdw">22:45 tomorrow</tg-time>',
                'Meet 22:45 tomorrow',
                [['offset' => 5, 'length' => 14, 'type' => 'date_time', 'unix_time' => 1647531900, 'date_time_format' => 'wdT']],
                'Meet <tg-time unix="1647531900" format="wdT">22:45 tomorrow</tg-time>',
            ],
            [
                'html',
                'Meet <tg-time unix="1647531900" format="r">soon</tg-time>',
                'Meet soon',
                [['offset' => 5, 'length' => 4, 'type' => 'date_time', 'unix_time' => 1647531900, 'date_time_format' => 'r']],
            ],
            [
                'html',
                'Meet <tg-time unix="1647531900">then</tg-time>',
                'Meet then',
                [['offset' => 5, 'length' => 4, 'type' => 'date_time', 'unix_time' => 1647531900, 'date_time_format' => '']],
            ],
            [
                'html',
                'a<ins>underline</ins>',
                'aunderline',
                [['offset' => 1, 'length' => 9, 'type' => 'underline']],
                'a<u>underline</u>',
            ],
            [
                'html',
                '<pre><code class="language-php">echo 1;</code></pre>',
                'echo 1;',
                [['offset' => 0, 'length' => 7, 'type' => 'pre', 'language' => 'php']],
            ],
            [
                'html',
                'a<code class="language-php">echo 1;</code>',
                'aecho 1;',
                [['offset' => 1, 'length' => 7, 'type' => 'code']],
                'a<code>echo 1;</code>',
            ],
            [
                'markdown',
                ">quote\n>line *2*\nnot quoted",
                "quote\nline 2\nnot quoted",
                [
                    ['offset' => 11, 'length' => 1, 'type' => 'bold'],
                    ['offset' => 0, 'length' => 13, 'type' => 'blockquote'],
                ],
            ],
            [
                'markdown',
                "text\n**>quote\n>line 2||\nnot quoted",
                "text\nquote\nline 2\nnot quoted",
                [['offset' => 5, 'length' => 13, 'type' => 'expandable_blockquote']],
            ],
            [
                'markdown',
                ">quote||",
                "quote",
                [['offset' => 0, 'length' => 5, 'type' => 'expandable_blockquote']],
            ],
            [
                'markdown',
                ">quote ||spoiler||",
                "quote spoiler",
                [
                    ['offset' => 6, 'length' => 7, 'type' => 'spoiler'],
                    ['offset' => 0, 'length' => 13, 'type' => 'blockquote'],
                ],
            ],
            [
                'markdown',
                'Meet ![22:45 tomorrow](tg://time?unix=1647531900&format=wDT)',
                'Meet 22:45 tomorrow',
                [['offset' => 5, 'length' => 14, 'type' => 'date_time', 'unix_time' => 1647531900, 'date_time_format' => 'wDT']],
            ],
        ];
    }
}
