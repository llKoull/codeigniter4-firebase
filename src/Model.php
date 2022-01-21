<?php

namespace Tatter\Firebase;

use CodeIgniter\Database\Exceptions\DataException;
use CodeIgniter\Exceptions\ModelException;
use CodeIgniter\Validation\ValidationInterface;
use Config\Services;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\FieldValue;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Query;
use RuntimeException;

/**
 * Class Model
 *
 * This is a faux model provided for convenience so projects using an intermediary
 * version of this Firestore module can fake having a database driver.
 * Be sure you know the limitations of this model before using it.
 */
class Model
{
    /**
     * Name of database table
     *
     * @var string
     */
    protected $table;

    /**
     * The table's primary key.
     *
     * @var string
     */
    protected $primaryKey = 'uid';

    /**
     * The format that the results should be returned as.
     * Will be overridden if the as* methods are used.
     *
     * @var string
     */
    protected $returnType = '\Tatter\Firebase\Entity';

    /**
     * If this model should use "softDeletes" and
     * simply set a date when rows are deleted, or
     * do hard deletes.
     *
     * @var bool
     */
    protected $useSoftDeletes = false;

    /**
     * An array of field names that are allowed
     * to be set by the user in inserts/updates.
     *
     * @var array
     */
    protected $allowedFields = [];

    /**
     * If true, will set created_at, and updated_at
     * values during insert and update routines.
     *
     * @var bool
     */
    protected $useTimestamps = false;

    /**
     * The type of column that created_at and updated_at
     * are expected to be.
     *
     * Allowed: 'datetime', 'date', 'int'
     *
     * @var string
     */
    protected $dateFormat = 'datetime';

    //--------------------------------------------------------------------

    /**
     * The column used for insert timestamps
     *
     * @var string
     */
    protected $createdField = 'createdAt';

    /**
     * The column used for update timestamps
     *
     * @var string
     */
    protected $updatedField = 'updatedAt';

    /**
     * Used by withDeleted to override the
     * model's softDelete setting.
     *
     * @var bool
     */
    protected $tempUseSoftDeletes;

    /**
     * The column used to save soft delete state
     *
     * @var string
     */
    protected $deletedField = 'deletedAt';

    /**
     * Used by asArray and asObject to provide
     * temporary overrides of model default.
     *
     * @var string
     */
    protected $tempReturnType;

    /**
     * Whether we should limit fields in inserts
     * and updates to those available in $allowedFields or not.
     *
     * @var bool
     */
    protected $protectFields = true;

    /**
     * Database Connection
     *
     * @var FirestoreClient
     */
    protected $db;

    /**
     * Query Builder object
     *
     * @var CollectionReference|Query
     */
    protected $builder;

    /**
     * Array of document references from the last actual Firestore call
     *
     * @var array
     */
    protected $documents;

    /**
     * Whether this model represents a collection group
     *
     * @var bool
     */
    protected $grouped = false;

    /**
     * Rules used to validate data in insert, update, and save methods.
     * The array must match the format of data passed to the Validation
     * library.
     *
     * @var array
     */
    protected $validationRules = [];

    /**
     * Contains any custom error messages to be
     * used during data validation.
     *
     * @var array
     */
    protected $validationMessages = [];

    /**
     * Skip the model's validation. Used in conjunction with skipValidation()
     * to skip data validation for any future calls.
     *
     * @var bool
     */
    protected $skipValidation = false;

    /**
     * Whether rules should be removed that do not exist
     * in the passed in data. Used between inserts/updates.
     *
     * @var bool
     */
    protected $cleanValidationRules = true;

    /**
     * Our validator instance.
     *
     * @var ValidationInterface
     */
    protected $validation;

    //--------------------------------------------------------------------

    /**
     * Error messages from the last call
     *
     * @var array
     */
    protected $errors = [];

    /**
     * @var string
     */
    protected $insertID;

    /**
     * Model constructor.
     *
     * @param FirestoreClient     $db
     * @param ValidationInterface $validation
     */
    public function __construct(?FirestoreClient &$db = null, ?ValidationInterface $validation = null)
    {
        if ($db instanceof FirestoreClient) {
            $this->db = &$db;
        } else {
            $this->db = Services::firebase()->firestore->database();
        }

        $this->tempReturnType     = $this->returnType;
        $this->tempUseSoftDeletes = $this->useSoftDeletes;

        if (null === $validation) {
            $validation = Services::validation(null, false);
        }

        $this->validation = $validation;
    }

    /**
     * Creates a reference to a document (that may or may not exist)
     * in this collection.
     * Helpful for creating references to add to other documents.
     */
    public function reference(string $uid): DocumentReference
    {
        return $this->builder(null, true)->document($uid); // @phpstan-ignore-line
    }

    //--------------------------------------------------------------------
    // CORE COLLECTION
    //--------------------------------------------------------------------

    /**
     * WHERE
     *
     * Adds a "where" to the query
     *
     * @param mixed $str
     * @param mixed $value
     *
     * @return $this
     */
    public function where($str, $value = null)
    {
        [$key, $op, $val] = $this->parseWhere($str);

        $value ??= $val;

        $this->builder = $this->builder()->where($key, $op, $value);

        return $this;
    }

    /**
     * WHERE IN
     *
     * Adds a "where in" to the query
     *
     * @param mixed $key
     * @param mixed $values
     *
     * @return $this
     */
    public function whereIn($key, $values)
    {
        $this->builder = $this->builder()->where($key, 'array-contains', $values);

        return $this;
    }

    //--------------------------------------------------------------------

    /**
     * Retrieve the results of the query in array format and store them in $rowData.
     *
     * @param int  $limit  The limit clause
     * @param int  $offset The offset clause
     * @param bool $reset  Do we want to clear query builder values?
     *
     * @return $this
     */
    public function get(?int $limit = null, int $offset = 0, bool $reset = true)
    {
        // Retrieve the documents from the collection
        $snapshot = $this->builder()->documents();

        // If nothing matched then we're done
        $this->documents = $snapshot->isEmpty() ? [] : $snapshot->rows();

        return $reset ? $this->reset() : $this;
    }

    //--------------------------------------------------------------------

    /**
     * ORDER BY
     *
     * @param string $direction ASC, DESC or RANDOM
     * @param bool   $escape
     *
     * @return $this
     */
    public function orderBy(string $orderBy, string $direction = 'ASC', ?bool $escape = null)
    {
        $this->builder = $this->builder()->orderBy($orderBy, $direction);

        return $this;
    }

    /**
     * LIMIT
     *
     * @param int $value  LIMIT value
     * @param int $offset OFFSET value
     *
     * @return $this
     */
    public function limit(?int $value = null, ?int $offset = 0)
    {
        $this->builder = $this->builder()->limit($value);

        return $this;
    }

    //--------------------------------------------------------------------

    /**
     * Format the results of the query. Returns an array of
     * individual data rows, which can be either an 'array', an
     * 'object', or a custom class name.
     *
     * @param string $type The row type. Either 'array', 'object', or a class name to use
     */
    public function getResult(string $type = 'object'): array
    {
        // Bail on missing or empty returns
        if (empty($this->documents)) {
            return [];
        }

        // Extract the actual data into arrays
        $result = [];

        foreach ($this->documents as $document) {
            // Get the meta fields
            $row = [
                $this->primaryKey   => $document->id(),
                $this->createdField => $document->createTime(),
                $this->updatedField => $document->updateTime(),
            ];

            // Add the array of data
            $row = array_merge($row, $document->data());

            // Array requests are ready to go
            if ($type === 'array') {
                $result[] = $row;
            }
            if ($type === 'object') {
                $result[] = (object) $row;
            }
            // If it is an Entity then use the native constructor fill
            elseif (is_a($type, '\CodeIgniter\Entity\Entity', true)) {
                $entity = new $type($row);

                // If it is our entity then inject the DocumentRerefence
                if ($entity instanceof Entity) {
                    $entity->document($document->reference());
                }

                $result[] = $entity;
            }
            // Not sure what this will be but assign each property
            else {
                $object = new $type();

                foreach ($row as $key => $value) {
                    $object->{$key} = $value;
                }
                $result[] = $object;
            }
        }

        return $result;
    }

    //--------------------------------------------------------------------
    // CRUD & FINDERS
    //--------------------------------------------------------------------

    /**
     * Fetches the row of database from $this->table with a primary key
     * matching $id.
     *
     * @param array|mixed|null $id One primary key or an array of primary keys
     *
     * @return array|object|null The resulting row of data, or null.
     */
    public function find($id = null)
    {
        if (is_array($id)) {
            if ($this->tempUseSoftDeletes === true) {
                $this->where($this->deletedField, null);
            }

            $result = $this->whereIn($this->primaryKey, $id)->getResult($this->tempReturnType);
        } elseif (is_numeric($id) || is_string($id)) {
            // Make sure we use the CollectionReference to get the Document directly
            if (($builder = $this->builder()) instanceof Query) {
                $builder = $this->db->collection($this->table);
            }

            $document = $builder->document($id)->snapshot();

            if (! $document->exists()) {
                $result = null;
            } else {
                $this->documents = [$document];
                $result          = $this->getResult($this->tempReturnType);
                $result          = $result ? $result[0] : null;
            }
        } else {
            return $this->findAll();
        }

        // Clear this execution's parameters
        $this->reset();

        return $result;
    }

    /**
     * Works with the current Collection Reference instance to return
     * all results, while optionally limiting them.
     *
     * @return array|null
     */
    public function findAll(int $limit = 0, int $offset = 0)
    {
        if ($this->tempUseSoftDeletes === true) {
            $this->where($this->deletedField, null);
        }

        $result = $this->get()->getResult($this->tempReturnType);

        // Clear this execution's parameters
        $this->reset();

        return $result;
    }

    //--------------------------------------------------------------------

    /**
     * Inserts data into the current collection.
     *
     * @param array|object $data
     * @param bool         $returnID Whether insert ID should be returned or not.
     *
     * @return bool|int|string
     */
    public function insert($data = null, bool $returnID = true)
    {
        if (empty($data)) {
            throw DataException::forEmptyDataset('insert');
        }

        // Convert to an array
        if (is_object($data)) {
            $data = self::classToArray($data);
        }

        // Check if an ID was provided
        $id = $data[$this->primaryKey] ?? false;

        // Must be called first so we don't
        // strip out created_at values.
        $data = $this->doProtectFields($data);

        // Make sure we have a fresh reference
        $this->reset();

        // If an ID was provided use 'set'
        if ($id) {
            $document = $this->builder()->document($id); // @phpstan-ignore-line
            $result   = (bool) $document->set($data);
        }
        // Otherwise add the documentElement
        else {
            $document = $this->builder()->add($data); // @phpstan-ignore-line
            $result   = (bool) $document;
        }

        // If insertion failed the we're done
        if (! $result) {
            return false;
        }

        // Save the insert ID
        $this->insertID = $document->id();

        // Set timestamps
        $timestamps = [];
        if ($this->useTimestamps && ! empty($this->createdField) && ! array_key_exists($this->createdField, $data)) {
            $timestamps[] = ['path' => $this->createdField, 'value' => FieldValue::serverTimestamp()];
        }
        if ($this->useTimestamps && ! empty($this->updatedField) && ! array_key_exists($this->updatedField, $data)) {
            $timestamps[] = ['path' => $this->updatedField, 'value' => FieldValue::serverTimestamp()];
        }
        if (! empty($timestamps)) {
            $document->update($timestamps);
        }

        return $returnID ? $this->insertID : true;
    }

    /**
     * Updates a document in the current collection.
     *
     * @param array|object $data
     */
    public function update(string $id, $data): bool
    {
        if (empty($data)) {
            throw DataException::forEmptyDataset('insert');
        }

        // Convert to an array
        if (is_object($data)) {
            $data = self::classToArray($data);
        }

        // Must be called first so we don't strip out updated_at values
        $data = $this->doProtectFields($data);

        // Update timestamp
        if ($this->useTimestamps && ! empty($this->updatedField) && ! array_key_exists($this->updatedField, $data)) {
            $data[$this->updatedField] = FieldValue::serverTimestamp();
        }

        // Build the paths
        $paths = [];

        foreach ($data as $key => $value) {
            $paths[] = ['path' => $key, 'value' => $value];
        }

        // Prep the document
        $document = $this->builder()->document($id); // @phpstan-ignore-line

        // Clear this execution's parameters
        $this->reset();

        return (bool) $document->update($paths);
    }

    /**
     * Deletes a document in the current collection.
     * Does not remove subcollections!
     */
    public function delete(string $id): bool
    {
        if (empty($id)) {
            throw DataException::forEmptyDataset('id');
        }

        if ($this->builder() instanceof CollectionReference) {
            // Prep the document
            $document = $this->builder()->document($id);
        }
        // Otherwise assume a Query
        else {
            $document = $this->where($this->primaryKey, $id)->findAll()[0];
        }

        // Clear this execution's parameters
        $this->reset();

        return (bool) $document->delete();
    }

    //--------------------------------------------------------------------
    // Utility
    //--------------------------------------------------------------------

    /**
     * Resets model state, e.g. between completed queries.
     *
     * @return $this
     */
    public function reset(): self
    {
        $this->builder(null, true);

        $this->tempReturnType     = $this->returnType;
        $this->tempUseSoftDeletes = $this->useSoftDeletes;

        return $this;
    }

    /**
     * Provides a shared instance of the collection reference or a query in process.
     *
     * @param string $table
     * @param bool   $refresh Resets the builder back to a clean CollectionReference
     *
     * @throws ModelException ;
     *
     * @return CollectionReference|Query
     */
    public function builder(?string $table = null, bool $refresh = false)
    {
        if (! $refresh && $this->builder instanceof Query) {
            return $this->builder;
        }

        // We're going to force a primary key to exist
        // so we don't have overly convoluted code,
        // and future features are likely to require them.
        if (empty($this->primaryKey)) {
            throw ModelException::forNoPrimaryKey(static::class);
        }

        $table = empty($table) ? $this->table : $table;

        // Ensure we have a good client
        if (! $this->db instanceof FirestoreClient) {
            $this->db = Services::firebase()->firestore->database();
        }

        $this->builder = $this->grouped ? $this->db->collectionGroup($table) : $this->db->collection($table);

        return $this->builder;
    }

    /**
     * Sets a new $builder (usually a subcollection)
     *
     * @return $this
     */
    public function setBuilder(CollectionReference $collection): self
    {
        $this->builder = $collection;
        $this->grouped = false;
        $this->table   = $collection->name();

        return $this;
    }

    // --------------------------------------------------------------------

    /**
     * Parses the first parameter to a where method into its logical parts
     *
     * @return array [key, operator, value]
     */
    protected function parseWhere(string $str): array
    {
        $parts = explode(' ', $str);

        switch (count($parts)) {
            // where('name', 'Roland')
            case 1:
                return [$parts[0], '=', null];

            // where('age >', 999)
            case 2:
                return [$parts[0], $parts[1], null];

            // where('status != "bogus"')
            case 3:
                return [$parts[0], $parts[1], $parts[2]];

            default:
                throw new RuntimeException('Unable to parse where clause: ' . $str);
        }
    }

    //--------------------------------------------------------------------
    /**
     * Ensures that only the fields that are allowed to be updated
     * are in the data array.
     *
     * Used by insert() and update() to protect against mass assignment
     * vulnerabilities.
     *
     * @throws DataException
     */
    protected function doProtectFields(array $data): array
    {
        if ($this->protectFields === false) {
            return $data;
        }

        if (empty($this->allowedFields)) {
            throw DataException::forInvalidAllowedFields(static::class);
        }

        if (is_array($data) && count($data)) {
            foreach (array_keys($data) as $key) {
                if (! in_array($key, $this->allowedFields, true)) {
                    unset($data[$key]);
                }
            }
        }

        return $data;
    }

    /**
     * Sets $useSoftDeletes value so that we can temporarily override
     * the softdeletes settings. Can be used for all find* methods.
     *
     * @param bool $val
     *
     * @return $this
     */
    public function withDeleted($val = true): self
    {
        $this->tempUseSoftDeletes = ! $val;

        return $this;
    }

    //--------------------------------------------------------------------

    /**
     * Return the total number of results (safe up to medium-large datasets).
     */
    public function countAllResults(bool $reset = true, bool $test = false): int
    {
        // Retrieve the documents from the collection
        $snapshot = $this->builder()->documents();

        return $snapshot->isEmpty() ? 0 : $snapshot->size();
    }

    //--------------------------------------------------------------------

    /**
     * Takes a class and returns an array of it's public and protected
     * properties as an array suitable for use in creates and updates.
     *
     * @param object|string $data
     * @param string|null   $primaryKey
     */
    public static function classToArray($data, $primaryKey = null, string $dateFormat = 'datetime'): array
    {
        return method_exists($data, 'toRawArray') ? $data->toRawArray() : (array) $data;
    }

    /**
     * Get and clear any error messsages
     *
     * @return array Any error messages from the last operation
     */
    public function errors(): array
    {
        $errors       = $this->errors;
        $this->errors = [];

        return $errors;
    }

    //--------------------------------------------------------------------

    /**
     * Provide access to underlying properties consistent with CodeIgniter\Model.
     *
     * @return mixed
     */
    public function __get(string $name)
    {
        if (property_exists($this, $name)) {
            return $this->{$name};
        }
        if (isset($this->db->{$name})) {
            return $this->db->{$name};
        }
        if (isset($this->builder()->{$name})) {
            return $this->builder()->{$name};
        }

        return null;
    }

    /**
     * Provide access to underlying properties consistent with CodeIgniter\Model.
     */
    public function __isset(string $name): bool
    {
        if (property_exists($this, $name)) {
            return true;
        }
        if (isset($this->db->{$name})) {
            return true;
        }

        return isset($this->builder()->{$name});
    }
}
