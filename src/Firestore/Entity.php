<?php

namespace Tatter\Firebase\Firestore;

use CodeIgniter\Entity\Entity as FrameworkEntity;
use CodeIgniter\I18n\Time;
use DateTime;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;

abstract class Entity extends FrameworkEntity
{
    protected $dates = [
        'createdAt',
        'updatedAt',
    ];

    /**
     * The originating Document from Firestore.
     */
    private ?DocumentReference $document = null;

    /**
     * Returns the ID of the underlying document, if it exists.
     */
    final public function id(): ?string
    {
        if ($document = $this->document()) {
            return $document->id();
        }

        return $this->attributes['uid'] ?? null;
    }

    /**
     * Gets or sets the originating document reference.
     * Named to avoid attribute get/set magic methods.
     */
    final public function document(?DocumentReference $document = null): ?DocumentReference
    {
        if ($document !== null) {
            $this->document          = $document;
            $this->attributes['uid'] = $document->id();
        }

        return $this->document;
    }

    /**
     * Converts the given item into a Time object.
     * Adds support for Google's Timestamp
     *
     * @param DateTime|int|string|Time|Timestamp $value
     */
    protected function mutateDate($value): Time
    {
        if ($value instanceof Timestamp) {
            // Convert to an int timestamp
            $value = $value->formatForApi()['seconds'];
        }

        return parent::mutateDate($value);
    }
}
