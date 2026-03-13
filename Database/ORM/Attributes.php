<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

namespace Miko\Database\ORM;

use Attribute;

/**
 * Table Attribute - Specifies the database table name for a model
 * 
 * Usage: #[Table('tblUsers')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Table
{
    public function __construct(
        public string $name,
        public ?string $schema = null
    ) {}
}

/**
 * PrimaryKey Attribute - Specifies the primary key column
 * 
 * Usage: #[PrimaryKey(autoIncrement: true)]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class PrimaryKey
{
    public function __construct(
        public bool $autoIncrement = true
    ) {}
}

/**
 * Column Attribute - Specifies column properties
 * 
 * Usage: #[Column('email', type: 'VARCHAR', length: 100, nullable: false)]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Column
{
    public function __construct(
        public ?string $name = null,
        public ?string $type = null,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public bool $nullable = true,
        public mixed $default = null,
        public bool $unique = false,
        public bool $unsigned = false
    ) {}
}

/**
 * Ignore Attribute - Marks a property to be ignored by ORM
 * 
 * Usage: #[Ignore]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Ignore
{
}

/**
 * CreatedAt Attribute - Specifies created_at timestamp column
 * 
 * Usage: #[CreatedAt]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class CreatedAt
{
}

/**
 * UpdatedAt Attribute - Specifies updated_at timestamp column
 * 
 * Usage: #[UpdatedAt]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class UpdatedAt
{
}

/**
 * SoftDelete Attribute - Specifies soft delete column (deleted_at)
 * 
 * Usage: #[SoftDelete]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class SoftDelete
{
}

/**
 * HasOne Attribute - Specifies a HasOne relationship (1:1)
 * 
 * Usage: #[HasOne(Company::class, foreignKey: 'CompanyId')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasOne
{
    public function __construct(
        public string $related,
        public ?string $foreignKey = null,
        public string $localKey = 'Id'
    ) {}
}

/**
 * HasMany Attribute - Specifies a HasMany relationship (1:N)
 * 
 * Usage: #[HasMany(Invoice::class, foreignKey: 'CustomerId')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class HasMany
{
    public function __construct(
        public string $related,
        public ?string $foreignKey = null,
        public string $localKey = 'Id'
    ) {}
}

/**
 * BelongsTo Attribute - Specifies a BelongsTo relationship (N:1)
 * 
 * Usage: #[BelongsTo(Company::class, foreignKey: 'CompanyId')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsTo
{
    public function __construct(
        public string $related,
        public ?string $foreignKey = null,
        public string $ownerKey = 'Id'
    ) {}
}

/**
 * BelongsToMany Attribute - Specifies a BelongsToMany relationship (N:M)
 * 
 * Usage: #[BelongsToMany(Role::class, pivotTable: 'user_roles')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class BelongsToMany
{
    public function __construct(
        public string $related,
        public string $pivotTable,
        public ?string $foreignPivotKey = null,
        public ?string $relatedPivotKey = null,
        public string $parentKey = 'Id',
        public string $relatedKey = 'Id'
    ) {}
}

/**
 * Index Attribute - Creates an index on the column
 * 
 * Usage: #[Index('idx_email')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Index
{
    public function __construct(
        public ?string $name = null
    ) {}
}

/**
 * Unique Attribute - Creates a unique constraint on the column
 * 
 * Usage: #[Unique]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Unique
{
    public function __construct(
        public ?string $name = null
    ) {}
}

/**
 * ForeignKey Attribute - Creates a foreign key constraint
 * 
 * Usage: #[ForeignKey(table: 'tblCompanies', column: 'Id', onDelete: 'CASCADE')]
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class ForeignKey
{
    public function __construct(
        public string $table,
        public string $column = 'Id',
        public string $onDelete = 'CASCADE',
        public string $onUpdate = 'CASCADE'
    ) {}
}
