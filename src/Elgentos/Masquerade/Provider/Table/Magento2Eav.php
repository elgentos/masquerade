<?php

namespace Elgentos\Masquerade\Provider\Table;

/**
 * Allows column names to be EAV attributes. No options are required.
 *
 * The table name should be the base table for the entity - eg. catalog_product_entity
 *
 */

class Magento2Eav extends Simple
{

    /**
     * @var array of eav_attribute records
     */
    protected $attributes = [];

    protected $entity;

    public function setup()
    {
        // find all EAV attribues for this table:
        $this->entity = $this->db->table('eav_entity_types')->where('entity_table', $this->table['name'])->first();
        if (!$this->entity) {
            throw new \Exception("Table {$this->table['name']} is not associated with EAV entities");
        }
        $attributes = $this->db->table('eav_attribute')->where('entity_type_id', $this->entity->entity_type_id)->get();
        foreach ($attributes as $attribute) {
            $this->attributes[$attribute->attribute_code] = $attribute;
        }
        
        parent::setup();
    }

    protected function _columnExists($name)
    {
        return parent::_columnExists($name) || isset($this->attributes[$name]);
    }

    /**
     * @inheritdoc
     */
    public function update($primaryKey, array $updates)
    {

        // first update the static properties:
        $staticUpdates = array_filter($updates, function ($value, $key) {
            return $this->attributes[$key]->backend_type === 'static';
        }, ARRAY_FILTER_USE_BOTH);

        $this->db->table($this->table['name'])->where($this->table['pk'], $primaryKey)->update($updates);

        // now individually update any EAV tables using $attribute->backend_type to determine table name...
    }

    /**
     * @inheritdoc
     */
    public function query() : \Illuminate\Database\Query\Builder
    {
        $query = parent::query();

        $selects = ["{$this->table['name']}.*"];

        // add any required attributes to the query using joins...
        foreach ($this->columns() as $columnName => $column) {
            $attr = $this->attributes[$columnName] ?? null;
            if (!$attr) {
                continue;
            }
            if ($attr->backend_type === 'static') {
                continue;
            }
            $joinTable = $this->table['name'] . '_' . $attr->backend_type; // only for basic EAV, not things like tier_price
            $query->leftJoin($joinTable, function ($join) use ($joinTable) {
                $join->on("{$this->table['name']}.{$this->table['pk']}", '=', "{$joinTable}.entity_id")
                    ->where("{$joinTable}.attribute_id", '=', $attr->attribute_id);
            });
            $selects[] = "{$joinTable}.value as {$columnName}";
        }

        $query->select(...$selects);

        return $query;
    }
}
