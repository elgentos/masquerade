<?php

namespace Elgentos\Masquerade\Provider\Table;

use \Symfony\Component\Console\Output\OutputInterface;

/**
 * All table providers must inherit this
 *
 * example config:
 *
 * group1:   # the group
 *   table1:  # the entity name
 *     provider: \Elgentos\Masquerade\Provider\Table\YourProviderClass
 *     columns:
 *       cost_price:
 *         ...your usual column formatting goes here...
 *
 * OR, if your provider takes options:
 *
 * group1:
 *   table1:
 *     provider:
 *       class: \My\Custom\Class\Name
 *       option1: "some value"
 *       option2: "some value"
 *
 * In your class, the provider options will be accessible as $this->options['option1'] etc, and table data as $this->table[...]
 *
 * The setup() method will be called before any processing.
 *
 *
 */

abstract class Base
{
    protected $output;
    protected $db;
    protected $table;
    protected $options = [];

    public function __construct(OutputInterface $output, \Illuminate\Database\Connection $db, array $tableData, array $providerData = [])
    {
        $this->output = $output;
        $this->db = $db;
        $this->table = $tableData;
        $this->options = $providerData;
    }

    /**
     * Do any setup or validation work here, eg. extracting details from the database for later use,
     * removing unused columns, etc
     *
     * @return void
     */
    public function setup()
    {
    }

    /**
     * Return the name of the primary key column in the query returned by ->query()
     * @return string
     */
    abstract public function getPrimaryKey();

    /**
     * Return the columns with their config
     * @return array
     */
    public function columns()
    {
        return $this->table['columns'];
    }

    /**
     * @return int The number of rows which will be affected
     */
    abstract public function count();

    /**
     * Update a set of columns for a specific primary key
     * @param string|int Primary Key
     * @param array in the form [column_name => value, ...]
     * @return void
     */
    abstract public function update($primaryKey, array $updates);

    /**
     * Return a query builder which will return the column names returned by $this->columns()
     * It should be ordered by primary key
     *
     * @return \Illuminate\Database\Query\Builder
     */
    abstract public function query() : \Illuminate\Database\Query\Builder;
}
