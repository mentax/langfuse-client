<?php

declare(strict_types=1);

namespace Mentax\LangfuseClient\Tests\Score;

use InvalidArgumentException;
use Mentax\LangfuseClient\Score\ScoreClient;
use Mentax\LangfuseClient\Score\ScoreDataType;
use Mentax\LangfuseClient\Tests\Support\Factory;
use Mentax\LangfuseClient\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ScoreClientTest extends TestCase
{
    private FakeHttpClient $http;

    private ScoreClient $scores;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->scores = new ScoreClient(Factory::http($this->http));
    }

    public function testBooleanScoreOnTrace(): void
    {
        $this->http->respondJson(['id' => 'score-1']);

        $id = $this->scores->create('correct', true, traceId: 'trace-1', comment: 'matches expected');

        self::assertSame('score-1', $id);
        self::assertSame(
            ['name' => 'correct', 'value' => 1, 'dataType' => 'BOOLEAN', 'traceId' => 'trace-1', 'comment' => 'matches expected'],
            $this->http->lastRequestJson(),
        );
    }

    public function testCategoricalScoreOnExperimentRun(): void
    {
        $this->http->respondJson(['id' => 'score-2']);

        $this->scores->create('verdict', 'regression', experimentRunId: 'run-1');

        self::assertSame(['name' => 'verdict', 'value' => 'regression', 'dataType' => 'CATEGORICAL', 'datasetRunId' => 'run-1'], $this->http->lastRequestJson());
    }

    public function testRequiresExactlyOneTarget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->scores->create('x', 1, traceId: 't', sessionId: 's');
    }

    public function testRejectsValueThatDoesNotMatchDataType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->scores->create('x', 'high', traceId: 't', dataType: ScoreDataType::Numeric);
    }
}
