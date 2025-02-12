<?php

namespace Modules\Core\Icrud\Services;

class MigrateService
{

  private $log = "Core: MigrateService|| ";
  private $baseConnection;

  private $includeModules;
  private $organization;


  /**
   * @param $baseConnection (Data from Connection from where the information is copied)
   * @param $includeModules (Flag that determines if the modules that are obtained are the ones to be ignored or installed)
   */
  public function __construct($baseConnection, $includeModules = false,$organization = null)
  {
    $this->baseConnection = $baseConnection;
    $this->includeModules = $includeModules;
    $this->organization = $organization;
  }


  /**
   * Migrate and Copy Data Only
   * @param $modules array
   */
  public function migrateAndCopyDataFromModules($modules)
  {

    \Log::info($this->log . "install");

    //Get Tables to migrate and sync
    $tables = $this->getMainTables(array_column($modules, 'dbPrefix'));

    //Sync tables from base tenant with the tenant created
    $this->syncTables($tables);

    //Copy Media Data
    $this->copyMediaData($modules);

    //Copy Fillable Data
    $this->copyFillableData($modules);

  }

  /**
   * Get the main tables to Migrate
   * @param $prefixes (Tables)
   */
  public function getMainTables(array $prefixes)
  {

    $include = $this->includeModules;

    // Get all tables from base connection
    $tables = \DB::connection($this->baseConnection)->select('SHOW TABLES');

    // Only name tables
    $tables = array_map('current', $tables);

    // Filter the tables based on the include flag
    $filteredTables = array_filter($tables, function ($key) use ($prefixes, $include) {
      foreach ($prefixes as $prefix) {
        if (str_contains($key, $prefix . '__')) {
          return $include; // Include or exclude based on the flag
        }
      }
      return !$include; // Include or exclude based on the flag
    }, ARRAY_FILTER_USE_BOTH);

    // Retrieve foreign key relationships
    $foreignKeyRelations = \DB::connection($this->baseConnection)->select("
            SELECT TABLE_NAME, REFERENCED_TABLE_NAME
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ?
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [config("database.connections.$this->baseConnection.database")]);

    // Create a dependency map
    $dependencyMap = [];
    foreach ($foreignKeyRelations as $relation) {
      $dependencyMap[$relation->TABLE_NAME][] = $relation->REFERENCED_TABLE_NAME;
    }

    // Order tables by dependencies
    $orderedTables = [];
    $visited = [];

    // Helper function for DFS
    $visit = function ($table) use (&$visit, &$orderedTables, &$visited, $dependencyMap) {
      if (isset($visited[$table])) return;
      $visited[$table] = true;

      // Visit dependencies first
      if (isset($dependencyMap[$table])) {
        foreach ($dependencyMap[$table] as $dependency) {
          $visit($dependency);
        }
      }

      // Add the table to the ordered list
      $orderedTables[] = $table;
    };

    // Perform DFS for all tables
    foreach ($filteredTables as $table) {
      $visit($table);
    }

    // Return the ordered list of tables
    return array_values(array_intersect($orderedTables, $filteredTables));
  }

  /**
   * Sync Table
   * @param $tables (Tables to sync)
   * @param $copyData (If copy the data or only create the table)
   */
  public function syncTables(array $tables,$copyData = true)
  {

    foreach ($tables as $tableName) {

      if (!\Schema::hasTable($tableName)) {

        \DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $moduleName = explode("__", $tableName);

        // Create table schema
        $createTableSql = \DB::connection($this->baseConnection)->select("SHOW CREATE TABLE `$tableName`")[0]->{'Create Table'};
        \DB::statement($createTableSql);

        // Reset auto increment Only case Ilocations
        if ($moduleName[0] == 'ilocations') \DB::statement("ALTER TABLE `$tableName` AUTO_INCREMENT = 1");

        // Validation to Copy Data
        if($copyData){

          //Ilocations will be seeder later || No copy data to Media | No Copy data Ifillable
          if ($moduleName[0] != 'ilocations' && $moduleName[0] != 'media' && $moduleName[0] != 'ifillable') {

            //insert data table
            $data = \DB::connection($this->baseConnection)->table($tableName)->get();

            if ($data->isNotEmpty()) {
              // Convert the data into an array with keys (column names preserved)
              $formattedData = $data->map(function ($item) {
                $itemArray = (array) $item; // Convert each item to an associative array

                //Set organization id in table
                if (array_key_exists('organization_id', $itemArray)) {
                  $itemArray['organization_id'] = $this->organization->id;
                }
                return $itemArray;
              })->toArray();

              \DB::table($tableName)->insert($formattedData);
            }
          }

          //Update organization Id
          if ($tableName == 'itenant__organizations') \DB::table($tableName)->update(['id' => $this->organization->id]);

          \Log::info("$this->log syncTable: $tableName");
        }

        \DB::statement('SET FOREIGN_KEY_CHECKS=1');
      }

    }

  }

  /**
   * Copy Media Data
   * @param $modules to copy
   * @param $queryWhere: Specific SQL Query
   */
  public function copyMediaData(array $modules=null, callable $queryWhere=null)
  {
    \Log::info($this->log . 'copyMediaData');

    $imageables = $this->getRowsFromModules('media__imageables','imageable_type',$modules,$queryWhere);

    if ($imageables->isNotEmpty()) {

      $imageablesIds = $imageables->pluck('file_id')->toArray();

      // Search Files in Base DB
      $files = \DB::connection($this->baseConnection)->table('media__files')
        ->whereIn('id', $imageablesIds)->get();

      //Validation insert data
      if ($files->isNotEmpty()) {
        \Log::info($this->log . 'copyMediaData|Inserting Media Files..');

        // Convert the data into an array with keys (column names preserved)
        $filesFormatted = $files->map(function ($item) {
          $itemArray = (array) $item; // Convert each item to an associative array
          $itemArray['organization_id'] = $this->organization->id;  // Set organization id in table
          return $itemArray;
        })->toArray();

        // Insert new files and get the new IDs
        $newFileIds = [];
        foreach ($filesFormatted as $file) {
          $copyFile = $file;
          unset($copyFile['id']);
          $newFileId = \DB::table('media__files')->insertGetId($copyFile);
          $newFileIds[$file['id']] = $newFileId;
        }

        // Update imageables with new file IDs and insert data
        $imageables = $imageables->map(function ($imageable) use ($newFileIds) {
          if (isset($newFileIds[$imageable->file_id])) {
            $imageable->file_id = $newFileIds[$imageable->file_id];
          }
          $imageableArray = (array)$imageable;
          unset($imageableArray['id']);
          return $imageableArray;
        })->toArray();

        // Insert updated imageables
        \DB::table('media__imageables')->insert($imageables);
      }
    }

  }

  /**
   * Get rows from Modules | Data
   * @param $tableName (Table from where the search starts)
   * @param $attTypeName (name of the attribute to filter)
   */
  private function getRowsFromModules(string $tableName,string $attTypeName, array $modules=null, callable $queryWhere=null)
  {

    \Log::info($this->log . 'getRowsFromModules');

    //Base Query
    $query = \DB::connection($this->baseConnection)->table($tableName);

    if(is_null($queryWhere)){
      //Get only module names with first letter in uppercase
      $moduleNames = array_map('ucfirst', array_keys($modules));

      // Remember if is not include (Case Creating) | Include is Installing
      $regexp = !$this->includeModules ? 'not regexp' : 'regexp';

      // Search Data in Base DB with filter $regexp
      $query->where($attTypeName, $regexp, implode('|',
          array_map(function ($moduleName) {
            return 'Modules\\\\' . $moduleName . '\\\\';
          }, $moduleNames)
        ));

    }else{
      //Case: Example ThemeService in Tenant
      $queryWhere($query);
    }

    //Get data
    return $query->get();

  }

  /**
   * Copy the Fillable Data
   */
  public function copyFillableData(array $modules=null, callable $queryWhere=null)
  {
    \Log::info($this->log . 'copyFillableData');

    //Get Ifillable Fields
    $baseFields = $this->getRowsFromModules('ifillable__fields','entity_type',$modules,$queryWhere);

    if($baseFields->isNotEmpty()) {

      //Get fields translations
      $baseFieldsId = $baseFields->pluck('id');
      $baseFieldsTranslations = \DB::connection($this->baseConnection)->table('ifillable__field_translations')->whereIn('field_id', $baseFieldsId)->get();

      //Convert to Array to insert and set organization id | Fields
      $baseFieldsArray = $baseFields->map(function($item) {
        $itemArray = (array) $item;
        if (array_key_exists('organization_id', $itemArray))
          $itemArray['organization_id'] = $this->organization->id;
        return $itemArray;
      })->toArray();

      //baseLayoutTranslations To Array
      $baseFieldsTranslationsArray = $baseFieldsTranslations->map(function($item) { return (array) $item; })->toArray();

      //Insert Data
      \Log::info($this->log . 'copyFillableData|Inserting Ifillable Fields..');
      \DB::table('ifillable__fields')->insert($baseFieldsArray);

      \Log::info($this->log . 'copyFillableData|Inserting Ifillable Fields Translations..');
      \DB::table('ifillable__field_translations')->insert($baseFieldsTranslationsArray);

    }

  }


}
