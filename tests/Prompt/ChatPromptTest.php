<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Prompt;

use Mentax\LangfuseClient\Exception\PromptCompilationException;
use Mentax\LangfuseClient\Prompt\ChatMessage;
use Mentax\LangfuseClient\Prompt\ChatPrompt;
use Mentax\LangfuseClient\Prompt\MessagePlaceholder;
use Mentax\LangfuseClient\Prompt\Prompt;
use Mentax\LangfuseClient\Tests\Support\Factory;
use PHPUnit\Framework\TestCase;

final class ChatPromptTest extends TestCase
{
    private function prompt(): ChatPrompt
    {
        $prompt = Prompt::fromArray(Factory::textPromptResponse([
            'type' => 'chat',
            'prompt' => [
                ['role' => 'system', 'content' => 'You audit {{claim_type}} claims.'],
                ['type' => 'placeholder', 'name' => 'history'],
                ['role' => 'user', 'content' => 'Note: {{note}}'],
            ],
        ]));
        self::assertInstanceOf(ChatPrompt::class, $prompt);

        return $prompt;
    }

    public function testParsesMessagesAndPlaceholders(): void
    {
        $prompt = $this->prompt();

        self::assertEquals(new ChatMessage('system', 'You audit {{claim_type}} claims.'), $prompt->messages[0]);
        self::assertEquals(new MessagePlaceholder('history'), $prompt->messages[1]);
        self::assertSame(['claim_type', 'note'], $prompt->variables());
        self::assertSame(['history'], $prompt->placeholders());
    }

    public function testCompilesVariablesAndExpandsPlaceholdersWithoutRenderingThem(): void
    {
        $compiled = $this->prompt()->compile(
            ['claim_type' => 'property', 'note' => 'flooded'],
            ['history' => [new ChatMessage('user', 'earlier {{note}}'), ['role' => 'assistant', 'content' => 'ok']]],
        );

        self::assertSame([
            ['role' => 'system', 'content' => 'You audit property claims.'],
            ['role' => 'user', 'content' => 'earlier {{note}}'],
            ['role' => 'assistant', 'content' => 'ok'],
            ['role' => 'user', 'content' => 'Note: flooded'],
        ], $compiled);
    }

    public function testMissingPlaceholderThrows(): void
    {
        try {
            $this->prompt()->compile(['claim_type' => 'property', 'note' => 'x']);
            self::fail('Expected PromptCompilationException.');
        } catch (PromptCompilationException $e) {
            self::assertSame(['history'], $e->missing);
        }
    }

    public function testRoundTripsThroughArray(): void
    {
        $prompt = $this->prompt();

        self::assertEquals($prompt, Prompt::fromArray($prompt->toArray()));
    }
}
