<?php

namespace Tatter\Firebase\Test;

use Google\Cloud\Firestore\CollectionReference;
use RuntimeException;

/**
 * Trait to add some helpful utilities for testing with Firestore.
 */
trait FirestoreTrait
{
    /**
     * Completely empty a Firestore database.
     */
    protected function clearFirestore()
    {
        if (ENVIRONMENT !== 'testing') {
            throw new RuntimeException('This feature is only available during testing.'); // @codeCoverageIgnore
        }

        // Get the shared instance
        $db = service('firebase')->firestore->database();

        // Check for an emulated instance
        if ($firestoreEmulatorHost = getenv('FIRESTORE_EMULATOR_HOST')) {
            $projectId = $this->getPrivateProperty($db, 'projectId');
            $deleteUrl = 'http://' . $firestoreEmulatorHost . '/emulator/v1/projects/' . $projectId . '/databases/(default)/documents';

            single_service('curlrequest')->delete($deleteUrl);

            return;
        }

        // Otherwise delete each collection
        foreach ($db->collections() as $collection) {
            $this->deleteCollection($collection);
        }
    }

    /**
     * Remove an entire Firestore Collection, with subcollections.
     *
     * @param CollectionReference|string $collection Collection to delete
     */
    protected function deleteCollection($collection)
    {
        // If a collection name was given then get the reference
        if (is_string($collection)) {
            $collection = service('firebase')->firestore->database()->collection($collection);
        }

        if (! $collection instanceof CollectionReference) {
            return;
        }

        // https://firebase.google.com/docs/firestore/manage-data/delete-data#collections
        $documents = $collection->limit(30)->documents();

        while (! $documents->isEmpty()) {
            foreach ($documents as $document) {
                foreach ($document->reference()->collections() as $subcollection) {
                    $this->deleteCollection($subcollection);
                }

                $document->reference()->delete();
            }
            $documents = $collection->limit(30)->documents();
        }
    }
}
