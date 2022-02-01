<?php

namespace Tests\Support;

use Google\Cloud\Firestore\FirestoreClient;
use Tatter\Firebase\Test\FirestoreTestTrait;
use Tests\Support\Collections\FruitCollection;

/**
 * @internal
 */
abstract class FirestoreTestCase extends TestCase
{
    use FirestoreTestTrait;

    /**
     * @var FirestoreClient
     */
    protected $firestore;

    /**
     * @var FruitCollection
     */
    protected $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = new FruitCollection();
        $this->firestore  = firestore();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->clearFirestore();
    }
}
