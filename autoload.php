<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * See LICENSE file for details.
 *
 * @contact hello@mersolution.com
 * @website https://www.mersolution.com/
 */

/**
 * Miko Framework Autoloader
 */

$entitiesPath = __DIR__;

// ============================================================================
// CORE
// ============================================================================
require_once $entitiesPath . "/Core/Config.php";
require_once $entitiesPath . "/Core/Database/DatabaseConfig.php";
require_once $entitiesPath . "/Core/Exceptions/FrameworkException.php";
require_once $entitiesPath . "/Core/Http/JsonResponse.php";
require_once $entitiesPath . "/Core/Http/Cors.php";
require_once $entitiesPath . "/Core/Http/HttpClient.php";
require_once $entitiesPath . "/Core/Http/SoapClient.php";
require_once $entitiesPath . "/Core/Http/XmlClient.php";
require_once $entitiesPath . "/Core/Http/JwtHelper.php";
require_once $entitiesPath . "/Core/Helpers/StringHelper.php";
require_once $entitiesPath . "/Core/Helpers/DateHelper.php";
require_once $entitiesPath . "/Core/Helpers/CodeGenerator.php";
require_once $entitiesPath . "/Core/Validation/Validator.php";

// ============================================================================
// DATABASE CORE
// ============================================================================
require_once $entitiesPath . "/Database/Exceptions/DatabaseException.php";
require_once $entitiesPath . "/Database/Exceptions/QueryException.php";
require_once $entitiesPath . "/Database/StatementInterface.php";
require_once $entitiesPath . "/Database/ResultInterface.php";
require_once $entitiesPath . "/Database/ConnectionInterface.php";
require_once $entitiesPath . "/Database/DatabaseInterface.php";
require_once $entitiesPath . "/Database/Statement.php";
require_once $entitiesPath . "/Database/Result.php";
require_once $entitiesPath . "/Database/Connection.php";

// ============================================================================
// DB FACADE
// ============================================================================
require_once $entitiesPath . "/Database/DB.php";
require_once $entitiesPath . "/Database/DbConfig.php";

// ============================================================================
// QUERY BUILDER
// ============================================================================
require_once $entitiesPath . "/Database/Query/QueryBuilderInterface.php";
require_once $entitiesPath . "/Database/Query/QueryInterface.php";
require_once $entitiesPath . "/Database/Query/QueryBuilder.php";
require_once $entitiesPath . "/Database/Query/FluentQueryBuilder.php";
require_once $entitiesPath . "/Database/Query/RawQuery.php";

// ============================================================================
// LOGGING
// ============================================================================
require_once $entitiesPath . "/Database/Log/QueryLogger.php";
require_once $entitiesPath . "/Log/Logger.php";

// ============================================================================
// CACHE
// ============================================================================
require_once $entitiesPath . "/Database/Cache/QueryCache.php";
require_once $entitiesPath . "/Cache/ApcuCache.php";

// ============================================================================
// SECURITY + LIBRARY
// ============================================================================
require_once $entitiesPath . "/Security/FormCrypt.php";
require_once $entitiesPath . "/Library/Crypto.php";
require_once $entitiesPath . "/Library/Security.php";
require_once $entitiesPath . "/Library/TextHelper.php";

// ============================================================================
// DATABASE DRIVERS
// ============================================================================
require_once $entitiesPath . "/Database/Drivers/DriverInterface.php";
require_once $entitiesPath . "/Database/Drivers/MySqlDriver.php";
require_once $entitiesPath . "/Database/Drivers/PostgreSqlDriver.php";
require_once $entitiesPath . "/Database/Drivers/SqliteDriver.php";
require_once $entitiesPath . "/Database/Drivers/SqlServerDriver.php";
require_once $entitiesPath . "/Database/Drivers/DriverFactory.php";

// ============================================================================
// MIGRATION
// ============================================================================
require_once $entitiesPath . "/Database/Migration/TableBuilder.php";
require_once $entitiesPath . "/Database/Migration/Schema.php";
require_once $entitiesPath . "/Database/Migration/Migration.php";
require_once $entitiesPath . "/Database/Migration/Migrator.php";

// ============================================================================
// ORM
// ============================================================================
require_once $entitiesPath . "/Database/ORM/Attributes.php";
require_once $entitiesPath . "/Database/ORM/ModelMetadata.php";
require_once $entitiesPath . "/Database/ORM/Observer.php";
require_once $entitiesPath . "/Database/ORM/Events/ModelEvent.php";
require_once $entitiesPath . "/Database/ORM/Traits/HasEvents.php";
require_once $entitiesPath . "/Database/ORM/Traits/HasTimestamps.php";
require_once $entitiesPath . "/Database/ORM/Traits/HasMigration.php";
require_once $entitiesPath . "/Database/ORM/Traits/SoftDeletes.php";
require_once $entitiesPath . "/Database/ORM/QueryBuilder.php";
require_once $entitiesPath . "/Database/ORM/Model.php";
require_once $entitiesPath . "/Database/ORM/Relations/Relation.php";
require_once $entitiesPath . "/Database/ORM/Relations/HasOne.php";
require_once $entitiesPath . "/Database/ORM/Relations/HasMany.php";
require_once $entitiesPath . "/Database/ORM/Relations/BelongsTo.php";
require_once $entitiesPath . "/Database/ORM/Relations/BelongsToMany.php";
require_once $entitiesPath . "/Database/ORM/MikoSet.php";
require_once $entitiesPath . "/Database/ORM/DbContext.php";
require_once $entitiesPath . "/Database/ORM/BulkOperations.php";
require_once $entitiesPath . "/Database/ORM/JsonColumn.php";
require_once $entitiesPath . "/Database/ORM/ValidationAttributes.php";
require_once $entitiesPath . "/Database/ORM/Transaction.php";
require_once $entitiesPath . "/Database/ORM/ConnectionPool.php";

// ============================================================================
// OPTIONAL MODULES
// ============================================================================
require_once $entitiesPath . "/Database/Transaction/TransactionManager.php";
require_once $entitiesPath . "/Database/Bulk/BulkInsert.php";
require_once $entitiesPath . "/Database/Bulk/BulkUpdate.php";
require_once $entitiesPath . "/Database/Seeders/Seeder.php";
require_once $entitiesPath . "/Database/Seeders/SeederRunner.php";
require_once $entitiesPath . "/Database/Factories/Factory.php";
require_once $entitiesPath . "/Database/Pagination/Paginator.php";
require_once $entitiesPath . "/Database/Monitor/HealthCheck.php";
require_once $entitiesPath . "/Database/Monitor/ConnectionStats.php";

// ============================================================================
// CONNECTION POOL + DATABASE MANAGER
// ============================================================================
require_once $entitiesPath . "/Database/ConnectionPool/ConnectionPoolInterface.php";
require_once $entitiesPath . "/Database/ConnectionPool/ConnectionPool.php";
require_once $entitiesPath . "/Database/DatabaseManager.php";

// ============================================================================
// CARGO INTEGRATIONS (optional; skipped if folder missing)
// ============================================================================
$cargoBootstrap = dirname($entitiesPath, 2) . DIRECTORY_SEPARATOR . 'Cargo' . DIRECTORY_SEPARATOR . 'bootstrap.php';
if (is_file($cargoBootstrap)) {
    require_once $cargoBootstrap;
}

\Miko\Log\Logger::registerPhpErrorHandlers();
