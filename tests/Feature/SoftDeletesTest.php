<?php

namespace Moaines\IllumiSearch\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Moaines\IllumiSearch\Contracts\Engine;
use Moaines\IllumiSearch\Searchable;
use Moaines\IllumiSearch\Tests\TestCase;

class SoftDeletesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('soft_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function test_soft_deleted_model_skips_sync(): void
    {
        $modelClass = (new class extends Model
        {
            use SoftDeletes, Searchable;

            protected $table = 'soft_posts';

            protected array $searchable = [
                'title' => ['weight' => 3],
                'body' => ['weight' => 1],
            ];
        })::class;

        $engine = app(Engine::class);
        $engine->createTable($modelClass, ['title', 'body']);

        config(['illumi-search.indexing' => 'sync']);

        $model = $modelClass::forceCreate([
            'title' => 'visible post',
            'body' => 'content',
        ]);

        // Créer le modèle — doit être indexé
        $results = $engine->search('visible', [$modelClass], 10);
        $this->assertCount(1, $results, 'Model should be indexed after creation');

        // Soft-delete — doit être retiré de l'index
        $model->delete();
        $results = $engine->search('visible', [$modelClass], 10);
        $this->assertCount(0, $results, 'Model should be removed from index after soft delete');

        // Restore — doit être ré-indexé
        $model->restore();
        $results = $engine->search('visible', [$modelClass], 10);
        $this->assertCount(1, $results, 'Model should be re-indexed after restore');
    }
}
