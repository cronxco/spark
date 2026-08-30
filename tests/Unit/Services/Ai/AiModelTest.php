<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\AiModel;
use RuntimeException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiModelTest extends TestCase
{
    #[Test]
    public function each_role_resolves_its_configured_model(): void
    {
        config([
            'services.openai.models.embedding' => 'text-embedding-3-small',
            'services.openai.models.extraction' => 'gpt-5-nano',
            'services.openai.models.reasoning' => 'gpt-4o-mini',
        ]);

        $this->assertSame('text-embedding-3-small', AiModel::Embedding->model());
        $this->assertSame('gpt-5-nano', AiModel::Extraction->model());
        $this->assertSame('gpt-4o-mini', AiModel::Reasoning->model());
    }

    #[Test]
    public function a_role_follows_its_env_backed_config_key(): void
    {
        config(['services.openai.models.extraction' => 'some-other-model']);

        $this->assertSame('some-other-model', AiModel::Extraction->model());
    }

    #[Test]
    public function an_unconfigured_role_fails_loudly(): void
    {
        config(['services.openai.models.reasoning' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No OpenAI model is configured for the 'reasoning' role.");

        AiModel::Reasoning->model();
    }

    #[Test]
    public function a_blank_role_is_treated_as_unconfigured(): void
    {
        config(['services.openai.models.extraction' => '   ']);

        $this->expectException(RuntimeException::class);

        AiModel::Extraction->model();
    }
}
