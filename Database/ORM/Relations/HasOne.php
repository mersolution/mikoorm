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
 * HasOne Relation (1:1, foreign key on the related table)
 */
class HasOne extends HasOneOrMany
{
    public function getResults(): ?Model
    {
        if ($this->parent->getAttributeValue($this->localKey) === null) {
            return null;
        }

        $this->constrain();
        return $this->query->first();
    }

    public function match(array $models, array $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        foreach ($models as $model) {
            $key = self::keyOf($model->getAttributeValue($this->localKey));
            $model->setRelation($relation, $key !== null ? ($dictionary[$key][0] ?? null) : null);
        }

        return $models;
    }
}
