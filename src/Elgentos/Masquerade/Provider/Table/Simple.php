<?php

namespace Elgentos\Masquerade\Provider\Table;

use \Symfony\Component\Console\Output\OutputInterface;

/**
 * The default provider - for plain, regular database tables
 *
 * It has options for adding a 'where' clause and a custom query
 *
 * example config:
 *
 * group1:   # the group
 *   table1:  # the entity name
 *     provider: \Elgentos\Masquerade\Provider\Table\Simple # not needed because it's the default
 *     columns:
 *       cost_price:
 *         ...your usual column formatting goes here...
 *
 * group1:
 *   table1:
 *     provider:
 *       class: \Elgentos\Masquerade\Provider\Table\Simple
 *       where: "email is not like '%unit-test-user.com'"
 *
 * Options:
 *
 * where: a string containing a custom 'where' clause - for example, you could exclude data required for unit tests
 * delete: boolean - if true, don't anonymise this table, just delete the records specified
 *     (if no 'where' clause is present, it will try and use the 'truncate' method for speed)
 *
 */

class Simple extends Base
{

    /**
     * Do any setup or validation work here, eg. extracting details from the database for later use,
     * removing unused columns, etc
     *
     * @return void
     */
    public function setup()
    {
        // check the table exists:
        if (!$this->db->getSchemaBuilder()->hasTable($this->table['name'])) {
            throw new \Exception('Table ' . $this->table['name'] . ' does not exist.');
        }

        if (empty($this->table['pk'])) {
            throw new \Exception("Table {$this->table['name']} has no primary key - use 'pk:' in the config");
        }

        if (empty($this->table['columns'])) {
            $this->table['columns'] = [];
        }

        foreach ($this->table['columns'] as $columnName => $columnData) {
            if (!$this->_columnExists($columnName)) {
                unset($this->table['columns'][$columnName]);
                $this->output->writeln('Column ' . $columnName . ' in table ' . $this->table['name'] . ' does not exist; skip it.');
            }
        }

        if (array_get($this->options, 'delete', false)) {
            if (array_get($this->options, 'where', null)) {
                $this->query()->delete();
            } else {
                $this->query()->truncate();
            }
        }

        // Null columns before run to avoid integrity constrains errors
        foreach ($this->table['columns'] as $columnName => $columnData) {
            if (array_get($columnData, 'nullColumnBeforeRun', false)) {
                $this->query()->update([$columnName => null]);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function columns()
    {
        return $this->table['columns'];
    }

    protected function _columnExists($name)
    {
        return $this->db->getSchemaBuilder()->hasColumn($this->table['name'], $name);
    }

    /**
     * @inheritdoc
     */
    public function update($primaryKey, array $updates)
    {
        $this->db->table($this->table['name'])->where($this->table['pk'], $primaryKey)->update($updates);
    }

    /**
     * @inheritdoc
     */
    public function query() : \Illuminate\Database\Query\Builder
    {
        $query = $this->db->table($this->table['name'])->orderBy($this->table['pk']);

        $where = array_get($this->options, 'where', null);
        if ($where) {
            $query->whereRaw($where);
        }

        return $query;
    }
}
