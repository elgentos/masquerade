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
    const PRIMARY_KEYS = ['entity_id', 'id', 'ID'];

    protected $primaryKey = null;
    protected $orderBy = null;

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

        if (empty($this->table['columns'])) {
            $this->table['columns'] = [];
        }

        foreach ($this->table['columns'] as $columnName => $columnData) {
            if (!$this->_columnExists($columnName)) {
                unset($this->table['columns'][$columnName]);
                $this->output->writeln('Column ' . $columnName . ' in table ' . $this->table['name'] . ' does not exist; skip it.');
            }
        }

        $this->getPrimaryKey(); // verify it exists
        $this->orderBy = $this->primaryKey; // default, could be overridden by a subclass

        if (array_get($this->options, 'delete', false)) {
            $this->output->writeln(' - removing the selected records');
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

        // warn if 'where' includes a nulled column:
        $where = array_get($this->options, 'where', null);
        if ($where) {
            $this->output->writeln(" - where {$where}");
            foreach ($this->table['columns'] as $columnName => $columnData) {
                if (array_get($columnData, 'nullColumnBeforeRun', false)) {
                    if (strstr($where, $columnName) !== false) {
                        $this->output->writeln("WARNING - your 'where' mentions a field which is set to nullColumnBeforeRun - ensure your 'where' includes ' or `{$columnName}` is null'");
                    }
                }
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getPrimaryKey()
    {
        if (!$this->primaryKey) {
            $this->primaryKey = $this->table['pk'] ?? null;
            if (!$this->primaryKey) {
                foreach (self::PRIMARY_KEYS as $possibleKey) {
                    if ($this->_columnExists($possibleKey)) {
                        $this->primaryKey = $possibleKey;
                    }
                }
            }
            if (!$this->primaryKey || !$this->_columnExists($this->primaryKey)) {
                throw new \Exception("Table {$this->table['name']} primary key could not be determined - use 'pk:' in the config");
            }
            $this->output->writeln(" - using primary key '{$this->primaryKey}'");
        }
        return $this->primaryKey;
    }

    /**
     * @inheritdoc
     */
    public function columns()
    {
        return $this->table['columns'];
    }

    /**
     * @inheritdoc
     */
    public function count()
    {
        return $this->query()->count(); // works unless you're using groupBy in the query
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
        $this->db->table($this->table['name'])->where($this->primaryKey, $primaryKey)->update($updates);
    }

    /**
     * @inheritdoc
     */
    public function query() : \Illuminate\Database\Query\Builder
    {
        $query = $this->db->table($this->table['name'])->orderBy($this->orderBy);

        $where = array_get($this->options, 'where', null);
        if ($where) {
            $query->whereRaw($where);
        }

        return $query;
    }
}
