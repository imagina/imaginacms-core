<?php

namespace Modules\Core\Icrud\Repositories\Eloquent;

use Modules\Core\Repositories\Eloquent\EloquentBaseRepository;
use Modules\Core\Icrud\Repositories\BaseCrudRepository;
use Modules\Core\Icrud\Transformers\CrudResource;

use Modules\Ihelpers\Events\CreateMedia;
use Modules\Ihelpers\Events\DeleteMedia;
use Modules\Ihelpers\Events\UpdateMedia;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class EloquentCrudRepository
 *
 * @package Modules\Core\Repositories\Eloquent
 */
abstract class EloquentCrudRepository extends EloquentBaseRepository implements BaseCrudRepository
{
  /**
   * Filter name to replace
   * @var array
   */
  protected $replaceFilters = [];

  /**
   * Relation name to replace
   * @var array
   */
  protected $replaceSyncModelRelations = [];


  /**
   * Query where to save the current query
   * @var null
   */
  protected $query = null;

  /**
   * parameter to validate use of old query
   * @var null
   */
  protected $params = null;

  /**
   * Attribute to define default relations
   * all apply to getItemsBy and getItem
   * index apply in the getItemsBy
   * show apply in the getItem
   * @var array
   */
  protected $with = [/*all => [] ,index => [],show => []*/];


  public function getOrCreateQuery($params, $criteria = null)
  {
    //save parameters validate use of old query
    $this->params = $params;

    if (!empty($params)) {
      $params = (object)$params;
      $cloneParams = clone $params;
      $cloneParams->returnAsQuery = true;
    } else $cloneParams = (object)["returnAsQuery" => true];

    if (is_null($criteria))
      $this->query = $this->getItemsBy($cloneParams);
    else
      $this->query = $this->getItem($criteria, $cloneParams);

    return $this->query;
  }

  /**
   * Method to include relations to query
   * @param $query
   * @param $relations
   */
  public function includeToQuery($query, $params, $method = null)
  {
    $relations = $params->include ?? [];
    $withoutDefaultInclude = isset($params->filter->withoutDefaultInclude) ? $params->filter->withoutDefaultInclude : false;
    //request all categories instances in the "relations" attribute in the entity model
    if (in_array('*', $relations)) $relations = $this->model->getRelations() ?? [];
    else { // Set default Relations
      if (!$withoutDefaultInclude) {
        $relations = array_merge($relations, ($this->with['all'] ?? [])); // Include all default relations
        if ($method == 'show') $relations = array_merge($relations, ($this->with['show'] ?? [])); // include show default relations
        if ($method == 'index') $relations = array_merge($relations, ($this->with['index'] ?? [])); // include index default reltaion
      }
    }
    //Filter valid Relations if is possible
    if (method_exists($this->model, 'filterValidRelations')) {
      $relations = $this->model->filterValidRelations($relations);
    }
    //Instance relations in query
    $query->with(array_unique($relations));
    //Response
    return $query;
  }

  /**
   * Method to set default model filters by attributes
   *
   * @param $query
   * @param $filter
   * @param $fieldName
   * @return mixed
   */
  public function setFilterQuery($query, $filterData, $fieldName)
  {
    $filterWhere = $filterData->where ?? null;//Get filter where condition
    $filterOperator = $filterData->operator ?? '=';// Get filter operator
    $filterValue = $filterData->value ?? $filterData;//Get filter value

    //Set where condition
    if ($filterWhere == 'in') {
      $query->whereIn($fieldName, $filterValue);
    } else if ($filterWhere == 'notIn') {
      $query->whereNotIn($fieldName, $filterValue);
    } else if ($filterWhere == 'between') {
      $query->whereBetween($fieldName, $filterValue);
    } else if ($filterWhere == 'notBetween') {
      $query->whereNotBetween($fieldName, $filterValue);
    } else if ($filterWhere == 'null') {
      $query->whereNull($fieldName);
    } else if ($filterWhere == 'notNull') {
      $query->whereNotNull($fieldName);
    } else if ($filterWhere == 'date') {
      $query->whereDate($fieldName, $filterOperator, $filterValue);
    } else if ($filterWhere == 'year') {
      $query->whereYear($fieldName, $filterOperator, $filterValue);
    } else if ($filterWhere == 'month') {
      $query->whereMonth($fieldName, $filterOperator, $filterValue);
    } else if ($filterWhere == 'day') {
      $query->whereDay($fieldName, $filterOperator, $filterValue);
    } else if ($filterWhere == 'time') {
      $query->whereTime($fieldName, $filterOperator, $filterValue);
    } else if ($filterWhere == 'column') {
      $query->whereColumn($fieldName, $filterOperator, $filterValue);
    } else if ($filterWhere == 'orWhere') {
      $query->orWhere($fieldName, $filterOperator, $filterValue);
    } else if ($filterWhere == 'belongsToMany') {
      $filterValue = (array)$filterValue;
      //Sub query to get data by pivot
      if (count($filterValue)) {
        $relationName = $fieldName[0];
        $foreignKey = $this->model->$relationName()->getRelatedPivotKeyName();
        $query->whereHas($relationName, function ($q) use ($foreignKey, $filterValue) {
          $q->whereIn($foreignKey, $filterValue);
        });
      }
    } else if ($filterWhere == 'hasMany') {
      $filterValue = (array)$filterValue;
      //Sub query to get data by pivot
      if (count($filterValue)) {
        $relatedFieldName = camelToSnake($fieldName[1] ?? 'id');
        $query->whereHas($fieldName[0], function ($q) use ($relatedFieldName, $filterValue) {
          $q->whereIn($relatedFieldName, $filterValue);
        });
      }
    } else {
      $query->where($fieldName, $filterOperator, $filterValue);
    }

    //Response
    return $query;
  }

  /**
   * Method to filter query
   * @param $query
   * @param $filter
   * @param $params
   */
  public function filterQuery($query, $filter, $params)
  {
    return $query;
  }

  /**
   * Method to order Query
   *
   * @param $query
   * @param $filter
   */
  public function orderQuery($query, $order, $noSortOrder, $orderByRaw)
  {
    //allow order by raw with skipping tags
    if (!empty($orderByRaw)) {
      $orderByRaw = strip_tags($orderByRaw);
      return $query->orderByRaw($orderByRaw);
    }
    //Verify if the model has sort_order column and ordering by that column by default
    $modelFields = $this->model->getFillable();

    //Include sort_order filter by default
    if (in_array('sort_order', $modelFields) && !$noSortOrder) $query->orderByRaw('COALESCE(sort_order, 0) desc');

    $orderField = $order->field ?? 'created_at';//Default field
    $orderWay = $order->way ?? 'desc';//Default way

    //Set order to query
    if (in_array($orderField, ($this->model->translatedAttributes ?? []))) {
      $query->orderByTranslation($orderField, $orderWay);
    } else $query->orderBy($orderField, $orderWay);

    //Return query with filters
    return $query;
  }

  /**
   * Map the definition of model relation
   *
   * @return array
   */
  public function getModelRelations()
  {
    $modelRelations = [];
    foreach (($this->model->modelRelations ?? []) as $name => $value) {
      if (is_string($value)) $modelRelations[$name] = ['relation' => $value];
      else if (is_array($value) && isset($value['relation'])) $modelRelations[$name] = $value;
    }

    return $modelRelations;
  }

  /**
   * Method to sync Model Relations by default
   *
   * @param $model ,$data
   * @return $model
   */
  public function defaultSyncModelRelations($model, $data)
  {
    foreach ($this->getModelRelations() as $relationName => $relation) {
      // Check if exist relation in data
      if (!in_array($relationName, $this->replaceSyncModelRelations) && array_key_exists($relationName, $data)) {
        //Sync as updateOrCreateMany
        if (($relation['type'] ?? null) == 'updateOrCreateMany') {
          if (isset($relation['compareKeys']) && is_array($relation['compareKeys'])) {
            //Instance the relation
            $relationInstance = $model->$relationName();
            // Get the related repository
            $relatedRepository = $relationInstance->getRelated()->repository ?? null;
            // Dynamically determine the foreign key for the relation
            $foreignKey = $relation['foreignKey'] ?? (method_exists($relationInstance, 'getForeignKeyName')
              ? $relationInstance->getForeignKeyName()
              : null);

            if ($relatedRepository && $foreignKey) {
              //Init the related repository
              $relatedRepository = app($relatedRepository);
              //update or create each related record
              foreach ($data[$relationName] as $item) {
                // Validate that all compare keys exist in the item
                $missingKeys = array_diff($relation['compareKeys'], array_keys($item));
                //Validate
                if (empty($missingKeys)) {
                  // Build the comparison array dynamically
                  $compare = array_merge([$foreignKey => $model->id], array_intersect_key($item, array_flip($relation['compareKeys'])));
                  // Use updateOrCreate with the dynamic compare keys
                  $relatedRepository->updateOrCreate($compare, $item);
                }
              }
            }
          }
          break;
        }
        //Default laravel relation
        switch ($relation['relation']) {
          // Sync Has Many relation
          case 'hasMany':
            // Validate if exist relation with items
            $model->$relationName()->forceDelete();
            // Create and Set relation to Model
            $model->setRelation($relationName, $model->$relationName()->createMany($data[$relationName]));
            break;
          // Sync Belongs to many relation
          case 'belongsToMany':
            $model->$relationName()->sync($data[$relationName]);
            $model->setRelation($relationName, $model->$relationName);
            break;
        }
      }
    }

    //Response
    return $model;
  }

  /**
   * Method to sync Model Relations
   *
   * @param $model ,$data
   * @return $model
   */
  public function syncModelRelations($model, $data)
  {
    //Get model relations data from attribute of model
    $modelRelationsData = $this->getModelRelations();

    /**
     * Note: Add relation name to replaceSyncModelRelations attribute to replace it
     *
     * Example to sync relations
     * if (array_key_exists(<relationName>, $data)){
     *    $model->setRelation(<relationName>, $model-><relationName>()->sync($data[<relationName>]));
     * }
     *
     */

    //Response
    return $model;
  }

  /**
   * Method to create model
   *
   * @param $data
   * @return mixed
   */
  public function create($data)
  {
    //Event creating model
    $this->dispatchesEvents(['eventName' => 'creating', 'data' => $data]);

    // Call function before create it, and take all change from $data
    $this->beforeCreate($data);

    //Create model
    $model = $this->model->create($data);

    // Default sync model relations
    $model = $this->defaultSyncModelRelations($model, $data);

    // Custom sync model relations
    $model = $this->syncModelRelations($model, $data);

    // Call function after create it, and take all change from $data and $model
    $this->afterCreate($model, $data);

    //Event created model
    $this->dispatchesEvents(['eventName' => 'created', 'data' => $data, 'model' => $model]);

    //Response
    return $model;
  }

  /**
   * Method to override in the child class if there need modify the data before create
   * @param $data
   * @return void
   */
  public function beforeCreate(&$data)
  {

  }

  /**
   * Method to override in the child class if there need modify the data after create
   * @param $model ,$data
   * @return void
   */
  public function afterCreate(&$model, &$data)
  {

  }

  /**
   * Method to request all data from model
   *
   * @param false $params
   * @return mixed
   */
  public function getItemsBy($params)
  {
    // compare parameters validate use of old query
    $differentParameters = $this->compareParameters($params);
    //reusing query if exist
    if (empty($this->query) || $differentParameters) {
      //Instance Query
      $query = $this->model->query();

      //Include relationships
      $query = $this->includeToQuery($query, $params, "index");

      //Filter Query
      if (isset($params->filter)) {
        $filters = $params->filter;//Short data filter
        //Instance model relations
        $modelRelations = $this->getModelRelations();
        //Instance model fillable
        $modelFillable = array_merge(
          $this->model->getFillable(),
          ['id', 'created_at', 'updated_at', 'created_by', 'updated_by']
        );

        //Set fiter order to params.order: TODO: to keep and don't break old version api
        if (isset($filters->order) && !isset($params->order)) $params->order = $filters->order;

        //Add Requested Filters
        foreach ($filters as $filterName => $filterValue) {
          $filterNameSnake = camelToSnake($filterName);//Get filter name as snakeCase
          if (!in_array($filterName, $this->replaceFilters)) {
            //Add fillable filter
            if (in_array($filterNameSnake, $modelFillable)) {
              //instance an own filter way when the filter name is ID
              if ($filterNameSnake == "id") $filterValue = (object)["where" => 'in', "value" => (array)$filterValue];
              //Validate if filter is an array put where as "in" type
              if (is_array($filterValue) && !isset($filterValue['where'])) $filterValue = (object)["where" => 'in', "value" => $filterValue];
              //Filter by parent ID
              if ($filterNameSnake == "parent_id" && !$filterValue) $filterValue = (object)["where" => 'null'];
              //Set filter
              $query = $this->setFilterQuery($query, $filterValue, $filterNameSnake);
            }
            //Add relation filter
            $relationPath = explode('.', $filterName);
            if (in_array($relationPath[0], array_keys($modelRelations))) {
              $query = $this->setFilterQuery($query, (object)[
                'where' => $modelRelations[$relationPath[0]]['relation'],
                'value' => $filterValue
              ], $relationPath);
            }
          }
        }

        //Filter by date
        if (isset($filters->date)) {
          $date = $filters->date;//Short filter date
          $date->field = $date->field ?? 'created_at';
          if (isset($date->from))//From a date
            $query->whereDate($date->field, '>=', $date->from);
          if (isset($date->to))//to a date
            $query->whereDate($date->field, '<=', $date->to);
        }

        //Audit filter withTrashed
        if (isset($filters->withTrashed) && $filters->withTrashed) $query->withTrashed();

        //Audit filter onlyTrashed
        if (isset($filters->onlyTrashed) && $filters->onlyTrashed) $query->onlyTrashed();

        //Filter by not organization
        if (isset($filters->withoutTenancy) && $filters->withoutTenancy) $query->withoutTenancy();

        //Set params into filters, to keep uploader code
        if (is_array($filters)) $filters = (object)$filters;

        //Add model filters
        $query = $this->filterQuery($query, $filters, $params);
      }

      //Order Query
      $query = $this->orderQuery($query, $params->order ?? true, $filters->noSortOrder ?? false, $params->orderByRaw ?? null);

    } else {
      //save parameters validate use of old query
      $this->params = $params;
      //reusing query if exist
      $query = $this->query;
    }

    //Response as query
    if (isset($params->returnAsQuery) && $params->returnAsQuery) return $query;

    //Response paginate
    else if (isset($params->page) && $params->page) $response = $query->paginate($params->take, ['*'], null, $params->page);
    //Response complete
    else {
      if (isset($params->take) && $params->take) $query->take($params->take);//Take
      $response = $query->get();
    }

    //Event retrived model
    $this->dispatchesEvents(['eventName' => 'retrievedIndex', 'data' => [
      "requestParams" => $params,
      "response" => $response,
    ]]);

    //Response
    return $response;
  }

  /**
   * Method to get model by criteria
   *
   * @param $criteria
   * @param $params
   * @return mixed
   */
  public function getItem($criteria, $params = false)
  {
    // compare parameters validate use of query
    $differentParameters = $this->compareParameters($params);
    //reusing query if exist
    if (empty($this->query) || $differentParameters) {
      $filter = $params->filter ?? (object)[];
      $translatableAttributes = $this->model->translatedAttributes ?? [];

      //Instance Query
      $query = $this->model->query();

      //Include relationships
      $query = $this->includeToQuery($query, $params, "show");

      //Get fields to use as criteria filter
      $criteriaFields = $params->filter->field ?? ['id'];
      if (!is_array($criteriaFields)) $criteriaFields = [$criteriaFields];

      // Set filter column translatable for criteria
      $translatableFields = array_intersect($criteriaFields, $translatableAttributes);
      if (count($translatableFields)) {
        $query->whereHas('translations', function ($query) use ($criteria, $filter, $translatableFields) {
          $query->where('locale', $filter->locale ?? \App::getLocale())
            ->where(function ($query) use ($criteria, $translatableFields) {
              foreach ($translatableFields as $field) {
                $query->orWhere($field, $criteria);
              }
            });
        });
      }

      // Set filter column for criteria
      $modelFields = array_diff($criteriaFields, $translatableAttributes);
      if (count($modelFields)) {
        $query->where(function ($query) use ($modelFields, $criteria) {
          foreach ($modelFields as $field) {
            $query->orWhere($field, $criteria);
          }
        });
      }

      //Filter Query
      if (isset($params->filter)) {
        $filters = $params->filter;//Short data filter
        //Instance model fillable
        $modelFillable = array_merge(
          $this->model->getFillable(),
          ['id', 'created_at', 'updated_at', 'created_by', 'updated_by']
        );

        //Add Requested Filters
        foreach ($filters as $filterName => $filterValue) {
          $filterNameSnake = camelToSnake($filterName);//Get filter name as snakeCase
          if (!in_array($filterName, $this->replaceFilters)) {
            //Add fillable filter
            if (in_array($filterNameSnake, $modelFillable)) {
              $query = $this->setFilterQuery($query, $filterValue, $filterNameSnake);
            }
          }
        }

        //Filter by not organization
        if (isset($filters->withoutTenancy) && $filters->withoutTenancy) $query->withoutTenancy();

        //Set params into filters, to keep uploader code
        if (is_array($filters)) $filters = (object)$filters;

        //Add model filters
        $query = $this->filterQuery($query, $filters, $params);
      }
    } else {
      //reusing query if exist
      $query = $this->query;
    }

    //Response as query
    if (isset($params->returnAsQuery) && $params->returnAsQuery) return $query;

    //Request
    $response = $query->first();

    //Event retrived model
    $this->dispatchesEvents(['eventName' => 'retrievedShow', 'data' => [
      "requestParams" => $params,
      "response" => $response,
      "criteria" => $criteria
    ]]);

    //Response
    return $response;
  }

  public function getItemsByTransformed($models, $params)
  {
    return json_decode(json_encode(CrudResource::transformData($models)));
  }

  /**
   * Method to update model by criteria
   *
   * @param $criteria
   * @param $data
   * @param $params
   * @return mixed
   */
  public function updateBy($criteria, $data, $params = false)
  {
    //Event updating model
    $this->dispatchesEvents(['eventName' => 'updating', 'data' => $data, 'criteria' => $criteria]);

    //Instance Query
    $query = $this->model->query();

    //Check field name to criteria
    if (isset($params->filter->field)) $field = $params->filter->field;

    //get model and update
    $model = $query->where($field ?? 'id', $criteria)->first();
    if (isset($model)) {
      $data['id'] = $model->id;
      $this->beforeUpdate($data);
      //Update Model
      $model->update((array)$data);
      // Default Sync model relations
      $model = $this->defaultSyncModelRelations($model, $data);
      // Custom Sync model relations
      $model = $this->syncModelRelations($model, $data);
      // Call function after update it, and take all change from $data and $model
      $this->afterUpdate($model, $data);
      //Event updated model
      $this->dispatchesEvents([
        'eventName' => 'updated',
        'data' => $data,
        'criteria' => $criteria,
        'model' => $model
      ]);
    }

    //Response
    return $model;
  }

  /**
   * Method to override in the child class if there need modify the data before update
   * @param $data
   * @return void
   */
  public function beforeUpdate(&$data)
  {

  }

  /**
   * Method to override in the child class if there need modify the data after update
   * @param $model , $data
   * @return void
   */
  public function afterUpdate(&$model, &$data)
  {

  }

  /**
   * Method to do a bulk order
   *
   * @param $data
   * @param $params
   * @return mixed|void
   */
  public function bulkOrder($data, $params = false)
  {
    //Instance the orderField
    $orderField = $params->filter->field ?? 'position';
    //loop through data to update the position according to index data
    foreach ($data as $key => $item) {
      $this->model->find($item['id'])->update([$orderField => ++$key]);
    }
    //Response
    return $this->model->whereIn('id', array_column($data, "id"))->get();
  }

  /**
   * Method to do a bulk update models
   *
   * @param $data
   * @param $params
   * @return mixed|void
   */
  public function bulkUpdate($data, $params = false)
  {
    //Instance the orderField
    $fieldName = $params->filter->field ?? 'id';
    //loop through data to update the position according to index data
    foreach ($data as $key => $item) {
      $this->updateBy($item[$fieldName], $item, $params);
    }
    //Response
    return true;
  }

  /**
   * Method to do a bulk create models
   *
   * @param $data
   * @return mixed|void
   */
  public function bulkCreate($data)
  {
    //loop through data to create the position according to index data
    foreach ($data as $key => $item) {
      $this->create($item);
    }
    //Response
    return true;
  }

  /**
   * Method to delete model by criteria
   *
   * @param $criteria
   * @param $params
   * @return mixed
   */
  public function deleteBy($criteria, $params = false)
  {
    //Instance Query
    $query = $this->model->query();

    //Check field name to criteria
    if (isset($params->filter->field)) $field = $params->filter->field;

    //Include trashed records
    if ($this->hasSoftDeletes()) $query->withTrashed();

    //get model
    $model = $query->where($field ?? 'id', $criteria)->first();

    //Event deleting model
    $this->dispatchesEvents(['eventName' => 'deleting', 'criteria' => $criteria, 'model' => $model]);

    //Delete Model
    if ($model) {
      if (isset($params->filter->forceDelete) && $this->hasSoftDeletes()) $model->forceDelete();
      else $model->delete();
    }

    //Event deleted model
    $this->dispatchesEvents(['eventName' => 'deleted', 'criteria' => $criteria]);

    //Response
    return $model;
  }

  /**
   * Method to delete model by criteria
   *
   * @param $criteria
   * @param $params
   * @return mixed
   */
  public function restoreBy($criteria, $params = false)
  {
    //Instance Query
    $query = $this->model->query();

    //Check field name to criteria
    if (isset($params->filter->field)) $field = $params->filter->field;

    //get model
    $model = $query->where($field ?? 'id', $criteria)->withTrashed()->first();

    //Delete Model
    if ($model) $model->restore();

    //Response
    return $model;
  }

  /**
   * Dispathes events
   *
   * @param $params
   */
  public function dispatchesEvents($params)
  {
    //Instance parameters
    $eventName = $params['eventName'];
    $data = $params['data'] ?? [];
    $criteria = $params['criteria'] ?? null;
    $model = $params['model'] ?? null;

    //Dispatch retrieved events
    if ($eventName == 'retrievedIndex') {
      //Emit event retrievedWithBindings
      if (method_exists($this->model, 'retrievedIndexCrudModel'))
        $this->model->retrievedIndexCrudModel(['data' => $data]);
    }

    //Dispatch retrieved events
    if ($eventName == 'retrievedShow') {
      //Emit event retrievedWithBindings
      if (method_exists($this->model, 'retrievedShowCrudModel'))
        $this->model->retrievedShowCrudModel(['data' => $data]);
    }

    //Dispatch creating events
    if ($eventName == 'creating') {
      //Emit event creatingWithBindings
      if (method_exists($this->model, 'creatingCrudModel'))
        $this->model->creatingCrudModel(['data' => $data]);
    }

    //Dispatch created events
    if ($eventName == 'created') {
      //Emit event createdWithBindings
      if (method_exists($model, 'createdCrudModel'))
        $model->createdCrudModel(['data' => $data]);
      //Event to ADD media
      if (method_exists($model, 'mediaFiles'))
        event(new CreateMedia($model, $data));
    }

    //Dispatch updating events
    if ($eventName == 'updating') {
      //Emit event updatingWithBindings
      if (method_exists($this->model, 'updatingCrudModel'))
        $this->model->updatingCrudModel(['data' => $data, 'params' => $params, 'criteria' => $criteria]);
    }

    //Dispatch updated events
    if ($eventName == 'updated') {
      //Emit event updatedWithBindings
      if (method_exists($model, 'updatedCrudModel'))
        $model->updatedCrudModel(['data' => $data, 'params' => $params, 'criteria' => $criteria]);
      //Event to Update media
      if (method_exists($model, 'mediaFiles'))
        event(new UpdateMedia($model, $data));
    }

    //Dispatch deleting events
    if ($eventName == 'deleting') {
      //Emit event deletingWithBindings
      if (method_exists($model, 'deletingCrudModel'))
        $model->deletingCrudModel(['params' => $params, 'criteria' => $criteria]);
    }

    //Dispatch deleted events
    if ($eventName == 'deleted') {
    }

    //Dispatches model events
    $dispatchesEvents = $this->model->dispatchesEventsWithBindings ?? [];
    if (isset($dispatchesEvents[$eventName]) && count($dispatchesEvents[$eventName])) {
      //Dispath every model events from eventName
      foreach ($dispatchesEvents[$eventName] as $event) {
        //Get the module name from path event parameter
        $moduleName = explode("\\", $event['path'])[1];
        //Validate if module is enabled to dispath event
        if (is_module_enabled($moduleName)) event(new $event['path']([
          'data' => $data,
          'extraData' => $event['extraData'] ?? [],
          'criteria' => $criteria,
          'model' => $model
        ]));
      }
    }
  }

  /**
   * Function to validate parameters
   *
   * @param $params
   */
  public function compareParameters($params): bool
  {
    $newParams = json_encode($params);
    $queryParams = json_encode($this->params);
    return $newParams != $queryParams;
  }


  private function hasSoftDeletes()
  {
    return in_array(SoftDeletes::class, class_uses_recursive($this->model));
  }

  public function updateOrCreate($validationData, $data)
  {
    //Search the record
    $model = $this->getItemsBy((object)['filter' => (object)$validationData])->first();
    $modelData = array_merge($validationData, $data);
    //update Or Create the record
    if ($model) $this->updateBy($model->id, $modelData);
    else $this->create($modelData);
    //Response
    return $model;
  }

  /**
   * Return a dashboard information
   *
   * @param $params
   * @return array
   */
  public function getDashboard($params)
  {
    return [];
  }
}
