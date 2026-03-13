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
 * 
 * Production optimized - Only essential modules loaded by default
 * Uncomment modules as needed for your project
 */

$entitiesPath = __DIR__;

// ============================================================================
// CORE - Required (Always loaded)
// ============================================================================
require_once $entitiesPath . "/Core/Config.php";                              // Config & env() helper
require_once $entitiesPath . "/Core/Database/DatabaseConfig.php";             // Database configuration
require_once $entitiesPath . "/Core/Exceptions/FrameworkException.php";       // Base exception class
require_once $entitiesPath . "/Core/Http/JsonResponse.php";                   // JSON API responses
require_once $entitiesPath . "/Core/Http/Cors.php";                           // CORS handler
require_once $entitiesPath . "/Core/Http/HttpClient.php";                     // HTTP client (cURL, async support)
require_once $entitiesPath . "/Core/Http/SoapClient.php";                     // SOAP web service client
require_once $entitiesPath . "/Core/Http/XmlClient.php";                      // XML web service client
require_once $entitiesPath . "/Core/Http/JwtHelper.php";                      // JWT token helper
require_once $entitiesPath . "/Core/Helpers/StringHelper.php";                // String utilities
require_once $entitiesPath . "/Core/Helpers/DateHelper.php";                  // Date utilities
require_once $entitiesPath . "/Core/Helpers/CodeGenerator.php";               // Reference code generator
require_once $entitiesPath . "/Core/Validation/Validator.php";                // Input validation

// ============================================================================
// DATABASE CORE - Required (Always loaded)
// ============================================================================
require_once $entitiesPath . "/Database/Exceptions/DatabaseException.php";    // Database exceptions
require_once $entitiesPath . "/Database/Exceptions/QueryException.php";       // Query exceptions
require_once $entitiesPath . "/Database/StatementInterface.php";              // Statement interface
require_once $entitiesPath . "/Database/ResultInterface.php";                 // Result interface
require_once $entitiesPath . "/Database/ConnectionInterface.php";             // Connection interface
require_once $entitiesPath . "/Database/Statement.php";                       // PDO statement wrapper
require_once $entitiesPath . "/Database/Result.php";                          // Query result wrapper
require_once $entitiesPath . "/Database/Connection.php";                      // PDO connection wrapper

// ============================================================================
// CONNECTION POOL - Required (Always loaded)
// ============================================================================
require_once $entitiesPath . "/Database/ConnectionPool/ConnectionPoolInterface.php";
require_once $entitiesPath . "/Database/ConnectionPool/ConnectionPool.php";   // Connection pooling

// ============================================================================
// DB FACADE - Required (Always loaded)
// ============================================================================
require_once $entitiesPath . "/Database/DB.php";                              // DB::connection() facade
require_once $entitiesPath . "/Database/DbConfig.php";                        // Fluent database configuration

// ============================================================================
// QUERY BUILDER - Required (Always loaded)
// ============================================================================
require_once $entitiesPath . "/Database/Query/QueryBuilderInterface.php";
require_once $entitiesPath . "/Database/Query/QueryBuilder.php";              // Fluent query builder
require_once $entitiesPath . "/Database/Query/RawQuery.php";                  // Raw SQL queries

// ============================================================================
// LOGGING - Required (Always loaded)
// ============================================================================
require_once $entitiesPath . "/Database/Log/QueryLogger.php";                 // Query logging
require_once $entitiesPath . "/Log/Logger.php";                               // Application logging

// ============================================================================
// CACHE - Required for performance (Always loaded)
// ============================================================================
require_once $entitiesPath . "/Database/Cache/QueryCache.php";                // Query result caching (memory)
require_once $entitiesPath . "/Cache/ApcuCache.php";                          // APCu persistent cache

// ============================================================================
// SECURITY - Required (Always loaded)
// ============================================================================
require_once $entitiesPath . "/Security/FormCrypt.php";                       // Form data encryption

// ============================================================================
// LIBRARY - Utility classes (Crypto, Security, TextHelper)
// ============================================================================
require_once $entitiesPath . "/Library/Crypto.php";                           // Encryption, hashing, tokens
require_once $entitiesPath . "/Library/Security.php";                         // Input sanitization, CSRF, XSS
require_once $entitiesPath . "/Library/TextHelper.php";                       // String manipulation

// ============================================================================
// DATABASE DRIVERS - Multi-database support (MySQL, PostgreSQL, SQLite, SqlServer)
// ============================================================================
require_once $entitiesPath . "/Database/Drivers/DriverInterface.php";         // Driver interface
require_once $entitiesPath . "/Database/Drivers/MySqlDriver.php";             // MySQL driver
require_once $entitiesPath . "/Database/Drivers/PostgreSqlDriver.php";        // PostgreSQL driver
require_once $entitiesPath . "/Database/Drivers/SqliteDriver.php";            // SQLite driver
require_once $entitiesPath . "/Database/Drivers/SqlServerDriver.php";         // SQL Server driver
require_once $entitiesPath . "/Database/Drivers/DriverFactory.php";           // Driver factory

// ============================================================================
// MIGRATION SYSTEM - Professional migration like Entity Framework
// ============================================================================
require_once $entitiesPath . "/Database/Migration/TableBuilder.php";          // Fluent table builder
require_once $entitiesPath . "/Database/Migration/Schema.php";                // Schema operations
require_once $entitiesPath . "/Database/Migration/Migration.php";             // Migration base class
require_once $entitiesPath . "/Database/Migration/Migrator.php";              // Migration runner

// ============================================================================
// ORM - Model classes with Attribute support
// ============================================================================
require_once $entitiesPath . "/Database/ORM/Attributes.php";                  // PHP 8 Attributes
require_once $entitiesPath . "/Database/ORM/ModelMetadata.php";               // Metadata extraction
require_once $entitiesPath . "/Database/ORM/Events/ModelEvent.php";           // Model events
require_once $entitiesPath . "/Database/ORM/Traits/HasEvents.php";            // Event trait
require_once $entitiesPath . "/Database/ORM/Traits/HasTimestamps.php";        // Auto timestamps
require_once $entitiesPath . "/Database/ORM/Traits/HasMigration.php";         // Migration trait (up/down)
require_once $entitiesPath . "/Database/ORM/Traits/SoftDeletes.php";          // Soft delete trait
require_once $entitiesPath . "/Database/ORM/Relations/Relation.php";          // Base relation
require_once $entitiesPath . "/Database/ORM/Relations/HasOne.php";            // HasOne relation
require_once $entitiesPath . "/Database/ORM/Relations/HasMany.php";           // HasMany relation
require_once $entitiesPath . "/Database/ORM/Relations/BelongsTo.php";         // BelongsTo relation
require_once $entitiesPath . "/Database/ORM/Relations/BelongsToMany.php";     // Many-to-many relation
require_once $entitiesPath . "/Database/ORM/QueryBuilder.php";                // ORM query builder
require_once $entitiesPath . "/Database/ORM/Model.php";                       // Base Model class
require_once $entitiesPath . "/Database/ORM/MikoSet.php";                     // MikoSet (entity set)
require_once $entitiesPath . "/Database/ORM/DbContext.php";                   // DbContext with auto-migration
require_once $entitiesPath . "/Database/ORM/Observer.php";                    // Model observers
require_once $entitiesPath . "/Database/ORM/BulkOperations.php";              // Bulk insert/update/delete
require_once $entitiesPath . "/Database/ORM/JsonColumn.php";                  // JSON column support (JsonDictionary, JsonList)
require_once $entitiesPath . "/Database/ORM/ValidationAttributes.php";        // Validation attributes
require_once $entitiesPath . "/Database/ORM/Transaction.php";                 // Transaction helper
require_once $entitiesPath . "/Database/ORM/ConnectionPool.php";              // Connection pool management

// ============================================================================
// OPTIONAL MODULES - Uncomment as needed
// ============================================================================
// require_once $entitiesPath . "/Database/Transaction/TransactionManager.php";  // Complex transactions
// require_once $entitiesPath . "/Database/Bulk/BulkInsert.php";                  // Batch inserts
// require_once $entitiesPath . "/Database/Bulk/BulkUpdate.php";                  // Batch updates
// require_once $entitiesPath . "/Database/Seeders/Seeder.php";                   // Database seeding
// require_once $entitiesPath . "/Database/Seeders/SeederRunner.php";             // Seeder runner
// require_once $entitiesPath . "/Database/Factories/Factory.php";                // Test data generation
// require_once $entitiesPath . "/Database/Pagination/Paginator.php";             // Query pagination
// require_once $entitiesPath . "/Database/Monitor/HealthCheck.php";              // Connection health
// require_once $entitiesPath . "/Database/Monitor/ConnectionStats.php";          // Connection statistics
