<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ORM\Relations;

use Miko\Database\ORM\Model;

/**
 * HasMany Relation (1:N, foreign key on the related table)
 */
class HasMany extends HasOneOrMany
{
    /**
     * @return Model[]
     */
    public function getResults(): array
    {
        if ($this->parent->getAttributeValue($this->localKey) === null) {
            return [];
        }

        $this->constrain();
        return $this->query->get();
    }

    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = self::keyOf($model->getAttributeValue($this->localKey));
            $model->setRelation($relation, $key !== null ? ($dictionary[$key] ?? []) : []);
        }

        return $models;
    }
}
