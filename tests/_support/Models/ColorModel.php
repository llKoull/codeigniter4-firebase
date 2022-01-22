<?php

namespace Tests\Support\Models;

use Faker\Generator;
use Tatter\Firebase\Model;

class ColorModel extends Model
{
    protected $table          = 'colors';
    protected $primaryKey     = 'uid';
    protected $returnType     = 'object';
    protected $useTimestamps  = true;
    protected $skipValidation = true;
    protected $allowedFields  = ['name', 'hex'];

    /**
     * Whether this model represents a collection group
     *
     * @var bool
     */
    protected $grouped = true;

    /**
     * Faked data for Fabricator.
     */
    public function fake(Generator &$faker): object
    {
        return (object) [
            'name' => $faker->colorName,
            'hex'  => $faker->hexcolor,
        ];
    }
}
