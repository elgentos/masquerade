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
        $this->entity = $this->db->table('eav_entity_type')->where('entity_table', $this->table['name'])->first();
        if (!$this->entity) {
            throw new \Exception("Table {$this->table['name']} is not associated with EAV entities");
        }
        $attributes = $this->db->table('eav_attribute')->where('entity_type_id', $this->entity->entity_type_id)->get();
        foreach ($attributes as $attribute) {
            $this->attributes[$attribute->attribute_code] = $attribute;
            if ($attribute->is_unique && isset($this->table['columns']) && isset($this->table['columns'][$attribute->attribute_code])) {
                $this->output->writeln(' - forcing ' . $attribute->attribute_code . ' to be unique');
                $this->table['columns'][$attribute->attribute_code]['unique'] = true;
            }
        }

        // useful data in $this->attributes[$code]:
        // ->attribute_id
        // ->backend_type - gives us the table suffix
        // ->is_unique - we use this to apply the 'unique' faker setting
        // ->is_required - we don't use this, because if it's a required value then it'll already exist in the DB
        
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
        $staticUpdates = array_filter($updates, function ($value, $code) {
            return $this->_isInBaseTable($code);
        }, ARRAY_FILTER_USE_BOTH);

        // only update static values if there are any:
        if (count($staticUpdates)) {
            $this->db->table($this->table['name'])->where($this->table['pk'], $primaryKey)->update($staticUpdates);
        }

        // now individually update any EAV tables using $attribute->backend_type to determine table name:
        // NOTE: for attributes with per-store values (eg. products) this will put the same value in for each store ID
        foreach ($updates as $code => $value) {
            if ($this->_isInBaseTable($code)) {
                continue;
            }
            // determine the table name for this attribute (we're ignoring eav_attribute->backend_table because nothing uses it)
            $table = $this->table['name'] . '_' . $this->attributes[$code]->backend_type;
            // we're assuming the entity ID field is 'entity_id' - could be overridden in eav_entity_type->entity_id_field but unlikely
            // update anything for this entity ID
            $this->db->table($table)
                ->where('entity_id', '=', $primaryKey)
                ->where('attribute_id', '=', $this->attributes[$code]->attribute_id)
                ->update([
                    'value' => $value
                ]);
        }
    }

    protected function _isInBaseTable($attributeCode)
    {
        return !isset($this->attributes[$attributeCode]) || $this->attributes[$attributeCode]->backend_type === 'static';
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
            if ($this->_isInBaseTable($columnName)) {
                continue;
            }
            $attr = $this->attributes[$columnName];
            $joinTable = $this->table['name'] . '_' . $attr->backend_type; // only for basic EAV, not things like tier_price
            $query->leftJoin($joinTable, function ($join) use ($joinTable, $attr) {
                $join->on("{$this->table['name']}.{$this->table['pk']}", '=', "{$joinTable}.entity_id")
                    ->where("{$joinTable}.attribute_id", '=', $attr->attribute_id);
            });
            $selects[] = "{$joinTable}.value as {$columnName}";
        }

        $query->select(...$selects);

        return $query;
    }
}
