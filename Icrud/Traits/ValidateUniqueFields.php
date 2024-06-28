<?php

namespace Modules\Core\Icrud\Traits;

use Illuminate\Support\Facades\DB;
use Modules\Core\Icrud\Exceptions\DuplicateRecordException;

trait ValidateUniqueFields
{
  public static function bootValidateUniqueFields()
  {
    static::creatingWithBindings(function ($model) {
      $model->validateModel($model->getEventBindings('creatingWithBindings'));
    });

    static::updatingWithBindings(function ($model) {
      $model->validateModel($model->getAttributes('updatingWithBindings'), $model->id);
    });
  }

  public function validateModel($data, $excludeId = null)
  {
    $uniqueFields = $this->uniqueFields ?? [];
    if (count($uniqueFields)) {
      $translatableAttributes = $this->translatedAttributes ?? []; // Get translatable attributes
      $languages = array_keys(\LaravelLocalization::getSupportedLocales()); // Get site languages
      $query = $this->query(); // Initialize the query

      // Exclude current record if updating
      if ($excludeId) {
          $query->where('id', '<>', $excludeId);
      }

      // Add no translatable filters
      foreach ($uniqueFields as $field) {
        if (!in_array($field, $translatableAttributes)) {
          if (isset($data['data'][$field])) {
            $query->where($field, $data['data'][$field]);
          }
        }
      }

      // Add translatable filters
      foreach ($languages as $lang) {
        if (isset($data['data'][$lang])) {
          foreach ($uniqueFields as $field) {
            if (in_array($field, $translatableAttributes)) {
              if (isset($data['data'][$lang][$field])) {
                $query->orWhere(function ($query) use ($lang, $field, $data) {
                  $query->whereTranslation($field, $data['data'][$lang][$field], $lang);
                });
              }
            }
          }
        }
      }

      // Throw with duplicated records
      $duplicatedRecords = $query->get();
      if ($duplicatedRecords->isNotEmpty()) {
        throw new \Exception(json_encode([
          'messages' => [['message' => "Duplicated Record", 'type' => 'error']],
          'data' => $duplicatedRecords
        ]), 409);
      }
    }
  }
}
