<?php

namespace Illuminate\Tests\Database;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Support\Collection as BaseCollection;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use UnexpectedValueException;

class DatabaseEloquentCollectionTest extends TestCase
{
    /**
     * Setup the database schema.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();
    }

    protected function createSchema()
    {
        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->string('email')->unique();
        });

        $this->schema()->create('articles', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('title');
        });

        $this->schema()->create('comments', function ($table) {
            $table->increments('id');
            $table->integer('article_id');
            $table->string('content');
        });
    }

    protected function tearDown(): void
    {
        $this->schema()->drop('users');
        $this->schema()->drop('articles');
        $this->schema()->drop('comments');
        m::close();
    }

    public function testAddingItemsToCollection()
    {
        $models = [
            $model1 = new TestEloquentCollectionModel,
            $model2 = new TestEloquentCollectionModel,
            $model3 = new TestEloquentCollectionModel,
        ];
        $c = new Collection([$model1]);
        $c->add($model2)->add($model3);
        $this->assertEquals($models, $c->all());
    }

    public function testGettingMaxItemsFromCollection()
    {
        $c = new Collection([
            new TestEloquentCollectionModel(['foo' => 10]),
            new TestEloquentCollectionModel(['foo' => 20]),
        ]);
        $this->assertEquals(20, $c->max('foo'));
    }

    public function testGettingMinItemsFromCollection()
    {
        $c = new Collection([
            new TestEloquentCollectionModel(['foo' => 10]),
            new TestEloquentCollectionModel(['foo' => 20]),
        ]);
        $this->assertEquals(10, $c->min('foo'));
    }

    public function testContainsWithMultipleArguments()
    {
        $c = new Collection([
            new TestEloquentCollectionModel(['id' => 1]),
            new TestEloquentCollectionModel(['id' => 2]),
        ]);

        $this->assertTrue($c->contains('id', 1));
        $this->assertTrue($c->contains('id', '>=', 2));
        $this->assertFalse($c->contains('id', '>', 2));
    }

    public function testContainsIndicatesIfModelInArray()
    {
        $mockModel = m::mock(Model::class);
        $mockModel->shouldReceive('is')->with($mockModel)->andReturn(true);
        $mockModel->shouldReceive('is')->andReturn(false);
        $mockModel2 = m::mock(Model::class);
        $mockModel2->shouldReceive('is')->with($mockModel2)->andReturn(true);
        $mockModel2->shouldReceive('is')->andReturn(false);
        $mockModel3 = m::mock(Model::class);
        $mockModel3->shouldReceive('is')->with($mockModel3)->andReturn(true);
        $mockModel3->shouldReceive('is')->andReturn(false);
        $c = new Collection([$mockModel, $mockModel2]);

        $this->assertTrue($c->contains($mockModel));
        $this->assertTrue($c->contains($mockModel2));
        $this->assertFalse($c->contains($mockModel3));
    }

    public function testContainsIndicatesIfDifferentModelInArray()
    {
        $mockModelFoo = m::namedMock('Foo', Model::class);
        $mockModelFoo->shouldReceive('is')->with($mockModelFoo)->andReturn(true);
        $mockModelFoo->shouldReceive('is')->andReturn(false);
        $mockModelBar = m::namedMock('Bar', Model::class);
        $mockModelBar->shouldReceive('is')->with($mockModelBar)->andReturn(true);
        $mockModelBar->shouldReceive('is')->andReturn(false);
        $c = new Collection([$mockModelFoo]);

        $this->assertTrue($c->contains($mockModelFoo));
        $this->assertFalse($c->contains($mockModelBar));
    }

    public function testContainsIndicatesIfKeyedModelInArray()
    {
        $mockModel = m::mock(Model::class);
        $mockModel->shouldReceive('getKey')->andReturn('1');
        $c = new Collection([$mockModel]);
        $mockModel2 = m::mock(Model::class);
        $mockModel2->shouldReceive('getKey')->andReturn('2');
        $c->add($mockModel2);

        $this->assertTrue($c->contains(1));
        $this->assertTrue($c->contains(2));
        $this->assertFalse($c->contains(3));
    }

    public function testContainsKeyAndValueIndicatesIfModelInArray()
    {
        $mockModel1 = m::mock(Model::class);
        $mockModel1->shouldReceive('offsetExists')->with('name')->andReturn(true);
        $mockModel1->shouldReceive('offsetGet')->with('name')->andReturn('Taylor');
        $mockModel2 = m::mock(Model::class);
        $mockModel2->shouldReceive('offsetExists')->andReturn(true);
        $mockModel2->shouldReceive('offsetGet')->with('name')->andReturn('Abigail');
        $c = new Collection([$mockModel1, $mockModel2]);

        $this->assertTrue($c->contains('name', 'Taylor'));
        $this->assertTrue($c->contains('name', 'Abigail'));
        $this->assertFalse($c->contains('name', 'Dayle'));
    }

    public function testContainsClosureIndicatesIfModelInArray()
    {
        $mockModel1 = m::mock(Model::class);
        $mockModel1->shouldReceive('getKey')->andReturn(1);
        $mockModel2 = m::mock(Model::class);
        $mockModel2->shouldReceive('getKey')->andReturn(2);
        $c = new Collection([$mockModel1, $mockModel2]);

        $this->assertTrue($c->contains(function ($model) {
            return $model->getKey() < 2;
        }));
        $this->assertFalse($c->contains(function ($model) {
            return $model->getKey() > 2;
        }));
    }

    public function testFindMethodFindsModelById()
    {
        $mockModel = m::mock(Model::class);
        $mockModel->shouldReceive('getKey')->andReturn(1);
        $c = new Collection([$mockModel]);

        $this->assertSame($mockModel, $c->find(1));
        $this->assertSame('taylor', $c->find(2, 'taylor'));
    }

    public function testFindMethodFindsManyModelsById()
    {
        $model1 = (new TestEloquentCollectionModel)->forceFill(['id' => 1]);
        $model2 = (new TestEloquentCollectionModel)->forceFill(['id' => 2]);
        $model3 = (new TestEloquentCollectionModel)->forceFill(['id' => 3]);

        $c = new Collection;
        $this->assertInstanceOf(Collection::class, $c->find([]));
        $this->assertCount(0, $c->find([1]));

        $c->push($model1);
        $this->assertCount(1, $c->find([1]));
        $this->assertEquals(1, $c->find([1])->first()->id);
        $this->assertCount(0, $c->find([2]));

        $c->push($model2)->push($model3);
        $this->assertCount(1, $c->find([2]));
        $this->assertEquals(2, $c->find([2])->first()->id);
        $this->assertCount(2, $c->find([2, 3, 4]));
        $this->assertCount(2, $c->find(collect([2, 3, 4])));
        $this->assertEquals([2, 3], $c->find(collect([2, 3, 4]))->pluck('id')->all());
        $this->assertEquals([2, 3], $c->find([2, 3, 4])->pluck('id')->all());
    }

    public function testLoadMethodEagerLoadsGivenRelationships()
    {
        $mockModel = m::mock(Model::class);
        $c = $this->getMockBuilder(Collection::class)
            ->onlyMethods(['first'])
            ->setConstructorArgs([[$mockModel]])
            ->getMock();
        $mockItem = m::mock(stdClass::class);
        $c->expects($this->once())->method('first')->willReturn($mockItem);
        $mockItem->shouldReceive('newQueryWithoutRelationships')->once()->andReturn($mockItem);
        $mockItem->shouldReceive('with')->with(['bar', 'baz'])->andReturn($mockItem);
        $mockItem->shouldReceive('eagerLoadRelations')->once()->with([$mockModel])->andReturn(['results']);
        $c->load('bar', 'baz');

        $this->assertEquals(['results'], $c->all());
    }

    public function testCollectionDictionaryReturnsModelKeys()
    {
        $one = m::mock(Model::class);
        $one->shouldReceive('getKey')->andReturn(1);

        $two = m::mock(Model::class);
        $two->shouldReceive('getKey')->andReturn(2);

        $three = m::mock(Model::class);
        $three->shouldReceive('getKey')->andReturn(3);

        $c = new Collection([$one, $two, $three]);

        $this->assertEquals([1, 2, 3], $c->modelKeys());
    }

    public function testCollectionMergesWithGivenCollection()
    {
        $one = new TestEloquentCollectionModel(['id' => 1]);
        $two = new TestEloquentCollectionModel(['id' => 2]);
        $three = new TestEloquentCollectionModel(['id' => 3]);

        $c1 = new Collection([$one, $two]);
        $c2 = new Collection([$two, $three]);

        $this->assertEquals(new Collection([$one, $two, $three]), $c1->merge($c2));
    }

    public function testMap()
    {
        $one = m::mock(Model::class);
        $two = m::mock(Model::class);

        $c = new Collection([$one, $two]);

        $cAfterMap = $c->map(function ($item) {
            return $item;
        });

        $this->assertEquals($c->all(), $cAfterMap->all());
        $this->assertInstanceOf(Collection::class, $cAfterMap);
    }

    public function testMapWithKeys()
    {
        $one = m::mock(Model::class);
        $two = m::mock(Model::class);

        $c = new Collection([$one, $two]);

        $key = 0;
        $cAfterMap = $c->mapWithKeys(function ($item) use (&$key) {
            return [$key++ => $item];
        });

        $this->assertEquals($c->all(), $cAfterMap->all());
        $this->assertInstanceOf(Collection::class, $cAfterMap);
    }

    public function testCollectionDiffsWithGivenCollection()
    {
        $one = m::mock(Model::class);
        $one->shouldReceive('getKey')->andReturn(1);

        $two = m::mock(Model::class);
        $two->shouldReceive('getKey')->andReturn(2);

        $three = m::mock(Model::class);
        $three->shouldReceive('getKey')->andReturn(3);

        $c1 = new Collection([$one, $two]);
        $c2 = new Collection([$two, $three]);

        $this->assertEquals(new Collection([$one]), $c1->diff($c2));
    }

    public function testCollectionReturnsDuplicateBasedOnlyOnKeys()
    {
        $one = new TestEloquentCollectionModel;
        $two = new TestEloquentCollectionModel;
        $three = new TestEloquentCollectionModel;
        $four = new TestEloquentCollectionModel;
        $one->id = 1;
        $one->someAttribute = '1';
        $two->id = 1;
        $two->someAttribute = '2';
        $three->id = 1;
        $three->someAttribute = '3';
        $four->id = 2;
        $four->someAttribute = '4';

        $duplicates = Collection::make([$one, $two, $three, $four])->duplicates()->all();
        $this->assertSame([1 => $two, 2 => $three], $duplicates);

        $duplicates = Collection::make([$one, $two, $three, $four])->duplicatesStrict()->all();
        $this->assertSame([1 => $two, 2 => $three], $duplicates);
    }

    public function testCollectionIntersectWithNull()
    {
        $one = m::mock(Model::class);
        $one->shouldReceive('getKey')->andReturn(1);

        $two = m::mock(Model::class);
        $two->shouldReceive('getKey')->andReturn(2);

        $three = m::mock(Model::class);
        $three->shouldReceive('getKey')->andReturn(3);

        $c1 = new Collection([$one, $two, $three]);

        $this->assertEquals([], $c1->intersect(null)->all());
    }

    public function testCollectionIntersectsWithGivenCollection()
    {
        $one = m::mock(Model::class);
        $one->shouldReceive('getKey')->andReturn(1);

        $two = m::mock(Model::class);
        $two->shouldReceive('getKey')->andReturn(2);

        $three = m::mock(Model::class);
        $three->shouldReceive('getKey')->andReturn(3);

        $c1 = new Collection([$one, $two]);
        $c2 = new Collection([$two, $three]);

        $this->assertEquals(new Collection([$two]), $c1->intersect($c2));
    }

    public function testCollectionReturnsUniqueItems()
    {
        $one = m::mock(Model::class);
        $one->shouldReceive('getKey')->andReturn(1);

        $two = m::mock(Model::class);
        $two->shouldReceive('getKey')->andReturn(2);

        $c = new Collection([$one, $two, $two]);

        $this->assertEquals(new Collection([$one, $two]), $c->unique());
    }

    public function testCollectionReturnsUniqueStrictBasedOnKeysOnly()
    {
        $one = new TestEloquentCollectionModel;
        $two = new TestEloquentCollectionModel;
        $three = new TestEloquentCollectionModel;
        $four = new TestEloquentCollectionModel;
        $one->id = 1;
        $one->someAttribute = '1';
        $two->id = 1;
        $two->someAttribute = '2';
        $three->id = 1;
        $three->someAttribute = '3';
        $four->id = 2;
        $four->someAttribute = '4';

        $uniques = Collection::make([$one, $two, $three, $four])->unique()->all();
        $this->assertSame([$three, $four], $uniques);

        $uniques = Collection::make([$one, $two, $three, $four])->unique(null, true)->all();
        $this->assertSame([$three, $four], $uniques);
    }

    public function testOnlyReturnsCollectionWithGivenModelKeys()
    {
        $one = m::mock(Model::class);
        $one->shouldReceive('getKey')->andReturn(1);

        $two = m::mock(Model::class);
        $two->shouldReceive('getKey')->andReturn(2);

        $three = m::mock(Model::class);
        $three->shouldReceive('getKey')->andReturn(3);

        $c = new Collection([$one, $two, $three]);

        $this->assertEquals($c, $c->only(null));
        $this->assertEquals(new Collection([$one]), $c->only(1));
        $this->assertEquals(new Collection([$two, $three]), $c->only([2, 3]));
    }

    public function testExceptReturnsCollectionWithoutGivenModelKeys()
    {
        $one = m::mock(Model::class);
        $one->shouldReceive('getKey')->andReturn(1);

        $two = m::mock(Model::class);
        $two->shouldReceive('getKey')->andReturn(2);

        $three = m::mock(Model::class);
        $three->shouldReceive('getKey')->andReturn(3);

        $c = new Collection([$one, $two, $three]);

        $this->assertEquals($c, $c->except(null));
        $this->assertEquals(new Collection([$one, $three]), $c->except(2));
        $this->assertEquals(new Collection([$one]), $c->except([2, 3]));
    }

    public function testMakeHiddenAddsHiddenOnEntireCollection()
    {
        $c = new Collection([new TestEloquentCollectionModel]);
        $c = $c->makeHidden(['visible']);

        $this->assertEquals(['hidden', 'visible'], $c[0]->getHidden());
    }

    public function testMakeVisibleRemovesHiddenFromEntireCollection()
    {
        $c = new Collection([new TestEloquentCollectionModel]);
        $c = $c->makeVisible(['hidden']);

        $this->assertEquals([], $c[0]->getHidden());
    }

    public function testSetVisibleReplacesVisibleOnEntireCollection()
    {
        $c = new Collection([new TestEloquentCollectionModel]);
        $c = $c->setVisible(['hidden']);

        $this->assertEquals(['hidden'], $c[0]->getVisible());
    }

    public function testSetHiddenReplacesHiddenOnEntireCollection()
    {
        $c = new Collection([new TestEloquentCollectionModel]);
        $c = $c->setHidden(['visible']);

        $this->assertEquals(['visible'], $c[0]->getHidden());
    }

    public function testAppendsAddsTestOnEntireCollection()
    {
        $c = new Collection([new TestEloquentCollectionModel]);
        $c = $c->makeVisible('test');
        $c = $c->append('test');

        $this->assertEquals(['test' => 'test'], $c[0]->toArray());
    }

    public function testMakeVisibleRemovesHiddenAndIncludesVisible()
    {
        $c = new Collection([new TestEloquentCollectionModel]);
        $c = $c->makeVisible('hidden');

        $this->assertEquals([], $c[0]->getHidden());
        $this->assertEquals(['visible', 'hidden'], $c[0]->getVisible());
    }

    public function testQueueableCollectionImplementation()
    {
        $c = new Collection([new TestEloquentCollectionModel, new TestEloquentCollectionModel]);
        $this->assertEquals(TestEloquentCollectionModel::class, $c->getQueueableClass());
    }

    public function testQueueableCollectionImplementationThrowsExceptionOnMultipleModelTypes()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Queueing collections with multiple model types is not supported.');

        $c = new Collection([new TestEloquentCollectionModel, new EloquentTestUserModel]);
        $c->getQueueableClass();
    }

    public function testQueueableRelationshipsReturnsOnlyRelationsCommonToAllModels()
    {
        // This is needed to prevent loading non-existing relationships on polymorphic model collections (#26126)
        $c = new Collection([
            new class extends Model
            {
                public function getQueueableRelations()
                {
                    return ['user'];
                }
            },
            new class extends Model
            {
                public function getQueueableRelations()
                {
                    return ['user', 'comments'];
                }
            },
        ]);

        $this->assertEquals(['user'], $c->getQueueableRelations());
    }

    public function testQueueableRelationshipsIgnoreCollectionKeys()
    {
        $c = new Collection([
            'foo' => new class extends Model
            {
                public function getQueueableRelations()
                {
                    return [];
                }
            },
            'bar' => new class extends Model
            {
                public function getQueueableRelations()
                {
                    return [];
                }
            },
        ]);

        $this->assertEquals([], $c->getQueueableRelations());
    }

    public function testEmptyCollectionStayEmptyOnFresh()
    {
        $c = new Collection;
        $this->assertEquals($c, $c->fresh());
    }

    public function testCanConvertCollectionOfModelsToEloquentQueryBuilder()
    {
        $one = m::mock(Model::class);
        $one->shouldReceive('getKey')->andReturn(1);

        $two = m::mock(Model::class);
        $two->shouldReceive('getKey')->andReturn(2);

        $c = new Collection([$one, $two]);

        $mocBuilder = m::mock(Builder::class);
        $one->shouldReceive('newModelQuery')->once()->andReturn($mocBuilder);
        $mocBuilder->shouldReceive('whereKey')->once()->with($c->modelKeys())->andReturn($mocBuilder);
        $this->assertInstanceOf(Builder::class, $c->toQuery());
    }

    public function testConvertingEmptyCollectionToQueryThrowsException()
    {
        $this->expectException(LogicException::class);

        $c = new Collection;
        $c->toQuery();
    }

    public function testLoadExistsShouldCastBool()
    {
        $this->seedData();
        $user = EloquentTestUserModel::with('articles')->first();
        $user->articles->loadExists('comments');
        $commentsExists = $user->articles->pluck('comments_exists')->toArray();
        $this->assertContainsOnly('bool', $commentsExists);
    }

    public function testWithNonScalarKey()
    {
        $fooKey = new EloquentTestKey('foo');
        $foo = m::mock(Model::class);
        $foo->shouldReceive('getKey')->andReturn($fooKey);

        $barKey = new EloquentTestKey('bar');
        $bar = m::mock(Model::class);
        $bar->shouldReceive('getKey')->andReturn($barKey);

        $collection = new Collection([$foo, $bar]);

        $this->assertCount(1, $collection->only([$fooKey]));
        $this->assertSame($foo, $collection->only($fooKey)->first());

        $this->assertCount(1, $collection->except([$fooKey]));
        $this->assertSame($bar, $collection->except($fooKey)->first());
    }

    public function testFlippingEloquentCollectionReturnsEmptyBaseCollection()
    {
        $this->seedData();
        $c = EloquentTestArticleModel::all()->flip();

        $this->assertEquals(BaseCollection::class, get_class($c));
        $this->assertSame(0, $c->count());
    }

    public function testEloquentCollectionConvertsToBaseCollectionWhenContainsNonModels()
    {
        $this->seedData();
        $a = EloquentTestArticleModel::all();

        $b = $a->chunk(2);
        $this->assertEquals(BaseCollection::class, get_class($b));
        $this->assertEquals(Collection::class, get_class($b->first()));
        $b = $a->chunkWhile(fn ($v) => false);
        $this->assertEquals(BaseCollection::class, get_class($b));
        $this->assertEquals(Collection::class, get_class($b->first()));
        $this->assertEquals(BaseCollection::class, get_class($a->collapse()));
        $this->assertEquals(BaseCollection::class, get_class($a->concat(['foo'])));
        $this->assertEquals(BaseCollection::class, get_class($a->countBy('foo')));
        $this->assertEquals(BaseCollection::class, get_class($a->crossJoin(['a', 'b'])));
        $this->assertEquals(BaseCollection::class, get_class($a->flatMap(fn ($v) => ['foo'])));
        $this->assertEquals(BaseCollection::class, get_class($a->flatten()));
        $b = $a->groupBy('foo');
        $this->assertEquals(BaseCollection::class, get_class($b));
        $this->assertEquals(Collection::class, get_class($b->first()));
        $this->assertEquals(BaseCollection::class, get_class($a->keys()));
        $this->assertEquals(BaseCollection::class, get_class($a->map(fn ($v) => 'foo')));
        $this->assertEquals(BaseCollection::class, get_class($a->mapInto(stdClass::class)));
        $this->assertEquals(BaseCollection::class, get_class($a->chunk(2)->mapSpread(fn ($a, $b) => 'foo')));
        $this->assertEquals(BaseCollection::class, get_class($a->mapToDictionary(fn ($v) => [$v->id => 'foo'])));
        $b = $a->mapToGroups(fn ($v) => ['foo' => $v]);
        $this->assertEquals(BaseCollection::class, get_class($b));
        $this->assertEquals(Collection::class, get_class($b->first()));
        $this->assertEquals(BaseCollection::class, get_class($a->mapWithKeys(fn ($v) => [$v->id => 'foo'])));
        $this->assertEquals(BaseCollection::class, get_class($a->merge(['foo'])));
        $this->assertEquals(BaseCollection::class, get_class($a->mergeRecursive(['foo'])));
        $this->assertEquals(BaseCollection::class, get_class($a->pad(5, 0)));
        $this->assertEquals(BaseCollection::class, get_class($a->pluck('foo')));
        $this->assertEquals(BaseCollection::class, get_class($a->range(1, 5)));
        $b = (clone $a)->replace([0 => 'foo']);
        $this->assertEquals(BaseCollection::class, get_class($b));
        $b = (clone $a)->replaceRecursive(['foo']);
        $this->assertEquals(BaseCollection::class, get_class($b));
        $b = $a->sliding(2);
        $this->assertEquals(BaseCollection::class, get_class($b));
        $this->assertEquals(Collection::class, get_class($b->first()));
        $b = $a->split(2);
        $this->assertEquals(BaseCollection::class, get_class($b));
        $this->assertEquals(Collection::class, get_class($b->first()));
        $b = $a->splitIn(3);
        $this->assertEquals(BaseCollection::class, get_class($b));
        $this->assertEquals(Collection::class, get_class($b->first()));
        $this->assertEquals(BaseCollection::class, get_class($a->union([10 => 'a'])));
        $this->assertEquals(BaseCollection::class, get_class($a->zip(['a', 'b'], ['c', 'd'])));

        $this->assertEquals(BaseCollection::class, get_class(Collection::make([1, 2, 3])));
        $this->assertEquals(BaseCollection::class, get_class(Collection::range(1, 5)));
        $this->assertEquals(BaseCollection::class, get_class(Collection::times(2, fn ($v) => 'foo')));
        $this->assertEquals(BaseCollection::class, get_class(Collection::wrap([1, 2, 3])));
    }

    #[DataProvider('mutationDataProvider')]
    public function testEloquentCollectionNonModelMutationsThrowException(string $method, array $args)
    {
        $this->expectException(UnexpectedValueException::class);

        $a = Collection::make([new EloquentTestUserModel]);

        $a->{$method}(...$args);
    }

    public static function mutationDataProvider(): array
    {
        return [
            ['offsetSet', [null, 'foo']],
            ['prepend', ['foo']],
            ['push', ['foo']],
            ['put', ['foo', 'bar']],
            ['transform', [fn ($v) => 'foo']],
        ];
    }

    /**
     * Helpers...
     */
    protected function seedData()
    {
        $user = EloquentTestUserModel::create(['id' => 1, 'email' => 'taylorotwell@gmail.com']);

        EloquentTestArticleModel::query()->insert([
            ['user_id' => 1, 'title' => 'Another title'],
            ['user_id' => 1, 'title' => 'Another title'],
            ['user_id' => 1, 'title' => 'Another title'],
        ]);

        EloquentTestCommentModel::query()->insert([
            ['article_id' => 1, 'content' => 'Another comment'],
            ['article_id' => 2, 'content' => 'Another comment'],
        ]);
    }

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\ConnectionInterface
     */
    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }
}

class TestEloquentCollectionModel extends Model
{
    protected $guarded = [];
    protected $visible = ['visible'];
    protected $hidden = ['hidden'];

    public function getTestAttribute()
    {
        return 'test';
    }
}

class EloquentTestUserModel extends Model
{
    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;

    public function articles()
    {
        return $this->hasMany(EloquentTestArticleModel::class, 'user_id');
    }
}

class EloquentTestArticleModel extends Model
{
    protected $table = 'articles';
    protected $guarded = [];
    public $timestamps = false;

    public function comments()
    {
        return $this->hasMany(EloquentTestCommentModel::class, 'article_id');
    }
}

class EloquentTestCommentModel extends Model
{
    protected $table = 'comments';
    protected $guarded = [];
    public $timestamps = false;
}

class EloquentTestKey
{
    public function __construct(private readonly string $key)
    {
    }

    public function __toString()
    {
        return $this->key;
    }
}
