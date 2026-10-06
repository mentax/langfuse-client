<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Prompt;

use Mentax\LangfuseClient\Exception\InvalidResponseException;
use Mentax\LangfuseClient\Exception\PromptCompilationException;
use Mentax\LangfuseClient\Prompt\Prompt;
use Mentax\LangfuseClient\Prompt\TextPrompt;
use Mentax\LangfuseClient\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

final class TextPromptTest extends TestCase
{
    public function testCompilesVariablesWithOptionalWhitespaceAndUnicodeNames(): void
    {
        $prompt = new TextPrompt('p', 1, 'Szkoda: {{ przyczyna_szkody }}, zdjęć: {{count}}.');

        self::assertSame(['przyczyna_szkody', 'count'], $prompt->variables());
        self::assertSame('Szkoda: zalanie, zdjęć: 3.', $prompt->compile(['przyczyna_szkody' => 'zalanie', 'count' => 3]));
    }

    public function testSubstitutesInOnePassSoValuesAreNeverExpanded(): void
    {
        $prompt = new TextPrompt('p', 1, '{{a}} / {{b}}');

        self::assertSame('{{b}} / x', $prompt->compile(['a' => '{{b}}', 'b' => 'x']));
    }

    public function testLeavesBracesThatAreNotVariablesUntouched(): void
    {
        $prompt = new TextPrompt('p', 1, 'JSON: {"a": {{value}}} {{ not a var }} {{1abc}}');

        self::assertSame(['value'], $prompt->variables());
        self::assertSame('JSON: {"a": 1} {{ not a var }} {{1abc}}', $prompt->compile(['value' => 1]));
    }

    public function testMissingVariableThrows(): void
    {
        $prompt = new TextPrompt('damageaudit/vin', 2, '{{vin}} {{plate}}');

        try {
            $prompt->compile(['vin' => 'X']);
            self::fail('Expected PromptCompilationException.');
        } catch (PromptCompilationException $e) {
            self::assertSame(['plate'], $e->missing);
            self::assertSame([], $e->unexpected);
            self::assertStringContainsString('damageaudit/vin', $e->getMessage());
        }
    }

    public function testUnexpectedVariableThrows(): void
    {
        $prompt = new TextPrompt('p', 1, 'static text');

        $this->expectException(PromptCompilationException::class);
        $prompt->compile(['leftover' => 'x']);
    }

    public function testRoundTripsThroughArray(): void
    {
        $prompt = Prompt::fromArray(Factory::textPromptResponse());

        self::assertInstanceOf(TextPrompt::class, $prompt);
        self::assertSame('damageaudit/airbag-photo', $prompt->name);
        self::assertSame(3, $prompt->version);
        self::assertSame(['production', 'latest'], $prompt->labels);
        self::assertSame(['model' => 'gemini-2.5-flash-lite', 'temperature' => 0], $prompt->config);
        self::assertSame('Initial import', $prompt->commitMessage);
        self::assertEquals($prompt, Prompt::fromArray($prompt->toArray()));
    }

    public function testRejectsMalformedResponse(): void
    {
        $this->expectException(InvalidResponseException::class);
        Prompt::fromArray(Factory::textPromptResponse(['version' => '3']));
    }
}
