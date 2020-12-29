<?php

namespace \Elgentos\Masquerade\Provider\Table;

/**
 * Allows column names to be EAV attributes. No options are required.
 *
 * The table name should be the base table for the entity - eg. catalog_product_entity
 *
 */

class Magento2Eav extends Simple {

    /**
     * @var array of eav_attribute records
     */
    protected array $attributes = [];

    protected stdClass $entity;

    public function setup()
    {
        if (array_get($this->options, 'delete', false)) {
            if (array_get($this->options, 'where', null)) {
                $this->query()->delete(); // if it's an EAV table, this should cascade
            } else {
                $this->query()->truncate();
            }
        }

        // check the table exists:
            if (!$this->db->getSchemaBuilder()->hasTable($table['name'])) {
            throw new \Exception('Table ' . $table['name'] . ' does not exist.');
            }

        // find all EAV attribues for this table:
        $this->entity = $this->db->table('eav_entity_types')->where('entity_table', $this->table['name'])->first();
        if (!$this->entity) {
            throw new \Exception("Table {$this->table['name']} is not associated with EAV entities");
        }
        $attributes = $this->db->table('eav_attribute')->where('entity_type_id', $this->entity->entity_type_id)->get();
        foreach($attributes as $attribute) {
            $this->attributes[$attribute->attribute_code] = $attribute;
        }
        
    }

    /**
     * @inheritdoc
     */
    public function update($primaryKey, array $updates) {

        // first update the static properties:
        $staticUpdates = array_filter($updates, function($value, $key) use($this) {
            return $this->attributes[$key]->backend_type === 'static';
        }, ARRAY_FILTER_USE_BOTH);

        $this->db->table($this->table['name'])->where($this->table['pk'], $primaryKey)->update($updates);

        // now individually update any EAV tables using $attribute->backend_type to determine table name...
    }

    /**
     * @inheritdoc
     */
    public function query() : \Illuminate\Database\Query\Builder {
        $query = $this->db->table($this->table['name']);

        $where = array_get($this->options, 'where', null);
        if ($where) {
            $query->whereRaw($where);
        }

        // add any required attributes to the query using joins...

        return $query;
    }
}

